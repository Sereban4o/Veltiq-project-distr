<?php

namespace App\Services\Tasksbase;

use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Support\Notifications\NotificationChannel;
use App\Support\Notifications\NotificationType;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds per-user notification digests for the configured frequency window.
 */
class NotificationDigestService
{
    public function __construct(
        private NotificationPreferenceResolver $preferences,
    ) {
    }

    /**
     * Collect the digest payload for every user subscribed to the frequency.
     *
     * @return array<int, array{user: User, notifications: Collection<int, Notification>}>
     */
    public function collect(string $frequency, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $fallbackWindow = $frequency === 'weekly' ? $now->copy()->subWeek() : $now->copy()->subDay();

        $digests = [];

        NotificationSetting::query()
            ->where('digest_frequency', $frequency)
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->with('user')
            ->chunkById(200, function ($settings) use (&$digests, $fallbackWindow, $now) {
                foreach ($settings as $setting) {
                    /** @var User|null $user */
                    $user = $setting->user;
                    if ($user === null) {
                        continue;
                    }

                    $since = $setting->last_digest_sent_at ?? $fallbackWindow;

                    $notifications = Notification::query()
                        ->where('user_id', $setting->user_id)
                        ->where('created_at', '>', $since)
                        ->where('created_at', '<=', $now)
                        ->orderBy('created_at')
                        ->get()
                        ->filter(fn (Notification $notification) => $this->preferences->enabled(
                            (int) $setting->user_id,
                            $notification->type,
                            NotificationChannel::Email,
                        ))
                        ->values();

                    if ($notifications->isEmpty()) {
                        continue;
                    }

                    $digests[] = [
                        'user' => $user,
                        'notifications' => $notifications,
                    ];
                }
            });

        return $digests;
    }

    /**
     * @return array{users: int, notifications: int}
     */
    public function send(string $frequency, bool $dryRun = false): array
    {
        $users = 0;
        $notifications = 0;

        foreach ($this->collect($frequency) as $digest) {
            $users++;
            $notifications += $digest['notifications']->count();

            if ($dryRun) {
                continue;
            }

            \App\Jobs\SendNotificationDigest::dispatch(
                $digest['user']->getKey(),
                $digest['notifications']->pluck('id')->all(),
                $frequency,
            );
        }

        return ['users' => $users, 'notifications' => $notifications];
    }

    public function label(string $frequency): string
    {
        return $frequency === 'weekly' ? 'Недельный' : 'Ежедневный';
    }

    /**
     * @return array<int, string>
     */
    public static function typeLabels(): array
    {
        $labels = [];
        foreach (NotificationType::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }
}
