<?php

namespace App\Services\Branding;

use App\Models\Skin;
use App\Support\SvgSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Skins are named branding presets. Applying a skin copies its config into the
 * runtime branding setting (see BrandingService), so the compiled clients pick
 * it up without a rebuild.
 */
class SkinService
{
    public const DIR = 'skins';

    public const URL_PREFIX = '/storage/skins/';

    private const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'ico', 'svg'];

    public function __construct(private readonly BrandingService $branding)
    {
    }

    /** @return array<string, mixed> */
    public function payload(Skin $skin): array
    {
        $config = $skin->config;

        if ($skin->logo_path) {
            $config['logo'] = $skin->logo_path;
        }

        if ($skin->favicon_path) {
            $config['favicon'] = $skin->favicon_path;
        }

        return $this->branding->sanitize($config);
    }

    public function apply(Skin $skin): void
    {
        DB::transaction(function () use ($skin): void {
            Skin::query()
                ->whereKeyNot($skin->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $skin->forceFill(['is_active' => true])->save();

            $this->branding->save($this->payload($skin));
        });
    }

    /** Re-apply an already active skin after it was edited. */
    public function syncActive(Skin $skin): void
    {
        if ($skin->is_active) {
            $this->branding->save($this->payload($skin));
        }
    }

    /**
     * Stores an uploaded logo/favicon on the public disk and returns its URL.
     * SVG files are sanitized before they reach the disk.
     */
    public function storeUpload(?UploadedFile $file, string $field): ?string
    {
        if (! $file) {
            return null;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                $field => 'Недопустимый формат файла. Разрешены: png, jpg, jpeg, webp, ico, svg.',
            ]);
        }

        $name = Str::uuid()->toString() . '.' . $extension;

        if ($extension === 'svg') {
            $contents = SvgSanitizer::clean((string) file_get_contents((string) $file->getRealPath()));

            if ($contents === null) {
                throw ValidationException::withMessages([
                    $field => 'Не удалось прочитать SVG-файл.',
                ]);
            }

            Storage::disk('public')->put(self::DIR . '/' . $name, $contents);
        } else {
            $file->storeAs(self::DIR, $name, 'public');
        }

        return self::URL_PREFIX . $name;
    }

    public function deleteFile(?string $path): void
    {
        if (! $path || ! str_starts_with($path, self::URL_PREFIX)) {
            return;
        }

        $basename = basename($path);

        if ($basename === '' || str_contains($basename, '..')) {
            return;
        }

        Storage::disk('public')->delete(self::DIR . '/' . $basename);
    }

    /**
     * Builds the nested branding payload from the flat admin form fields and
     * runs it through BrandingService::sanitize().
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function buildConfig(array $validated, ?Skin $skin, ?string $logo, ?string $favicon): array
    {
        $appName = isset($validated['appName']) && is_string($validated['appName']) && trim($validated['appName']) !== ''
            ? $validated['appName']
            : null;

        $config = [
            'brand' => $this->stringOrNull($validated['brand'] ?? null),
            'appName' => $appName,
            'logo' => $logo ?? $skin?->logo_path,
            'favicon' => $favicon ?? $skin?->favicon_path,
            'theme' => [
                'palette' => [
                    'primary' => $this->stringOrNull($validated['primary'] ?? null),
                    'secondary' => $this->stringOrNull($validated['secondary'] ?? null),
                    'mode' => $this->stringOrNull($validated['mode'] ?? null),
                ],
                'typography' => [
                    'fontFamily' => $this->stringOrNull($validated['fontFamily'] ?? null),
                ],
                'shape' => [
                    'borderRadius' => $validated['borderRadius'] ?? null,
                ],
                'layout' => $this->stringOrNull($validated['layout'] ?? null),
            ],
            'menu' => [
                'order' => $this->splitList((string) ($validated['menu_order'] ?? '')),
                'hidden' => $this->splitList((string) ($validated['menu_hidden'] ?? '')),
            ],
            'i18n' => [
                'appTitle' => $appName,
                'labels' => $this->parseLabels((string) ($validated['labels'] ?? '')),
            ],
            'customCss' => (string) ($validated['customCss'] ?? ''),
        ];

        return $this->branding->sanitize($config);
    }

    public function duplicate(Skin $skin): Skin
    {
        $copy = $skin->replicate();
        $copy->name = mb_substr($skin->name, 0, 111) . ' (копия)';
        $copy->is_active = false;
        $copy->created_by = Auth::id();
        $copy->save();

        return $copy;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return list<string> */
    private function splitList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, fn (string $item): bool => $item !== ''));
    }

    /** @return array<string, string> */
    private function parseLabels(string $value): array
    {
        $result = [];

        foreach (preg_split('/\r?\n/', $value) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $label] = explode('=', $line, 2);
            $key = trim($key);
            $label = trim($label);

            if ($key !== '' && $label !== '') {
                $result[$key] = $label;
            }
        }

        return $result;
    }
}
