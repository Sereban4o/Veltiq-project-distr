<?php

namespace App\Jobs;

use App\Mail\NotificationDigestMail;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationDigest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $notificationIds
     */
    public function __construct(
        public int $userId,
        public array $notificationIds,
        public string $frequency = 'daily',
    ) {
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $notifications = Notification::query()
            ->whereIn('id', $this->notificationIds)
            ->orderBy('created_at')
            ->get();

        if ($notifications->isEmpty()) {
            return;
        }

        try {
            Mail::to($user->email)->send(new NotificationDigestMail($user, $notifications, $this->frequency));
        } catch (Throwable $e) {
            Log::warning('Notification digest send failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Advance the digest window so the same notifications are not re-sent.
        NotificationSetting::query()
            ->where('user_id', $user->id)
            ->updateQuietly(['last_digest_sent_at' => now()]);
    }
}
