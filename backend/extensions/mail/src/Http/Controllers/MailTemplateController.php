<?php

namespace App\Extensions\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MailTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $templates = MailTemplate::where('user_id', Auth::id())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'subject' => 'nullable|string|max:998',
            'body_html' => 'required|string|max:200000',
            'position' => 'sometimes|integer|min:0',
        ]);

        $template = MailTemplate::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'subject' => $validated['subject'] ?? null,
            'body_html' => $validated['body_html'],
            'position' => $validated['position']
                ?? ((int) MailTemplate::where('user_id', Auth::id())->max('position') + 1),
        ]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, MailTemplate $template): JsonResponse
    {
        $this->ensureOwned($template);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'subject' => 'sometimes|nullable|string|max:998',
            'body_html' => 'sometimes|required|string|max:200000',
            'position' => 'sometimes|integer|min:0',
        ]);

        $template->update($validated);

        return response()->json(['data' => $template->fresh()]);
    }

    public function destroy(MailTemplate $template): JsonResponse
    {
        $this->ensureOwned($template);

        $template->delete();

        return response()->json(['message' => 'Шаблон удалён']);
    }

    private function ensureOwned(MailTemplate $template): void
    {
        if ((int) $template->user_id !== (int) Auth::id()) {
            abort(404, 'Шаблон не найден');
        }
    }
}
