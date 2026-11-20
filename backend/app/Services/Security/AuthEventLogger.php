<?php

namespace App\Services\Security;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the authentication journal. Never receives or stores secrets.
 */
class AuthEventLogger
{
    public function login(Request $request, User $user, string $channel = 'api', string $guard = 'web'): void
    {
        $this->write($request, 'login', true, null, $user, $channel, $guard);
    }

    public function failed(
        Request $request,
        ?string $email,
        string $reason = 'invalid_credentials',
        string $channel = 'api',
    ): void {
        $this->write($request, 'failed', false, $reason, null, $channel, null, $email);
    }

    public function logout(Request $request, ?User $user, string $channel = 'api'): void
    {
        $this->write($request, 'logout', true, null, $user, $channel, 'web');
    }

    public function tokenRefreshed(Request $request, User $user): void
    {
        $this->write($request, 'token_refresh', true, null, $user, 'api', 'web');
    }

    public function registered(Request $request, User $user): void
    {
        $this->write($request, 'register', true, null, $user, 'api', 'web');
    }

    public function passwordChanged(Request $request, User $user, ?string $source = null): void
    {
        $this->write($request, 'password_changed', true, null, $user, 'api', 'web', null, $source);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function write(
        Request $request,
        string $event,
        bool $successful,
        ?string $reason,
        ?User $user,
        string $channel,
        ?string $guard,
        ?string $email = null,
        ?string $source = null,
    ): void {
        try {
            LoginLog::create([
                'user_id' => $user?->id,
                'email' => $email ?? $user?->email,
                'event' => $event,
                'channel' => $channel,
                'guard' => $guard,
                'successful' => $successful,
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'meta' => $source ? ['source' => $source] : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Login journal write failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
