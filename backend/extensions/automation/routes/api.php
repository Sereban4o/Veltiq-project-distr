<?php

/*
| Task automation module routes (rules CRUD).
| Gated by `extension.enabled:automation`.
*/

use App\Extensions\Automation\Http\Controllers\TaskAutomationRuleController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('tasksbase/automations')->middleware(['extension.enabled:automation', 'auth:sanctum'])->group(function () {
        Route::get('/', [TaskAutomationRuleController::class, 'index']);
        Route::post('/', [TaskAutomationRuleController::class, 'store']);
        Route::patch('/{rule}', [TaskAutomationRuleController::class, 'update'])->whereNumber('rule');
        Route::delete('/{rule}', [TaskAutomationRuleController::class, 'destroy'])->whereNumber('rule');
    });
});
