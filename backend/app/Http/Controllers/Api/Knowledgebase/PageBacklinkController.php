<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Pages that link to the given page.
 *
 * Pages are stored as HTML, so a candidate scan (LIKE) is followed by an exact
 * regex check and a per-page view permission check before returning anything.
 */
class PageBacklinkController extends Controller
{
    public function index(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);

        if (! $page->canView(Auth::user())) {
            abort(404, 'Страница не найдена');
        }

        $needle = '/pages/' . $page->id;

        $candidates = Page::query()
            ->with('space:id,key,name')
            ->whereKeyNot($page->id)
            ->where('body', 'like', '%' . $needle . '%')
            ->whereHas('space', fn ($space) => $space->visibleTo(Auth::user()))
            ->limit(50)
            ->get(['id', 'space_id', 'title', 'body']);

        $backlinks = [];

        foreach ($candidates as $candidate) {
            if ($candidate->space === null) {
                continue;
            }

            // Avoid matching /pages/12 when looking for /pages/1.
            if (! preg_match('#/pages/' . $page->id . '(?![0-9])#', (string) $candidate->body)) {
                continue;
            }

            if (! $candidate->canView(Auth::user())) {
                continue;
            }

            $backlinks[] = [
                'id' => $candidate->id,
                'title' => $candidate->title,
                'space_key' => $candidate->space->key,
                'space_name' => $candidate->space->name,
                'link' => "/knowledgebase/{$candidate->space->key}/pages/{$candidate->id}",
            ];
        }

        return response()->json(['data' => $backlinks]);
    }
}
