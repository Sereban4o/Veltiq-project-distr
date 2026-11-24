<?php

namespace App\Services\Core;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for the compiled core service.
 *
 * Every call is fail-open: when the service is not configured, the breaker is
 * open, or the request fails, the method returns a "not available" value and the
 * caller keeps using the local PHP implementation. The product therefore keeps
 * working even if the core container is missing or misconfigured.
 *
 * Repeated failures open a short breaker so an unhealthy service cannot add a
 * timeout to every request, and each fallback is counted for observability
 * (`php artisan core:stats`).
 */
class CoreClient
{
    private const BREAKER_KEY = 'core:breaker:open_until';

    private const FAILURES_KEY = 'core:breaker:failures';

    /** Cache key prefix for the fallback counters. */
    public const STATS_PREFIX = 'core:fallback:';

    public function enabled(): bool
    {
        return (string) config('core.url') !== '' && (string) config('core.token') !== '';
    }

    /** True when the breaker is open (calls are skipped without a request). */
    public function breakerOpen(): bool
    {
        $until = Cache::get(self::BREAKER_KEY);

        return is_numeric($until) && (int) $until > time();
    }

    /** Seconds left until the breaker closes (0 when closed). */
    public function breakerRetryIn(): int
    {
        $until = Cache::get(self::BREAKER_KEY);

        return is_numeric($until) ? max(0, (int) $until - time()) : 0;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        $counters = Cache::get(self::STATS_PREFIX . 'all', []);

        return is_array($counters) ? array_map('intval', $counters) : [];
    }

    public function resetBreaker(): void
    {
        Cache::forget(self::BREAKER_KEY);
        Cache::forget(self::FAILURES_KEY);
    }

    /**
     * Compute free windows through the compiled service.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     * @return array<int, array{start: string, end: string}>|null null → caller falls back to PHP
     */

    /**
     * Decide which automation rules fire for an issue.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $issue
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id: int, actions: array<int, array<string, mixed>>}>|null null → caller matches locally
     */

    /**
     * Aggregate worklog entries into a per-day report.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{total_seconds:int,total_hours:float,days:array<int,array<string,mixed>>}|null null → caller aggregates in PHP
     */

    /**
     * Which CRM automation rules fire for a deal.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $deal
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id:int,action:string,action_payload:array<string,mixed>}>|null null → caller matches locally
     */

    /**
     * Permission decision (deny/allow order, scope chain, baseline, role).
     *
     * @param  array<string, mixed>  $request
     * @return array{allow:bool,reason:string}|null null → caller decides locally
     */
    public function permissionDecision(array $request): ?array
    {
        $response = $this->post('permissions', '/v1/permissions/resolve', $request);

        if ($response === null) {
            return null;
        }

        if (! is_bool($response->json('allow'))) {
            $this->fallback('permissions', 'invalid_response');

            return null;
        }

        return [
            'allow' => (bool) $response->json('allow'),
            'reason' => (string) $response->json('reason', ''),
        ];
    }

    /**
     * Id of the first mail filter rule that matches the message.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $message
     * @return int|null|false Rule id, null when nothing matched, false → caller decides locally
     */

    /**
     * Expand an RRULE into concrete occurrences.
     *
     * @param  array<int, string>  $exdates
     * @return array{items:array<int,array{start:string,end:string}>,truncated:bool}|false false → caller expands locally
     */

    /**
     * Batch snippet generation for search results.
     *
     * @param  array<string|int, string>  $texts  key => source text
     * @return array<string, string>|null null → caller builds snippets locally
     */
    public function searchSnippets(array $texts, string $query, ?int $length = null): ?array
    {
        if ($texts === []) {
            return null;
        }

        $payload = [];
        foreach ($texts as $key => $text) {
            $payload[] = ['key' => (string) $key, 'text' => (string) $text];
        }

        $response = $this->post('search_snippets', '/v1/search/snippets', [
            'query' => $query,
            'length' => $length ?? 160,
            'texts' => $payload,
            'window' => ['per_page' => 0, 'page' => 0],
        ]);

        if ($response === null) {
            return null;
        }

        $snippets = $response->json('snippets');
        if (! is_array($snippets)) {
            $this->fallback('search_snippets', 'invalid_response');

            return null;
        }

        $result = [];
        foreach ($snippets as $key => $value) {
            if (is_string($value)) {
                $result[(string) $key] = $value;
            }
        }

        return $result;
    }

