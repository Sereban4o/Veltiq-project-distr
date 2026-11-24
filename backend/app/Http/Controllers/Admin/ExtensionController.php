<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Core\EntitlementClient;
use App\Services\Extensions\ExtensionInstaller;
use App\Services\Extensions\ExtensionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Extension administration page (Blade): what is discovered, what the license
 * allows, and enable/disable toggles. Install of third-party extensions is a
 * separate step (developer mode), documented in docs/11-extensions.md.
 */
class ExtensionController extends Controller
{
    public function __construct(
        private readonly ExtensionRegistry $registry,
        private readonly ExtensionInstaller $installer,
        private readonly EntitlementClient $entitlement,
    ) {
    }

    public function index(): View
    {
        return view('admin.extensions.index', [
            'extensions' => $this->registry->list(),
            'allowUnsigned' => (bool) config('extensions.allow_unsigned', false),
            'canInstall' => $this->entitlement->canInstallExtensions(),
            'enforced' => (bool) config('license.enforce', false),
        ]);
    }

    public function toggle(Request $request, string $id): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        if (! $this->registry->setEnabled($id, (bool) $validated['enabled'])) {
            return back()->withErrors([
                'extensions' => "Не удалось изменить расширение {$id}: он не входит в лицензию или ядро недоступно.",
            ]);
        }

        return back()->with('status', "Расширение {$id} обновлён.");
    }

    /** Install a third-party extension from a .zip (developer mode required). */
    public function install(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'archive' => ['required', 'file', 'max:65536'],
            'force' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('archive');
        if ($file === null) {
            return back()->withErrors(['extensions' => 'Архив расширения не загружен.']);
        }

        try {
            $manifest = $this->installer->install(
                $file->getRealPath(),
                (bool) ($validated['force'] ?? false),
                (string) optional($request->user())->email,
            );
        } catch (Throwable $e) {
            return back()->withErrors(['extensions' => 'Установка отклонена: ' . $e->getMessage()]);
        }

        return back()->with('status', "Расширение {$manifest->id} {$manifest->version} установлен. Включите его в таблице ниже.");
    }

    /** Remove an installed third-party module. */
    public function uninstall(Request $request, string $id): RedirectResponse
    {
        try {
            $manifest = $this->installer->uninstall($id, (string) optional($request->user())->email);
        } catch (Throwable $e) {
            return back()->withErrors(['extensions' => 'Удаление отклонено: ' . $e->getMessage()]);
        }

        $note = $manifest->uninstall === 'purge'
            ? ' Данные расширения помечены как подлежащие удалению (purge) — очистите таблицы вручную.'
            : '';

        return back()->with('status', "Расширение {$manifest->id} удалён (код). Данные сохранены." . $note);
    }
}
