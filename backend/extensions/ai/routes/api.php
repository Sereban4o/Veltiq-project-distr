<?php

/*
| AI assistant routes (local Ollama + permission-aware retrieval).
| Owned by the module and gated by `extension.enabled:ai`.
*/

use App\Extensions\Ai\Http\Controllers\AssistantController;
use App\Extensions\Ai\Http\Controllers\ConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('ai')->middleware('extension.enabled:ai')->group(function () {
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/status', [AssistantController::class, 'status']);
            Route::post('/ask', [AssistantController::class, 'ask'])->middleware('throttle:30,1');
            Route::post('/summarize', [AssistantController::class, 'summarize'])->middleware('throttle:30,1');
            Route::post('/draft', [AssistantController::class, 'draft'])->middleware('throttle:30,1');

            Route::get('/conversations', [ConversationController::class, 'index']);
            Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])
                ->whereNumber('conversation');
            Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])
                ->whereNumber('conversation')
                ->middleware('throttle:30,1');
        });
    });
});
