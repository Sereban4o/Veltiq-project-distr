<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageShare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Manages public read-only links to a knowledgebase page.
 */
class PageShareController extends Controller
{
    public function index(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $this->authorizeManage($page);

        $shares = $page->shares()
            ->latest()
            ->get()
            ->map(fn (PageShare $share) => [
                'id' => $share->id,
                'has_password' => $share->hasPassword(),
                'expires_at' => optional($share->expires_at)->toIso8601String(),
                'revoked_at' => optional($share->revoked_at)->toIso8601String(),
                'created_at' => optional($share->created_at)->toIso8601String(),
                'active' => ! $share->isExpired(),
            ]);

        return response()->json(['data' => $shares]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $this->authorizeManage($page);

        $validated = $request->validate([
            'expires_at' => 'sometimes|nullable|date|after:now',
            'password' => 'sometimes|nullable|string|min:4|max:128',
        ]);

        $token = Str::random(48);
        $password = $validated['password'] ?? null;

        $share = PageShare::create([
            'page_id' => $page->id,
            'created_by' => Auth::id(),
            'token_hash' => PageShare::hashToken($token),
            'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : null,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'id' => $share->id,
                'token' => $token,
                'path' => "/share/page/{$token}",
                'expires_at' => optional($share->expires_at)->toIso8601String(),
                'has_password' => $share->hasPassword(),
            ],
        ], 201);
    }

    public function destroy(PageShare $share): JsonResponse
    {
        $this->authorizeManage($share->page);

        $share->forceFill(['revoked_at' => now()])->save();

        return response()->json(['data' => ['revoked' => true]]);
    }

    private function authorizeManage(Page $page): void
    {
        if (! $page->canEdit()) {
            abort(403, 'Недостаточно прав для управления ссылкой');
        }
    }
}
