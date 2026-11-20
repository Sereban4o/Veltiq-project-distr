<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Http\Controllers\Controller;
use App\Models\PageTemplate;
use App\Models\Space;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PageTemplateController extends Controller
{
    /**
     * Space templates plus built-in global templates.
     */
    public function index(Request $request, string $spaceKey)
    {
        $space = Space::where('key', $spaceKey)->firstOrFail();

        if (! $space->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $templates = PageTemplate::with('creator')
            ->where(function ($q) use ($space) {
                $q->where('space_id', $space->id)->orWhereNull('space_id');
            })
            ->orderByRaw('space_id IS NULL') // space-scoped templates first
            ->orderBy('name')
            ->get();

        return response()->json($templates);
    }

    public function store(Request $request, string $spaceKey)
    {
        $space = Space::where('key', $spaceKey)->firstOrFail();

        if (! $space->canEdit(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $this->validatePayload($request);

        $template = PageTemplate::create([
            'space_id' => $space->id,
            'created_by' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'body' => HtmlSanitizer::clean($validated['body'] ?? ''),
            'labels' => array_values(array_map('strtolower', $validated['labels'] ?? [])),
        ]);

        return response()->json($template->load('creator'), 201);
    }

    /**
     * Create a built-in global template (admins only).
     */
    public function storeGlobal(Request $request)
    {
        if (! Auth::user()->isAdmin()) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $this->validatePayload($request);

        $template = PageTemplate::create([
            'space_id' => null,
            'created_by' => Auth::id(),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'body' => HtmlSanitizer::clean($validated['body'] ?? ''),
            'labels' => array_values(array_map('strtolower', $validated['labels'] ?? [])),
        ]);

        return response()->json($template->load('creator'), 201);
    }

    public function update(Request $request, string $templateId)
    {
        $template = PageTemplate::findOrFail($templateId);

        if (! $this->canManage($template)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $this->validatePayload($request);

        $template->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'body' => HtmlSanitizer::clean($validated['body'] ?? ''),
            'labels' => array_values(array_map('strtolower', $validated['labels'] ?? [])),
        ]);

        return response()->json($template->load('creator'));
    }

    public function destroy(Request $request, string $templateId)
    {
        $template = PageTemplate::findOrFail($templateId);

        if (! $this->canManage($template)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted']);
    }

    private function canManage(PageTemplate $template): bool
    {
        $user = Auth::user();

        if ($template->space_id === null) {
            return $user->isAdmin();
        }

        return $template->space && $template->space->canEdit($user);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'body' => 'nullable|string',
            'labels' => 'nullable|array',
            'labels.*' => 'string|max:50',
        ]);
    }
}
