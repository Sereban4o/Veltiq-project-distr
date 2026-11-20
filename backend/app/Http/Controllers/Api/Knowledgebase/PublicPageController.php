<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Http\Controllers\Controller;
use App\Models\PageShare;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Public (unauthenticated) access to a page shared with a link.
 */
class PublicPageController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $share = PageShare::query()
            ->with(['page.space'])
            ->where('token_hash', PageShare::hashToken($token))
            ->active()
            ->first();

        if ($share === null || $share->page === null) {
            return response()->json(['message' => 'Ссылка не найдена или истекла'], 404);
        }

        if ($share->hasPassword()) {
            $password = (string) $request->input('password', '');

            if ($password === '' || ! Hash::check($password, (string) $share->password_hash)) {
                return response()->json([
                    'message' => 'password_required',
                    'password_required' => true,
                ], 401);
            }
        }

        $share->forceFill(['last_accessed_at' => now()])->save();

        $page = $share->page;

        return response()->json([
            'data' => [
                'title' => $page->title,
                'body' => HtmlSanitizer::clean($page->body),
                'labels' => $page->labels ?? [],
                'space' => $page->space?->name,
                'updated_at' => optional($page->updated_at)->toIso8601String(),
            ],
        ]);
    }
}
