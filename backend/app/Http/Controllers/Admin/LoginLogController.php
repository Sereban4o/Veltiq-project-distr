<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoginLogController extends Controller
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
        $events = LoginLog::query()->distinct()->orderBy('event')->pluck('event');
        $channels = LoginLog::query()->distinct()->orderBy('channel')->pluck('channel');

        return view('admin.login-logs.index', compact('logs', 'users', 'events', 'channels', 'filters'));
    }

    public function show(LoginLog $loginLog)
    {
        $loginLog->load('user');

        return view('admin.login-logs.show', ['log' => $loginLog]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'login-logs-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($filters) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'id', 'created_at', 'email', 'user_id', 'event', 'channel',
                'successful', 'reason', 'ip_address', 'user_agent',
            ]);

            $this->query($filters)
                ->orderBy('id')
                ->chunk((int) config('audit.export_chunk', 1000), function ($logs) use ($handle) {
                    foreach ($logs as $log) {
                        fputcsv($handle, [
                            $log->id,
                            $log->created_at?->toDateTimeString(),
                            $log->email,
                            $log->user_id,
                            $log->event,
                            $log->channel,
                            $log->successful ? 'yes' : 'no',
                            $log->reason,
                            $log->ip_address,
                            $log->user_agent,
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
            'email' => $request->string('email')->toString() ?: null,
            'event' => $request->string('event')->toString() ?: null,
            'channel' => $request->string('channel')->toString() ?: null,
            'successful' => $request->has('successful') && $request->input('successful') !== ''
                ? $request->boolean('successful')
                : null,
            'date_from' => $request->date('date_from')?->startOfDay(),
            'date_to' => $request->date('date_to')?->endOfDay(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters)
    {
        return LoginLog::query()
            ->when($filters['user_id'], fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['email'], fn ($q, $email) => $q->whereLike('email', "%{$email}%"))
            ->when($filters['event'], fn ($q, $event) => $q->where('event', $event))
            ->when($filters['channel'], fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['successful'] !== null, fn ($q) => $q->where('successful', $filters['successful']))
            ->when($filters['date_from'], fn ($q, $from) => $q->where('created_at', '>=', $from))
            ->when($filters['date_to'], fn ($q, $to) => $q->where('created_at', '<=', $to));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, $request->integer('per_page', 50)));
    }
}
