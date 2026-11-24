<?php

namespace App\Console\Commands;

use App\Services\Core\CoreClient;
use App\Services\Extensions\ExtensionInstaller;
use App\Services\Extensions\ExtensionRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Extension platform operations (docs/11-extensions.md):
 *
 *   php artisan extensions list
 *   php artisan extensions sync
 *   php artisan extensions enable crm
 *   php artisan extensions disable crm
 *   php artisan extensions install /path/to/module.zip [--force]
 *   php artisan extensions uninstall acme.reports
 *   php artisan extensions doctor
 */
class ExtensionsCommand extends Command
{
    protected $signature = 'extensions {action=list : list|sync|enable|disable|install|uninstall|doctor} {id? : extension id, or the archive path for install} {--force : reinstall/replace an existing module}';

    protected $description = 'List, sync, install, enable/disable and audit extensions';

    public function handle(ExtensionRegistry $registry, CoreClient $core, ExtensionInstaller $installer): int
    {
        return match ($this->argument('action')) {
            'list' => $this->listExtensions($registry),
            'sync' => $this->sync($registry),
            'enable' => $this->toggle($registry, $core, true),
            'disable' => $this->toggle($registry, $core, false),
            'install' => $this->install($installer),
            'uninstall' => $this->uninstall($installer),
            'doctor' => $this->doctor($registry, $core),
            default => $this->invalid(),
        };
    }

    private function install(ExtensionInstaller $installer): int
    {
        $path = (string) $this->argument('id');
        if ($path === '') {
            $this->error('Укажите путь к архиву расширения (.zip)');

            return self::FAILURE;
        }

        try {
            $manifest = $installer->install($path, (bool) $this->option('force'), 'artisan');
        } catch (Throwable $e) {
            $this->error('Установка отклонена: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Расширение {$manifest->id} {$manifest->version} установлен");

        return self::SUCCESS;
    }

    private function uninstall(ExtensionInstaller $installer): int
    {
        $id = (string) $this->argument('id');
        if ($id === '') {
            $this->error('Укажите id расширения');

            return self::FAILURE;
        }

        try {
            $manifest = $installer->uninstall($id, 'artisan');
        } catch (Throwable $e) {
            $this->error('Удаление отклонено: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("Расширение {$manifest->id} удалён (код). Данные сохранены.");

        return self::SUCCESS;
    }

    private function listExtensions(ExtensionRegistry $registry): int
    {
        $rows = [];
        foreach ($registry->list() as $module) {
            $rows[] = [
                $module['id'],
                $module['version'],
                $module['tier'],
                $module['entitlement'] ?? '—',
                $module['allowed'] ? 'да' : 'нет',
                $module['enabled'] ? 'включён' : 'выключен',
                $module['reason'],
            ];
        }

        $this->table(
            ['расширение', 'версия', 'tier', 'entitlement', 'разрешён', 'состояние', 'причина'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function sync(ExtensionRegistry $registry): int
    {
        $enabled = $registry->refresh();

        if ($enabled === null) {
            $this->error('Ядро недоступно: состояние расширений не синхронизировано');

            return self::FAILURE;
        }

        $this->info('Синхронизировано с ядром. Включены: ' . ($enabled === [] ? '—' : implode(', ', $enabled)));

        return self::SUCCESS;
    }

    private function toggle(ExtensionRegistry $registry, CoreClient $core, bool $enabled): int
    {
        $id = (string) $this->argument('id');
        if ($id === '') {
            $this->error('Укажите id расширения');

            return self::FAILURE;
        }

        if (! $core->enabled()) {
            $this->error('Ядро недоступно (CORE_URL/CORE_TOKEN): состояние расширений хранится в ядре');

            return self::FAILURE;
        }

        if (! $registry->setEnabled($id, $enabled)) {
            $this->error("Не удалось изменить расширение {$id} (см. аудит ядра)");

            return self::FAILURE;
        }

        $this->info($enabled ? "Расширение {$id} включён" : "Расширение {$id} выключен");

        return self::SUCCESS;
    }

    private function doctor(ExtensionRegistry $registry, CoreClient $core): int
    {
        $manifests = $registry->discover();
        $this->info('Найдено манифестов: ' . count($manifests));

        foreach ($manifests as $id => $manifest) {
            $warnings = [];

            if (! class_exists($manifest->provider)) {
                $warnings[] = 'провайдер не найден: ' . $manifest->provider;
            }

            if ($manifest->tier === 'third_party' && ! config('extensions.allow_unsigned')) {
                $warnings[] = 'сторонний расширение без developer mode отключён';
            }

            $this->line(sprintf(
                '%s %s — %s',
                $id,
                $manifest->version,
                $warnings === [] ? 'OK' : implode('; ', $warnings),
            ));
        }

        if (! $core->enabled()) {
            $this->warn('Ядро недоступно: разрешения по лицензии не проверяются');
        }

        return self::SUCCESS;
    }

    private function invalid(): int
    {
        $this->error('Действия: list, sync, enable, disable, install, uninstall, doctor');

        return self::FAILURE;
    }
}
