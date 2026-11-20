<?php

namespace App\Jobs;

use App\Models\MailMessage;
use App\Services\Mail\MailAccountService;
use App\Services\Mail\SmtpSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Delivers a message that was scheduled with "send later".
 *
 * The message is stored as a draft with `scheduled_at`; the job is dispatched
 * with a delay until that moment. Cancelling the schedule (setting
 * `scheduled_at` back to null) makes the job a no-op when it eventually runs.
 */
class SendScheduledMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $messageId)
    {
    }

    public function handle(MailAccountService $accounts, SmtpSender $smtp): void
    {
        $message = MailMessage::with('user')->find($this->messageId);

        if ($message === null
            || $message->folder !== MailMessage::FOLDER_DRAFTS
            || $message->scheduled_at === null
        ) {
            // Cancelled, deleted or already delivered.
            return;
        }

        if ($message->scheduled_at->isFuture()) {
            $this->release(max(1, (int) now()->diffInSeconds($message->scheduled_at)));

            return;
        }

        $user = $message->user;
        if ($user === null) {
            return;
        }

        $account = $accounts->forUser($user);
        if ($account === null || empty($account->smtp_host)) {
            throw new RuntimeException('SMTP-сервер не настроен в профиле');
        }

        $recipients = [
            'to' => $this->emails($message->to_recipients),
            'cc' => $this->emails($message->cc_recipients),
            'bcc' => $this->emails($message->bcc_recipients),
        ];

        if ($recipients['to'] === []) {
            throw new RuntimeException('У письма нет получателей');
        }

        $smtp->send($account, [
            'from_email' => $account->email,
            'from_name' => $account->display_name,
            'to' => $recipients['to'],
            'cc' => $recipients['cc'],
            'bcc' => $recipients['bcc'],
            'subject' => (string) $message->subject,
            'text' => $message->body_text,
            'html' => $message->body_html,
            'in_reply_to' => $message->in_reply_to,
        ]);

        $message->forceFill([
            'folder' => MailMessage::FOLDER_SENT,
            'scheduled_at' => null,
            'sent_at' => now(),
            'is_read' => true,
            'source' => 'smtp',
        ])->save();
    }

    /**
     * @param  array<int, mixed>|null  $recipients
     * @return array<int, string>
     */
    private function emails(?array $recipients): array
    {
        return collect($recipients ?? [])
            ->map(fn ($recipient) => is_array($recipient) ? ($recipient['email'] ?? null) : $recipient)
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->values()
            ->all();
    }
}
