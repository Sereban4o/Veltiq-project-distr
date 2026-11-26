<?php

namespace App\Extensions\Ai\Retrieval;

use App\Models\User;

/**
 * One searchable section of the product. Providers are the only place that
 * knows how to query a section, so every provider must enforce the same
 * visibility rules as the section's own API controller.
 */
interface AiSourceProvider
{
    /** @return list<string> source types handled by this provider */
    public function types(): array;

    /** Extension the provider depends on, or null when it reads core data. */
    public function extension(): ?string;

    /**
     * @param  list<string>  $types  requested subset of types()
     * @param  int  $limit  cap per type
     * @return array<string, list<array<string, mixed>>>
     */
    public function retrieve(User $user, string $query, array $types, int $limit): array;
}
