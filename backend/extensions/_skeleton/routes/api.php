<?php

/*
| Skeleton module route file (docs/11-extensions.md).
|
| Rules: declare full paths, keep the shared `api` middleware group and gate the
| module with `extension.enabled:<id>`. A disabled module then closes its whole HTTP
| surface with 403 `module_disabled`.
*/

use Acme\Example\Http\Controllers\ExampleController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('m/acme-example')->middleware('extension.enabled:acme.example')->group(function () {
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/', [ExampleController::class, 'index']);
        });
    });
});
