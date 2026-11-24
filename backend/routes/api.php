<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\AdminLicenseController;
use App\Http\Controllers\Api\Admin\AdminExtensionsController;
use App\Http\Controllers\Api\Admin\AdminTemplateController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\QrLoginController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\Integration\OneC\OneCController;
use App\Http\Controllers\Api\Knowledgebase\KnowledgebaseSearchController;
use App\Http\Controllers\Api\Knowledgebase\PageBacklinkController;
use App\Http\Controllers\Api\Knowledgebase\PageController;
use App\Http\Controllers\Api\Knowledgebase\PageExportController;
use App\Http\Controllers\Api\Knowledgebase\PageShareController;
use App\Http\Controllers\Api\Knowledgebase\PageTemplateController;
use App\Http\Controllers\Api\Knowledgebase\PublicPageController;
use App\Http\Controllers\Api\Knowledgebase\TasksbaseEmbedController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\WidgetsController;
use App\Http\Controllers\TemplateServeController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ─── Public Auth Routes ───
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/auth/2fa/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:10,1');
    Route::post('/auth/qr/claim', [QrLoginController::class, 'claim'])->middleware('throttle:10,1');
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');
    Route::post('/auth/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->middleware('signed');

    // ─── Runtime branding / theme (public) ───
    Route::get('/branding', [BrandingController::class, 'show']);

    // ─── Licensing & modules (reachable even without a license) ───
    // The SPA uses these to render the "license missing" screen and to build its
    // menu from the enabled modules only.
    Route::get('/license/status', [\App\Http\Controllers\Api\LicenseController::class, 'status']);
    Route::get('/extensions', [\App\Http\Controllers\Api\ExtensionsController::class, 'index']);
    Route::get('/template/active', [TemplateServeController::class, 'active']);
    // Installation status (no database access): the SPA redirects to /install
    // until the product is installed.
    Route::get('/install', [\App\Http\Controllers\InstallController::class, 'status']);
    // Rendered document of the active template: the SPA swaps its own document
    // for it, so the template opens at the site root without changing the URL.
    Route::get('/template/active/document', [TemplateServeController::class, 'document']);

    // ─── 1С Integration (token + HMAC, no user session) ───
    Route::prefix('integration/1c')->middleware(['one.c', 'throttle:120,1'])->group(function () {
        Route::post('/users/sync', [OneCController::class, 'usersSync']);
        Route::post('/issues/push', [OneCController::class, 'issuesPush']);
        Route::match(['get', 'post'], '/issues/pull', [OneCController::class, 'issuesPull']);
        Route::post('/issues/{externalId}/comment', [OneCController::class, 'issueComment']);
        Route::post('/notifications', [OneCController::class, 'notifications']);
        Route::post('/chats/messages', [OneCController::class, 'chatMessage']);
        Route::post('/kb/pages', [OneCController::class, 'kbPage']);
    });

    // ─── Protected Auth Routes ───
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/refresh', [AuthController::class, 'refresh']);
        Route::get('/auth/sessions', [AuthController::class, 'sessions']);
        Route::post('/auth/qr/start', [QrLoginController::class, 'start']);
        Route::get('/auth/2fa', [TwoFactorController::class, 'status']);
        Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup']);
        Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/auth/2fa/disable', [TwoFactorController::class, 'disable']);
        Route::delete('/auth/sessions', [AuthController::class, 'revokeOtherSessions']);
        Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');
        Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('/auth/avatar', [AuthController::class, 'uploadAvatar']);

        // Users
        Route::get('/users/all', [AuthController::class, 'allUsers']);

        // Published client builds (download page)

        // Notifications
        Route::get('/auth/notifications', [NotificationController::class, 'index']);
        Route::get('/auth/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/auth/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/auth/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::get('/auth/activity', [NotificationController::class, 'activity']);

        // Notification channel preferences (per type + digest frequency)
        Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'index']);
        Route::match(['put', 'patch'], '/notifications/preferences', [NotificationPreferenceController::class, 'update']);

        // ─── Knowledgebase Routes ───
        Route::prefix('knowledgebase')->group(function () {
            // Spaces

            // Members (membership changes trigger a search-index resync)

            // Search (Scout/Meilisearch with ACL filtering)
            Route::get('/search', [KnowledgebaseSearchController::class, 'index']);

            // Page tree
            Route::get('/spaces/{spaceKey}/tree', [PageController::class, 'tree']);

            // Templates (space-scoped + built-in global)
            Route::get('/spaces/{spaceKey}/templates', [PageTemplateController::class, 'index']);
            Route::post('/spaces/{spaceKey}/templates', [PageTemplateController::class, 'store']);
            Route::post('/templates', [PageTemplateController::class, 'storeGlobal']);
            Route::patch('/templates/{templateId}', [PageTemplateController::class, 'update'])->whereNumber('templateId');
            Route::delete('/templates/{templateId}', [PageTemplateController::class, 'destroy'])->whereNumber('templateId');

            // Pages
            Route::get('/spaces/{spaceKey}/pages', [PageController::class, 'spacePages']);
            Route::post('/spaces/{spaceKey}/pages', [PageController::class, 'store']);
            Route::get('/pages', [PageController::class, 'index']);
            Route::get('/pages/{id}', [PageController::class, 'show'])->whereNumber('id');
            Route::patch('/pages/{id}', [PageController::class, 'update'])->whereNumber('id');
            Route::delete('/pages/{id}', [PageController::class, 'destroy'])->whereNumber('id');
            Route::patch('/pages/{id}/move', [PageController::class, 'move'])->whereNumber('id');
            Route::post('/pages/{id}/publish', [PageController::class, 'publish'])->whereNumber('id');
            Route::patch('/pages/{id}/labels', [PageController::class, 'updateLabels'])->whereNumber('id');

            // Versions
            Route::get('/pages/{id}/versions', [PageController::class, 'versions'])->whereNumber('id');
            Route::get('/pages/{id}/diff', [PageController::class, 'diff'])->whereNumber('id');
            Route::post('/pages/{id}/versions/{version}/restore', [PageController::class, 'restore'])->whereNumber('id');
            Route::get('/pages/{id}/backlinks', [PageBacklinkController::class, 'index'])->whereNumber('id');
            Route::post('/pages/{id}/follow', [PageController::class, 'follow'])->whereNumber('id');
            Route::delete('/pages/{id}/follow', [PageController::class, 'unfollow'])->whereNumber('id');

            // Comments
            Route::get('/pages/{id}/comments', [PageController::class, 'comments'])->whereNumber('id');
            Route::post('/pages/{id}/comments', [PageController::class, 'addComment'])->whereNumber('id');
            Route::patch('/pages/{id}/comments/{commentId}', [PageController::class, 'updateComment'])->whereNumber('id')->whereNumber('commentId');
            Route::delete('/pages/{id}/comments/{commentId}', [PageController::class, 'deleteComment'])->whereNumber('id')->whereNumber('commentId');
            Route::patch('/pages/{id}/comments/{commentId}/resolve', [PageController::class, 'resolveComment'])->whereNumber('id')->whereNumber('commentId');

            // Page-level permissions
            Route::get('/pages/{id}/permissions', [PageController::class, 'permissions'])->whereNumber('id');
            Route::put('/pages/{id}/permissions', [PageController::class, 'updatePermissions'])->whereNumber('id');
            Route::get('/pages/{id}/shares', [PageShareController::class, 'index'])->whereNumber('id');
            Route::post('/pages/{id}/shares', [PageShareController::class, 'store'])->whereNumber('id');
            Route::delete('/shares/{share}', [PageShareController::class, 'destroy'])->whereNumber('share');

            // Export
            Route::get('/pages/{id}/export/pdf', [PageExportController::class, 'pdf'])->whereNumber('id');
            Route::get('/pages/{id}/export/markdown', [PageExportController::class, 'markdown'])->whereNumber('id');

            // Page attachments
            Route::get('/pages/{id}/attachments', [PageController::class, 'attachments'])->whereNumber('id');
            Route::post('/pages/{id}/attachments', [PageController::class, 'addAttachment'])->whereNumber('id');
            Route::delete('/pages/{id}/attachments/{attachmentId}', [PageController::class, 'removeAttachment'])->whereNumber('id');

            // Embeds
            Route::get('/tasksbase-embed', [TasksbaseEmbedController::class, 'index']);
        });

        // ─── Global Search (authenticated, filtered per user) ───
        Route::get('/search', [SearchController::class, 'index']);

        // ─── Embeddable iframe widgets (§12 extension point) ───
        Route::get('/widgets', [WidgetsController::class, 'index']);

        // ─── Custom fields (§12 extension point) ───
        Route::get('/custom-fields', [CustomFieldController::class, 'index']);
        Route::patch('/issues/{issueId}/custom-fields', [CustomFieldController::class, 'updateIssue']);
    });

    // ─── Public knowledgebase page (anonymous, throttled) ───
    Route::get('/public/pages/{token}', [PublicPageController::class, 'show'])->middleware('throttle:60,1');

    // ─── Admin Routes ───
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        // Licensing & modules (always reachable: activation must work while blocked)
        Route::get('/license', [AdminLicenseController::class, 'show']);
        Route::post('/license/request', [AdminLicenseController::class, 'request']);
        Route::post('/license/activate', [AdminLicenseController::class, 'activate']);
        Route::post('/license/crl', [AdminLicenseController::class, 'crl']);
        Route::get('/extensions', [AdminExtensionsController::class, 'index']);
        Route::post('/extensions/{id}/toggle', [AdminExtensionsController::class, 'toggle'])
            ->where('id', '[a-z0-9._-]+');

        // Templates (uploadable HTML/CSS/JS themes, docs/13-template-system.md)
        Route::get('/templates', [AdminTemplateController::class, 'index']);
        Route::post('/templates', [AdminTemplateController::class, 'store']);
        Route::get('/templates/{id}', [AdminTemplateController::class, 'show'])->whereNumber('id');
        Route::delete('/templates/{id}', [AdminTemplateController::class, 'destroy'])->whereNumber('id');
        Route::post('/templates/{id}/activate', [AdminTemplateController::class, 'activate'])->whereNumber('id');
        Route::post('/templates/{id}/deactivate', [AdminTemplateController::class, 'deactivate'])->whereNumber('id');

        // User Management
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'userShow']);

        // Space Management
        Route::get('/spaces', [AdminController::class, 'spaces']);
        Route::post('/spaces', [AdminController::class, 'storeSpace']);
        Route::patch('/spaces/{id}', [AdminController::class, 'updateSpace']);
        Route::delete('/spaces/{id}', [AdminController::class, 'destroySpace']);
        Route::post('/users', [AdminController::class, 'storeUser']);
        Route::patch('/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'destroyUser']);

        // Status Management
        Route::get('/statuses', [AdminController::class, 'statuses']);
        Route::post('/statuses', [AdminController::class, 'storeStatus']);
        Route::patch('/statuses/{id}', [AdminController::class, 'updateStatus']);
        Route::delete('/statuses/{id}', [AdminController::class, 'destroyStatus']);

        // Priority Management
        Route::get('/priorities', [AdminController::class, 'priorities']);
        Route::post('/priorities', [AdminController::class, 'storePriority']);
        Route::patch('/priorities/{id}', [AdminController::class, 'updatePriority']);
        Route::delete('/priorities/{id}', [AdminController::class, 'destroyPriority']);

        // Role Management
        Route::get('/roles', [AdminController::class, 'roles']);
        Route::post('/roles', [AdminController::class, 'storeRole']);
        Route::patch('/roles/{id}', [AdminController::class, 'updateRole']);
        Route::delete('/roles/{id}', [AdminController::class, 'destroyRole']);

        // Space Management

        // File storage administration
    });
});
