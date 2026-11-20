<?php

namespace App\Services\Tasksbase;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates an in-app notification.
 *
 * The `notifications` row is always created — it is the delivery ledger used by
 * the in-app feed, the realtime broadcast, push and the email digest, so the
 * `in_app` preference is informational only (the UI shows it as always on).
 * The `websocket` and `email` preferences are enforced by the delivery side
 * (observer push / digest service).
 *
 * Delivery is centralised in {@see \App\Observers\NotificationObserver}: it
 * broadcasts the realtime event, sends the ntfy push (honouring the user's
 * channel preferences) and mirrors the notification to 1С. Creating a
 * notification by any other route (e.g. the 1С inbound sync) therefore behaves
 * exactly the same, without duplicating deliveries.
 */
class NotificationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function notify(
        int $userId,
        string $type,
        ?Model $subject = null,
        array $data = [],
        ?int $actorId = null,
        ?string $pushTitle = null,
        ?string $pushBody = null,
    ): Notification {
        // Push wording is carried inside `data`; the observer reads these keys.
        if ($pushTitle !== null) {
            $data['push_title'] = $pushTitle;
        }
        if ($pushBody !== null) {
            $data['push_body'] = $pushBody;
        }

        return Notification::create([
            'user_id' => $userId,
            'actor_id' => $actorId,
            'type' => $type,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'data' => $data,
        ]);
    }

    /**
     * Send the same (subject-less) notification to many users, e.g. a system
     * announcement. Returns the number of notifications created.
     *
     * @param  iterable<int|string>  $userIds
     * @param  array<string, mixed>  $data
     */
    public function broadcast(
        iterable $userIds,
        string $type,
        array $data = [],
        ?int $actorId = null,
        ?string $pushTitle = null,
        ?string $pushBody = null,
    ): int {
        $count = 0;

        foreach ($userIds as $userId) {
            $this->notify((int) $userId, $type, null, $data, $actorId, $pushTitle, $pushBody);
            $count++;
        }

        return $count;
    }
}
