<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only admin view over the file storage access journal
 * ({@see StorageAuditLog}) with filters and CSV export.
 */
class StorageAuditController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->with('user:id,first_name,last_name')
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $users = User::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        $actions = StorageAuditLog::query()->distinct()->orderBy('action')->pluck('action');

        return view('admin.storage-audit.index', compact('logs', 'users', 'actions', 'filters'));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'storage-audit-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($filters): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'created_at', 'user', 'action', 'path', 'status', 'size', 'ip']);

            $this->query($filters)
                ->with('user:id,first_name,last_name')
                ->orderBy('id')
                ->chunk(1000, function ($logs) use ($handle): void {
                    foreach ($logs as $log) {
                        fputcsv($handle, [
                            $log->id,
                            $log->created_at?->toDateTimeString(),
                            $log->user?->full_name,
                            $log->action,
                            $log->path,
                            $log->status,
                            $log->size,
                            $log->ip,
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return [
            'user_id' => $request->integer('user_id') ?: null,
            'action' => $request->string('action')->toString() ?: null,
            'q' => $request->string('q')->toString() ?: null,
            'status' => $request->filled('status') ? $request->integer('status') : null,
            'date_from' => $request->date('date_from')?->startOfDay(),
            'date_to' => $request->date('date_to')?->endOfDay(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters)
    {
        return StorageAuditLog::query()
            ->when($filters['user_id'], fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['action'], fn ($q, $action) => $q->where('action', $action))
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['q'], fn ($q, $term) => $q->whereLike('path', "%{$term}%"))
            ->when($filters['date_from'], fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['date_to'], fn ($q, $to) => $q->where('created_at', '<=', $to));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, $request->integer('per_page', 50)));
    }
}
