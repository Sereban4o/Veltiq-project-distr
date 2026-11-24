<?php

namespace App\Extensions\Storage\Services;

use App\Models\StorageMeta;
use App\Models\User;
use App\Extensions\Storage\Services\Contracts\StorageDriver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orchestrates the configured {@see StorageDriver} and shapes its output into
 * the JSON payloads expected by the API/frontend.
 */
class FileBrowserService
{
    /** Office formats edited through the ONLYOFFICE editor. */
    private const OFFICE_EXTENSIONS = [
        'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'odt', 'ott', 'fodt', 'rtf',
        'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'xltm', 'ods', 'ots', 'fods',
        'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'pot', 'potx', 'potm', 'odp', 'otp', 'fodp',
        'epub', 'fb2', 'mht',
    ];

    private ?StorageDriver $driver = null;

    private ?bool $writable = null;

    private ?string $disk = null;

    public function __construct(
        private StorageDriverFactory $factory,
        private StorageVersionService $versions,
        private StoragePreviewService $previews,
        private StorageAuditService $audit,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->factory->isConfigured();
    }

    public function root(): string
    {
        return $this->driver()->displayRoot();
    }

    public function isWritable(): bool
    {
        return $this->writable ??= $this->driver()->isWritable();
    }

    /**
     * @return array{configured:bool,protocol:?string,address:?string,root:?string}
     */
    public function describe(): array
    {
        return $this->factory->describe();
    }

    public function normalize(string $relative): string
    {
        return StoragePath::normalize($relative);
    }

    /**
     * Resolve (and lazily create) the metadata row backing a driver entry.
     */
    public function ensureMeta(string $path, string $type = 'file'): StorageMeta
    {
        $path = $this->normalize($path);
        if ($path === '') {
            throw StorageException::notFound();
        }

        $meta = $this->metaFor($path);
        if ($meta instanceof StorageMeta) {
            return $meta;
        }

        $entry = $this->statOrNull($path);
        if ($entry === null) {
            throw StorageException::notFound();
        }

        $resolvedType = $entry['type'] === 'dir' ? 'dir' : ($type === 'dir' ? 'dir' : 'file');

        return $this->createMeta(
            $path,
            $resolvedType,
            (int) ($entry['size'] ?? 0),
            $entry['mime'] ?? null,
            $this->currentUserId(),
        );
    }

    /**
     * Existing live metadata row for a path (without creating one).
     */
    public function metaFor(string $path): ?StorageMeta
    {
        $path = $this->normalize($path);
        if ($path === '') {
            return null;
        }

        return StorageMeta::query()
            ->where('disk', $this->diskKey())
            ->where('path_hash', StoragePath::hash($path))
            ->whereNull('deleted_at')
            ->first();
    }

    public function previewable(mixed $entry): bool
    {
        return $this->previews->isPreviewable($entry);
    }

    /**
     * Serve (generating on demand) a thumbnail for a driver entry.
     *
     * @return array{status:string,path?:string,mime?:string,etag?:string}
     */
    public function preview(string $relative, string $size = 'thumb'): array
    {
        $relative = $this->normalize($relative);
        $entry = $this->ensureMeta($relative);

        if (!$this->previews->enabled() || !$this->previews->isPreviewable($entry)) {
            return ['status' => 'none'];
        }

        $info = $this->previews->serve($entry, $size);
        if ($info === null) {
            $this->previews->generate($entry);
            $entry->refresh();
            $info = $this->previews->serve($entry, $size);
        }

        if ($info === null) {
            return ['status' => (string) ($entry->preview_status ?: 'none')];
        }

        return [
            'status' => 'ready',
            'path' => $info['path'],
            'mime' => $info['mime'],
            'etag' => $info['etag'],
        ];
    }

