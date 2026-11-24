<?php

namespace App\Extensions\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MailFilterRule;
use App\Models\MailFolder;
use App\Models\MailMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MailFolderController extends Controller
{
    public function index(): JsonResponse
    {
        $folders = MailFolder::where('user_id', Auth::id())
            ->withCount('messages')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $folders]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('mail_folders', 'name')->where('user_id', Auth::id()),
            ],
        ]);

        $position = (int) MailFolder::where('user_id', Auth::id())->max('position') + 1;

        $folder = MailFolder::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'position' => $position,
        ]);

        return response()->json(['data' => $folder], 201);
    }

    /**
     * Create (or reuse) a folder named after a sender, file that sender's
     * messages into it and keep future mail coming there via a filter rule.
     */
    public function fromSender(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sender_email' => ['required', 'email', 'max:255'],
        ]);

        $email = mb_strtolower(trim($validated['sender_email']));
        $userId = (int) Auth::id();

        $sender = MailMessage::where('user_id', $userId)
            ->whereRaw('lower(from_email) = ?', [$email])
            ->orderByDesc('id')
            ->first();

        $name = trim((string) ($sender->from_name ?? ''));
        if ($name === '') {
            $name = Str::before($email, '@') ?: $email;
        }

        $folder = MailFolder::where('user_id', $userId)->where('name', $name)->first();

        if ($folder === null) {
            $folder = MailFolder::create([
                'user_id' => $userId,
                'name' => $name,
                'position' => (int) MailFolder::where('user_id', $userId)->max('position') + 1,
            ]);
        }

        $moved = MailMessage::where('user_id', $userId)
            ->where('folder', MailMessage::FOLDER_INBOX)
            ->whereNull('mail_folder_id')
            ->whereRaw('lower(from_email) = ?', [$email])
            ->update(['mail_folder_id' => $folder->id]);

        $rule = MailFilterRule::where('user_id', $userId)
            ->where('action_target_folder_id', $folder->id)
            ->first();

        $ruleCreated = false;

        if ($rule === null) {
            MailFilterRule::create([
                'user_id' => $userId,
                'name' => 'От: ' . $email,
                'is_active' => true,
                'position' => (int) MailFilterRule::where('user_id', $userId)->max('position') + 1,
                'action_target_folder_id' => $folder->id,
                'conditions' => [
                    'match' => 'all',
                    'groups' => [
                        ['match' => 'all', 'conditions' => [
                            ['field' => 'from', 'op' => 'contains', 'value' => $email],
                        ]],
                    ],
                ],
            ]);

            $ruleCreated = true;
        }

        return response()->json([
            'folder' => $folder->only(['id', 'name']),
            'moved' => $moved,
            'rule_created' => $ruleCreated,
        ]);
    }

    public function update(Request $request, MailFolder $folder): JsonResponse
    {
        $this->ensureOwned($folder);

        $validated = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:100',
                Rule::unique('mail_folders', 'name')->where('user_id', Auth::id())->ignore($folder->id),
            ],
            'position' => ['sometimes', 'integer', 'min:0'],
        ]);

        $folder->update($validated);

        return response()->json(['data' => $folder->fresh()]);
    }

    public function destroy(MailFolder $folder): JsonResponse
    {
        $this->ensureOwned($folder);

        $folder->delete();

        return response()->json(['message' => 'Папка удалена']);
    }

    private function ensureOwned(MailFolder $folder): void
    {
        if ((int) $folder->user_id !== (int) Auth::id()) {
            abort(404, 'Папка не найдена');
        }
    }
}
