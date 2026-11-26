<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Retrieval\QueryTokens;
use App\Models\User;
use App\Services\Search\GlobalSearchService;

/**
 * Knowledge base, tasks, files and mail keep using the permission-aware global
 * search (Meilisearch ranking with an Eloquent ACL re-check), so the assistant
 * can never surface content the user may not open in the UI.
 */
final class CoreSearchProvider implements AiSourceProvider
{
    public function __construct(private readonly GlobalSearchService $search)
    {
    }

    public function types(): array
    {
        return ['pages', 'issues', 'files', 'mail'];
    }

    public function extension(): ?string
    {
        return null;
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        // One call for every requested core type: search() resolves the
        // candidate window once per call, which may hit the core service.
        $groups = $this->search->search($user, $query, $types, $limit, 1);

        // A full question rarely matches a substring search (the database
        // fallback when Meilisearch is off), so retry with the most
        // significant word before giving up on the section.
        if ($this->isEmpty($groups, $types)) {
            $keyword = QueryTokens::of($query)[0] ?? '';

            if ($keyword !== '' && $keyword !== $query) {
                $groups = $this->search->search($user, $keyword, $types, $limit, 1);
            }
        }

        $result = [];

        foreach ($types as $type) {
            $hits = array_slice($groups[$type]['hits'] ?? [], 0, $limit);

            $result[$type] = array_map(fn (array $hit): array => AiSource::make(
                $type,
                (int) ($hit['id'] ?? 0),
                (string) ($hit['title'] ?? ''),
                (string) ($hit['url'] ?? ''),
                (string) ($hit['snippet'] ?? ''),
                is_array($hit['meta'] ?? null) ? $hit['meta'] : [],
            ), $hits);
        }

        return $result;
    }

    /**
     * @param  array<string, array{total?:int, hits?:list<array>}>  $groups
     * @param  list<string>  $types
     */
    private function isEmpty(array $groups, array $types): bool
    {
        foreach ($types as $type) {
            if (($groups[$type]['hits'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }
}
