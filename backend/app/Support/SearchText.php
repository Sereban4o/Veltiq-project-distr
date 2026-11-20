<?php

namespace App\Support;

/**
 * Reduces rich-text HTML to plain text for indexing and previews.
 *
 * This is intentionally search-oriented (not sanitisation): it does not escape
 * anything, it only strips markup and normalises whitespace so that Meilisearch
 * stores readable text and hits can be highlighted.
 */
class SearchText
{
    public static function plain(?string $html): string
    {
        if (! $html) {
            return '';
        }

        // Block-level closers become a space so words are not glued together.
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|blockquote|pre)>/i', ' ', $html);
        $text = preg_replace('/<br\s*\/?>/i', ' ', (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
