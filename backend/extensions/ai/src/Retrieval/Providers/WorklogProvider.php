<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Retrieval\Concerns\MatchesKeywords;
use App\Models\IssueWorklog;
use App\Models\User;

/**
 * Timesheet entries. Visibility mirrors the timesheet section: employees see
 * their own hours, administrators see everyone's. The project of the logged
 * issue must be visible to the acting user as well.
 */
final class WorklogProvider implements AiSourceProvider
{
    use MatchesKeywords;

    private const PAST_DAYS = 30;

    /** "How many hours did I log this week" shares no words with a comment. */
    private const INTENT = '/часов|часа|часы|часу|час\b|табел|трудозатрат|ворклог|списал|списано|отработал|переработ/iu';

    public function types(): array
    {
        return ['worklogs'];
    }

    public function extension(): ?string
    {
        return 'timesheet';
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        $worklogs = IssueWorklog::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->whereBetween('started_at', [now()->subDays(self::PAST_DAYS)->startOfDay(), now()->addDay()])
            ->whereHas('issue', fn ($issue) => $issue->whereHas('project', fn ($project) => $project->visibleTo($user)))
            ->when(! $this->matchesIntent($query, self::INTENT), function ($q) use ($query): void {
                $q->where(function ($group) use ($query): void {
                    $this->whereMatchesTokens($group, ['comment'], $query);
                    $group->orWhereHas('issue', fn ($issue) => $this->whereMatchesTokens($issue, ['summary'], $query));
                });
            })
            ->with(['user:id,first_name,last_name', 'issue:id,key,summary,project_id', 'issue.project:id,key,name'])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        return ['worklogs' => $worklogs->map(function (IssueWorklog $worklog): array {
            $issue = $worklog->issue;
            $author = trim(($worklog->user?->first_name ?? '') . ' ' . ($worklog->user?->last_name ?? ''));
            $hours = number_format((float) $worklog->hours, 2, '.', '');

            $snippet = implode(' · ', array_filter([
                optional($worklog->started_at)->format('d.m.Y'),
                $hours . ' ч',
                $author,
                trim((string) $worklog->comment),
            ]));

            return AiSource::make(
                'worklogs',
                (int) $worklog->id,
                trim(($issue?->key ?? '') . ' ' . ($issue?->summary ?? '')),
                '/issues/' . (int) $worklog->issue_id,
                $snippet,
                [
                    'issue_id' => (int) $worklog->issue_id,
                    'issue_key' => $issue?->key,
                    'hours' => (float) $worklog->hours,
                    'started_at' => optional($worklog->started_at)->toIso8601String(),
                    'user_id' => $worklog->user_id ? (int) $worklog->user_id : null,
                ],
            );
        })->all()];
    }
}
