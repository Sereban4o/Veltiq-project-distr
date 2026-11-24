<?php

namespace App\Services\Search;

use App\Models\File;
use App\Models\Issue;
use App\Models\MailMessage;
use App\Models\Page;
use App\Models\User;
use App\Services\Core\CoreClient;
use App\Services\Knowledgebase\PageAcl;
use App\Support\SearchText;
use Illuminate\Support\Facades\Log;

/**
 * Permission-aware global search across issues, knowledgebase pages, files,
 * mail and chat.
 *
 * Meilisearch (Laravel Scout) is used as a candidate generator + ranker, while
 * Eloquent scopes remain the authority: every candidate is re-checked against
 * the database with the acting user's visibility rules before it is returned.
 * Chat never reaches Meilisearch (bodies are encrypted at rest) and is served
 * from the blind index via {@see ChatMessageSearch}.
 */
class GlobalSearchService
{
    public const TYPES = ['issues', 'pages', 'files', 'mail', 'chat'];

    public function __construct(
        private readonly ChatMessageSearch $chatSearch,
        private readonly CoreClient $core,
    ) {
    }

    /**
     * @param  list<string>  $types
     * @return array<string, array{total:int, hits:list<array>}>
     */
    public function search(User $user, string $query, array $types, int $perPage, int $page): array
    {
        // Meili ranks before we filter, so fetch a wider window of candidates
        // than a single page and document that per-type totals are approximate.
        $window = $this->core->searchWindow(max(1, $perPage), max(1, $page))
            ?? max($perPage * $page * 3, 100);

        $groups = [];

        foreach ($types as $type) {
            $groups[$type] = match ($type) {
                'issues' => $this->issues($user, $query, $window),
                'pages' => $this->pages($user, $query, $window),
                'files' => $this->files($user, $query, $window),
                'mail' => $this->mail($user, $query, $window),
                'chat' => $this->chat($user, $query, $window),
                default => ['total' => 0, 'hits' => []],
            };
        }

        return $groups;
    }

    private function issues(User $user, string $query, int $window): array
    {
        $ids = $this->candidateIds(
            Issue::class,
            $query,
            $window,
            fn () => Issue::query()
                ->where(function ($q) use ($query) {
                    $q->whereLike('summary', "%{$query}%")
                        ->orWhereLike('description', "%{$query}%");
                })
                ->orderByDesc('updated_at')
                ->limit($window)
                ->pluck('id')
                ->all(),
        );

        if ($ids === []) {
            return ['total' => 0, 'hits' => []];
        }

        $issues = Issue::whereIn('id', $ids)
            ->whereHas('project', fn ($q) => $q->visibleTo($user))
            ->with('project:id,key,name,visibility')
            ->get()
            ->keyBy('id');

        $hits = [];

        foreach ($ids as $id) {
            $issue = $issues->get($id);
            if (! $issue) {
                continue;
            }

            $hits[] = [
                'id' => (int) $issue->id,
                'title' => trim($issue->key . ' ' . $issue->summary),
                'snippet' => $this->snippet(SearchText::plain($issue->description), $query),
                'url' => '/issues/' . (int) $issue->id,
                'meta' => [
                    'key' => $issue->key,
                    'project_id' => (int) $issue->project_id,
                    'project_key' => $issue->project?->key,
                    'status_id' => $issue->status_id ? (int) $issue->status_id : null,
                    'priority' => $issue->priority,
                ],
            ];
        }

        return ['total' => count($hits), 'hits' => $hits];
    }

    private function pages(User $user, string $query, int $window): array
    {
        $ids = $this->candidateIds(
            Page::class,
            $query,
            $window,
            fn () => Page::query()
                ->where('status', 'published')
                ->where(function ($q) use ($query) {
                    $q->whereLike('title', "%{$query}%")
                        ->orWhereLike('body', "%{$query}%");
                })
                ->orderByDesc('updated_at')
                ->limit($window)
                ->pluck('id')
                ->all(),
        );

        if ($ids === []) {
            return ['total' => 0, 'hits' => []];
        }

        $pages = Page::whereIn('id', $ids)
            ->whereHas('space', fn ($q) => $q->visibleTo($user))
            ->with('space:id,key,name,visibility')
            ->get()
            ->keyBy('id');

        $acls = [];
        $hits = [];

        foreach ($ids as $id) {
            $page = $pages->get($id);
            if (! $page || ! $page->space) {
                continue;
            }

            $acls[$page->space->id] ??= PageAcl::for($page->space, $user);

            if (! $acls[$page->space->id]->canView($page)) {
                continue;
            }

            $hits[] = [
                'id' => (int) $page->id,
                'title' => (string) $page->title,
                'snippet' => $this->snippet(Page::plainText($page->body), $query),
                'url' => '/knowledgebase/' . $page->space->key . '/pages/' . (int) $page->id,
                'meta' => [
                    'space_id' => (int) $page->space_id,
                    'space_key' => $page->space->key,
                    'space_name' => $page->space->name,
                    'status' => $page->status,
                    'updated_at' => optional($page->updated_at)->toIso8601String(),
                ],
            ];
        }

        return ['total' => count($hits), 'hits' => $hits];
    }

