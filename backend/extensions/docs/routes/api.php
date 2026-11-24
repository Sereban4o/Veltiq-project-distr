<?php

/*
| Documentation module routes (knowledge base spaces).
| Gated by `extension.enabled:docs`.
*/

use App\Extensions\Docs\Http\Controllers\SpaceController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('knowledgebase/spaces')->middleware(['extension.enabled:docs', 'auth:sanctum'])->group(function () {
        Route::get('/', [SpaceController::class, 'index']);
        Route::post('/', [SpaceController::class, 'store']);
        Route::get('/{key}', [SpaceController::class, 'show']);
        Route::patch('/{key}', [SpaceController::class, 'update']);
        Route::delete('/{key}', [SpaceController::class, 'destroy']);
        Route::get('/{key}/members', [SpaceController::class, 'members']);
        Route::post('/{key}/members', [SpaceController::class, 'addMember']);
        Route::delete('/{key}/members/{userId}', [SpaceController::class, 'removeMember'])->whereNumber('userId');
    });
});
