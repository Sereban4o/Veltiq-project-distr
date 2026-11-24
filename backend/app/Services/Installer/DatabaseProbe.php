<?php

namespace App\Services\Installer;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Verifies a database connection during installation and can create the database
 * for PostgreSQL/MySQL when it does not exist yet.
 *
 * Drivers: pgsql, mysql (MariaDB is compatible) and sqlite.
 */
class DatabaseProbe
{
    /** @return array<int, string> */
    public function supportedDrivers(): array
    {
        $drivers = [];

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL / MariaDB', 'sqlite' => 'SQLite'] as $driver => $label) {
            if (extension_loaded('pdo_' . $driver)) {
                $drivers[$driver] = $label;
            }
        }

        return $drivers;
    }

    /**
     * @param  array{driver: string, host?: string, port?: int|string, database: string, username?: string, password?: string}  $config
     *
     * @throws RuntimeException with a human-readable reason
     */
    public function assertConnectable(array $config): void
    {
        $driver = (string) ($config['driver'] ?? '');

        if (! extension_loaded('pdo_' . $driver)) {
            throw new RuntimeException("расширение pdo_{$driver} не установлено в PHP");
        }

        try {
            $this->connect($config, true);
        } catch (PDOException $e) {
            throw new RuntimeException($this->friendly($e));
        }
    }

    /**
     * Creates the database when the credentials allow it.
     *
     * @param  array{driver: string, host?: string, port?: int|string, database: string, username?: string, password?: string}  $config
     */
    public function createDatabase(array $config): void
    {
        $driver = (string) $config['driver'];

        if ($driver === 'sqlite') {
            $path = (string) $config['database'];
            if (! is_file($path)) {
                @touch($path);
            }

            return;
        }

        $maintenanceDatabase = $driver === 'pgsql' ? 'postgres' : 'mysql';
        $database = (string) $config['database'];

        if (preg_match('/^[A-Za-z0-9_\-]+$/', $database) !== 1) {
            throw new RuntimeException('имя базы может содержать только буквы, цифры, дефис и подчёркивание');
        }

        $pdo = $this->connect($config + ['database' => $maintenanceDatabase], false);
        $sql = $driver === 'pgsql'
            ? 'CREATE DATABASE "' . $database . '"'
            : 'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // 42P04 / 1007: database already exists — fine.
            if (! str_contains($e->getMessage(), 'already exists')) {
                throw new RuntimeException('не удалось создать базу: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function connect(array $config, bool $withDatabase): PDO
    {
        $driver = (string) $config['driver'];
        $database = (string) ($config['database'] ?? '');

        if ($driver === 'sqlite') {
            if ($database === '') {
                throw new RuntimeException('укажите путь к файлу SQLite');
            }

            return new PDO('sqlite:' . $database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
        $name = $withDatabase ? $database : ($driver === 'pgsql' ? 'postgres' : 'mysql');

        if ($driver === 'pgsql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        }

        return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    private function friendly(PDOException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'does not exist'), str_contains($message, 'Unknown database') => 'база данных не найдена (включите «создать базу», если её ещё нет)',
            str_contains($message, 'password authentication failed'), str_contains($message, 'Access denied') => 'неверный пользователь или пароль базы данных',
            str_contains($message, 'could not connect'), str_contains($message, 'Connection refused'), str_contains($message, 'getaddrinfo') => 'не удалось подключиться к серверу базы данных',
            default => 'ошибка подключения: ' . $message,
        };
    }
}
