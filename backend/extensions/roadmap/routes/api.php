<?php

/*
| Roadmap (timeline) module routes. Gated by `extension.enabled:roadmap`.
*/

use App\Extensions\Roadmap\Http\Controllers\RoadmapController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::middleware(['extension.enabled:roadmap', 'auth:sanctum'])
        ->get('/tasksbase/projects/{key}/timeline', [RoadmapController::class, 'timeline']);
});
