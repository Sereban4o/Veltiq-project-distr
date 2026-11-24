<?php

/*
| Timesheet module routes: timesheet report and issue worklogs/timer.
| Gated by `extension.enabled:timesheet`.
*/

use App\Extensions\Timesheet\Http\Controllers\IssueWorklogController;
use App\Extensions\Timesheet\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('tasksbase')->middleware(['extension.enabled:timesheet', 'auth:sanctum'])->group(function () {
        Route::get('/timesheet', [TimesheetController::class, 'index']);
        Route::get('/timesheet/export', [TimesheetController::class, 'export']);

        Route::get('/issues/{id}/worklogs', [IssueWorklogController::class, 'index']);
        Route::post('/issues/{id}/worklogs', [IssueWorklogController::class, 'store']);
        Route::patch('/issues/{id}/worklogs/{worklogId}', [IssueWorklogController::class, 'update'])->whereNumber('worklogId');
        Route::delete('/issues/{id}/worklogs/{worklogId}', [IssueWorklogController::class, 'destroy'])->whereNumber('worklogId');
        Route::get('/issues/{id}/timer', [IssueWorklogController::class, 'running']);
        Route::post('/issues/{id}/timer/start', [IssueWorklogController::class, 'startTimer']);
        Route::post('/issues/{id}/timer/stop', [IssueWorklogController::class, 'stopTimer']);
    });
});
