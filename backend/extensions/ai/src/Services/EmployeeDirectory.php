<?php

namespace App\Extensions\Ai\Services;

use App\Extensions\Ai\Retrieval\QueryTokens;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Employee lookup for the assistant: the persona line ("address the user by
 * name") and the compact "colleagues" block in the system prompt.
 *
 * Shares the cache key with AuthController::allUsers so the app keeps a single
 * user directory instead of two caches that can drift apart.
 */
final class EmployeeDirectory
{
    private const CACHE_KEY = 'users.all';

    private const CACHE_TTL = 60;

    /** @return Collection<int, User> */
    public function all(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => User::query()
            ->where('status', 'active')
            ->with('role:id,name')
            ->orderByRaw("LOWER(COALESCE(first_name, '')) ASC")
            ->orderByRaw("LOWER(COALESCE(last_name, '')) ASC")
            ->orderByRaw("LOWER(COALESCE(email, '')) ASC")
            ->get());
    }

    /**
     * Colleagues matching the question, best match first.
     *
     * @return Collection<int, User>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $tokens = QueryTokens::of($query);

        if ($tokens === [] || $limit < 1) {
            return collect();
        }

        return $this->all()
            ->map(fn (User $candidate): array => [
                'user' => $candidate,
                'score' => $this->score($candidate, $tokens),
            ])
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('user')
            ->values();
    }

    /**
     * The asker first, then colleagues matching the question, then the rest of
     * the directory up to the limit.
     *
     * @return array{items: list<array{id:int, name:string, role:?string, email:string}>, truncated: bool}
     */
    public function forPrompt(User $user, string $query, int $limit = 20): array
    {
        $all = $this->all();
        $items = [];
        $seen = [];

        foreach (collect([$user])->merge($this->search($query, $limit))->merge($all) as $candidate) {
            if (count($items) >= max(1, $limit)) {
                break;
            }

            if (isset($seen[$candidate->id])) {
                continue;
            }

            $seen[$candidate->id] = true;
            $items[] = [
                'id' => (int) $candidate->id,
                'name' => $this->fullName($candidate),
                'role' => $candidate->role?->name,
                'email' => (string) $candidate->email,
            ];
        }

        return [
            'items' => $items,
            'truncated' => $all->count() > count($items),
        ];
    }

    /** Name shown in the employee list. */
    public function fullName(User $user): string
    {
        $full = trim($user->full_name);

        if ($full !== '') {
            return $full;
        }

        return trim((string) $user->email) !== '' ? (string) $user->email : 'Сотрудник';
    }

    /** Name the assistant may address the user by. */
    public function displayName(User $user): string
    {
        $first = trim((string) $user->first_name);

        if ($first !== '') {
            return $first;
        }

        return $this->fullName($user);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function score(User $candidate, array $tokens): int
    {
        $haystack = mb_strtolower(trim(
            $candidate->full_name . ' ' . $candidate->email . ' ' . ($candidate->role?->name ?? ''),
        ));

        $score = 0;

        foreach ($tokens as $token) {
            if (mb_strpos($haystack, $token) !== false) {
                $score++;
            }
        }

        return $score;
    }
}
