<?php

namespace App\Services\Knowledgebase;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Produces a human-readable diff between two HTML page snapshots.
 *
 * HTML is reduced to plain-text lines (block elements become line breaks) so
 * the diff reflects content changes rather than markup noise.
 */
class HtmlDiff
{
    public static function compute(?string $old, ?string $new): array
    {
        $oldLines = self::toLines((string) $old);
        $newLines = self::toLines((string) $new);

        $differ = new Differ(new UnifiedDiffOutputBuilder("--- Old\n+++ New\n"));

        $raw = $differ->diffToArray($oldLines, $newLines);

        $lines = [];
        $added = 0;
        $removed = 0;

        foreach ($raw as [$token, $type]) {
            switch ($type) {
                case Differ::ADDED:
                    $lines[] = ['type' => 'added', 'text' => rtrim((string) $token, "\r\n")];
                    $added++;
                    break;
                case Differ::REMOVED:
                    $lines[] = ['type' => 'removed', 'text' => rtrim((string) $token, "\r\n")];
                    $removed++;
                    break;
                default:
                    $lines[] = ['type' => 'context', 'text' => rtrim((string) $token, "\r\n")];
            }
        }

        return [
            'lines' => $lines,
            'stats' => [
                'added' => $added,
                'removed' => $removed,
            ],
            'unified' => $differ->diff($oldLines, $newLines),
        ];
    }

    /**
     * Turn HTML into an array of plain-text lines.
     *
     * @return list<string>
     */
    private static function toLines(string $html): array
    {
        if ($html === '') {
            return [];
        }

        // Block-level elements and <br> become line breaks.
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr|ul|ol|blockquote|pre|section|article|table)>/i', "\n", $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", (string) $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = preg_split('/\R/u', $text);
        $lines = array_map(fn ($line) => trim((string) $line), $lines ?: []);

        // Drop empty lines that are only markup artifacts.
        return array_values(array_filter($lines, fn ($line) => $line !== ''));
    }
}
