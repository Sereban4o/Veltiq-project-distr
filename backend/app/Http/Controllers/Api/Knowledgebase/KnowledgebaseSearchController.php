<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Knowledgebase\PageAcl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KnowledgebaseSearchController extends Controller
{
    public function index(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $spaceKey = $request->query('space');
        $limit = (int) $request->query('limit', 30);
        $limit = max(1, min($limit, 100));

        if (mb_strlen($query) < 2) {
            return response()->json(['source' => $this->driver(), 'results' => []]);
        }

        $driver = $this->driver();

        $candidates = $this->searchPages($query, $driver, $limit * 4);

        $user = Auth::user();
        $acls = [];
        $results = [];

        foreach ($candidates as $page) {
            if ($spaceKey && $page->space?->key !== $spaceKey) {
                continue;
            }

            $space = $page->space;
            if (! $space) {
                continue;
            }

            $acls[$space->id] ??= PageAcl::for($space, $user);

            if (! $acls[$space->id]->canView($page)) {
                continue;
            }

            $results[] = [
                'id' => $page->id,
                'title' => $page->title,
                'space_id' => $page->space_id,
                'space_key' => $space->key,
                'space_name' => $space->name,
                'excerpt' => $this->excerpt(Page::plainText($page->body), $query),
                'labels' => $page->labels,
                'updated_at' => $page->updated_at,
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return response()->json([
            'source' => $driver,
            'query' => $query,
            'results' => $results,
        ]);
    }

    private function driver(): string
    {
        return config('scout.driver') === 'meilisearch' && $this->meilisearchAvailable()
            ? 'meilisearch'
            : 'database';
    }

    private function meilisearchAvailable(): bool
    {
        return (bool) config('scout.meilisearch.host');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Page>
     */
    private function searchPages(string $query, string $driver, int $take)
    {
        if ($driver === 'meilisearch') {
            return Page::search($query)->take($take)->get()->load('space');
        }

        return Page::query()
            ->where('status', 'published')
            ->where(function ($q) use ($query) {
                $q->whereLike('title', "%{$query}%")
                    ->orWhereLike('body', "%{$query}%");
            })
            ->with('space')
            ->limit($take)
            ->get();
    }

    private function excerpt(string $text, string $query, int $length = 200): string
    {
        if ($text === '') {
            return '';
        }

        $pos = mb_stripos($text, $query);

        if ($pos === false) {
            return mb_strimwidth($text, 0, $length, '…');
        }

        $start = max(0, $pos - (int) ($length / 3));
        $snippet = mb_substr($text, $start, $length);

        return ($start > 0 ? '…' : '') . $snippet . (mb_strlen($text) > $start + $length ? '…' : '');
    }
}
