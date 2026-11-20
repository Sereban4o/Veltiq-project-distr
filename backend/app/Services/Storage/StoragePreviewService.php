<?php

namespace App\Services\Storage;

use App\Jobs\GenerateStoragePreview;
use App\Models\StorageMeta;
use Throwable;

/**
 * Generates and serves thumbnails for images, PDFs and videos.
 *
 * Previews are best-effort: external tools (pdftoppm/ffmpeg) are used when
 * available, otherwise the entry is marked as failed/none and the frontend
 * falls back to a kind icon or native rendering.
 */
class StoragePreviewService
{
    private const VIDEO_EXTENSIONS = [
        'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'wmv', 'flv', 'mpg', 'mpeg', '3gp', 'ogv',
    ];

    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar', 'tar', 'gz', 'tgz'];

    private ?bool $pdfTool = null;

    private ?bool $ffmpegTool = null;

    public function __construct(
        private StorageScope $scope,
        private StorageMetadataService $metadata,
        private StorageDriverFactory $factory,
        private StorageAuditService $audit,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('storage_browser.previews_enabled', true);
    }

    public function isPreviewable(mixed $entry): bool
    {
        $mime = '';
        $extension = '';

        if ($entry instanceof StorageMeta) {
            $mime = (string) $entry->mime;
            $extension = (string) StoragePath::extension($entry->path);
        } else {
            $extension = strtolower((string) $entry);
        }

        if (str_starts_with($mime, 'image/') && $extension !== 'svg') {
            return true;
        }

        return $extension === 'pdf' || in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    public function isArchive(string $path): bool
    {
        return in_array((string) StoragePath::extension($path), self::ARCHIVE_EXTENSIONS, true);
    }

    public function dispatch(StorageMeta $entry): void
    {
        if (!$this->enabled() || !$this->isPreviewable($entry)) {
            return;
        }

        $entry->forceFill(['preview_status' => 'pending', 'preview_at' => null])->save();

        GenerateStoragePreview::dispatch($entry->getKey());
    }

    /** Run the generation synchronously (used by the job and backfill command). */
    public function generate(StorageMeta $entry): void
    {
        if (!$this->enabled() || !$this->isPreviewable($entry)) {
            return;
        }

        $tmp = null;

        try {
            $tmp = $this->downloadToTemp($entry);
            if ($tmp === null) {
                $this->markFailed($entry, 'Не удалось прочитать файл');

                return;
            }

            $extension = (string) StoragePath::extension($entry->path);
            $mime = (string) $entry->mime;

            if (str_starts_with($mime, 'image/') && $extension !== 'svg') {
                $this->makeImageThumbs($tmp, $entry);
            } elseif ($extension === 'pdf') {
                $this->makePdfThumbs($tmp, $entry);
            } elseif (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                $this->makeVideoThumb($tmp, $entry);
            } else {
                $entry->forceFill(['preview_status' => 'none', 'preview_at' => now()])->save();

                return;
            }

            $entry->forceFill(['preview_status' => 'ready', 'preview_at' => now()])->save();
        } catch (Throwable $e) {
            $this->markFailed($entry, $e->getMessage());
        } finally {
            if ($tmp !== null && is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return array{path:string,mime:string,etag:string}|null
     */
    public function serve(StorageMeta $entry, string $size): ?array
    {
        $size = $size === 'medium' ? 'medium' : 'thumb';
        $candidates = $size === 'medium'
            ? ['medium', 'thumb']
            : ['thumb', 'medium'];

        foreach ($candidates as $candidate) {
            $path = $this->previewPath($entry, $candidate);
            if (is_file($path)) {
                return [
                    'path' => $path,
                    'mime' => 'image/jpeg',
                    'etag' => '"' . (int) $entry->version . '-' . $candidate . '-' . filemtime($path) . '"',
                ];
            }
        }

        return null;
    }

    /**
     * @return array{entries:array<int,array{name:string,size:?int,is_dir:bool}>,truncated:bool}
     */
    public function archiveListing(string $path): array
    {
        $tmp = $this->downloadToTemp($this->metadata->require($path));
        if ($tmp === null) {
            throw new StorageException('Не удалось прочитать архив');
        }

        try {
            $extension = (string) StoragePath::extension($path);

            if ($extension === 'zip' && class_exists(\ZipArchive::class)) {
                return $this->listZip($tmp);
            }

            if ($this->toolExists('7z') || $this->toolExists('7za')) {
                return $this->listWith7z($tmp);
            }

            if ($extension === 'zip' && class_exists(\ZipArchive::class)) {
                return $this->listZip($tmp);
            }

            throw new StorageException('Просмотр этого формата архива недоступен на сервере');
        } finally {
            @unlink($tmp);
        }
    }

    /** Extract a single archive entry to a temp file and return its handle. */
    public function archiveEntryStream(string $path, string $entryName): array
    {
        $tmp = $this->downloadToTemp($this->metadata->require($path));
        if ($tmp === null) {
            throw new StorageException('Не удалось прочитать архив');
        }

        $extension = (string) StoragePath::extension($path);

        if ($extension !== 'zip' || !class_exists(\ZipArchive::class)) {
            @unlink($tmp);

            throw new StorageException('Извлечение доступно только для ZIP-архивов');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            throw new StorageException('Не удалось открыть архив');
        }

        $stream = $zip->getStream($entryName);
        if ($stream === false) {
            $zip->close();
            @unlink($tmp);

            throw StorageException::notFound();
        }

        $contents = stream_get_contents($stream) ?: '';
        fclose($stream);
        $zip->close();
        @unlink($tmp);

        $out = fopen('php://temp', 'r+');
        fwrite($out, $contents);
        rewind($out);

        return ['stream' => $out, 'name' => StoragePath::name($entryName), 'size' => strlen($contents)];
    }

    private function downloadToTemp(StorageMeta $entry): ?string
    {
        try {
            $stream = $this->factory->make()->readStream($entry->path);
        } catch (Throwable) {
            return null;
        }

        $tmp = tempnam($this->scope->tmpDir(), 'prev');
        if ($tmp === false) {
            return null;
        }

        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return null;
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

        return $tmp;
    }

    private function makeImageThumbs(string $source, StorageMeta $entry): void
    {
        // Imagick (when installed) gives us a real JPEG/WebP encoder and colour
        // management; GD is used only as a fallback.
        if ($this->makeImageThumbsWithImagick($source, $entry)) {
            return;
        }

        $info = @getimagesize($source);
        if ($info === false) {
            throw new StorageException('Не удалось распознать изображение');
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));
        if ($image === false) {
            throw new StorageException('Не удалось обработать изображение');
        }

        try {
            foreach (['thumb' => 320, 'medium' => 1280] as $size => $maxWidth) {
                $this->saveScaled($image, $this->previewPath($entry, (string) $size), $maxWidth);
            }
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Generate both thumbnails with Imagick, honouring EXIF orientation and
     * flattening transparency onto white (previews are served as JPEG). Returns
     * false when Imagick is unavailable or fails, so the GD path can take over.
     */
    private function makeImageThumbsWithImagick(string $source, StorageMeta $entry): bool
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return false;
        }

        foreach (['thumb' => 320, 'medium' => 1280] as $size => $maxWidth) {
            $original = null;
            $frame = null;

            try {
                $original = new \Imagick($source);
                $original->setIteratorIndex(0);
                $original->setImageColorspace(\Imagick::COLORSPACE_SRGB);

                if (method_exists($original, 'autoOrientImage')) {
                    $original->autoOrientImage();
                }

                $original->setImageBackgroundColor('white');
                $frame = $original->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);

                if ($frame->getImageWidth() > $maxWidth) {
                    $frame->thumbnailImage($maxWidth, 0, true);
                }

                $frame->setImageFormat('jpeg');
                $frame->setImageCompressionQuality(82);
                $frame->stripImage();

                if (!$frame->writeImage($this->previewPath($entry, (string) $size))) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            } finally {
                $frame?->clear();
                $original?->clear();
            }
        }

        return true;
    }

    private function saveScaled(\GdImage $image, string $target, int $maxWidth): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        $ratio = $maxWidth / $width;
        $newWidth = $width > $maxWidth ? $maxWidth : $width;
        $newHeight = (int) max(1, round($height * ($width > $maxWidth ? $ratio : 1)));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $this->encodePreview($canvas, $target);
        imagedestroy($canvas);
    }

    /**
     * Encode a thumbnail with GD, falling back to the PNG encoder when the
     * installed GD build has no JPEG support. The previews are always served
     * as JPEG. Only used when Imagick is unavailable.
     */
    private function encodePreview(\GdImage $image, string $target): void
    {
        if (function_exists('imagejpeg')) {
            imagejpeg($image, $target, 82);

            return;
        }

        if (function_exists('imagewebp')) {
            imagewebp($image, $target, 82);

            return;
        }

        imagepng($image, $target);
    }

    private function makePdfThumbs(string $source, StorageMeta $entry): void
    {
        if (!$this->toolExists('pdftoppm')) {
            throw new StorageException('pdftoppm недоступен на сервере');
        }

        $prefix = $this->scope->tmpDir() . '/pdf-' . bin2hex(random_bytes(6));
        $timeout = (int) config('storage_browser.preview_timeout', 60);

        $this->exec(sprintf(
            'pdftoppm -singlefile -png -r 96 -f 1 -l 1 %s %s',
            escapeshellarg($source),
            escapeshellarg($prefix),
        ), $timeout);

        $png = $prefix . '.png';
        if (!is_file($png)) {
            throw new StorageException('Не удалось отрисовать PDF');
        }

        try {
            $this->makeImageThumbs($png, $entry);
        } finally {
            @unlink($png);
        }
    }

    private function makeVideoThumb(string $source, StorageMeta $entry): void
    {
        if (!$this->toolExists('ffmpeg')) {
            throw new StorageException('ffmpeg недоступен на сервере');
        }

        $target = $this->previewPath($entry, 'thumb');
        $timeout = (int) config('storage_browser.preview_timeout', 60);

        $this->exec(sprintf(
            'ffmpeg -ss 00:00:01 -i %s -frames:v 1 -vf scale=640:-2 -y %s',
            escapeshellarg($source),
            escapeshellarg($target),
        ), $timeout);

        if (!is_file($target)) {
            throw new StorageException('Не удалось создать превью видео');
        }

        @copy($target, $this->previewPath($entry, 'medium'));
    }

    /**
     * Location of a cached thumbnail; the entry version keeps stale previews
     * distinguishable from freshly generated ones.
     */
    private function previewPath(StorageMeta $entry, string $size): string
    {
        return $this->scope->previewsDir($entry->getKey()) . '/' . (int) $entry->version . '-' . $size . '.jpg';
    }

    /** @return array{entries:array<int,array{name:string,size:?int,is_dir:bool}>,truncated:bool} */
    private function listZip(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new StorageException('Не удалось открыть ZIP-архив');
        }

        $max = (int) config('storage_browser.preview_max_entries', 2000);
        $entries = [];
        $count = $zip->numFiles;
        $truncated = $count > $max;

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

        return ['entries' => $entries, 'truncated' => $truncated];
    }

