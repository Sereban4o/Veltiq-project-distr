<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Services\Branding\BrandingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Uploadable templates (docs/13-template-system.md).
 *
 * A template is a ZIP with `manifest.json` plus plain HTML/CSS/JS. It is served
 * by the backend (never executed as PHP, never copied into `public/`), and is
 * addressed as `/template/{slug}`. The built-in React SPA is a template row too
 * (`is_builtin`), so "no custom template" and "back to the SPA" are the same
 * state.
 */
class TemplateService
{
    public const MAX_ZIP_SIZE = 50 * 1024 * 1024;

    public const MAX_EXTRACTED_SIZE = 100 * 1024 * 1024;

    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public const MAX_FILES = 2000;

    public const ALLOWED_EXTENSIONS = [
        'html', 'htm', 'php', 'css', 'js', 'mjs', 'json', 'map', 'webmanifest', 'txt',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    private const PAGE_EXTENSIONS = ['html', 'htm', 'php'];

    private const DATA_HOOKS = ['user', 'branding', 'settings'];

    private const MIME_TYPES = [
        'html' => 'text/html; charset=UTF-8',
        'htm' => 'text/html; charset=UTF-8',
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'webmanifest' => 'application/manifest+json; charset=UTF-8',
        'txt' => 'text/plain; charset=UTF-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    public function __construct(private readonly BrandingService $branding)
    {
    }

    /** Root directory that holds installed templates. */
    public function root(): string
    {
        return rtrim((string) config('templates.path', storage_path('app/templates')), '/');
    }

    /**
     * Validate and unpack an uploaded archive into a new template.
     *
     * @throws RuntimeException when the archive is unsafe or the manifest is invalid
     */
    public function uploadAndExtract(UploadedFile $file, ?string $name = null, ?string $author = null): Template
    {
        $size = $file->getSize();
        if (! $file->isValid() || $size === false) {
            throw new RuntimeException('файл шаблона не загружен');
        }

        if ($size > self::MAX_ZIP_SIZE) {
            throw new RuntimeException('архив шаблона больше 50 МБ');
        }

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            throw new RuntimeException('ожидается ZIP-архив шаблона');
        }

        $archivePath = $file->getRealPath();
        if (! is_string($archivePath) || ! is_file($archivePath)) {
            throw new RuntimeException('не удалось прочитать загруженный архив');
        }

        $workspace = $this->makeWorkspace();

        try {
            $this->extractSafely($archivePath, $workspace);

            $source = $this->locateRoot($workspace);

            if (! (bool) config('templates.allow_php', true) && $this->containsPhpFiles($source)) {
                throw new RuntimeException('PHP-файлы в шаблоне запрещены (TEMPLATES_ALLOW_PHP=false)');
            }

            $manifest = $this->validateManifest($this->readManifest($source));

            if ($this->resolveRelativeFile($source, $manifest['main_file']) === null) {
                throw new RuntimeException('в архиве нет главного файла шаблона: ' . $manifest['main_file']);
            }

            $slug = $this->uniqueSlug($manifest['slug'] !== '' ? $manifest['slug'] : ($name ?: $manifest['name']));
            $target = $this->root() . '/' . $slug;

            $this->ensureRoot();

            if (is_dir($target)) {
                throw new RuntimeException("шаблон {$slug} уже установлен");
            }

            if (! @rename($source, $target)) {
                $this->copyDirectory($source, $target);
                $this->removeDirectory($source);
            }

            try {
                return Template::create([
                    'name' => $name !== null && trim($name) !== '' ? trim($name) : $manifest['name'],
                    'slug' => $slug,
                    'version' => $manifest['version'],
                    'author' => $author !== null && trim($author) !== '' ? trim($author) : $manifest['author'],
                    'description' => $manifest['description'],
                    'storage_path' => $slug,
                    'main_file' => $manifest['main_file'],
                    'requires_auth' => $manifest['requires_auth'],
                    'is_active' => false,
                    'is_builtin' => false,
                ]);
            } catch (Throwable $e) {
                $this->removeDirectory($target);

                throw $e;
            }
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * Validate `manifest.json`.
     *
     * @param  array<string, mixed>  $raw
     * @return array{name: string, version: string, author: ?string, description: ?string, main_file: string, requires_auth: bool, slug: string, data_hooks: array<int, string>}
     */
    public function validateManifest(array $raw): array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 128) {
            throw new RuntimeException('manifest.json: поле name обязательно (до 128 символов)');
        }

        $version = trim((string) ($raw['version'] ?? '1.0.0'));
        if ($version === '' || mb_strlen($version) > 32) {
            throw new RuntimeException('manifest.json: поле version должно быть строкой до 32 символов');
        }

        $mainFile = trim((string) ($raw['main_file'] ?? 'index.html'));
        $mainFile = ltrim(str_replace('\\', '/', $mainFile), '/');
        $extension = strtolower(pathinfo($mainFile, PATHINFO_EXTENSION));
        if (! $this->isSafeRelativePath($mainFile) || ! in_array($extension, self::PAGE_EXTENSIONS, true)) {
            throw new RuntimeException('manifest.json: main_file должен быть путём к .html/.php внутри шаблона');
        }

        if ($extension === 'php' && ! (bool) config('templates.allow_php', true)) {
            throw new RuntimeException('manifest.json: PHP-шаблоны запрещены (TEMPLATES_ALLOW_PHP=false)');
        }

        $author = trim((string) ($raw['author'] ?? ''));
        if (mb_strlen($author) > 128) {
            throw new RuntimeException('manifest.json: author слишком длинный');
        }

        $description = trim((string) ($raw['description'] ?? ''));
        if (mb_strlen($description) > 2000) {
            throw new RuntimeException('manifest.json: description слишком длинный');
        }

        $requiresAuth = true;
        if (array_key_exists('requires_auth', $raw)) {
            if (! is_bool($raw['requires_auth'])) {
                throw new RuntimeException('manifest.json: requires_auth должен быть boolean');
            }
            $requiresAuth = $raw['requires_auth'];
        }

        $hooks = array_values(array_filter((array) ($raw['data_hooks'] ?? []), 'is_string'));
        foreach ($hooks as $hook) {
            if (! in_array($hook, self::DATA_HOOKS, true)) {
                throw new RuntimeException("manifest.json: неизвестный data_hook {$hook}");
            }
        }

        $slugRaw = trim((string) ($raw['slug'] ?? ''));
        if ($slugRaw !== '' && ! $this->isSafeRelativePath($slugRaw)) {
            throw new RuntimeException('manifest.json: некорректный slug');
        }

        return [
            'name' => $name,
            'version' => $version,
            'author' => $author !== '' ? $author : null,
            'description' => $description !== '' ? $description : null,
            'main_file' => $mainFile,
            'requires_auth' => $requiresAuth,
            'slug' => $slugRaw !== '' ? (Str::slug($slugRaw) ?: '') : '',
            'data_hooks' => $hooks,
        ];
    }

    public function activate(Template $template): Template
    {
        $template->update(['is_active' => true]);

        return $template->refresh();
    }

    public function deactivate(Template $template): Template
    {
        if ($template->is_builtin) {
            throw new RuntimeException('встроенный шаблон нельзя деактивировать');
        }

        $template->update(['is_active' => false]);

        return $template->refresh();
    }

    public function activeTemplate(): ?Template
    {
        return Template::query()->active()->first();
    }

    /** @return Collection<int, Template> */
    public function listTemplates(bool $onlyActive = false): Collection
    {
        $query = Template::query();

        if ($onlyActive) {
            $query->active();
        }

        return $query
            ->orderByDesc('is_builtin')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** Remove the template files and soft-delete the row. */
    public function delete(Template $template): void
    {
        if ($template->is_builtin) {
            throw new RuntimeException('встроенный шаблон нельзя удалить');
        }

        $directory = $this->existingDirectory($template);
        if ($directory !== null) {
            $this->removeDirectory($directory);
        }

        $template->delete();

        if (! Template::query()->active()->exists()) {
            Template::query()->where('is_builtin', true)->first()?->update(['is_active' => true]);
        }
    }

    /**
     * Read the HTML of a template page.
     *
     * @return array{content: string, file: string}
     */
    public function renderPage(Template $template, ?string $page = null, array $data = []): array
    {
        if ($template->is_builtin) {
            throw new RuntimeException('встроенный шаблон обслуживается React-приложением');
        }

        $directory = $this->templateDirectory($template);
        $file = $this->resolvePageFile($directory, $page, $template->main_file);

        if ($file === null) {
            throw new RuntimeException('страница шаблона не найдена: ' . ($page ?? $template->main_file));
        }

        $relative = ltrim(substr($file, strlen($directory)), '/');

        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
            return ['content' => $this->renderPhpPage($file, $template, $data), 'file' => $relative, 'php' => true];
        }

        return ['content' => (string) file_get_contents($file), 'file' => $relative, 'php' => false];
    }

    /**
     * Execute a PHP template page.
     *
     * A PHP template is WordPress-style: it renders the whole page itself, so it
     * is NOT wrapped in the Blade layout. It runs with the same trust level as a
     * third-party module (full access), which is why uploads are admin-only and
     * `.php` files are never served as static assets (docs/13-template-system.md).
     *
     * @param  array<string, mixed>  $data
     */
    private function renderPhpPage(string $file, Template $template, array $data): string
    {
        if (! (bool) config('templates.allow_php', true)) {
            throw new RuntimeException('PHP-шаблоны запрещены (TEMPLATES_ALLOW_PHP=false)');
        }

        $level = ob_get_level();
        ob_start();

        try {
            (static function (string $__templateFile, array $__templateData, Template $template): void {
                extract($__templateData, EXTR_SKIP);
                require $__templateFile;
            })($file, $data, $template);
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Resolve a static asset of a template.
     *
     * @return array{path: string, mime: string}
     */
    public function serveAsset(Template $template, ?string $path = null): array
    {
        $relative = ltrim(str_replace('\\', '/', (string) $path), '/');

        if ($template->is_builtin || $relative === '' || ! $this->isSafeRelativePath($relative)) {
            throw new RuntimeException('некорректный путь к файлу шаблона');
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

        // Never serve PHP sources: a template is executed as a page, not exposed.
        if ($extension === 'php') {
            throw new RuntimeException('исходники PHP-страниц не отдаются как ассеты');
        }

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('тип файла не разрешён: ' . $extension);
        }

        $file = $this->resolveRelativeFile($this->templateDirectory($template), $relative);
        if ($file === null) {
            throw new RuntimeException('файл шаблона не найден');
        }

        return ['path' => $file, 'mime' => self::MIME_TYPES[$extension]];
    }

    /**
     * Data injected as `window.TEMPLATE_DATA`.
     *
     * @return array<string, mixed>
     */
    public function getTemplateData(?User $user = null): array
    {
        $connection = (string) config('broadcasting.default', 'reverb');
        $config = (array) config('broadcasting.connections.' . $connection, []);
        $options = (array) ($config['options'] ?? []);

        $roleName = null;
        if ($user instanceof User) {
            $storedRole = Role::query()->whereKey($user->role_id)->value('name');
            $roleName = is_string($storedRole) ? $storedRole : null;
        }

        return [
            'user' => $user instanceof User ? [
                'id' => $user->id,
                'name' => trim((string) $user->first_name . ' ' . (string) $user->last_name) ?: $user->email,
                'email' => $user->email,
                'role' => $roleName,
            ] : null,
            'branding' => $this->branding->get(),
            'settings' => [
                'app_name' => (string) config('app.name'),
                'locale' => app()->getLocale(),
            ],
            'api_base' => '/api/v1',
            // Platform pages a template can link to.
            'profile_url' => '/profile',
            // Path being rendered (`/`, `/profile`, …): the template decides what to
            // show, since it serves every route while it is active.
            'path' => '/',
            'csrf_token' => csrf_token(),
            'pusher' => [
                'driver' => $connection,
                'key' => $config['key'] ?? null,
                'cluster' => $options['cluster'] ?? null,
                'host' => $options['host'] ?? null,
                'port' => $options['port'] ?? null,
                'scheme' => $options['scheme'] ?? null,
                'channel_prefix' => 'veltiq',
            ],
        ];
    }

    /**
     * Conventional entry points, only when the files exist.
     *
     * @return array{stylesheet: ?string, script: ?string}
     */
    public function assetsFor(Template $template): array
    {
        $directory = $this->existingDirectory($template);
        $prefix = rtrim((string) config('templates.assets_prefix', '/template-assets'), '/');

        return [
            'stylesheet' => $directory !== null && is_file($directory . '/styles.css')
                ? $prefix . '/' . $template->slug . '/styles.css'
                : null,
            'script' => $directory !== null && is_file($directory . '/scripts.js')
                ? $prefix . '/' . $template->slug . '/scripts.js'
                : null,
        ];
    }

    public function pageUrl(Template $template, ?string $page = null): string
    {
        $url = '/template/' . $template->slug;
        $page = trim((string) $page, '/');

        return $page === '' ? $url : $url . '/' . $page;
    }

    /**
     * Resolve the file for a requested path.
     *
     * A template serves every route, so the path is mapped to a page: `profile`
     * looks for `profile.php`, `profile.html`, `profile/index.php`, …, and falls
     * back to the main file. That way `/profile` stays inside the template
     * (docs/13-template-system.md).
     */
    private function resolvePageFile(string $directory, ?string $page, string $mainFile): ?string
    {
        $page = trim(str_replace('\\', '/', (string) $page), '/');
        $candidates = [];

        if ($page !== '') {
            foreach (explode('/', $page) as $segment) {
                if ($segment === '..' || str_contains($segment, "\0")) {
                    $page = '';
                    break;
                }
            }
        }

        if ($page !== '') {
            $extension = strtolower(pathinfo($page, PATHINFO_EXTENSION));

            if ($extension !== '') {
                $candidates[] = $page;
            } else {
                $candidates[] = $page . '.php';
                $candidates[] = $page . '.html';
                $candidates[] = $page . '.htm';
                $candidates[] = rtrim($page, '/') . '/index.php';
                $candidates[] = rtrim($page, '/') . '/index.html';
            }
        }

        $candidates[] = $mainFile !== '' ? $mainFile : 'index.html';

        foreach ($candidates as $candidate) {
            $file = $this->resolveRelativeFile($directory, $candidate);
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    private function templateDirectory(Template $template): string
    {
        $directory = $this->existingDirectory($template);

        if ($directory === null) {
            throw new RuntimeException('файлы шаблона не найдены');
        }

        return $directory;
    }

    private function existingDirectory(Template $template): ?string
    {
        $relative = trim(str_replace('\\', '/', (string) $template->storage_path), '/');

        if ($relative === '' || ! $this->isSafeRelativePath($relative)) {
            return null;
        }

        $rootReal = realpath($this->root());
        $directoryReal = realpath($this->root() . '/' . $relative);

        if ($rootReal === false || $directoryReal === false || ! is_dir($directoryReal)) {
            return null;
        }

        return str_starts_with($directoryReal, rtrim($rootReal, '/') . '/') ? $directoryReal : null;
    }

    private function resolveRelativeFile(string $baseDir, string $relative): ?string
    {
        if (! $this->isSafeRelativePath($relative)) {
            return null;
        }

        $baseReal = realpath($baseDir);
        $fileReal = realpath($baseDir . '/' . ltrim($relative, '/'));

        if ($baseReal === false || $fileReal === false || ! is_file($fileReal)) {
            return null;
        }

        return str_starts_with($fileReal, rtrim($baseReal, '/') . '/') ? $fileReal : null;
    }

    /** Whether the extracted template tree contains PHP sources. */
    private function containsPhpFiles(string $directory): bool
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                return true;
            }
        }

        return false;
    }

    private function isSafeRelativePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return $normalized !== ''
            && ! str_contains($normalized, "\0")
            && ! str_starts_with($normalized, '/')
            && preg_match('/^[A-Za-z]:/', $normalized) !== 1
            && ! in_array('..', explode('/', $normalized), true);
    }

    private function uniqueSlug(string $candidate): string
    {
        $base = Str::limit(Str::slug($candidate) ?: 'template', 48, '');

        if ($base === '') {
            $base = 'template';
        }

        $slug = $base;
        $suffix = 2;

        while (Template::withTrashed()->where('slug', $slug)->exists() || is_dir($this->root() . '/' . $slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    private function ensureRoot(): void
    {
        if (! is_dir($this->root()) && ! @mkdir($this->root(), 0o755, true) && ! is_dir($this->root())) {
            throw new RuntimeException('не удалось создать каталог шаблонов');
        }
    }

    private function makeWorkspace(): string
    {
        $path = sys_get_temp_dir() . '/velt-template-' . bin2hex(random_bytes(6));

        if (! mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new RuntimeException('не удалось создать временный каталог');
        }

        return $path;
    }

    /** @return array<string, mixed> */
    private function readManifest(string $source): array
    {
        $file = $source . '/manifest.json';

        if (! is_file($file)) {
            throw new RuntimeException('в архиве нет manifest.json');
        }

        try {
            $raw = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('manifest.json не является корректным JSON');
        }

        if (! is_array($raw)) {
            throw new RuntimeException('manifest.json должен быть объектом');
        }

        return $raw;
    }

    private function locateRoot(string $workspace): string
    {
        if (is_file($workspace . '/manifest.json')) {
            return $workspace;
        }

        $candidates = glob($workspace . '/*/manifest.json') ?: [];
        if (count($candidates) !== 1) {
            throw new RuntimeException('в архиве должен быть ровно один каталог с manifest.json');
        }

        return dirname($candidates[0]);
    }

    /** Rejects traversal, absolute paths, links, PHP files and oversized archives. */
    private function extractSafely(string $archivePath, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('не удалось открыть архив шаблона');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('в архиве слишком много файлов');
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = (string) $stat['name'];
                $isDirectory = str_ends_with($name, '/');
                $normalized = str_replace('\\', '/', $name);
                $size = (int) $stat['size'];

                $total += $size;

                if (! $this->isSafeRelativePath(rtrim($normalized, '/'))) {
                    throw new RuntimeException('архив содержит недопустимый путь: ' . $name);
                }

                if ($size > self::MAX_FILE_SIZE) {
                    throw new RuntimeException('файл шаблона больше 10 МБ: ' . $name);
                }

                $zip->getExternalAttributesIndex($i, $operatingSystem, $attributes);
                $mode = $attributes >> 16;
                if (($mode & 0o170000) === 0o120000) {
                    throw new RuntimeException('архив содержит символическую ссылку: ' . $name);
                }

                if ($isDirectory) {
                    continue;
                }

                $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
                $executables = ['phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'cgi', 'pl', 'py', 'sh'];

                // PHP pages are allowed only when templates.allow_php is on; every
                // other executable type is always rejected (docs/13-template-system.md).
                if (in_array($extension, $executables, true)
                    || ($extension === 'php' && ! (bool) config('templates.allow_php', true))) {
                    throw new RuntimeException('архив содержит исполняемый файл: ' . $name);
                }

                if ($extension !== '' && ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                    throw new RuntimeException('тип файла не разрешён в шаблоне: ' . $name);
                }

                if (str_starts_with(basename($normalized), '.')) {
                    throw new RuntimeException('архив содержит скрытый файл: ' . $name);
                }
            }

            if ($total > self::MAX_EXTRACTED_SIZE) {
                throw new RuntimeException('распакованный шаблон больше 100 МБ');
            }

            if (! is_dir($target) && ! mkdir($target, 0o700, true) && ! is_dir($target)) {
                throw new RuntimeException('не удалось создать каталог распаковки');
            }

            // Windows archivers store names as "dir\file". ZipArchive::extractTo()
            // would create a single file with a backslash in its name on Linux, so
            // every entry is written explicitly under a normalised path.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = ltrim(str_replace('\\', '/', (string) $stat['name']), '/');

                if ($name === '') {
                    continue;
                }

                $destination = $target . '/' . $name;

                if (str_ends_with($name, '/')) {
                    if (! is_dir($destination) && ! mkdir($destination, 0o755, true) && ! is_dir($destination)) {
                        throw new RuntimeException('не удалось создать каталог: ' . $name);
                    }

                    continue;
                }

                $directory = dirname($destination);
                if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                    throw new RuntimeException('не удалось создать каталог: ' . $directory);
                }

                $content = $zip->getFromIndex($i);
                if ($content === false || file_put_contents($destination, $content) === false) {
                    throw new RuntimeException('не удалось распаковать файл: ' . $name);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /** Fallback for cross-filesystem moves (temp dir → storage). */
    private function copyDirectory(string $from, string $to): void
    {
        if (! mkdir($to, 0o755, true) && ! is_dir($to)) {
            throw new RuntimeException('не удалось создать каталог шаблона');
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $destination = $to . '/' . $relative;

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0o755, true);
                }

                continue;
            }

            if (! copy($item->getPathname(), $destination)) {
                throw new RuntimeException('не удалось скопировать ' . $relative);
            }
        }
    }
}
