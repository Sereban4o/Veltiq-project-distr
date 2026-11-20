<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Skin;
use App\Services\Branding\BrandingService;
use App\Services\Branding\SkinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin editor for branding "skins": named appearance presets (palette, shape,
 * typography, logo, menu, i18n) the customer can switch without a rebuild.
 */
class SkinController extends Controller
{
    public function __construct(
        private readonly SkinService $skins,
        private readonly BrandingService $branding,
    ) {
    }

    public function index(): View
    {
        return view('admin.skins.index', [
            'skins' => Skin::query()
                ->with('creator:id,first_name,last_name')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'branding' => $this->branding->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.skins.create', [
            'config' => $this->branding->defaults(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $logo = $this->resolveAsset($request, 'logo_file', 'logo_url', null);
        $favicon = $this->resolveAsset($request, 'favicon_file', 'favicon_url', null);

        $skin = Skin::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'config' => $this->skins->buildConfig($validated, null, $logo, $favicon),
            'logo_path' => $logo,
            'favicon_path' => $favicon,
            'is_active' => false,
            'created_by' => Auth::id(),
        ]);

        // The first skin becomes active automatically; otherwise honour the checkbox.
        if ($request->boolean('activate') || ! Skin::query()->where('is_active', true)->exists()) {
            $this->skins->apply($skin);
        }

        return redirect()->route('admin.skins.index')->with('success', 'Скин создан');
    }

    public function edit(Skin $skin): View
    {
        return view('admin.skins.edit', [
            'skin' => $skin,
            'config' => $this->skins->payload($skin),
        ]);
    }

    public function update(Request $request, Skin $skin): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $oldLogo = $skin->logo_path;
        $oldFavicon = $skin->favicon_path;

        $logo = $this->resolveAsset($request, 'logo_file', 'logo_url', $oldLogo);
        $favicon = $this->resolveAsset($request, 'favicon_file', 'favicon_url', $oldFavicon);

        $skin->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'config' => $this->skins->buildConfig($validated, $skin, $logo, $favicon),
            'logo_path' => $logo,
            'favicon_path' => $favicon,
        ]);

        if ($oldLogo !== $logo) {
            $this->skins->deleteFile($oldLogo);
        }

        if ($oldFavicon !== $favicon) {
            $this->skins->deleteFile($oldFavicon);
        }

        // Edits to the applied skin should reach the clients immediately.
        $this->skins->syncActive($skin);

        return redirect()->route('admin.skins.edit', $skin)->with('success', 'Скин сохранён');
    }

    public function destroy(Skin $skin): RedirectResponse
    {
        if ($skin->is_active) {
            return back()->with('error', 'Нельзя удалить активный скин. Сначала примените другой скин.');
        }

        $this->skins->deleteFile($skin->logo_path);
        $this->skins->deleteFile($skin->favicon_path);
        $skin->delete();

        return redirect()->route('admin.skins.index')->with('success', 'Скин удалён');
    }

    public function duplicate(Skin $skin): RedirectResponse
    {
        $copy = $this->skins->duplicate($skin);

        return redirect()->route('admin.skins.edit', $copy)->with('success', 'Скин скопирован');
    }

    public function apply(Skin $skin): RedirectResponse
    {
        $this->skins->apply($skin);

        return back()->with('success', 'Скин применён');
    }

    private function resolveAsset(Request $request, string $fileField, string $urlField, ?string $current): ?string
    {
        $file = $request->file($fileField);
        $uploaded = $this->skins->storeUpload($file instanceof UploadedFile ? $file : null, $fileField);

        if ($uploaded !== null) {
            return $uploaded;
        }

        $url = $request->input($urlField);

        if (is_string($url) && trim($url) !== '') {
            return trim($url);
        }

        return $current;
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'appName' => 'nullable|string|max:120',
            'brand' => 'nullable|string|max:64',
            'primary' => 'nullable|string|max:7',
            'secondary' => 'nullable|string|max:7',
            'mode' => 'nullable|in:light,dark,system',
            'borderRadius' => 'nullable|integer|min:0|max:32',
            'fontFamily' => 'nullable|string|max:200',
            'layout' => 'nullable|in:comfortable,compact',
            'customCss' => 'nullable|string|max:20000',
            'menu_order' => 'nullable|string|max:400',
            'menu_hidden' => 'nullable|string|max:400',
            'labels' => 'nullable|string|max:4000',
            'logo_file' => 'nullable|file|max:2048',
            'logo_url' => ['nullable', 'string', 'max:2048', 'regex:#^(/(?!/)|https?://[^\s<>"]+)$#i'],
            'favicon_file' => 'nullable|file|max:1024',
            'favicon_url' => ['nullable', 'string', 'max:2048', 'regex:#^(/(?!/)|https?://[^\s<>"]+)$#i'],
            'activate' => 'sometimes|boolean',
        ]);
    }
}
