<?php

namespace App\Extensions\Ai\Services;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Task listing for questions the keyword search cannot answer.
 *
 * "Какие открытые задачи?" shares no words with the issue summaries, so the
 * retriever legitimately returns nothing and the model reports that no data
 * exists. For such listing questions the assistant takes the most recently
 * updated issues instead — always through the same project visibility scope
 * as the tasks section, so nothing a user may not open leaks into the prompt.
 */
final class RecentIssues
{
    public const MODE_ALL = 'all';

    public const MODE_OPEN = 'open';

    public const MODE_CLOSED = 'closed';

    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, int $limit, string $mode = self::MODE_ALL): array
    {
        $statusFilter = match ($mode) {
            self::MODE_OPEN => static fn (Builder $query) => $query->where('category', '!=', 'done'),
            self::MODE_CLOSED => static fn (Builder $query) => $query->where('category', 'done'),
            default => null,
        };

        $issues = Issue::query()
            ->whereHas('project', static fn (Builder $query) => $query->visibleTo($user))
            ->when(
                $statusFilter !== null,
                static fn (Builder $query) => $query->whereHas('status', $statusFilter),
            )
            ->with([
                'status:id,name,category',
                'project:id,key,name',
                'assignee:id,first_name,last_name',
            ])
            ->orderByDesc('updated_at')
            ->limit(max(1, $limit))
            ->get();

        $sources = [];

        foreach ($issues as $issue) {
            $sources[] = AiSource::make(
                'issues',
                (int) $issue->id,
                trim($issue->key . ' ' . $issue->summary),
                '/issues/' . (int) $issue->id,
                $this->snippet($issue),
                [
                    'key' => $issue->key,
                    'project_id' => (int) $issue->project_id,
                    'project_key' => $issue->project?->key,
                    'status_id' => $issue->status_id !== null ? (int) $issue->status_id : null,
                    'status' => $issue->status?->name,
                    'priority' => $issue->priority,
                    'listed' => true,
                ],
            );
        }

        return $sources;
    }

    /** Human-readable facts first: the model lists them instead of guessing. */
    private function snippet(Issue $issue): string
    {
        $parts = [];

        if ($issue->status?->name !== null && $issue->status->name !== '') {
            $parts[] = 'Статус: ' . $issue->status->name;
        }

        if ($issue->priority !== null && $issue->priority !== '') {
            $parts[] = 'Приоритет: ' . $issue->priority;
        }

        if ($issue->assignee !== null) {
            $name = trim($issue->assignee->first_name . ' ' . $issue->assignee->last_name);

            if ($name !== '') {
                $parts[] = 'Исполнитель: ' . $name;
            }
        }

        if ($issue->due_date !== null) {
            $parts[] = 'Срок: ' . $issue->due_date->format('Y-m-d');
        }

        $headline = implode('; ', $parts);
        $description = trim((string) $issue->description);

        if ($description === '') {
            return $headline;
        }

        return $headline === '' ? $description : $headline . '. ' . $description;
    }
}
