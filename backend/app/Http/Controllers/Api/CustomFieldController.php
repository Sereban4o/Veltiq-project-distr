<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Services\CustomFields\CustomFieldService;
use App\Support\Tasksbase\AuditWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Custom field definitions for the client UI, and writing issue values.
 */
class CustomFieldController extends Controller
{
    public function __construct(private readonly CustomFieldService $fields)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $entity = $this->fields->entity((string) $request->query('entity', 'issue'));

        $definitions = $this->fields->definitions($entity)
            ->map(fn ($field) => $this->fields->present($field))
            ->values();

        return response()->json(['data' => $definitions]);
    }

    /**
     * Replace the custom field values of an issue.
     */
    public function updateIssue(Request $request, string $issueId): JsonResponse
    {
        $issue = Issue::findOrFail($issueId);

        $user = Auth::user();
        if (! $user->isAdmin() && ! $issue->project->hasPermission($user, 'edit_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'values' => 'present|array',
        ]);

        $definitions = $this->fields->definitions('issue');
        $result = $this->fields->validate($validated['values'], $definitions);

        if ($result['errors'] !== []) {
            return response()->json(['message' => 'Некорректные значения', 'errors' => $result['errors']], 422);
        }

        $previous = $issue->custom_fields ?? [];

        // Merge so values of definitions that are currently inactive survive.
        $issue->update(['custom_fields' => array_merge($previous, $result['values'])]);

        AuditWriter::record($issue, 'custom_fields_updated', [
            'custom_fields' => ['old' => $previous, 'new' => $issue->custom_fields],
        ]);

        return response()->json(['data' => ['custom_fields' => $issue->fresh()->custom_fields ?? []]]);
    }
}
