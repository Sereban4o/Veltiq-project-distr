<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\Ai\Retrieval\AiRetriever;
use App\Extensions\Ai\Services\WebFetch;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

/**
 * Admin settings for the AI assistant (local Ollama). Values are stored in the
 * `settings` table and take precedence over the env defaults in config/ai.php.
 *
 * Besides the connection itself, an administrator decides which sections the
 * assistant may read, which roles may use it, and which hosts it may download
 * pages from (no search engine is involved).
 */
class AiSettingsController extends Controller
{
    /** Human labels for the searchable sections (AiRetriever::TYPES order). */
    private const SECTIONS = [
        'pages' => 'База знаний (страницы)',
        'issues' => 'Задачи',
        'calendar' => 'Календарь',
        'deals' => 'CRM: сделки',
        'companies' => 'CRM: компании',
        'contacts' => 'CRM: контакты',
        'files' => 'Файлы',
        'mail' => 'Почта',
        'users' => 'Сотрудники',
        'worklogs' => 'Табель (worklogs)',
    ];

    public function index(): View
    {
        return view('admin.ai-settings.index', [
            'settings' => [
                'ai_enabled' => Setting::get('ai_enabled', config('ai.enabled') ? '1' : '0'),
                'ai_url' => Setting::get('ai_url', (string) config('ai.url')),
                'ai_model' => Setting::get('ai_model', (string) config('ai.model')),
                'ai_timeout' => Setting::get('ai_timeout', (string) config('ai.timeout')),
                'ai_max_sources' => Setting::get('ai_max_sources', (string) config('ai.max_sources')),
                'ai_max_context_chars' => Setting::get('ai_max_context_chars', (string) config('ai.max_context_chars')),
                'ai_history_messages' => Setting::get('ai_history_messages', (string) config('ai.history_messages')),
                'ai_show_sources' => Setting::get('ai_show_sources', config('ai.show_sources', true) ? '1' : '0'),
                'ai_retention_days' => Setting::get('ai_retention_days', (string) config('ai.retention_days')),
                'ai_sections' => $this->listSetting('ai_sections', (array) config('ai.sections', [])),
                'ai_roles' => $this->listSetting('ai_roles', (array) config('ai.roles', [])),
                'ai_web_enabled' => Setting::get('ai_web_enabled', config('ai.web_enabled') ? '1' : '0'),
                'ai_web_provider' => Setting::get('ai_web_provider', (string) config('ai.web_provider', 'fetch')),
                'ai_web_url' => Setting::get('ai_web_url', (string) config('ai.web_url')),
                'ai_web_domains' => implode("\n", $this->listSetting('ai_web_domains', (array) config('ai.web_domains', []))),
                'ai_web_timeout' => Setting::get('ai_web_timeout', (string) config('ai.web_timeout')),
                'ai_web_results' => Setting::get('ai_web_results', (string) config('ai.web_results')),
                'ai_web_max_chars' => Setting::get('ai_web_max_chars', (string) config('ai.web_max_chars')),
            ],
            'sections' => self::SECTIONS,
            'roles' => Role::query()->orderBy('name')->pluck('name')->all(),
            'envUrl' => (string) config('ai.url'),
            'envModel' => (string) config('ai.model'),
            'envSections' => implode(', ', (array) config('ai.sections', [])),
            'envRoles' => implode(', ', (array) config('ai.roles', [])),
            'envDomains' => implode(', ', (array) config('ai.web_domains', [])),
            'envWebUrl' => (string) config('ai.web_url'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_enabled' => ['nullable', 'boolean'],
            'ai_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\/.+/'],
            'ai_model' => ['nullable', 'string', 'max:100'],
            'ai_timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'ai_max_sources' => ['nullable', 'integer', 'min:1', 'max:20'],
            'ai_max_context_chars' => ['nullable', 'integer', 'min:500', 'max:50000'],
            'ai_history_messages' => ['nullable', 'integer', 'min:0', 'max:50'],
            'ai_show_sources' => ['nullable', 'boolean'],
            'ai_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'ai_sections' => ['sometimes', 'array'],
            'ai_sections.*' => ['string', 'in:' . implode(',', AiRetriever::TYPES)],
            'ai_roles' => ['sometimes', 'array'],
            'ai_roles.*' => ['string', 'max:60', 'exists:roles,name'],
            'ai_web_enabled' => ['nullable', 'boolean'],
            'ai_web_provider' => ['nullable', 'string', 'in:fetch,searxng,both'],
            'ai_web_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\/[^\s@]+$/'],
            'ai_web_domains' => ['nullable', 'string', 'max:2000'],
            'ai_web_timeout' => ['nullable', 'integer', 'min:1', 'max:30'],
            'ai_web_results' => ['nullable', 'integer', 'min:1', 'max:5'],
            'ai_web_max_chars' => ['nullable', 'integer', 'min:500', 'max:20000'],
        ], [
            'ai_sections.*.in' => 'Неизвестный раздел.',
            'ai_roles.*.exists' => 'Такой роли нет.',
        ]);

