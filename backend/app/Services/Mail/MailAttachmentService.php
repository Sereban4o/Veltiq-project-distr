<?php

namespace App\Services\Mail;

use App\Models\MailAttachment;
use App\Models\MailMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores and removes outgoing attachments using the same private-disk layout
 * as incoming mail (`mail/{accountId}/{uuid}-{name}`), and creates/removes the
 * `mail_attachments` rows.
 */
class MailAttachmentService
{
    /** Extensions that must never be sent as attachments. */
    public const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'jar', 'ps1', 'vbs', 'js', 'jse',
        'wsf', 'sh', 'php', 'phtml', 'cgi', 'pl', 'py', 'html', 'htm', 'svg', 'hta',
        'lnk', 'reg',
    ];

    public const MAX_FILES = 10;

    public const MAX_FILE_KB = 10240;   // 10 MB

    public const MAX_TOTAL_KB = 25600;  // 25 MB

    /**
     * Validate a set of uploaded files against the mail limits.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function guard(array $files): void
    {
        if ($files === []) {
            return;
        }

        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages([
                'attachments' => 'К письму можно прикрепить не более ' . self::MAX_FILES . ' файлов',
            ]);
        }

        $total = 0;
        foreach ($files as $file) {
            $extension = mb_strtolower($file->getClientOriginalExtension());

            if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'attachments' => "Файлы с расширением .{$extension} нельзя отправлять",
                ]);
            }

            if ($file->getSize() > self::MAX_FILE_KB * 1024) {
                throw ValidationException::withMessages([
                    'attachments' => 'Каждый файл должен быть не больше ' . (self::MAX_FILE_KB / 1024) . ' МБ',
                ]);
            }

            $total += (int) $file->getSize();
        }

        if ($total > self::MAX_TOTAL_KB * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'Общий размер вложений не должен превышать ' . (self::MAX_TOTAL_KB / 1024) . ' МБ',
            ]);
        }
    }

    /**
     * Persist uploaded files to the private disk.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{filename: string, mime: ?string, size: int, path: string}>
     */
    public function storeUploads(?int $accountId, array $files): array
    {
        $stored = [];

        foreach ($files as $file) {
            $filename = $file->getClientOriginalName() ?: 'attachment';
            $path = 'mail/' . ($accountId ?? 0) . '/' . Str::uuid() . '-' . $this->safeName($filename);

            try {
                Storage::disk('local')->put($path, $file->get());
            } catch (Throwable) {
                continue;
            }

            $stored[] = [
                'filename' => $filename,
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'path' => $path,
            ];
        }

        return $stored;
    }

    /**
     * Create attachment rows for a message.
     *
     * @param  array<int, array{filename: string, mime: ?string, size: int, path: string}>  $stored
     */
    public function attachToMessage(MailMessage $message, array $stored): void
    {
        foreach ($stored as $item) {
            MailAttachment::create([
                'mail_message_id' => $message->id,
                'filename' => $item['filename'],
                'mime_type' => $item['mime'],
                'size' => $item['size'],
                'path' => $item['path'],
            ]);
        }

        if ($stored !== []) {
            $message->forceFill(['has_attachments' => true])->save();
        }
    }

    /**
     * Move existing attachment rows (e.g. from a draft) to another message.
     *
     * @param  array<int, int>  $ids
     */
    public function moveToMessage(int $userId, int $fromMessageId, MailMessage $target, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        MailAttachment::where('mail_message_id', $fromMessageId)
            ->whereIn('id', $ids)
            ->update(['mail_message_id' => $target->id]);

        if (MailAttachment::where('mail_message_id', $target->id)->exists()) {
            $target->forceFill(['has_attachments' => true])->save();
        }
    }

    /**
     * Delete attachment rows and their files.
     *
     * @param  array<int, int>  $ids
     */
    public function removeByIds(MailMessage $message, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $attachments = MailAttachment::where('mail_message_id', $message->id)
            ->whereIn('id', $ids)
            ->get();

        $this->deleteFiles($attachments->pluck('path')->filter()->all());

        MailAttachment::whereIn('id', $attachments->pluck('id')->all())->delete();
    }

    /**
     * Delete the given storage paths (ignoring missing files).
     *
     * @param  iterable<string>  $paths
     */
    public function deleteFiles(iterable $paths): void
    {
        foreach ($paths as $path) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?? $name;

        return $name !== '' ? Str::limit($name, 120, '') : 'attachment';
    }
}
