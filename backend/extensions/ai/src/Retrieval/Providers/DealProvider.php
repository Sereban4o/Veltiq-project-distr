<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Retrieval\Concerns\MatchesKeywords;
use App\Extensions\Crm\Models\Deal;
use App\Models\User;

/** CRM deals. The deal's company name is searchable too. */
final class DealProvider implements AiSourceProvider
{
    use MatchesKeywords;

    public function types(): array
    {
        return ['deals'];
    }

    public function extension(): ?string
    {
        return 'crm';
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        $deals = Deal::query()
            ->with(['company:id,name', 'stage:id,name'])
            ->where(function ($group) use ($query): void {
                $this->whereMatchesTokens($group, ['title', 'description'], $query);
                $group->orWhereHas('company', fn ($company) => $this->whereMatchesTokens($company, ['name'], $query));
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return ['deals' => $deals->map(function (Deal $deal): array {
            $amount = trim((string) $deal->amount . ' ' . (string) $deal->currency);

            return AiSource::make(
                'deals',
                (int) $deal->id,
                (string) $deal->title,
                '/crm?section=deals',
                implode(' · ', array_filter([
                    $amount,
                    (string) ($deal->stage?->name ?? ''),
                    (string) ($deal->company?->name ?? ''),
                    trim((string) $deal->description),
                ])),
                [
                    'amount' => $deal->amount,
                    'currency' => $deal->currency,
                    'stage' => $deal->stage?->name,
                    'status' => $deal->status,
                    'expected_close_date' => optional($deal->expected_close_date)->format('Y-m-d'),
                    'company_name' => $deal->company?->name,
                ],
            );
        })->all()];
    }
}
