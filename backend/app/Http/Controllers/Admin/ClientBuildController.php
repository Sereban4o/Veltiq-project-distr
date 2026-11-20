<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Builds\ClientBuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Admin page that triggers preset (branded) APK/MSI builds in GitHub Actions.
 */
class ClientBuildController extends Controller
{
    public function __construct(private readonly ClientBuildService $builds)
    {
    }

    public function index()
    {
        $settings = [
            'repo' => $this->builds->repo(),
            'workflow' => $this->builds->workflow(),
            'ref' => $this->builds->ref(),
            'token_set' => $this->builds->token() !== '',
        ];

        $runs = [];
        $runsError = null;

        if ($this->builds->configured()) {
            try {
                $runs = $this->builds->runs();
            } catch (Throwable $e) {
                $runsError = $e->getMessage();
            }
        }

        return view('admin.client-builds.index', compact('settings', 'runs', 'runsError'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'brand' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/i'],
            'app_name' => 'nullable|string|max:100',
            'server_host' => 'required|string|max:255',
            'server_port' => 'required|integer|min:1|max:65535',
            'server_https' => 'nullable|boolean',
            'ntfy_host' => 'nullable|string|max:255',
            'ntfy_port' => 'nullable|integer|min:1|max:65535',
            'ntfy_user' => 'nullable|string|max:255',
            'ntfy_pass' => 'nullable|string|max:255',
            'ntfy_https' => 'nullable|boolean',
        ]);

        $inputs = [
            'brand' => (string) $validated['brand'],
            'app_name' => (string) ($validated['app_name'] ?? ''),
            'server_host' => (string) $validated['server_host'],
            'server_port' => (string) $validated['server_port'],
            'server_https' => $request->boolean('server_https') ? 'true' : 'false',
            'ntfy_host' => (string) ($validated['ntfy_host'] ?? ''),
            'ntfy_port' => isset($validated['ntfy_port']) ? (string) $validated['ntfy_port'] : '0',
            'ntfy_user' => (string) ($validated['ntfy_user'] ?? ''),
            'ntfy_pass' => (string) ($validated['ntfy_pass'] ?? ''),
            'ntfy_https' => $request->boolean('ntfy_https') ? 'true' : 'false',
        ];

        try {
            $this->builds->dispatch($inputs);
        } catch (Throwable $e) {
            return back()->withInput()->with('error', 'Не удалось запустить сборку: ' . $e->getMessage());
        }

        return back()->with('success', 'Сборка запущена. Статус — в GitHub Actions (см. список ниже).');
    }

    public function settings(Request $request)
    {
        $validated = $request->validate([
            'repo' => ['nullable', 'string', 'max:200', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'workflow' => 'nullable|string|max:200',
            'ref' => 'nullable|string|max:200',
            'token' => 'nullable|string|max:4000',
            'clear_token' => 'nullable|boolean',
        ]);

        $this->builds->saveSettings($validated);

        return back()->with('success', 'Настройки сборок сохранены');
    }

    /** Lightweight status endpoint for the admin page (optional polling). */
    public function status(): JsonResponse
    {
        try {
            $runs = $this->builds->runs(5);
        } catch (Throwable $e) {
            return response()->json(['data' => [], 'message' => $e->getMessage()], 200);
        }

        return response()->json(['data' => $runs]);
    }
}
