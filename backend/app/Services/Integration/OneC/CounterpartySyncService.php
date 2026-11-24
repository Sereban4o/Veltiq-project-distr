<?php

namespace App\Services\Integration\OneC;

use App\Extensions\Crm\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Imports 1С counterparties into `crm_companies`, keyed by the 1С `Ref` UUID.
 */
class CounterpartySyncService
{
    public function __construct(
        private CounterpartySoapClient $client,
    ) {
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    /**
     * Map one 1С record onto a CRM company (creating or updating by ref_1c).
     */
    public function upsert(array $row, ?int $ownerId = null, bool &$restored = false): ?Company
    {
        $restored = false;

        $ref = $this->normalizeRef($row['Ref'] ?? '');
        if ($ref === '') {
            return null;
        }

        $attributes = $this->map($row);
        $contacts = $this->deriveContacts((array) ($row['ContactInfo'] ?? []));

        return DB::transaction(function () use ($ref, $attributes, $contacts, $ownerId, &$restored) {
            $company = Company::withTrashed()->where('ref_1c', $ref)->first();

            if ($company) {
                $wasTrashed = $company->trashed();

                $fill = $attributes;
                foreach (['phone', 'email', 'address'] as $field) {
                    if (trim((string) $company->{$field}) === '' && ($contacts[$field] ?? null) !== null) {
                        $fill[$field] = $contacts[$field];
                    }
                }

                if ($company->owner_id === null && $ownerId !== null) {
                    $fill['owner_id'] = $ownerId;
                }

                $company->fill($fill)->save();

                if ($wasTrashed) {
                    $company->restore();
                    $restored = true;
                }

                return $company;
            }

            return Company::create($attributes + $contacts + [
                'ref_1c' => $ref,
                'owner_id' => $ownerId,
            ]);
        });
    }

    /**
     * Import the whole catalogue, page by page.
     *
     * @return array{configured:bool,pages:int,total:int,created:int,updated:int,restored:int,skipped:int,error:?string}
     */
    public function syncAll(?int $ownerId = null, ?int $portion = null, ?int $maxPages = null): array
    {
        $stats = [
            'configured' => $this->configured(),
            'pages' => 0,
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'restored' => 0,
            'skipped' => 0,
            'error' => null,
        ];

        if (!$stats['configured']) {
            $stats['error'] = 'not_configured';

            return $stats;
        }

        $portion ??= (int) config('one_c.counterparties.portion', 500);
        $maxPages ??= (int) config('one_c.counterparties.max_pages', 100);

        try {
            foreach ($this->client->fetchAll($portion, $maxPages) as $page) {
                $stats['pages']++;
                $stats['total'] = $page['Total'];

                foreach ($page['Counterparties'] as $row) {
                    $restored = false;
                    $company = $this->upsert($row, $ownerId, $restored);

                    if ($company === null) {
                        $stats['skipped']++;

                        continue;
                    }

                    if ($company->wasRecentlyCreated) {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }

                    if ($restored) {
                        $stats['restored']++;
                    }
                }
            }
        } catch (OneCSoapException) {
            $stats['error'] = 'request_failed';
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(array $row): array
    {
        $name = trim((string) ($row['Description'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['FullDescription'] ?? ''));
        }
        if ($name === '') {
            $name = trim('Контрагент ' . (string) ($row['Code'] ?? ''));
        }
        if ($name === 'Контрагент') {
            $name = $this->normalizeRef($row['Ref'] ?? '');
        }

        $contactInfo = array_values(array_filter((array) ($row['ContactInfo'] ?? []), 'is_array'));

        return [
            'ref_1c' => $this->normalizeRef($row['Ref'] ?? ''),
            'name' => $this->str($name, 255),
            'code_1c' => $this->str($row['Code'] ?? null, 32),
            'full_name' => $this->str($row['FullDescription'] ?? null, 500),
            'inn' => $this->str($row['INN'] ?? null, 12),
            'kpp' => $this->str($row['KPP'] ?? null, 12),
            'legal_physical_person' => $this->str($row['LegalPhysicalPerson'] ?? null, 32),
            'registration_country' => $this->str($row['RegistrationCountry'] ?? null, 128),
            'registration_number' => $this->str($row['RegistrationNumber'] ?? null, 64),
            'tax_number' => $this->str($row['TaxNumber'] ?? null, 64),
            'okpo_code' => $this->str($row['OKPOCode'] ?? null, 32),
            'head_counterpart' => $this->str($row['HeadCounterpart'] ?? null, 255),
            'partner_1c' => $this->str($row['Partner'] ?? null, 255),
            'international_description' => $this->str($row['InternationalDescription'] ?? null, 500),
            'transcription_description' => $this->str($row['TranscriptionDescription'] ?? null, 500),
            'additional_information' => $this->str($row['AdditionalInformation'] ?? null, null),
            'contact_info' => $contactInfo ?: null,
            'deleted_in_1c' => (bool) ($row['DeletionMark'] ?? false),
            'synced_at' => now(),
        ];
    }

    /**
     * Derive the legacy CRM contact columns from the 1С ContactInfo array.
     *
     * @return array{phone:?string,email:?string,address:?string}
     */
    private function deriveContacts(array $contacts): array
    {
        $result = ['phone' => null, 'email' => null, 'address' => null];

        foreach ($contacts as $contact) {
            $kind = mb_strtolower((string) ($contact['Kind'] ?? ''));
            if ($kind === '') {
                continue;
            }

            $presentation = (string) ($contact['Presentation'] ?? '');
            $value = (string) ($contact['Value'] ?? '');

            if ($result['phone'] === null && (mb_strpos($kind, 'телефон') !== false || mb_strpos($kind, 'phone') !== false)) {
                $result['phone'] = $this->str(
                    (string) ($contact['PhoneNumber'] ?? '') ?: ($presentation ?: $value),
                    64,
                );
            }

            if ($result['email'] === null && (mb_strpos($kind, 'почт') !== false || mb_strpos($kind, 'электрон') !== false || mb_strpos($kind, 'mail') !== false)) {
                $candidate = $value ?: $presentation;
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $result['email'] = $this->str($candidate, 255);
                }
            }

            if ($result['address'] === null && (mb_strpos($kind, 'адрес') !== false || mb_strpos($kind, 'address') !== false)) {
                $result['address'] = $this->str($presentation ?: $value, 255);
            }
        }

        return $result;
    }

    private function normalizeRef(mixed $ref): string
    {
        return strtolower(trim(trim((string) $ref), '{}'));
    }

    private function str(mixed $value, ?int $limit): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $limit !== null ? mb_substr($value, 0, $limit) : $value;
    }
}
