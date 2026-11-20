<?php

namespace App\Services\Crm;

use App\Models\Crm\Activity;
use App\Models\Crm\CrmAutomationRule;
use App\Models\Crm\Deal;
use App\Services\Core\CoreClient;
use App\Services\Tasksbase\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Evaluates configurable CRM automation rules on deal events and performs
 * their actions (create a task or send a notification).
 *
 * Failures are swallowed and logged so a broken rule can never break deal CRUD.
 */
class CrmAutomationService
{
    public function __construct(
        private NotificationService $notifications,
        private CoreClient $core,
    ) {
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(string $trigger, Deal $deal, array $context = []): void
    {
        try {
            $rules = CrmAutomationRule::query()
                ->where('is_active', true)
                ->where('trigger', $trigger)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            if ($rules->isEmpty()) {
                return;
            }

            foreach ($this->decide($rules, $deal, $context) as $match) {
                $rule = $rules->firstWhere('id', $match['rule_id']);
                if ($rule === null) {
                    continue;
                }

                match ($match['action']) {
                    'create_task' => $this->createTask($rule, $deal),
                    'send_notification' => $this->sendNotification($rule, $deal),
                    default => null,
                };
            }
        } catch (Throwable $e) {
            Log::warning('CRM automation failed', [
                'trigger' => $trigger,
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Which rules fire: the core service decides when available, the local
     * implementation is the fallback.
     *
     * @param  \Illuminate\Support\Collection<int, CrmAutomationRule>  $rules
     * @param  array<string, mixed>  $context
     * @return array<int, array{rule_id:int,action:string}>
     */
    private function decide($rules, Deal $deal, array $context): array
    {
        $snapshot = [
            'id' => (int) $deal->id,
            'stage_id' => (int) $deal->stage_id,
            'status' => (string) $deal->status,
            'amount' => (float) $deal->amount,
            'company_id' => $deal->company_id !== null ? (int) $deal->company_id : null,
        ];

        $payload = $rules->map(fn (CrmAutomationRule $rule) => [
            'id' => (int) $rule->id,
            'position' => (int) $rule->position,
            'conditions' => (object) ($rule->conditions ?? []),
            'action' => (string) $rule->action,
            'action_payload' => (object) ($rule->action_payload ?? []),
        ])->all();

        $remote = $this->core->crmAutomationMatches($payload, $snapshot, [
            'to_stage_id' => isset($context['to_stage_id']) ? (int) $context['to_stage_id'] : null,
        ]);

        if ($remote === null) {
            $matches = [];
            foreach ($rules as $rule) {
                if ($this->matches($rule, $deal, $context)) {
                    $matches[] = ['rule_id' => (int) $rule->id, 'action' => (string) $rule->action];
                }
            }

            return $matches;
        }

        return array_map(fn (array $match) => [
            'rule_id' => $match['rule_id'],
            'action' => $match['action'],
        ], $remote);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function matches(CrmAutomationRule $rule, Deal $deal, array $context): bool
    {
        $conditions = $rule->conditions ?? [];

        if (isset($conditions['stage_id'])) {
            $target = $context['to_stage_id'] ?? $deal->stage_id;
            if ((int) $conditions['stage_id'] !== (int) $target) {
                return false;
            }
        }

        if (isset($conditions['status']) && (string) $conditions['status'] !== (string) $deal->status) {
            return false;
        }

        if (isset($conditions['min_amount']) && (float) $deal->amount < (float) $conditions['min_amount']) {
            return false;
        }

        if (isset($conditions['max_amount']) && (float) $deal->amount > (float) $conditions['max_amount']) {
            return false;
        }

        return ! (isset($conditions['company_id']) && (int) $conditions['company_id'] !== (int) $deal->company_id)

        ;
    }

    private function createTask(CrmAutomationRule $rule, Deal $deal): void
    {
        $payload = $rule->action_payload ?? [];
        $dueInDays = isset($payload['due_in_days']) ? (int) $payload['due_in_days'] : 1;

        Activity::create([
            'type' => 'task',
            'subject' => (string) ($payload['subject'] ?? 'Задача по сделке: ' . $deal->title),
            'description' => $payload['description'] ?? null,
            'due_at' => now()->addDays(max(0, $dueInDays)),
            'is_completed' => false,
            'deal_id' => $deal->id,
            'contact_id' => $deal->contact_id,
            'company_id' => $deal->company_id,
            'owner_id' => $payload['user_id'] ?? $deal->owner_id ?? Auth::id(),
        ]);
    }

    private function sendNotification(CrmAutomationRule $rule, Deal $deal): void
    {
        $payload = $rule->action_payload ?? [];
        $userId = (int) ($payload['user_id'] ?? $deal->owner_id ?? Auth::id());

        if ($userId === 0) {
            return;
        }

        $title = (string) ($payload['title'] ?? 'Сделка: ' . $deal->title);
        $body = (string) ($payload['body'] ?? $rule->name);

        $this->notifications->notify(
            $userId,
            'crm_automation',
            $deal,
            ['title' => $title, 'body' => $body, 'rule' => $rule->name],
            Auth::id(),
            $title,
            $body,
        );
    }
}
