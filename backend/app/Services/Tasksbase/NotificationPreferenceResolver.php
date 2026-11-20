<?php

namespace App\Services\Tasksbase;

use App\Models\NotificationPreference;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationType;

/**
 * Resolves a user's per-channel notification preferences, falling back to the
 * channel defaults declared in the NotificationType registry when no explicit
 * row exists for the user+type pair.
 */
class NotificationPreferenceResolver
{
    /** @var array<string, array{in_app: bool, email: bool, websocket: bool}> */
    private array $memo = [];

    /**
     * @return array{in_app: bool, email: bool, websocket: bool}
     */
    public function for(int $userId, string $type): array
    {
        $key = $userId . ':' . $type;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $row = NotificationPreference::query()
            ->where('user_id', $userId)
            ->where('type', $type)
            ->first();

        $definition = NotificationType::tryFromString($type);

        return $this->memo[$key] = [
            'in_app' => $row?->in_app ?? $definition->defaultsFor(NotificationChannel::InApp),
            'email' => $row?->email ?? $definition->defaultsFor(NotificationChannel::Email),
            'websocket' => $row?->websocket ?? $definition->defaultsFor(NotificationChannel::Push),
        ];
    }

    public function enabled(int $userId, string $type, NotificationChannel $channel): bool
    {
        return (bool) ($this->for($userId, $type)[$channel->value] ?? true);
    }

    public function flush(): void
    {
        $this->memo = [];
    }
}
