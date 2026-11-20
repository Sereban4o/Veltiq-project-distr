<?php

use App\Http\Controllers\Admin\AccessController;
use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\ClientBuildController;
use App\Http\Controllers\Admin\CounterpartyController;
use App\Http\Controllers\Admin\CrmStageController;
use App\Http\Controllers\Admin\CustomFieldController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LoginLogController;
use App\Http\Controllers\Admin\NotificationBroadcastController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Admin\ReleaseController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SkinController;
use App\Http\Controllers\Admin\StorageAuditController;
use App\Http\Controllers\Admin\StorageController;
use App\Http\Controllers\Admin\WebhookController;
use App\Http\Controllers\Admin\WidgetController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// API root
Route::get('/', function () {
    return response()->json(['message' => 'Tasksbase-Knowledgebase API Backend']);
});

// Login route (for Laravel's internal redirects)
Route::get('/login', function () {
    return redirect('/');
})->name('login');

// Admin Auth Routes (public)
Route::get('/admin/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login.form');
Route::post('/admin/login', [AdminAuthController::class, 'login'])->name('admin.login')->middleware('throttle:5,1');
Route::post('/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

// Admin Panel routes (Blade views) - protected
Route::middleware(['auth', EnsureUserIsAdmin::class])->prefix('admin')->name('admin.')->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Users
    Route::get('/users', [DashboardController::class, 'users'])->name('users.index');
    Route::get('/users/create', [DashboardController::class, 'userCreate'])->name('users.create');
    Route::post('/users', [DashboardController::class, 'userStore'])->name('users.store');
    Route::get('/users/{user}/edit', [DashboardController::class, 'userEdit'])->name('users.edit');
    Route::patch('/users/{user}', [DashboardController::class, 'userUpdate'])->name('users.update');
    Route::delete('/users/{user}', [DashboardController::class, 'userDelete'])->name('users.delete');
    Route::patch('/users/{user}/toggle-status', [DashboardController::class, 'toggleUserStatus'])->name('users.toggle-status');

    // Statuses
    Route::get('/statuses', [DashboardController::class, 'statuses'])->name('statuses.index');
    Route::get('/statuses/create', [DashboardController::class, 'statusCreate'])->name('statuses.create');
    Route::post('/statuses', [DashboardController::class, 'statusStore'])->name('statuses.store');
    Route::get('/statuses/{status}/edit', [DashboardController::class, 'statusEdit'])->name('statuses.edit');
    Route::patch('/statuses/{status}', [DashboardController::class, 'statusUpdate'])->name('statuses.update');
    Route::delete('/statuses/{status}', [DashboardController::class, 'statusDelete'])->name('statuses.delete');

    // Priorities
    Route::get('/priorities', [DashboardController::class, 'priorities'])->name('priorities.index');
    Route::get('/priorities/create', [DashboardController::class, 'priorityCreate'])->name('priorities.create');
    Route::post('/priorities', [DashboardController::class, 'priorityStore'])->name('priorities.store');
    Route::get('/priorities/{priority}/edit', [DashboardController::class, 'priorityEdit'])->name('priorities.edit');
    Route::patch('/priorities/{priority}', [DashboardController::class, 'priorityUpdate'])->name('priorities.update');
    Route::delete('/priorities/{priority}', [DashboardController::class, 'priorityDelete'])->name('priorities.delete');

    // Roles
    Route::get('/roles', [DashboardController::class, 'roles'])->name('roles.index');
    Route::get('/roles/create', [DashboardController::class, 'roleCreate'])->name('roles.create');
    Route::post('/roles', [DashboardController::class, 'roleStore'])->name('roles.store');
    Route::get('/roles/{role}/edit', [DashboardController::class, 'roleEdit'])->name('roles.edit');
    Route::patch('/roles/{role}', [DashboardController::class, 'roleUpdate'])->name('roles.update');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'syncPermissions'])->name('roles.permissions');
    Route::delete('/roles/{role}', [DashboardController::class, 'roleDelete'])->name('roles.delete');

    // Access overrides (project / space scoped permission rules)
    Route::get('/access/projects', [AccessController::class, 'projects'])->name('access.projects');
    Route::get('/access/spaces', [AccessController::class, 'spaces'])->name('access.spaces');
    Route::post('/access/overrides', [AccessController::class, 'store'])->name('access.overrides.store');
    Route::delete('/access/overrides/{id}', [AccessController::class, 'destroy'])
        ->whereNumber('id')->name('access.overrides.destroy');

    // Audit log (activity journal)
    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('/audit/export', [AuditLogController::class, 'export'])->name('audit.export');
    Route::get('/audit/{auditLog}', [AuditLogController::class, 'show'])->name('audit.show');

    // Login journal (successful/failed sign-ins, logouts, password changes)
    Route::get('/login-logs', [LoginLogController::class, 'index'])->name('login-logs.index');
    Route::get('/login-logs/export', [LoginLogController::class, 'export'])->name('login-logs.export');
    Route::get('/login-logs/{loginLog}', [LoginLogController::class, 'show'])->name('login-logs.show');

    // Storage audit journal (file access)
    Route::get('/storage-audit', [StorageAuditController::class, 'index'])->name('storage-audit.index');
    Route::get('/storage-audit/export', [StorageAuditController::class, 'export'])->name('storage-audit.export');

    // Notification settings + test digest
    Route::get('/notifications', [NotificationSettingsController::class, 'index'])->name('notifications.index');
    Route::post('/notifications', [NotificationSettingsController::class, 'update'])->name('notifications.update');
    Route::post('/notifications/test', [NotificationSettingsController::class, 'test'])->name('notifications.test');

    // Broadcast an announcement to all (or role-filtered) users + view the queue.
    Route::get('/notifications/broadcast', [NotificationBroadcastController::class, 'index'])->name('notifications.broadcast');
    Route::post('/notifications/broadcast', [NotificationBroadcastController::class, 'send'])->name('notifications.broadcast.send');

    // Spaces
    Route::get('/spaces', [DashboardController::class, 'spaces'])->name('spaces.index');
    Route::get('/spaces/create', [DashboardController::class, 'spaceCreate'])->name('spaces.create');
    Route::post('/spaces', [DashboardController::class, 'spaceStore'])->name('spaces.store');
    Route::get('/spaces/{space}/edit', [DashboardController::class, 'spaceEdit'])->name('spaces.edit');
    Route::patch('/spaces/{space}', [DashboardController::class, 'spaceUpdate'])->name('spaces.update');
    Route::delete('/spaces/{space}', [DashboardController::class, 'spaceDelete'])->name('spaces.delete');

    // File storage settings
    Route::get('/storage', [StorageController::class, 'index'])->name('storage.index');
    Route::post('/storage', [StorageController::class, 'update'])->name('storage.update');
    Route::post('/storage/test', [StorageController::class, 'test'])->name('storage.test');

    // CRM pipeline stages
    Route::get('/crm-stages', [CrmStageController::class, 'index'])->name('crm-stages.index');
    Route::post('/crm-stages', [CrmStageController::class, 'store'])->name('crm-stages.store');
    Route::patch('/crm-stages/{stage}', [CrmStageController::class, 'update'])->name('crm-stages.update');
    Route::delete('/crm-stages/{stage}', [CrmStageController::class, 'destroy'])->name('crm-stages.destroy');

    // Counterparties (1С CounterpartiesExchange)
    Route::get('/counterparties', [CounterpartyController::class, 'index'])->name('counterparties.index');
    Route::post('/counterparties/settings', [CounterpartyController::class, 'updateSettings'])->name('counterparties.settings');
    Route::post('/counterparties/sync', [CounterpartyController::class, 'sync'])->name('counterparties.sync');

    // App releases (updates)
    Route::get('/releases', [ReleaseController::class, 'index'])->name('releases.index');
    Route::post('/releases', [ReleaseController::class, 'store'])->name('releases.store');
    Route::delete('/releases/{platform}', [ReleaseController::class, 'destroy'])->name('releases.delete');

    // Preset client builds (GitHub Actions dispatch)
    Route::get('/client-builds', [ClientBuildController::class, 'index'])->name('client-builds.index');
    Route::post('/client-builds', [ClientBuildController::class, 'store'])->name('client-builds.store');
    Route::put('/client-builds/settings', [ClientBuildController::class, 'settings'])->name('client-builds.settings');
    Route::get('/client-builds/status', [ClientBuildController::class, 'status'])->name('client-builds.status');

    // Runtime branding / theme
    Route::get('/branding', [BrandingController::class, 'index'])->name('branding.index');
    Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');

    // Branding skins (named appearance presets applied without a rebuild)
    Route::prefix('skins')->name('skins.')->group(function () {
        Route::get('/', [SkinController::class, 'index'])->name('index');
        Route::get('/create', [SkinController::class, 'create'])->name('create');
        Route::post('/', [SkinController::class, 'store'])->name('store');
        Route::get('/{skin}/edit', [SkinController::class, 'edit'])->name('edit');
        Route::put('/{skin}', [SkinController::class, 'update'])->name('update');
        Route::delete('/{skin}', [SkinController::class, 'destroy'])->name('destroy');
        Route::post('/{skin}/duplicate', [SkinController::class, 'duplicate'])->name('duplicate');
        Route::post('/{skin}/apply', [SkinController::class, 'apply'])->name('apply');
    });

    // Outbound webhooks (integrations)
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->name('webhooks.test');

    // Embeddable iframe widgets
    Route::get('/widgets', [WidgetController::class, 'index'])->name('widgets.index');
    Route::put('/widgets', [WidgetController::class, 'update'])->name('widgets.update');

    // Custom fields (issue extra fields)
    Route::get('/custom-fields', [CustomFieldController::class, 'index'])->name('custom-fields.index');
    Route::post('/custom-fields', [CustomFieldController::class, 'store'])->name('custom-fields.store');
    Route::put('/custom-fields/{customField}', [CustomFieldController::class, 'update'])->name('custom-fields.update');
    Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy'])->name('custom-fields.destroy');
});
