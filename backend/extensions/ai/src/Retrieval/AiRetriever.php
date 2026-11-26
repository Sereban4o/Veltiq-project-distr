<?php

namespace App\Extensions\Ai\Retrieval;

use App\Extensions\Ai\Services\EmployeeDirectory;
use App\Models\User;
use App\Services\Extensions\ExtensionRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Fans a question out over every searchable section and merges the hits.
 *
 * Each provider is isolated: a disabled extension, a missing table or a broken
 * query only removes that section from the answer instead of failing the whole
 * request. Hits are interleaved by type priority so one verbose section (mail)
 * cannot crowd out the others.
 */
final class AiRetriever
{
    /** Source types in priority order; chat is deliberately absent. */
    public const TYPES = [
        'pages',
        'issues',
        'calendar',
        'deals',
        'companies',
        'contacts',
        'files',
        'mail',
        'users',
        'worklogs',
    ];

    /** Cap per type: enough to be useful, small enough to keep the prompt short. */
    private const PER_TYPE_LIMIT = 3;

    /**
     * @param  iterable<AiSourceProvider>  $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly ExtensionRegistry $extensions,
        private readonly EmployeeDirectory $directory,
    ) {
    }

    /** Employees relevant to the question, the asker first. */
    public function directory(User $user, string $query, int $limit = 20): array
    {
        return $this->directory->forPrompt($user, $query, $limit);
    }

    /** Name the assistant may address the user by. */
    public function displayName(User $user): string
    {
        return $this->directory->displayName($user);
    }

    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function search(User $user, string $query, array $types, int $limit): array
    {
        $wanted = array_values(array_intersect($types, self::TYPES));

        if ($wanted === [] || $limit < 1 || trim($query) === '') {
            return [];
        }

        $groups = [];

        foreach ($this->providers as $provider) {
            $own = array_values(array_intersect($wanted, $provider->types()));

            if ($own === []) {
                continue;
            }

            $extension = $provider->extension();

            if ($extension !== null && ! $this->extensions->isEnabled($extension)) {
                continue;
            }

            try {
                foreach ($provider->retrieve($user, $query, $own, self::PER_TYPE_LIMIT) as $type => $hits) {
                    if (! in_array($type, $own, true)) {
                        continue;
                    }

                    $groups[$type] = array_merge($groups[$type] ?? [], array_values($hits));
                }
            } catch (\Throwable $e) {
                Log::warning('AI source failed; skipping it.', [
                    'provider' => $provider::class,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->interleave($groups, $limit);
    }

    /**
     * Round-robin over the priority order: first hit of the first non-empty
     * type, then the second type, and so on, until the limit is reached.
     *
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @return list<array<string, mixed>>
     */
    private function interleave(array $groups, int $limit): array
    {
        $ordered = [];

        foreach (self::TYPES as $type) {
            if (! empty($groups[$type])) {
                $ordered[$type] = array_values($groups[$type]);
            }
        }

        $sources = [];
        $seen = [];

        for ($index = 0; $index < self::PER_TYPE_LIMIT && count($sources) < $limit; $index++) {
            foreach ($ordered as $type => $hits) {
                if (! isset($hits[$index])) {
                    continue;
                }

                $source = $hits[$index];
                $key = $type . '#' . ($source['id'] ?? '');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $sources[] = $source;

                if (count($sources) >= $limit) {
                    break;
                }
            }
        }

        return $sources;
    }
}
