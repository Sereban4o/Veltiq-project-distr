<?php

namespace App\Providers;

use App\Listeners\NotifyFailedJob;
use App\Listeners\NotifyLongWait;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Queue\Events\JobFailed;
use Laravel\Horizon\Events\LongWaitDetected;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // Events for WebSocket broadcasting
        JobFailed::class => [
            NotifyFailedJob::class,
        ],

        // Queue wait-time thresholds (config/horizon.php `waits`).
        LongWaitDetected::class => [
            NotifyLongWait::class,
        ],
    ];

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url') . "/reset-password?token={$token}&email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}
