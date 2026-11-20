<?php

namespace App\Services\Builds;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Triggers and inspects preset (branded) client builds through GitHub Actions.
 *
 * Building Android/Windows artifacts requires toolchains that are not present
 * on the application server, so the admin page dispatches a dedicated workflow
 * and shows its recent runs.
 */
class ClientBuildService
{
    private const API = 'https://api.github.com';

    public function repo(): string
    {
        return (string) Setting::get('client_build_repo', (string) config('client_builds.repo', ''));
    }

    public function workflow(): string
    {
        return (string) Setting::get('client_build_workflow', (string) config('client_builds.workflow', 'build-clients.yml'));
    }

    public function ref(): string
    {
        return (string) Setting::get('client_build_ref', (string) config('client_builds.ref', 'main'));
    }

    public function token(): string
    {
        return (string) (Setting::getEncrypted('client_build_token') ?? config('client_builds.token', ''));
    }

    public function configured(): bool
    {
        return $this->repo() !== '' && $this->token() !== '';
    }

    /**
     * @param  array<string, string>  $inputs
     */
    public function dispatch(array $inputs): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('Не настроены репозиторий и токен GitHub');
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->post($this->url('/actions/workflows/' . rawurlencode($this->workflow()) . '/dispatches'), [
                'ref' => $this->ref(),
                'inputs' => $inputs,
            ]);

        if ($response->status() !== 204) {
            $message = (string) ($response->json('message') ?? '');

            throw new RuntimeException('GitHub API: HTTP ' . $response->status() . ($message !== '' ? ' — ' . $message : ''));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function runs(int $limit = 10): array
    {
        if (! $this->configured()) {
            return [];
        }

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->get($this->url('/actions/workflows/' . rawurlencode($this->workflow()) . '/runs'), [
                'per_page' => max(1, min($limit, 50)),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('GitHub API: HTTP ' . $response->status());
        }

        $runs = $response->json('workflow_runs') ?? [];

        return array_map(fn (array $run) => [
            'id' => (int) ($run['id'] ?? 0),
            'status' => (string) ($run['status'] ?? ''),
            'conclusion' => $run['conclusion'] ?? null,
            'created_at' => $run['created_at'] ?? null,
            'url' => (string) ($run['html_url'] ?? ''),
            'title' => (string) ($run['display_title'] ?? ''),
        ], is_array($runs) ? $runs : []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveSettings(array $data): void
    {
        Setting::put('client_build_repo', $data['repo'] ?? null);
        Setting::put('client_build_workflow', $data['workflow'] ?? null);
        Setting::put('client_build_ref', $data['ref'] ?? null);

        if (! empty($data['token'])) {
            Setting::putEncrypted('client_build_token', (string) $data['token']);
        }

        if (array_key_exists('clear_token', $data) && $data['clear_token']) {
            Setting::putEncrypted('client_build_token', null);
        }
    }

    private function url(string $path): string
    {
        return self::API . '/repos/' . $this->repo() . $path;
    }
}
