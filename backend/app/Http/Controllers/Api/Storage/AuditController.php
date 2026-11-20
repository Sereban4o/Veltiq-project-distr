<?php

namespace App\Http\Controllers\Api\Storage;

use App\Http\Controllers\Api\Storage\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Models\StorageAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    use MapsStorageErrors;

    public function index(Request $request): JsonResponse
    {
        return $this->storage(function () use ($request) {
            return response()->json($this->page($request, admin: false));
        });
    }

    public static function query(Request $request, bool $admin): \Illuminate\Database\Eloquent\Builder
    {
        $query = StorageAuditLog::query()->orderByDesc('created_at')->orderByDesc('id');

        if (!$admin) {
            $query->where('user_id', $request->user()?->getKey());
        } elseif ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', (string) $request->query('action'));
        }

        if ($request->filled('path')) {
            $query->where('path_hash', \App\Services\Storage\StoragePath::hash((string) $request->query('path')));
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', (string) $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', (string) $request->query('to'));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function page(Request $request, bool $admin): array
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $page = self::query($request, $admin)->paginate($perPage);

        return [
            'data' => collect($page->items())->map(fn (StorageAuditLog $log) => [
                'id' => $log->getKey(),
                'user_id' => $log->user_id,
                'action' => $log->action,
                'path' => $log->path,
                'size' => $log->size,
                'status' => $log->status,
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
                'payload' => $log->payload,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }
}
