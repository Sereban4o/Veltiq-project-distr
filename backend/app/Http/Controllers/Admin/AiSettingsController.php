<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

/**
 * Admin settings for the AI assistant (local Ollama). Values are stored in the
 * `settings` table and take precedence over the env defaults in config/ai.php.
 */
class AiSettingsController extends Controller
{
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
                'ai_retention_days' => Setting::get('ai_retention_days', (string) config('ai.retention_days')),
                'ai_web_enabled' => Setting::get('ai_web_enabled', config('ai.web_enabled') ? '1' : '0'),
                'ai_web_url' => Setting::get('ai_web_url', (string) config('ai.web_url')),
                'ai_web_timeout' => Setting::get('ai_web_timeout', (string) config('ai.web_timeout')),
                'ai_web_results' => Setting::get('ai_web_results', (string) config('ai.web_results')),
            ],
            'envUrl' => (string) config('ai.url'),
            'envModel' => (string) config('ai.model'),
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
            'ai_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'ai_web_enabled' => ['nullable', 'boolean'],
            // `[^\s@]+` also rejects userinfo (user:pass@host) in the URL.
            'ai_web_url' => ['nullable', 'string', 'max:255', 'regex:/^https?:\/\/[^\s@]+$/'],
            'ai_web_timeout' => ['nullable', 'integer', 'min:1', 'max:30'],
            'ai_web_results' => ['nullable', 'integer', 'min:1', 'max:5'],
        ], [
            'ai_web_url.regex' => 'Адрес SearXNG: только http/https, без учётных данных в URL.',
        ]);

        Setting::put('ai_enabled', $request->boolean('ai_enabled') ? '1' : '0');
        Setting::put('ai_url', $validated['ai_url'] ?? null);
        Setting::put('ai_model', $validated['ai_model'] ?? null);
        Setting::put('ai_timeout', isset($validated['ai_timeout']) ? (string) $validated['ai_timeout'] : null);
        Setting::put('ai_max_sources', isset($validated['ai_max_sources']) ? (string) $validated['ai_max_sources'] : null);
        Setting::put('ai_max_context_chars', isset($validated['ai_max_context_chars']) ? (string) $validated['ai_max_context_chars'] : null);
        Setting::put('ai_history_messages', isset($validated['ai_history_messages']) ? (string) $validated['ai_history_messages'] : null);
        Setting::put('ai_retention_days', isset($validated['ai_retention_days']) ? (string) $validated['ai_retention_days'] : null);
        Setting::put('ai_web_enabled', $request->boolean('ai_web_enabled') ? '1' : '0');
        Setting::put('ai_web_url', $validated['ai_web_url'] ?? null);
        Setting::put('ai_web_timeout', isset($validated['ai_web_timeout']) ? (string) $validated['ai_web_timeout'] : null);
        Setting::put('ai_web_results', isset($validated['ai_web_results']) ? (string) $validated['ai_web_results'] : null);

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

    /** Probe the SearXNG instance used for the web fallback. */
    public function testWeb(): RedirectResponse
    {
        $url = rtrim((string) (Setting::get('ai_web_url') ?: config('ai.web_url')), '/');

        if ($url === '') {
            return back()->with('error', 'Не задан адрес SearXNG.');
        }

        try {
            $response = Http::timeout(5)->acceptJson()->get($url . '/search', [
                'q' => 'tasksbase',
                'format' => 'json',
            ]);
        } catch (Throwable $e) {
            return back()->with('error', 'SearXNG недоступен: ' . $e::class);
        }

        if ($response->status() === 403) {
            return back()->with('error', 'SearXNG отвечает 403: включите формат json в settings.yml (search.formats: [html, json]).');
        }

        if (! $response->successful()) {
            return back()->with('error', 'SearXNG ответил HTTP ' . $response->status());
        }

        $results = $response->json('results');

        if (! is_array($results)) {
            return back()->with('error', 'SearXNG вернул не JSON: проверьте search.formats в settings.yml.');
        }

        return back()->with('success', 'SearXNG доступен: ' . count($results) . ' результатов.');
    }
}
