<?php

namespace App\Extensions\Calendar\Services;

use App\Models\Issue;
use App\Models\User;
use App\Extensions\Calendar\Models\CalendarEvent;
use App\Extensions\Calendar\Models\CalendarEventAttendee;
use App\Extensions\Calendar\Models\CalendarFeedEvent;
use App\Extensions\Crm\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the calendar feed: task deadlines, open CRM deals and the current
 * user's events (local + subscribed feeds), with recurring events expanded
 * into concrete occurrences.
 */
class CalendarAggregator
{
    public function __construct(
        private RecurrenceExpander $expander,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, truncated: bool}
     */
    public function aggregate(User $user, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $items = array_merge(
            $this->taskItems($user, $from, $to),
            $this->dealItems($from, $to),
        );

        $eventResult = $this->eventItems($user, $from, $to, $filters);

        $items = array_merge($items, $eventResult['items']);
        $items = $this->sortItems($items);

        $truncated = $eventResult['truncated'];
        $maxItems = (int) config('calendar.max_items', 5000);
        if (count($items) > $maxItems) {
            $items = array_slice($items, 0, $maxItems);
            $truncated = true;
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * Present a single event (with its first occurrence) in the same shape the
     * aggregate uses, so detail/participant/link responses stay consistent.
     *
     * @return array<string, mixed>
     */
    public function present(CalendarEvent $event, User $user): array
    {
        $start = CarbonImmutable::instance($event->starts_at)->utc();
        $from = $start->subDay();
        $to = $start->addDay();

        $result = $this->expander->expand(
            $event->starts_at,
            $event->ends_at,
            $event->all_day,
            $event->timezoneName(),
            $event->rrule,
            $event->exdates,
            $from,
            $to,
            1,
        );

        $occurrence = $result['items'][0] ?? [
            'start' => $start,
            'end' => $start,
        ];

        return $this->localEventItem($event, $occurrence, $user);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, truncated: bool}
     */
    public function eventItems(User $user, CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $items = [];
        $truncated = false;

        $feedId = isset($filters['feed_id']) ? (int) $filters['feed_id'] : null;

        if ($feedId === null) {
            $query = CalendarEvent::query()
                ->with(['attendees.user', 'links.issue', 'links.deal', 'organizer', 'user'])
                ->where('starts_at', '<', $to)
                ->where(function (Builder $q) use ($from) {
                    $q->whereNotNull('rrule')
                        ->orWhere('ends_at', '>=', $from)
                        ->orWhere(fn (Builder $inner) => $inner->whereNull('ends_at')->where('starts_at', '>=', $from));
                })
                ->orderBy('starts_at');

            // Events are personal: even admins only see events they own,
            // organise, attend or that are explicitly public.
            $query->visibleTo($user);

            $this->applyEventFilters($query, $filters);

            foreach ($query->get() as $event) {
                $result = $this->expander->expand(
                    $event->starts_at,
                    $event->ends_at,
                    $event->all_day,
                    $event->timezoneName(),
                    $event->rrule,
                    $event->exdates,
                    $from,
                    $to,
                );

                $truncated = $truncated || $result['truncated'];

                foreach ($result['items'] as $occurrence) {
                    $items[] = $this->localEventItem($event, $occurrence, $user);
                }
            }
        }

        $feedQuery = CalendarFeedEvent::query()
            ->with('feed')
            ->whereHas('feed', fn (Builder $q) => $q->where('user_id', $user->id))
            ->where('starts_at', '<', $to)
            ->where(function (Builder $q) use ($from) {
                $q->whereNotNull('rrule')
                    ->orWhere('ends_at', '>=', $from)
                    ->orWhere(fn (Builder $inner) => $inner->whereNull('ends_at')->where('starts_at', '>=', $from));
            })
            ->orderBy('starts_at');

        if ($feedId !== null) {
            $feedQuery->where('feed_id', $feedId);
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $feedQuery->where('summary', 'ilike', "%{$search}%");
        }

        foreach ($feedQuery->get() as $feedEvent) {
            if ($feedEvent->is_cancelled || ! $feedEvent->feed) {
                continue;
            }

            $result = $this->expander->expand(
                $feedEvent->starts_at,
                $feedEvent->ends_at,
                $feedEvent->all_day,
                $feedEvent->timezoneName(),
                $feedEvent->rrule,
                $feedEvent->exdates,
                $from,
                $to,
            );

            $truncated = $truncated || $result['truncated'];

            foreach ($result['items'] as $occurrence) {
                $items[] = $this->feedEventItem($feedEvent, $occurrence);
            }
        }

        return ['items' => $this->sortItems($items), 'truncated' => $truncated];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function taskItems(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = [];

        $issues = Issue::query()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->whereHas('status', fn ($q) => $q->where('category', '!=', 'done'))
            ->with([
                'status:id,name,category,color',
                'project:id,key,name',
                'assignee:id,first_name,last_name',
                'type:id,name,color',
            ]);

        if (! $user->isAdmin()) {
            $issues->whereHas('project', fn ($q) => $q->visibleTo($user));
        }

        foreach ($issues->get() as $issue) {
            $items[] = [
                'type' => 'task',
                'id' => $issue->id,
                'title' => $issue->summary,
                'date' => $issue->due_date?->format('Y-m-d'),
                'meta' => [
                    'key' => $issue->key,
                    'status' => $issue->status?->name,
                    'status_category' => $issue->status?->category,
                    'color' => $issue->status?->color,
                    'project' => $issue->project?->key,
                    'priority' => $issue->priority,
                    'type' => $issue->type?->name,
                    'type_color' => $issue->type?->color,
                    'assignee' => $issue->assignee?->full_name,
                ],
                'link' => "/issues/{$issue->id}",
                'occurrence_key' => "task:{$issue->id}",
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dealItems(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = [];

        $deals = Deal::query()
            ->where('status', Deal::STATUS_OPEN)
            ->whereNotNull('expected_close_date')
            ->whereBetween('expected_close_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->with(['company:id,name', 'stage:id,name,color'])
            ->get();

        foreach ($deals as $deal) {
            $items[] = [
                'type' => 'deal',
                'id' => $deal->id,
                'title' => $deal->title,
                'date' => $deal->expected_close_date?->format('Y-m-d'),
                'meta' => [
                    'amount' => (float) $deal->amount,
                    'currency' => $deal->currency,
                    'company' => $deal->company?->name,
                    'stage' => $deal->stage?->name,
                    'color' => $deal->stage?->color,
                ],
                'link' => '/crm',
                'occurrence_key' => "deal:{$deal->id}",
            ];
        }

        return $items;
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $occurrence
     * @return array<string, mixed>
     */
    private function localEventItem(CalendarEvent $event, array $occurrence, User $user): array
    {
        $myAttendee = $event->attendees->firstWhere('user_id', $user->id);
        $myStatus = $myAttendee?->status;

        if ($event->isOrganizer($user)) {
            $myStatus = CalendarEventAttendee::STATUS_ACCEPTED;
        }

        $participants = $event->attendees->map(fn (CalendarEventAttendee $attendee) => [
            'id' => $attendee->id,
            'user_id' => $attendee->user_id,
            'email' => $attendee->email,
            'name' => $attendee->name,
            'status' => $attendee->status,
            'role' => $attendee->role,
            'user' => $attendee->user ? [
                'id' => $attendee->user->id,
                'full_name' => $attendee->user->full_name,
                'email' => $attendee->user->email,
            ] : null,
        ])->values()->all();

        $links = $event->links->map(fn ($link) => [
            'id' => $link->id,
            'issue_id' => $link->issue_id,
            'deal_id' => $link->deal_id,
            'issue' => $link->issue ? [
                'id' => $link->issue->id,
                'key' => $link->issue->key,
                'summary' => $link->issue->summary,
            ] : null,
            'deal' => $link->deal ? [
                'id' => $link->deal->id,
                'title' => $link->deal->title,
            ] : null,
        ])->values()->all();

        $payload = [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $occurrence['start']->toIso8601String(),
            'ends_at' => $occurrence['end']->toIso8601String(),
            'all_day' => $event->all_day,
            'timezone' => $event->timezoneName(),
            'rrule' => $event->rrule,
            'exdates' => $event->exdates,
            'color' => $event->color,
            'visibility' => $event->visibility,
            'source' => $event->source,
            'organizer_id' => $event->organizer_id,
            'event_date' => $occurrence['start']->format('Y-m-d'),
            'participants' => $participants,
            'links' => $links,
            'my_status' => $myStatus,
            'is_organizer' => $event->isOrganizer($user),
        ];

        return [
            'type' => 'event',
            'id' => $event->id,
            'title' => $event->title,
            'date' => $occurrence['start']->format('Y-m-d'),
            'meta' => [
                'color' => $event->color,
                'description' => $event->description,
                'source' => $event->source,
                'starts_at' => $occurrence['start']->toIso8601String(),
                'ends_at' => $occurrence['end']->toIso8601String(),
                'all_day' => $event->all_day,
                'timezone' => $event->timezoneName(),
                'location' => $event->location,
                'recurring' => $event->isRecurring(),
                'occurrence_start' => $occurrence['start']->toIso8601String(),
                'occurrence_end' => $occurrence['end']->toIso8601String(),
                'event_id' => $event->id,
                'feed_id' => null,
                'my_status' => $myStatus,
                'participants_count' => count($participants),
                'issues' => array_values(array_filter(array_map(fn ($link) => $link['issue'], $links))),
                'deals' => array_values(array_filter(array_map(fn ($link) => $link['deal'], $links))),
                'event' => $payload,
            ],
            'link' => null,
            'editable' => $event->source !== CalendarEvent::SOURCE_FEED && $event->isOrganizer($user),
            'occurrence_key' => "local:{$event->id}:{$occurrence['start']->toIso8601String()}",
        ];
    }

    /**
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $occurrence
     * @return array<string, mixed>
     */
    private function feedEventItem(CalendarFeedEvent $event, array $occurrence): array
    {
        $feed = $event->feed;
        $color = $feed?->color ?: '#8B5CF6';

        $payload = [
            'id' => $event->id,
            'title' => $event->summary,
            'description' => $event->description,
            'location' => $event->location,
            'starts_at' => $occurrence['start']->toIso8601String(),
            'ends_at' => $occurrence['end']->toIso8601String(),
            'all_day' => $event->all_day,
            'timezone' => $event->timezoneName(),
            'rrule' => $event->rrule,
            'exdates' => $event->exdates,
            'color' => $color,
            'visibility' => CalendarEvent::VISIBILITY_PRIVATE,
            'source' => 'feed',
            'feed_id' => $feed?->id,
            'event_date' => $occurrence['start']->format('Y-m-d'),
            'participants' => [],
            'links' => [],
            'my_status' => null,
            'is_organizer' => false,
        ];

        return [
            'type' => 'event',
            'id' => $event->id,
            'title' => $event->summary,
            'date' => $occurrence['start']->format('Y-m-d'),
            'meta' => [
                'color' => $color,
                'description' => $event->description,
                'source' => 'feed',
                'starts_at' => $occurrence['start']->toIso8601String(),
                'ends_at' => $occurrence['end']->toIso8601String(),
                'all_day' => $event->all_day,
                'timezone' => $event->timezoneName(),
                'location' => $event->location,
                'recurring' => $event->isRecurring(),
                'occurrence_start' => $occurrence['start']->toIso8601String(),
                'occurrence_end' => $occurrence['end']->toIso8601String(),
                'event_id' => $event->id,
                'feed_id' => $feed?->id,
                'feed_name' => $feed?->name,
                'my_status' => null,
                'participants_count' => 0,
                'issues' => [],
                'deals' => [],
                'event' => $payload,
            ],
            'link' => null,
            'editable' => false,
            'occurrence_key' => "feed:{$event->id}:{$occurrence['start']->toIso8601String()}",
        ];
    }

    private function applyEventFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['issue_id'])) {
            $query->whereHas('issues', fn (Builder $q) => $q->where('issues.id', (int) $filters['issue_id']));
        }

        if (! empty($filters['deal_id'])) {
            $query->whereHas('deals', fn (Builder $q) => $q->where('crm_deals.id', (int) $filters['deal_id']));
        }

        if (! empty($filters['mine'])) {
            $userId = (int) ($filters['user_id'] ?? 0);
            $query->where(fn (Builder $q) => $q->where('organizer_id', $userId)->orWhere('user_id', $userId));
        }

        if (! empty($filters['attending'])) {
            $userId = (int) ($filters['user_id'] ?? 0);
            $query->whereHas('attendees', fn (Builder $q) => $q->where('user_id', $userId));
        }

        if ($search = trim((string) ($filters['q'] ?? ''))) {
            $query->where('title', 'ilike', "%{$search}%");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function sortItems(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            $left = ($a['date'] ?? '') . '|' . ($a['meta']['occurrence_start'] ?? $a['date'] ?? '') . '|' . $a['type'];
            $right = ($b['date'] ?? '') . '|' . ($b['meta']['occurrence_start'] ?? $b['date'] ?? '') . '|' . $b['type'];

            return strcmp($left, $right);
        });

        return array_values($items);
    }
}
