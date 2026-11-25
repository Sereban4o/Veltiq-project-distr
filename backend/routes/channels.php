<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Project channels - same access rule the HTTP layer uses (admin/creator/team/public/member).
Broadcast::channel('project.{projectId}', function ($user, $projectId) {
    if (!$user) return false;

    $project = \App\Models\Project::find($projectId);

    return $project ? $project->canView($user) : false;
});

// Issue channels - view access to the parent project (admin/creator/team/public/member).
Broadcast::channel('issue.{issueId}', function ($user, $issueId) {
    if (!$user) return false;

    $issue = \App\Models\Issue::with('project')->find($issueId);

    return $issue && $issue->project ? $issue->project->canView($user) : false;
});

// Board channels - view access to the parent project (admin/creator/team/public/member).
Broadcast::channel('board.{boardId}', function ($user, $boardId) {
    if (!$user) return false;

    $board = \App\Models\Board::with('project')->find($boardId);

    return $board && $board->project ? $board->project->canView($user) : false;
});

// Space channels
Broadcast::channel('space.{spaceId}', function ($user, $spaceId) {
    if (!$user) return false;

    return (bool) \App\Models\Space::where('id', $spaceId)
        ->whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->orWhere('visibility', 'public')
        ->exists();
});

// Page channels
Broadcast::channel('page.{pageId}', function ($user, $pageId) {
    if (!$user) return false;

    return (bool) \App\Models\Page::where('id', $pageId)
        ->whereHas('space', function ($q) use ($user) {
            $q->whereHas('members', function ($q2) use ($user) {
                $q2->where('user_id', $user->id);
            });
        })
        ->exists();
});

// Page presence channel for collaborative editing
Broadcast::channel('page.{pageId}.presence', function ($user, $pageId) {
    if (!$user) return null;

    return ['id' => $user->id, 'name' => $user->full_name];
});

// User-specific channels (notifications)
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

// Chat channels - only members can subscribe
Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    if (!$user) return false;

    return (bool) \App\Models\Chat::where('id', $chatId)
        ->whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->exists();
});

// Chat presence channel (online members of an open chat)
Broadcast::channel('chat.{chatId}.presence', function ($user, $chatId) {
    if (!$user) return null;

    $isMember = \App\Models\Chat::where('id', $chatId)
        ->whereHas('members', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->exists();

    return $isMember ? ['id' => (int) $user->id, 'name' => $user->full_name] : null;
});
