<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Core\CoreClient;
use App\Services\Core\EntitlementClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * License administration page (Blade).
 *
 * This page must work while the product is blocked — it is the only way an
 * operator can activate a license (docs/12-licensing.md).
 */
class LicenseController extends Controller
{
    public function __construct(
        private readonly CoreClient $core,
        private readonly EntitlementClient $entitlement,
    ) {
    }

    public function index(): View
    {
        $status = $this->core->licenseStatus();

        return view('admin.license.index', [
            'app' => $this->entitlement->state(),
            'core' => $status['ok'] ? $status['data'] : null,
            'coreReachable' => $status['ok'],
            'enforced' => (bool) config('license.enforce', false),
            'requestCode' => session('license_request_code'),
        ]);
    }

    /** Build the offline request code (fingerprint + nonce). */
    public function request(): RedirectResponse
    {
        $response = $this->core->licenseRequest();

        if (! $response['ok']) {
            return back()->withErrors(['license' => 'Ядро недоступно: код активации получить нельзя.']);
        }

        return back()->with('license_request_code', $response['data']);
    }

    /** Upload a signed license file (offline or online activation). */
    public function activate(Request $request): RedirectResponse
    {
        $request->validate([
            'license' => ['nullable', 'file', 'max:256'],
            'license_text' => ['nullable', 'string'],
        ]);

        $contents = null;
        if ($request->hasFile('license')) {
            $file = $request->file('license');
            $contents = $file !== null ? (string) file_get_contents($file->getRealPath()) : null;
        } elseif ($request->filled('license_text')) {
            $contents = $request->string('license_text')->toString();
        }

        if ($contents === null || trim($contents) === '') {
            return back()->withErrors(['license' => 'Приложите файл лицензии или вставьте его содержимое.']);
        }

        $response = $this->core->licenseActivate($contents);
        $this->forgetCaches();

        if (! $response['ok']) {
            return back()->withErrors([
                'license' => 'Лицензия не принята: ' . ($response['data']['error'] ?? 'ошибка ядра'),
            ]);
        }

        $state = $response['data']['status']['state'] ?? 'unknown';

        return back()->with('status', "Лицензия установлена, состояние: {$state}.");
    }

    /** Upload a signed revocation list. */
    public function crl(Request $request): RedirectResponse
    {
        $request->validate(['crl' => ['required', 'file', 'max:1024']]);

        $file = $request->file('crl');
        $contents = $file !== null ? (string) file_get_contents($file->getRealPath()) : '';

        $response = $this->core->licenseCrl($contents);
        $this->forgetCaches();

        if (! $response['ok']) {
            return back()->withErrors(['license' => 'CRL не принят: ' . ($response['data']['error'] ?? 'ошибка ядра')]);
        }

        return back()->with('status', 'Список отзыва принят.');
    }

    private function forgetCaches(): void
    {
        Cache::forget('license:ticket');
        Cache::forget('license:state');
    }
}
