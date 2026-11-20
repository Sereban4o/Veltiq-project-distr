<?php

namespace App\Jobs;

use App\Services\NtfyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a single ntfy push from the queue.
 *
 * Push used to be sent synchronously inside the request (and inside a single
 * fan-out job for chats), which made mass sends slow: each recipient cost an
 * HTTP round-trip. One queued job per recipient keeps the web request instant
 * and lets Horizon scale the delivery.
 */
class SendNtfyNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $tags = [],
        public array $meta = [],
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NtfyService $ntfy): void
    {
        $ntfy->notifyUser($this->userId, $this->title, $this->body, $this->tags, $this->meta);
    }
}
