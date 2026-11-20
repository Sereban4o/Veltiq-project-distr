<?php

namespace App\Support\Notifications;

/**
 * Delivery channels. Values match the columns of the notification_preferences
 * table, so the enum can be used as a direct column key.
 */
enum NotificationChannel: string
{
    /**
     * The in-app feed.
     *
     * Always recorded: the `notifications` table is the delivery ledger that
     * push and the email digest read from, so this channel cannot be switched
     * off. The `in_app` preference column is stored for UI completeness and is
     * reported as always true.
     */
    case InApp = 'in_app';
    case Email = 'email';
    case Push = 'websocket';

    public function label(): string
    {
        return match ($this) {
            self::InApp => 'В приложении',
            self::Email => 'Email (дайджест)',
            self::Push => 'Push (WebSocket + ntfy)',
        };
    }

    public function isPush(): bool
    {
        return $this === self::Push;
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
