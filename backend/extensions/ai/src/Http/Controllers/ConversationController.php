<?php

namespace App\Extensions\Ai\Http\Controllers;

use App\Extensions\Ai\Models\AiConversation;
use App\Extensions\Ai\Models\AiMessage;
use App\Extensions\Ai\Services\AiConversationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stored assistant conversations. Every query is owner-scoped: a conversation
 * that belongs to somebody else looks missing (404), like inaccessible pages
 * in the assistant itself.
 */
class ConversationController extends Controller
{
    public function __construct(private readonly AiConversationService $conversations)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);

        return response()->json(
            AiConversation::query()
                ->where('user_id', $request->user()->id)
                ->select(['id', 'title', 'last_activity_at', 'created_at'])
                ->withCount('messages')
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->paginate($perPage),
        );
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        $model = $this->conversations->find($request->user(), $conversation);
        $limit = min(max((int) $request->input('per_page', 200), 1), 200);

        $messages = $model->messages()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (AiMessage $message) => [
                'id' => (int) $message->id,
                'role' => (string) $message->role,
                'content' => (string) ($message->content ?? ''),
                'sources' => $message->sources ?? [],
                'error' => $message->error,
                'created_at' => optional($message->created_at)->toIso8601String(),
            ]);

        return response()->json(['data' => [
            'id' => (int) $model->id,
            'title' => $model->title,
            'last_activity_at' => optional($model->last_activity_at)->toIso8601String(),
            'messages' => $messages,
        ]]);
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $this->conversations->delete($this->conversations->find($request->user(), $conversation));

        return response()->json(['message' => 'Диалог удалён']);
    }
}