    /**
     * List the entries of a ZIP archive.
     *
     * @return array{entries:array<int,array{name:string,size:?int,is_dir:bool}>,truncated:bool}
     */
    public function archive(string $relative): array
    {
        $relative = $this->normalize($relative);
        $this->ensureMeta($relative);

        if ((string) StoragePath::extension($relative) !== 'zip' || !class_exists(\ZipArchive::class)) {
            throw new StorageException('Просмотр этого формата архива недоступен на сервере');
        }

        $stream = $this->driver()->readStream($relative);
        $tmp = tempnam(sys_get_temp_dir(), 'arc');
        $out = $tmp !== false ? @fopen($tmp, 'wb') : false;

        if ($tmp === false || $out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new StorageException('Не удалось прочитать архив');
        }

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return $this->listArchiveEntries($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Create every missing directory segment of a connection-relative path.
     */
    public function ensureDirectories(string $relative): void
    {
        $relative = $this->normalize($relative);
        if ($relative === '') {
            return;
        }

        $current = '';
        foreach (explode('/', $relative) as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            if ($this->statOrNull($current) === null) {
                try {
                    $this->driver()->createDirectory($current);
                } catch (\Throwable) {
                    // Already created by a concurrent request.
                }
            }
        }
    }

    /**
     * Move a file or directory tree, keeping the metadata rows in sync.
     */
    public function move(string $from, string $to, bool $overwrite = false, ?User $user = null): string
    {
        $from = $this->normalize($from);
        $to = $this->normalize($to);

        if ($from === '') {
            throw new InvalidArgumentException('Укажите путь');
        }
        if ($to === '') {
            throw new InvalidArgumentException('Укажите путь');
        }
        if ($from === $to) {
            return $to;
        }

        $driver = $this->driver();
        $source = $this->statOrNull($from);
        if ($source === null) {
            throw StorageException::notFound();
        }

        $target = $this->statOrNull($to);
        if ($target !== null) {
            if (!$overwrite) {
                throw StorageConflictException::at($to);
            }

            $driver->delete($to);

            $targetMeta = $this->metaFor($to);
            if ($targetMeta instanceof StorageMeta) {
                $this->purgeMetaSubtree($targetMeta);
            }
        }

        $entry = $this->ensureMeta($from, $source['type']);

        $this->ensureDirectories(StoragePath::parent($to));
        $this->copyTree($from, $to);
        $driver->delete($from);

        $this->renameMetaSubtree($entry, $to);

        return $to;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(string $relative): array
    {
        $relative = $this->normalize($relative);
        $entries = [];

        foreach ($this->driver()->list($relative) as $item) {
            $isDir = $item['type'] === 'dir';
            $extension = $isDir ? null : (strtolower(pathinfo($item['name'], PATHINFO_EXTENSION)) ?: null);
            $created = $item['created'] ?? $item['modified'] ?? time();
            $modified = $item['modified'] ?? $created;

            $entries[] = [
                'name' => $item['name'],
                'path' => $item['path'],
                'type' => $item['type'],
                'size' => $isDir ? null : (int) ($item['size'] ?? 0),
                'created_at' => date(DATE_ATOM, (int) $created),
                'modified_at' => date(DATE_ATOM, (int) $modified),
                'extension' => $extension,
                'kind' => $this->kindLabel($isDir, $extension),
            ];
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $relative): array
    {
        $relative = $this->normalize($relative);
        $this->ensureMeta($relative);
        $entry = $this->driver()->stat($relative);

        if ($entry === null) {
            throw StorageException::notFound();
        }

        if ($entry['type'] === 'dir') {
            throw StorageException::notAFile();
        }

        $name = $entry['name'] !== '' ? $entry['name'] : StoragePath::name($relative);
        $size = (int) ($entry['size'] ?? 0);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $entry['mime'] ?? 'application/octet-stream';
        $maxEdit = (int) config('storage_browser.max_edit_size', 2 * 1024 * 1024);

        $isText = $this->looksLikeText($relative, $extension, $mime, $size, $maxEdit);
        $content = null;
        if ($isText && $size <= $maxEdit) {
            $content = $this->driver()->read($relative);
        }

        return [
            'name' => $name,
            'path' => $relative,
            'size' => $size,
            'created_at' => date(DATE_ATOM, (int) ($entry['created'] ?? $entry['modified'] ?? time())),
            'modified_at' => date(DATE_ATOM, (int) ($entry['modified'] ?? time())),
            'extension' => $extension,
            'kind' => $this->kindLabel(false, $extension),
            'mime' => $mime,
            'is_text' => $isText,
            'is_image' => str_starts_with($mime, 'image/'),
            'editor' => $this->editorMode($extension, $isText, $mime),
            'editable' => $isText && $size <= $maxEdit,
            'too_large' => $size > $maxEdit,
            'content' => $content,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function write(string $relative, string $content): array
    {
        $relative = $this->normalize($relative);

        if ($relative === '') {
            throw new InvalidArgumentException('Укажите имя файла');
        }

        StoragePath::assertSafeName(StoragePath::name($relative));

        $maxEdit = (int) config('storage_browser.max_edit_size', 2 * 1024 * 1024);
        if (strlen($content) > $maxEdit) {
            throw new InvalidArgumentException('Файл слишком большой для редактирования');
        }

        $user = $this->currentUser();
        $existing = $this->existingFileMeta($relative, $user);
        if ($existing instanceof StorageMeta) {
            $this->versions->snapshot($existing, $user, 'edit');
        }

        $this->driver()->write($relative, $content);
        $this->touchEntry($relative, 'file', strlen($content), null, $user);

        return $this->read($relative);
    }

    /**
     * Write raw (binary) contents, e.g. a document saved by ONLYOFFICE.
     */
    public function writeBinary(string $relative, string $contents): void
    {
        $relative = $this->normalize($relative);

        if ($relative === '') {
            throw new InvalidArgumentException('Укажите имя файла');
        }

        StoragePath::assertSafeName(StoragePath::name($relative));

        $max = (int) config('onlyoffice.max_write_size', 50 * 1024 * 1024);
        if (strlen($contents) > $max) {
            throw new InvalidArgumentException('Файл слишком большой');
        }

        $user = $this->currentUser();
        $existing = $this->existingFileMeta($relative, $user);
        if ($existing instanceof StorageMeta) {
            $this->versions->snapshot($existing, $user, 'office');
        }

        $this->driver()->write($relative, $contents);
        $this->touchEntry($relative, 'file', strlen($contents), null, $user);
    }

    public function makeDirectory(string $relative): void
    {
        $relative = $this->normalize($relative);

        if ($relative === '') {
            throw new InvalidArgumentException('Укажите имя папки');
        }

        StoragePath::assertSafeName(StoragePath::name($relative));

        $this->driver()->createDirectory($relative);
        $this->touchEntry($relative, 'dir', 0, null, $this->currentUser());
    }

    public function delete(string $relative): void
    {
        $relative = $this->normalize($relative);

        if ($relative === '') {
            throw new InvalidArgumentException('Нельзя удалить корневой каталог');
        }

        $this->driver()->delete($relative);
    }

    public function downloadResponse(string $relative): StreamedResponse
    {
        $relative = $this->normalize($relative);
        $entry = $this->driver()->stat($relative);

        if ($entry === null) {
            throw StorageException::notFound();
        }

        if ($entry['type'] === 'dir') {
            throw StorageException::notAFile();
        }

        $stream = $this->driver()->readStream($relative);
        $name = $entry['name'] !== '' ? $entry['name'] : StoragePath::name($relative);
        $size = $entry['size'];

        $meta = $this->metaFor($relative) ?? $this->createMeta($relative, 'file', (int) ($size ?? 0), $entry['mime'] ?? null, $this->currentUserId());
        $this->audit->record('file.download', entry: $meta, size: (int) ($size ?? 0));

        $headers = [];
        if ($size !== null) {
            $headers['Content-Length'] = (string) $size;
        }

        return response()->streamDownload($this->streamer($stream), $name, $headers);
    }

    public function rawResponse(string $relative): StreamedResponse
    {
        $relative = $this->normalize($relative);
        $entry = $this->driver()->stat($relative);

        if ($entry === null) {
            throw StorageException::notFound();
        }

        if ($entry['type'] === 'dir') {
            throw StorageException::notAFile();
        }

        $stream = $this->driver()->readStream($relative);
        $name = $entry['name'] !== '' ? $entry['name'] : StoragePath::name($relative);

        $headers = [
            'Content-Type' => $entry['mime'] ?? 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($name) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($entry['size'] !== null) {
            $headers['Content-Length'] = (string) $entry['size'];
        }

        return response()->stream($this->streamer($stream), 200, $headers);
    }

    private function streamer($stream): callable
    {
        return function () use ($stream) {
            if (!is_resource($stream)) {
                return;
            }

            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($stream);
            }
        };
    }

    private function looksLikeText(string $path, string $extension, string $mime, int $size, int $maxEdit): bool
    {
        if ($size === 0) {
            return true;
        }

        if (in_array($extension, (array) config('storage_browser.text_extensions', []), true)) {
            return true;
        }

        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        if (in_array($mime, ['application/json', 'application/xml', 'application/javascript'], true)) {
            return true;
        }

        if ($size <= $maxEdit) {
            $chunk = $this->driver()->readHead($path, 4096);

            if ($chunk === null) {
                return false;
            }

            return $chunk !== '' && !str_contains($chunk, "\0");
        }

        return false;
    }

    private function driver(): StorageDriver
    {
        return $this->driver ??= $this->factory->make();
    }

    /**
     * Some drivers throw for missing paths instead of returning null.
     *
     * @return array<string, mixed>|null
     */
    private function statOrNull(string $path): ?array
    {
        try {
            return $this->driver()->stat($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Display identity of the configured connection, used as the `disk` key of
     * the metadata overlay. Shared with the version/trash/preview scopes so all
     * of them address the same rows.
     */
    private function diskKey(): string
    {
        return $this->disk ??= $this->factory->scopeKey();
    }

    private function currentUser(): ?User
    {
        $user = request()->user();

        return $user instanceof User ? $user : null;
    }

    private function currentUserId(): ?int
    {
        return $this->currentUser()?->getKey();
    }

    /**
     * Create the metadata row for a path, materialising ancestor directories.
     */
    private function createMeta(string $path, string $type, int $size, ?string $mime, ?int $userId): StorageMeta
    {
        $path = $this->normalize($path);

        $segments = explode('/', $path);
        array_pop($segments);

        $current = '';
        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            if ($this->metaFor($current) !== null) {
                continue;
            }

            StorageMeta::query()->create([
                'disk' => $this->diskKey(),
                'path' => $current,
                'path_hash' => StoragePath::hash($current),
                'parent_path' => StoragePath::parent($current),
                'type' => 'dir',
                'size' => 0,
                'mime' => null,
                'owner_id' => $userId,
                'created_by' => $userId,
            ]);
        }

        return StorageMeta::query()->create([
            'disk' => $this->diskKey(),
            'path' => $path,
            'path_hash' => StoragePath::hash($path),
            'parent_path' => StoragePath::parent($path),
            'type' => $type === 'dir' ? 'dir' : 'file',
            'size' => $type === 'dir' ? 0 : $size,
            'mime' => $type === 'dir' ? null : $mime,
            'owner_id' => $userId,
            'created_by' => $userId,
        ]);
    }

    /**
     * Return the metadata row for an existing driver file, creating it when it
     * is not tracked yet. Directories and missing paths yield null.
     */
    private function existingFileMeta(string $path, ?User $user): ?StorageMeta
    {
        $meta = $this->metaFor($path);
        if ($meta instanceof StorageMeta) {
            return $meta->type === 'file' ? $meta : null;
        }

        $entry = $this->statOrNull($path);
        if ($entry === null || $entry['type'] !== 'file') {
            return null;
        }

        return $this->createMeta($path, 'file', (int) ($entry['size'] ?? 0), $entry['mime'] ?? null, $user?->getKey());
    }

    private function touchEntry(string $path, string $type, int $size, ?string $mime, ?User $user): StorageMeta
    {
        $meta = $this->metaFor($path);

        if ($meta === null) {
            return $this->createMeta($path, $type, $size, $mime, $user?->getKey());
        }

        $attributes = ['size' => $size, 'type' => $type === 'dir' ? 'dir' : 'file'];
        if ($mime !== null) {
            $attributes['mime'] = $mime;
        }
        if ($meta->owner_id === null && $user !== null) {
            $attributes['owner_id'] = $user->getKey();
        }

        $meta->forceFill($attributes)->save();

        return $meta;
    }

    /** Copy a driver entry (recursively) without relying on a streamed write. */
    private function copyTree(string $from, string $to): void
    {
        $driver = $this->driver();
        $entry = $driver->stat($from);

        if ($entry === null) {
            throw StorageException::notFound();
        }

        if ($entry['type'] === 'dir') {
            $driver->createDirectory($to);

            foreach ($driver->list($from) as $child) {
                $this->copyTree($child['path'], StoragePath::join($to, $child['name']));
            }

            return;
        }

        $stream = $driver->readStream($from);

        try {
            $contents = stream_get_contents($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $driver->write($to, $contents === false ? '' : $contents);
    }

    private function renameMetaSubtree(StorageMeta $entry, string $newPath): void
    {
        $oldPath = $entry->path;
        $oldPrefix = StoragePath::escapeLike($oldPath);
        $disk = $entry->disk;

        DB::transaction(function () use ($entry, $oldPath, $newPath, $oldPrefix, $disk) {
            $entry->forceFill([
                'path' => $newPath,
                'path_hash' => StoragePath::hash($newPath),
                'parent_path' => StoragePath::parent($newPath),
            ])->save();

            StorageMeta::query()
                ->where('disk', $disk)
                ->where('id', '!=', $entry->id)
                ->where(function ($query) use ($oldPath, $oldPrefix) {
                    $query->where('path', $oldPath)
                        ->orWhereRaw("path LIKE ? ESCAPE '!'", [$oldPrefix . '/%']);
                })
                ->get()
                ->each(function (StorageMeta $child) use ($oldPath, $newPath) {
                    $tail = substr($child->path, strlen($oldPath));
                    $moved = $newPath . $tail;

                    $child->forceFill([
                        'path' => $moved,
                        'path_hash' => StoragePath::hash($moved),
                        'parent_path' => StoragePath::parent($moved),
                    ])->save();
                });
        });
    }

    private function purgeMetaSubtree(StorageMeta $entry): void
    {
        $prefix = StoragePath::escapeLike($entry->path);

        StorageMeta::query()
            ->where('disk', $entry->disk)
            ->where(function ($query) use ($entry, $prefix) {
                $query->where('path', $entry->path)
                    ->orWhereRaw("path LIKE ? ESCAPE '!'", [$prefix . '/%']);
            })
            ->delete();
    }

    /** @return array{entries:array<int,array{name:string,size:?int,is_dir:bool}>,truncated:bool} */
    private function listArchiveEntries(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new StorageException('Не удалось открыть ZIP-архив');
        }

        $max = (int) config('storage_browser.preview_max_entries', 2000);
        $count = $zip->numFiles;
        $entries = [];

        for ($i = 0; $i < min($count, $max); $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];
            $entries[] = [
                'name' => $name,
                'size' => (int) $stat['size'],
                'is_dir' => str_ends_with($name, '/'),
            ];
        }

        $zip->close();

        return ['entries' => $entries, 'truncated' => $count > $max];
    }

    private function kindLabel(bool $isDir, ?string $extension): string
    {
        if ($isDir) {
            return 'Папка с файлами';
        }

        $ext = strtolower((string) $extension);

        return match ($ext) {
            'txt', 'log' => 'Текстовый документ',
            'md', 'markdown' => 'Документ Markdown',
            'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm' => 'Документ Word',
            'rtf' => 'Документ RTF',
            'odt', 'ott', 'fodt' => 'Документ OpenDocument',
            'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'xltm' => 'Таблица Excel',
            'ods', 'ots', 'fods' => 'Таблица OpenDocument',
            'csv', 'tsv' => 'Таблица (текст)',
            'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm', 'pot', 'potx', 'potm' => 'Презентация PowerPoint',
            'odp', 'otp', 'fodp' => 'Презентация OpenDocument',
            'epub', 'fb2' => 'Электронная книга',
            'pdf' => 'PDF-документ',
            'png', 'jpg', 'jpeg', 'gif', 'bmp', 'webp', 'svg' => 'Изображение',
            'zip', 'rar', '7z', 'tar', 'gz' => 'Архив',
            'php', 'js', 'ts', 'css', 'html', 'htm', 'mht', 'json', 'xml' => 'Веб-документ',
            '' => 'Файл',
            default => strtoupper($ext) . '-файл',
        };
    }

    private function editorMode(string $extension, bool $isText, string $mime): string
    {
        $ext = strtolower($extension);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, ['html', 'htm'], true)) {
            return $isText ? 'richtext' : 'office';
        }
        if (in_array($ext, ['csv', 'tsv'], true)) {
            return $isText ? 'grid' : 'office';
        }
        if (in_array($ext, self::OFFICE_EXTENSIONS, true)) {
            return 'office';
        }
        if ($isText) {
            return 'text';
        }

        return 'binary';
    }
}
