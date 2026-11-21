<?php

namespace App\Services\Branding;

use App\Models\Setting;
use App\Support\HtmlSanitizer;

/**
 * Runtime branding/theme configuration.
 *
 * The web client fetches this via `GET /api/v1/branding` and applies it with
 * MUI (`createTheme`) and CSS variables, so a rebrand does not require a
 * rebuild. Everything coming from the admin form is sanitised here: colours are
 * strict hex, asset URLs are restricted to relative or http(s) paths, and the
 * optional `customCss` is stripped of constructs that could break out of the
 * injected <style> or execute script.
 */
class BrandingService
{
    public const KEY = 'branding_config';

    private const MAX_CSS = 20000;

    private const LAYOUT_SHELLS = ['sidebar-left', 'sidebar-right', 'sidebar-top', 'sidebar-bottom'];

    private const LAYOUT_REGIONS = [
        'sidebar.top', 'sidebar.main', 'sidebar.bottom',
        'appbar.left', 'appbar.right',
        'content.before', 'content.after',
    ];

    private const LAYOUT_COMPONENTS = [
        'brand', 'collapse-toggle', 'menu-toggle',
        'menu.tasksbase', 'menu.knowledgebase', 'calendar', 'logout',
        'search', 'notifications', 'realtime-status', 'theme-toggle', 'profile',
        'footer', 'chat-widget',
    ];

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'brand' => 'veltiq',
            'appName' => 'Veltiq project',
            'logo' => '/veltiq-icon.svg',
            'favicon' => '/favicon.svg',
            'theme' => [
                'palette' => [
                    'primary' => '#0052CC',
                    'secondary' => '#7C3AED',
                    'mode' => 'system',
                    'modes' => ['light', 'dark'],
                ],
                'typography' => [
                    'fontFamily' => 'Inter, Helvetica, Arial, sans-serif',
                    'scale' => 1.0,
                ],
                'shape' => ['borderRadius' => 12],
                'layout' => 'comfortable',
            ],
            'menu' => ['order' => [], 'hidden' => []],
            'i18n' => ['appTitle' => 'Veltiq project', 'labels' => []],
            'customCss' => '',
            'layout' => [
                'shell' => 'sidebar-left',
                'sidebar' => ['width' => 260, 'collapsed' => 56, 'defaultCollapsed' => false],
                'regions' => [
                    'sidebar.top' => ['brand', 'collapse-toggle'],
                    'sidebar.main' => ['menu.tasksbase', 'menu.knowledgebase'],
                    'sidebar.bottom' => ['calendar', 'logout'],
                    'appbar.left' => ['menu-toggle', 'brand'],
                    'appbar.right' => ['search', 'notifications', 'realtime-status', 'theme-toggle', 'profile'],
                    'content.before' => [],
                    'content.after' => ['footer', 'chat-widget'],
                ],
                'customHtml' => [],
                'customIframe' => [],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $raw = Setting::get(self::KEY);
        $decoded = $raw !== null ? json_decode($raw, true) : null;

        return $this->sanitize(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $clean = $this->sanitize($data);
        Setting::put(self::KEY, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitize(array $data): array
    {
        $defaults = $this->defaults();
        $theme = is_array($data['theme'] ?? null) ? $data['theme'] : [];
        $palette = is_array($theme['palette'] ?? null) ? $theme['palette'] : [];
        $typography = is_array($theme['typography'] ?? null) ? $theme['typography'] : [];
        $shape = is_array($theme['shape'] ?? null) ? $theme['shape'] : [];
        $menu = is_array($data['menu'] ?? null) ? $data['menu'] : [];
        $i18n = is_array($data['i18n'] ?? null) ? $data['i18n'] : [];

        return [
            'brand' => $this->text($data['brand'] ?? null, $defaults['brand'], 64),
            'appName' => $this->text($data['appName'] ?? null, $defaults['appName'], 120),
            'logo' => $this->assetUrl($data['logo'] ?? null) ?? $defaults['logo'],
            'favicon' => $this->assetUrl($data['favicon'] ?? null) ?? $defaults['favicon'],
            'theme' => [
                'palette' => [
                    'primary' => $this->color($palette['primary'] ?? null) ?? $defaults['theme']['palette']['primary'],
                    'secondary' => $this->color($palette['secondary'] ?? null) ?? $defaults['theme']['palette']['secondary'],
                    'mode' => $this->mode($palette['mode'] ?? null),
                    'modes' => $this->modes($palette['modes'] ?? null),
                ],
                'typography' => [
                    'fontFamily' => $this->fontFamily($typography['fontFamily'] ?? null)
                        ?? $defaults['theme']['typography']['fontFamily'],
                    'scale' => $this->scale($typography['scale'] ?? null),
                ],
                'shape' => ['borderRadius' => $this->radius($shape['borderRadius'] ?? null)],
                'layout' => in_array(($theme['layout'] ?? ''), ['comfortable', 'compact'], true)
                    ? $theme['layout']
                    : 'comfortable',
            ],
            'menu' => [
                'order' => $this->stringList($menu['order'] ?? null),
                'hidden' => $this->stringList($menu['hidden'] ?? null),
            ],
            'i18n' => [
                'appTitle' => $this->text($i18n['appTitle'] ?? null, $defaults['i18n']['appTitle'], 120),
                'labels' => $this->labels($i18n['labels'] ?? null),
            ],
            'customCss' => $this->sanitizeCss((string) ($data['customCss'] ?? '')),
            'layout' => $this->layout($data['layout'] ?? null, $defaults['layout']),
        ];
    }

    /**
     * Sanitise the layout/regions config: shell and component keys are an
     * allow-list, sizes are clamped and unknown/duplicated entries dropped.
     *
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private function layout(mixed $value, array $default): array
    {
        $layout = is_array($value) ? $value : [];
        $sidebar = is_array($layout['sidebar'] ?? null) ? $layout['sidebar'] : [];
        $regions = is_array($layout['regions'] ?? null) ? $layout['regions'] : [];

        $clean = [];
        foreach (self::LAYOUT_REGIONS as $region) {
            $items = is_array($regions[$region] ?? null) ? $regions[$region] : $default['regions'][$region];
            $clean[$region] = $this->components($items);
        }

        $shell = $layout['shell'] ?? null;

        return [
            'shell' => in_array($shell, self::LAYOUT_SHELLS, true) ? $shell : $default['shell'],
            'sidebar' => [
                'width' => $this->clampInt($sidebar['width'] ?? null, 180, 400, $default['sidebar']['width']),
                'collapsed' => $this->clampInt($sidebar['collapsed'] ?? null, 40, 120, $default['sidebar']['collapsed']),
                'defaultCollapsed' => (bool) ($sidebar['defaultCollapsed'] ?? false),
            ],
            'regions' => $clean,
            'customHtml' => $this->customHtml($layout['customHtml'] ?? null),
            'customIframe' => $this->customIframe($layout['customIframe'] ?? null),
        ];
    }

    /**
     * Full HTML/CSS/JS documents rendered inside a sandboxed iframe (no
     * allow-same-origin), so the markup is intentionally NOT rewritten here.
     * Only the size and the target regions are constrained.
     *
     * @return array<string, string>
     */
    private function customIframe(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach (self::LAYOUT_REGIONS as $region) {
            $html = $value[$region] ?? null;

            if (is_string($html) && trim($html) !== '') {
                $result[$region] = mb_substr($html, 0, 100000);
            }
        }

        return $result;
    }

    /**
     * Static HTML fragments a customer may place per region. They are sanitised
     * server-side (the client never trusts them) and length-capped.
     *
     * @return array<string, string>
     */
    private function customHtml(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach (self::LAYOUT_REGIONS as $region) {
            $html = $value[$region] ?? null;

            if (! is_string($html) || trim($html) === '') {
                continue;
            }

            $clean = HtmlSanitizer::clean(mb_substr($html, 0, 20000));

            if (is_string($clean) && trim($clean) !== '') {
                $result[$region] = $clean;
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function components(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (is_string($item) && in_array($item, self::LAYOUT_COMPONENTS, true) && ! in_array($item, $result, true)) {
                $result[] = $item;
            }
        }

        return array_slice($result, 0, 40);
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return (int) max($min, min($max, (int) $value));
    }

    private function text(mixed $value, string $default, int $max): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim(strip_tags($value));

        return $value === '' ? $default : mb_substr($value, 0, $max);
    }

    private function assetUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2048) {
            return null;
        }

        // Relative paths ("/logo.svg") or absolute http(s) URLs; reject //host
        // and any other scheme (javascript:, data:, ...).
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return preg_match('#^https?://[^\s<>"]+$#i', $value) === 1 ? $value : null;
    }

    private function color(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($value)) === 1 ? trim($value) : null;
    }

    private function mode(mixed $value): string
    {
        return in_array($value, ['light', 'dark', 'system'], true) ? (string) $value : 'system';
    }

    /** @return list<string> */
    private function modes(mixed $value): array
    {
        if (! is_array($value)) {
            return ['light', 'dark'];
        }

        $modes = array_values(array_unique(array_intersect($value, ['light', 'dark'])));

        return $modes !== [] ? $modes : ['light', 'dark'];
    }

    private function fontFamily(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(strip_tags($value));
        if ($value === '' || mb_strlen($value) > 200) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9 ,\'"()\-]+$/', $value) === 1 ? $value : null;
    }

