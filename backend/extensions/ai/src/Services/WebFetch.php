<?php

namespace App\Extensions\Ai\Services;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Direct page reading for the assistant's external fallback.
 *
 * No search engine is involved: the assistant downloads http(s) links that the
 * user typed into the question, and only from hosts an administrator
 * allowlisted. Only the download request leaves the infrastructure — the
 * question text, retrieved snippets, task keys and employee names never do.
 */
final class WebFetch
{
    private const USER_AGENT = 'Tasksbase-AI/1.0';

    /** A response above this size is almost never an article; skip it. */
    private const MAX_BYTES = 2_000_000;

    private const MAX_REDIRECTS = 2;

    /**
     * @return list<array<string, mixed>> sources in the assistant format
     */
    public function read(string $question, int $cap = 3): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $limit = min(
            max(1, (int) $this->setting('ai_web_results', config('ai.web_results', 3))),
            max(1, $cap),
        );

        $sources = [];
        $seen = [];

        foreach ($this->extractUrls($question) as $url) {
            if (count($sources) >= $limit) {
                break;
            }

            $key = mb_strtolower(rtrim($url, '/'));

            if (isset($seen[$key]) || ! $this->allowed($url)) {
                continue;
            }

            $seen[$key] = true;

            $source = $this->fetch($url, count($sources) + 1);

            if ($source !== null) {
                $sources[] = $source;
            }
        }

        return $sources;
    }

    public function enabled(): bool
    {
        return $this->enabledFlag() && $this->domains() !== [];
    }

    /** A page the admin form can probe: the first allowlisted host. */
    public function probeUrl(): ?string
    {
        $domains = $this->domains();

        return $domains === [] ? null : 'https://' . $domains[0] . '/';
    }

    /**
     * Parses the admin textarea/field (one host per line or comma separated).
     *
     * @return list<string>|null null when at least one entry is not a valid host
     */
    public function parseDomains(string $raw): ?array
    {
        $domains = [];

        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $part = trim((string) $part);

            if ($part === '') {
                continue;
            }

            $domain = $this->normalizeDomain($part);

            if (! $this->isValidHost($domain)) {
                return null;
            }

            if (! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /** Whether a URL points at an allowlisted public host. */
    public function allowed(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        if (! in_array(mb_strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        $host = mb_strtolower(rtrim((string) $parts['host'], '.'));

        if ($host === '' || $this->isPrivateHost($host)) {
            return false;
        }

        foreach ($this->domains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractUrls(string $question): array
    {
        preg_match_all('#https?://[^\s<>"\'()]+#iu', $question, $matches);

        $urls = [];

        foreach ($matches[0] ?? [] as $raw) {
            // Trailing punctuation belongs to the sentence, not to the link.
            $url = rtrim(trim((string) $raw), '.,;:!?»');

            if ($url !== '' && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Loopback, RFC1918, link-local and other reserved literals stay refused
     * even when allowlisted: the assistant must not become a probe for the
     * internal network.
     */
    private function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** A public IP literal or a dot-separated hostname (IDN converted when possible). */
    private function isValidHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! $this->isPrivateHost($host);
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (is_string($ascii) && $ascii !== '') {
                $host = mb_strtolower($ascii);
            }
        }

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch(string $url, int $position): ?array
    {
        $timeout = max(1, (int) $this->setting('ai_web_timeout', config('ai.web_timeout', 8)));
        $maxChars = max(500, (int) $this->setting('ai_web_max_chars', config('ai.web_max_chars', 4000)));

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(3, $timeout))
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'text/html,application/xhtml+xml,text/plain',
                ])
                ->withOptions(['allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    // A redirect must not smuggle the request to another host.
                    'on_redirect' => function (mixed $request, mixed $response, mixed $uri): void {
                        if (! $this->allowed((string) $uri)) {
                            throw new RuntimeException('redirect outside the allowlist');
                        }
                    },
                ]])
                ->get($url);
        } catch (Throwable $e) {
            // Never log the URL or the message: both may carry the question.
            Log::warning('AI page fetch failed.', ['exception' => $e::class]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('AI page fetch failed.', ['status' => $response->status()]);

            return null;
        }

        $contentType = mb_strtolower((string) $response->header('Content-Type'));

        if ($contentType !== '' && ! str_starts_with($contentType, 'text/') && ! str_contains($contentType, 'xhtml')) {
            return null;
        }

        $body = (string) $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        [$title, $text] = $this->toText($body, $contentType);

        if ($text === '') {
            return null;
        }

        return AiSource::make(
            'web',
            $position,
            $title !== '' ? $title : $url,
            $url,
            mb_substr($text, 0, $maxChars),
            ['source' => 'fetch'],
            $maxChars,
        );
    }

    /**
     * @return array{0:string, 1:string} title and plain text
     */
    private function toText(string $body, string $contentType): array
    {
        if (str_contains($contentType, 'text/plain')) {
            return ['', $this->collapse($body)];
        }

        $title = '';

        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $match) === 1) {
            $title = $this->collapse(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Scripts, styles and the head carry no readable text; dropping them
        // also keeps stray braces out of the prompt.
        $html = preg_replace('#<(script|style|noscript|svg|template|head)[^>]*>.*?</\1>#is', ' ', $body) ?? $body;
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [$title, $this->collapse($text)];
    }

    private function collapse(string $text): string
    {
        $text = preg_replace('/[ \t\r\f\v]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Allowlisted hosts; an empty list disables the fallback entirely.
     *
     * @return list<string>
     */
    private function domains(): array
    {
        $raw = Setting::get('ai_web_domains');

        if ($raw !== null && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded)
                ? implode(' ', array_map(static fn (mixed $value): string => (string) $value, $decoded))
                : $raw;

            return $this->parseDomains($raw) ?? [];
        }

        $domains = [];

        foreach ((array) config('ai.web_domains', []) as $entry) {
            $domain = $this->normalizeDomain((string) $entry);

            if ($this->isValidHost($domain) && ! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /** Tolerates "https://docs.example.com/path", "*.example.com" and "example.com". */
    private function normalizeDomain(string $entry): string
    {
        $value = mb_strtolower(trim($entry));
        $value = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value) ?? $value;
        $value = explode('/', $value)[0];
        $value = explode('?', $value)[0];
        $value = ltrim($value, '*.');

        if (! str_starts_with($value, '[')) {
            $value = explode(':', $value)[0];
        }

        return rtrim($value, '.');
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
