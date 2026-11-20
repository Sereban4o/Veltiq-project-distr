<?php

namespace App\Observers;

use App\Events\NotificationCreated;
use App\Jobs\PushNotificationTo1C;
use App\Jobs\SendNtfyNotification;
use App\Models\Notification;
use App\Services\Tasksbase\NotificationPreferenceResolver;
use App\Support\Notifications\NotificationChannel;

/**
 * Single delivery point for every notification, however it was created:
 * realtime (Reverb) + optional ntfy push + 1С mirror.
 */
class NotificationObserver
{
    public function __construct(
        private NotificationPreferenceResolver $preferences,
    ) {
    }

    public function created(Notification $notification): void
    {
        // Realtime: the SPA listens for ".notification.created" on user.{id}.
        event(new NotificationCreated($notification));

        $data = (array) ($notification->data ?? []);
        $summary = $data['summary'] ?? $data['message'] ?? $notification->type ?? 'Уведомление';

        // Push (WebSocket is the same event above; ntfy is the OS-level push).
        $pushEnabled = $this->preferences->enabled(
            (int) $notification->user_id,
            (string) $notification->type,
            NotificationChannel::Push,
        );

        if ($pushEnabled) {
            SendNtfyNotification::dispatch(
                (int) $notification->user_id,
                (string) ($data['push_title'] ?? 'Tasksbase'),
                (string) ($data['push_body'] ?? $summary),
            );
        }

        // Mirror the notification to 1С when the integration is enabled.
        if (config('one_c.enabled')) {
            PushNotificationTo1C::dispatch($notification);
        }
    }
}
