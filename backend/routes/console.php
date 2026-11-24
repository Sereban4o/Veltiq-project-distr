<?php

use App\Extensions\Storage\Jobs\PruneStorageAudit;
use App\Extensions\Storage\Jobs\PruneStorageTrash;
use App\Extensions\Storage\Jobs\PruneStorageUploads;
use App\Extensions\Storage\Jobs\PruneStorageVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes / Scheduler
|--------------------------------------------------------------------------
|
| Heavy or long-running tasks pass an explicit `withoutOverlapping` expiry (in
| minutes). The default lock lives for 24 hours, so a crashed task would keep
| the next runs blocked for a whole day.
|
*/

// Heartbeat for the scheduler itself. If `schedule:run`/`schedule:work` stops,
// the timestamp goes stale and `system:scheduler-health` (used by the
// container healthcheck and external monitoring) reports a failure.
Schedule::call(fn () => Cache::forever('scheduler:heartbeat', now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');

Schedule::command('one-c:pull-issues')->hourly()->withoutOverlapping(120);
Schedule::command('sprints:snapshot')->dailyAt('23:50')->withoutOverlapping(60);
// Calendar feeds are part of the `calendar` module: skip while it is disabled.
Schedule::command('calendar:sync-feeds')->hourly()->withoutOverlapping(60)
    ->when(fn () => app(\App\Services\Extensions\ExtensionRegistry::class)->isEnabled('calendar'));
Schedule::command('storage:previews --missing --limit=200')->everyThirtyMinutes()->withoutOverlapping(60)->when(fn () => app(\App\Services\Extensions\ExtensionRegistry::class)->isEnabled('storage'));
Schedule::job(new PruneStorageVersions())->dailyAt('03:00')->withoutOverlapping(60)->when(fn () => app(\App\Services\Extensions\ExtensionRegistry::class)->isEnabled('storage'));
Schedule::job(new PruneStorageTrash())->dailyAt('03:10')->withoutOverlapping(60)->when(fn () => app(\App\Services\Extensions\ExtensionRegistry::class)->isEnabled('storage'));
Schedule::job(new PruneStorageUploads())->hourly()->withoutOverlapping(60)->when(fn () => app(\App\Services\Extensions\ExtensionRegistry::class)->isEnabled('storage'));
Schedule::job(new PruneStorageAudit())->dailyAt('03:20')->withoutOverlapping(60)->when(fn () => app(\App\Services\Extensions\ExtensionRegistry::class)->isEnabled('storage'));
Schedule::command('audit:prune')->dailyAt('03:30')->withoutOverlapping(60);
Schedule::command('login-logs:prune')->dailyAt('03:40')->withoutOverlapping(60);
Schedule::command('notifications:prune')->dailyAt('03:50')->withoutOverlapping(60);

// Email digests (skipped automatically when disabled via config).
Schedule::command('notifications:send-digests --frequency=daily')
    ->dailyAt(config('notifications.digest.daily_at', '08:00'))
    ->withoutOverlapping(60)
    ->when(fn () => (bool) config('notifications.digest.enabled', true));
Schedule::command('notifications:send-digests --frequency=weekly')
    ->weeklyOn(1, config('notifications.digest.daily_at', '08:00'))
    ->withoutOverlapping(60)
    ->when(fn () => (bool) config('notifications.digest.enabled', true));

Schedule::command('queue:prune-failed --hours=168')->weekly()->withoutOverlapping(60);
Schedule::command('queue:prune-batches --hours=24')->daily()->withoutOverlapping(60);

// Safety net: also rebuild the Meilisearch indexes nightly (catches any
// index drift that model events alone do not cover).
Schedule::command('search:reindex')->dailyAt('04:10')->withoutOverlapping(180);

// After a deploy the source fingerprint changes, so rebuild the indexes right
// away instead of waiting for the nightly run. Cheap no-op when unchanged.
Schedule::command('search:reindex-if-stale')->everyFiveMinutes()->withoutOverlapping(120);

// Horizon metrics snapshots — without this the dashboard graphs stay empty.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping(10);

// Report the active-user count to the compiled core, which forwards it in the
// license heartbeat (docs/12-licensing.md). No-op when the core is disabled.
Schedule::command('license:usage')->hourly()->withoutOverlapping(30);
