<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Storage\FileBrowserService;
use App\Services\Storage\OfficeJwt;
use App\Services\Storage\OfficeSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ONLYOFFICE Document Server integration for the file storage.
 *
 * The browser embeds the editor from the Document Server; the server fetches
 * the file from us and posts the saved document back to the callback URL.
 */
class OfficeController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const TYPES = [
        'word' => [
            'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm',
            'odt', 'ott', 'fodt', 'rtf', 'txt', 'html', 'htm', 'mht',
            'xml', 'epub', 'fb2',
        ],
        'cell' => [
            'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'xltm',
            'ods', 'ots', 'fods', 'csv', 'tsv',
        ],
        'slide' => [
            'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm',
            'pot', 'potx', 'potm', 'odp', 'otp', 'fodp',
        ],
    ];

    public function __construct(
        private FileBrowserService $files,
        private OfficeJwt $jwt,
        private OfficeSettings $settings,
    ) {
    }

    public function config(Request $request): JsonResponse
    {
        abort_unless(
            (bool) config('onlyoffice.enabled'),
            404,
            'Редактор ONLYOFFICE не включён (задайте ONLYOFFICE_ENABLED=true в backend/.env.production)',
        );

        $validated = $request->validate([
            'path' => 'required|string|max:4096',
        ]);

        if (!config('onlyoffice.url')) {
            return response()->json(['message' => 'ONLYOFFICE_URL не задан'], 503);
        }
        if (!$this->jwt->isConfigured()) {
            return response()->json(['message' => 'ONLYOFFICE_JWT_SECRET не задан'], 503);
        }

        $meta = $this->files->read($validated['path']);
        $extension = strtolower((string) ($meta['extension'] ?? ''));
        $documentType = $this->documentType($extension);

        if ($documentType === null) {
            return response()->json(['message' => 'Этот формат не поддерживается редактором'], 422);
        }

        $path = (string) $meta['path'];
        $user = $request->user();

        $base = rtrim((string) config('onlyoffice.callback_base_url'), '/');
        $downloadUrl = $base . '/api/v1/storage/office/download?token=' . urlencode(
            $this->jwt->signWithTtl(['action' => 'download', 'path' => $path]),
        );
        $callbackUrl = $base . '/api/v1/storage/office/callback?token=' . urlencode(
            $this->jwt->signWithTtl(['action' => 'callback', 'path' => $path]),
        );

        $key = substr(sha1($path . '|' . ($meta['modified_at'] ?? '') . '|' . ($meta['size'] ?? 0)), 0, 40);

        $document = [
            'fileType' => $extension,
            'key' => $key,
            'title' => $meta['name'],
            'url' => $downloadUrl,
            'permissions' => ['edit' => true, 'download' => true, 'print' => true],
        ];

        $editorConfig = [
            'callbackUrl' => $callbackUrl,
            'lang' => 'ru',
            'mode' => 'edit',
            'user' => [
                'id' => (string) ($user?->id ?? 0),
                'name' => trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? '')) ?: 'Пользователь',
            ],
            'customization' => [
                'autosave' => $this->settings->autosaveEnabled(),
                'forcesave' => true,
                'compactHeader' => false,
                'toolbarNoTabs' => false,
            ],
        ];

        $token = $this->jwt->signWithTtl([
            'document' => $document,
            'documentType' => $documentType,
            'editorConfig' => $editorConfig,
        ]);

        return response()->json([
            'data' => [
                'enabled' => true,
                'documentServerUrl' => rtrim((string) config('onlyoffice.url'), '/'),
                'apiScript' => rtrim((string) config('onlyoffice.url'), '/') . '/web-apps/apps/api/documents/api.js',
                'config' => [
                    'documentType' => $documentType,
                    'document' => $document,
                    'editorConfig' => $editorConfig,
                    'token' => $token,
                    'width' => '100%',
                    'height' => '100%',
                ],
            ],
        ]);
    }

    public function download(Request $request)
    {
        abort_unless((bool) config('onlyoffice.enabled'), 404);

        $data = $this->jwt->decode((string) $request->query('token', ''));
        if ($data === null || ($data['action'] ?? '') !== 'download' || empty($data['path'])) {
            return response()->json(['message' => 'Недействительная ссылка'], 403);
        }

        try {
            return $this->files->downloadResponse((string) $data['path']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    public function callback(Request $request): JsonResponse
    {
        abort_unless((bool) config('onlyoffice.enabled'), 404);

        $data = $this->jwt->decode((string) $request->query('token', ''));
        if ($data === null || ($data['action'] ?? '') !== 'callback' || empty($data['path'])) {
            return response()->json(['error' => 1, 'message' => 'Недействительный токен'], 403);
        }

        $payload = $request->json()->all();
        $status = (int) ($payload['status'] ?? 0);

        // 2 — ready for saving, 6 — force save (autosave).
        if (in_array($status, [2, 6], true) && !empty($payload['url'])) {
            try {
                $response = Http::timeout(120)->withOptions(['stream' => false])->get((string) $payload['url']);

                if (!$response->successful()) {
                    return response()->json(['error' => 1, 'message' => 'HTTP ' . $response->status()], 500);
                }

                $this->files->writeBinary((string) $data['path'], $response->body());
            } catch (Throwable $e) {
                return response()->json(['error' => 1, 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json(['error' => 0]);
    }

    private function documentType(string $extension): ?string
    {
        foreach (self::TYPES as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }
}
