<?php

namespace App\Http\Controllers\Api\Knowledgebase;

use App\Events\PageUpdated;
use App\Http\Controllers\Controller;
use App\Models\File;
use App\Models\Page;
use App\Models\PageAttachment;
use App\Models\PageComment;
use App\Models\PageFollower;
use App\Models\PageLabel;
use App\Models\PagePermission;
use App\Models\PageTemplate;
use App\Models\PageVersion;
use App\Models\Space;
use App\Services\Knowledgebase\HtmlDiff;
use App\Services\Knowledgebase\PageAcl;
use App\Services\Tasksbase\NotificationService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PageController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
    ) {
    }

    public function index(Request $request, string $spaceKey)
    {
        $space = Space::where('key', $spaceKey)->firstOrFail();

        if (!$space->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $query = Page::where('space_id', $space->id)
            ->with(['author', 'space', 'parent'])
            ->whereNull('parent_id');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $query->search($request->search);
        }

        $pages = $query->latest()->paginate(20);

        return response()->json($pages);
    }

    public function spacePages(Request $request, string $spaceKey)
    {
        $space = Space::where('key', $spaceKey)->firstOrFail();

        if (!$space->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $pages = Page::where('space_id', $space->id)
            ->with(['author', 'space'])
            ->latest()
            ->get();

        return response()->json($pages);
    }

    /**
     * Flat, ACL-filtered list of the space's pages.
     *
     * Returns every visible page as a single-level node carrying its own
     * `parent_id`/`position` (the client reconstructs the tree), plus the
     * `has_children` and `can_edit` capabilities computed for the current user.
     */
    public function tree(Request $request, string $spaceKey)
    {
        $space = Space::where('key', $spaceKey)->firstOrFail();
        $user = Auth::user();

        if (!$space->canView($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $acl = PageAcl::for($space, $user);

        $pages = Page::where('space_id', $space->id)
            ->with('author')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (Page $page) => $acl->canView($page))
            ->values();

        $parentIds = $pages->pluck('parent_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $nodes = $pages->map(function (Page $page) use ($acl, $parentIds) {
            $metadata = is_array($page->metadata) ? $page->metadata : [];

            return [
                'id' => (int) $page->id,
                'space_id' => (int) $page->space_id,
                'parent_id' => $page->parent_id !== null ? (int) $page->parent_id : null,
                'position' => (int) $page->position,
                'title' => $page->title,
                'status' => $page->status,
                'labels' => $page->labels ?? [],
                'icon' => $metadata['icon'] ?? null,
                'has_children' => in_array((int) $page->id, $parentIds, true),
                'can_edit' => $acl->canEdit($page),
                'updated_at' => $page->updated_at,
                'author' => $page->author,
            ];
        })->values();

        return response()->json($nodes);
    }

    public function show(Request $request, string $pageId)
    {
        $page = Page::with(['space', 'author', 'lastEditor', 'parent', 'children'])
            ->findOrFail($pageId);

        $user = Auth::user();

        if (!$page->space || !$page->canView($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $acl = PageAcl::for($page->space, $user);

        $payload = $page->toArray();
        $payload['can_edit'] = $acl->canEdit($page);
        $payload['can_manage_permissions'] = $acl->canManagePermissions($page);
        $payload['can_comment'] = $acl->canView($page);
        $payload['is_following'] = PageFollower::where('page_id', $page->id)
            ->where('user_id', $user->id)
            ->exists();
        $payload['followers_count'] = PageFollower::where('page_id', $page->id)->count();

        return response()->json($payload);
    }

    public function store(Request $request, string $spaceKey)
    {
        $space = Space::where('key', $spaceKey)->firstOrFail();

        if (!$space->canEdit(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'parent_id' => 'nullable|integer|exists:pages,id',
            'template_id' => 'nullable|integer|exists:page_templates,id',
            'title' => 'required|string|max:500',
            'body' => 'nullable|string',
            'status' => 'sometimes|in:draft,published,archived',
            'labels' => 'nullable|array',
            'labels.*' => 'string|max:50',
        ]);

        $parentId = isset($validated['parent_id']) ? (int) $validated['parent_id'] : null;

        // A page may only be nested inside another page of its own space.
        if ($parentId !== null
            && !Page::where('space_id', $space->id)->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages([
                'parent_id' => 'Parent page must belong to the same space.',
            ]);
        }

        $body = $validated['body'] ?? null;
        $labels = $validated['labels'] ?? [];

        // Seed the new page from a template (space-scoped or built-in global)
        // when the caller does not supply its own body/labels.
        if (!empty($validated['template_id'])) {
            $template = PageTemplate::where('id', $validated['template_id'])
                ->where(function ($q) use ($space) {
                    $q->where('space_id', $space->id)->orWhereNull('space_id');
                })
                ->first();

            if (!$template) {
                throw ValidationException::withMessages([
                    'template_id' => 'Template must belong to this space or be a global template.',
                ]);
            }

            if ($body === null) {
                $body = $template->body ?? '';
            }

            if (empty($labels)) {
                $labels = $template->labels ?? [];
            }
        }

        $body = HtmlSanitizer::clean($body ?? '');

        $page = Page::create([
            'space_id' => $space->id,
            'parent_id' => $parentId,
            'author_id' => Auth::id(),
            'last_edited_by' => Auth::id(),
            'title' => $validated['title'],
            'body' => $body,
            'status' => $validated['status'] ?? 'draft',
            'version' => 1,
            'labels' => $labels,
        ]);

        // Create initial version
        PageVersion::create([
            'page_id' => $page->id,
            'version' => 1,
            'author_id' => Auth::id(),
            'summary' => 'Initial version',
            'body_snapshot' => $body,
        ]);

        // Save labels
        foreach ($labels as $label) {
            PageLabel::create([
                'page_id' => $page->id,
                'label' => strtolower((string) $label),
            ]);
        }

        return response()->json($page, 201);
    }

    public function update(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);
        $user = Auth::user();

        if (!$page->space || !PageAcl::for($page->space, $user)->canEdit($page)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:500',
            'body' => 'nullable|string',
            'status' => 'sometimes|in:draft,published,archived',
            'labels' => 'nullable|array',
            'labels.*' => 'string|max:50',
        ]);

        $bodyChanged = false;

        if (array_key_exists('body', $validated)) {
            $validated['body'] = HtmlSanitizer::clean($validated['body']);
            $bodyChanged = $validated['body'] !== $page->body;
        }

        $attributes = [
            ...$validated,
            'last_edited_by' => Auth::id(),
        ];

        // Only a content change produces a new version; metadata-only edits
        // (title, status, labels) leave the version untouched.
        if ($bodyChanged) {
            $newVersion = $page->version + 1;

            PageVersion::create([
                'page_id' => $page->id,
                'version' => $newVersion,
                'author_id' => Auth::id(),
                'summary' => $request->input('version_summary', 'Updated'),
                'body_snapshot' => $validated['body'] ?? $page->body,
            ]);

            $attributes['version'] = $newVersion;
        }

        $page->update($attributes);

        // Update labels
        if (isset($validated['labels'])) {
            PageLabel::where('page_id', $page->id)->delete();
            foreach ($validated['labels'] as $label) {
                PageLabel::create([
                    'page_id' => $page->id,
                    'label' => strtolower($label),
                ]);
            }
        }

        event(new PageUpdated($page, 'updated'));

        $contentChanged = $bodyChanged
            || array_key_exists('title', $validated)
            || array_key_exists('status', $validated);

        if ($contentChanged) {
            $this->notifyFollowers($page);
        }

        return response()->json($page->load(['space', 'author', 'lastEditor']));
    }

    public function follow(string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (! $page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        PageFollower::firstOrCreate(['page_id' => $page->id, 'user_id' => Auth::id()]);

        return response()->json(['following' => true]);
    }

    public function unfollow(string $pageId)
    {
        $page = Page::findOrFail($pageId);

        PageFollower::where('page_id', $page->id)
            ->where('user_id', Auth::id())
            ->delete();

        return response()->json(['following' => false]);
    }

    /**
     * Notify everyone watching the page (except the editor).
     */
    private function notifyFollowers(Page $page): void
    {
        $spaceKey = $page->space?->key;
        $url = $spaceKey !== null ? "/knowledgebase/{$spaceKey}/pages/{$page->id}" : null;

        $followerIds = PageFollower::where('page_id', $page->id)
            ->where('user_id', '!=', Auth::id())
            ->pluck('user_id');

        foreach ($followerIds as $followerId) {
            try {
                $this->notifications->notify(
                    (int) $followerId,
                    'page_updated',
                    $page,
                    [
                        'page_id' => (int) $page->id,
                        'title' => $page->title,
                        'space_key' => $spaceKey,
                        'url' => $url,
                    ],
                    Auth::id(),
                    'Страница обновлена',
                    (string) $page->title,
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::debug('Page follower notification failed', [
                    'page_id' => $page->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function destroy(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->space || !$page->space->canEdit(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        // Soft-delete the page together with its whole subtree so descendants
        // do not linger as orphans.
        Page::whereIn('id', $this->subtreeIds($page))->delete();

        return response()->json(['message' => 'Page deleted']);
    }

    /**
     * Ids of a page and every descendant below it.
     *
     * @return list<int>
     */
    private function subtreeIds(Page $page): array
    {
        $ids = [(int) $page->id];
        $frontier = [(int) $page->id];

        while (!empty($frontier)) {
            $children = Page::whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => in_array($id, $ids, true))
                ->values()
                ->all();

            if (empty($children)) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return array_values(array_unique($ids));
    }

    public function publish(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        $page->update(['status' => 'published']);

        return response()->json($page);
    }

    public function move(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->space || !PageAcl::for($page->space, Auth::user())->canEdit($page)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'parent_id' => 'nullable|integer|exists:pages,id',
            'position' => 'nullable|integer|min:0',
        ]);

        $parentId = isset($validated['parent_id'])
            ? (int) $validated['parent_id']
            : ($page->parent_id !== null ? (int) $page->parent_id : null);

        if ($parentId !== null) {
            // A page can only be nested inside a page of its own space…
            $parent = Page::where('space_id', $page->space_id)->find($parentId);

            if (!$parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Parent page must belong to the same space.',
                ]);
            }

            // …and never inside its own subtree, which would detach the branch.
            $parentIds = Page::where('space_id', $page->space_id)->pluck('parent_id', 'id');
            $ancestorId = $parentId;
            $guard = 0;

            while ($ancestorId !== null && $guard++ < 500) {
                if ($ancestorId === (int) $page->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'A page cannot be moved into its own subtree.',
                    ]);
                }

                $ancestorId = isset($parentIds[$ancestorId]) ? (int) $parentIds[$ancestorId] : null;
            }
        }

        $oldParentId = $page->parent_id !== null ? (int) $page->parent_id : null;

        $page->update(['parent_id' => $parentId]);

        // Insert the page at the requested index among its new siblings and
        // renumber the whole list, so positions stay dense (0..n-1).
        $siblings = Page::where('space_id', $page->space_id)
            ->where('parent_id', $parentId)
            ->whereKeyNot($page->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('id');

        $position = isset($validated['position']) ? (int) $validated['position'] : $siblings->count();
        $position = max(0, min($position, $siblings->count()));

        $ordered = $siblings->all();
        array_splice($ordered, $position, 0, [(int) $page->id]);

        foreach ($ordered as $index => $id) {
            Page::whereKey($id)->update(['position' => $index]);
        }

        // Close the gap left behind in the previous sibling list.
        if ($oldParentId !== $parentId) {
            $source = Page::where('space_id', $page->space_id)
                ->where('parent_id', $oldParentId)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id');

            foreach ($source as $index => $id) {
                Page::whereKey($id)->update(['position' => $index]);
            }
        }

        return response()->json($page->refresh());
    }

    public function restore(Request $request, string $pageId, string $version)
    {
        $page = Page::findOrFail($pageId);

        $pageVersion = PageVersion::where('page_id', $pageId)
            ->where('version', $version)
            ->firstOrFail();

        $newVersion = $page->version + 1;

        PageVersion::create([
            'page_id' => $page->id,
            'version' => $newVersion,
            'author_id' => Auth::id(),
            'summary' => "Restored from version {$version}",
            'body_snapshot' => $pageVersion->body_snapshot,
        ]);

        $page->update([
            'body' => $pageVersion->body_snapshot,
            'version' => $newVersion,
            'last_edited_by' => Auth::id(),
        ]);

        return response()->json($page);
    }

    public function versions(Request $request, string $pageId)
    {
        $versions = PageVersion::where('page_id', $pageId)
            ->with('author')
            ->orderBy('version', 'desc')
            ->get();

        return response()->json($versions);
    }

    /**
     * GET /pages/{id}/diff?from=&to= — textual diff between two versions.
     */
    public function diff(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $from = (int) $request->query('from', 0);
        $to = (int) $request->query('to', 0);

        if ($from <= 0) {
            $from = max(1, (int) $page->version - 1);
        }

        if ($to <= 0) {
            $to = (int) $page->version;
        }

        $version1 = PageVersion::where('page_id', $pageId)
            ->where('version', $from)
            ->firstOrFail();

        $version2 = PageVersion::where('page_id', $pageId)
            ->where('version', $to)
            ->firstOrFail();

        return response()->json([
            'version_a' => $version1,
            'version_b' => $version2,
            'diff' => HtmlDiff::compute($version1->body_snapshot, $version2->body_snapshot),
        ]);
    }

    public function comments(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $comments = PageComment::where('page_id', $pageId)
            ->with('user')
            ->latest()
            ->get();

        return response()->json($comments);
    }

    public function addComment(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        // Any user who can view the page may leave a comment.
        if (!$page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string',
            'line_number' => 'nullable|integer',
            'column_number' => 'nullable|integer',
        ]);

        $comment = PageComment::create([
            'page_id' => $page->id,
            'user_id' => Auth::id(),
            'body' => $validated['body'],
            'line_number' => $validated['line_number'] ?? null,
            'column_number' => $validated['column_number'] ?? null,
        ]);

        return response()->json($comment->load('user'), 201);
    }

    /**
     * PATCH /pages/{id}/comments/{commentId}/resolve — toggle a comment's
     * resolved flag. The comment author or any page editor/admin may resolve.
     */
    public function resolveComment(Request $request, string $pageId, string $commentId)
    {
        $page = Page::findOrFail($pageId);
        $user = Auth::user();

        if (!$page->space || !PageAcl::for($page->space, $user)->canView($page)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $comment = PageComment::where('page_id', $page->id)
            ->where('id', $commentId)
            ->firstOrFail();

        $canResolve = (int) $comment->user_id === (int) $user->id
            || PageAcl::for($page->space, $user)->canEdit($page);

        if (!$canResolve) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'resolved' => 'required|boolean',
        ]);

        $comment->update(['resolved' => $validated['resolved']]);

        return response()->json($comment->load('user'));
    }

    public function updateComment(Request $request, string $pageId, string $commentId)
    {
        $comment = PageComment::where('page_id', $pageId)
            ->where('id', $commentId)
            ->where('user_id', Auth::id())
            ->firstOrFail();

        $validated = $request->validate([
            'body' => 'required|string',
        ]);

        $comment->update(['body' => $validated['body']]);

        return response()->json($comment);
    }

    public function deleteComment(Request $request, string $pageId, string $commentId)
    {
        $comment = PageComment::where('page_id', $pageId)
            ->where('id', $commentId)
            ->where(function ($q) {
                $q->where('user_id', Auth::id())
                  ->orWhereHas('page.space', function ($q) {
                      $q->whereHas('members', function ($q) {
                          $q->where('user_id', Auth::id())
                            ->where('role', 'admin');
                      });
                  });
            })
            ->firstOrFail();

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    public function updateLabels(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        $validated = $request->validate([
            'labels' => 'required|array',
            'labels.*' => 'string|max:50',
        ]);

        PageLabel::where('page_id', $page->id)->delete();

        foreach ($validated['labels'] as $label) {
            PageLabel::create([
                'page_id' => $page->id,
                'label' => strtolower($label),
            ]);
        }

        $page->update(['labels' => array_map('strtolower', $validated['labels'])]);

        return response()->json($page->load('labels'));
    }

    /**
     * GET /pages/{id}/permissions — explicit ACL entries plus whether the
     * current user may edit them (drives the permissions dialog).
     */
    public function permissions(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $acl = PageAcl::for($page->space, Auth::user());

        return response()->json([
            'permissions' => PagePermission::where('page_id', $page->id)->with('user')->get(),
            'can_manage' => $acl->canManagePermissions($page),
        ]);
    }

    public function updatePermissions(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->space || !PageAcl::for($page->space, Auth::user())->canManagePermissions($page)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*.user_id' => 'required|integer|distinct|exists:users,id',
            'permissions.*.permission' => 'required|in:viewer,editor',
        ]);

        // The submitted list is the complete ACL of the page.
        PagePermission::where('page_id', $page->id)->delete();

        foreach ($validated['permissions'] as $entry) {
            PagePermission::create([
                'page_id' => $page->id,
                'user_id' => (int) $entry['user_id'],
                'permission' => $entry['permission'],
            ]);
        }

        return response()->json([
            'permissions' => PagePermission::where('page_id', $page->id)->with('user')->get(),
        ]);
    }

    public function attachments(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->space->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $attachments = PageAttachment::with(['file', 'uploader'])
            ->where('page_id', $page->id)
            ->latest()
            ->get();

        return response()->json($attachments);
    }

    public function addAttachment(Request $request, string $pageId)
    {
        $page = Page::findOrFail($pageId);

        if (!$page->space->canEdit(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'file_id' => 'required|exists:files,id',
        ]);

        $file = File::where('id', $validated['file_id'])
            ->where('uploaded_by', Auth::id())
            ->first();

        if (!$file) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $attachment = PageAttachment::firstOrCreate(
            ['page_id' => $page->id, 'file_id' => $file->id],
            ['uploaded_by' => Auth::id()],
        );

        return response()->json($attachment->load(['file', 'uploader']), 201);
    }

    public function removeAttachment(Request $request, string $pageId, string $attachmentId)
    {
        $page = Page::findOrFail($pageId);

        $attachment = PageAttachment::where('page_id', $page->id)
            ->where('id', $attachmentId)
            ->firstOrFail();

        if ((int) $attachment->uploaded_by !== (int) Auth::id() && !$page->space->canEdit(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $attachment->delete();

        return response()->json(['message' => 'Attachment removed']);
    }
}
