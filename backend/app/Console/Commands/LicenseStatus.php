<?php

namespace App\Console\Commands;

use App\Services\Core\CoreClient;
use App\Services\Core\EntitlementClient;
use Illuminate\Console\Command;

/**
 * Shows the licensing state as the application sees it, plus what the core
 * reports (fingerprint, request code for offline activation).
 *
 * Usage: `php artisan license:status`.
 */
class LicenseStatus extends Command
{
    protected $signature = 'license:status';

    protected $description = 'Show license state, fingerprint and offline request code';

    public function handle(EntitlementClient $entitlement, CoreClient $core): int
    {
        $state = $entitlement->state();

        $this->table(['поле', 'значение'], [
            ['состояние', $state['state']],
            ['доступ', $state['access']],
            ['источник', $state['source']],
            ['истекает (лицензия)', $state['expires_at'] ?? '—'],
            ['фичи', $state['features'] === [] ? '—' : implode(', ', $state['features'])],
            ['расширения', $state['modules'] === [] ? '—' : implode(', ', $state['modules'])],
            ['enforcement', config('license.enforce') ? 'включён' : 'выключен'],
        ]);

        if (! $core->enabled()) {
            $this->warn('Ядро недоступно (CORE_URL/CORE_TOKEN не заданы)');

            return self::SUCCESS;
        }

        $status = $core->licenseStatus();
        if (! $status['ok']) {
            $this->error('Ядро не ответило');

            return self::FAILURE;
        }

        $this->info('Отпечаток: ' . ($status['data']['fingerprint'] ?? '—'));

        if (($status['data']['clock_tamper'] ?? false) === true) {
            $this->warn('Обнаружен откат часов');
        }

        if (($status['data']['status']['state'] ?? '') === 'invalid') {
            $code = $core->licenseRequest();
            if ($code['ok']) {
                $this->line('Код активации (offline): ' . json_encode($code['data'], JSON_UNESCAPED_UNICODE));
            }
        }

        return self::SUCCESS;
    }
}
