<?php

namespace App\Extensions\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MailAccount;
use App\Models\MailAccountShare;
use App\Models\MailAttachment;
use App\Models\MailFolder;
use App\Models\MailMessage;
use App\Extensions\Mail\Jobs\SendScheduledMail;
use App\Extensions\Mail\Services\MailAccountService;
use App\Extensions\Mail\Services\MailAttachmentService;
use App\Extensions\Mail\Services\MailRemoteDeleter;
use App\Extensions\Mail\Services\MailTrashService;
use App\Extensions\Mail\Services\SmtpSender;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MailMessageController extends Controller
{
    public function __construct(
        private MailAccountService $accounts,
        private SmtpSender $smtp,
        private MailRemoteDeleter $remoteDeleter,
        private MailAttachmentService $attachments,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        $query = MailMessage::accessibleBy(Auth::id())
            ->with('attachments:id,mail_message_id,filename,mime_type,size');

        // Optional mailbox filter: view a single inbox (own or shared).
        $accountId = $request->input('account_id');
        if ($accountId !== null && $accountId !== '') {
            $accountId = (int) $accountId;
            if ($this->accounts->accessibleAccount(Auth::user(), $accountId) === null) {
                abort(404, 'Ящик не найден');
            }
            $query->where('mail_account_id', $accountId);
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('subject', 'ilike', $like)
                    ->orWhere('from_email', 'ilike', $like)
                    ->orWhere('from_name', 'ilike', $like)
                    ->orWhere('body_text', 'ilike', $like)
                    ->orWhere('preview', 'ilike', $like);
            });
        }

        $folderId = $request->input('folder_id');

        if ($folderId === null || $folderId === '') {
            $systemFolder = (string) $request->input('folder', MailMessage::FOLDER_INBOX);
            if (!in_array($systemFolder, MailMessage::FOLDERS, true)) {
                $systemFolder = MailMessage::FOLDER_INBOX;
            }

            $query->where('folder', $systemFolder)->whereNull('mail_folder_id');

            if ($systemFolder === MailMessage::FOLDER_DRAFTS || $systemFolder === MailMessage::FOLDER_SENT) {
                $query->orderByDesc('updated_at');
            } else {
                $query->orderByDesc('received_at')->orderByDesc('id');
            }
        } else {
            $folder = MailFolder::where('user_id', Auth::id())->findOrFail((int) $folderId);

            $query->where('mail_folder_id', $folder->id)
                ->orderByDesc('received_at')
                ->orderByDesc('id');
        }

        if ($request->input('group') === 'thread') {
            return $this->groupedByThread($query);
        }

        return response()->json($query->paginate($perPage));
    }

    /**
     * Collapse the result set into conversation summaries (one row per thread).
     */
    private function groupedByThread($query): JsonResponse
    {
        $messages = $query->orderByDesc('received_at')->orderByDesc('id')->get();

        $threads = $messages
            ->groupBy(fn (MailMessage $message) => $message->thread_key ?: 'msg-' . $message->id)
            ->map(function (Collection $items): array {
                $latest = $items->first();

                return [
                    'thread_key' => $latest->thread_key,
                    'count' => $items->count(),
                    'unread' => $items->where('is_read', false)->count(),
                    'has_attachments' => $items->contains(fn (MailMessage $message) => (bool) $message->has_attachments),
                    'latest' => $latest,
                ];
            })
            ->values();

        return response()->json(['data' => $threads]);
    }

    public function show(MailMessage $message): JsonResponse
    {
        $this->ensureAccessible($message);

        if ($message->folder === MailMessage::FOLDER_INBOX && !$message->is_read) {
            $message->update(['is_read' => true]);
        }

        return response()->json(['data' => $message->load('attachments:id,mail_message_id,filename,mime_type,size')]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->asApiRequest($request);

        $validated = $request->validate([
            'to' => 'required|array|min:1',
            'to.*' => 'email|max:255',
            'cc' => 'sometimes|array',
            'cc.*' => 'email|max:255',
            'bcc' => 'sometimes|array',
            'bcc.*' => 'email|max:255',
            'subject' => 'nullable|string|max:998',
            'body_text' => 'nullable|string|max:100000',
            'body_html' => 'nullable|string|max:200000',
            'draft_id' => 'nullable|integer',
            'in_reply_to' => 'nullable|string|max:998',
            'attachments' => 'sometimes|array|max:' . MailAttachmentService::MAX_FILES,
            'attachments.*' => 'file|max:' . MailAttachmentService::MAX_FILE_KB,
            'keep_attachment_ids' => 'sometimes|array',
            'keep_attachment_ids.*' => 'integer',
            'scheduled_at' => 'sometimes|nullable|date',
            'account_id' => 'sometimes|nullable|integer|exists:mail_accounts,id',
        ]);

        $files = $request->file('attachments') ?? [];
        $this->attachments->guard($files);

        $account = $this->accounts->accountForSend(
            Auth::user(),
            isset($validated['account_id']) ? (int) $validated['account_id'] : null,
        );
        if ($account === null || empty($account->smtp_host)) {
            throw ValidationException::withMessages(['smtp' => 'SMTP-сервер не настроен в профиле']);
        }

        $draft = null;
        if (!empty($validated['draft_id'])) {
            $draft = MailMessage::accessibleBy(Auth::id())
                ->where('id', (int) $validated['draft_id'])
                ->where('folder', MailMessage::FOLDER_DRAFTS)
                ->first();
        }

        // "Send later": keep the message as a draft and deliver it via a delayed
        // job. Cancelling sets `scheduled_at` back to null (see update()).
        $scheduledAt = isset($validated['scheduled_at']) ? Carbon::parse($validated['scheduled_at']) : null;
        if ($scheduledAt !== null && $scheduledAt->isFuture()) {
            $message = $this->store([
                'folder' => MailMessage::FOLDER_DRAFTS,
                'to_recipients' => $validated['to'],
                'cc_recipients' => $validated['cc'] ?? [],
                'bcc_recipients' => $validated['bcc'] ?? [],
                'subject' => $validated['subject'] ?? '',
                'body_text' => $validated['body_text'] ?? null,
                'body_html' => $validated['body_html'] ?? null,
                'in_reply_to' => $validated['in_reply_to'] ?? null,
                'source' => 'local',
            ], $account);
            $message->forceFill(['scheduled_at' => $scheduledAt])->save();

            $this->attachments->attachToMessage($message, $this->attachments->storeUploads($account->id, $files));

            if ($draft !== null) {
                $this->attachments->moveToMessage(
                    (int) Auth::id(),
                    $draft->id,
                    $message,
                    $validated['keep_attachment_ids'] ?? [],
                );

                $draft->delete();
            }

            SendScheduledMail::dispatch($message->id)->delay($scheduledAt);

            return response()->json([
                'data' => $message->fresh('attachments:id,mail_message_id,filename,mime_type,size'),
            ], 202);
        }

        try {
            $this->smtp->send($account, [
                'from_email' => $account->email,
                'from_name' => $account->display_name,
                'to' => $validated['to'],
                'cc' => $validated['cc'] ?? [],
                'bcc' => $validated['bcc'] ?? [],
                'subject' => $validated['subject'] ?? '',
                'text' => $validated['body_text'] ?? null,
                'html' => $validated['body_html'] ?? null,
                'in_reply_to' => $validated['in_reply_to'] ?? null,
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['smtp' => 'Не удалось отправить письмо: ' . $e->getMessage()]);
        }

        $message = $this->store([
            'folder' => MailMessage::FOLDER_SENT,
            'to_recipients' => $validated['to'],
            'cc_recipients' => $validated['cc'] ?? [],
            'bcc_recipients' => $validated['bcc'] ?? [],
            'subject' => $validated['subject'] ?? '',
            'body_text' => $validated['body_text'] ?? null,
            'body_html' => $validated['body_html'] ?? null,
            'in_reply_to' => $validated['in_reply_to'] ?? null,
            'is_read' => true,
            'source' => 'smtp',
            'sent_at' => now(),
        ], $account);

        $this->attachments->attachToMessage($message, $this->attachments->storeUploads($account->id, $files));

        if ($draft !== null) {
            $this->attachments->moveToMessage(
                (int) Auth::id(),
                $draft->id,
                $message,
                $validated['keep_attachment_ids'] ?? [],
            );

            $draft->delete();
        }

        return response()->json(['data' => $message->fresh('attachments:id,mail_message_id,filename,mime_type,size')], 201);
    }

    public function storeDraft(Request $request): JsonResponse
    {
        $this->asApiRequest($request);

        $validated = $request->validate([
            'to' => 'sometimes|array',
            'to.*' => 'email|max:255',
            'cc' => 'sometimes|array',
            'cc.*' => 'email|max:255',
            'bcc' => 'sometimes|array',
            'bcc.*' => 'email|max:255',
            'subject' => 'nullable|string|max:998',
            'body_text' => 'nullable|string|max:100000',
            'body_html' => 'nullable|string|max:200000',
            'attachments' => 'sometimes|array|max:' . MailAttachmentService::MAX_FILES,
            'attachments.*' => 'file|max:' . MailAttachmentService::MAX_FILE_KB,
            'account_id' => 'sometimes|nullable|integer|exists:mail_accounts,id',
        ]);

        $files = $request->file('attachments') ?? [];
        $this->attachments->guard($files);

        if (!empty($validated['draft_id'] ?? null)) {
            $this->ensureAccessible(MailMessage::findOrFail($validated['draft_id']));
        }

        $account = $this->accounts->accountForSend(
            Auth::user(),
            isset($validated['account_id']) ? (int) $validated['account_id'] : null,
        );

        $message = $this->store(array_merge($validated, [
            'folder' => MailMessage::FOLDER_DRAFTS,
            'source' => 'local',
        ]), $account);

        $this->attachments->attachToMessage($message, $this->attachments->storeUploads($account?->id, $files));

        return response()->json(['data' => $message->fresh('attachments:id,mail_message_id,filename,mime_type,size')], 201);
    }

    public function update(Request $request, MailMessage $message): JsonResponse
    {
        $this->ensureAccessible($message);

        $validated = $request->validate([
            'is_read' => 'sometimes|boolean',
            'is_starred' => 'sometimes|boolean',
            'folder' => 'sometimes|in:' . implode(',', MailMessage::FOLDERS),
            'subject' => 'sometimes|nullable|string|max:998',
            'body_text' => 'sometimes|nullable|string|max:100000',
            'body_html' => 'sometimes|nullable|string|max:200000',
            'to' => 'sometimes|array',
            'to.*' => 'email|max:255',
            'cc' => 'sometimes|array',
            'cc.*' => 'email|max:255',
            'bcc' => 'sometimes|array',
            'bcc.*' => 'email|max:255',
            'folder_id' => ['sometimes', 'nullable', 'integer', Rule::exists('mail_folders', 'id')->where('user_id', Auth::id())],
            'remove_attachment_ids' => 'sometimes|array',
            'remove_attachment_ids.*' => 'integer',
            'scheduled_at' => 'sometimes|nullable|date',
            'assigned_to' => 'sometimes|nullable|integer|exists:users,id',
            'snoozed_until' => 'sometimes|nullable|date',
            'is_done' => 'sometimes|boolean',
        ]);

        $updates = [];
        foreach ([
            'is_read', 'is_starred', 'folder', 'subject', 'body_text', 'body_html',
            'scheduled_at', 'assigned_to', 'snoozed_until', 'is_done',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        // Moving to a system folder clears the custom-folder assignment.
        if (array_key_exists('folder', $validated)) {
            $updates['mail_folder_id'] = null;
        }
        // Moving to a custom folder keeps the origin folder unchanged.
        if (array_key_exists('folder_id', $validated)) {
            $updates['mail_folder_id'] = $validated['folder_id'] !== null ? (int) $validated['folder_id'] : null;
        }
        if (array_key_exists('to', $validated)) {
            $updates['to_recipients'] = $validated['to'];
        }
        if (array_key_exists('cc', $validated)) {
            $updates['cc_recipients'] = $validated['cc'];
        }
        if (array_key_exists('bcc', $validated)) {
            $updates['bcc_recipients'] = $validated['bcc'];
        }

        $message->update($updates);

        $this->attachments->removeByIds($message, $validated['remove_attachment_ids'] ?? []);

        return response()->json(['data' => $message->fresh('attachments:id,mail_message_id,filename,mime_type,size')]);
    }

    /**
     * Apply one action (read/star/move/delete) to a set of owned messages.
     */
    public function bulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|in:read,unread,star,unstar,move,delete',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'folder_id' => ['sometimes', 'nullable', 'integer', Rule::exists('mail_folders', 'id')->where('user_id', Auth::id())],
            'folder' => 'sometimes|nullable|in:' . implode(',', MailMessage::FOLDERS),
        ]);

        $messages = MailMessage::accessibleBy(Auth::id())
            ->whereIn('id', $validated['ids'])
            ->get();

        $action = $validated['action'];

        if ($action === 'move') {
            if (empty($validated['folder_id']) && empty($validated['folder'])) {
                throw ValidationException::withMessages(['folder' => 'Укажите папку или системную папку для перемещения']);
            }

            $updates = !empty($validated['folder_id'])
                ? ['mail_folder_id' => (int) $validated['folder_id']]
                : ['folder' => $validated['folder'], 'mail_folder_id' => null];

            $updated = $messages->isEmpty()
                ? 0
                : MailMessage::whereIn('id', $messages->pluck('id'))->update($updates);

            return response()->json(['updated' => $updated]);
        }

        if ($action === 'delete') {
            return $this->bulkDelete($messages);
        }

        $updates = match ($action) {
            'read' => ['is_read' => true],
            'unread' => ['is_read' => false],
            'star' => ['is_starred' => true],
            'unstar' => ['is_starred' => false],
        };

        $updated = $messages->isEmpty()
            ? 0
            : MailMessage::whereIn('id', $messages->pluck('id'))->update($updates);

        return response()->json(['updated' => $updated]);
    }

    /**
     * Return every message of a conversation, marking them as read.
     */
    public function thread(string $threadKey): JsonResponse
    {
        $messages = MailMessage::accessibleBy(Auth::id())
            ->where('thread_key', $threadKey)
            ->with('attachments:id,mail_message_id,filename,mime_type,size')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            abort(404, 'Цепочка писем не найдена');
        }

        MailMessage::whereIn('id', $messages->pluck('id'))
            ->where('is_read', false)
            ->update(['is_read' => true]);

        $messages->each(fn (MailMessage $message) => $message->setAttribute('is_read', true));

        return response()->json([
            'data' => [
                'thread_key' => $threadKey,
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * Delete the given messages: soft-delete to trash, or hard-delete (with a
     * remote delete first) when they already sit in the trash.
     */
    private function bulkDelete(Collection $messages): JsonResponse
    {
        $soft = $messages->where('folder', '!=', MailMessage::FOLDER_TRASH);
        $hard = $messages->where('folder', MailMessage::FOLDER_TRASH);

        $updated = $soft->isEmpty()
            ? 0
            : MailMessage::whereIn('id', $soft->pluck('id'))
                ->update(['folder' => MailMessage::FOLDER_TRASH, 'mail_folder_id' => null]);

        $deleted = 0;
        $failed = [];

        if ($hard->isNotEmpty()) {
            $ok = [];
            $byAccount = [];

            foreach ($hard as $message) {
                if (($message->source === 'imap' || $message->source === 'pop') && $message->account !== null) {
                    $byAccount[$message->mail_account_id][] = $message;
                } else {
                    $ok[$message->id] = true;
                }
            }

            foreach ($byAccount as $group) {
                $account = $group[0]->account;

                try {
                    $result = $this->remoteDeleter->deleteMany($account, $group);
                } catch (Throwable) {
                    $result = [];
                }

                foreach ($group as $message) {
                    $ok[$message->id] = (bool) ($result[$message->id] ?? false);
                }
            }

            foreach ($hard as $message) {
                if (empty($ok[$message->id])) {
                    $failed[] = $message->id;
                    continue;
                }

                foreach ($message->attachments as $attachment) {
                    if ($attachment->path) {
                        Storage::disk('local')->delete($attachment->path);
                    }
                }

                $message->delete();
                $deleted++;
            }
        }

        return response()->json([
            'updated' => $updated,
            'deleted' => $deleted,
            'failed' => $failed,
        ]);
    }

    public function destroy(Request $request, MailMessage $message): JsonResponse
    {
        $this->ensureAccessible($message);

        $force = $request->boolean('force');

        if ($message->folder === MailMessage::FOLDER_TRASH || $force) {
            // Delete on the mail server first (IMAP/POP3). If that fails, keep
            // the message in the local trash so it is not re-downloaded.
            $account = $message->account;

            if ($account !== null && !$this->remoteDeleter->delete($account, $message)) {
                $account->forceFill([
                    'last_sync_error' => 'Не удалось удалить письмо на почтовом сервере',
                ])->save();

                $message->update(['folder' => MailMessage::FOLDER_TRASH, 'mail_folder_id' => null]);

                return response()->json([
                    'message' => 'Не удалось удалить письмо на почтовом сервере. Письмо оставлено в корзине.',
                ]);
            }

            foreach ($message->attachments as $attachment) {
                if ($attachment->path) {
                    Storage::disk('local')->delete($attachment->path);
                }
            }
            $message->delete();

            return response()->json(['message' => 'Письмо удалено']);
        }

        $message->update(['folder' => MailMessage::FOLDER_TRASH, 'mail_folder_id' => null]);

        return response()->json(['message' => 'Письмо перемещено в корзину']);
    }

    public function emptyTrash(Request $request, MailTrashService $trash): JsonResponse
    {
        $validated = $request->validate([
            'limit' => 'sometimes|integer|min:1|max:1000',
        ]);

        $result = $trash->empty(
            (int) Auth::id(),
            (int) ($validated['limit'] ?? MailTrashService::DEFAULT_LIMIT),
        );

        $message = match (true) {
            $result['deleted'] === 0 && $result['failed'] === 0 => 'Корзина пуста',
            $result['failed'] === 0 => "Удалено писем: {$result['deleted']}",
            default => "Удалено: {$result['deleted']}. Не удалось удалить на сервере: {$result['failed']}. Эти письма оставлены в корзине.",
        };

        if ($result['remaining'] > 0) {
            $message .= " Осталось в корзине: {$result['remaining']}.";
        }

        return response()->json($result + ['message' => $message]);
    }

    public function downloadAttachment(MailMessage $message, MailAttachment $attachment): StreamedResponse|JsonResponse
    {
        $this->ensureAccessible($message);

        if ($attachment->mail_message_id !== $message->id || !$attachment->path) {
            return response()->json(['message' => 'Вложение не найдено'], 404);
        }

        if (!Storage::disk('local')->exists($attachment->path)) {
            return response()->json(['message' => 'Файл вложения отсутствует'], 404);
        }

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(array $data, ?MailAccount $account = null): MailMessage
    {
        $account ??= $this->accounts->forUser(Auth::user());
        $text = $data['body_text'] ?? null;
        $html = $data['body_html'] ?? null;
        $previewSource = $text ?? ($html !== null ? trim(strip_tags($html)) : '');

        return MailMessage::create([
            'mail_account_id' => $account?->id,
            'user_id' => Auth::id(),
            'folder' => $data['folder'],
            'uid' => null,
            'message_id' => $data['message_id'] ?? null,
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'from_email' => $account?->email,
            'from_name' => $account?->display_name,
            'to_recipients' => $this->normalizeRecipients($data['to_recipients'] ?? ($data['to'] ?? [])),
            'cc_recipients' => $this->normalizeRecipients($data['cc_recipients'] ?? ($data['cc'] ?? [])),
            'bcc_recipients' => $this->normalizeRecipients($data['bcc_recipients'] ?? ($data['bcc'] ?? [])),
            'subject' => $data['subject'] ?? '',
            'preview' => Str::limit($previewSource, 200),
            'body_text' => $text,
            'body_html' => $html,
            'is_read' => $data['is_read'] ?? false,
            'source' => $data['source'] ?? 'local',
            'sent_at' => $data['sent_at'] ?? null,
        ]);
    }

    /**
     * Normalise recipients into a list of {name, email} objects.
     *
     * @param  array<int, mixed>  $recipients
     * @return array<int, array{name: ?string, email: ?string}>
     */
    private function normalizeRecipients(array $recipients): array
    {
        $result = [];
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $result[] = ['name' => null, 'email' => $recipient];
            } elseif (is_array($recipient)) {
                $result[] = [
                    'name' => $recipient['name'] ?? null,
                    'email' => $recipient['email'] ?? null,
                ];
            }
        }

        return $result;
    }

    private function ensureAccessible(MailMessage $message): void
    {
        $userId = (int) Auth::id();

        if ((int) $message->user_id === $userId) {
            return;
        }

        if ($message->mail_account_id !== null
            && MailAccountShare::where('mail_account_id', $message->mail_account_id)
                ->where('user_id', $userId)
                ->exists()) {
            return;
        }

        abort(404, 'Письмо не найдено');
    }

    /**
     * Multipart endpoints are called without an Accept header by the mail
     * clients, so JSON (and 422 validation) responses are forced here.
     */
    private function asApiRequest(Request $request): void
    {
        if (!$request->expectsJson()) {
            $request->headers->set('Accept', 'application/json');
        }
    }
}
