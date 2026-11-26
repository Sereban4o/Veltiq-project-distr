<?php

namespace App\Extensions\Ai\Retrieval\Concerns;

use App\Extensions\Ai\Retrieval\QueryTokens;

/** Shared text matching for providers that query the database directly. */
trait MatchesKeywords
{
    /**
     * Matches any significant token of the question against the columns.
     * A question without tokens (e.g. "ai") is matched literally instead.
     *
     * @param  list<string>  $columns
     */
    protected function whereMatchesTokens($query, array $columns, string $search): void
    {
        $tokens = QueryTokens::of($search);

        $this->whereMatchesAny($query, $columns, $tokens !== [] ? $tokens : [$search]);
    }

    /**
     * `whereLike` is case-insensitive on PostgreSQL (ILIKE), which is what we
     * want for Cyrillic names typed in different registers.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $needles
     */
    protected function whereMatchesAny($query, array $columns, array $needles): void
    {
        $query->where(function ($group) use ($columns, $needles): void {
            foreach ($columns as $column) {
                foreach ($needles as $needle) {
                    $group->orWhereLike($column, '%' . addcslashes($needle, '%_\\') . '%');
                }
            }
        });
    }

    /** Detects a section-specific question ("what is on my calendar this week"). */
    protected function matchesIntent(string $query, string $pattern): bool
    {
        return preg_match($pattern, $query) === 1;
    }
}
