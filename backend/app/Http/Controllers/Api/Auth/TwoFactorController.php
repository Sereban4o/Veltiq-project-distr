<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Two-factor authentication (TOTP) setup and login challenge.
 *
 * The secret is stored encrypted on the user; recovery codes are single-use.
 * Login with 2FA enabled returns a short-lived encrypted challenge instead of a
 * token (see AuthController::login).
 */
class TwoFactorController extends Controller
{
    private const CHALLENGE_TTL = 300;

    private const RECOVERY_CODES = 8;

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'confirmed_at' => optional($user->two_factor_confirmed_at)->toIso8601String(),
                'pending' => $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled(),
            ],
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Двухфакторная аутентификация уже включена'], 409);
        }

        $secret = Totp::generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_url' => Totp::provisioningUri($secret, (string) $user->email, 'Veltiq'),
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => 'required|string|max:20']);

        $user = $request->user();
        $secret = (string) $user->two_factor_secret;

        if ($secret === '') {
            return response()->json(['message' => 'Сначала запустите настройку'], 422);
        }

        if (! Totp::verify($secret, (string) $validated['code'])) {
            return response()->json(['message' => 'Неверный код'], 422);
        }

        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => Str::upper(Str::random(10)))
            ->all();

        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate(['code' => 'required|string|max:20']);

        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['data' => ['enabled' => false]]);
        }

        if (! $this->verifyOrConsume($user, (string) $validated['code'])) {
            return response()->json(['message' => 'Неверный код'], 422);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['data' => ['enabled' => false]]);
    }

    public function challenge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => 'required|string',
            'code' => 'required|string|max:20',
        ]);

        try {
            $payload = json_decode(
                Crypt::decryptString($validated['challenge_token']),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return response()->json(['message' => 'Сессия входа устарела'], 422);
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            return response()->json(['message' => 'Сессия входа устарела'], 422);
        }

        $user = User::find($payload['uid'] ?? 0);

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Сессия входа устарела'], 422);
        }

        if (! $this->verifyOrConsume($user, (string) $validated['code'])) {
            return response()->json(['message' => 'Неверный код'], 422);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'user' => $user->load('role'),
            'token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Accepts a TOTP code or a single-use recovery code (which is then removed).
     */
    private function verifyOrConsume(User $user, string $code): bool
    {
        if (Totp::verify((string) $user->two_factor_secret, $code)) {
            return true;
        }

        $normalized = Str::upper(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($normalized, $codes, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_values(array_diff($codes, [$normalized])),
        ])->save();

        return true;
    }

    public static function challengeToken(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'uid' => (int) $user->id,
            'exp' => time() + self::CHALLENGE_TTL,
            'nonce' => Str::random(16),
        ], JSON_THROW_ON_ERROR));
    }
}
