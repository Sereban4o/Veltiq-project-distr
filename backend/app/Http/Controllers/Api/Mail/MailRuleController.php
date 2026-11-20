<?php

namespace App\Http\Controllers\Api\Mail;

use App\Http\Controllers\Controller;
use App\Models\MailFilterRule;
use App\Services\Mail\MailRuleApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MailRuleController extends Controller
{
    public function index(): JsonResponse
    {
        $rules = MailFilterRule::where('user_id', Auth::id())
            ->with('targetFolder:id,name')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rules]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateRule($request);

        if (!array_key_exists('position', $data)) {
            $data['position'] = (int) MailFilterRule::where('user_id', Auth::id())->max('position') + 1;
        }

        $rule = MailFilterRule::create($data + ['user_id' => Auth::id()]);

        return response()->json(['data' => $rule->load('targetFolder:id,name')], 201);
    }

    public function update(Request $request, MailFilterRule $rule): JsonResponse
    {
        $this->ensureOwned($rule);

        $rule->update($this->validateRule($request, true));

        return response()->json(['data' => $rule->fresh('targetFolder:id,name')]);
    }

    public function destroy(MailFilterRule $rule): JsonResponse
    {
        $this->ensureOwned($rule);

        $rule->delete();

        return response()->json(['message' => 'Правило удалено']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($validated['ids'] as $index => $id) {
            MailFilterRule::where('user_id', Auth::id())
                ->where('id', $id)
                ->update(['position' => $index]);
        }

        return response()->json(['message' => 'Порядок сохранён']);
    }

    public function apply(MailRuleApplier $applier): JsonResponse
    {
        $moved = $applier->applyToUser((int) Auth::id());

        return response()->json(['moved' => $moved]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'is_active' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
            'action_target_folder_id' => [
                $required, 'integer',
                Rule::exists('mail_folders', 'id')->where('user_id', Auth::id()),
            ],
            'conditions' => [$required, 'array'],
        ]);

        if (array_key_exists('conditions', $data)) {
            $data = array_merge($data, $request->validate([
                'conditions.match' => 'required|in:all,any',
                'conditions.groups' => 'required|array|min:1',
                'conditions.groups.*.match' => 'sometimes|in:all,any',
                'conditions.groups.*.conditions' => 'required|array',
                'conditions.groups.*.conditions.*.field' => 'required|in:from,to,subject,body,has_attachment',
                'conditions.groups.*.conditions.*.op' => 'required|in:contains,equals,regex,is_true',
                'conditions.groups.*.conditions.*.value' => 'nullable|string|max:1000',
            ]));

            foreach ($data['conditions']['groups'] as $group) {
                foreach ((array) ($group['conditions'] ?? []) as $condition) {
                    if (($condition['op'] ?? null) !== 'regex') {
                        continue;
                    }

                    $pattern = (string) ($condition['value'] ?? '');

                    if ($pattern === '' || !$this->regexOk($pattern)) {
                        throw ValidationException::withMessages([
                            'conditions' => 'Некорректное регулярное выражение: ' . $pattern,
                        ]);
                    }
                }
            }
        }

        return $data;
    }

    private function ensureOwned(MailFilterRule $rule): void
    {
        if ((int) $rule->user_id !== (int) Auth::id()) {
            abort(404, 'Правило не найдено');
        }
    }

    private function regexOk(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
