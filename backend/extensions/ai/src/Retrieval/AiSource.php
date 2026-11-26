<?php

namespace App\Extensions\Ai\Retrieval;

/**
 * Normalises one retrieved hit into the shape the assistant API returns and
 * the frontend renders. Title and snippet are clipped so a verbose record
 * (a long event description, a mail body) cannot eat the whole prompt budget.
 */
final class AiSource
{
    private const TITLE_LIMIT = 200;

    private const SNIPPET_LIMIT = 240;

    /**
     * @param  array<string, mixed>  $meta
     * @return array{type:string, id:int, title:string, url:string, snippet:string, meta:array<string, mixed>}
     */
    public static function make(
        string $type,
        int|string $id,
        ?string $title,
        ?string $url,
        ?string $snippet,
        array $meta = [],
    ): array {
        return [
            'type' => $type,
            'id' => (int) $id,
            'title' => self::clip(trim((string) $title), self::TITLE_LIMIT),
            'url' => trim((string) $url),
            'snippet' => self::clip(trim((string) $snippet), self::SNIPPET_LIMIT),
            'meta' => $meta,
        ];
    }

    private static function clip(string $value, int $limit): string
    {
        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
    }
}
