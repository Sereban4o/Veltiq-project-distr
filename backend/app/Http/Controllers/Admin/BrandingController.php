<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Branding\BrandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin editor for the runtime branding/theme served at `GET /api/v1/branding`.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding)
    {
    }

    public function index()
    {
        $config = $this->branding->get();

        return view('admin.branding.index', compact('config'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'brand' => 'nullable|string|max:64',
            'appName' => 'nullable|string|max:120',
            'logo' => 'nullable|string|max:2048',
            'favicon' => 'nullable|string|max:2048',
            'primary' => 'nullable|string|max:7',
            'secondary' => 'nullable|string|max:7',
            'mode' => 'nullable|in:light,dark,system',
            'borderRadius' => 'nullable|integer|min:0|max:32',
            'fontFamily' => 'nullable|string|max:200',
            'customCss' => 'nullable|string|max:20000',
            'menu_order' => 'nullable|string|max:400',
            'menu_hidden' => 'nullable|string|max:400',
            'labels' => 'nullable|string|max:4000',
            'layout_shell' => 'nullable|in:sidebar-left,sidebar-right,sidebar-top,sidebar-bottom',
            'sidebar_width' => 'nullable|integer|min:180|max:400',
            'sidebar_collapsed' => 'nullable|integer|min:40|max:120',
            'sidebar_default_collapsed' => 'sometimes|boolean',
            'layout_regions' => 'nullable|string|max:8000',
            'layout_html' => 'nullable|string|max:20000',
            'layout_iframe' => 'nullable|string|max:100000',
        ]);

        $payload = [
            'brand' => $validated['brand'] ?? null,
            'appName' => $validated['appName'] ?? null,
            'logo' => $validated['logo'] ?? null,
            'favicon' => $validated['favicon'] ?? null,
            'theme' => [
                'palette' => [
                    'primary' => $validated['primary'] ?? null,
                    'secondary' => $validated['secondary'] ?? null,
                    'mode' => $validated['mode'] ?? null,
                ],
                'typography' => ['fontFamily' => $validated['fontFamily'] ?? null],
                'shape' => ['borderRadius' => $validated['borderRadius'] ?? null],
            ],
            'i18n' => [
                'appTitle' => $validated['appName'] ?? null,
                'labels' => $this->parseLabels($validated['labels'] ?? ''),
            ],
            'menu' => [
                'order' => $this->splitList($validated['menu_order'] ?? ''),
                'hidden' => $this->splitList($validated['menu_hidden'] ?? ''),
            ],
            'customCss' => $validated['customCss'] ?? '',
            'layout' => [
                'shell' => $validated['layout_shell'] ?? null,
                'sidebar' => [
                    'width' => $validated['sidebar_width'] ?? null,
                    'collapsed' => $validated['sidebar_collapsed'] ?? null,
                    'defaultCollapsed' => (bool) ($validated['sidebar_default_collapsed'] ?? false),
                ],
                'regions' => $this->parseJsonObject($validated['layout_regions'] ?? ''),
                'customHtml' => $this->parseJsonObject($validated['layout_html'] ?? ''),
                'customIframe' => $this->parseJsonObject($validated['layout_iframe'] ?? ''),
            ],
        ];

        DB::transaction(function () use ($payload): void {
            $this->branding->save($payload);
        });

        return redirect()->route('admin.branding.index')->with('success', 'Брендинг сохранён');
    }

    /** @return list<string> */
    private function splitList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, fn (string $item) => $item !== ''));
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

    /** @return array<string, mixed> */
    private function parseJsonObject(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
