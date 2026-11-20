<?php

namespace App\Services\Updates;

use App\Models\SystemUpdateLog;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Applies a system update by running the admin-configured deploy command
 * (for example: pull new images, restart containers and run migrations).
 */
class SystemUpdateApplier
{
    public function apply(?int $userId = null, ?string $fromVersion = null, ?string $toVersion = null): SystemUpdateLog
    {
        $log = SystemUpdateLog::create([
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'status' => 'pending',
            'user_id' => $userId,
        ]);

        $command = trim((string) config('update_service.apply_command'));

        if ($command === '') {
            $log->update([
                'status' => 'failed',
                'message' => 'Команда обновления не настроена (UPDATE_SERVICE_APPLY_COMMAND)',
            ]);

            return $log;
        }

        if (!config('update_service.allow_apply')) {
            $log->update([
                'status' => 'failed',
                'message' => 'Применение обновлений отключено (UPDATE_SERVICE_ALLOW_APPLY=false)',
            ]);

            return $log;
        }

        try {
            $process = Process::fromShellCommandline($command, base_path(), null, null, 3600);
            $process->run();

            $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());

            $log->update([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'message' => Str::limit($output !== '' ? $output : 'Команда выполнена без вывода', 60000),
            ]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'message' => $e->getMessage()]);
        }

        return $log;
    }
}
