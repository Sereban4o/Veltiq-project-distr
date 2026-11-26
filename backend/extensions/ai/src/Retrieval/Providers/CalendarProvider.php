<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Retrieval\Concerns\MatchesKeywords;
use App\Extensions\Calendar\Models\CalendarEvent;
use App\Models\User;
use App\Support\SearchText;

/**
 * Calendar events the user can see in the calendar section (`visibleTo`):
 * own events, events they attend and public company events.
 */
final class CalendarProvider implements AiSourceProvider
{
    use MatchesKeywords;

    private const PAST_DAYS = 7;

    private const FUTURE_DAYS = 60;

    /**
     * Schedule questions ("what is on my calendar this week") usually share no
     * words with an event title, so they skip the text filter and get the
     * nearest events of the window instead.
     */
    private const INTENT = '/встреч|календар|событ|расписан|созвон|план[её]рк|свободн|занят|недел|сегодня|завтра|послезавтра/iu';

    public function types(): array
    {
        return ['calendar'];
    }

    public function extension(): ?string
    {
        return 'calendar';
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        $from = now()->subDays(self::PAST_DAYS)->startOfDay();
        $to = now()->addDays(self::FUTURE_DAYS)->endOfDay();

        $events = CalendarEvent::query()
            ->visibleTo($user)
            ->where('starts_at', '<=', $to)
            ->where(function ($q) use ($from): void {
                $q->where('ends_at', '>=', $from)
                    ->orWhere(fn ($inner) => $inner->whereNull('ends_at')->where('starts_at', '>=', $from));
            })
            ->when(
                ! $this->matchesIntent($query, self::INTENT),
                fn ($q) => $this->whereMatchesTokens($q, ['title', 'description', 'location'], $query),
            )
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();

        return ['calendar' => $events->map(function (CalendarEvent $event): array {
            $when = $event->starts_at?->format('d.m.Y H:i') ?? '';
            $place = trim((string) $event->location);
            $description = SearchText::plain((string) $event->description);

            $snippet = implode(' · ', array_filter([$when, $place, $description]));

            return AiSource::make(
                'calendar',
                (int) $event->id,
                (string) $event->title,
                '/calendar?date=' . ($event->starts_at?->format('Y-m-d') ?? '') . '&event=' . (int) $event->id,
                $snippet,
                [
                    'starts_at' => optional($event->starts_at)->toIso8601String(),
                    'ends_at' => optional($event->ends_at)->toIso8601String(),
                    'all_day' => (bool) $event->all_day,
                    'location' => $event->location,
                    'visibility' => $event->visibility,
                ],
            );
        })->all()];
    }
}
