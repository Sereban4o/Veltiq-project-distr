<?php

namespace App\Services\Search;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Support\ChatSearchIndexer;
use Illuminate\Support\Facades\DB;

/**
 * Word search over the chat blind index (`message_search_terms`).
 *
 * Message bodies are encrypted at rest, so they can neither be pushed to
 * Meilisearch nor matched with SQL; only whole-word HMAC hashes are stored.
 * Shared by the chat search endpoint and the global search endpoint.
 */
class ChatMessageSearch
{
    /**
     * Ids of messages in the user's chats that contain all query terms.
     *
     * @return list<int>
     */
    public function messageIds(User $user, string $query, ?int $chatId = null): array
    {
        $terms = ChatSearchIndexer::terms($query);

        if ($terms === []) {
            return [];
        }

        if ($chatId !== null) {
            $chatIds = Chat::whereKey($chatId)
                ->whereHas('members', fn ($q) => $q->where('users.id', $user->id))
                ->pluck('id')
                ->all();
        } else {
            $chatIds = Chat::whereHas('members', fn ($q) => $q->where('users.id', $user->id))
                ->pluck('id')
                ->all();
        }

        if ($chatIds === []) {
            return [];
        }

        return DB::table('message_search_terms')
            ->select('message_id')
            ->whereIn('chat_id', $chatIds)
            ->whereIn('term', $terms)
            ->groupBy('message_id')
            ->havingRaw('count(distinct term) = ?', [count($terms)])
            ->pluck('message_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Global-search hits (ordered newest first) with a PHP-built snippet.
     *
     * @return list<array{id:int,title:string,snippet:string,url:string,meta:array}>
     */
    public function hits(User $user, string $query, int $limit): array
    {
        $ids = $this->messageIds($user, $query);

        if ($ids === []) {
            return [];
        }

        $messages = Message::whereIn('id', $ids)
            ->with(['sender:id,first_name,last_name', 'chat:id,name,type'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $messages->map(function (Message $message) use ($query) {
            $body = (string) $message->body;
            $sender = trim(($message->sender?->first_name ?? '') . ' ' . ($message->sender?->last_name ?? ''));
            $title = $message->chat?->name ?: ($sender !== '' ? $sender : 'Сообщение');

            return [
                'id' => (int) $message->id,
                'title' => $title,
                'snippet' => $this->snippet($body, $query),
                'url' => '/chat?chat=' . (int) $message->chat_id . '&message=' . (int) $message->id,
                'meta' => [
                    'chat_id' => (int) $message->chat_id,
                    'chat_type' => $message->chat?->type,
                    'sender_id' => $message->sender_id ? (int) $message->sender_id : null,
                    'created_at' => optional($message->created_at)->toIso8601String(),
                ],
            ];
        })->all();
    }

    private function snippet(string $text, string $query, int $length = 160): string
    {
        if ($text === '') {
            return '';
        }

        $pos = mb_stripos($text, $query);

        if ($pos === false) {
            return mb_strimwidth($text, 0, $length, '…');
        }

        $start = max(0, $pos - (int) ($length / 3));
        $snippet = mb_substr($text, $start, $length);

        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + $length ? '…' : '');
    }
}
