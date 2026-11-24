<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Facade;
use Laravel\Scout\Searchable;

class MailMessage extends Model
{
    use HasFactory;
    use Searchable {
        bootSearchable as protected bootScoutSearchable;
    }

    public const FOLDER_INBOX = 'inbox';

    public const FOLDER_SENT = 'sent';

    public const FOLDER_DRAFTS = 'drafts';

    public const FOLDER_TRASH = 'trash';

    public const FOLDERS = [
        self::FOLDER_INBOX,
        self::FOLDER_SENT,
        self::FOLDER_DRAFTS,
        self::FOLDER_TRASH,
    ];

    protected $fillable = [
        'mail_account_id', 'user_id', 'folder', 'mail_folder_id',
        'uid', 'remote_uid', 'message_id', 'in_reply_to', 'thread_key', 'references',
        'from_email', 'from_name', 'to_recipients', 'cc_recipients', 'bcc_recipients',
        'subject', 'preview', 'body_text', 'body_html',
        'is_read', 'is_starred', 'has_attachments', 'source', 'raw_headers',
        'sent_at', 'scheduled_at', 'received_at', 'crm_contact_id', 'crm_company_id',
        'assigned_to', 'snoozed_until', 'is_done',
    ];

    protected $casts = [
        'to_recipients' => 'array',
        'cc_recipients' => 'array',
        'bcc_recipients' => 'array',
        'is_read' => 'boolean',
        'is_starred' => 'boolean',
        'is_done' => 'boolean',
        'has_attachments' => 'boolean',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments()
    {
        return $this->hasMany(MailAttachment::class);
    }

    public function mailFolder()
    {
        return $this->belongsTo(MailFolder::class);
    }

    /**
     * Restrict a query to messages the given user may see: their own messages
     * plus every message stored in a mailbox shared with them (team inbox).
     */
    public function scopeAccessibleBy($query, int $userId)
    {
        $sharedAccountIds = MailAccountShare::where('user_id', $userId)
            ->pluck('mail_account_id')
            ->all();

        return $query->where(function ($q) use ($userId, $sharedAccountIds) {
            $q->where('user_id', $userId);

            if (!empty($sharedAccountIds)) {
                $q->orWhereIn('mail_account_id', $sharedAccountIds);
            }
        });
    }

    public function crmContact()
    {
        return $this->belongsTo(\App\Extensions\Crm\Models\Contact::class, 'crm_contact_id');
    }

    public function crmCompany()
    {
        return $this->belongsTo(\App\Extensions\Crm\Models\Company::class, 'crm_company_id');
    }

    // ─── Search (Laravel Scout / Meilisearch) ───

    public function searchableAs(): string
    {
        return 'mail_messages';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'user_id' => (int) $this->user_id,
            'subject' => (string) $this->subject,
            'preview' => (string) $this->preview,
            'body_text' => (string) $this->body_text,
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,
            'received_at' => optional($this->received_at)->timestamp,
            'sent_at' => optional($this->sent_at)->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Scout's boot hook builds a {@see \Laravel\Scout\ModelObserver}, which
     * resolves the Config facade and therefore requires a booted application.
     * Plain PHPUnit unit tests instantiate the model directly (or run after a
     * torn-down application), so registration is skipped whenever there is no
     * usable facade application.
     */
    public static function bootSearchable(): void
    {
        $app = Facade::getFacadeApplication();

        if ($app === null || ! $app->bound('config')) {
            return;
        }

        static::bootScoutSearchable();
    }
}
