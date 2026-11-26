<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Retrieval\Concerns\MatchesKeywords;
use App\Extensions\Crm\Models\Company;
use App\Models\User;

/**
 * CRM companies. The CRM module has no per-record ACL (its own index lists
 * every company to every user), so visibility equals "the module is enabled".
 */
final class CompanyProvider implements AiSourceProvider
{
    use MatchesKeywords;

    private const COLUMNS = ['name', 'full_name', 'inn', 'email', 'phone', 'industry'];

    public function types(): array
    {
        return ['companies'];
    }

    public function extension(): ?string
    {
        return 'crm';
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        $companies = Company::query()
            ->where(fn ($q) => $this->whereMatchesTokens($q, self::COLUMNS, $query))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return ['companies' => $companies->map(fn (Company $company): array => AiSource::make(
            'companies',
            (int) $company->id,
            (string) ($company->name ?: $company->full_name),
            '/crm?section=companies',
            implode(' · ', array_filter([
                (string) $company->industry,
                (string) $company->phone,
                (string) $company->email,
            ])),
            [
                'inn' => $company->inn,
                'phone' => $company->phone,
                'email' => $company->email,
                'owner_id' => $company->owner_id ? (int) $company->owner_id : null,
            ],
        ))->all()];
    }
}
