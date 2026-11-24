<?php

namespace App\Extensions\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Calendar\Http\Controllers\Concerns\ResolvesCalendarActor;
use App\Extensions\Calendar\Models\CalendarEvent;
use App\Extensions\Calendar\Models\CalendarEventAttendee;
use App\Extensions\Calendar\Services\CalendarAggregator;
use App\Services\Tasksbase\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarParticipantController extends Controller
{
    use ResolvesCalendarActor;

    public function __construct(
        private CalendarAggregator $aggregator,
        private NotificationService $notifications,
    ) {
    }

    public function store(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->isOrganizer($user) && ! $user->isAdmin()) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'emails' => 'nullable|array',
            'emails.*' => 'email|max:255',
            'names' => 'nullable|array',
            'names.*' => 'nullable|string|max:255',
        ]);

        foreach (array_unique($validated['user_ids'] ?? []) as $userId) {
            $event->attendees()->updateOrCreate(
                ['user_id' => $userId],
                [
                    'status' => CalendarEventAttendee::STATUS_NEEDS_ACTION,
                    'role' => CalendarEventAttendee::ROLE_REQUIRED,
                    'invited_by' => $user->id,
                ],
            );

            if ((int) $userId !== (int) $user->id) {
                $this->notifications->notify(
                    (int) $userId,
                    'calendar_invite',
                    $event,
                    [
                        'event_id' => $event->id,
                        'title' => $event->title,
                        'starts_at' => $event->starts_at?->toIso8601String(),
                    ],
                    $user->id,
                    'Приглашение в календарь',
                    $event->title,
                );
            }
        }

        foreach ($validated['emails'] ?? [] as $index => $email) {
            $event->attendees()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $validated['names'][$index] ?? null,
                    'status' => CalendarEventAttendee::STATUS_NEEDS_ACTION,
                    'role' => CalendarEventAttendee::ROLE_REQUIRED,
                    'invited_by' => $user->id,
                ],
            );
        }

        return response()->json(['data' => $this->aggregator->present($this->reload($event), $user)], 201);
    }

    public function destroy(Request $request, CalendarEvent $event, CalendarEventAttendee $attendee): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->isOrganizer($user) && ! $user->isAdmin()) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if ((int) $attendee->event_id !== (int) $event->id) {
            return response()->json(['message' => 'Участник не относится к событию'], 404);
        }

        $attendee->delete();

        return response()->json(['data' => $this->aggregator->present($this->reload($event), $user)]);
    }

    public function rsvp(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->canView($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if ($event->isOrganizer($user)) {
            return response()->json(['message' => 'Организатор не может отвечать на приглашение'], 422);
        }

        $attendee = $event->attendees()->where('user_id', $user->id)->first();
        if ($attendee === null) {
            return response()->json(['message' => 'Вы не являетесь участником события'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:accepted,declined,tentative,needs_action',
        ]);

        $attendee->update([
            'status' => $validated['status'],
            'responded_at' => now(),
        ]);

        $organizerId = $event->organizer_id ?? $event->user_id;
        if ($organizerId && (int) $organizerId !== (int) $user->id) {
            $this->notifications->notify(
                (int) $organizerId,
                'calendar_rsvp',
                $event,
                [
                    'event_id' => $event->id,
                    'status' => $validated['status'],
                    'attendee' => $user->full_name,
                ],
                $user->id,
                'Ответ на приглашение',
                "{$user->full_name}: {$validated['status']}",
            );
        }

        return response()->json(['data' => $this->aggregator->present($this->reload($event), $user)]);
    }

    private function reload(CalendarEvent $event): CalendarEvent
    {
        return $event->fresh([
            'attendees.user', 'links.issue', 'links.deal', 'organizer', 'user',
        ]);
    }
}
