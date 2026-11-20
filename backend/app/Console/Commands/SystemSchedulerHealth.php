<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fails when the scheduler has not ticked recently.
 *
 * `routes/console.php` writes a `scheduler:heartbeat` timestamp every minute.
 * If `schedule:run`/`schedule:work` silently stops, the value goes stale and
 * this command (used by the `scheduler` container healthcheck and by external
 * monitoring) returns a non-zero exit code.
 */
class SystemSchedulerHealth extends Command
{
    protected $signature = 'system:scheduler-health {--max-age=300}';

    protected $description = 'Проверяет, что планировщик недавно запускался (heartbeat)';

    public function handle(): int
    {
        $maxAge = max((int) $this->option('max-age'), 60);
        $value = Cache::get('scheduler:heartbeat');

        if (! is_string($value) || $value === '') {
            $this->error('Heartbeat не найден: планировщик ещё не запускался.');

            return self::FAILURE;
        }

        try {
            $at = Carbon::parse($value);
        } catch (Throwable) {
            $this->error("Heartbeat повреждён: {$value}");

            return self::FAILURE;
        }

        $age = now()->getTimestamp() - $at->getTimestamp();

        if ($age > $maxAge) {
            $this->error("Планировщик не отвечает: heartbeat устарел ({$age}s > {$maxAge}s).");

            return self::FAILURE;
        }

        $this->info("Планировщик активен (heartbeat {$age}s назад).");

        return self::SUCCESS;
    }
}
