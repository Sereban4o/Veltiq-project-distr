<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Retrieval\Concerns\MatchesKeywords;
use App\Extensions\Crm\Models\Contact;
use App\Models\User;

/**
 * CRM contacts. As with companies, the CRM module is the visibility boundary:
 * no per-record ACL exists in the product.
 */
final class ContactProvider implements AiSourceProvider
{
    use MatchesKeywords;

    private const COLUMNS = ['first_name', 'last_name', 'email', 'position', 'notes', 'phone'];

    public function types(): array
    {
        return ['contacts'];
    }

    public function extension(): ?string
    {
        return 'crm';
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        $contacts = Contact::query()
            ->with('company:id,name')
            ->where(fn ($q) => $this->whereMatchesTokens($q, self::COLUMNS, $query))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return ['contacts' => $contacts->map(fn (Contact $contact): array => AiSource::make(
            'contacts',
            (int) $contact->id,
            $contact->full_name !== '' ? $contact->full_name : (string) $contact->email,
            '/crm?section=contacts',
            implode(' · ', array_filter([
                (string) $contact->position,
                (string) ($contact->company?->name ?? ''),
                (string) $contact->email,
                (string) $contact->phone,
            ])),
            [
                'company_id' => $contact->company_id ? (int) $contact->company_id : null,
                'company_name' => $contact->company?->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
            ],
        ))->all()];
    }
}
