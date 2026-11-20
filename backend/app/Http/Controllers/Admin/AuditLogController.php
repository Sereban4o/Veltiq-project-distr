<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->with('user')
            ->latest('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $users = User::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        $actions = ActivityLog::query()->distinct()->orderBy('action')->pluck('action');
        $subjectTypes = ActivityLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type');

        return view('admin.audit.index', compact('logs', 'users', 'actions', 'subjectTypes', 'filters'));
    }

    public function show(ActivityLog $auditLog)
    {
        $auditLog->load('user', 'subject');

        return view('admin.audit.show', ['log' => $auditLog]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'audit-logs-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'id', 'created_at', 'user', 'action', 'subject_type', 'subject_id',
                'description', 'ip_address',
            ]);

            $this->query($filters)
                ->with('user:id,first_name,last_name')
                ->orderBy('id')
                ->chunk((int) config('audit.export_chunk', 1000), function ($logs) use ($handle) {
                    foreach ($logs as $log) {
                        fputcsv($handle, [
                            $log->id,
                            $log->created_at?->toDateTimeString(),
                            $log->user?->full_name,
                            $log->action,
                            $log->subject_type,
                            $log->subject_id,
                            $log->description,
                            $log->ip_address,
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
            'subject_type' => $request->string('subject_type')->toString() ?: null,
            'q' => $request->string('q')->toString() ?: null,
            'date_from' => $request->date('date_from')?->startOfDay(),
            'date_to' => $request->date('date_to')?->endOfDay(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters)
    {
        return ActivityLog::query()
            ->when($filters['user_id'], fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['action'], fn ($q, $action) => $q->where('action', $action))
            ->when($filters['subject_type'], fn ($q, $type) => $q->where('subject_type', $type))
            ->when($filters['q'], fn ($q, $term) => $q->where(function ($sub) use ($term) {
                $sub->where('description', 'ilike', "%{$term}%")
                    ->orWhere('action', 'ilike', "%{$term}%");
            }))
            ->when($filters['date_from'], fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['date_to'], fn ($q, $to) => $q->where('created_at', '<=', $to));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, $request->integer('per_page', 50)));
    }
}
