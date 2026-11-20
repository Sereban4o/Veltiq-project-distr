<?php

namespace App\Support\Tasksbase;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Extracts and resolves `@email` / `@name` mentions from comment bodies.
 */
class MentionParser
{
    /**
     * @return array<int, string> lowercased unique tokens (without the @)
     */
    public static function extract(?string $html): array
    {
        $text = strip_tags((string) $html);

        preg_match_all(
            '/(?<![\w.])@([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}|[A-Za-z0-9._\-]{2,32})/u',
            $text,
            $matches,
        );

        $tokens = array_map(static fn (string $t): string => mb_strtolower(trim($t)), $matches[1] ?? []);

        return array_values(array_unique(array_filter($tokens, static fn (string $t): bool => $t !== '')));
    }

    /**
     * Resolve tokens to active users; when a project is given, only users who can view it.
     *
     * @param  array<int, string>  $tokens
     * @return Collection<int, User>
     */
    public static function resolve(array $tokens, ?Project $project = null): Collection
    {
        if ($tokens === []) {
            return collect();
        }

        $users = User::query()
            ->where('status', 'active')
            ->where(function ($query) use ($tokens) {
                foreach ($tokens as $token) {
                    if (str_contains($token, '@')) {
                        $query->orWhereRaw('lower(email) = ?', [$token]);

                        continue;
                    }

                    $query->orWhereRaw('lower(first_name) = ?', [$token])
                        ->orWhereRaw('lower(last_name) = ?', [$token])
                        ->orWhereRaw("lower(first_name || ' ' || coalesce(last_name, '')) = ?", [$token])
                        ->orWhereRaw('lower(email) like ?', [$token . '@%']);
                }
            })
            ->get();

        if ($project !== null) {
            $users = $users->filter(static fn (User $user): bool => $project->canView($user))->values();
        }

        return $users;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public static function stripSelf(Collection $users, ?int $selfId): Collection
    {
        return $users->reject(static fn (User $user): bool => (int) $user->id === (int) $selfId)->values();
    }
}
