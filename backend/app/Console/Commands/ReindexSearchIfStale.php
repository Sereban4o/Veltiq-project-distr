<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Rebuilds the search indexes only when the deployed code changed since the
 * last rebuild, so a fresh deploy is reindexed automatically without waiting
 * for the nightly `search:reindex` safety net.
 */
class ReindexSearchIfStale extends Command
{
    protected $signature = 'search:reindex-if-stale {--chunk=500}';

    protected $description = 'Пересобирает поисковые индексы, если код изменился с прошлой переиндексации (после деплоя)';

    private const CACHE_KEY = 'search:reindexed_release';

    public function handle(): int
    {
        $release = $this->releaseFingerprint();
        $last = Cache::get(self::CACHE_KEY);

        if ($release !== null && $last === $release) {
            $this->info("Индексы уже пересобраны для релиза [{$release}]; пропускаю.");

            return self::SUCCESS;
        }

        $this->info($release === null
            ? 'Релиз не определён; пересобираю индексы.'
            : "Новый релиз [{$release}]; пересобираю индексы.");

        $this->call('search:reindex', ['--chunk' => $this->option('chunk')]);

        Cache::forever(self::CACHE_KEY, $release ?? 'unknown');

        return self::SUCCESS;
    }

    /**
     * Prefers the explicit APP_RELEASE value; otherwise fingerprints the source
     * tree by its newest mtime. Docker COPY preserves file mtimes, so a fresh
     * `git checkout` + image rebuild changes the fingerprint.
     */
    private function releaseFingerprint(): ?string
    {
        $configured = config('app.release');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $latest = 0;
        foreach (File::allFiles(base_path('app')) as $file) {
            $latest = max($latest, $file->getMTime());
        }
        foreach (File::files(base_path('database/migrations')) as $file) {
            $latest = max($latest, $file->getMTime());
        }

        return $latest > 0 ? 'mtime:' . $latest : null;
    }
}