    /** @return array{entries:array<int,array{name:string,size:?int,is_dir:bool}>,truncated:bool} */
    private function listWith7z(string $path): array
    {
        $binary = $this->toolExists('7z') ? '7z' : '7za';
        $output = $this->exec(sprintf('%s l -slt %s', $binary, escapeshellarg($path)), 15);

        $max = (int) config('storage_browser.preview_max_entries', 2000);
        $entries = [];
        $current = null;
        $truncated = false;

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (str_starts_with($line, 'Path = ')) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['name' => substr($line, 7), 'size' => null, 'is_dir' => false];
            } elseif ($current !== null && str_starts_with($line, 'Size = ')) {
                $current['size'] = (int) substr($line, 7);
            } elseif ($current !== null && str_starts_with($line, 'Attributes = ')) {
                $current['is_dir'] = str_contains($line, 'D');
            }

            if (count($entries) >= $max + 1) {
                $truncated = true;
                break;
            }
        }

        if ($current !== null && count($entries) < $max) {
            $entries[] = $current;
        }

        // The first two entries are the archive itself and its summary block.
        $entries = array_slice($entries, 2);

        return ['entries' => array_slice($entries, 0, $max), 'truncated' => $truncated || count($entries) > $max];
    }

    private function markFailed(StorageMeta $entry, string $message): void
    {
        $entry->forceFill(['preview_status' => 'failed', 'preview_at' => now()])->save();
        $this->audit->record('file.preview', entry: $entry, payload: ['error' => $message], status: 500);
    }

    private function toolExists(string $binary): bool
    {
        if ($binary === 'pdftoppm' && $this->pdfTool !== null) {
            return $this->pdfTool;
        }
        if ($binary === 'ffmpeg' && $this->ffmpegTool !== null) {
            return $this->ffmpegTool;
        }

        $command = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? "where {$binary}" : "command -v {$binary}";
        $output = @shell_exec($command . ' 2>/dev/null');
        $exists = is_string($output) && trim($output) !== '';

        if ($binary === 'pdftoppm') {
            $this->pdfTool = $exists;
        } elseif ($binary === 'ffmpeg') {
            $this->ffmpegTool = $exists;
        }

        return $exists;
    }

    private function exec(string $command, int $timeout): string
    {
        if (!function_exists('exec')) {
            throw new StorageException('Выполнение внешних команд недоступно на сервере');
        }

        $output = [];
        $code = 0;
        @exec('timeout ' . max(1, $timeout) . ' ' . $command . ' 2>/dev/null', $output, $code);

        return implode("\n", $output);
    }
}
