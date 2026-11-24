<?php

/*
| CRM module routes.
|
| Owned by the module and gated by `extension.enabled:crm`: disabling the module
| closes the whole HTTP surface (docs/11-extensions.md). Paths stay identical to the
| previous central definition so clients do not change.
*/

use App\Extensions\Crm\Http\Controllers\ActivityController;
use App\Extensions\Crm\Http\Controllers\AutomationRuleController;
use App\Extensions\Crm\Http\Controllers\CompanyController;
use App\Extensions\Crm\Http\Controllers\ContactController;
use App\Extensions\Crm\Http\Controllers\CounterpartyController;
use App\Extensions\Crm\Http\Controllers\CrmEmailController;
use App\Extensions\Crm\Http\Controllers\CrmFileController;
use App\Extensions\Crm\Http\Controllers\CsvController;
use App\Extensions\Crm\Http\Controllers\DashboardController;
use App\Extensions\Crm\Http\Controllers\DealController;
use App\Extensions\Crm\Http\Controllers\PipelineStageController;
use App\Extensions\Crm\Http\Controllers\ReportsController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    // ONLYOFFICE callbacks for CRM files are token-protected and work without a
    // session, so they live outside `auth:sanctum` but inside the module gate.
    Route::prefix('crm')->middleware('extension.enabled:crm')->group(function () {
        Route::get('/files/{file}/office/download', [CrmFileController::class, 'officeDownload'])->whereNumber('file');
        Route::post('/files/{file}/office/callback', [CrmFileController::class, 'officeCallback'])->whereNumber('file');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::get('/stages', [PipelineStageController::class, 'index']);

            // Reports
            Route::get('/reports/funnel', [ReportsController::class, 'funnel']);
            Route::get('/reports/conversion', [ReportsController::class, 'conversion']);
            Route::get('/reports/revenue', [ReportsController::class, 'revenue']);
            Route::get('/reports/summary', [ReportsController::class, 'summary']);

            // CSV export / import
            Route::get('/companies/export', [CsvController::class, 'export'])->defaults('entity', 'companies');
            Route::post('/companies/import', [CsvController::class, 'import'])->defaults('entity', 'companies');
            Route::get('/contacts/export', [CsvController::class, 'export'])->defaults('entity', 'contacts');
            Route::post('/contacts/import', [CsvController::class, 'import'])->defaults('entity', 'contacts');
            Route::get('/deals/export', [CsvController::class, 'export'])->defaults('entity', 'deals');
            Route::post('/deals/import', [CsvController::class, 'import'])->defaults('entity', 'deals');

            Route::get('/deals/stats', [DealController::class, 'stats']);
            Route::get('/deals', [DealController::class, 'index']);
            Route::post('/deals', [DealController::class, 'store']);
            Route::get('/deals/{deal}', [DealController::class, 'show'])->whereNumber('deal');
            Route::patch('/deals/{deal}', [DealController::class, 'update'])->whereNumber('deal');
            Route::patch('/deals/{deal}/stage', [DealController::class, 'moveStage'])->whereNumber('deal');
            Route::delete('/deals/{deal}', [DealController::class, 'destroy'])->whereNumber('deal');

            Route::get('/companies', [CompanyController::class, 'index']);
            Route::post('/companies', [CompanyController::class, 'store']);
            Route::get('/companies/{company}', [CompanyController::class, 'show'])->whereNumber('company');
            Route::patch('/companies/{company}', [CompanyController::class, 'update'])->whereNumber('company');
            Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->whereNumber('company');

            // Counterparties (1С CounterpartiesExchange)
            Route::get('/counterparties/search-1c', [CounterpartyController::class, 'searchOneC'])->middleware('throttle:30,1');
            Route::get('/counterparties', [CounterpartyController::class, 'index']);
            Route::post('/counterparties', [CounterpartyController::class, 'store']);
            Route::patch('/counterparties/{company}', [CounterpartyController::class, 'update'])->whereNumber('company');

            Route::get('/contacts', [ContactController::class, 'index']);
            Route::post('/contacts', [ContactController::class, 'store']);
            Route::get('/contacts/{contact}', [ContactController::class, 'show'])->whereNumber('contact');
            Route::patch('/contacts/{contact}', [ContactController::class, 'update'])->whereNumber('contact');
            Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->whereNumber('contact');

            Route::get('/activities', [ActivityController::class, 'index']);
            Route::post('/activities', [ActivityController::class, 'store']);
            Route::patch('/activities/{activity}', [ActivityController::class, 'update'])->whereNumber('activity');
            Route::patch('/activities/{activity}/toggle', [ActivityController::class, 'toggle'])->whereNumber('activity');
            Route::delete('/activities/{activity}', [ActivityController::class, 'destroy'])->whereNumber('activity');

            // Automations (admin only)
            Route::middleware('admin')->group(function () {
                Route::get('/automation-rules', [AutomationRuleController::class, 'index']);
                Route::post('/automation-rules', [AutomationRuleController::class, 'store']);
                Route::patch('/automation-rules/{rule}', [AutomationRuleController::class, 'update'])->whereNumber('rule');
                Route::delete('/automation-rules/{rule}', [AutomationRuleController::class, 'destroy'])->whereNumber('rule');
            });

            // Related mail (current user's messages only)
            Route::get('/{entity}/{id}/emails', [CrmEmailController::class, 'index'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id');
            Route::post('/{entity}/{id}/emails', [CrmEmailController::class, 'attach'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id');
            Route::delete('/{entity}/{id}/emails/{message}', [CrmEmailController::class, 'detach'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id')->whereNumber('message');

            // Linked files
            Route::get('/{entity}/{id}/files', [CrmFileController::class, 'index'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id');
            Route::post('/{entity}/{id}/files', [CrmFileController::class, 'attach'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id');
            Route::post('/{entity}/{id}/files/upload', [CrmFileController::class, 'upload'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id');
            Route::delete('/{entity}/{id}/files/{file}', [CrmFileController::class, 'detach'])->whereIn('entity', ['deals', 'companies', 'contacts'])->whereNumber('id')->whereNumber('file');

            // ONLYOFFICE editor config for a linked file (auth required)
            Route::get('/files/{file}/office/config', [CrmFileController::class, 'officeConfig'])->whereNumber('file');
        });
    });
});
