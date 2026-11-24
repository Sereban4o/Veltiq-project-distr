<?php

namespace App\Extensions\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Extensions\Chat\Jobs\PushChatMessageNotifications;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function index(Chat $chat, Request $request)
    {
        $this->ensureUserIsMember($chat);

        $perPage = min(max((int) $request->input('per_page', 50), 1), 100);
        $before = $request->input('before');

        $query = Message::where('chat_id', $chat->id)
            ->with(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions'])
            ->withCount('replies')
            ->orderByDesc('created_at');

        if ($before) {
            $query->where('created_at', '<', $before);
        }

        $messages = $query->paginate($perPage);

        return response()->json($messages);
    }

    public function store(Request $request, Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        $validated = $request->validate([
            'body' => 'nullable|string|max:5000',
            'type' => 'sometimes|in:text,image,file,system',
            'parent_id' => 'nullable|exists:messages,id',
            'client_id' => 'nullable|string|max:64',
            'file_ids' => 'sometimes|array|max:10',
            'file_ids.*' => 'exists:files,id',
        ]);

        // A reply may only reference a message that lives in the same chat.
        if (!empty($validated['parent_id'])) {
            $parentInChat = Message::where('id', $validated['parent_id'])
                ->where('chat_id', $chat->id)
                ->exists();

            if (!$parentInChat) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Родительское сообщение должно принадлежать этому чату.',
                ]);
            }
        }

        // Idempotent send: an offline outbox retry with the same client_id must
        // return the already-created message instead of creating a duplicate.
        if (!empty($validated['client_id'])) {
            $existing = Message::where('chat_id', $chat->id)
                ->where('sender_id', Auth::id())
                ->where('client_id', $validated['client_id'])
                ->first();

            if ($existing) {
                return response()->json(
                    $existing->load(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions']),
                    200,
                );
            }
        }

        $message = DB::transaction(function () use ($validated, $chat) {
            $message = Message::create([
                'chat_id' => $chat->id,
                'sender_id' => Auth::id(),
                'body' => $validated['body'] ?? '',
                'type' => $validated['type'] ?? 'text',
                'parent_id' => $validated['parent_id'] ?? null,
                'client_id' => $validated['client_id'] ?? null,
            ]);

            // Attach files if provided (only files owned by the sender)
            if (!empty($validated['file_ids'])) {
                foreach ($validated['file_ids'] as $fileId) {
                    $file = \App\Models\File::where('id', $fileId)
                        ->where('uploaded_by', Auth::id())
                        ->firstOrFail();

                    $message->files()->create([
                        'file_id' => $file->id,
                        'mime_type' => $file->mime_type,
                        'size' => $file->size,
                        'thumbnail_url' => $file->thumbnail_url,
                    ]);
                }
            }

            // Update chat last_message_at
            $chat->update(['last_message_at' => now()]);

            return $message;
        });

        // Broadcast message via event
        event(new \App\Events\MessageCreated($message, $chat));

        // Push notification to every member except the author (best-effort, queued).
        PushChatMessageNotifications::dispatch($message->id, $chat->id);

        return response()->json($message->load(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions']), 201);
    }

    public function show(Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        if ($message->chat_id !== $chat->id) {
            abort(404, 'Сообщение не найдено');
        }

        return response()->json($message->load(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions', 'replies.sender:id,first_name,last_name,avatar']));
    }

    public function update(Request $request, Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        if ($message->sender_id !== Auth::id()) {
            abort(403, 'Только автор может редактировать сообщение');
        }

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message->update([
            'body' => $validated['body'],
            'edit_count' => $message->edit_count + 1,
            'edited_at' => now(),
        ]);

        event(new \App\Events\MessageUpdated($message, $chat));

        return response()->json($message->load(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions']));
    }

    public function destroy(Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        if ($message->sender_id !== Auth::id() && !$chat->members()->where('user_id', Auth::id())
            ->wherePivot('role', 'admin')->exists()) {
            abort(403, 'Только автор или администратор может удалить сообщение');
        }

        $message->markAsDeleted();

        event(new \App\Events\MessageDeleted($message, $chat));

        return response()->json(['message' => 'Сообщение удалено']);
    }

    public function addReaction(Request $request, Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        $validated = $request->validate([
            'emoji' => 'required|string|max:20',
        ]);

        $reaction = MessageReaction::updateOrCreate(
            [
                'message_id' => $message->id,
                'user_id' => Auth::id(),
                'emoji' => $validated['emoji'],
            ],
            [],
        );

        return response()->json($reaction);
    }

    public function removeReaction(Chat $chat, Message $message, string $emoji)
    {
        $this->ensureUserIsMember($chat);

        MessageReaction::where('message_id', $message->id)
            ->where('user_id', Auth::id())
            ->where('emoji', $emoji)
            ->delete();

        return response()->json(['message' => 'Реакция удалена']);
    }

    public function reply(Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        return response()->json([
            'parent_id' => $message->id,
            'parent_message' => $message->body,
            'sender' => $message->sender,
        ]);
    }

    /**
     * Thread replies of a message, oldest first.
     */
    public function replies(Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        if ($message->chat_id !== $chat->id) {
            abort(404, 'Сообщение не найдено');
        }

        $replies = Message::where('parent_id', $message->id)
            ->with(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $replies]);
    }

    /**
     * Forward a message into one or more chats the current user belongs to.
     * The original sender/chat is preserved in `metadata.forward_from`.
     */
    public function forward(Request $request, Chat $chat, Message $message)
    {
        $this->ensureUserIsMember($chat);

        if ($message->chat_id !== $chat->id) {
            abort(404, 'Сообщение не найдено');
        }

        $validated = $request->validate([
            'chat_ids' => 'required|array|min:1|max:5',
            'chat_ids.*' => 'integer|distinct|exists:chats,id',
        ]);

        $targets = Chat::whereIn('id', $validated['chat_ids'])->get();

        foreach ($targets as $target) {
            $this->ensureUserIsMember($target);
        }

        $sender = $message->sender;

        $forwarded = DB::transaction(function () use ($targets, $message, $sender) {
            $created = [];

            foreach ($targets as $target) {
                $metadata = $message->metadata ?? [];
                $metadata['forward_from'] = [
                    'message_id' => $message->id,
                    'chat_id' => $message->chat_id,
                    'chat_name' => $message->chat?->name,
                    'sender_id' => $message->sender_id,
                    'sender_name' => $sender
                        ? trim($sender->first_name . ' ' . $sender->last_name)
                        : null,
                    'forwarded_at' => now()->toIso8601String(),
                ];

                $copy = Message::create([
                    'chat_id' => $target->id,
                    'sender_id' => Auth::id(),
                    'body' => $message->body,
                    'type' => $message->type === 'system' ? 'text' : $message->type,
                    'metadata' => $metadata,
                ]);

                // Keep the attachments of the original message on the copy.
                foreach ($message->files as $file) {
                    $copy->files()->create([
                        'file_id' => $file->file_id,
                        'mime_type' => $file->mime_type,
                        'size' => $file->size,
                        'thumbnail_url' => $file->thumbnail_url,
                    ]);
                }

                $target->update(['last_message_at' => now()]);

                $created[] = [$copy, $target];
            }

            return $created;
        });

        $messages = [];
        foreach ($forwarded as [$copy, $target]) {
            event(new \App\Events\MessageCreated($copy, $target));
            PushChatMessageNotifications::dispatch($copy->id, $target->id);

            $messages[] = $copy->load(['sender:id,first_name,last_name,avatar', 'files.file', 'reactions']);
        }

        return response()->json(['data' => $messages], 201);
    }

    private function ensureUserIsMember(Chat $chat): void
    {
        if (!$chat->members()->where('user_id', Auth::id())->exists()) {
            abort(403, 'Вы не являетесь участником этого чата');
        }
    }
}
