<?php

namespace App\Extensions\Storage\Services;

use App\Models\Setting;
use App\Models\StorageMeta;
use App\Models\StorageTrash;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Storage quota accounting.
 *
 * The limit lives on the user (`storage_quota_bytes`: null = global default,
 * -1 = unlimited) and the global default in the `settings` table. Usage counts
 * live files plus trashed files; versions are reported separately and are not
 * enforced against the limit.
 */
class StorageQuotaService
{
    private const DEFAULT_LIMIT = 5 * 1024 * 1024 * 1024;

    private const CACHE_TTL = 300;

    public function globalLimit(): ?int
    {
        $value = Setting::get('storage_default_quota_bytes');

        return $value === null ? self::DEFAULT_LIMIT : (int) $value;
    }

    /** The effective byte limit, or null when unlimited. */
    public function limitFor(?User $user): ?int
    {
        if ($user === null) {
            return $this->globalLimit();
        }

        $quota = $user->storage_quota_bytes;

        if ($quota === null) {
            return $this->globalLimit();
        }

        return $quota < 0 ? null : (int) $quota;
    }

    /** @return array{used:int,used_live:int,used_trash:int,used_versions:int,limit:?int,percent:?int,source:string} */
    public function summary(?User $user): array
    {
        $limit = $this->limitFor($user);
        $usage = $this->usage($user);

        $source = 'global';
        if ($user !== null && $user->storage_quota_bytes !== null) {
            $source = $user->storage_quota_bytes < 0 ? 'unlimited' : 'user';
        } elseif ($limit === null) {
            $source = 'unlimited';
        }

        $percent = $limit === null || $limit === 0
            ? null
            : (int) min(100, round($usage['used'] / $limit * 100));

        return [
            'used' => $usage['used'],
            'used_live' => $usage['used_live'],
            'used_trash' => $usage['used_trash'],
            'used_versions' => $usage['used_versions'],
            'limit' => $limit,
            'percent' => $percent,
            'source' => $source,
        ];
    }

    /** @return array{used:int,used_live:int,used_trash:int,used_versions:int} */
    public function usage(?User $user): array
    {
        if ($user === null) {
            return ['used' => 0, 'used_live' => 0, 'used_trash' => 0, 'used_versions' => 0];
        }

        return Cache::remember($this->cacheKey($user), self::CACHE_TTL, function () use ($user) {
            $live = (int) StorageMeta::query()
                ->where('owner_id', $user->getKey())
                ->whereNull('deleted_at')
                ->sum('size');

            $trash = (int) StorageTrash::query()
                ->where('owner_id', $user->getKey())
                ->whereNull('purged_at')
                ->sum('size');

            $versions = (int) DB::table('storage_versions')
                ->join('storage_meta', 'storage_meta.id', '=', 'storage_versions.entry_id')
                ->where('storage_meta.owner_id', $user->getKey())
                ->sum('storage_versions.size');

            return [
                'used' => $live + $trash,
                'used_live' => $live,
                'used_trash' => $trash,
                'used_versions' => $versions,
            ];
        });
    }

    public function usedBytes(?User $user): int
    {
        return $this->usage($user)['used'];
    }

    /**
     * Ensure a write of $incoming bytes (replacing $replacing existing bytes)
     * fits within the user's quota.
     */
    public function assertCanWrite(?User $user, int $incoming, int $replacing = 0): void
    {
        $limit = $this->limitFor($user);
        if ($limit === null) {
            return;
        }

        $used = max(0, $this->usedBytes($user) - max(0, $replacing));

        if ($used + $incoming > $limit) {
            $this->forget($user);

            throw StorageQuotaException::for($used, $limit, $incoming);
        }
    }

    public function forget(?User $user): void
    {
        if ($user !== null) {
            Cache::forget($this->cacheKey($user));
        }
    }

    /** Rebuild the usage cache for every user that owns storage entries. */
    public function recalculate(?User $only = null): int
    {
        $users = $only !== null
            ? collect([$only])
            : User::query()->whereIn('id', StorageMeta::query()->whereNotNull('owner_id')->select('owner_id'))
                ->orWhereIn('id', StorageTrash::query()->whereNotNull('owner_id')->select('owner_id'))
                ->get();

        $count = 0;
        foreach ($users as $user) {
            $this->forget($user);
            $this->usage($user);
            $count++;
        }

        return $count;
    }

    private function cacheKey(User $user): string
    {
        return 'storage:usage:' . $user->getKey();
    }
}
