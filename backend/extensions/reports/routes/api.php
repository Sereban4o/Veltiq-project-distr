<?php

/*
| Reports module routes (task completion and workload reports).
| Gated by `extension.enabled:reports`.
*/

use App\Extensions\Reports\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('tasksbase/report')->middleware(['extension.enabled:reports', 'auth:sanctum'])->group(function () {
        Route::get('/completion', [ReportController::class, 'completion']);
        Route::get('/workload', [ReportController::class, 'workload']);
    });
});
