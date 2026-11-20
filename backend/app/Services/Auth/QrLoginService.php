<?php

namespace App\Services\Auth;

use App\Models\QrLoginSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issue and redeem one-time QR login codes.
 *
 * Flow: an authenticated web session asks for a code and renders it as a QR;
 * the mobile app scans it and calls `claim`, receiving its own Sanctum token.
 * The plaintext code is never stored — only its SHA-256 hash.
 */
class QrLoginService
{
    public const DEFAULT_TTL = 120;

    /**
     * @return array{code:string, expires_at:Carbon}
     */
    public function start(User $user, int $ttl = self::DEFAULT_TTL): array
    {
        $ttl = max(30, min($ttl, 600));
        $code = $this->generateCode();
        $expiresAt = now()->addSeconds($ttl);

        // Only one active code per user at a time.
        QrLoginSession::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        QrLoginSession::create([
            'user_id' => $user->id,
            'code_hash' => $this->hash($code),
            'expires_at' => $expiresAt,
        ]);

        return ['code' => $code, 'expires_at' => $expiresAt];
    }

    /**
     * Redeem a code and issue a token for the matching user.
     *
     * @return array{user:User, token:string}|null
     */
    public function claim(string $code, ?string $deviceName = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return DB::transaction(function () use ($code, $deviceName) {
            /** @var QrLoginSession|null $session */
            $session = QrLoginSession::where('code_hash', $this->hash($code))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($session === null) {
                return null;
            }

            $session->forceFill([
                'used_at' => now(),
                'device_name' => $deviceName !== null ? mb_substr($deviceName, 0, 255) : null,
            ])->save();

            /** @var User|null $user */
            $user = $session->user;
            if ($user === null) {
                return null;
            }

            $token = $user->createToken('qr-login:' . ($deviceName ?: 'mobile'))->plainTextToken;

            return ['user' => $user, 'token' => $token];
        });
    }

    private function generateCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
