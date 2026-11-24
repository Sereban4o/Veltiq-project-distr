<?php

namespace App\Extensions\Storage\Services;

use App\Models\StorageMeta;
use App\Models\StorageShare;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Public link sharing for storage entries: token generation, optional
 * password, expiry and download limits.
 */
class StorageShareService
{
    public function __construct(
        private StorageMetadataService $metadata,
        private StorageAuditService $audit,
        private StorageShareToken $tokens,
    ) {
    }

    /**
     * @param  array{password?:?string,expires_in?:?int,max_downloads?:?int,can_download?:bool}  $options
     * @return array<string, mixed>
     */
    public function create(StorageMeta $entry, User $user, array $options): array
    {
        $token = $this->randomToken();
        $expiresIn = $options['expires_in'] ?? null;

        if ($expiresIn !== null && $expiresIn !== '') {
            $expiresIn = min((int) $expiresIn, (int) config('storage_browser.share_max_expiry_days', 30));
            $expiresIn = max(1, $expiresIn);
        } else {
            $expiresIn = (int) config('storage_browser.share_default_expiry_days', 7);
        }

        $share = StorageShare::query()->create([
            'token_hash' => hash('sha256', $token),
            'entry_id' => $entry->getKey(),
            'disk' => $entry->disk,
            'path' => $entry->path,
            'created_by' => $user->getKey(),
            'password_hash' => !empty($options['password']) ? Hash::make((string) $options['password']) : null,
            'can_download' => $options['can_download'] ?? true,
            'max_downloads' => $options['max_downloads'] ?? null,
            'expires_at' => now()->addDays($expiresIn),
        ]);

        $this->audit->record('share.create', entry: $entry, payload: ['share_id' => $share->getKey(), 'expires_at' => $share->expires_at?->toIso8601String()]);

        return $this->payload($share, $token);
    }

    /** @return \Illuminate\Support\Collection<int, StorageShare> */
    public function listFor(StorageMeta|string $path, ?User $user)
    {
        $path = $path instanceof StorageMeta ? $path->path : $path;

        $query = StorageShare::query()
            ->where('path', StoragePath::normalize($path))
            ->whereNull('revoked_at');

        if ($user !== null && !$user->isAdmin()) {
            $query->where('created_by', $user->getKey());
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function byId(int $id): StorageShare
    {
        $share = StorageShare::query()->find($id);
        if ($share === null) {
            throw StorageNotFoundException::make();
        }

        return $share;
    }

    public function revoke(StorageShare $share): void
    {
        $share->forceFill(['revoked_at' => now()])->save();
        $this->audit->record('share.revoke', path: $share->path, payload: ['share_id' => $share->getKey()], disk: $share->disk);
    }

    public function findByToken(string $token): StorageShare
    {
        $share = StorageShare::query()->where('token_hash', hash('sha256', $token))->first();

        if ($share === null) {
            throw StorageNotFoundException::make();
        }

        return $share;
    }

    /** Reject revoked/expired/download-exhausted shares with a 410. */
    public function assertUsable(StorageShare $share): void
    {
        if ($share->revoked_at !== null) {
            $this->auditDenied($share, 'revoked');

            throw StorageShareUnavailableException::gone('Ссылка отозвана');
        }

        if ($share->expires_at !== null && $share->expires_at->isPast()) {
            $this->auditDenied($share, 'expired');

            throw StorageShareUnavailableException::gone('Срок действия ссылки истёк');
        }

        if ($share->hasReachedDownloadLimit() && $share->password_hash === null) {
            $this->auditDenied($share, 'limit');

            throw StorageShareUnavailableException::gone('Лимит скачиваний исчерпан');
        }
    }

    public function requiresPassword(StorageShare $share): bool
    {
        return $share->password_hash !== null;
    }

    /**
     * Verify a password and return a fresh access token.
     */
    public function unlock(StorageShare $share, string $password): string
    {
        if ($share->locked_until !== null && $share->locked_until->isFuture()) {
            $this->audit->record('share.lockout', path: $share->path, payload: ['share_id' => $share->getKey()], status: 429, disk: $share->disk);

            throw StorageShareUnavailableException::gone('Слишком много неверных попыток. Повторите позже.');
        }

        if ($share->password_hash === null || Hash::check($password, $share->password_hash)) {
            $share->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();

            return $this->tokens->sign((int) $share->getKey(), (int) $share->entry_id, (int) config('storage_browser.share_token_ttl', 3600));
        }

        $attempts = (int) $share->failed_attempts + 1;
        $attributes = ['failed_attempts' => $attempts];
        if ($attempts >= 10) {
            $attributes['locked_until'] = now()->addMinutes(15);
        }
        $share->forceFill($attributes)->save();

        $this->audit->record('share.denied', path: $share->path, payload: ['share_id' => $share->getKey(), 'attempts' => $attempts], status: 403, disk: $share->disk);

        throw new \Illuminate\Auth\Access\AuthorizationException('Неверный пароль');
    }

    public function cookieName(StorageShare $share): string
    {
        return 'storage_share_' . $share->getKey();
    }

    /** True when the request carries a valid unlock token/cookie. */
    public function hasAccess(StorageShare $share, Request $request): bool
    {
        if ($share->password_hash === null) {
            return true;
        }

        $access = (string) $request->query('access', '');
        if ($access !== '' && $this->tokens->verify($access, (int) $share->getKey()) !== null) {
            return true;
        }

        $cookie = (string) $request->cookie($this->cookieName($share));

        return $cookie !== '' && $this->tokens->verify($cookie, (int) $share->getKey()) !== null;
    }

    /** Record a successful access; the download counter only moves on download. */
    public function registerAccess(StorageShare $share, bool $download): void
    {
        $attributes = ['last_accessed_at' => now()];

        if ($download) {
            $attributes['downloads_count'] = (int) $share->downloads_count + 1;
        }

        $share->forceFill($attributes)->save();

        $this->audit->record('share.access', path: $share->path, payload: ['share_id' => $share->getKey(), 'download' => $download], disk: $share->disk);
    }

    /** @return array<string, mixed> */
    public function payload(StorageShare $share, ?string $token = null): array
    {
        $path = '/s/' . ($token ?? '');

        return [
            'id' => $share->getKey(),
            'token' => $token,
            'path' => $token !== null ? $path : null,
            'url' => $token !== null ? rtrim((string) config('app.url'), '/') . $path : null,
            'file' => $share->path,
            'requires_password' => $this->requiresPassword($share),
            'can_download' => (bool) $share->can_download,
            'max_downloads' => $share->max_downloads,
            'downloads_count' => (int) $share->downloads_count,
            'expires_at' => $share->expires_at?->toIso8601String(),
            'created_at' => $share->created_at?->toIso8601String(),
        ];
    }

    private function auditDenied(StorageShare $share, string $reason): void
    {
        $this->audit->record('share.denied', path: $share->path, payload: ['share_id' => $share->getKey(), 'reason' => $reason], status: 410, disk: $share->disk);
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
