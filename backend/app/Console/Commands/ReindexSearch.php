<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Issue;
use App\Models\MailMessage;
use App\Models\Page;
use Illuminate\Console\Command;

class ReindexSearch extends Command
{
    protected $signature = 'search:reindex {--chunk=500}';

    protected $description = 'Перестраивает поисковые индексы (Meilisearch) и blind-индекс чата';

    public function handle(): int
    {
        $chunk = max((int) $this->option('chunk'), 50);

        $this->call('scout:sync-index-settings');

        foreach ([Issue::class, Page::class, File::class, MailMessage::class] as $model) {
            $this->line("Индексируем {$model}…");
            $this->call('scout:import', ['model' => $model]);
        }

        $this->call('chat:reindex-search', ['--chunk' => $chunk]);

        $this->info('Поисковые индексы перестроены.');

        return self::SUCCESS;
    }
}
