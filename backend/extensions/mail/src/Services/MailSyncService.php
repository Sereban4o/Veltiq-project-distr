<?php

namespace App\Extensions\Mail\Services;

use App\Events\MailMessageCreated;
use App\Models\MailAccount;
use App\Models\MailAttachment;
use App\Models\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pulls incoming messages from the user's IMAP/POP server and stores them in
 * the local mailbox (inbox folder), de-duplicating by remote identifier.
 */
class MailSyncService
{
    private const MAX_MESSAGES = 50;

    public function __construct(
        private MimeParser $parser,
        private MailRuleApplier $rules,
        private MailThreadResolver $threads,
    ) {
    }

    /**
     * @return int number of newly stored messages
     */
    public function sync(MailAccount $account): int
    {
        $items = $account->incoming_protocol === MailAccount::PROTOCOL_POP
            ? $this->fetchFromPop($account)
            : $this->fetchFromImap($account);

        $stored = 0;
        foreach ($items as $item) {
            if ($this->storeRaw($account, $item['raw'], $item['remote_uid'] ?? null)) {
                $stored++;
            }
        }

        $account->forceFill([
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ])->save();

        return $stored;
    }

    /**
     * @return array<int, array{remote_uid: ?string, raw: string}>
     */
    private function fetchFromImap(MailAccount $account): array
    {
        $client = new ImapClient(
            (string) $account->imap_host,
            (int) ($account->imap_port ?: 993),
            (string) ($account->imap_encryption ?: 'ssl'),
            (string) ($account->imap_username ?: $account->email),
            (string) $account->imap_password,
        );

        try {
            $client->connect();
            $client->selectInbox();
            $uids = $client->searchAll();
            $uids = array_slice($uids, -self::MAX_MESSAGES);

            $items = [];
            foreach ($uids as $uid) {
                // Skip messages already stored for this account (in any folder).
                if (MailMessage::where('mail_account_id', $account->id)
                    ->where('remote_uid', (string) $uid)
                    ->exists()) {
                    continue;
                }
                $message = $client->fetchRaw((string) $uid);
                if ($message !== null) {
                    $items[] = ['remote_uid' => (string) $uid, 'raw' => $message];
                }
            }

            return $items;
        } finally {
            $client->close();
        }
    }

    /**
     * @return array<int, array{remote_uid: ?string, raw: string}>
     */
    private function fetchFromPop(MailAccount $account): array
    {
        $client = new Pop3Client(
            (string) $account->pop_host,
            (int) ($account->pop_port ?: 995),
            (string) ($account->pop_encryption ?: 'ssl'),
            (string) ($account->pop_username ?: $account->email),
            (string) $account->pop_password,
        );

        try {
            $client->connect();
            $list = $client->list();
            $list = array_slice($list, -self::MAX_MESSAGES);

            $items = [];
            foreach ($list as $item) {
                // POP3 message numbers are session-scoped, so they are not
                // stored; de-duplication relies on the RFC Message-ID.
                $items[] = ['remote_uid' => null, 'raw' => $client->retrieve($item['index'])];
            }

            return $items;
        } finally {
            $client->close();
        }
    }

    private function storeRaw(MailAccount $account, string $raw, ?string $remoteUid = null): bool
    {
        $parsed = $this->parser->parse($raw);
        $uid = ($parsed['message_id'] ?? null) ?: 'sha1:' . sha1($raw);

        return $this->storeParsed($account, $parsed, $remoteUid, $uid) !== null;
    }

    /**
     * Public testable seam: create one message from already-parsed data.
     * Returns null when the message is a duplicate.
     *
     * @param  array<string, mixed>  $parsed
     */
    public function storeParsed(MailAccount $account, array $parsed, ?string $remoteUid = null, ?string $uidOverride = null): ?MailMessage
    {
        $messageId = $parsed['message_id'] ?? null;
        $uid = $uidOverride ?? ($messageId ?: 'sha1:' . sha1((string) json_encode($parsed)));

        // De-duplicate across all folders so a message moved to trash (or a
        // locally deleted one that is still on the server) is not re-imported.
        if (MailMessage::where('mail_account_id', $account->id)
            ->where('uid', $uid)
            ->exists()) {
            return null;
        }

        $text = $parsed['text'] ?? null;
        $html = $parsed['html'] ?? null;
        $previewSource = $text ?? ($html !== null ? trim(strip_tags($html)) : '');

        $message = MailMessage::create([
            'mail_account_id' => $account->id,
            'user_id' => $account->user_id,
            'folder' => MailMessage::FOLDER_INBOX,
            'uid' => $uid,
            'remote_uid' => $remoteUid,
            'message_id' => $messageId,
            'in_reply_to' => $parsed['in_reply_to'] ?? null,
            'thread_key' => $this->threads->keyForIncoming(
                (int) $account->user_id,
                $parsed['in_reply_to'] ?? null,
                $parsed['references'] ?? null,
                $messageId,
                $parsed['subject'] ?? null,
            ),
            'from_email' => $parsed['from']['email'] ?? null,
            'from_name' => $parsed['from']['name'] ?? null,
            'to_recipients' => $parsed['to'] ?? [],
            'cc_recipients' => $parsed['cc'] ?? [],
            'subject' => $parsed['subject'] ?? null,
            'preview' => Str::limit($previewSource, 200),
            'body_text' => $text,
            'body_html' => $html,
            'has_attachments' => !empty($parsed['attachments']),
            'source' => $account->incoming_protocol,
            'received_at' => $this->parseDate($parsed['date'] ?? null) ?? now(),
        ]);

        foreach ((array) ($parsed['attachments'] ?? []) as $attachment) {
            $this->storeAttachment($account, $message, $attachment);
        }

        $this->rules->applyToMessage($message);

        $this->announce($message);

        return $message;
    }

    /**
     * Nudge the user's open mailbox over Reverb, throttled so a batch of synced
     * messages produces a single broadcast per user within the window.
     */
    private function announce(MailMessage $message): void
    {
        $window = (int) config('mail.realtime_throttle_seconds', 5);

        if ($window > 0 && ! Cache::add('mail:realtime:' . $message->user_id, true, $window)) {
            return;
        }

        MailMessageCreated::dispatch(
            (int) $message->user_id,
            (int) $message->mail_account_id,
            (int) $message->id,
            (string) $message->folder,
        );
    }

    /**
     * @param  array{filename: string, mime: ?string, content: string, size: int}  $attachment
     */
    private function storeAttachment(MailAccount $account, MailMessage $message, array $attachment): void
    {
        $path = 'mail/' . $account->id . '/' . Str::uuid() . '-' . $this->safeName($attachment['filename']);

        try {
            Storage::disk('local')->put($path, $attachment['content']);
        } catch (\Throwable) {
            return;
        }

        MailAttachment::create([
            'mail_message_id' => $message->id,
            'filename' => $attachment['filename'],
            'mime_type' => $attachment['mime'],
            'size' => $attachment['size'],
            'path' => $path,
        ]);
    }

    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?? $name;

        return $name !== '' ? Str::limit($name, 120, '') : 'attachment';
    }

    private function parseDate(?string $date): ?\DateTimeInterface
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($date);
        } catch (\Throwable) {
            return null;
        }
    }
}
