<?php

namespace App\Services\Calendar;

use App\Services\Core\CoreClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Sabre\VObject\Recur\EventIterator;
use Sabre\VObject\Recur\NoInstancesException;
use Throwable;

/**
 * Expands RFC 5545 recurrence rules into concrete occurrences inside a range.
 * All expansion happens server-side; the frontend never evaluates RRULE.
 */
class RecurrenceExpander
{
    public function __construct(private readonly CoreClient $core)
    {
    }

    /**
     * @return array{items: array<int, array{start: CarbonImmutable, end: CarbonImmutable}>, truncated: bool}
     */
    public function expand(
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        bool $allDay,
        string $timezone,
        ?string $rrule,
        ?array $exdates,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $limit = null,
    ): array {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        $duration = $endsAt !== null
            ? abs(CarbonImmutable::instance($endsAt)->diffInSeconds(CarbonImmutable::instance($startsAt), true))
            : 0;

        if (empty($rrule)) {
            return $this->singleOccurrence($startsAt, $duration, $from, $to);
        }

        $limit ??= (int) config('calendar.max_occurrences_per_event', 1000);

        // The compiled core owns RRULE expansion when it supports the rule.
        $remote = $this->core->expandRecurrence(
            CarbonImmutable::instance($startsAt)->utc()->toIso8601String(),
            (int) $duration,
            (string) $rrule,
            array_map(
                static function ($exdate): string {
                    // Exclusions arrive as Carbon instances or raw ICS strings.
                    return $exdate instanceof \DateTimeInterface
                        ? CarbonImmutable::instance($exdate)->utc()->toIso8601String()
                        : CarbonImmutable::parse((string) $exdate)->utc()->toIso8601String();
                },
                $exdates ?? [],
            ),
            $from->toIso8601String(),
            $to->toIso8601String(),
            (int) $limit,
            $timezone,
        );

        if ($remote !== false) {
            return [
                'items' => array_map(static fn (array $item): array => [
                    'start' => CarbonImmutable::parse($item['start'])->utc(),
                    'end' => CarbonImmutable::parse($item['end'])->utc(),
                ], $remote['items']),
                'truncated' => $remote['truncated'],
            ];
        }

        try {
            $iterator = new EventIterator($this->buildComponent($startsAt, $timezone, $rrule, $exdates ?? [], $allDay));
        } catch (NoInstancesException) {
            return ['items' => [], 'truncated' => false];
        }

        $iterator->fastForward($from->toDateTimeImmutable());

        $items = [];
        $truncated = false;

        while ($iterator->valid()) {
            $current = $iterator->current();
            if ($current === null) {
                break;
            }

            $start = CarbonImmutable::instance($current)->utc();
            if ($start->greaterThan($to)) {
                break;
            }

            if ($start->greaterThanOrEqualTo($from)) {
                $items[] = [
                    'start' => $start,
                    'end' => $start->addSeconds($duration),
                ];

                if (count($items) >= $limit) {
                    $truncated = true;
                    break;
                }
            }

            $iterator->next();
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * @return array{items: array<int, array{start: CarbonImmutable, end: CarbonImmutable}>, truncated: bool}
     */
    private function singleOccurrence(
        CarbonInterface $startsAt,
        int $duration,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $start = CarbonImmutable::instance($startsAt)->utc();
        $end = $start->addSeconds($duration);

        // Zero-length events match when their start falls inside the range.
        $overlaps = $duration > 0
            ? $start->lessThan($to) && $end->greaterThanOrEqualTo($from)
            : $start->greaterThanOrEqualTo($from) && $start->lessThanOrEqualTo($to);

        if (! $overlaps) {
            return ['items' => [], 'truncated' => false];
        }

        return ['items' => [['start' => $start, 'end' => $end]], 'truncated' => false];
    }

    /**
     * Validate an RRULE and throw InvalidArgumentException when it is malformed.
     */
    public function validateRule(string $rrule, CarbonInterface $start, string $timezone): void
    {
        try {
            $iterator = new EventIterator($this->buildComponent($start, $timezone, $rrule, [], false));
            // Force sabre to parse the rule.
            $iterator->valid();
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Некорректное правило повторения: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function nextOccurrences(
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        bool $allDay,
        string $timezone,
        ?string $rrule,
        ?array $exdates,
        int $count,
    ): array {
        $to = CarbonImmutable::instance($startsAt)->addYears(5);

        return $this->expand(
            $startsAt,
            $endsAt,
            $allDay,
            $timezone,
            $rrule,
            $exdates,
            CarbonImmutable::instance($startsAt),
            $to,
            max(1, $count),
        )['items'];
    }

    private function buildComponent(
        CarbonInterface $start,
        string $timezone,
        string $rrule,
        array $exdates,
        bool $allDay,
    ): VEvent {
        $start = CarbonImmutable::instance($start);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//tasksbase//calendar//EN',
            'BEGIN:VEVENT',
            'UID:recurrence@tasksbase',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        ];

        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $start->setTimezone($timezone)->format('Ymd');
        } else {
            $lines[] = 'DTSTART;TZID=' . $timezone . ':' . $start->setTimezone($timezone)->format('Ymd\THis');
        }

        $lines[] = 'RRULE:' . $rrule;

        if (! empty($exdates)) {
            $formatted = [];
            foreach ($exdates as $exdate) {
                $date = $exdate instanceof \DateTimeInterface
                    ? CarbonImmutable::instance($exdate)
                    : CarbonImmutable::parse((string) $exdate);

                $formatted[] = $allDay
                    ? $date->setTimezone($timezone)->format('Ymd')
                    : $date->setTimezone($timezone)->format('Ymd\THis');
            }

            $lines[] = $allDay
                ? 'EXDATE;VALUE=DATE:' . implode(',', $formatted)
                : 'EXDATE;TZID=' . $timezone . ':' . implode(',', $formatted);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return Reader::read(implode("\r\n", $lines))->VEVENT;
    }
}
