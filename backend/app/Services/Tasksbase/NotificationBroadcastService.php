<?php

namespace App\Services\Tasksbase;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared core of the admin broadcast UI and the `notifications:broadcast`
 * command: resolves recipients and sends the same system notification to each.
 */
class NotificationBroadcastService
{
    public function __construct(
        private NotificationService $notifications,
    ) {
    }

    /**
     * @return Builder<User>
     */
    public function recipients(?string $role = null, bool $includeInactive = false): Builder
    {
        return User::query()
            ->select('id')
            ->when(! $includeInactive, fn ($q) => $q->where('status', 'active'))
            ->when($role, fn ($q, $name) => $q->whereHas('role', fn ($r) => $r->where('name', $name)));
    }

    public function count(?string $role = null, bool $includeInactive = false): int
    {
        return $this->recipients($role, $includeInactive)->count();
    }

    /**
     * @return int number of notifications created
     */
    public function send(
        string $message,
        string $type = 'system',
        ?string $title = null,
        ?string $role = null,
        bool $includeInactive = false,
        int $chunk = 200,
    ): int {
        $chunk = max(1, $chunk);
        $sent = 0;

        $this->recipients($role, $includeInactive)
            ->orderBy('id')
            ->chunkById($chunk, function ($users) use (&$sent, $message, $type, $title) {
                $sent += $this->notifications->broadcast(
                    $users->pluck('id')->all(),
                    $type,
                    ['summary' => $message],
                    null,
                    $title ?? 'Tasksbase',
                    $message,
                );
            });

        return $sent;
    }
}
