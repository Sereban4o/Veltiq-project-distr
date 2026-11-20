<?php

namespace App\Services\Files;

use App\Models\File;
use App\Models\User;
use App\Services\Knowledgebase\PageAcl;

/**
 * Access rules for uploaded files.
 *
 * A file may be viewed by its uploader, by admins, or by users who can see the
 * container it is attached to: an issue in a visible project, a page in a
 * visible space (respecting the page ACL), or a message in a chat the user
 * belongs to. Files with no visible attachment stay private to the uploader.
 */
class FileAccess
{
    public function canView(User $user, File $file): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ((int) $file->uploaded_by === (int) $user->id) {
            return true;
        }

        $file->loadMissing([
            'issueAttachments.issue.project',
            'pageAttachments.page.space',
            'messageFiles.message.chat',
        ]);

        if ($this->attachedToVisibleIssue($user, $file)) {
            return true;
        }

        if ($this->attachedToVisiblePage($user, $file)) {
            return true;
        }

        return $this->attachedToVisibleChat($user, $file);
    }

    private function attachedToVisibleIssue(User $user, File $file): bool
    {
        foreach ($file->issueAttachments as $attachment) {
            $project = $attachment->issue?->project;

            if ($project !== null && $project->canView($user)) {
                return true;
            }
        }

        return false;
    }

    private function attachedToVisiblePage(User $user, File $file): bool
    {
        /** @var array<int, PageAcl> $acls */
        $acls = [];

        foreach ($file->pageAttachments as $attachment) {
            $page = $attachment->page;

            if ($page === null || $page->space === null) {
                continue;
            }

            if (! $page->space->canView($user)) {
                continue;
            }

            $acls[$page->space_id] ??= PageAcl::for($page->space, $user);

            if ($acls[$page->space_id]->canView($page)) {
                return true;
            }
        }

        return false;
    }

    private function attachedToVisibleChat(User $user, File $file): bool
    {
        foreach ($file->messageFiles as $messageFile) {
            $chat = $messageFile->message?->chat;

            if ($chat === null) {
                continue;
            }

            if ($chat->members()->where('users.id', $user->id)->exists()) {
                return true;
            }
        }

        return false;
    }
}
