<?php

namespace App\Services\Widgets;

use App\Models\Setting;

/**
 * Embeddable iframe panels (extension point, §12).
 *
 * Admins register external pages (dashboards, BI, internal tools) and the web
 * client renders them as sandboxed iframes. Only http(s) URLs are accepted and
 * the list is capped, so the feature cannot become an XSS or resource-abuse
 * vector.
 */
class WidgetService
{
    public const KEY = 'widgets_config';

    private const MAX_WIDGETS = 20;

    private const MAX_URL = 2048;

    private const MAX_TITLE = 80;

    private const MIN_HEIGHT = 240;

    private const MAX_HEIGHT = 2000;

    /**
     * Widgets as rendered by the client.
     *
     * @return list<array{id:string,title:string,url:string,height:int}>
     */
    public function all(): array
    {
        $raw = Setting::get(self::KEY);
        $decoded = $raw !== null ? json_decode($raw, true) : null;

        return is_array($decoded) ? $this->sanitize($decoded) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $widgets
     */
    public function save(array $widgets): void
    {
        Setting::put(self::KEY, json_encode($this->sanitize($widgets), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Parses the admin textarea: one widget per line, `Название | URL | высота`.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $value): array
    {
        $widgets = [];

        foreach (preg_split('/\r?\n/', $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $widgets[] = [
                'title' => $parts[0],
                'url' => $parts[1] ?? '',
                'height' => $parts[2] ?? null,
            ];
        }

        return $widgets;
    }

    /**
     * @param  list<array<string, mixed>>  $widgets
     * @return list<array{id:string,title:string,url:string,height:int}>
     */
    public function sanitize(array $widgets): array
    {
        $result = [];
        $seen = [];

        foreach ($widgets as $widget) {
            $url = $this->url($widget['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $title = $this->title($widget['title'] ?? null, $url);

            // Stable, collision-free slug used as the React key.
            $base = $this->slug($title);
            $id = $base;
            $suffix = 2;
            while (isset($seen[$id])) {
                $id = $base . '-' . $suffix++;
            }
            $seen[$id] = true;

            $result[] = [
                'id' => $id,
                'title' => $title,
                'url' => $url,
                'height' => $this->height($widget['height'] ?? null),
            ];

            if (count($result) >= self::MAX_WIDGETS) {
                break;
            }
        }

        return $result;
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::MAX_URL) {
            return null;
        }

        return preg_match('#^https?://[^\s<>"\'\#]+$#i', $value) === 1 ? $value : null;
    }

    private function title(mixed $value, string $fallbackUrl): string
    {
        $title = is_string($value) ? trim(strip_tags($value)) : '';
        if ($title === '') {
            $host = parse_url($fallbackUrl, PHP_URL_HOST);
            $title = is_string($host) && $host !== '' ? $host : 'Виджет';
        }

        return mb_substr($title, 0, self::MAX_TITLE);
    }

    private function height(mixed $value): int
    {
        $height = is_numeric($value) ? (int) $value : 480;

        return max(self::MIN_HEIGHT, min(self::MAX_HEIGHT, $height));
    }

    private function slug(string $title): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $title) ?? '';
        $slug = trim(mb_strtolower($slug), '-');

        return $slug !== '' ? mb_substr($slug, 0, 40) : 'widget';
    }
}
