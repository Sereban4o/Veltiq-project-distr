<?php

namespace App\Services\Storage;

use App\Models\StorageAuditLog;
use App\Models\StorageMeta;
use Illuminate\Support\Str;
use Throwable;

/**
 * Append-only journal for file storage access. All writes go through
 * {@see record()} and never throw: auditing must not break the operation.
 */
class StorageAuditService
{
    private ?string $requestId = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $action,
        ?string $path = null,
        array $payload = [],
        int $status = 200,
        ?StorageMeta $entry = null,
        ?int $size = null,
        ?string $disk = null,
    ): void {
        try {
            $request = request();
            $user = $request?->user();

            StorageAuditLog::query()->create([
                'user_id' => $user?->getKey(),
                'action' => $action,
                'disk' => $entry?->disk ?? $disk,
                'path' => $entry?->path ?? ($path !== null ? StoragePath::normalize($path) : null),
                'path_hash' => ($entry !== null || $path !== null)
                    ? ($entry?->path_hash ?? StoragePath::hash($path))
                    : null,
                'entry_id' => $entry?->getKey(),
                'size' => $size ?? $entry?->size,
                'status' => $status,
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent() !== null
                    ? Str::limit((string) $request->userAgent(), 512, '')
                    : null,
                'request_id' => $this->requestId(),
                'payload' => $payload === [] ? null : $payload,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Never surface audit failures to the caller.
        }
    }

    private function requestId(): string
    {
        return $this->requestId ??= (string) Str::uuid();
    }
}
