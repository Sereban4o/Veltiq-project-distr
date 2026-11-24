<?php

namespace App\Extensions\Chat\Http\Controllers;

use App\Events\PinUpdated;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Extensions\Chat\Http\Controllers\Concerns\EnsuresChatMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PinController extends Controller
{
    use EnsuresChatMembership;

    /** Maximum number of pinned messages per chat. */
    private const LIMIT = 100;

    public function index(Chat $chat): JsonResponse
    {
        $this->ensureUserIsMember($chat);

        $pins = PinnedMessage::where('chat_id', $chat->id)
            ->whereHas('message')
            ->with([
                'message.sender:id,first_name,last_name,avatar',
                'message.files.file',
                'message.reactions',
            ])
            ->withCount('message')
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $pins]);
    }

    public function store(Chat $chat, Message $message): JsonResponse
    {
        $this->ensureUserIsMember($chat);
        $this->ensureCanPin($chat);

        if ((int) $message->chat_id !== (int) $chat->id) {
            abort(404, 'Сообщение не найдено');
        }

        $exists = PinnedMessage::where('chat_id', $chat->id)
            ->where('message_id', $message->id)
            ->exists();

        if (!$exists && PinnedMessage::where('chat_id', $chat->id)->count() >= self::LIMIT) {
            return response()->json(['message' => 'Достигнут лимит закреплённых сообщений'], 422);
        }

        $pin = PinnedMessage::firstOrCreate(
            ['chat_id' => $chat->id, 'message_id' => $message->id],
            ['pinned_by' => Auth::id()],
        );

        event(new PinUpdated($chat, $message, true, (int) Auth::id()));

        return response()->json(['data' => $pin], 201);
    }

    public function destroy(Chat $chat, Message $message): JsonResponse
    {
        $this->ensureUserIsMember($chat);
        $this->ensureCanPin($chat);

        if ((int) $message->chat_id !== (int) $chat->id) {
            abort(404, 'Сообщение не найдено');
        }

        PinnedMessage::where('chat_id', $chat->id)
            ->where('message_id', $message->id)
            ->delete();

        event(new PinUpdated($chat, $message, false, (int) Auth::id()));

        return response()->json(['message' => 'Сообщение откреплено']);
    }

    private function ensureCanPin(Chat $chat): void
    {
        if ($chat->type === 'channel' && !$this->isChatAdmin($chat)) {
            abort(403, 'Только администратор канала может закреплять сообщения');
        }
    }
}