        $domains = app(WebFetch::class)->parseDomains((string) ($validated['ai_web_domains'] ?? ''));

        if ($domains === null) {
            return back()->withErrors([
                'ai_web_domains' => 'Некорректный домен. Укажите host без пути, например example.com, docs.example.com или *.example.com.',
            ])->withInput();
        }

        // A search mode without an address silently does nothing, so refuse it.
        $provider = (string) ($validated['ai_web_provider'] ?? 'fetch');
        $webUrl = trim((string) ($validated['ai_web_url'] ?? '')) !== ''
            ? trim((string) $validated['ai_web_url'])
            : rtrim((string) config('ai.web_url'), '/');

        if ($request->boolean('ai_web_enabled')
            && in_array($provider, ['searxng', 'both'], true)
            && $webUrl === '') {
            return back()->withErrors([
                'ai_web_url' => 'Для режима поиска укажите адрес SearXNG (например http://searxng:8080).',
            ])->withInput();
        }

        Setting::put('ai_enabled', $request->boolean('ai_enabled') ? '1' : '0');
        Setting::put('ai_url', $validated['ai_url'] ?? null);
        Setting::put('ai_model', $validated['ai_model'] ?? null);
        Setting::put('ai_timeout', isset($validated['ai_timeout']) ? (string) $validated['ai_timeout'] : null);
        Setting::put('ai_max_sources', isset($validated['ai_max_sources']) ? (string) $validated['ai_max_sources'] : null);
        Setting::put('ai_max_context_chars', isset($validated['ai_max_context_chars']) ? (string) $validated['ai_max_context_chars'] : null);
        Setting::put('ai_history_messages', isset($validated['ai_history_messages']) ? (string) $validated['ai_history_messages'] : null);
        Setting::put('ai_show_sources', $request->boolean('ai_show_sources') ? '1' : '0');
        Setting::put('ai_retention_days', isset($validated['ai_retention_days']) ? (string) $validated['ai_retention_days'] : null);

        // An empty selection means "not limited": every section for sections,
        // every role for roles. Storing null keeps env defaults working.
        Setting::put('ai_sections', $this->encodeList($validated['ai_sections'] ?? []));
        Setting::put('ai_roles', $this->encodeList($validated['ai_roles'] ?? []));

