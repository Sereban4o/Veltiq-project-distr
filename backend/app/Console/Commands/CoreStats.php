<?php

namespace App\Console\Commands;

use App\Services\Core\CoreClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Shows how often the app had to fall back to the local implementation because
 * the compiled core service was unavailable, plus the breaker state.
 *
 * Usage: `php artisan core:stats`, `php artisan core:stats --reset`.
 */
class CoreStats extends Command
{
    protected $signature = 'core:stats {--reset : Clear the counters and close the breaker}';

    protected $description = 'Show core-service fallback counters and breaker state';

    public function handle(CoreClient $core): int
    {
        if ($this->option('reset')) {
            Cache::forget(CoreClient::STATS_PREFIX . 'all');
            Cache::forget(CoreClient::STATS_PREFIX . 'reasons');
            $core->resetBreaker();
            $this->info('Счётчики сброшены, breaker закрыт');

            return self::SUCCESS;
        }

        $this->info('Core service: ' . ($core->enabled() ? 'включён' : 'выключен (работает PHP)'));
        $this->info('Breaker: ' . ($core->breakerOpen()
            ? 'открыт, ещё ' . $core->breakerRetryIn() . ' с'
            : 'закрыт'));

        $counters = $core->stats();
        if ($counters === []) {
            $this->info('Fallback: не зафиксирован');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($counters as $context => $count) {
            $rows[] = [$context, $count];
        }

        $this->table(['контекст', 'переходов на PHP'], $rows);

        $reasons = Cache::get(CoreClient::STATS_PREFIX . 'reasons', []);
        if (is_array($reasons) && $reasons !== []) {
            $reasonRows = [];
            foreach ($reasons as $reason => $count) {
                $reasonRows[] = [$reason, $count];
            }
            $this->table(['причина', 'раз'], $reasonRows);
        }

        return self::SUCCESS;
    }
}
