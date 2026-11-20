<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use App\Models\MailMessage;
use Throwable;

/**
 * Deletes a message on the user's mail server (IMAP/POP3).
 *
 * IMAP: uses the stored UID (remote_uid) to flag the message as \Deleted and
 * expunge it. POP3: message numbers are session-scoped, so the current index
 * is located by matching the RFC Message-ID stored in `uid`.
 */
class MailRemoteDeleter
{
    public function __construct(
        private MimeParser $parser,
    ) {
    }

    /**
     * @return bool true when the message is gone from the server (or nothing
     *              needed to be done), false when the remote delete failed.
     */
    public function delete(MailAccount $account, MailMessage $message): bool
    {
        // Drafts/sent messages and messages that were never synced exist only
        // in our database — nothing to delete remotely.
        if ($message->source !== 'imap' && $message->source !== 'pop') {
            return true;
        }

        try {
            if ($account->incoming_protocol === MailAccount::PROTOCOL_POP) {
                return $this->deleteFromPop($account, $message);
            }

            return $this->deleteFromImap($account, $message);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete several messages of the same account in one session.
     *
     * @param  array<int, MailMessage>  $messages
     * @return array<int, bool> message id => success
     */
    public function deleteMany(MailAccount $account, array $messages): array
    {
        $results = [];
        $remote = [];

        foreach ($messages as $message) {
            if ($message->source !== 'imap' && $message->source !== 'pop') {
                $results[$message->id] = true;
                continue;
            }

            $remote[] = $message;
        }

        if ($remote === []) {
            return $results;
        }

        try {
            $inner = $account->incoming_protocol === MailAccount::PROTOCOL_POP
                ? $this->deleteManyFromPop($account, $remote)
                : $this->deleteManyFromImap($account, $remote);

            return $results + $inner;
        } catch (Throwable) {
            foreach ($remote as $message) {
                $results[$message->id] = false;
            }

            return $results;
        }
    }

    /**
     * @param  array<int, MailMessage>  $messages
     * @return array<int, bool>
     */
    private function deleteManyFromImap(MailAccount $account, array $messages): array
    {
        $results = [];
        $uids = [];

        foreach ($messages as $message) {
            $uid = (string) ($message->remote_uid ?? '');
            if ($uid === '') {
                $results[$message->id] = false;
                continue;
            }

            $uids[$uid][] = $message->id;
        }

        if ($uids === []) {
            return $results;
        }

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
            $client->deleteMessages(array_keys($uids));
        } finally {
            $client->close();
        }

        foreach ($uids as $ids) {
            foreach ($ids as $id) {
                $results[$id] = true;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, MailMessage>  $messages
     * @return array<int, bool>
     */
    private function deleteManyFromPop(MailAccount $account, array $messages): array
    {
        $results = [];
        $wanted = [];

        foreach ($messages as $message) {
            $uid = (string) ($message->uid ?? '');
            if ($uid === '') {
                $results[$message->id] = false;
                continue;
            }

            $wanted[$uid][] = $message->id;
        }

        if ($wanted === []) {
            return $results;
        }

        $client = new Pop3Client(
            (string) $account->pop_host,
            (int) ($account->pop_port ?: 995),
            (string) ($account->pop_encryption ?: 'ssl'),
            (string) ($account->pop_username ?: $account->email),
            (string) $account->pop_password,
        );

        $indexByIdentity = [];

        try {
            $client->connect();

            foreach ($client->list() as $item) {
                $raw = $client->retrieve((int) $item['index']);
                $parsed = $this->parser->parse($raw);
                $identity = $parsed['message_id'] ?: 'sha1:' . sha1($raw);

                if (isset($wanted[$identity]) && !isset($indexByIdentity[$identity])) {
                    $indexByIdentity[$identity] = (int) $item['index'];
                }

                if (count($indexByIdentity) === count($wanted)) {
                    break;
                }
            }

            foreach ($wanted as $identity => $ids) {
                $index = $indexByIdentity[$identity] ?? null;

                if ($index === null) {
                    foreach ($ids as $id) {
                        $results[$id] = true; // already removed on the server
                    }
                    continue;
                }

                try {
                    $client->deleteMessage($index);
                    foreach ($ids as $id) {
                        $results[$id] = true;
                    }
                } catch (Throwable) {
                    foreach ($ids as $id) {
                        $results[$id] = false;
                    }
                }
            }
        } finally {
            $client->close();
        }

        return $results;
    }

    private function deleteFromImap(MailAccount $account, MailMessage $message): bool
    {
        $remoteUid = (string) ($message->remote_uid ?? '');
        if ($remoteUid === '') {
            // Older rows may not have the IMAP UID; nothing safe to delete.
            return false;
        }

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
            $client->deleteMessage($remoteUid);

            return true;
        } finally {
            $client->close();
        }
    }

    private function deleteFromPop(MailAccount $account, MailMessage $message): bool
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

            $index = $this->findPopIndex($client, (string) $message->uid);
            if ($index === null) {
                // Already removed on the server.
                return true;
            }

            $client->deleteMessage($index);

            // QUIT (inside close()) commits the deletion.
            return true;
        } finally {
            $client->close();
        }
    }

    private function findPopIndex(Pop3Client $client, string $uid): ?int
    {
        foreach ($client->list() as $item) {
            $raw = $client->retrieve((int) $item['index']);
            $parsed = $this->parser->parse($raw);
            $identity = $parsed['message_id'] ?: 'sha1:' . sha1($raw);

            if ($identity === $uid) {
                return (int) $item['index'];
            }
        }

        return null;
    }
}
