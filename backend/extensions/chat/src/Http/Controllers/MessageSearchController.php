<?php

namespace App\Extensions\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Extensions\Chat\Http\Controllers\Concerns\EnsuresChatMembership;
use App\Services\Search\ChatMessageSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Word search over the blind index (message bodies are encrypted at rest).
 */
class MessageSearchController extends Controller
{
    use EnsuresChatMembership;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'chat_id' => 'nullable|integer|exists:chats,id',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        if (!empty($validated['chat_id'])) {
            $chat = Chat::findOrFail((int) $validated['chat_id']);
            $this->ensureUserIsMember($chat);
            $chatId = (int) $chat->id;
        } else {
            $chatId = null;
        }

        $perPage = (int) ($validated['per_page'] ?? 30);

        $messageIds = app(ChatMessageSearch::class)
            ->messageIds(Auth::user(), (string) $validated['q'], $chatId);

        if ($messageIds === []) {
            return $this->emptyPage($perPage);
        }

        $messages = Message::whereIn('id', $messageIds)
            ->with(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions'])
            ->withCount('replies')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($messages);
    }

    private function emptyPage(int $perPage): JsonResponse
    {
        return response()->json([
            'current_page' => 1,
            'data' => [],
            'per_page' => $perPage,
            'total' => 0,
        ]);
    }
}
