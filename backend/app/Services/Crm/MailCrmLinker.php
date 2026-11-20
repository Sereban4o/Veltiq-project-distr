<?php

namespace App\Services\Crm;

use App\Models\Crm\Company;
use App\Models\Crm\Contact;
use App\Models\MailMessage;
use Illuminate\Support\Facades\DB;

/**
 * Automatically links synced/sent mail to CRM contacts and companies by
 * matching the message addresses against CRM contact/company emails.
 */
class MailCrmLinker
{
    public function link(MailMessage $message): void
    {
        $addresses = $this->addresses($message);
        if ($addresses === []) {
            return;
        }

        $contact = Contact::query()
            ->whereNotNull('email')
            ->whereIn(DB::raw('LOWER(email)'), $addresses)
            ->orderBy('id')
            ->first();

        $company = Company::query()
            ->whereNotNull('email')
            ->whereIn(DB::raw('LOWER(email)'), $addresses)
            ->orderBy('id')
            ->first();

        if ($company === null && $contact?->company_id) {
            $company = Company::find($contact->company_id);
        }

        if ($contact === null && $company === null) {
            return;
        }

        $message->forceFill([
            'crm_contact_id' => $contact?->id,
            'crm_company_id' => $company?->id,
        ])->saveQuietly();
    }

    /**
     * @return array<int, string>
     */
    private function addresses(MailMessage $message): array
    {
        $raw = [];

        if ($message->from_email) {
            $raw[] = $message->from_email;
        }

        foreach (['to_recipients', 'cc_recipients', 'bcc_recipients'] as $field) {
            foreach ((array) $message->{$field} as $recipient) {
                if (is_array($recipient)) {
                    if (! empty($recipient['email'])) {
                        $raw[] = $recipient['email'];
                    }
                } elseif (is_string($recipient)) {
                    $raw[] = $recipient;
                }
            }
        }

        $normalised = array_map(
            fn ($email) => mb_strtolower(trim((string) $email)),
            array_filter($raw, fn ($email) => is_string($email) && trim($email) !== ''),
        );

        return array_values(array_unique($normalised));
    }
}
