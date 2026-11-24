<?php

namespace App\Extensions\Tasksbase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueChecklist;
use App\Models\IssueChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IssueChecklistController extends Controller
{
    public function index(Request $request, string $issueId)
    {
        $issue = $this->resolveIssue($issueId);

        if ($denied = $this->denyUnlessVisible($issue)) {
            return $denied;
        }

        $checklists = IssueChecklist::where('issue_id', $issue->id)
            ->with(['items.assignee'])
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(
            $checklists->map(fn (IssueChecklist $checklist) => $this->present($checklist))->values(),
        );
    }

    public function store(Request $request, string $issueId)
    {
        $issue = $this->resolveIssue($issueId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $checklist = IssueChecklist::create([
            'issue_id' => $issue->id,
            'title' => $validated['title'],
            'position' => (int) IssueChecklist::where('issue_id', $issue->id)->max('position') + 1,
            'created_by' => Auth::id(),
        ]);

        return response()->json($this->present($checklist->load('items.assignee')), 201);
    }

    public function update(Request $request, string $issueId, string $checklistId)
    {
        $issue = $this->resolveIssue($issueId);
        $checklist = $this->resolveChecklist($issue, $checklistId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'position' => 'sometimes|integer|min:0',
        ]);

        $checklist->update($validated);

        return response()->json($this->present($checklist->fresh('items.assignee')));
    }

    public function destroy(Request $request, string $issueId, string $checklistId)
    {
        $issue = $this->resolveIssue($issueId);
        $checklist = $this->resolveChecklist($issue, $checklistId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $checklist->delete();

        return response()->json(['message' => 'Чек-лист удалён']);
    }

    public function storeItem(Request $request, string $issueId, string $checklistId)
    {
        $issue = $this->resolveIssue($issueId);
        $checklist = $this->resolveChecklist($issue, $checklistId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $validated = $request->validate([
            'body' => 'required|string|max:1000',
            'assignee_id' => 'nullable|exists:users,id',
            'done' => 'sometimes|boolean',
        ]);

        $item = IssueChecklistItem::create([
            'checklist_id' => $checklist->id,
            'body' => $validated['body'],
            'assignee_id' => $validated['assignee_id'] ?? null,
            'done' => $validated['done'] ?? false,
            'position' => (int) IssueChecklistItem::where('checklist_id', $checklist->id)->max('position') + 1,
        ]);

        return response()->json($item->load('assignee'), 201);
    }

    public function updateItem(Request $request, string $issueId, string $checklistId, string $itemId)
    {
        $issue = $this->resolveIssue($issueId);
        $checklist = $this->resolveChecklist($issue, $checklistId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $item = IssueChecklistItem::where('checklist_id', $checklist->id)->findOrFail($itemId);

        $validated = $request->validate([
            'body' => 'sometimes|string|max:1000',
            'done' => 'sometimes|boolean',
            'assignee_id' => 'sometimes|nullable|exists:users,id',
            'position' => 'sometimes|integer|min:0',
        ]);

        $item->update($validated);

        return response()->json($item->fresh('assignee'));
    }

    public function destroyItem(Request $request, string $issueId, string $checklistId, string $itemId)
    {
        $issue = $this->resolveIssue($issueId);
        $checklist = $this->resolveChecklist($issue, $checklistId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        IssueChecklistItem::where('checklist_id', $checklist->id)->findOrFail($itemId)->delete();

        return response()->json(['message' => 'Пункт удалён']);
    }

    private function resolveIssue(string $issueId): Issue
    {
        return Issue::findOrFail($issueId);
    }

    private function resolveChecklist(Issue $issue, string $checklistId): IssueChecklist
    {
        return IssueChecklist::where('issue_id', $issue->id)->findOrFail($checklistId);
    }

    private function denyUnlessVisible(Issue $issue)
    {
        if (Auth::user()->isAdmin() || $issue->project?->canView(Auth::user())) {
            return null;
        }

        return response()->json(['message' => 'Permission denied'], 403);
    }

    private function denyUnlessEditable(Issue $issue)
    {
        $user = Auth::user();

        if ($user->isAdmin() || $issue->project?->hasPermission($user, 'edit_issues')) {
            return null;
        }

        return response()->json(['message' => 'Permission denied'], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IssueChecklist $checklist): array
    {
        $items = $checklist->relationLoaded('items')
            ? $checklist->items
            : $checklist->items()->with('assignee')->get();

        return [
            'id' => $checklist->id,
            'issue_id' => $checklist->issue_id,
            'title' => $checklist->title,
            'position' => $checklist->position,
            'items' => $items->map(fn (IssueChecklistItem $item) => [
                'id' => $item->id,
                'checklist_id' => $item->checklist_id,
                'body' => $item->body,
                'done' => $item->done,
                'assignee_id' => $item->assignee_id,
                'position' => $item->position,
                'assignee' => $item->assignee ? [
                    'id' => $item->assignee->id,
                    'full_name' => $item->assignee->full_name,
                    'initials' => $item->assignee->initials,
                ] : null,
            ])->values(),
            'total' => $items->count(),
            'done_count' => $items->where('done', true)->count(),
        ];
    }
}
