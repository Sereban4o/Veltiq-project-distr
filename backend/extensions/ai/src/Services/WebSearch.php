<?php

namespace App\Extensions\Ai\Services;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * External web fallback through a self-hosted SearXNG instance.
 *
 * Used only when the internal retrieval found nothing, and only after an
 * administrator opted in. Only the question text leaves the infrastructure:
 * no page titles, task keys, CRM records or employee names are sent.
 */
final class WebSearch
{
    /** Keeps the GET query string within proxy limits. */
    private const MAX_QUESTION_CHARS = 500;

    private const USER_AGENT = 'Tasksbase-AI/1.0';

    public function enabled(): bool
    {
        return $this->enabledFlag() && $this->url() !== '';
    }

    /**
     * @return list<array<string, mixed>> sources in the assistant format
     */
    public function search(string $question, int $cap = 3): array
    {
        $question = trim($question);

        if ($question === '' || ! $this->enabled()) {
            return [];
        }

        $timeout = max(1, (int) $this->setting('ai_web_timeout', config('ai.web_timeout', 8)));
        $limit = max(1, min((int) $this->setting('ai_web_results', config('ai.web_results', 3)), max(1, $cap)));

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(3, $timeout))
                ->acceptJson()
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get($this->url() . '/search', [
                    'q' => mb_substr($question, 0, self::MAX_QUESTION_CHARS),
                    'format' => 'json',
                    'safesearch' => 1,
                ]);
        } catch (Throwable $e) {
            // Never log the message: Guzzle puts the full URI (question
            // included) into it.
            Log::warning('AI web search failed.', ['exception' => $e::class]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('AI web search failed.', ['status' => $response->status()]);

            return [];
        }

        $results = $response->json('results');

        return is_array($results) ? $this->normalize($results, $limit) : [];
    }

    /**
     * @param  array<int|string, mixed>  $results
     * @return list<array<string, mixed>>
     */
    private function normalize(array $results, int $limit): array
    {
        $sources = [];
        $seen = [];

        foreach ($results as $result) {
            if (count($sources) >= $limit) {
                break;
            }

            if (! is_array($result)) {
                continue;
            }

            $url = trim((string) ($result['url'] ?? ''));

            // Only http(s): a javascript:/data: URL would be an XSS vector in
            // the rendered source link.
            if (! $this->isHttpUrl($url)) {
                continue;
            }

            $key = mb_strtolower(rtrim($url, '/'));

            if (isset($seen[$key])) {
                continue;
            }

            $title = trim((string) ($result['title'] ?? ''));
            $snippet = trim((string) ($result['content'] ?? ''));

            if ($title === '' && $snippet === '') {
                continue;
            }

            $seen[$key] = true;

            $sources[] = AiSource::make(
                'web',
                count($sources) + 1,
                $title !== '' ? $title : $url,
                $url,
                $snippet,
                array_filter([
                    'engine' => is_string($result['engine'] ?? null) ? $result['engine'] : null,
                    'published' => is_string($result['publishedDate'] ?? null) ? $result['publishedDate'] : null,
                ], fn (mixed $value): bool => is_string($value) && $value !== ''),
            );
        }

        return $sources;
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function url(): string
    {
        return rtrim((string) $this->setting('ai_web_url', config('ai.web_url')), '/');
    }

    private function enabledFlag(): bool
    {
        $raw = Setting::get('ai_web_enabled');

        if ($raw === null || $raw === '') {
            return (bool) config('ai.web_enabled');
        }

        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    private function setting(string $key, mixed $default): mixed
    {
        $value = Setting::get($key);

        return ($value === null || $value === '') ? $default : $value;
    }
}
