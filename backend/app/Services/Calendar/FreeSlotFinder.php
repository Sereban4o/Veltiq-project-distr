<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\Core\CoreClient;
use Carbon\CarbonImmutable;

/**
 * Finds free slots for one or more users within a day.
 *
 * Only events the requesting user is allowed to see are considered (the
 * {@see CalendarEvent::scopeVisibleTo} scope). Recurring events contribute
 * their base occurrence only; expanding RRULEs is left to the calendar view.
 */
class FreeSlotFinder
{
    /** Candidate start granularity, in minutes. */
    private const STEP_MINUTES = 15;

    /** Fallback length for an event without an end, in minutes. */
    private const DEFAULT_EVENT_MINUTES = 30;

    public function __construct(private readonly CoreClient $core)
    {
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{start: string, end: string}>
     */
    public function find(
        User $user,
        array $userIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        int $durationMinutes,
    ): array {
        // Access control stays here: the caller decides which events are visible,
        // and only the resulting busy intervals leave the process.
        $busy = $this->busyIntervals($user, $userIds, $windowStart, $windowEnd);
        $duration = max(self::STEP_MINUTES, $durationMinutes);

        // The compiled core service owns the geometry; PHP remains the fallback
        // so the product keeps working when the container is absent.
        $remote = $this->core->freeSlots($windowStart, $windowEnd, $busy, $duration);

        if ($remote !== null) {
            return $remote;
        }

        return $this->localSlots($busy, $windowStart, $windowEnd, $duration);
    }

    /**
     * Local reference implementation (used when the core service is off).
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     * @return array<int, array{start: string, end: string}>
     */
    private function localSlots(
        array $busy,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        int $duration,
    ): array {
        $slots = [];
        $cursor = $windowStart;

        while ($cursor->addMinutes($duration)->lessThanOrEqualTo($windowEnd)) {
            $slotEnd = $cursor->addMinutes($duration);

            if (! $this->overlaps($busy, $cursor, $slotEnd)) {
                $slots[] = [
                    'start' => $cursor->toIso8601String(),
                    'end' => $slotEnd->toIso8601String(),
                ];
            }

            $cursor = $cursor->addMinutes(self::STEP_MINUTES);
        }

        return $slots;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function busyIntervals(
        User $user,
        array $userIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): array {
        if ($userIds === []) {
            return [];
        }

        $events = CalendarEvent::query()
            ->visibleTo($user)
            ->where(function ($query) use ($userIds) {
                $query->whereIn('user_id', $userIds)
                    ->orWhereIn('organizer_id', $userIds)
                    ->orWhereHas('attendees', fn ($a) => $a->whereIn('user_id', $userIds));
            })
            ->where('starts_at', '<', $windowEnd)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $windowStart))
            ->get(['starts_at', 'ends_at', 'all_day']);

        $intervals = [];

        foreach ($events as $event) {
            $startsAt = $event->getAttribute('starts_at');

            if ($event->all_day || $startsAt === null) {
                $intervals[] = [$windowStart, $windowEnd];

                continue;
            }

            $start = CarbonImmutable::instance($event->starts_at);
            $end = $event->ends_at
                ? CarbonImmutable::instance($event->ends_at)
                : $start->addMinutes(self::DEFAULT_EVENT_MINUTES);

            $start = $start->greaterThan($windowStart) ? $start : $windowStart;
            $end = $end->lessThan($windowEnd) ? $end : $windowEnd;

            if ($end->greaterThan($start)) {
                $intervals[] = [$start, $end];
            }
        }

        return $this->merge($intervals);
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function merge(array $intervals): array
    {
        if ($intervals === []) {
            return [];
        }

        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);

        $merged = [$intervals[0]];
        foreach (array_slice($intervals, 1) as [$start, $end]) {
            $lastIndex = count($merged) - 1;
            [$lastStart, $lastEnd] = $merged[$lastIndex];

            if ($start->lessThanOrEqualTo($lastEnd)) {
                $merged[$lastIndex] = [$lastStart, $end->greaterThan($lastEnd) ? $end : $lastEnd];
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     */
    private function overlaps(array $busy, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($start->lessThan($busyEnd) && $end->greaterThan($busyStart)) {
                return true;
            }
        }

        return false;
    }
}
