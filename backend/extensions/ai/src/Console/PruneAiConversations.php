<?php

namespace App\Extensions\Ai\Console;

use App\Extensions\Ai\Models\AiConversation;
use App\Extensions\Ai\Services\AiConversationService;
use App\Models\Setting;
use Illuminate\Console\Command;

class PruneAiConversations extends Command
{
    protected $signature = 'ai:prune-conversations
        {--days= : Override retention days}
        {--dry-run : Show what would be deleted without touching data}';

    protected $description = 'Удаляет диалоги ИИ-ассистента старше срока хранения';

    public function handle(AiConversationService $conversations): int
    {
        $days = (int) ($this->option('days') ?: (Setting::get('ai_retention_days') ?? config('ai.retention_days', 180)));
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = AiConversation::query()->where('last_activity_at', '<', $cutoff)->count();
            $this->table(['Would delete', 'Inactive since'], [[$count, $cutoff->toDateTimeString()]]);
            $this->info('Dry run complete (no changes applied).');

            return self::SUCCESS;
        }

        $deleted = $conversations->prune($cutoff);
        $this->table(['Deleted', 'Inactive since'], [[$deleted, $cutoff->toDateTimeString()]]);
        $this->info('AI conversation pruning complete.');

        return self::SUCCESS;
    }
}
