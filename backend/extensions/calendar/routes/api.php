<?php

/*
| Calendar module routes.
|
| Owned by the module: mounted by App\Extensions\ExtensionServiceProvider, gated by
| `extension.enabled:calendar` so disabling the module closes its whole HTTP surface
| (docs/11-extensions.md). The `api` middleware group restores the shared stack
| (throttle, bundle integrity check, license gate); `api/v1` keeps the original
| public paths.
*/

use App\Extensions\Calendar\Http\Controllers\CalendarController;
use App\Extensions\Calendar\Http\Controllers\CalendarEventLinkController;
use App\Extensions\Calendar\Http\Controllers\CalendarFeedController;
use App\Extensions\Calendar\Http\Controllers\CalendarIcsController;
use App\Extensions\Calendar\Http\Controllers\CalendarParticipantController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('calendar')->middleware('extension.enabled:calendar')->group(function () {
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/', [CalendarController::class, 'index']);

            Route::get('/events', [CalendarController::class, 'indexEvents']);
            Route::get('/free-slots', [CalendarController::class, 'freeSlots']);
            Route::post('/events', [CalendarController::class, 'store']);
            Route::post('/events/recurrence-preview', [CalendarController::class, 'recurrencePreview']);
            Route::get('/events/{event}', [CalendarController::class, 'show'])->whereNumber('event');
            Route::patch('/events/{event}', [CalendarController::class, 'update'])->whereNumber('event');
            Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->whereNumber('event');

            Route::post('/events/{event}/participants', [CalendarParticipantController::class, 'store'])->whereNumber('event');
            Route::delete('/events/{event}/participants/{attendee}', [CalendarParticipantController::class, 'destroy'])
                ->whereNumber('event')->whereNumber('attendee');
            Route::post('/events/{event}/rsvp', [CalendarParticipantController::class, 'rsvp'])->whereNumber('event');

            Route::post('/events/{event}/links', [CalendarEventLinkController::class, 'store'])->whereNumber('event');
            Route::delete('/events/{event}/links/{link}', [CalendarEventLinkController::class, 'destroy'])
                ->whereNumber('event')->whereNumber('link');

            Route::get('/events/{event}/ics', [CalendarIcsController::class, 'exportEvent'])->whereNumber('event');
            Route::get('/ics/export', [CalendarIcsController::class, 'exportRange']);
            Route::post('/ics/import', [CalendarIcsController::class, 'import'])->middleware('throttle:10,1');

            Route::get('/feeds', [CalendarFeedController::class, 'index']);
            Route::post('/feeds', [CalendarFeedController::class, 'store'])->middleware('throttle:10,1');
            Route::patch('/feeds/{feed}', [CalendarFeedController::class, 'update'])->whereNumber('feed');
            Route::delete('/feeds/{feed}', [CalendarFeedController::class, 'destroy'])->whereNumber('feed');
            Route::post('/feeds/{feed}/sync', [CalendarFeedController::class, 'sync'])->whereNumber('feed')->middleware('throttle:10,1');
        });
    });
});