    /** Candidate window size (Meilisearch ranks before the ACL filter). */
    public function searchWindow(int $perPage, int $page): ?int
    {
        $response = $this->post('search_window', '/v1/search/snippets', [
            'query' => '',
            'texts' => [],
            'window' => ['per_page' => $perPage, 'page' => $page],
        ]);

        if ($response === null || ! is_numeric($response->json('window'))) {
            return null;
        }

        return (int) $response->json('window');
    }

    /**
     * Burndown series for a sprint (ideal line plus captured remaining points).
     *
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array{total_points:float,series:array<int,array<string,mixed>>}|null null → caller builds the series in PHP
     */

    /**
     * Average completed points across finished sprints.
     *
     * @param  array<int, float|int>  $completedPoints
     * @return float|null null → caller averages in PHP
     */

    /**
     * Resolve CRM CSV column mappings (explicit indexes/names plus auto-map by
     * Russian labels).
     *
     * @param  array<int, string>  $header
     * @param  array<string, string>  $labels
     * @param  array<string, mixed>  $mapping
     * @return array<string, int>|null null → caller resolves in PHP
     */

    /**
     * Conversation key for a mail message (subject already normalised by the
     * caller, which keeps the PCRE prefix-stripping in PHP).
     *
     * @return string|null 40-char sha1 (or the given parent key), null → caller computes in PHP
     */

    /**
     * Effective page permission for a user (nearest restricted ancestor wins,
     * otherwise space-level permissions apply).
     *
     * @param  array<int, array{has_grants: bool, grant: string|null}>  $chain  page → root
     * @return 'admin'|'editor'|'viewer'|'none'|null null → caller resolves in PHP
     */
    public function pageAclEffective(bool $isSpaceAdmin, bool $spaceCanEdit, bool $spaceCanView, array $chain): ?string
    {
        $response = $this->post('page_acl', '/v1/knowledgebase/page-acl/resolve', [
            'is_space_admin' => $isSpaceAdmin,
            'space_can_edit' => $spaceCanEdit,
            'space_can_view' => $spaceCanView,
            'chain' => array_values($chain),
        ]);

        if ($response === null) {
            return null;
        }

        $permission = $response->json('permission');
        if (! in_array($permission, ['admin', 'editor', 'viewer', 'none'], true)) {
            $this->fallback('page_acl', 'invalid_response');

            return null;
        }

        return (string) $permission;
    }

