<?php

namespace App\Http\Controllers\Api\Calendar;

use App\Http\Controllers\Api\Calendar\Concerns\ResolvesCalendarActor;
use App\Http\Controllers\Api\Calendar\Concerns\ResolvesCalendarRange;
use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Services\Calendar\IcsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class CalendarIcsController extends Controller
{
    use ResolvesCalendarActor;
    use ResolvesCalendarRange;

    public function __construct(
        private IcsService $ics,
    ) {
    }

    public function exportEvent(Request $request, CalendarEvent $event): Response
    {
        $user = $this->actor($request);

        if (! $event->canView($user)) {
            abort(403, 'Permission denied');
        }

        $body = $this->ics->exportEvent($event, $user);

        return $this->download($body, "event-{$event->id}.ics");
    }

    public function exportRange(Request $request): Response
    {
        $user = $this->actor($request);
        [$from, $to] = $this->resolveRange($request);

        $body = $this->ics->exportRange($user, $from, $to);

        return $this->download($body, 'calendar.ics');
    }

    public function import(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        $validated = $request->validate([
            'file' => 'required|file|max:5120',
        ]);

        $maxBytes = (int) config('calendar.ics.max_bytes', 5 * 1024 * 1024);
        $content = $validated['file']->get();

        if (strlen($content) > $maxBytes) {
            return response()->json(['message' => 'Файл превышает допустимый размер'], 422);
        }

        try {
            $result = $this->ics->import($content, $user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    private function download(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
