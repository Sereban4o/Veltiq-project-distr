<?php

namespace App\Services\Tasksbase;

use App\Models\Issue;
use App\Models\TaskAutomationRule;
use App\Services\Core\CoreClient;
use App\Support\Tasksbase\AuditWriter;

/**
 * Applies task automation rules when issues are created or change status.
 *
 * Actions are intentionally small and idempotent (assign, priority, notify);
 * they never recurse into further issue updates.
 */
class TaskAutomationEngine
{
    private const PRIORITIES = ['lowest', 'low', 'medium', 'high', 'highest'];

    public function __construct(
        private NotificationService $notifications,
        private CoreClient $core,
    ) {
    }

    public function issueCreated(Issue $issue): void
    {
        $this->apply($issue, 'issue_created');
    }

    public function statusChanged(Issue $issue, int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }

        $this->apply($issue, 'status_changed', ['from' => $from, 'to' => $to]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function apply(Issue $issue, string $trigger, array $context = []): void
    {
        $rules = TaskAutomationRule::query()
            ->where('is_active', true)
            ->where('trigger', $trigger)
            ->where(fn ($query) => $query->where('project_id', $issue->project_id)->orWhereNull('project_id'))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $dirty = false;

        foreach ($this->decide($rules, $issue, $context) as $match) {
            foreach ($match['actions'] as $action) {
                $dirty = $this->run($action, $issue) || $dirty;
            }

            AuditWriter::record($issue, 'automation_applied', [], [
                'rule_id' => (int) $match['rule_id'],
                'rule' => $match['name'],
                'trigger' => $trigger,
            ]);
        }

        if ($dirty) {
            $issue->saveQuietly();
        }
    }

    /**
     * Which rules fire: the compiled core service decides when available, the
     * local implementation is the fallback.
     *
     * @param  \Illuminate\Support\Collection<int, TaskAutomationRule>  $rules
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id: int, name: string, actions: array<int, array<string, mixed>>}>
     */
    private function decide($rules, Issue $issue, array $context): array
    {
        $snapshot = [
            'id' => (int) $issue->id,
            'project_id' => (int) $issue->project_id,
            'status_id' => (int) $issue->status_id,
            'priority' => (string) $issue->priority,
        ];

        $payload = $rules->map(fn (TaskAutomationRule $rule) => [
            'id' => (int) $rule->id,
            'project_id' => $rule->project_id !== null ? (int) $rule->project_id : null,
            'position' => (int) $rule->position,
            // Cast to objects: an empty PHP array encodes as `[]`, which is not a
            // JSON object the service expects for conditions/actions.
            'conditions' => (object) ($rule->conditions ?? []),
            'actions' => array_values(array_map(fn ($action) => (object) $action, $rule->actions ?? [])),
        ])->all();

        $remote = $this->core->automationMatches($payload, $snapshot, [
            'from' => (int) ($context['from'] ?? 0),
            'to' => (int) ($context['to'] ?? 0),
        ]);

        if ($remote === null) {
            return $this->decideLocally($rules, $issue, $context);
        }

        $byId = $rules->keyBy('id');
        $matches = [];

        foreach ($remote as $match) {
            $rule = $byId->get($match['rule_id']);

            if ($rule === null) {
                continue;
            }

            $matches[] = [
                'rule_id' => (int) $rule->id,
                'name' => (string) $rule->name,
                'actions' => $match['actions'],
            ];
        }

        return $matches;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TaskAutomationRule>  $rules
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id: int, name: string, actions: array<int, array<string, mixed>>}>
     */
    private function decideLocally($rules, Issue $issue, array $context): array
    {
        $matches = [];

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $issue, $context)) {
                continue;
            }

            $matches[] = [
                'rule_id' => (int) $rule->id,
                'name' => (string) $rule->name,
                'actions' => $rule->actions ?? [],
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function matches(TaskAutomationRule $rule, Issue $issue, array $context): bool
    {
        $conditions = $rule->conditions ?? [];

        if (isset($conditions['to_status_id']) && (int) $conditions['to_status_id'] !== (int) ($context['to'] ?? 0)) {
            return false;
        }

        return ! (isset($conditions['priority']) && $conditions['priority'] !== $issue->priority)

        ;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return bool Whether the issue was modified.
     */
    private function run(array $action, Issue $issue): bool
    {
        $type = $action['type'] ?? null;

        if ($type === 'assign') {
            $userId = (int) ($action['user_id'] ?? 0);
            if ($userId > 0 && (int) $issue->assigned_to !== $userId) {
                $issue->assigned_to = $userId;

                return true;
            }

            return false;
        }

        if ($type === 'set_priority') {
            $priority = $action['priority'] ?? null;
            if (in_array($priority, self::PRIORITIES, true) && $issue->priority !== $priority) {
                $issue->priority = $priority;

                return true;
            }

            return false;
        }

        if ($type === 'notify') {
            $userId = (int) ($action['user_id'] ?? $issue->assigned_to ?? $issue->reporter_id ?? 0);
            if ($userId > 0) {
                $message = (string) ($action['message'] ?? "Задача {$issue->key}: {$issue->summary}");

                $this->notifications->notify(
                    $userId,
                    'task_automation',
                    $issue,
                    [
                        'issue_id' => (int) $issue->id,
                        'issue_key' => $issue->key,
                        'summary' => $issue->summary,
                        'message' => $message,
                        'url' => '/issues/' . $issue->id,
                    ],
                    null,
                    (string) ($action['title'] ?? 'Автоматизация'),
                    $message,
                );
            }
        }

        return false;
    }
}
