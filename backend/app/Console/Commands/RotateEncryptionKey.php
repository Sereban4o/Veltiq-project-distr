<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;
use Throwable;

/**
 * Re-encrypts the encrypted `settings` values under a new APP_KEY.
 *
 * Usage (rotate from OLD to the currently configured APP_KEY):
 *   php artisan settings:rotate-encryption-key --old-key="base64:OLD..."
 *
 * If a stored value cannot be decrypted with the old key it is treated as
 * legacy plaintext and encrypted with the new key.
 */
class RotateEncryptionKey extends Command
{
    protected $signature = 'settings:rotate-encryption-key
        {--old-key= : Previous APP_KEY (base64:...) used to decrypt the stored values}
        {--key= : New APP_KEY to encrypt with (defaults to the current APP_KEY)}
        {--keys= : Comma-separated setting keys (defaults to the known encrypted keys)}';

    protected $description = 'Re-encrypt encrypted settings values under a new APP_KEY';

    /** @var array<int, string> */
    private const DEFAULT_KEYS = [
        'storage_password',
        'one_c_counterparties_password',
    ];

    public function handle(): int
    {
        $oldKey = (string) $this->option('old-key');
        if ($oldKey === '') {
            $this->error('Provide the previous key via --old-key.');

            return self::FAILURE;
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');

        try {
            $old = $this->encrypter($oldKey, $cipher);
        } catch (Throwable $e) {
            $this->error('Invalid --old-key: ' . $e->getMessage());

            return self::FAILURE;
        }

        $newKey = (string) ($this->option('key') ?: config('app.key'));

        try {
            $new = $this->encrypter($newKey, $cipher);
        } catch (Throwable $e) {
            $this->error('Invalid new APP_KEY: ' . $e->getMessage());

            return self::FAILURE;
        }

        $keysOption = (string) $this->option('keys');
        $keys = array_values(array_filter(array_map(
            'trim',
            explode(',', $keysOption !== '' ? $keysOption : implode(',', self::DEFAULT_KEYS)),
        )));

        $rotated = 0;
        foreach ($keys as $key) {
            $raw = Setting::get($key);
            if ($raw === null || $raw === '') {
                continue;
            }

            $plain = $this->tryDecrypt($old, $raw);

            if ($plain === null) {
                // Already encrypted under the new key — leave it untouched.
                if ($this->tryDecrypt($new, $raw) !== null) {
                    $this->line("Skipped {$key} (already using the new key).");

                    continue;
                }

                // Legacy plaintext value.
                $plain = $raw;
            }

            Setting::put($key, $new->encryptString($plain));
            $rotated++;
        }

        Setting::forgetCache();

        $this->info("Rotated {$rotated} setting(s).");

        return self::SUCCESS;
    }

    private function encrypter(string $key, string $cipher): Encrypter
    {
        $parsed = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        if (!is_string($parsed) || $parsed === '') {
            throw new InvalidArgumentException('Empty key');
        }

        return new Encrypter($parsed, $cipher);
    }

    private function tryDecrypt(Encrypter $encrypter, string $value): ?string
    {
        try {
            return $encrypter->decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }
}
