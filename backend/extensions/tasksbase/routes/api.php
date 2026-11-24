<?php

/*
| Tasks module routes (projects, issues, kanban boards, sprints, epics,
| dashboards, saved filters). Gated by extension.enabled:tasksbase.
*/

use App\Extensions\Tasksbase\Http\Controllers\BoardController;
use App\Extensions\Tasksbase\Http\Controllers\DashboardController;
use App\Extensions\Tasksbase\Http\Controllers\EpicController;
use App\Extensions\Tasksbase\Http\Controllers\FilterController;
use App\Extensions\Tasksbase\Http\Controllers\IssueChecklistController;
use App\Extensions\Tasksbase\Http\Controllers\IssueController;
use App\Extensions\Tasksbase\Http\Controllers\ProjectController;
use App\Extensions\Tasksbase\Http\Controllers\SprintController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('api/v1')->group(function () {
    Route::prefix('tasksbase')->middleware(['extension.enabled:tasksbase', 'auth:sanctum'])->group(function () {
        // Projects
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{key}', [ProjectController::class, 'show']);
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
        Route::post('/issues/{id}/move', [IssueController::class, 'moveIssue']);

        // Boards
        Route::get('/boards', [BoardController::class, 'index']);
        Route::get('/boards/{id}', [BoardController::class, 'show']);
        Route::get('/boards/{id}/view', [BoardController::class, 'view']);
        Route::get('/boards/{id}/columns', [BoardController::class, 'columns']);
        Route::post('/boards/{id}/columns', [BoardController::class, 'addColumn'])->whereNumber('id');
        Route::patch('/boards/{id}/columns/{columnId}', [BoardController::class, 'updateColumn'])->whereNumber('id')->whereNumber('columnId');
        Route::delete('/boards/{id}/columns/{columnId}', [BoardController::class, 'deleteColumn'])->whereNumber('id')->whereNumber('columnId');
        Route::post('/boards', [BoardController::class, 'store']);
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

        // Saved filters (personal, per user)
        Route::get('/filters', [FilterController::class, 'index']);
        Route::post('/filters', [FilterController::class, 'store']);
        Route::get('/filters/{id}', [FilterController::class, 'show'])->whereNumber('id');
        Route::patch('/filters/{id}', [FilterController::class, 'update'])->whereNumber('id');
        Route::delete('/filters/{id}', [FilterController::class, 'destroy'])->whereNumber('id');
        Route::get('/filters/{id}/execute', [FilterController::class, 'execute'])->whereNumber('id');
    });
});
