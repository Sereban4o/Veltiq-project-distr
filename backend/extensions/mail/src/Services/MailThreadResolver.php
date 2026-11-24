<?php

namespace App\Extensions\Mail\Services;

use App\Models\MailMessage;

/**
 * Resolves the conversation ("thread") a message belongs to.
 *
 * A thread is identified by a 40-char sha1 key stored on
 * `mail_messages.thread_key`. The same static helpers are reused by the sync
 * service, the send flow and the backfill migration so the key never diverges.
 *
 * Precedence:
 *  1. an existing thread (parent found by In-Reply-To / References);
 *  2. sha1 of the RFC Message-ID;
 *  3. sha1 of the normalised subject (last resort — may merge unrelated mail).
 */
class MailThreadResolver
{
    public static function normalizeId(?string $id): string
    {
        return mb_strtolower(trim((string) $id, " \t<>"));
    }

    /**
     * Strip any number of Re:/Fwd:/Ответ:/Перес: prefixes, collapse whitespace.
     */
    public static function normalizeSubject(?string $subject): string
    {
        $value = (string) $subject;

        $changed = true;
        while ($changed) {
            $changed = false;
            $stripped = preg_replace(
                '/^\s*(re|fw|fwd|ответ|перес|пересл)\s*(\[\d+\])?\s*:\s*/iu',
                '',
                $value,
                -1,
                $count,
            );

            if ($count > 0 && $stripped !== null) {
                $value = $stripped;
                $changed = true;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    public static function hash(string $value): string
    {
        return sha1($value);
    }

    public static function computeKey(?string $parentKey, ?string $messageId, ?string $subject): string
    {
        // A known parent key is already the answer; no need to ask the service.
        if ($parentKey !== null && $parentKey !== '') {
            return $parentKey;
        }

        $normalizedSubject = self::normalizeSubject($subject);

        $id = self::normalizeId($messageId);
        if ($id !== '') {
            return sha1('m:' . $id);
        }

        return sha1('s:' . $normalizedSubject);
    }

    /**
     * @param  string|array<int, string>|null  $references
     * @return array<int, string>
     */
    public static function referenceIds(string|array|null $references): array
    {
        if ($references === null) {
            return [];
        }

        $items = is_array($references)
            ? $references
            : (preg_split('/\s+/u', trim($references)) ?: []);

        $out = [];
        foreach ($items as $item) {
            $id = self::normalizeId((string) $item);
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    public function keyForIncoming(
        int $userId,
        ?string $inReplyTo,
        string|array|null $references,
        ?string $messageId,
        ?string $subject,
    ): string {
        $parentId = self::normalizeId($inReplyTo);

        if ($parentId === '') {
            $ids = self::referenceIds($references);
            $parentId = $ids[0] ?? '';
        }

        if ($parentId !== '') {
            $parent = $this->findByMessageId($userId, $parentId);
            if ($parent !== null && !empty($parent->thread_key)) {
                return (string) $parent->thread_key;
            }

            return self::computeKey(null, $parentId, null);
        }

        return self::computeKey(null, $messageId, $subject);
    }

    public function keyForOutgoing(int $userId, ?string $inReplyTo, ?string $messageId, ?string $subject): string
    {
        return $this->keyForIncoming($userId, $inReplyTo, null, $messageId, $subject);
    }

    /**
     * @return array<int, string> Reference chain for the outgoing message.
     */
    public function referencesFor(int $userId, ?string $inReplyTo): array
    {
        $parentId = self::normalizeId($inReplyTo);
        if ($parentId === '') {
            return [];
        }

        $parent = $this->findByMessageId($userId, $parentId);
        if ($parent === null) {
            return [$parentId];
        }

        $ids = self::referenceIds($parent->references);

        $parentMessageId = self::normalizeId($parent->message_id);
        $ids[] = $parentMessageId !== '' ? $parentMessageId : $parentId;

        return array_values(array_unique(array_filter($ids)));
    }

    public function generateMessageId(string $fromEmail): string
    {
        $domain = 'localhost';
        $at = strrpos($fromEmail, '@');

        if ($at !== false && $at < strlen($fromEmail) - 1) {
            $domain = substr($fromEmail, $at + 1);
        }

        return sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
    }

    private function findByMessageId(int $userId, string $messageId): ?MailMessage
    {
        return MailMessage::where('user_id', $userId)
            ->where(function ($query) use ($messageId): void {
                $query->where('message_id', $messageId)
                    ->orWhereRaw('lower(message_id) = ?', [$messageId]);
            })
            ->orderBy('id')
            ->first();
    }
}
