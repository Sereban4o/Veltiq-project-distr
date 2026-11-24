<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\File;
use App\Extensions\Crm\Models\Company;
use App\Extensions\Crm\Models\Contact;
use App\Extensions\Crm\Models\CrmFileLink;
use App\Extensions\Crm\Models\Deal;
use App\Extensions\Storage\Services\FileUploadService;
use App\Extensions\Storage\Services\OfficeDocumentTypes;
use App\Extensions\Storage\Services\OfficeJwt;
use App\Extensions\Storage\Services\OfficeSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Attaches existing files to CRM deals/companies/contacts and opens office
 * documents in the ONLYOFFICE editor.
 */
class CrmFileController extends Controller
{
    public function __construct(
        private OfficeJwt $jwt,
        private FileUploadService $uploads,
        private OfficeSettings $settings,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $entity = $this->entity($request);

        return response()->json([
            'data' => $entity->files()
                ->with('uploader:id,first_name,last_name')
                ->orderByDesc('crm_file_links.id')
                ->get(),
        ]);
    }

    public function attach(Request $request): JsonResponse
    {
        $entity = $this->entity($request);

        $validated = $request->validate([
            'file_id' => 'required|integer|exists:files,id',
        ]);

        $entity->files()->syncWithoutDetaching([
            (int) $validated['file_id'] => ['linked_by' => Auth::id()],
        ]);

        return $this->index($request);
    }

    public function detach(Request $request): JsonResponse
    {
        $entity = $this->entity($request);
        $entity->files()->detach((int) $request->route('file'));

        return $this->index($request);
    }

    public function upload(Request $request): JsonResponse
    {
        $entity = $this->entity($request);

        $request->validate([
            'file' => 'required|file|max:' . FileUploadService::MAX_KB . '|mimes:' . FileUploadService::ALLOWED_MIMES,
        ]);

        $file = $this->uploads->store($request->file('file'), Auth::id());

        $entity->files()->syncWithoutDetaching([
            $file->id => ['linked_by' => Auth::id()],
        ]);

        return response()->json(['data' => $file], 201);
    }

    public function officeConfig(File $file): JsonResponse
    {
        abort_unless((bool) config('onlyoffice.enabled'), 404, 'Редактор ONLYOFFICE не включён');

        abort_unless(CrmFileLink::where('file_id', $file->id)->exists(), 404, 'Файл не привязан к CRM');

        if (! config('onlyoffice.url')) {
            return response()->json(['message' => 'ONLYOFFICE_URL не задан'], 503);
        }
        if (! $this->jwt->isConfigured()) {
            return response()->json(['message' => 'ONLYOFFICE_JWT_SECRET не задан'], 503);
        }

        $extension = strtolower(pathinfo((string) $file->original_name, PATHINFO_EXTENSION));
        $documentType = OfficeDocumentTypes::forExtension($extension);

        if ($documentType === null) {
            return response()->json(['message' => 'Этот формат не поддерживается редактором'], 422);
        }

        $user = Auth::user();
        $base = rtrim((string) config('onlyoffice.callback_base_url'), '/');

        $downloadUrl = $base . '/api/v1/crm/files/' . $file->id . '/office/download?token=' . urlencode(
            $this->jwt->signWithTtl(['action' => 'download', 'file_id' => $file->id]),
        );
        $callbackUrl = $base . '/api/v1/crm/files/' . $file->id . '/office/callback?token=' . urlencode(
            $this->jwt->signWithTtl(['action' => 'callback', 'file_id' => $file->id]),
        );

        $key = substr(sha1($file->id . '|' . $file->updated_at . '|' . $file->size), 0, 40);

        $document = [
            'fileType' => $extension,
            'key' => $key,
            'title' => $file->original_name,
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

    public function officeDownload(Request $request, File $file)
    {
        abort_unless((bool) config('onlyoffice.enabled'), 404);

        $data = $this->jwt->decode((string) $request->query('token', ''));
        if ($data === null || ($data['action'] ?? '') !== 'download' || (int) ($data['file_id'] ?? 0) !== (int) $file->id) {
            return response()->json(['message' => 'Недействительная ссылка'], 403);
        }

        if (! Storage::disk('public')->exists($file->path)) {
            return response()->json(['message' => 'Файл не найден'], 404);
        }

        return Storage::disk('public')->download($file->path, $file->original_name);
    }

    public function officeCallback(Request $request, File $file): JsonResponse
    {
        abort_unless((bool) config('onlyoffice.enabled'), 404);

        $data = $this->jwt->decode((string) $request->query('token', ''));
        if ($data === null || ($data['action'] ?? '') !== 'callback' || (int) ($data['file_id'] ?? 0) !== (int) $file->id) {
            return response()->json(['error' => 1, 'message' => 'Недействительный токен'], 403);
        }

        $payload = $request->json()->all();
        $status = (int) ($payload['status'] ?? 0);

        // 2 — ready for saving, 6 — force save (autosave).
        if (in_array($status, [2, 6], true) && ! empty($payload['url'])) {
            try {
                $response = Http::timeout(120)->get((string) $payload['url']);

                if (! $response->successful()) {
                    return response()->json(['error' => 1, 'message' => 'HTTP ' . $response->status()], 500);
                }

                Storage::disk('public')->put($file->path, $response->body());
            } catch (Throwable $e) {
                return response()->json(['error' => 1, 'message' => $e->getMessage()], 500);
            }
        }

        return response()->json(['error' => 0]);
    }

    private function entity(Request $request): Model
    {
        $type = (string) $request->route('entity');
        $id = (int) $request->route('id');

        $model = match ($type) {
            'deals' => Deal::class,
            'companies' => Company::class,
            'contacts' => Contact::class,
            default => null,
        };

        abort_if($model === null, 404);

        return $model::findOrFail($id);
    }
}