    /**
     * Control-plane call (licensing and modules).
     *
     * Unlike the algorithm calls this never silently falls back: the caller must
     * surface the real state, so transport failures and rejections are returned
     * as a result array instead of being swallowed.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status: int, data: array<string, mixed>}
     */
    public function control(string $method, string $path, array $payload = [], ?string $rawBody = null): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'core_disabled']];
        }

        try {
            $options = $rawBody !== null ? ['body' => $rawBody] : ['json' => $payload];

            $response = Http::timeout(max(1, (int) config('core.timeout', 3)))
                ->acceptJson()
                ->withHeaders(['X-Core-Token' => (string) config('core.token')])
                ->send(strtoupper($method), $this->endpoint($path), $options);
        } catch (Throwable $e) {
            Log::warning('Core control-plane call failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'data' => ['error' => 'core_unreachable']];
        }

        $json = $response->json();

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => is_array($json) ? $json : [],
        ];
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function licenseStatus(): array
    {
        return $this->control('GET', '/v1/license/status');
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function licenseRequest(): array
    {
        return $this->control('POST', '/v1/license/request');
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function licenseActivate(string $licenseFile): array
    {
        return $this->control('POST', '/v1/license/activate', [], $licenseFile);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function licenseCrl(string $crlFile): array
    {
        return $this->control('POST', '/v1/license/crl', [], $crlFile);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function entitlementToken(): array
    {
        return $this->control('GET', '/v1/entitlement/token');
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function entitlementPublicKey(): array
    {
        return $this->control('GET', '/v1/entitlement/pubkey');
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function extensions(): array
    {
        return $this->control('GET', '/v1/extensions');
    }

    /**
     * @param  array<int, array<string, mixed>>  $extensions
     * @return array{ok: bool, status: int, data: array<string, mixed>}
     */
    public function syncExtensions(array $extensions): array
    {
        return $this->control('POST', '/v1/extensions/sync', ['extensions' => $extensions]);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function setExtension(string $id, bool $enabled): array
    {
        return $this->control('POST', '/v1/extensions/set', ['id' => $id, 'enabled' => $enabled]);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function audit(string $action, string $detail = '', string $actor = ''): array
    {
        return $this->control('POST', '/v1/audit', [
            'action' => $action,
            'detail' => $detail,
            'actor' => $actor,
        ]);
    }

    /** @return array{ok: bool, status: int, data: array<string, mixed>} */
    public function usage(int $activeUsers): array
    {
        return $this->control('POST', '/v1/license/usage', ['active_users' => max(0, $activeUsers)]);
    }

    /**
     * Single place for transport concerns: enabled check, breaker, timeout,
     * failure accounting and logging. Returns null when the caller must fall back.
     */
    private function post(string $context, string $path, array $payload): ?Response
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($this->breakerOpen()) {
            $this->fallback($context, 'breaker_open');

            return null;
        }

        try {
            $response = Http::timeout(max(1, (int) config('core.timeout', 3)))
                ->acceptJson()
                ->withHeaders(['X-Core-Token' => (string) config('core.token')])
                ->post($this->endpoint($path), $payload);
        } catch (Throwable $e) {
            Log::warning('Core service unavailable; using the local implementation', [
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            $this->recordFailure($context);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Core service rejected the request; using the local implementation', [
                'context' => $context,
                'status' => $response->status(),
            ]);
            $this->recordFailure($context);

            return null;
        }

        $this->recordSuccess();

        return $response;
    }

    private function recordFailure(string $context): void
    {
        $this->fallback($context, 'transport_error');

        $failures = (int) Cache::increment(self::FAILURES_KEY);
        $threshold = max(1, (int) config('core.breaker_threshold', 5));

        if ($failures >= $threshold) {
            $cooldown = max(1, (int) config('core.breaker_cooldown', 60));
            Cache::put(self::BREAKER_KEY, time() + $cooldown, $cooldown);
            Cache::forget(self::FAILURES_KEY);

            Log::warning('Core service breaker opened', ['cooldown_seconds' => $cooldown]);
        }
    }

    private function recordSuccess(): void
    {
        if ((int) Cache::get(self::FAILURES_KEY, 0) > 0 || $this->breakerOpen()) {
            Cache::forget(self::FAILURES_KEY);
            Cache::forget(self::BREAKER_KEY);
        }
    }

    /** Counts a fallback so the rate is visible (`core:stats`). */
    private function fallback(string $context, string $reason): void
    {
        $counters = Cache::get(self::STATS_PREFIX . 'all', []);
        if (! is_array($counters)) {
            $counters = [];
        }

        $counters[$context] = (int) ($counters[$context] ?? 0) + 1;
        Cache::put(self::STATS_PREFIX . 'all', $counters, now()->addDay());

        $reasons = Cache::get(self::STATS_PREFIX . 'reasons', []);
        if (! is_array($reasons)) {
            $reasons = [];
        }

        $reasons[$reason] = (int) ($reasons[$reason] ?? 0) + 1;
        Cache::put(self::STATS_PREFIX . 'reasons', $reasons, now()->addDay());
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('core.url'), '/') . $path;
    }
}
