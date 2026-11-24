<?php

namespace App\Extensions\Calendar\Services;

use App\Models\User;
use App\Extensions\Calendar\Models\CalendarEvent;
use App\Extensions\Calendar\Models\CalendarEventAttendee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Reader;
use Throwable;

class IcsService
{
    private const PARTSTAT = [
        CalendarEventAttendee::STATUS_ACCEPTED => 'ACCEPTED',
        CalendarEventAttendee::STATUS_DECLINED => 'DECLINED',
        CalendarEventAttendee::STATUS_TENTATIVE => 'TENTATIVE',
        CalendarEventAttendee::STATUS_NEEDS_ACTION => 'NEEDS-ACTION',
    ];

    private const ROLE = [
        CalendarEventAttendee::ROLE_ORGANIZER => 'CHAIR',
        CalendarEventAttendee::ROLE_REQUIRED => 'REQ-PARTICIPANT',
        CalendarEventAttendee::ROLE_OPTIONAL => 'OPT-PARTICIPANT',
    ];

    public function exportEvent(CalendarEvent $event, User $user): string
    {
        $vcal = $this->newCalendar();
        $this->fillEvent($vcal, $event);

        return $vcal->serialize();
    }

    public function exportRange(User $user, CarbonInterface $from, CarbonInterface $to): string
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        $query = CalendarEvent::query()
            ->with(['attendees.user', 'organizer', 'issues', 'deals'])
            ->where('starts_at', '<', $to)
            ->orderBy('starts_at');

        if (! $user->isAdmin()) {
            $query->visibleTo($user);
        }

        $vcal = $this->newCalendar();

        foreach ($query->get() as $event) {
            $isRecurring = $event->isRecurring();
            $end = $event->ends_at ?? $event->starts_at;

            if (! $isRecurring && $end !== null && $end->lessThan($from)) {
                continue;
            }

            $this->fillEvent($vcal, $event);
        }

