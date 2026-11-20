<?php

namespace App\Console\Commands;

use App\Services\Assets\BundleIntegrity;
use Illuminate\Console\Command;

/**
 * Pins (or shows) the expected frontend bundle id used by the integrity check.
 *
 * Usage: `php artisan assets:pin 3f9c…` after a release, `assets:pin none` to
 * disable the check, `assets:pin` to print the current value.
 */
class PinAssetsBundle extends Command
{
    protected $signature = 'assets:pin {bundle? : Expected bundle id, or "none" to disable}';

    protected $description = 'Show or set the expected frontend bundle id (integrity check)';

    public function handle(BundleIntegrity $integrity): int
    {
        $bundle = $this->argument('bundle');

        if ($bundle === null) {
            $this->info('Expected bundle: ' . ($integrity->expected() ?? '— (не задан, проверка выключена)'));

            return self::SUCCESS;
        }

        if ($bundle === 'none') {
            $integrity->pin(null);
            $this->info('Проверка целостности бандла выключена');

            return self::SUCCESS;
        }

        $integrity->pin((string) $bundle);
        $this->info('Ожидаемый bundle id: ' . $bundle);

        return self::SUCCESS;
    }
}
