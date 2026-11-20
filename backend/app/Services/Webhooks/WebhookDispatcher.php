<?php

namespace App\Services\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fan-out of domain events to configured webhooks. Delivery is queued; the
 * payload is signed with HMAC-SHA256 (`X-Veltiq-Signature`) when a secret is set.
 */
class WebhookDispatcher
{
    /** Events an integration may subscribe to. */
    public const EVENTS = [
        'issue.created',
        'issue.updated',
        'issue.deleted',
    ];

    /**
     * Queue delivery of an event to every interested active webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public function emit(string $event, array $data): void
    {
        $webhooks = Webhook::query()->where('is_active', true)->get();

        foreach ($webhooks as $webhook) {
            if (! $webhook->subscribesTo($event)) {
                continue;
            }

            DeliverWebhook::dispatch($webhook->id, $event, $data);
        }
    }

    /**
     * Perform the HTTP delivery and record the outcome.
     *
     * @param  array<string, mixed>  $data
     */
    public function deliver(Webhook $webhook, string $event, array $data): void
    {
        $body = json_encode([
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Veltiq-Event' => $event,
        ];

        if (! empty($webhook->secret)) {
            $headers['X-Veltiq-Signature'] = 'sha256=' . hash_hmac('sha256', (string) $body, (string) $webhook->secret);
        }

        $response = Http::timeout(10)
            ->withHeaders($headers)
            ->withBody((string) $body, 'application/json')
            ->post($webhook->url);

        $webhook->forceFill([
            'last_status' => $response->status(),
            'last_called_at' => now(),
        ])->save();

        if (! $response->successful()) {
            $webhook->increment('failures');

            throw new RuntimeException('Webhook HTTP ' . $response->status());
        }

        if ($webhook->failures > 0) {
            $webhook->forceFill(['failures' => 0])->save();
        }
    }
}
