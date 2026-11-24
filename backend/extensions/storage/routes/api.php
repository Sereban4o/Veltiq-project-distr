<?php

/*
| File storage module routes (docs/11-extensions.md).
|
| The module owns the whole storage surface: browser API, uploads, versions,
| trash, share links, ONLYOFFICE integration and the public file view. Everything
| is gated by `extension.enabled:storage`, so disabling the module closes it all.
*/

use App\Extensions\Storage\Http\Controllers\Admin\StorageAdminController;
use App\Extensions\Storage\Http\Controllers\FilesController;
use App\Extensions\Storage\Http\Controllers\OfficeController;
use App\Extensions\Storage\Http\Controllers\PublicShareController;
use App\Extensions\Storage\Http\Controllers\QuotaController;
use App\Extensions\Storage\Http\Controllers\ShareController;
use App\Extensions\Storage\Http\Controllers\StorageController;
use App\Extensions\Storage\Http\Controllers\TrashController;
use App\Extensions\Storage\Http\Controllers\UploadController;
use App\Extensions\Storage\Http\Controllers\VersionController;
use App\Extensions\Storage\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    // Authenticated storage browser
    Route::prefix('storage')->middleware('extension.enabled:storage')->group(function () {
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/files', [StorageController::class, 'index']);
            Route::get('/file', [StorageController::class, 'show']);
            Route::put('/file', [StorageController::class, 'save']);
            Route::post('/entry', [StorageController::class, 'store']);
            Route::post('/entry/move', [StorageController::class, 'move']);
            Route::delete('/entry', [StorageController::class, 'destroy']);
            Route::get('/download', [StorageController::class, 'download']);
            Route::get('/raw', [StorageController::class, 'raw']);
            Route::get('/preview', [StorageController::class, 'preview']);
            Route::get('/archive', [StorageController::class, 'archive']);

            // Uploads (direct + resumable) and folder drops
            Route::post('/upload', [UploadController::class, 'store']);
            Route::post('/uploads', [UploadController::class, 'createSession']);
            Route::put('/uploads/{uploadId}/chunks/{index}', [UploadController::class, 'putChunk'])->whereNumber('index');
            Route::get('/uploads/{uploadId}', [UploadController::class, 'status']);
            Route::post('/uploads/{uploadId}/complete', [UploadController::class, 'complete']);
            Route::delete('/uploads/{uploadId}', [UploadController::class, 'cancel']);

            // Versions
            Route::get('/versions', [VersionController::class, 'index']);
            Route::post('/versions/{version}/restore', [VersionController::class, 'restore'])->whereNumber('version');
            Route::get('/versions/{version}/download', [VersionController::class, 'download'])->whereNumber('version');
            Route::delete('/versions/{version}', [VersionController::class, 'destroy'])->whereNumber('version');

            // Trash
            Route::get('/trash', [TrashController::class, 'index']);
            Route::delete('/trash', [TrashController::class, 'empty']);
            Route::post('/trash/{id}/restore', [TrashController::class, 'restore'])->whereNumber('id');
            Route::delete('/trash/{id}', [TrashController::class, 'destroy'])->whereNumber('id');

            // Public share links
            Route::get('/shares', [ShareController::class, 'index']);
            Route::post('/shares', [ShareController::class, 'store']);
            Route::delete('/shares/{id}', [ShareController::class, 'destroy'])->whereNumber('id');

            // Quota and access journal
            Route::get('/quota', [QuotaController::class, 'show']);
            Route::get('/audit', [AuditController::class, 'index']);

            // ONLYOFFICE editor config (auth required)
            Route::get('/office/config', [OfficeController::class, 'config']);
        });

        // ONLYOFFICE callbacks are token-protected and work without a session.
        Route::get('/office/download', [OfficeController::class, 'download']);
        Route::post('/office/callback', [OfficeController::class, 'callback']);
    });

    // Attachment upload/delete for other modules
    Route::middleware(['extension.enabled:storage', 'auth:sanctum'])->group(function () {
        Route::post('/files/upload', [FilesController::class, 'upload']);
        Route::delete('/files/{id}', [FilesController::class, 'destroy']);
    });

    // Public share links (anonymous, throttled)
    Route::prefix('storage/public')->middleware(['extension.enabled:storage', 'throttle:30,1'])->group(function () {
        Route::get('/share/{token}', [PublicShareController::class, 'show']);
        Route::post('/share/{token}/unlock', [PublicShareController::class, 'unlock'])->middleware('throttle:10,1');
        Route::get('/share/{token}/raw', [PublicShareController::class, 'raw']);
        Route::get('/share/{token}/download', [PublicShareController::class, 'download']);
        Route::get('/share/{token}/preview', [PublicShareController::class, 'preview']);
        Route::get('/share/{token}/archive', [PublicShareController::class, 'archive']);
    });

    // Public file view (supports ?token= for <img> tags)
    Route::middleware('extension.enabled:storage')->group(function () {
        Route::get('/files/{id}', [FilesController::class, 'show']);
        Route::get('/files/{id}/download', [FilesController::class, 'download']);
    });

    // Admin storage settings/quotas
    Route::middleware(['api', 'extension.enabled:storage', 'auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        Route::get('/storage/quotas', [StorageAdminController::class, 'quotas']);
        Route::put('/storage/quotas/{userId}', [StorageAdminController::class, 'updateQuota'])->whereNumber('userId');
        Route::get('/storage/settings', [StorageAdminController::class, 'settings']);
        Route::put('/storage/settings', [StorageAdminController::class, 'updateSettings']);
        Route::get('/storage/audit', [StorageAdminController::class, 'audit']);
        Route::post('/storage/recalculate', [StorageAdminController::class, 'recalculate']);
    });
});