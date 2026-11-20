<?php

namespace App\Services\Mail;

use App\Models\MailAccount;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends outgoing mail through the user's SMTP server using Symfony Mailer
 * (bundled with Laravel). Kept as a separate class so it can be mocked in tests.
 */
class SmtpSender
{
    /**
     * @param  array{from_email: string, from_name?: ?string, to: array<int, string>, cc?: array<int, string>, bcc?: array<int, string>, subject?: ?string, text?: ?string, html?: ?string, in_reply_to?: ?string}  $message
     */
    public function send(MailAccount $account, array $message): void
    {
        if (empty($account->smtp_host)) {
            throw new RuntimeException('SMTP-сервер не настроен');
        }

        $tls = $account->smtp_encryption === 'ssl';
        $defaultPort = $tls ? 465 : 587;
        $port = (int) ($account->smtp_port ?: $defaultPort);

        $transport = new EsmtpTransport($account->smtp_host, $port, $tls);

        if ($account->smtp_encryption === 'none' && method_exists($transport, 'setAutoTls')) {
            $transport->setAutoTls(false);
        }

        $username = $account->smtpUsernameValue();
        if ($username !== null && $account->smtp_password !== null) {
            $transport->setUsername($username);
            $transport->setPassword($account->smtp_password);
        }

        $email = new Email();
        $fromName = $account->display_name ?: ($message['from_name'] ?? null);
        $email->from($fromName ? new Address($message['from_email'], $fromName) : new Address($message['from_email']));

        foreach ($message['to'] ?? [] as $to) {
            $email->addTo($to);
        }
        foreach ($message['cc'] ?? [] as $cc) {
            $email->addCc($cc);
        }
        foreach ($message['bcc'] ?? [] as $bcc) {
            $email->addBcc($bcc);
        }

        $email->subject((string) ($message['subject'] ?? ''));

        if (!empty($message['text'])) {
            $email->text((string) $message['text']);
        }
        if (!empty($message['html'])) {
            $email->html((string) $message['html']);
        }
        if (empty($message['text']) && empty($message['html'])) {
            $email->text('');
        }

        if (!empty($message['in_reply_to'])) {
            $email->getHeaders()->addIdHeader('In-Reply-To', (string) $message['in_reply_to']);
        }

        (new Mailer($transport))->send($email);
    }
}
