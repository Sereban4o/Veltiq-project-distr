<?php

namespace App\Services\Storage;

use App\Models\StorageMeta;
use App\Models\StorageUpload;
use App\Models\StorageUploadChunk;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Direct and resumable (chunked) uploads into the configured storage.
 *
 * Both paths funnel into {@see writeEntry()} so versioning, quota checks,
 * preview generation and auditing behave identically.
 */
class StorageUploadService
{
    /**
     * Script/executable extensions that are never accepted (config can narrow
     * or extend the list through storage_browser.blocked_extensions).
     */
    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'exe', 'com', 'bat', 'cmd', 'csh', 'sh', 'bash', 'msi', 'dll', 'scr',
        'jar', 'app', 'vb', 'vbs', 'vbscript', 'ps1', 'psm1', 'htaccess',
    ];

    public function __construct(
        private StorageScope $scope,
        private StorageMetadataService $metadata,
        private StorageAuditService $audit,
        private StorageDriverFactory $factory,
        private StorageQuotaService $quota,
        private StorageVersionService $versions,
        private StoragePreviewService $previews,
        private FileBrowserService $files,
    ) {
    }

    /**
     * @return array{entry: StorageMeta, path: string}
     */
    public function upload(UploadedFile $file, string $folder, ?string $relativePath, ?User $user, bool $overwrite = false): array
    {
        $name = $relativePath !== null && trim($relativePath) !== ''
            ? $relativePath
            : $file->getClientOriginalName();

        $finalPath = $this->resolveTarget($folder, $name);
        $this->validatePath($finalPath);

        $size = (int) $file->getSize();
        $mime = $file->getClientMimeType() ?: null;

        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new StorageException('Не удалось прочитать загружаемый файл');
        }

        try {
            $entry = $this->writeEntry($finalPath, $handle, $size, $mime, $user, $overwrite, 'upload');
        } finally {
            fclose($handle);
        }

        return ['entry' => $entry, 'path' => $entry->path];
    }

    /**
     * @return array{upload_id:string,chunk_size:int,total_chunks:int,received:array<int,int>}
     */
    public function createSession(User $user, string $folder, string $name, int $size, ?string $checksum = null): array
    {
        $finalPath = $this->resolveTarget($folder, $name);
        $this->validatePath($finalPath);

        $max = (int) config('storage_browser.max_upload_size', 512 * 1024 * 1024);
        if ($size <= 0 || $size > $max) {
            throw new StorageException('Файл слишком большой для загрузки');
        }

        $existing = $this->metadata->find($finalPath);
        $this->quota->assertCanWrite($user, $size, (int) ($existing?->size ?? 0));

        $chunkSize = max(1024 * 1024, min(16 * 1024 * 1024, (int) config('storage_browser.chunk_size', 5 * 1024 * 1024)));

        $upload = StorageUpload::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'disk' => $this->scope->disk(),
            'target_path' => $finalPath,
            'file_name' => StoragePath::name($finalPath),
            'total_size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil($size / $chunkSize),
            'checksum' => $checksum,
            'status' => 'open',
            'expires_at' => now()->addDay(),
        ]);

        $this->scope->uploadsDir($upload->id);

        return $this->sessionPayload($upload);
    }

    public function putChunk(User $user, string $uploadId, int $index, string $contents, ?string $checksum = null): void
    {
        $upload = $this->session($user, $uploadId);

        if ($index < 0 || $index >= $upload->total_chunks) {
            throw new StorageException('Недопустимый номер части файла');
        }

        if ($upload->status !== 'open') {
            throw new StorageException('Загрузка уже завершена или отменена');
        }

        $expected = $index === $upload->total_chunks - 1
            ? $upload->total_size - $upload->chunk_size * ($upload->total_chunks - 1)
            : $upload->chunk_size;

        if (strlen($contents) > $expected + 1024) {
            throw new StorageException('Размер части файла превышает ожидаемый');
        }

        $actual = hash('sha256', $contents);
        if ($checksum !== null && $checksum !== '' && !hash_equals(strtolower($checksum), $actual)) {
            throw new StorageException('Контрольная сумма части файла не совпадает');
        }

        $path = $this->scope->uploadsDir($upload->id) . '/' . $index;
        if (@file_put_contents($path, $contents) === false) {
            throw new StorageException('Не удалось сохранить часть файла');
        }

        StorageUploadChunk::query()->updateOrCreate(
            ['upload_id' => $upload->id, 'chunk_index' => $index],
            ['size' => strlen($contents), 'checksum' => $actual, 'created_at' => now()],
        );
    }

    /** @return array{upload_id:string,chunk_size:int,total_chunks:int,received:array<int,int>} */
    public function status(User $user, string $uploadId): array
    {
        return $this->sessionPayload($this->session($user, $uploadId));
    }

    /**
     * @return array{entry: StorageMeta, path: string}
     */
    public function complete(User $user, string $uploadId, ?string $checksum = null): array
    {
        $upload = $this->session($user, $uploadId);

        if ($upload->status === 'done') {
            $entry = $this->metadata->find($upload->target_path);
            if ($entry !== null) {
                return ['entry' => $entry, 'path' => $entry->path];
            }
        }

        $received = StorageUploadChunk::query()->where('upload_id', $upload->id)->pluck('chunk_index')->all();
        $missing = array_values(array_diff(range(0, $upload->total_chunks - 1), $received));

        if ($missing !== []) {
            throw new StorageException('Не загружены части файла: ' . implode(', ', array_slice($missing, 0, 10)));
        }

        $upload->forceFill(['status' => 'assembling'])->save();

        $assembled = $this->scope->uploadsDir($upload->id) . '/assembled';
        $out = @fopen($assembled, 'wb');
        if ($out === false) {
            throw new StorageException('Не удалось собрать файл');
        }

        $hash = hash_init('sha256');

        try {
            for ($i = 0; $i < $upload->total_chunks; $i++) {
                $chunkPath = $this->scope->uploadsDir($upload->id) . '/' . $i;
                $in = @fopen($chunkPath, 'rb');
                if ($in === false) {
                    throw new StorageException('Часть файла не найдена');
                }

                try {
                    while (!feof($in)) {
                        $buffer = fread($in, 262144);
                        if ($buffer === false || $buffer === '') {
                            break;
                        }
                        hash_update($hash, $buffer);
                        fwrite($out, $buffer);
                    }
                } finally {
                    fclose($in);
                }
            }
        } finally {
            fclose($out);
        }

        $actual = hash_final($hash);
        $expected = $checksum ?? $upload->checksum;

        if ($expected !== null && $expected !== '' && !hash_equals(strtolower($expected), $actual)) {
            @unlink($assembled);
            $upload->forceFill(['status' => 'failed'])->save();

            throw new StorageException('Контрольная сумма файла не совпадает');
        }

        clearstatcache(true, $assembled);
        $size = (int) filesize($assembled);

        if ($size !== (int) $upload->total_size) {
            @unlink($assembled);
            $upload->forceFill(['status' => 'failed'])->save();

            throw new StorageException('Размер собранного файла не совпадает');
        }

        // Authoritative quota re-check: parallel sessions share the same quota.
        $existing = $this->metadata->find($upload->target_path);
        $this->quota->assertCanWrite($user, $size, (int) ($existing?->size ?? 0));

        $handle = fopen($assembled, 'rb');
        if ($handle === false) {
            throw new StorageException('Не удалось прочитать собранный файл');
        }

        try {
            $entry = $this->writeEntry($upload->target_path, $handle, $size, null, $user, true, 'upload');
        } finally {
            fclose($handle);
        }

        $upload->forceFill(['status' => 'done'])->save();
        $this->cleanup($upload->id);

        $this->audit->record('file.upload.chunked', entry: $entry, payload: ['upload_id' => $upload->id], size: $size);

        return ['entry' => $entry, 'path' => $entry->path];
    }

    public function cancel(User $user, string $uploadId): void
    {
        $upload = $this->session($user, $uploadId);

        $upload->forceFill(['status' => 'failed'])->save();
        $upload->delete();

        $this->cleanup($uploadId);
    }

    /** Remove expired sessions and their chunk files. */
    public function prune(): int
    {
        $count = 0;

        foreach (StorageUpload::query()->where('expires_at', '<', now())->get() as $upload) {
            $this->cleanup($upload->id);
            $upload->delete();
            $count++;
        }

        return $count;
    }

    private function writeEntry(
        string $finalPath,
        $stream,
        int $size,
        ?string $mime,
        ?User $user,
        bool $overwrite,
        string $source,
    ): StorageMeta {
        $driverEntry = $this->driverEntry($finalPath);
        $driverExists = $driverEntry !== null;
        $existing = $this->metadata->find($finalPath);

        if ($driverExists && !$overwrite) {
            throw StorageConflictException::at($finalPath);
        }

        if ($driverExists) {
            // The driver is the source of truth: an untracked file still has
            // to be snapshotted before its bytes are replaced.
            $existing ??= $this->metadata->ensureEntry(
                $finalPath,
                'file',
                (int) ($driverEntry['size'] ?? 0),
                $driverEntry['mime'] ?? null,
                $user?->getKey(),
            );

            $this->versions->snapshot($existing, $user, $source);
        }

        $this->quota->assertCanWrite($user, $size, (int) ($existing?->size ?? 0));

        $this->files->ensureDirectories(StoragePath::parent($finalPath));

        $this->factory->make()->writeStream($finalPath, $stream);

        $entry = $this->metadata->ensureEntry($finalPath, 'file', $size, $mime, $user?->getKey());
        $this->metadata->updateAfterWrite($entry, $size, $mime ?? $entry->mime, $user?->getKey());

        $this->previews->dispatch($entry);
        $this->audit->record('file.upload', entry: $entry, size: $size, payload: ['source' => $source]);
        $this->quota->forget($user);

        return $entry->refresh();
    }

    private function resolveTarget(string $folder, string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = trim($name, '/');

        return StoragePath::join($folder, $name);
    }

    private function validatePath(string $path): void
    {
        $path = StoragePath::normalize($path);

        if ($path === '') {
            throw new \InvalidArgumentException('Укажите имя файла');
        }

        $max = (int) config('storage_browser.max_path_length', 512);
        if (strlen($path) > $max) {
            throw new \InvalidArgumentException('Путь слишком длинный');
        }

        foreach (explode('/', $path) as $segment) {
            StoragePath::assertSafeName($segment);
        }

        $extension = StoragePath::extension($path);
        if ($extension !== null && in_array($extension, (array) config('storage_browser.blocked_extensions', self::BLOCKED_EXTENSIONS), true)) {
            throw new \InvalidArgumentException('Этот тип файла запрещён к загрузке');
        }
    }

    private function driverEntry(string $path): ?array
    {
        try {
            return $this->factory->make()->stat($path);
        } catch (\Throwable) {
            return null;
        }
    }

    private function session(User $user, string $uploadId): StorageUpload
    {
        $upload = StorageUpload::query()->whereKey($uploadId)->first();

        if ($upload === null || (int) $upload->user_id !== (int) $user->getKey()) {
            throw StorageNotFoundException::make();
        }

        if ($upload->expires_at !== null && $upload->expires_at->isPast()) {
            throw new StorageException('Сессия загрузки истекла');
        }

        return $upload;
    }

    /** @return array{upload_id:string,chunk_size:int,total_chunks:int,received:array<int,int>} */
    private function sessionPayload(StorageUpload $upload): array
    {
        return [
            'upload_id' => $upload->id,
            'chunk_size' => (int) $upload->chunk_size,
            'total_chunks' => (int) $upload->total_chunks,
            'received' => StorageUploadChunk::query()
                ->where('upload_id', $upload->id)
                ->orderBy('chunk_index')
                ->pluck('chunk_index')
                ->map(fn ($value) => (int) $value)
                ->all(),
        ];
    }

    private function cleanup(string $uploadId): void
    {
        $dir = $this->scope->root() . '/uploads/' . $uploadId;
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removePath($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function removePath(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removePath($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
