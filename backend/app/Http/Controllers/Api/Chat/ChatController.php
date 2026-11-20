<?php

namespace App\Http\Controllers\Api\Chat;

use App\Events\ChatRead;
use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        $chats = $this->chatsForUser($user)
            ->paginate(20);

        return response()->json($chats);
    }

    public function myChats(Request $request)
    {
        $user = Auth::user();
        $perPage = min((int) $request->input('per_page', 50), 100);

        $chats = $this->chatsForUser($user)
            ->paginate($perPage);

        return response()->json($chats);
    }

    /**
     * Base query for the current user's chats, including an `unread_count`
     * computed from the member's `last_read_at` pivot value.
     */
    private function chatsForUser(User $user)
    {
        $unreadSql = '(select count(*) from messages m'
            . ' where m.chat_id = chats.id and m.deleted_at is null'
            . ' and m.created_at > coalesce((select cm.last_read_at from chat_members cm'
            . ' where cm.chat_id = chats.id and cm.user_id = ' . (int) $user->id . '), \'1970-01-01 00:00:00\'))';

        return Chat::whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->with(['lastMessage.sender:id,first_name,last_name,avatar', 'creator:id,first_name,last_name,avatar', 'members:id,first_name,last_name,avatar,email,status'])
        ->withCount(['messages as messages_count' => function ($q) {
            $q->whereNull('deleted_at');
        }])
        ->select('chats.*')
        ->selectRaw($unreadSql . ' as unread_count')
        ->orderByDesc('last_message_at');
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => 'required_if:type,group,channel|string|max:255',
            'type' => 'required|in:dm,group,channel',
            'description' => 'nullable|string|max:5000',
            'user_ids' => 'sometimes|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        if ($validated['type'] === 'dm') {
            $validated['name'] = null;
            $userIds = $validated['user_ids'] ?? [];

            if (count($userIds) !== 1) {
                return response()->json(['message' => 'Необходимо указать ровно одного пользователя'], 422);
            }

            $otherUserId = (int) $userIds[0];

            if ($otherUserId === (int) $user->id) {
                return response()->json(['message' => 'Нельзя создать чат с самим собой'], 422);
            }

            // Reuse only a DM that has exactly two members (guards against historical broken DMs).
            $existingChat = Chat::where('type', 'dm')
                ->has('members', '=', 2)
                ->whereHas('members', function ($q) use ($otherUserId) {
                    $q->where('user_id', $otherUserId);
                })
                ->whereHas('members', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })
                ->orderBy('id')
                ->first();

            if ($existingChat) {
                return response()->json($existingChat->load(['lastMessage.sender', 'members']), 200);
            }
        }

        $chat = DB::transaction(function () use ($validated) {
            $chat = Chat::create([
                'created_by' => Auth::id(),
                'name' => $validated['name'],
                'type' => $validated['type'],
                'description' => $validated['description'] ?? null,
            ]);

            // Add creator as admin
            ChatMember::create([
                'chat_id' => $chat->id,
                'user_id' => Auth::id(),
                'role' => 'admin',
            ]);

            // Add other users
            if (!empty($validated['user_ids'])) {
                foreach ($validated['user_ids'] as $userId) {
                    // Skip if already a member (creator is already added)
                    if ($userId === Auth::id()) {
                        continue;
                    }

                    ChatMember::firstOrCreate(
                        ['chat_id' => $chat->id, 'user_id' => $userId],
                        ['role' => 'member'],
                    );
                }
            }

            return $chat;
        });

        return response()->json($chat->load(['lastMessage.sender:id,first_name,last_name,avatar', 'members:id,first_name,last_name,avatar,email,status']), 201);
    }

    public function show(Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        return response()->json($chat->load([
            'members:id,first_name,last_name,avatar,email,status',
            'lastMessage.sender:id,first_name,last_name,avatar',
            'creator:id,first_name,last_name,avatar',
        ]));
    }

    public function update(Request $request, Chat $chat)
    {
        $this->ensureUserIsAdmin($chat);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'avatar' => 'nullable|string',
        ]);

        $chat->update($validated);

        return response()->json($chat->load(['members']));
    }

    public function destroy(Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        $memberIds = $chat->members()->pluck('users.id')->all();
        $type = $chat->type;
        $name = $chat->name;

        DB::transaction(function () use ($chat) {
            $chat->members()->detach();
            $chat->delete();
        });

        event(new \App\Events\ChatDeleted((int) $chat->id, $type, $name, $memberIds));

        return response()->json(['message' => 'Чат удалён']);
    }

    public function addMembers(Request $request, Chat $chat)
    {
        $this->ensureUserIsAdmin($chat);

        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        DB::transaction(function () use ($chat, $validated) {
            foreach ($validated['user_ids'] as $userId) {
                ChatMember::firstOrCreate(
                    ['chat_id' => $chat->id, 'user_id' => $userId],
                    ['role' => 'member'],
                );
            }
        });

        return response()->json($chat->load('members:id,first_name,last_name,avatar,email,status'));
    }

    public function removeMember(Request $request, Chat $chat)
    {
        $this->ensureUserIsAdmin($chat);

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        ChatMember::where('chat_id', $chat->id)
            ->where('user_id', $validated['user_id'])
            ->delete();

        return response()->json(['message' => 'Участник удалён']);
    }

    public function leaveChat(Chat $chat)
    {
        $userId = Auth::id();
        $member = $chat->members()->where('user_id', $userId)->first();

        if (!$member) {
            abort(403, 'Вы не являетесь участником этого чата');
        }

        $wasAdmin = $member->pivot->role === 'admin';
        $chat->members()->detach($userId);

        // Передать админство, чтобы группа не осталась без администратора.
        if ($wasAdmin && $chat->type !== 'dm') {
            $next = $chat->members()->oldest('chat_members.created_at')->first();
            if ($next) {
                $chat->members()->updateExistingPivot($next->id, ['role' => 'admin']);
            }
        }

        return response()->json(['message' => 'Вы покинули чат']);
    }

    public function markAsRead(Request $request, Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        $user = Auth::user();
        $requested = $request->validate(['message_id' => 'sometimes|integer'])['message_id'] ?? null;

        if ($requested !== null) {
            if (! $chat->messages()->whereKey((int) $requested)->exists()) {
                abort(422, 'Сообщение не найдено в этом чате');
            }
            $targetId = (int) $requested;
        } else {
            $targetId = (int) $chat->messages()->max('id');
        }

        $member = ChatMember::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $user->id],
            ['last_read_at' => now()],
        );

        // Read position never moves backwards.
        $lastReadId = max((int) $member->last_read_message_id, $targetId);
        $member->forceFill([
            'last_read_at' => now(),
            'last_read_message_id' => $lastReadId,
        ])->save();

        try {
            broadcast(new ChatRead($chat, $user, $lastReadId))->toOthers();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('ChatRead broadcast failed', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Прочитано',
            'last_read_message_id' => $lastReadId,
        ]);
    }

    /**
     * Current read position of every chat member (for read receipts).
     */
    public function reads(Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        $rows = ChatMember::where('chat_id', $chat->id)
            ->get(['user_id', 'last_read_message_id', 'last_read_at'])
            ->map(fn (ChatMember $member) => [
                'user_id' => (int) $member->user_id,
                'last_read_message_id' => (int) ($member->last_read_message_id ?? 0),
                'last_read_at' => optional($member->last_read_at)->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * Broadcast a transient "typing" signal for the open chat.
     */
    public function typing(Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        try {
            event(new \App\Events\ChatUserTyping($chat, Auth::user()));
        } catch (\Throwable $e) {
            // The indicator is best-effort: never fail the request when the
            // broadcast transport is unavailable.
            \Illuminate\Support\Facades\Log::debug('Typing broadcast failed', ['error' => $e->getMessage()]);
        }

        return response()->noContent();
    }

    /**
     * Relay a WebRTC signaling message for a chat call to the chat channel.
     */
    public function callSignal(Request $request, Chat $chat)
    {
        $this->ensureUserIsMember($chat);

        $validated = $request->validate([
            'type' => 'required|in:invite,accept,decline,offer,answer,ice,hangup',
            'call_id' => 'required|string|max:64',
            'to' => 'sometimes|nullable|integer',
            'payload' => 'sometimes|array',
        ]);

        $to = isset($validated['to']) ? (int) $validated['to'] : null;
        if ($to !== null && ! $chat->members()->where('user_id', $to)->exists()) {
            abort(422, 'Получатель не является участником чата');
        }

        try {
            event(new \App\Events\CallSignal(
                $chat,
                Auth::user(),
                $validated['type'],
                $to,
                $validated['call_id'],
                $validated['payload'] ?? [],
            ));
        } catch (\Throwable $e) {
            // Calls are best-effort: never fail the request when the broadcast
            // transport is unavailable.
            \Illuminate\Support\Facades\Log::debug('Call signal broadcast failed', ['error' => $e->getMessage()]);
        }

        return response()->noContent();
    }

    public function searchUsers(Request $request)
    {
        $query = $request->input('q');

        $users = User::where('id', '!=', Auth::id())
            ->where(function ($q) use ($query) {
                $q->where('first_name', 'ilike', "%{$query}%")
                  ->orWhere('last_name', 'ilike', "%{$query}%")
                  ->orWhere('email', 'ilike', "%{$query}%");
            })
            ->limit(10)
            ->get();

        return response()->json($users);
    }

    private function ensureUserIsMember(Chat $chat): void
    {
        if (!$chat->members()->where('user_id', Auth::id())->exists()) {
            abort(403, 'Вы не являетесь участником этого чата');
        }
    }

    private function ensureUserIsAdmin(Chat $chat): void
    {
        $member = $chat->members()->where('user_id', Auth::id())->first();
        if (!$member || $member->pivot->role !== 'admin') {
            abort(403, 'Только администратор может выполнять это действие');
        }
    }
}
