<?php

namespace App\Extensions\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Calendar\Http\Controllers\Concerns\ResolvesCalendarActor;
use App\Extensions\Calendar\Models\CalendarEvent;
use App\Extensions\Calendar\Services\CalendarAggregator;
use App\Extensions\Calendar\Services\FreeSlotFinder;
use App\Extensions\Calendar\Services\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class CalendarController extends Controller
{
    use ResolvesCalendarActor;

    public function __construct(
        private RecurrenceExpander $expander,
        private CalendarAggregator $aggregator,
        private FreeSlotFinder $freeSlots,
    ) {
    }

    /**
     * Aggregate open task deadlines, open CRM deals, the current user's
     * personal events and subscribed feeds for the requested date range.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateRange($request, withFilters: true);

        $user = $this->actor($request);
        [$from, $to] = $this->bounds($validated['from'], $validated['to']);

        $result = $this->aggregator->aggregate($user, $from, $to, $validated + ['user_id' => $user->id]);

        return response()->json([
            'data' => [
                'from' => $validated['from'],
                'to' => $validated['to'],
                'items' => $result['items'],
                'truncated' => $result['truncated'],
            ],
        ]);
    }

    /**
     * Event-only listing (local events + subscribed feeds) with filters.
     */
    public function indexEvents(Request $request): JsonResponse
    {
        $validated = $this->validateRange($request, withFilters: true);

        $user = $this->actor($request);
        [$from, $to] = $this->bounds($validated['from'], $validated['to']);

        $result = $this->aggregator->eventItems($user, $from, $to, $validated + ['user_id' => $user->id]);

        return response()->json([
            'data' => [
                'from' => $validated['from'],
                'to' => $validated['to'],
                'items' => $result['items'],
                'truncated' => $result['truncated'],
            ],
        ]);
    }

    public function show(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->canView($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return response()->json(['data' => $this->aggregator->present($event, $user)]);
    }

    /**
     * Free slots for one or more users on a given day (to pick a meeting time).
     */
    public function freeSlots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'duration' => 'sometimes|integer|min:15|max:480',
            'user_ids' => 'sometimes|array',
            'user_ids.*' => 'integer',
            'window_start' => 'sometimes|date_format:H:i',
            'window_end' => 'sometimes|date_format:H:i',
        ]);

        $user = $this->actor($request);
        $timezone = config('app.timezone', 'UTC');
        $date = CarbonImmutable::createFromFormat('Y-m-d', $validated['date'], $timezone)->startOfDay();

        [$startH, $startM] = array_map('intval', explode(':', $validated['window_start'] ?? '09:00'));
        [$endH, $endM] = array_map('intval', explode(':', $validated['window_end'] ?? '18:00'));

        $windowStart = $date->setTime($startH, $startM);
        $windowEnd = $date->setTime($endH, $endM);

        if ($windowEnd->lessThanOrEqualTo($windowStart)) {
            return response()->json(['message' => 'window_end must be after window_start'], 422);
        }

        $userIds = $validated['user_ids'] ?? [$user->id];
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $duration = (int) ($validated['duration'] ?? 30);

        $slots = $this->freeSlots->find($user, $userIds, $windowStart, $windowEnd, $duration);

        return response()->json([
            'data' => [
                'date' => $validated['date'],
                'duration' => $duration,
                'timezone' => $timezone,
                'slots' => $slots,
            ],
        ]);
    }

    /**
     * Expand a candidate RRULE into concrete occurrences so the event form can
     * show the user what a recurrence rule will actually produce. Nothing is
     * persisted; the expansion is bounded by the same guards as the aggregate.
     */
    public function recurrencePreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date',
            'all_day' => 'sometimes|boolean',
            'timezone' => 'nullable|string|max:64',
            'rrule' => 'nullable|string|max:1000',
            'exdates' => 'nullable|array',
            'exdates.*' => 'date',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $timezone = $validated['timezone'] ?? config('app.timezone', 'UTC');

        $from = CarbonImmutable::parse($validated['from'], $timezone)->startOfDay()->utc();
        $to = CarbonImmutable::parse($validated['to'], $timezone)->endOfDay()->utc();

        // Guard against unreasonably wide ranges.
        $maxDays = (int) config('calendar.max_range_days', 366);
        if ($from->diffInDays($to) > $maxDays) {
            return response()->json(['message' => "Диапазон дат не должен превышать {$maxDays} дней"], 422);
        }

        $startsAt = CarbonImmutable::parse($validated['starts_at'], $timezone);
        $endsAt = isset($validated['ends_at'])
            ? CarbonImmutable::parse($validated['ends_at'], $timezone)
            : null;
        $allDay = (bool) ($validated['all_day'] ?? false);

        $rrule = $this->normaliseRrule($validated['rrule'] ?? null);

        if ($rrule !== null) {
            try {
                $this->expander->validateRule($rrule, $startsAt, $timezone);
            } catch (InvalidArgumentException $e) {
                return $this->invalidRule($e);
            }
        }

        $result = $this->expander->expand(
            $startsAt,
            $endsAt,
            $allDay,
            $timezone,
            $rrule,
            $validated['exdates'] ?? null,
            $from,
            $to,
        );

        $items = array_map(fn (array $occurrence) => [
            'start' => $occurrence['start']->toIso8601String(),
            'end' => $occurrence['end']->toIso8601String(),
            'date' => $occurrence['start']->setTimezone($timezone)->format('Y-m-d'),
        ], $result['items']);

        return response()->json([
            'data' => [
                'from' => $validated['from'],
                'to' => $validated['to'],
                'timezone' => $timezone,
                'all_day' => $allDay,
                'rrule' => $rrule,
                'items' => $items,
                'truncated' => $result['truncated'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateEvent($request);

        $timezone = $validated['timezone'] ?? config('app.timezone', 'UTC');
        $rrule = $this->normaliseRrule($validated['rrule'] ?? null);

        if ($rrule !== null) {
            $ruleStart = $validated['starts_at'] ?? $validated['event_date'] ?? null;

            try {
                $this->expander->validateRule(
                    $rrule,
                    CarbonImmutable::parse($ruleStart, $timezone),
                    $timezone,
                );
            } catch (InvalidArgumentException $e) {
                return $this->invalidRule($e);
            }
        }

        $user = $this->actor($request);

        $event = new CalendarEvent();
        $event->user_id = $user->id;
        $event->organizer_id = $user->id;
        $event->source = CalendarEvent::SOURCE_LOCAL;
        $event->visibility = $validated['visibility'] ?? CalendarEvent::VISIBILITY_PRIVATE;

        $this->applyEventAttributes($event, $validated, $timezone, $rrule);
        $event->save();

        return response()->json(['data' => $event], 201);
    }

    public function update(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->isOrganizer($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $this->validateEvent($request, partial: true);

        $timezone = $validated['timezone'] ?? $event->timezoneName();
        $rrule = array_key_exists('rrule', $validated)
            ? $this->normaliseRrule($validated['rrule'])
            : $event->rrule;

        if (array_key_exists('rrule', $validated) && $rrule !== null) {
            $ruleStart = $validated['starts_at']
                ?? $validated['event_date']
                ?? $event->starts_at?->toIso8601String();

            try {
                $this->expander->validateRule(
                    $rrule,
                    CarbonImmutable::parse($ruleStart, $timezone),
                    $timezone,
                );
            } catch (InvalidArgumentException $e) {
                return $this->invalidRule($e);
            }

            $validated['rrule'] = $rrule;
        }

        $this->applyEventAttributes($event, $validated, $timezone, $rrule);
        $event->save();

        return response()->json(['data' => $event->fresh()]);
    }

    public function destroy(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->isOrganizer($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Deleting a single occurrence of a recurring event records an EXDATE
        // instead of removing the whole series.
        $occurrence = $request->query('occurrence_start');
        if (is_string($occurrence) && $occurrence !== '' && $event->isRecurring()) {
            $exdate = CarbonImmutable::parse($occurrence)->utc()->toIso8601String();

            $exdates = $event->exdates ?? [];
            $exdates[] = $exdate;

            $event->forceFill(['exdates' => array_values(array_unique($exdates))])->save();

            return response()->json([
                'message' => 'Вхождение удалено',
                'data' => $event->fresh(),
            ]);
        }

        $event->delete();

        return response()->json(['message' => 'Событие удалено']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyEventAttributes(CalendarEvent $event, array $validated, string $timezone, ?string $rrule): void
    {
        $fillable = [
            'title', 'description', 'location', 'color', 'visibility',
            'all_day', 'timezone', 'exdates',
        ];

        foreach ($fillable as $key) {
            if (array_key_exists($key, $validated)) {
                $event->{$key} = $validated[$key];
            }
        }

        if (array_key_exists('rrule', $validated) || $rrule !== null) {
            $event->rrule = $rrule;
        }

        if (array_key_exists('starts_at', $validated) && $validated['starts_at'] !== null) {
            $event->starts_at = CarbonImmutable::parse($validated['starts_at'], $timezone);
        } elseif (array_key_exists('event_date', $validated) && $validated['event_date'] !== null) {
            $event->event_date = $validated['event_date'];
        }

        if (array_key_exists('ends_at', $validated)) {
            $event->ends_at = $validated['ends_at'] !== null
                ? CarbonImmutable::parse($validated['ends_at'], $timezone)
                : null;
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(string $from, string $to): array
    {
        $timezone = config('app.timezone', 'UTC');

        return [
            CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $timezone)->endOfDay()->utc(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRange(Request $request, bool $withFilters = false): array
    {
        $rules = [
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ];

        if ($withFilters) {
            $rules += [
                'issue_id' => 'nullable|integer',
                'deal_id' => 'nullable|integer',
                'feed_id' => 'nullable|integer',
                'mine' => 'sometimes|boolean',
                'attending' => 'sometimes|boolean',
                'q' => 'nullable|string|max:255',
            ];
        }

        $validated = $request->validate($rules);

        $maxDays = (int) config('calendar.max_range_days', 366);
        $timezone = config('app.timezone', 'UTC');
        if (CarbonImmutable::parse($validated['from'], $timezone)
            ->startOfDay()
            ->diffInDays(CarbonImmutable::parse($validated['to'], $timezone)->endOfDay()) > $maxDays) {
            abort(response()->json(['message' => "Диапазон дат не должен превышать {$maxDays} дней"], 422));
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEvent(Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required_without:starts_at';
        $startPresence = $partial ? 'sometimes' : 'required_without:event_date';

        return $request->validate([
            'title' => ($partial ? 'sometimes' : 'required') . '|string|max:255',
            'description' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'event_date' => "{$presence}|nullable|date",
            'starts_at' => "{$startPresence}|nullable|date",
            'ends_at' => 'nullable|date',
            'all_day' => 'sometimes|boolean',
            'timezone' => 'nullable|string|max:64',
            'rrule' => 'nullable|string|max:1000',
            'exdates' => 'nullable|array',
            'exdates.*' => 'date',
            'color' => 'nullable|string|max:20',
            'visibility' => 'nullable|in:private,public',
        ]);
    }

    private function normaliseRrule(?string $rrule): ?string
    {
        if ($rrule === null) {
            return null;
        }

        $normalised = preg_replace('/^RRULE:/i', '', trim($rrule));

        return ($normalised === null || $normalised === '') ? null : $normalised;
    }

    private function invalidRule(InvalidArgumentException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => ['rrule' => [$e->getMessage()]],
        ], 422);
    }
}
