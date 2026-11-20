<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** @var array<string, string|null> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key] ?? $default;
        }

        $value = static::query()->where('key', $key)->value('value');
        self::$cache[$key] = $value;

        return $value ?? $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        self::$cache[$key] = $value;
    }

    public static function forgetCache(): void
    {
        self::$cache = [];
    }

    /**
     * Read a value that may be stored encrypted; tolerates legacy plaintext.
     */
    public static function getEncrypted(string $key, ?string $default = null): ?string
    {
        $value = static::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Store a value encrypted; null/empty clears it. Never double-encrypts.
     */
    public static function putEncrypted(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            static::put($key, null);

            return;
        }

        try {
            Crypt::decryptString($value);
            static::put($key, $value);
        } catch (Throwable) {
            static::put($key, Crypt::encryptString($value));
        }
    }
}