    private function files(User $user, string $query, int $window): array
    {
        $ids = $this->candidateIds(
            File::class,
            $query,
            $window,
            fn () => File::query()
                ->where(function ($q) use ($query) {
                    $q->whereLike('original_name', "%{$query}%")
                        ->orWhereLike('mime_type', "%{$query}%");
                })
                ->orderByDesc('created_at')
                ->limit($window)
                ->pluck('id')
                ->all(),
        );

        if ($ids === []) {
            return ['total' => 0, 'hits' => []];
        }

        // A file is visible when the user uploaded it, or it is attached to an
        // issue in a visible project / page in a visible space / message in a
        // chat the user belongs to.
        $files = File::whereIn('id', $ids)
            ->where(function ($q) use ($user) {
                $q->where('uploaded_by', $user->id)
                    ->orWhereHas('issueAttachments.issue.project', fn ($p) => $p->visibleTo($user))
                    ->orWhereHas('pageAttachments.page.space', fn ($s) => $s->visibleTo($user))
                    ->orWhereHas('messageFiles.message.chat.members', fn ($m) => $m->where('users.id', $user->id));
            })
            ->get()
            ->keyBy('id');

        $hits = [];

        foreach ($ids as $id) {
            $file = $files->get($id);
            if (! $file) {
                continue;
            }

            $hits[] = [
                'id' => (int) $file->id,
                'title' => (string) $file->original_name,
                'snippet' => (string) $file->mime_type,
                'url' => '/files?file=' . (int) $file->id,
                'meta' => [
                    'mime_type' => $file->mime_type,
                    'size' => $file->size ? (int) $file->size : null,
                    'uploaded_by' => $file->uploaded_by ? (int) $file->uploaded_by : null,
                    'created_at' => optional($file->created_at)->toIso8601String(),
                ],
            ];
        }

        return ['total' => count($hits), 'hits' => $hits];
    }

    private function mail(User $user, string $query, int $window): array
    {
        // Mail is strictly per-user; the user_id filter is never merged from input.
        $ids = $this->candidateIds(
            MailMessage::class,
            $query,
            $window,
            fn () => MailMessage::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($query) {
                    $q->whereLike('subject', "%{$query}%")
                        ->orWhereLike('body_text', "%{$query}%")
                        ->orWhereLike('preview', "%{$query}%");
                })
                ->orderByDesc('received_at')
                ->limit($window)
                ->pluck('id')
                ->all(),
        );

        if ($ids === []) {
            return ['total' => 0, 'hits' => []];
        }

        $messages = MailMessage::whereIn('id', $ids)
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('id');

        $hits = [];

        foreach ($ids as $id) {
            $message = $messages->get($id);
            if (! $message) {
                continue;
            }

            $hits[] = [
                'id' => (int) $message->id,
                'title' => (string) $message->subject,
                'snippet' => $this->snippet((string) ($message->body_text ?: $message->preview), $query),
                'url' => '/mail?message=' . (int) $message->id,
                'meta' => [
                    'folder' => $message->folder,
                    'from_name' => $message->from_name,
                    'from_email' => $message->from_email,
                    'is_read' => (bool) $message->is_read,
                    'received_at' => optional($message->received_at)->toIso8601String(),
                ],
            ];
        }

        return ['total' => count($hits), 'hits' => $hits];
    }

    private function chat(User $user, string $query, int $window): array
    {
        $hits = $this->chatSearch->hits($user, $query, $window);

        return ['total' => count($hits), 'hits' => $hits];
    }

    /**
     * Candidate ids ordered by engine relevance (Meili) or newest first (DB).
     *
     * @param  class-string  $modelClass
     * @param  callable():array<int>  $fallback
     * @return list<int>
     */
    private function candidateIds(string $modelClass, string $query, int $window, callable $fallback): array
    {
        if ($this->usesMeilisearch()) {
            try {
                return $modelClass::search($query)
                    ->take($window)
                    ->get()
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            } catch (\Throwable $e) {
                // Search engine unavailable: degrade to a database scan instead
                // of failing the whole request.
                Log::warning('Global search engine failed; falling back to database.', [
                    'model' => $modelClass,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return array_map('intval', $fallback());
    }

    private function usesMeilisearch(): bool
    {
        return config('scout.driver') === 'meilisearch' && (bool) config('scout.meilisearch.host');
    }

    private function snippet(string $text, string $query, int $length = 160): string
    {
        return $this->snippets([$text], $query, $length)[0] ?? '';
    }

    /**
     * Build snippets in one batch through the core service when available.
     *
     * @param  array<int, string>  $texts
     * @return array<int, string>
     */
    private function snippets(array $texts, string $query, int $length = 160): array
    {
        if ($texts === []) {
            return [];
        }

        $remote = $this->core->searchSnippets(array_values($texts), $query, $length);

        if ($remote !== null && count($remote) === count($texts)) {
            ksort($remote);

            return array_values($remote);
        }

        return array_map(fn (string $text): string => $this->localSnippet($text, $query, $length), $texts);
    }

    private function localSnippet(string $text, string $query, int $length = 160): string
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
