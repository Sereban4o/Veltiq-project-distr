<?php

namespace App\Support;

use App\Models\Message;
use App\Models\MessageSearchTerm;

/**
 * Keeps `message_search_terms` in sync with message bodies.
 *
 * Message bodies are encrypted with a random IV (`EncryptedOrPlain` cast), so
 * they cannot be searched with SQL. Instead every normalised word is stored as
 * an HMAC-SHA256 hash; a search matches whole words only (AND by word).
 */
class ChatSearchIndexer
{
    /** Maximum number of words taken from a search query. */
    public const MAX_TERMS = 10;

    /** Words shorter than this are not indexed/searched. */
    private const MIN_LENGTH = 2;

    /**
     * Hashes for every word of the given text (unique, order preserved).
     *
     * @return list<string>
     */
    public static function terms(string $text, int $max = self::MAX_TERMS): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < self::MIN_LENGTH) {
                continue;
            }

            $term = self::hash($word);
            if (!in_array($term, $terms, true)) {
                $terms[] = $term;
            }

            if (count($terms) >= $max) {
                break;
            }
        }

        return $terms;
    }

    public static function hash(string $word): string
    {
        return hash_hmac('sha256', $word, self::key());
    }

    /** Replaces the index rows of a single message. */
    public static function reindex(Message $message): void
    {
        self::forget((int) $message->id);

        $body = (string) ($message->body ?? '');
        if ($message->deleted_at !== null || trim($body) === '') {
            return;
        }

        $terms = self::terms($body, PHP_INT_MAX);
        if ($terms === []) {
            return;
        }

        $rows = array_map(static fn (string $term) => [
            'message_id' => (int) $message->id,
            'chat_id' => (int) $message->chat_id,
            'term' => $term,
        ], $terms);

        MessageSearchTerm::insert($rows);
    }

    public static function forget(int $messageId): void
    {
        MessageSearchTerm::where('message_id', $messageId)->delete();
    }

    private static function key(): string
    {
        return (string) config('services.chat_search.key', config('app.key'));
    }
}
