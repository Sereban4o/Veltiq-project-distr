<?php

namespace App\Extensions\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ReleaseService;
use Illuminate\Http\JsonResponse;

/**
 * Public (authenticated) list of the latest published app builds, used by the
 * client-downloads page of the web app.
 */
class ReleasesController extends Controller
{
    public function index(ReleaseService $releases): JsonResponse
    {
        $items = [];

        foreach ($releases->manifests() as $manifest) {
            $platform = (string) ($manifest['platform'] ?? '');
            if ($platform === '') {
                continue;
            }

            $size = isset($manifest['size']) ? (int) $manifest['size'] : null;

            // Manifests written by the publish script may not carry a size.
            if ($size === null && !empty($manifest['path'])) {
                $binary = $releases->basePath() . '/' . $platform . '/' . basename((string) $manifest['path']);
                if (is_file($binary)) {
                    $size = filesize($binary) ?: null;
                }
            }

            $items[$platform] = [
                'platform' => $platform,
                'version' => (string) ($manifest['version'] ?? ''),
                'versionCode' => isset($manifest['versionCode']) ? (int) $manifest['versionCode'] : null,
                'path' => (string) ($manifest['path'] ?? ''),
                'notes' => (string) ($manifest['notes'] ?? ''),
                'sha256' => (string) ($manifest['sha256'] ?? ''),
                'size' => $size,
                'size_human' => $size !== null ? $this->humanSize($size) : null,
                'uploaded_at' => $manifest['uploaded_at'] ?? null,
            ];
        }

        // Keep a stable order for the UI regardless of the storage layout.
        $ordered = [];
        foreach ((array) config('updates.platforms', []) as $platform) {
            if (isset($items[$platform])) {
                $ordered[] = $items[$platform];
                unset($items[$platform]);
            }
        }

        return response()->json(['data' => array_merge($ordered, array_values($items))]);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' МБ';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', ' ') . ' КБ';
        }

        return $bytes . ' Б';
    }
}
