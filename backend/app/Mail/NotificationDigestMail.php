<?php

namespace App\Mail;

use App\Models\Setting;
use App\Models\User;
use App\Support\Notifications\NotificationType;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class NotificationDigestMail extends Mailable
{
    /**
     * @param  Collection<int, \App\Models\Notification>  $notifications
     */
    public function __construct(
        public User $user,
        public Collection $notifications,
        public string $frequency = 'daily',
    ) {
    }

    public function envelope(): Envelope
    {
        $address = Setting::get('mail_from_address') ?: config('mail.from.address');

        return new Envelope(
            from: new Address($address, (string) (Setting::get('mail_from_name') ?: config('mail.from.name'))),
            subject: 'Уведомления Veltiq — ' . ($this->frequency === 'weekly' ? 'недельный дайджест' : 'дайджест'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.notification-digest',
            text: 'emails.notification-digest-text',
            with: [
                'user' => $this->user,
                'rows' => $this->rows(),
                'frequency' => $this->frequency,
            ],
        );
    }

    /**
     * @return array<int, array{summary: string, type: string, created_at: string, url: ?string}>
     */
    private function rows(): array
    {
        return $this->notifications->map(function ($notification) {
            $data = (array) ($notification->data ?? []);

            return [
                'summary' => (string) ($data['summary'] ?? $data['message'] ?? NotificationType::tryFromString($notification->type)->label()),
                'type' => NotificationType::tryFromString($notification->type)->label(),
                'created_at' => $notification->created_at?->format('d.m.Y H:i'),
                'url' => $data['url'] ?? null,
            ];
        })->all();
    }
}
