<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Core\CoreClient;
use Illuminate\Console\Command;

/**
 * Reports the active-user count to the compiled core, which includes it in the
 * license heartbeat to the vendor server (docs/12-licensing.md).
 *
 * Usage: `php artisan license:usage [--days=7]`.
 */
class LicenseUsage extends Command
{
    protected $signature = 'license:usage {--days=7 : Activity window in days}';

    protected $description = 'Report active users to the core for the license heartbeat';

    public function handle(CoreClient $core): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days)->timestamp;

        $active = User::query()->where('last_activity', '>=', $threshold)->count();

        if (! $core->enabled()) {
            $this->warn("Ядро недоступно; активных пользователей за {$days} дн.: {$active}");

            return self::SUCCESS;
        }

        $response = $core->usage($active);
        if (! $response['ok']) {
            $this->error('Не удалось передать счётчик в ядро');

            return self::FAILURE;
        }

        $this->info("Активных пользователей за {$days} дн.: {$active} (передано в ядро)");

        return self::SUCCESS;
    }
}
