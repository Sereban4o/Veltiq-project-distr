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
    public function freeSlots(
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        array $busy,
        int $durationMinutes,
    ): ?array {
        $response = $this->post('free_slots', '/v1/calendar/free-slots', [
            'window_start' => $windowStart->toIso8601String(),
            'window_end' => $windowEnd->toIso8601String(),
            'duration' => $durationMinutes,
            'meetings' => array_map(fn (array $interval) => [
                'start' => $interval[0]->toIso8601String(),
                'end' => $interval[1]->toIso8601String(),
            ], $busy),
        ]);

        if ($response === null) {
            return null;
        }

        $slots = $response->json('slots');
        if (! is_array($slots)) {
            $this->fallback('free_slots', 'invalid_response');

            return null;
        }

        $result = [];
        foreach ($slots as $slot) {
            $start = is_array($slot) ? ($slot['start'] ?? null) : null;
            $end = is_array($slot) ? ($slot['end'] ?? null) : null;

            if (! is_string($start) || ! is_string($end)) {
                continue;
            }

            try {
                // Normalise to the same ISO-8601 shape the PHP path returns, so
                // the API contract does not depend on the implementation.
                $result[] = [
                    'start' => CarbonImmutable::parse($start)->toIso8601String(),
                    'end' => CarbonImmutable::parse($end)->toIso8601String(),
                ];
            } catch (Throwable) {
                continue;
            }
        }

        return $result;
    }

    /**
     * Decide which automation rules fire for an issue.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $issue
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id: int, actions: array<int, array<string, mixed>>}>|null null → caller matches locally
     */
    public function automationMatches(array $rules, array $issue, array $context): ?array
    {
        $response = $this->post('automation', '/v1/tasksbase/automation/match', [
            'rules' => array_values($rules),
            'issue' => $issue,
            'context' => $context,
        ]);

        if ($response === null) {
            return null;
        }

        $matches = $response->json('matches');
        if (! is_array($matches)) {
            $this->fallback('automation', 'invalid_response');

            return null;
        }

        $result = [];
        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['rule_id'])) {
                continue;
            }

            $actions = is_array($match['actions'] ?? null) ? $match['actions'] : [];

            $result[] = [
                'rule_id' => (int) $match['rule_id'],
                'actions' => array_values(array_filter($actions, 'is_array')),
            ];
        }

        return $result;
    }

    /**
     * Aggregate worklog entries into a per-day report.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array{total_seconds:int,total_hours:float,days:array<int,array<string,mixed>>}|null null → caller aggregates in PHP
     */
    public function timesheet(array $entries): ?array
    {
        $response = $this->post('timesheet', '/v1/tasksbase/timesheet', ['entries' => array_values($entries)]);

        if ($response === null) {
            return null;
        }

        $days = $response->json('days');
        if (! is_array($days)) {
            $this->fallback('timesheet', 'invalid_response');

            return null;
        }

        return [
            'total_seconds' => (int) $response->json('total_seconds'),
            'total_hours' => (float) $response->json('total_hours'),
            'days' => array_values(array_filter($days, 'is_array')),
        ];
    }

    /**
     * Which CRM automation rules fire for a deal.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<string, mixed>  $deal
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id:int,action:string,action_payload:array<string,mixed>}>|null null → caller matches locally
     */
    public function crmAutomationMatches(array $rules, array $deal, array $context): ?array
    {
        $response = $this->post('crm_automation', '/v1/crm/automation/match', [
            'rules' => array_values($rules),
            'deal' => $deal,
            'context' => $context,
        ]);

        if ($response === null) {
            return null;
        }

        $matches = $response->json('matches');
        if (! is_array($matches)) {
            $this->fallback('crm_automation', 'invalid_response');

            return null;
        }

        $result = [];
        foreach ($matches as $match) {
            if (! is_array($match) || ! isset($match['rule_id'])) {
                continue;
            }

            $result[] = [
                'rule_id' => (int) $match['rule_id'],
                'action' => (string) ($match['action'] ?? ''),
                'action_payload' => is_array($match['action_payload'] ?? null) ? $match['action_payload'] : [],
            ];
        }

        return $result;
    }

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
    public function mailRuleResolve(array $rules, array $message): int|null|false
    {
        $response = $this->post('mail_rules', '/v1/mail/rules/resolve', [
            'rules' => array_values($rules),
            'message' => $message,
        ]);

        if ($response === null) {
            return false;
        }

        $ruleId = $response->json('rule_id');

        return is_int($ruleId) || is_numeric($ruleId) ? (int) $ruleId : null;
    }

    /**
     * Expand an RRULE into concrete occurrences.
     *
     * @param  array<int, string>  $exdates
     * @return array{items:array<int,array{start:string,end:string}>,truncated:bool}|false false → caller expands locally
     */
    public function expandRecurrence(
        string $start,
        int $durationSeconds,
        string $rrule,
        array $exdates,
        string $from,
        string $to,
        int $limit,
        string $timezone,
    ): array|false {
        $response = $this->post('recurrence', '/v1/calendar/expand-recurrence', [
            'start' => $start,
            'duration_seconds' => $durationSeconds,
            'rrule' => $rrule,
            'exdates' => array_values($exdates),
            'from' => $from,
            'to' => $to,
            'limit' => $limit,
            'timezone' => $timezone,
        ]);

        // 501 marks an RRULE the compiled engine does not implement; open-breaker
        // and transport failures also end up here and keep the local engine.
        if ($response === null || $response->status() === 501) {
            return false;
        }

        $items = $response->json('items');
        if (! is_array($items)) {
            $this->fallback('recurrence', 'invalid_response');

            return false;
        }

        $result = [];
        foreach ($items as $item) {
            $itemStart = is_array($item) ? ($item['start'] ?? null) : null;
            $itemEnd = is_array($item) ? ($item['end'] ?? null) : null;

            if (is_string($itemStart) && is_string($itemEnd)) {
                $result[] = ['start' => $itemStart, 'end' => $itemEnd];
            }
        }

        return ['items' => $result, 'truncated' => (bool) $response->json('truncated')];
    }

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
