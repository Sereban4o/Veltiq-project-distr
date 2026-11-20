<?php

namespace App\Http\Controllers\Api\Calendar;

use App\Http\Controllers\Api\Calendar\Concerns\ResolvesCalendarActor;
use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\CalendarEventLink;
use App\Models\Issue;
use App\Services\Calendar\CalendarAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarEventLinkController extends Controller
{
    use ResolvesCalendarActor;

    public function __construct(
        private CalendarAggregator $aggregator,
    ) {
    }

    public function store(Request $request, CalendarEvent $event): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->isOrganizer($user) && ! $user->isAdmin()) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'issue_id' => 'nullable|integer|exists:issues,id',
            'deal_id' => 'nullable|integer|exists:crm_deals,id',
        ]);

        $hasIssue = ! empty($validated['issue_id']);
        $hasDeal = ! empty($validated['deal_id']);

        if ($hasIssue === $hasDeal) {
            return response()->json(['message' => 'Укажите ровно одну цель: задачу или сделку'], 422);
        }

        if ($hasIssue) {
            $visible = Issue::query()
                ->whereKey($validated['issue_id'])
                ->whereHas('project', fn ($q) => $q->visibleTo($user))
                ->exists();

            if (! $visible) {
                return response()->json(['message' => 'Нет доступа к задаче'], 403);
            }
        }

        $link = $event->links()->firstOrCreate([
            'issue_id' => $validated['issue_id'] ?? null,
            'deal_id' => $validated['deal_id'] ?? null,
        ], [
            'created_by' => $user->id,
        ]);

        return response()->json([
            'data' => $this->aggregator->present($this->reload($event), $user),
            'link_id' => $link->id,
        ], 201);
    }

    public function destroy(Request $request, CalendarEvent $event, CalendarEventLink $link): JsonResponse
    {
        $user = $this->actor($request);

        if (! $event->isOrganizer($user) && ! $user->isAdmin()) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if ((int) $link->event_id !== (int) $event->id) {
            return response()->json(['message' => 'Связь не относится к событию'], 404);
        }

        $link->delete();

        return response()->json(['data' => $this->aggregator->present($this->reload($event), $user)]);
    }

    private function reload(CalendarEvent $event): CalendarEvent
    {
        return $event->fresh([
            'attendees.user', 'links.issue', 'links.deal', 'organizer', 'user',
        ]);
    }
}
