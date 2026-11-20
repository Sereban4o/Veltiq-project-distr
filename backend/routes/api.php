<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\StorageAdminController;
use App\Http\Controllers\Api\Ai\AssistantController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\QrLoginController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\Calendar\CalendarEventLinkController;
use App\Http\Controllers\Api\Calendar\CalendarFeedController;
use App\Http\Controllers\Api\Calendar\CalendarIcsController;
use App\Http\Controllers\Api\Calendar\CalendarParticipantController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\Chat\ChatController;
use App\Http\Controllers\Api\Chat\MessageController;
use App\Http\Controllers\Api\Chat\MessageSearchController;
use App\Http\Controllers\Api\Chat\PinController;
use App\Http\Controllers\Api\Crm\ActivityController as CrmActivityController;
use App\Http\Controllers\Api\Crm\AutomationRuleController as CrmAutomationRuleController;
use App\Http\Controllers\Api\Crm\CompanyController as CrmCompanyController;
use App\Http\Controllers\Api\Crm\ContactController as CrmContactController;
use App\Http\Controllers\Api\Crm\CounterpartyController as CrmCounterpartyController;
use App\Http\Controllers\Api\Crm\CrmEmailController as CrmEmailController;
use App\Http\Controllers\Api\Crm\CrmFileController as CrmFileController;
use App\Http\Controllers\Api\Crm\CsvController as CrmCsvController;
use App\Http\Controllers\Api\Crm\DashboardController as CrmDashboardController;
use App\Http\Controllers\Api\Crm\DealController as CrmDealController;
use App\Http\Controllers\Api\Crm\PipelineStageController as CrmPipelineStageController;
use App\Http\Controllers\Api\Crm\ReportsController as CrmReportsController;
use App\Http\Controllers\Api\CustomFieldController;
use App\Http\Controllers\Api\FilesController;
use App\Http\Controllers\Api\Integration\OneC\OneCController;
use App\Http\Controllers\Api\Knowledgebase\KnowledgebaseSearchController;
use App\Http\Controllers\Api\Knowledgebase\PageBacklinkController;
use App\Http\Controllers\Api\Knowledgebase\PageController;
use App\Http\Controllers\Api\Knowledgebase\PageExportController;
use App\Http\Controllers\Api\Knowledgebase\PageShareController;
use App\Http\Controllers\Api\Knowledgebase\PageTemplateController;
use App\Http\Controllers\Api\Knowledgebase\PublicPageController;
use App\Http\Controllers\Api\Knowledgebase\SpaceController;
use App\Http\Controllers\Api\Knowledgebase\TasksbaseEmbedController;
use App\Http\Controllers\Api\Mail\MailAccountController;
use App\Http\Controllers\Api\Mail\MailContactController;
use App\Http\Controllers\Api\Mail\MailFolderController;
use App\Http\Controllers\Api\Mail\MailMessageController;
use App\Http\Controllers\Api\Mail\MailRuleController;
use App\Http\Controllers\Api\Mail\MailSignatureController;
use App\Http\Controllers\Api\Mail\MailTemplateController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OfficeController;
use App\Http\Controllers\Api\ReleasesController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\Storage\AuditController as StorageAuditController;
use App\Http\Controllers\Api\Storage\PublicShareController as StoragePublicShareController;
use App\Http\Controllers\Api\Storage\QuotaController as StorageQuotaController;
use App\Http\Controllers\Api\Storage\ShareController as StorageShareController;
use App\Http\Controllers\Api\Storage\TrashController as StorageTrashController;
use App\Http\Controllers\Api\Storage\UploadController as StorageUploadController;
use App\Http\Controllers\Api\Storage\VersionController as StorageVersionController;
use App\Http\Controllers\Api\StorageController;
use App\Http\Controllers\Api\Tasksbase\BoardController;
use App\Http\Controllers\Api\Tasksbase\DashboardController;
use App\Http\Controllers\Api\Tasksbase\EpicController;
use App\Http\Controllers\Api\Tasksbase\FilterController;
use App\Http\Controllers\Api\Tasksbase\IssueChecklistController;
use App\Http\Controllers\Api\Tasksbase\IssueController;
use App\Http\Controllers\Api\Tasksbase\IssueWorklogController;
use App\Http\Controllers\Api\Tasksbase\ProjectController;
use App\Http\Controllers\Api\Tasksbase\ReportController;
use App\Http\Controllers\Api\Tasksbase\SprintController;
use App\Http\Controllers\Api\Tasksbase\TaskAutomationRuleController;
use App\Http\Controllers\Api\Tasksbase\TimesheetController;
use App\Http\Controllers\Api\WidgetsController;
use Illuminate\Http\Request;

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

    // ─── 1С Integration (token + HMAC, no user session) ───
    Route::prefix('integration/1c')->middleware(['one.c', 'throttle:120,1'])->group(function () {
        Route::post('/users/sync', [OneCController::class, 'usersSync']);
        Route::post('/issues/push', [OneCController::class, 'issuesPush']);
        Route::match(['get', 'post'], '/issues/pull', [OneCController::class, 'issuesPull']);
        Route::post('/issues/{externalId}/comment', [OneCController::class, 'issueComment']);
        Route::post('/notifications', [OneCController::class, 'notifications']);
        Route::post('/chats/messages', [OneCController::class, 'chatMessage']);
        Route::post('/files/upload', [OneCController::class, 'fileUpload']);
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
        Route::get('/releases', [ReleasesController::class, 'index']);

        // Notifications
        Route::get('/auth/notifications', [NotificationController::class, 'index']);
        Route::get('/auth/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/auth/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/auth/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::get('/auth/activity', [NotificationController::class, 'activity']);

        // Notification channel preferences (per type + digest frequency)
        Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'index']);
        Route::match(['put', 'patch'], '/notifications/preferences', [NotificationPreferenceController::class, 'update']);

        // ─── Tasksbase Routes ───
        Route::prefix('tasksbase')->group(function () {
            // Projects
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/{key}', [ProjectController::class, 'show']);
            Route::get('/projects/{key}/timeline', [ProjectController::class, 'timeline']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::patch('/projects/{key}', [ProjectController::class, 'update']);
            Route::delete('/projects/{key}', [ProjectController::class, 'destroy']);

            // Members (membership changes trigger a search-index resync)
            Route::get('/projects/{key}/members', [ProjectController::class, 'members']);
            Route::post('/projects/{key}/members', [ProjectController::class, 'addMember']);
            Route::delete('/projects/{key}/members/{userId}', [ProjectController::class, 'removeMember'])
                ->whereNumber('userId');

            // Issues
            Route::get('/issues', [IssueController::class, 'index']);
            Route::get('/issues/{id}', [IssueController::class, 'show']);
            Route::post('/issues', [IssueController::class, 'store']);
            Route::patch('/issues/{id}', [IssueController::class, 'update']);
            Route::delete('/issues/{id}', [IssueController::class, 'destroy']);
            Route::get('/issues/{id}/comments', [IssueController::class, 'comments']);
            Route::post('/issues/{id}/comments', [IssueController::class, 'addComment']);
            Route::patch('/issues/{id}/comments/{commentId}', [IssueController::class, 'updateComment'])->whereNumber('commentId');
            Route::delete('/issues/{id}/comments/{commentId}', [IssueController::class, 'deleteComment'])->whereNumber('commentId');
            Route::post('/issues/{id}/comments/{commentId}/attachments', [IssueController::class, 'addCommentAttachment'])->whereNumber('commentId');
            Route::get('/issues/{id}/mentions', [IssueController::class, 'mentions']);
            Route::get('/issues/{id}/activity', [IssueController::class, 'activity']);
            Route::get('/issues/{id}/checklists', [IssueChecklistController::class, 'index']);
            Route::post('/issues/{id}/checklists', [IssueChecklistController::class, 'store']);
            Route::patch('/issues/{id}/checklists/{checklistId}', [IssueChecklistController::class, 'update'])->whereNumber('checklistId');
            Route::delete('/issues/{id}/checklists/{checklistId}', [IssueChecklistController::class, 'destroy'])->whereNumber('checklistId');
            Route::post('/issues/{id}/checklists/{checklistId}/items', [IssueChecklistController::class, 'storeItem'])->whereNumber('checklistId');
            Route::patch('/issues/{id}/checklists/{checklistId}/items/{itemId}', [IssueChecklistController::class, 'updateItem'])->whereNumber('checklistId')->whereNumber('itemId');
            Route::delete('/issues/{id}/checklists/{checklistId}/items/{itemId}', [IssueChecklistController::class, 'destroyItem'])->whereNumber('checklistId')->whereNumber('itemId');
            Route::get('/issues/{id}/subtasks', [IssueController::class, 'subtasks']);
            Route::post('/issues/{id}/subtasks', [IssueController::class, 'storeSubtask']);
            Route::post('/issues/{id}/attachments', [IssueController::class, 'addAttachment']);
            Route::get('/issues/{id}/links', [IssueController::class, 'links']);
            Route::post('/issues/{id}/links', [IssueController::class, 'addLink']);
            Route::delete('/issues/{id}/links/{linkId}', [IssueController::class, 'removeLink'])->whereNumber('linkId');
            Route::get('/issues/{id}/worklogs', [IssueWorklogController::class, 'index']);
            Route::post('/issues/{id}/worklogs', [IssueWorklogController::class, 'store']);
            Route::patch('/issues/{id}/worklogs/{worklogId}', [IssueWorklogController::class, 'update'])->whereNumber('worklogId');
            Route::delete('/issues/{id}/worklogs/{worklogId}', [IssueWorklogController::class, 'destroy'])->whereNumber('worklogId');
            Route::get('/issues/{id}/timer', [IssueWorklogController::class, 'running']);
            Route::post('/issues/{id}/timer/start', [IssueWorklogController::class, 'startTimer']);
            Route::post('/issues/{id}/timer/stop', [IssueWorklogController::class, 'stopTimer']);
            Route::post('/issues/{id}/move', [IssueController::class, 'moveIssue']);

            // Boards
            Route::get('/boards', [BoardController::class, 'index']);
            Route::get('/boards/{id}', [BoardController::class, 'show']);
            Route::get('/boards/{id}/view', [BoardController::class, 'view']);
            Route::get('/boards/{id}/columns', [BoardController::class, 'columns']);
            Route::post('/boards/{id}/columns', [BoardController::class, 'addColumn'])->whereNumber('id');
            Route::patch('/boards/{id}/columns/{columnId}', [BoardController::class, 'updateColumn'])->whereNumber('id')->whereNumber('columnId');
            Route::delete('/boards/{id}/columns/{columnId}', [BoardController::class, 'deleteColumn'])->whereNumber('id')->whereNumber('columnId');
            Route::post('/boards', function (Request $request) {
                $validated = $request->validate([
                    'project_key' => 'required|exists:projects,key',
                    'name' => 'required|string|max:255',
                    'type' => 'required|in:kanban,scrum',
                ]);

                $project = \App\Models\Project::where('key', $validated['project_key'])->firstOrFail();

                $user = \Illuminate\Support\Facades\Auth::user();
                if (!$user->isAdmin() && !$project->hasPermission($user, 'edit_boards')) {
                    return response()->json(['message' => 'Permission denied'], 403);
                }

                $board = $project->boards()->create([
                    'name' => $validated['name'],
                    'type' => $validated['type'],
                    'created_by' => \Illuminate\Support\Facades\Auth::id(),
                ]);

                return response()->json($board->load('columns'), 201);
            });
            Route::patch('/boards/{id}', [BoardController::class, 'update']);
            Route::delete('/boards/{id}', [BoardController::class, 'destroy']);

            // Sprints
            Route::get('/boards/{boardId}/sprints', [SprintController::class, 'index'])->whereNumber('boardId');
            Route::post('/boards/{boardId}/sprints', [SprintController::class, 'store'])->whereNumber('boardId');
            Route::get('/sprints/{id}', [SprintController::class, 'show'])->whereNumber('id');
            Route::post('/sprints/{id}/start', [SprintController::class, 'start'])->whereNumber('id');
            Route::post('/sprints/{id}/complete', [SprintController::class, 'complete'])->whereNumber('id');
            Route::post('/sprints/{id}/issues', [SprintController::class, 'addIssue'])->whereNumber('id');
            Route::delete('/sprints/{id}/issues/{issueId}', [SprintController::class, 'removeIssue'])->whereNumber('id');
            Route::get('/sprints/{id}/metrics', [SprintController::class, 'metrics'])->whereNumber('id');
            Route::get('/sprints/{id}/burndown', [SprintController::class, 'burndown'])->whereNumber('id');
            Route::get('/sprints/{id}/velocity', [SprintController::class, 'velocity'])->whereNumber('id');
            Route::post('/sprints/{id}/snapshot', [SprintController::class, 'snapshot'])->whereNumber('id');
            Route::patch('/sprints/{id}', [SprintController::class, 'update']);
            Route::delete('/sprints/{id}', [SprintController::class, 'destroy']);

            // Epics
            Route::get('/epics', [EpicController::class, 'index']);
            Route::get('/epics/{id}', [EpicController::class, 'show']);
            Route::post('/epics', [EpicController::class, 'store']);
            Route::patch('/epics/{id}', [EpicController::class, 'update']);
            Route::delete('/epics/{id}', [EpicController::class, 'destroy']);

            // Dashboard
            Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
            Route::get('/dashboard/activity', [DashboardController::class, 'activity']);
            Route::get('/automations', [TaskAutomationRuleController::class, 'index']);
            Route::post('/automations', [TaskAutomationRuleController::class, 'store']);
            Route::patch('/automations/{rule}', [TaskAutomationRuleController::class, 'update'])->whereNumber('rule');
            Route::delete('/automations/{rule}', [TaskAutomationRuleController::class, 'destroy'])->whereNumber('rule');
            Route::get('/timesheet', [TimesheetController::class, 'index']);
            Route::get('/timesheet/export', [TimesheetController::class, 'export']);

            // Report
            Route::get('/report/completion', [ReportController::class, 'completion']);
            Route::get('/report/workload', [ReportController::class, 'workload']);

            // Saved filters (personal, per user)
            Route::get('/filters', [FilterController::class, 'index']);
            Route::post('/filters', [FilterController::class, 'store']);
            Route::get('/filters/{id}', [FilterController::class, 'show'])->whereNumber('id');
            Route::patch('/filters/{id}', [FilterController::class, 'update'])->whereNumber('id');
            Route::delete('/filters/{id}', [FilterController::class, 'destroy'])->whereNumber('id');
            Route::get('/filters/{id}/execute', [FilterController::class, 'execute'])->whereNumber('id');
        });

        // ─── Knowledgebase Routes ───
        Route::prefix('knowledgebase')->group(function () {
            // Spaces
            Route::get('/spaces', [SpaceController::class, 'index']);
            Route::get('/spaces/{key}', [SpaceController::class, 'show']);
            Route::post('/spaces', [SpaceController::class, 'store']);
            Route::patch('/spaces/{key}', [SpaceController::class, 'update']);
            Route::delete('/spaces/{key}', [SpaceController::class, 'destroy']);

            // Members (membership changes trigger a search-index resync)
            Route::get('/spaces/{key}/members', [SpaceController::class, 'members']);
            Route::post('/spaces/{key}/members', [SpaceController::class, 'addMember']);
            Route::delete('/spaces/{key}/members/{userId}', [SpaceController::class, 'removeMember'])
                ->whereNumber('userId');

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
            Route::get('/pages', [PageController::class, 'index']);
            Route::get('/spaces/{spaceKey}/pages', [PageController::class, 'spacePages']);
            Route::post('/spaces/{spaceKey}/pages', [PageController::class, 'store']);
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

        // ─── Chat Routes ───
        Route::prefix('chat')->group(function () {
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

        // ─── CRM Routes ───
        Route::prefix('crm')->group(function () {
            Route::get('/dashboard', [CrmDashboardController::class, 'index']);
            Route::get('/stages', [CrmPipelineStageController::class, 'index']);

            // Reports
            Route::get('/reports/funnel', [CrmReportsController::class, 'funnel']);
            Route::get('/reports/conversion', [CrmReportsController::class, 'conversion']);
            Route::get('/reports/revenue', [CrmReportsController::class, 'revenue']);
            Route::get('/reports/summary', [CrmReportsController::class, 'summary']);

            // CSV export / import
            Route::get('/companies/export', [CrmCsvController::class, 'export'])->defaults('entity', 'companies');
            Route::post('/companies/import', [CrmCsvController::class, 'import'])->defaults('entity', 'companies');
            Route::get('/contacts/export', [CrmCsvController::class, 'export'])->defaults('entity', 'contacts');
            Route::post('/contacts/import', [CrmCsvController::class, 'import'])->defaults('entity', 'contacts');
            Route::get('/deals/export', [CrmCsvController::class, 'export'])->defaults('entity', 'deals');
            Route::post('/deals/import', [CrmCsvController::class, 'import'])->defaults('entity', 'deals');

            Route::get('/deals/stats', [CrmDealController::class, 'stats']);
            Route::get('/deals', [CrmDealController::class, 'index']);
            Route::post('/deals', [CrmDealController::class, 'store']);
            Route::get('/deals/{deal}', [CrmDealController::class, 'show'])->whereNumber('deal');
            Route::patch('/deals/{deal}', [CrmDealController::class, 'update'])->whereNumber('deal');
            Route::patch('/deals/{deal}/stage', [CrmDealController::class, 'moveStage'])->whereNumber('deal');
            Route::delete('/deals/{deal}', [CrmDealController::class, 'destroy'])->whereNumber('deal');

            Route::get('/companies', [CrmCompanyController::class, 'index']);
            Route::post('/companies', [CrmCompanyController::class, 'store']);
            Route::get('/companies/{company}', [CrmCompanyController::class, 'show'])->whereNumber('company');
            Route::patch('/companies/{company}', [CrmCompanyController::class, 'update'])->whereNumber('company');
            Route::delete('/companies/{company}', [CrmCompanyController::class, 'destroy'])->whereNumber('company');

            // Counterparties (1С CounterpartiesExchange)
            Route::get('/counterparties/search-1c', [CrmCounterpartyController::class, 'searchOneC'])->middleware('throttle:30,1');
            Route::get('/counterparties', [CrmCounterpartyController::class, 'index']);
            Route::post('/counterparties', [CrmCounterpartyController::class, 'store']);
            Route::patch('/counterparties/{company}', [CrmCounterpartyController::class, 'update'])->whereNumber('company');

            Route::get('/contacts', [CrmContactController::class, 'index']);
            Route::post('/contacts', [CrmContactController::class, 'store']);
            Route::get('/contacts/{contact}', [CrmContactController::class, 'show'])->whereNumber('contact');
            Route::patch('/contacts/{contact}', [CrmContactController::class, 'update'])->whereNumber('contact');
            Route::delete('/contacts/{contact}', [CrmContactController::class, 'destroy'])->whereNumber('contact');

            Route::get('/activities', [CrmActivityController::class, 'index']);
            Route::post('/activities', [CrmActivityController::class, 'store']);
            Route::patch('/activities/{activity}', [CrmActivityController::class, 'update'])->whereNumber('activity');
            Route::patch('/activities/{activity}/toggle', [CrmActivityController::class, 'toggle'])->whereNumber('activity');
            Route::delete('/activities/{activity}', [CrmActivityController::class, 'destroy'])->whereNumber('activity');

            // Automations (admin only)
            Route::middleware('admin')->group(function () {
                Route::get('/automation-rules', [CrmAutomationRuleController::class, 'index']);
                Route::post('/automation-rules', [CrmAutomationRuleController::class, 'store']);
                Route::patch('/automation-rules/{rule}', [CrmAutomationRuleController::class, 'update'])->whereNumber('rule');
                Route::delete('/automation-rules/{rule}', [CrmAutomationRuleController::class, 'destroy'])->whereNumber('rule');
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

        // ─── Calendar Routes ───
        Route::prefix('calendar')->group(function () {
            Route::get('/', [CalendarController::class, 'index']);

            Route::get('/events', [CalendarController::class, 'indexEvents']);
            Route::get('/free-slots', [CalendarController::class, 'freeSlots']);
            Route::post('/events', [CalendarController::class, 'store']);
            Route::post('/events/recurrence-preview', [CalendarController::class, 'recurrencePreview']);
            Route::get('/events/{event}', [CalendarController::class, 'show'])->whereNumber('event');
            Route::patch('/events/{event}', [CalendarController::class, 'update'])->whereNumber('event');
            Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->whereNumber('event');

            Route::post('/events/{event}/participants', [CalendarParticipantController::class, 'store'])->whereNumber('event');
            Route::delete('/events/{event}/participants/{attendee}', [CalendarParticipantController::class, 'destroy'])
                ->whereNumber('event')->whereNumber('attendee');
            Route::post('/events/{event}/rsvp', [CalendarParticipantController::class, 'rsvp'])->whereNumber('event');

            Route::post('/events/{event}/links', [CalendarEventLinkController::class, 'store'])->whereNumber('event');
            Route::delete('/events/{event}/links/{link}', [CalendarEventLinkController::class, 'destroy'])
                ->whereNumber('event')->whereNumber('link');

            Route::get('/events/{event}/ics', [CalendarIcsController::class, 'exportEvent'])->whereNumber('event');
            Route::get('/ics/export', [CalendarIcsController::class, 'exportRange']);
            Route::post('/ics/import', [CalendarIcsController::class, 'import'])->middleware('throttle:10,1');

            Route::get('/feeds', [CalendarFeedController::class, 'index']);
            Route::post('/feeds', [CalendarFeedController::class, 'store'])->middleware('throttle:10,1');
            Route::patch('/feeds/{feed}', [CalendarFeedController::class, 'update'])->whereNumber('feed');
            Route::delete('/feeds/{feed}', [CalendarFeedController::class, 'destroy'])->whereNumber('feed');
            Route::post('/feeds/{feed}/sync', [CalendarFeedController::class, 'sync'])->whereNumber('feed')->middleware('throttle:10,1');
        });

        // ─── Mail Routes ───
        Route::prefix('mail')->group(function () {
            Route::get('/settings', [MailAccountController::class, 'show']);
            Route::put('/settings', [MailAccountController::class, 'update']);
            Route::post('/settings/test', [MailAccountController::class, 'test']);
            Route::post('/sync', [MailAccountController::class, 'sync']);

            // Shared mailbox (team inbox) membership
            Route::get('/accounts', [MailAccountController::class, 'index']);
            Route::get('/settings/members', [MailAccountController::class, 'members']);
            Route::post('/settings/members', [MailAccountController::class, 'addMember']);
            Route::delete('/settings/members/{user}', [MailAccountController::class, 'removeMember'])->whereNumber('user');

            // Custom folders + filter rules
            Route::get('/folders', [MailFolderController::class, 'index']);
            Route::post('/folders', [MailFolderController::class, 'store']);
            Route::post('/folders/from-sender', [MailFolderController::class, 'fromSender']);
            Route::patch('/folders/{folder}', [MailFolderController::class, 'update'])->whereNumber('folder');
            Route::delete('/folders/{folder}', [MailFolderController::class, 'destroy'])->whereNumber('folder');

            Route::get('/rules', [MailRuleController::class, 'index']);
            Route::post('/rules/reorder', [MailRuleController::class, 'reorder']);
            Route::post('/rules/apply', [MailRuleController::class, 'apply']);
            Route::post('/rules', [MailRuleController::class, 'store']);
            Route::patch('/rules/{rule}', [MailRuleController::class, 'update'])->whereNumber('rule');
            Route::delete('/rules/{rule}', [MailRuleController::class, 'destroy'])->whereNumber('rule');

            // Shared address book
            Route::get('/contacts', [MailContactController::class, 'index']);
            Route::get('/contacts/suggest', [MailContactController::class, 'suggest']);
            Route::post('/contacts', [MailContactController::class, 'store']);
            Route::patch('/contacts/{contact}', [MailContactController::class, 'update'])->whereNumber('contact');
            Route::delete('/contacts/{contact}', [MailContactController::class, 'destroy'])->whereNumber('contact');

            // Signatures
            Route::get('/signatures', [MailSignatureController::class, 'index']);
            Route::post('/signatures', [MailSignatureController::class, 'store']);
            Route::patch('/signatures/{signature}', [MailSignatureController::class, 'update'])->whereNumber('signature');
            Route::delete('/signatures/{signature}', [MailSignatureController::class, 'destroy'])->whereNumber('signature');

            // Templates
            Route::get('/templates', [MailTemplateController::class, 'index']);
            Route::post('/templates', [MailTemplateController::class, 'store']);
            Route::patch('/templates/{template}', [MailTemplateController::class, 'update'])->whereNumber('template');
            Route::delete('/templates/{template}', [MailTemplateController::class, 'destroy'])->whereNumber('template');

            // Empty trash on the mail server, then locally
            Route::delete('/trash', [MailMessageController::class, 'emptyTrash']);

            Route::get('/messages', [MailMessageController::class, 'index']);
            Route::post('/messages', [MailMessageController::class, 'send']);
            Route::post('/messages/bulk', [MailMessageController::class, 'bulk']);
            Route::get('/threads/{threadKey}', [MailMessageController::class, 'thread'])->where('threadKey', '[a-f0-9]{40}');
            Route::post('/drafts', [MailMessageController::class, 'storeDraft']);
            Route::get('/messages/{message}', [MailMessageController::class, 'show'])->whereNumber('message');
            Route::patch('/messages/{message}', [MailMessageController::class, 'update'])->whereNumber('message');
            Route::delete('/messages/{message}', [MailMessageController::class, 'destroy'])->whereNumber('message');
            Route::post('/messages/{message}/contacts', [MailContactController::class, 'importFromMessage'])->whereNumber('message');
            Route::get('/messages/{message}/attachments/{attachment}', [MailMessageController::class, 'downloadAttachment'])
                ->whereNumber('message')->whereNumber('attachment');
        });

        // ─── File storage browser ───
        Route::prefix('storage')->group(function () {
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
            Route::post('/upload', [StorageUploadController::class, 'store']);
            Route::post('/uploads', [StorageUploadController::class, 'createSession']);
            Route::put('/uploads/{uploadId}/chunks/{index}', [StorageUploadController::class, 'putChunk'])->whereNumber('index');
            Route::get('/uploads/{uploadId}', [StorageUploadController::class, 'status']);
            Route::post('/uploads/{uploadId}/complete', [StorageUploadController::class, 'complete']);
            Route::delete('/uploads/{uploadId}', [StorageUploadController::class, 'cancel']);

            // Versions
            Route::get('/versions', [StorageVersionController::class, 'index']);
            Route::post('/versions/{version}/restore', [StorageVersionController::class, 'restore'])->whereNumber('version');
            Route::get('/versions/{version}/download', [StorageVersionController::class, 'download'])->whereNumber('version');
            Route::delete('/versions/{version}', [StorageVersionController::class, 'destroy'])->whereNumber('version');

            // Trash
            Route::get('/trash', [StorageTrashController::class, 'index']);
            Route::delete('/trash', [StorageTrashController::class, 'empty']);
            Route::post('/trash/{id}/restore', [StorageTrashController::class, 'restore'])->whereNumber('id');
            Route::delete('/trash/{id}', [StorageTrashController::class, 'destroy'])->whereNumber('id');

            // Public share links
            Route::get('/shares', [StorageShareController::class, 'index']);
            Route::post('/shares', [StorageShareController::class, 'store']);
            Route::delete('/shares/{id}', [StorageShareController::class, 'destroy'])->whereNumber('id');

            // Quota and access journal
            Route::get('/quota', [StorageQuotaController::class, 'show']);
            Route::get('/audit', [StorageAuditController::class, 'index']);

            // ONLYOFFICE editor config (auth required)
            Route::get('/office/config', [OfficeController::class, 'config']);
        });

        // ─── File Routes (inside sanctum for upload/delete) ───
        Route::post('/files/upload', [FilesController::class, 'upload']);
        Route::delete('/files/{id}', [FilesController::class, 'destroy']);

        // ─── Global Search (authenticated, filtered per user) ───
        Route::get('/search', [SearchController::class, 'index']);

        // ─── AI assistant (local Ollama + permission-aware retrieval) ───
        Route::get('/ai/status', [AssistantController::class, 'status']);
        Route::post('/ai/ask', [AssistantController::class, 'ask'])->middleware('throttle:30,1');
        Route::post('/ai/summarize', [AssistantController::class, 'summarize'])->middleware('throttle:30,1');
        Route::post('/ai/draft', [AssistantController::class, 'draft'])->middleware('throttle:30,1');

        // ─── Embeddable iframe widgets (§12 extension point) ───
        Route::get('/widgets', [WidgetsController::class, 'index']);

        // ─── Custom fields (§12 extension point) ───
        Route::get('/custom-fields', [CustomFieldController::class, 'index']);
        Route::patch('/issues/{issueId}/custom-fields', [CustomFieldController::class, 'updateIssue']);
    });

    // ─── ONLYOFFICE Document Server (token-protected, no session) ───
    Route::get('/storage/office/download', [OfficeController::class, 'download']);
    Route::post('/storage/office/callback', [OfficeController::class, 'callback']);

    // ─── Public share links (anonymous, throttled) ───
    Route::prefix('storage/public')->middleware('throttle:30,1')->group(function () {
        Route::get('/share/{token}', [StoragePublicShareController::class, 'show']);
        Route::post('/share/{token}/unlock', [StoragePublicShareController::class, 'unlock'])->middleware('throttle:10,1');
        Route::get('/share/{token}/raw', [StoragePublicShareController::class, 'raw']);
        Route::get('/share/{token}/download', [StoragePublicShareController::class, 'download']);
        Route::get('/share/{token}/preview', [StoragePublicShareController::class, 'preview']);
        Route::get('/share/{token}/archive', [StoragePublicShareController::class, 'archive']);
    });
    Route::get('/crm/files/{file}/office/download', [CrmFileController::class, 'officeDownload'])->whereNumber('file');
    Route::post('/crm/files/{file}/office/callback', [CrmFileController::class, 'officeCallback'])->whereNumber('file');

    // ─── Public knowledgebase page (anonymous, throttled) ───
    Route::get('/public/pages/{token}', [PublicPageController::class, 'show'])->middleware('throttle:60,1');

    // ─── Public File View (supports ?token= for <img> tags) ───
    Route::get('/files/{id}', [FilesController::class, 'show']);
    Route::get('/files/{id}/download', [FilesController::class, 'download']);

    // ─── Admin Routes ───
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
        // User Management
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/users/{id}', [AdminController::class, 'userShow']);
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
        Route::get('/spaces', [AdminController::class, 'spaces']);
        Route::post('/spaces', [AdminController::class, 'storeSpace']);
        Route::patch('/spaces/{id}', [AdminController::class, 'updateSpace']);
        Route::delete('/spaces/{id}', [AdminController::class, 'destroySpace']);

        // File storage administration
        Route::get('/storage/quotas', [StorageAdminController::class, 'quotas']);
        Route::put('/storage/quotas/{userId}', [StorageAdminController::class, 'updateQuota'])->whereNumber('userId');
        Route::get('/storage/settings', [StorageAdminController::class, 'settings']);
        Route::put('/storage/settings', [StorageAdminController::class, 'updateSettings']);
        Route::get('/storage/audit', [StorageAdminController::class, 'audit']);
        Route::post('/storage/recalculate', [StorageAdminController::class, 'recalculate']);
    });
});
