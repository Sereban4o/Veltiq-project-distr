<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUpdateLog;
use App\Services\Updates\SystemUpdateApplier;
use App\Services\Updates\SystemUpdateClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemUpdateController extends Controller
{
    public function index(SystemUpdateClient $client): View
    {
        return view('admin.system-update.index', [
            'status' => $client->status(),
            'logs' => SystemUpdateLog::with('user')->latest()->limit(20)->get(),
            'running' => SystemUpdateLog::running()->with('user')->latest()->get(),
            'allowApply' => (bool) config('update_service.allow_apply'),
            'applyCommand' => (string) config('update_service.apply_command'),
        ]);
    }

    public function check(SystemUpdateClient $client): RedirectResponse
    {
        $status = $client->status(true);

        if ($status['check_error'] !== null) {
            return back()->with('error', 'Не удалось проверить обновления: ' . $status['check_error']);
        }

        if ($status['update_available'] && is_array($status['latest'])) {
            return back()->with('success', 'Доступно обновление ' . (string) ($status['latest']['version'] ?? ''));
        }

        return back()->with('success', 'Обновлений нет: установлена актуальная версия (' . $status['current_version'] . ').');
    }

    public function apply(SystemUpdateClient $client, SystemUpdateApplier $applier, Request $request): RedirectResponse
    {
        if (! config('update_service.allow_apply')) {
            return back()->with('error', 'Применение обновлений отключено (UPDATE_SERVICE_ALLOW_APPLY=false).');
        }

        $status = $client->status(true);

        if (! $status['update_available']) {
            return back()->with('error', 'Обновлений нет — применять нечего.');
        }

        $log = $applier->apply(
            $request->user()?->id,
            (string) $status['current_version'],
            (string) ($status['latest']['version'] ?? ''),
        );

        if ($log->status === SystemUpdateLog::STATUS_SUCCESS) {
            return back()->with('success', trim('Обновление применено. ' . (string) $log->message));
        }

        return back()->with('error', 'Не удалось применить обновление: ' . (string) ($log->message ?? 'см. журнал'));
    }
}
