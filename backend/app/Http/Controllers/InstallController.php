<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Services\Installer\DatabaseProbe;
use App\Services\Installer\EnvWriter;
use App\Services\Installer\InstallationState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Throwable;

/**
 * Web installer (CMS-style): choose the database server, database and site
 * administrator, then migrate + seed and lock the installer.
 *
 * Available only while `storage/app/installed.lock` is absent.
 */
class InstallController extends Controller
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly EnvWriter $env,
        private readonly DatabaseProbe $probe,
    ) {
    }

    public function show(): View|RedirectResponse
    {
        if ($this->state->isInstalled()) {
            return redirect('/');
        }

        return view('install.requirements', [
            'requirements' => $this->requirements(),
            'drivers' => $this->probe->supportedDrivers(),
        ]);
    }

    public function databaseForm(): View|RedirectResponse
    {
        if ($this->state->isInstalled()) {
            return redirect('/');
        }

        return view('install.database', [
            'drivers' => $this->probe->supportedDrivers(),
            'current' => [
                'driver' => (string) config('database.default', 'pgsql'),
                'host' => (string) $this->env->read('DB_HOST', '127.0.0.1'),
                'port' => (string) $this->env->read('DB_PORT', ''),
                'database' => (string) $this->env->read('DB_DATABASE', ''),
                'username' => (string) $this->env->read('DB_USERNAME', ''),
            ],
        ]);
    }

    public function database(Request $request): RedirectResponse
    {
        if ($this->state->isInstalled()) {
            return redirect('/');
        }

        $data = $request->validate([
            'driver' => ['required', 'in:pgsql,mysql,sqlite'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'create_database' => ['nullable', 'boolean'],
            'app_url' => ['nullable', 'url', 'max:255'],
        ]);

        $config = [
            'driver' => $data['driver'],
            'host' => $data['host'] ?? '127.0.0.1',
            'port' => $data['port'] ?? ($data['driver'] === 'pgsql' ? 5432 : 3306),
            'database' => $data['database'],
            'username' => $data['username'] ?? '',
            'password' => $data['password'] ?? '',
        ];

        try {
            if (($data['create_database'] ?? false) === true || ($data['create_database'] ?? '') === '1') {
                $this->probe->createDatabase($config);
            }

            $this->probe->assertConnectable($config);
        } catch (Throwable $e) {
            return back()->withErrors(['database' => $e->getMessage()])->withInput();
        }

        $appUrl = $data['app_url'] ?? $request->getSchemeAndHttpHost();

        $this->env->write([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => $this->ensureAppKey(),
            'APP_URL' => rtrim($appUrl, '/'),
            'DB_CONNECTION' => $config['driver'],
            'DB_HOST' => $config['driver'] === 'sqlite' ? '' : (string) $config['host'],
            'DB_PORT' => $config['driver'] === 'sqlite' ? '' : (string) $config['port'],
            'DB_DATABASE' => (string) $config['database'],
            'DB_USERNAME' => $config['driver'] === 'sqlite' ? '' : (string) $config['username'],
            'DB_PASSWORD' => $config['driver'] === 'sqlite' ? '' : (string) $config['password'],
            // Bare-metal install: work without Redis; switch to Redis in production.
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
        ]);

        // The next request (admin step → migrations) must boot from the fresh
        // values, so a previously cached configuration has to go.
        Artisan::call('config:clear');

        return redirect()->route('install.admin');
    }

    public function adminForm(): View|RedirectResponse
    {
        if ($this->state->isInstalled()) {
            return redirect('/');
        }

        if ($this->env->read('DB_CONNECTION') === null && $this->env->read('DB_DATABASE') === null) {
            return redirect()->route('install.database')->withErrors([
                'database' => 'сначала укажите параметры базы данных',
            ]);
        }

        return view('install.admin');
    }

    public function finish(Request $request): RedirectResponse
    {
        if ($this->state->isInstalled()) {
            return redirect('/');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder', '--force' => true]);

            $adminRole = Role::query()->where('name', 'admin')->first();

            User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'first_name' => $data['name'],
                    'last_name' => '',
                    'password' => Hash::make($data['password']),
                    'status' => 'active',
                    'role_id' => $adminRole?->id,
                    'email_verified_at' => now(),
                ],
            );

            // The starter Kanban board must belong to the administrator, so it is
            // seeded after the account exists (BoardSeeder waits for an admin).
            Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\BoardSeeder', '--force' => true]);

            $this->state->lock();
        } catch (Throwable $e) {
            return back()->withErrors([
                'install' => 'Установка не завершена: ' . $e->getMessage(),
            ])->withInput();
        }

        return redirect('/admin/login')->with('status', 'Установка завершена. Войдите под созданным администратором.');
    }

    /** Public status for the SPA and monitoring (no database access). */
    public function status(): JsonResponse
    {
        $requirements = $this->requirements();

        return response()->json([
            'installed' => $this->state->isInstalled(),
            'requirements_ok' => ! in_array(false, array_column($requirements, 'ok'), true),
            'drivers' => array_keys($this->probe->supportedDrivers()),
        ]);
    }

    /** @return array<int, array{label: string, ok: bool, hint: string}> */
    private function requirements(): array
    {
        $envWritable = is_writable(base_path())
            || (is_file(base_path('.env')) && is_writable(base_path('.env')));

        return [
            ['label' => 'PHP 8.3+', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'hint' => 'текущая версия: ' . PHP_VERSION],
            ['label' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'hint' => 'кодировки и строки'],
            ['label' => 'openssl', 'ok' => extension_loaded('openssl'), 'hint' => 'шифрование и HTTPS'],
            ['label' => 'fileinfo', 'ok' => extension_loaded('fileinfo'), 'hint' => 'типы файлов'],
            ['label' => 'PDO + драйвер БД', 'ok' => $this->probe->supportedDrivers() !== [], 'hint' => 'pdo_pgsql, pdo_mysql или pdo_sqlite'],
            ['label' => 'storage/ записываемый', 'ok' => is_writable(storage_path()), 'hint' => 'кэш, логи, сессии'],
            ['label' => 'bootstrap/cache/ записываемый', 'ok' => is_writable(base_path('bootstrap/cache')), 'hint' => 'кэш конфигурации'],
            ['label' => '.env записываемый', 'ok' => $envWritable, 'hint' => 'инсталлятор сохраняет настройки'],
        ];
    }

    private function ensureAppKey(): string
    {
        $existing = (string) ($this->env->read('APP_KEY') ?? config('app.key', ''));

        if ($existing !== '') {
            return $existing;
        }

        return 'base64:' . base64_encode(random_bytes(32));
    }
}
