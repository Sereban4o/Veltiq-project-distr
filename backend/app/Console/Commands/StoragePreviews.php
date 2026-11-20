<?php

namespace App\Console\Commands;

use App\Models\StorageMeta;
use App\Services\Storage\StoragePreviewService;
use Illuminate\Console\Command;

class StoragePreviews extends Command
{
    protected $signature = 'storage:previews {--missing : Только записи без готового превью} {--limit=200}';

    protected $description = 'Формирует превью для файлов хранилища (изображения, PDF, видео)';

    public function handle(StoragePreviewService $previews): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $missing = (bool) $this->option('missing');

        $query = StorageMeta::query()
            ->where('type', 'file')
            ->when($missing, fn ($q) => $q->where(fn ($inner) => $inner
                ->whereNull('preview_status')
                ->orWhere('preview_status', 'pending')))
            ->orderBy('id')
            ->limit($limit);

        $processed = 0;

        foreach ($query->get() as $entry) {
            if (!$previews->isPreviewable($entry)) {
                continue;
            }

            $previews->generate($entry);
            $processed++;
        }

        $this->info("Обработано записей: {$processed}");

        return self::SUCCESS;
    }
}
