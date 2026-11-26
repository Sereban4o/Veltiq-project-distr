<?php

namespace App\Extensions\Ai\Retrieval\Providers;

use App\Extensions\Ai\Retrieval\AiSource;
use App\Extensions\Ai\Retrieval\AiSourceProvider;
use App\Extensions\Ai\Services\EmployeeDirectory;
use App\Models\User;

/**
 * Colleagues from the shared user directory. Unlike other sources there is no
 * per-employee page in the SPA, so the hit carries no link.
 */
final class EmployeeProvider implements AiSourceProvider
{
    public function __construct(private readonly EmployeeDirectory $directory)
    {
    }

    public function types(): array
    {
        return ['users'];
    }

    public function extension(): ?string
    {
        return null;
    }

    public function retrieve(User $user, string $query, array $types, int $limit): array
    {
        $employees = $this->directory->search($query, $limit);

        return ['users' => $employees->map(fn (User $employee): array => AiSource::make(
            'users',
            (int) $employee->id,
            $this->directory->fullName($employee),
            '',
            implode(' · ', array_filter([
                (string) ($employee->role?->name ?? ''),
                (string) $employee->email,
            ])),
            [
                'email' => $employee->email,
                'role' => $employee->role?->name,
            ],
        ))->all()];
    }
}
