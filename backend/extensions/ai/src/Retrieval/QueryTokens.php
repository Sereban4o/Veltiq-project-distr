<?php

namespace App\Extensions\Ai\Retrieval;

/**
 * Splits a natural-language question into the words worth searching for.
 *
 * Database lookups cannot rank a full question the way Meilisearch does, so
 * providers match any significant token instead. Short words ("что", "как",
 * "на") are dropped — they match almost everything.
 */
final class QueryTokens
{
    private const MIN_LENGTH = 3;

    private const MAX_TOKENS = 8;

    /** @return list<string> lowercase tokens, longest first */
    public static function of(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];

        $words = array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= self::MIN_LENGTH,
        )));

        usort($words, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return array_slice($words, 0, self::MAX_TOKENS);
    }
}