        return $vcal->serialize();
    }

    /**
     * Import an uploaded ICS file into the user's local events.
     *
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function import(string $ics, User $user): array
    {
        $vcal = $this->read($ics);
        $vevents = $vcal->select('VEVENT');

        $max = (int) config('calendar.ics.max_events', 500);
        if (count($vevents) > $max) {
            throw new RuntimeException("Файл содержит слишком много событий (максимум {$max})");
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($vevents as $vevent) {
            // Single-occurrence overrides are not supported for local imports.
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $skipped++;

                continue;
            }

            $parsed = $this->parseVEvent($vevent);
            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $uid = $parsed['external_uid'];

            $existing = CalendarEvent::query()
                ->where('user_id', $user->id)
                ->where('source', CalendarEvent::SOURCE_IMPORT)
                ->where('external_uid', $uid)
                ->first();

            $payload = [
                'user_id' => $user->id,
                'organizer_id' => $user->id,
                'title' => $parsed['title'],
                'description' => $parsed['description'],
                'location' => $parsed['location'],
                'starts_at' => $parsed['starts_at'],
                'ends_at' => $parsed['ends_at'],
                'all_day' => $parsed['all_day'],
                'timezone' => $parsed['timezone'],
                'rrule' => $parsed['rrule'],
                'exdates' => $parsed['exdates'],
                'source' => CalendarEvent::SOURCE_IMPORT,
                'external_uid' => $uid,
                'external_sequence' => $parsed['sequence'],
                'visibility' => CalendarEvent::VISIBILITY_PRIVATE,
            ];

            if ($existing !== null) {
                $existing->update($payload);
                $updated++;
            } else {
                CalendarEvent::create($payload);
                $imported++;
            }
        }

        return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Normalise the VEVENTs of a subscribed feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseFeed(string $ics): array
    {
        $vcal = $this->read($ics);
        $rows = [];

        foreach ($vcal->select('VEVENT') as $vevent) {
            $parsed = $this->parseVEvent($vevent);
            if ($parsed === null) {
                continue;
            }

            $status = strtoupper((string) ($vevent->STATUS ?? ''));
            $recurrenceId = isset($vevent->{'RECURRENCE-ID'})
                ? (string) $vevent->{'RECURRENCE-ID'}
                : '';

            $rows[] = $parsed + [
                'recurrence_id' => $recurrenceId,
                'is_cancelled' => $status === 'CANCELLED',
                'raw' => ['ics' => $vevent->serialize()],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseVEvent(VEvent $vevent): ?array
    {
        if (! isset($vevent->DTSTART)) {
            return null;
        }

        try {
            $dtstart = $vevent->DTSTART;
            $allDay = $dtstart->getValueType() === 'DATE' || ! $dtstart->hasTime();
            $startDateTime = $dtstart->getDateTime();
        } catch (Throwable) {
            return null;
        }

        $timezone = $startDateTime->getTimezone()?->getName() ?: 'UTC';
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            // Fixed offsets ("+03:00") are not valid IANA identifiers.
            $timezone = 'UTC';
        }

        $startsAt = CarbonImmutable::instance($startDateTime)->utc();

        $endsAt = null;
        if (isset($vevent->DTEND)) {
            try {
                $endsAt = CarbonImmutable::instance($vevent->DTEND->getDateTime())->utc();
            } catch (Throwable) {
                $endsAt = null;
            }
        }

        $summary = trim((string) ($vevent->SUMMARY ?? ''));
        $description = trim((string) ($vevent->DESCRIPTION ?? ''));
        $location = trim((string) ($vevent->LOCATION ?? ''));

        $rrule = isset($vevent->RRULE) ? (string) $vevent->RRULE : null;
        $rrule = $rrule !== null ? preg_replace('/^RRULE:/i', '', $rrule) : null;

        $exdates = [];
        foreach ($vevent->select('EXDATE') as $property) {
            try {
                foreach ($property->getDateTimes() as $dateTime) {
                    $exdates[] = $allDay
                        ? CarbonImmutable::instance($dateTime)->format('Y-m-d')
                        : CarbonImmutable::instance($dateTime)->utc()->format('Y-m-d\TH:i:s\Z');
                }
            } catch (Throwable) {
                // Ignore malformed EXDATE values.
            }
        }

        $uid = trim((string) ($vevent->UID ?? ''));
        if ($uid === '') {
            $uid = 'import-' . Str::uuid()->toString();
        }

        return [
            'external_uid' => $uid,
            'title' => $summary !== '' ? $summary : 'Без названия',
            'description' => $description !== '' ? $description : null,
            'location' => $location !== '' ? $location : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => $allDay,
            'timezone' => $timezone,
            'rrule' => $rrule !== '' ? $rrule : null,
            'exdates' => $exdates ?: null,
            'sequence' => isset($vevent->SEQUENCE) ? (int) (string) $vevent->SEQUENCE : null,
        ];
    }

    private function newCalendar(): VCalendar
    {
        $vcal = new VCalendar();
        $vcal->PRODID = '-//tasksbase//calendar//EN';

        return $vcal;
    }

    private function fillEvent(VCalendar $vcal, CalendarEvent $event): void
    {
        /** @var VEvent $vevent */
        $vevent = $vcal->add('VEVENT');

        $timezone = $event->timezoneName();
        $start = CarbonImmutable::instance($event->starts_at);

        $vevent->UID = $event->external_uid ?: "calendar-event-{$event->id}@tasksbase";
        $vevent->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $vevent->SUMMARY = $event->title;

        if ($event->description) {
            $vevent->DESCRIPTION = $event->description;
        }
        if ($event->location) {
            $vevent->LOCATION = $event->location;
        }

        if ($event->all_day) {
            $vevent->add('DTSTART', $start->setTimezone($timezone)->format('Ymd'), [], 'DATE');
            if ($event->ends_at) {
                $vevent->add('DTEND', CarbonImmutable::instance($event->ends_at)->setTimezone($timezone)->format('Ymd'), [], 'DATE');
            }
        } else {
            $vevent->add('DTSTART', $start->setTimezone($timezone)->format('Ymd\THis'), ['TZID' => $timezone]);
            if ($event->ends_at) {
                $vevent->add(
                    'DTEND',
                    CarbonImmutable::instance($event->ends_at)->setTimezone($timezone)->format('Ymd\THis'),
                    ['TZID' => $timezone],
                );
            }
        }

        if ($event->rrule) {
            $vevent->add('RRULE', $event->rrule);
        }

        foreach ($event->exdates ?? [] as $exdate) {
            $date = CarbonImmutable::parse((string) $exdate);
            if ($event->all_day) {
                $vevent->add('EXDATE', $date->setTimezone($timezone)->format('Ymd'), [], 'DATE');
            } else {
                $vevent->add('EXDATE', $date->setTimezone($timezone)->format('Ymd\THis'), ['TZID' => $timezone]);
            }
        }

        $organizer = $event->organizer ?? $event->user;
        if ($organizer) {
            $prop = $vevent->add('ORGANIZER', 'mailto:' . $organizer->email);
            $prop['CN'] = $organizer->full_name;
        }

        $event->loadMissing('attendees.user');

        foreach ($event->attendees as $attendee) {
            $email = $attendee->email ?: $attendee->user?->email;
            if (! $email) {
                continue;
            }

            $prop = $vevent->add('ATTENDEE', 'mailto:' . $email);

            // Keep the property line short: only emit parameters that carry
            // information (an empty CN or a default ROLE merely inflates the
            // line past the 75-octet fold boundary and splits the address).
            $name = $attendee->name ?: $attendee->user?->full_name;
            if ($name) {
                $prop['CN'] = $name;
            }

            $prop['PARTSTAT'] = self::PARTSTAT[$attendee->status] ?? 'NEEDS-ACTION';

            if ($attendee->role !== CalendarEventAttendee::ROLE_REQUIRED) {
                $prop['ROLE'] = self::ROLE[$attendee->role] ?? 'REQ-PARTICIPANT';
            }
        }

        if ($event->external_sequence !== null) {
            $vevent->SEQUENCE = $event->external_sequence;
        }

        $vevent->STATUS = 'CONFIRMED';
    }

    private function read(string $ics): VCalendar
    {
        try {
            $document = Reader::read($ics);
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось разобрать ICS-файл', 0, $e);
        }

        if (! $document instanceof VCalendar) {
            throw new RuntimeException('Файл не является календарём iCalendar');
        }

        return $document;
    }
}
