<?php

namespace App\Extensions\Calendar\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Calendar\Http\Controllers\Concerns\ResolvesCalendarActor;
use App\Extensions\Calendar\Jobs\SyncCalendarFeed;
use App\Extensions\Calendar\Models\CalendarFeed;
use App\Extensions\Calendar\Services\SafeUrlFetcher;
use App\Extensions\Calendar\Services\UnsafeUrlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarFeedController extends Controller
{
    use ResolvesCalendarActor;

    public function __construct(
        private SafeUrlFetcher $fetcher,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $feeds = CalendarFeed::query()
            ->where('user_id', $this->actor($request)->id)
            ->withCount('feedEvents')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $feeds]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);

        if (($error = $this->validateUrl($validated['url'])) !== null) {
            return $error;
        }

        $feed = CalendarFeed::create($validated + ['user_id' => $this->actor($request)->id]);

        return response()->json(['data' => $feed], 201);
    }

    public function update(Request $request, CalendarFeed $feed): JsonResponse
    {
        $user = $this->actor($request);

        if (! $feed->isOwnedBy($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $this->validateData($request, partial: true);

        if (isset($validated['url']) && ($error = $this->validateUrl($validated['url'])) !== null) {
            return $error;
        }

        $feed->update($validated);

        return response()->json(['data' => $feed->fresh()]);
    }

    public function destroy(Request $request, CalendarFeed $feed): JsonResponse
    {
        $user = $this->actor($request);

        if (! $feed->isOwnedBy($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $feed->delete();

        return response()->json(['message' => 'Подписка удалена']);
    }

    public function sync(Request $request, CalendarFeed $feed): JsonResponse
    {
        $user = $this->actor($request);

        if (! $feed->isOwnedBy($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        SyncCalendarFeed::dispatch($feed->id);

        return response()->json(['data' => ['status' => 'queued']], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$required}|string|max:120",
            'url' => "{$required}|string|url|max:2000",
            'color' => 'nullable|string|max:20',
            'sync_interval_minutes' => [
                'sometimes',
                'integer',
                'min:' . (int) config('calendar.feed.min_interval_minutes', 15),
                'max:10080',
            ],
            'is_active' => 'sometimes|boolean',
        ]);
    }

    private function validateUrl(string $url): ?JsonResponse
    {
        try {
            $this->fetcher->assertSafeUrl($url);
        } catch (UnsafeUrlException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['url' => [$e->getMessage()]],
            ], 422);
        }

        return null;
    }
}
