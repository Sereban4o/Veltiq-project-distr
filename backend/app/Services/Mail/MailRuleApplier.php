<?php

namespace App\Services\Mail;

use App\Models\MailMessage;

/**
 * Applies the user's filter rules to messages (sets/clears `mail_folder_id`).
 */
class MailRuleApplier
{
    public function __construct(
        private MailRuleEngine $engine,
    ) {
    }

    public function applyToMessage(MailMessage $message, ?int $userId = null): void
    {
        $userId ??= (int) $message->user_id;

        $folderId = $this->engine->resolveFolderId($userId, $message);

        if ((int) ($message->mail_folder_id ?? 0) !== (int) ($folderId ?? 0)) {
            $message->forceFill(['mail_folder_id' => $folderId])->save();
        }
    }

    /**
     * Re-evaluate all inbox messages of a user. Returns the number of changed rows.
     */
    public function applyToUser(int $userId): int
    {
        $moved = 0;

        MailMessage::where('user_id', $userId)
            ->where('folder', MailMessage::FOLDER_INBOX)
            ->orderBy('id')
            ->chunkById(200, function ($messages) use ($userId, &$moved) {
                foreach ($messages as $message) {
                    $before = (int) ($message->mail_folder_id ?? 0);
                    $this->applyToMessage($message, $userId);
                    if ((int) ($message->mail_folder_id ?? 0) !== $before) {
                        $moved++;
                    }
                }
            });

        return $moved;
    }
}