        Setting::put('ai_web_enabled', $request->boolean('ai_web_enabled') ? '1' : '0');
        Setting::put('ai_web_provider', $validated['ai_web_provider'] ?? null);
        Setting::put('ai_web_url', $validated['ai_web_url'] ?? null);
        Setting::put('ai_web_domains', $domains === [] ? null : (string) json_encode($domains, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        Setting::put('ai_web_timeout', isset($validated['ai_web_timeout']) ? (string) $validated['ai_web_timeout'] : null);
        Setting::put('ai_web_results', isset($validated['ai_web_results']) ? (string) $validated['ai_web_results'] : null);
        Setting::put('ai_web_max_chars', isset($validated['ai_web_max_chars']) ? (string) $validated['ai_web_max_chars'] : null);

        return back()->with('success', 'Настройки ИИ-ассистента сохранены');
    }

    public function test(): RedirectResponse
    {
        $url = rtrim((string) (Setting::get('ai_url') ?: config('ai.url')), '/');
        $model = (string) (Setting::get('ai_model') ?: config('ai.model'));

        if ($url === '') {
            return back()->with('error', 'Не задан адрес Ollama.');
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get($url . '/api/tags');
        } catch (Throwable $e) {
            return back()->with('error', 'Ollama недоступен: ' . $e->getMessage());
        }

        if (! $response->successful()) {
            return back()->with('error', 'Ollama ответил HTTP ' . $response->status());
        }

        $models = collect($response->json('models') ?? [])->pluck('name')->filter()->values();
        $hasModel = $models->contains(fn ($name) => str_starts_with((string) $name, $model));

        return back()->with(
            $hasModel ? 'success' : 'error',
            $hasModel
                ? 'Подключение успешно: модель «' . $model . '» найдена.'
                : 'Ollama доступен, но модель «' . $model . '» не найдена (выполните: ollama pull ' . $model . ').',
        );
    }

    /** Probe the configured web fallback: page reading and/or SearXNG. */
    public function testWeb(WebFetch $fetch): RedirectResponse
    {
        $provider = $this->webProvider();
        $passed = [];
        $failed = [];

        if (! $this->webEnabled()) {
            return back()->with('error', 'Выход в интернет выключен: включите флажок и сохраните настройки.');
        }

        if ($provider !== 'searxng') {
            $result = $this->probePageReading($fetch);
            $result['ok'] ? $passed[] = $result['text'] : $failed[] = $result['text'];
        }

        if ($provider !== 'fetch') {
            $result = $this->probeSearxng();
            $result['ok'] ? $passed[] = $result['text'] : $failed[] = $result['text'];
        }

        if ($failed !== []) {
            return back()->with('error', implode(' ', $failed));
        }

        return back()->with('success', implode(' ', $passed));
    }

    /**
     * @return array{ok:bool, text:string}
     */
    private function probePageReading(WebFetch $fetch): array
    {
        if (! $fetch->enabled()) {
            return ['ok' => false, 'text' => 'Чтение страниц: выключено или пуст список разрешённых доменов.'];
        }

        $url = $fetch->probeUrl();

        if ($url === null) {
            return ['ok' => false, 'text' => 'Чтение страниц: не задан ни один домен.'];
        }

        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->withHeaders(['User-Agent' => 'Tasksbase-AI/1.0'])
                ->get($url);
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => 'Чтение страниц: недоступно (' . $e::class . ').'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'text' => 'Чтение страниц: сайт ответил HTTP ' . $response->status() . ' на ' . $url];
        }

        $chars = mb_strlen(trim(strip_tags((string) $response->body())));

        return ['ok' => true, 'text' => 'Чтение страниц: ' . $url . ' — HTTP ' . $response->status() . ', ~' . $chars . ' символов текста.'];
    }

    /**
     * @return array{ok:bool, text:string}
     */
    private function probeSearxng(): array
    {
        $url = rtrim((string) (Setting::get('ai_web_url') ?: config('ai.web_url')), '/');

        if ($url === '') {
            return ['ok' => false, 'text' => 'Поиск SearXNG: не задан адрес.'];
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get($url . '/search', [
                'q' => 'tasksbase',
                'format' => 'json',
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'text' => 'Поиск SearXNG: недоступен (' . $e::class . ').'];
        }

        if ($response->status() === 403) {
            return ['ok' => false, 'text' => 'Поиск SearXNG: 403 — включите формат json в settings.yml (search.formats: [html, json]).'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'text' => 'Поиск SearXNG: ответ HTTP ' . $response->status() . '.'];
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            return ['ok' => false, 'text' => 'Поиск SearXNG: ответ не JSON — проверьте search.formats в settings.yml.'];
        }

        return ['ok' => true, 'text' => 'Поиск SearXNG: доступен, ' . count($results) . ' результатов.'];
    }

    /** Fallback mechanism: fetch, searxng or both. */
    private function webProvider(): string
    {
        $provider = mb_strtolower(trim((string) (Setting::get('ai_web_provider') ?: config('ai.web_provider', 'fetch'))));

        return in_array($provider, ['fetch', 'searxng', 'both'], true) ? $provider : 'fetch';
    }

    private function webEnabled(): bool
    {
        $raw = Setting::get('ai_web_enabled');

        if ($raw === null || $raw === '') {
            return (bool) config('ai.web_enabled');
        }

        return in_array($raw, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Decoded list setting: JSON from this form, comma/space separated from env.
     *
     * @param  list<string>  $fallback
     * @return list<string>
     */
    private function listSetting(string $key, array $fallback): array
    {
        $raw = Setting::get($key);

        if ($raw === null || trim($raw) === '') {
            return array_values(array_map(static fn (mixed $value): string => (string) $value, $fallback));
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_values(array_map(static fn (mixed $value): string => (string) $value, $decoded));
        }

        return preg_split('/[\s,]+/', $raw) ?: [];
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    private function encodeList(array $values): ?string
    {
        $values = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== '',
        )));

        return $values === [] ? null : (string) json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
