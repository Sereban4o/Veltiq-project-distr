<?php

namespace App\Extensions\Mail\Services;

use App\Models\MailAccount;
use App\Models\MailMessage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Empties the local trash by deleting messages on the mail server first.
 * Messages that the server refuses to delete stay in the trash.
 */
class MailTrashService
{
    public const DEFAULT_LIMIT = 500;

    public function __construct(private MailRemoteDeleter $remoteDeleter)
    {
    }

    /**
     * @return array{deleted:int,failed:int,remaining:int}
     */
    public function empty(int $userId, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = min(max($limit, 1), 1000);

        $base = MailMessage::query()
            ->where('user_id', $userId)
            ->where('folder', MailMessage::FOLDER_TRASH)
            ->whereNull('mail_folder_id');

        $total = (clone $base)->count();

        $messages = $base
            ->with(['account', 'attachments:id,mail_message_id,path'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $ok = [];
        $byAccount = [];

        foreach ($messages as $message) {
            if (($message->source === 'imap' || $message->source === 'pop') && $message->account !== null) {
                $byAccount[$message->mail_account_id][] = $message;
            } else {
                // Local/draft/sent messages exist only in the database.
                $ok[$message->id] = true;
            }
        }

        foreach ($byAccount as $accountId => $group) {
            $account = $group[0]->account;

            try {
                $result = $this->remoteDeleter->deleteMany($account, $group);
            } catch (Throwable) {
                $result = [];
            }

            $failed = false;

            foreach ($group as $message) {
                $success = (bool) ($result[$message->id] ?? false);
                $ok[$message->id] = $success;

                if (!$success) {
                    $failed = true;
                }
            }

            if ($failed) {
                MailAccount::whereKey($accountId)->update([
                    'last_sync_error' => 'Не удалось удалить часть писем на почтовом сервере',
                ]);
            }
        }

        $successIds = [];
        $deleted = 0;

        foreach ($messages as $message) {
            if (empty($ok[$message->id])) {
                continue;
            }

            foreach ($message->attachments as $attachment) {
                if ($attachment->path) {
                    Storage::disk('local')->delete($attachment->path);
                }
            }

            $successIds[] = $message->id;
            $deleted++;
        }

        if ($successIds !== []) {
            MailMessage::whereIn('id', $successIds)->delete();
        }

        return [
            'deleted' => $deleted,
            'failed' => $messages->count() - $deleted,
            'remaining' => max(0, $total - $messages->count()),
        ];
    }
}
