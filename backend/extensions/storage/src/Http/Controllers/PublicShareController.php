<?php

namespace App\Extensions\Storage\Http\Controllers;

use App\Extensions\Storage\Http\Controllers\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Models\StorageShare;
use App\Extensions\Storage\Services\FileBrowserService;
use App\Extensions\Storage\Services\StorageShareService;
use App\Extensions\Storage\Services\StorageShareUnavailableException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonymous access to a shared entry. Tokens are unguessable and passwords are
 * verified through a short-lived signed cookie/token.
 */
class PublicShareController extends Controller
{
    use MapsStorageErrors;

    public function __construct(
        private StorageShareService $shares,
        private FileBrowserService $files,
    ) {
    }

    public function show(Request $request, string $token): JsonResponse
    {
        return $this->storage(function () use ($request, $token) {
            $share = $this->share($token);
            $meta = $this->files->metaFor($share->path) ?? $this->files->ensureMeta($share->path);

            return $this->secure(response()->json(['data' => [
                'name' => basename($share->path),
                'type' => $meta->type,
                'size' => (int) $meta->size,
                'mime' => $meta->mime,
                'previewable' => $this->files->previewable($share->path),
                'requires_password' => $this->shares->requiresPassword($share),
                'has_access' => $this->shares->hasAccess($share, $request),
                'can_download' => (bool) $share->can_download,
                'expires_at' => $share->expires_at?->toIso8601String(),
                'downloads_left' => $share->downloadsLeft(),
            ]]));
        });
    }

    public function unlock(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate(['password' => 'required|string|max:100']);

        return $this->storage(function () use ($request, $token, $validated) {
            $share = $this->share($token);
            $access = $this->shares->unlock($share, $validated['password']);

            $cookie = cookie(
                name: $this->shares->cookieName($share),
                value: $access,
                minutes: 60,
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                raw: false,
                sameSite: 'Lax',
            );

            return $this->secure(response()->json(['data' => [
                'access_token' => $access,
                'ttl' => (int) config('storage_browser.share_token_ttl', 3600),
            ]]))->withCookie($cookie);
        });
    }

    public function raw(Request $request, string $token): Response
    {
        return $this->storage(function () use ($request, $token) {
            $share = $this->access($request, $token);
            $this->shares->registerAccess($share, false);

            return $this->secure($this->files->rawResponse($share->path, $request->header('Range')));
        });
    }

    public function download(Request $request, string $token): Response
    {
        return $this->storage(function () use ($request, $token) {
            $share = $this->access($request, $token);

            if (!$share->can_download) {
                abort(403, 'Скачивание по этой ссылке отключено');
            }

            if ($share->hasReachedDownloadLimit()) {
                throw StorageShareUnavailableException::gone('Лимит скачиваний исчерпан');
            }

            $this->shares->registerAccess($share, true);

            return $this->secure($this->files->downloadResponse($share->path));
        });
    }

    public function preview(Request $request, string $token): Response|JsonResponse
    {
        return $this->storage(function () use ($request, $token) {
            $share = $this->access($request, $token);
            $info = $this->files->preview($share->path, (string) $request->query('size', 'thumb'));

            if (($info['status'] ?? '') === 'ready' && isset($info['path'])) {
                $response = new \Symfony\Component\HttpFoundation\BinaryFileResponse($info['path']);
                $response->headers->set('Content-Type', $info['mime'] ?? 'image/jpeg');
                $response->headers->set('Cache-Control', 'private, max-age=3600');
                $response->setPrivate();

                return $this->secure($response);
            }

            return $this->secure(response()->json(['status' => $info['status'] ?? 'none'], 202));
        });
    }

    public function archive(Request $request, string $token): JsonResponse
    {
        return $this->storage(function () use ($request, $token) {
            $share = $this->access($request, $token);

            return $this->secure(response()->json(['data' => $this->files->archive($share->path)]));
        });
    }

    private function share(string $token): StorageShare
    {
        $share = $this->shares->findByToken($token);
        $this->shares->assertUsable($share);

        return $share;
    }

    /** Resolve the share and require a valid password unlock when needed. */
    private function access(Request $request, string $token): StorageShare
    {
        $share = $this->share($token);

        if (!$this->shares->hasAccess($share, $request)) {
            throw new AuthorizationException('Требуется пароль для доступа к ссылке');
        }

        return $share;
    }

    private function secure(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
