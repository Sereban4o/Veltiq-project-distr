<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * REST administration of templates for the SPA/admin clients
 * (docs/13-template-system.md). The Blade page uses the same service.
 */
class AdminTemplateController extends Controller
{
    public function __construct(private readonly TemplateService $templates)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'templates' => $this->templates->listTemplates(),
            'active' => $this->templates->activeTemplate(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'archive' => ['required', 'file', 'max:51200'],
            'name' => ['nullable', 'string', 'max:128'],
            'author' => ['nullable', 'string', 'max:128'],
        ]);

        $file = $request->file('archive');
        if ($file === null) {
            return response()->json(['message' => 'Архив шаблона не загружен.'], 422);
        }

        try {
            $template = $this->templates->uploadAndExtract(
                $file,
                $validated['name'] ?? null,
                $validated['author'] ?? null,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['template' => $template], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['template' => Template::query()->findOrFail($id)]);
    }

    public function destroy(int $id): JsonResponse
    {
        $template = Template::query()->findOrFail($id);

        try {
            $this->templates->delete($template);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['deleted' => true]);
    }

    public function activate(int $id): JsonResponse
    {
        $template = Template::query()->findOrFail($id);

        return response()->json(['template' => $this->templates->activate($template)]);
    }

    public function deactivate(int $id): JsonResponse
    {
        $template = Template::query()->findOrFail($id);

        try {
            $template = $this->templates->deactivate($template);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['template' => $template]);
    }
}
