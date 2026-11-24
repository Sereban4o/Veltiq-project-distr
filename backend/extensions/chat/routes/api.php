<?php

/*
| Chat module routes (chat itself + the app downloads page, which belongs to
| this module). Gated by extension.enabled:chat.
*/

use App\Extensions\Chat\Http\Controllers\ChatController;
use App\Extensions\Chat\Http\Controllers\MessageController;
use App\Extensions\Chat\Http\Controllers\MessageSearchController;
use App\Extensions\Chat\Http\Controllers\PinController;
use App\Extensions\Chat\Http\Controllers\ReleasesController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::middleware(['extension.enabled:chat', 'auth:sanctum'])->get('/releases', [ReleasesController::class, 'index']);

    Route::prefix('chat')->middleware(['extension.enabled:chat', 'auth:sanctum'])->group(function () {
        // Chats
        Route::get('/my', [ChatController::class, 'myChats']);
        Route::get('/search-users', [ChatController::class, 'searchUsers']);
        // Search over the user's chats (static segment, registered before {chat}).
        Route::get('/messages/search', [MessageSearchController::class, 'index']);
        Route::get('/', [ChatController::class, 'index']);
        Route::post('/', [ChatController::class, 'store']);
        Route::get('/{chat}', [ChatController::class, 'show'])->whereNumber('chat');
        Route::patch('/{chat}', [ChatController::class, 'update']);
        Route::delete('/{chat}', [ChatController::class, 'destroy']);

        // Chat members
        Route::post('/{chat}/members', [ChatController::class, 'addMembers']);
        Route::post('/{chat}/members/remove', [ChatController::class, 'removeMember']);
        Route::post('/{chat}/leave', [ChatController::class, 'leaveChat'])->whereNumber('chat');
        Route::post('/{chat}/read', [ChatController::class, 'markAsRead']);
        Route::get('/{chat}/reads', [ChatController::class, 'reads'])->whereNumber('chat');
        Route::post('/{chat}/typing', [ChatController::class, 'typing'])
            ->whereNumber('chat')->middleware('throttle:120,1');
        Route::post('/{chat}/call/signal', [ChatController::class, 'callSignal'])
            ->whereNumber('chat')->middleware('throttle:240,1');

        // Messages
        // Pinned messages
        Route::get('/{chat}/pins', [PinController::class, 'index'])->whereNumber('chat');

        // Messages
        Route::get('/{chat}/messages', [MessageController::class, 'index']);
        Route::post('/{chat}/messages', [MessageController::class, 'store'])->middleware('throttle:60,1');
        Route::get('/{chat}/messages/{message}', [MessageController::class, 'show'])->whereNumber('message');
        Route::patch('/{chat}/messages/{message}', [MessageController::class, 'update'])->whereNumber('message');
        Route::delete('/{chat}/messages/{message}', [MessageController::class, 'destroy'])->whereNumber('message');
        Route::post('/{chat}/messages/{message}/react', [MessageController::class, 'addReaction'])->whereNumber('message');
        Route::delete('/{chat}/messages/{message}/react/{emoji}', [MessageController::class, 'removeReaction'])->whereNumber('message');

        // Threads
        Route::get('/{chat}/messages/{message}/replies', [MessageController::class, 'replies'])->whereNumber('message');

        // Forwarding
        Route::post('/{chat}/messages/{message}/forward', [MessageController::class, 'forward'])->whereNumber('message');

        // Pins on a message
        Route::post('/{chat}/messages/{message}/pin', [PinController::class, 'store'])->whereNumber('message');
        Route::delete('/{chat}/messages/{message}/pin', [PinController::class, 'destroy'])->whereNumber('message');
    });
});
