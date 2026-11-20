<?php

namespace App\Services\Assets;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Frontend bundle integrity.
 *
 * The build emits `asset-manifest.json` with a `bundleId`; the client reports it
 * on every API request (`X-Veltiq-Bundle`). The expected id is pinned per
 * deployment (`assets:pin`). A mismatch — a modified or unexpected bundle — is
 * logged and audited, but **never blocks** the request (§9 of the licensing plan).
 */
class BundleIntegrity
{
    public const KEY = 'assets_bundle_id';

    /** Minutes between repeated audit entries for the same mismatch. */
    private const ALERT_THROTTLE_MINUTES = 60;

    public function expected(): ?string
    {
        $value = Setting::get(self::KEY, null);

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function pin(?string $bundleId): void
    {
        Setting::put(self::KEY, $bundleId !== null && $bundleId !== '' ? $bundleId : null);
    }

    /**
     * Compare the reported bundle id with the expected one.
     *
     * @return array{ok:bool, expected:string, reported:?string}|null null when no id is pinned
     */
    public function check(?string $reported): ?array
    {
        $expected = $this->expected();
        if ($expected === null) {
            return null;
        }

        $reported = $reported !== null ? trim($reported) : null;
        $reported = $reported === '' ? null : $reported;

        return [
            'ok' => $reported === $expected,
            'expected' => $expected,
            'reported' => $reported,
        ];
    }

    /**
     * @param  array{ok:bool, expected:string, reported:?string}  $result
     */
    public function reportMismatch(array $result, ?string $ip = null): void
    {
        Log::warning('Frontend bundle integrity mismatch', [
            'expected' => $result['expected'],
            'reported' => $result['reported'],
            'ip' => $ip,
        ]);

        // Throttle the audit entry: a modified bundle hits this on every request.
        if (! Cache::add('assets-integrity-alert', 1, now()->addMinutes(self::ALERT_THROTTLE_MINUTES))) {
            return;
        }

        try {
            ActivityLog::create([
                'user_id' => null,
                'subject_type' => 'system',
                'subject_id' => 0,
                'action' => 'assets.integrity_mismatch',
                'properties' => [
                    'expected' => $result['expected'],
                    'reported' => $result['reported'],
                    'ip' => $ip,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record bundle integrity audit entry', ['error' => $e->getMessage()]);
        }
    }
}
