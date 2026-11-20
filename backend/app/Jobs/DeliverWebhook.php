<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a single webhook payload. Retries with backoff on transport/HTTP
 * failure; the dispatcher records the last status and failure count.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $webhookId,
        public string $event,
        public array $data,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        $webhook = Webhook::find($this->webhookId);

        if ($webhook === null || ! $webhook->is_active) {
            return;
        }

        $dispatcher->deliver($webhook, $this->event, $this->data);
    }
}
