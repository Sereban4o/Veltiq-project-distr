<?php

namespace App\Observers;

use App\Models\Issue;
use App\Services\Webhooks\WebhookDispatcher;

/**
 * Emits `issue.*` webhook events. The dispatcher is a no-op when no webhook is
 * subscribed, so this observer adds no overhead without integrations.
 */
class IssueWebhookObserver
{
    public function __construct(private readonly WebhookDispatcher $dispatcher)
    {
    }

    public function created(Issue $issue): void
    {
        $this->emit('issue.created', $issue);
    }

    public function updated(Issue $issue): void
    {
        $this->emit('issue.updated', $issue);
    }

    public function deleted(Issue $issue): void
    {
        $this->emit('issue.deleted', $issue);
    }

    private function emit(string $event, Issue $issue): void
    {
        $this->dispatcher->emit($event, [
            'id' => (int) $issue->id,
            'key' => $issue->key,
            'summary' => $issue->summary,
            'project_id' => (int) $issue->project_id,
            'status_id' => $issue->status_id ? (int) $issue->status_id : null,
            'assigned_to' => $issue->assigned_to ? (int) $issue->assigned_to : null,
        ]);
    }
}
