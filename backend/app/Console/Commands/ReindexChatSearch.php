<?php

namespace App\Console\Commands;

use App\Models\Message;
use App\Models\MessageSearchTerm;
use App\Support\ChatSearchIndexer;
use Illuminate\Console\Command;

class ReindexChatSearch extends Command
{
    protected $signature = 'chat:reindex-search {--chunk=500}';

    protected $description = 'Перестраивает индекс поиска сообщений (blind index)';

    public function handle(): int
    {
        MessageSearchTerm::query()->delete();

        $chunk = max((int) $this->option('chunk'), 50);
        $indexed = 0;

        Message::withTrashed()
            ->orderBy('id')
            ->chunkById($chunk, function ($messages) use (&$indexed) {
                foreach ($messages as $message) {
                    ChatSearchIndexer::reindex($message);
                    $indexed++;
                }
            });

        $this->info("Проиндексировано сообщений: {$indexed}");

        return self::SUCCESS;
    }
}