    private function scale(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 1.0;
        }

        return round(max(0.75, min(1.5, (float) $value)), 2);
    }

    private function radius(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 12;
        }

        return (int) max(0, min(32, (int) $value));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $item) === 1) {
                $result[] = $item;
            }
        }

        return array_slice(array_values(array_unique($result)), 0, 40);
    }

    /**
     * Menu/UI label overrides: `key => text`, keys restricted to simple slugs.
     *
     * @return array<string, string>
     */
    private function labels(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $label) {
            if (! is_string($key) || preg_match('/^[a-z0-9_\-]{1,40}$/i', $key) !== 1) {
                continue;
            }

            $label = is_string($label) ? trim(strip_tags($label)) : '';
            if ($label === '') {
                continue;
            }

            $result[$key] = mb_substr($label, 0, 60);

            if (count($result) >= 50) {
                break;
            }
        }

        return $result;
    }

    private function sanitizeCss(string $css): string
    {
        $css = mb_substr($css, 0, self::MAX_CSS);

        // Prevent breaking out of the injected <style> and common script vectors.
        $patterns = [
            '/<\s*\/?\s*(script|style|iframe|object|embed)\b/i',
            '/@import\b/i',
            '/expression\s*\(/i',
            '/javascript\s*:/i',
            '/vbscript\s*:/i',
            '/-moz-binding\s*:/i',
            '/behavior\s*:/i',
        ];

        foreach ($patterns as $pattern) {
            $css = preg_replace($pattern, '', $css) ?? '';
        }

        return trim($css);
    }
}
