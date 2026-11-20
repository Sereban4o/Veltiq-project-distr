<?php

namespace App\Observers;

use App\Support\Tasksbase\AuditWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic audit trail for models that do not log their changes explicitly.
 *
 * Issue-related activity is recorded by IssueController/IssueWorklogController
 * with richer, domain-specific actions, so Issue is intentionally not observed.
 */
class AuditObserver
{
    /** Attributes never written to the audit trail. */
    private const IGNORED_ATTRIBUTES = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function created(Model $model): void
    {
        $this->write($model, 'created', [], [
            'attributes' => $this->filter($model->getAttributes()),
        ]);
    }

    public function updated(Model $model): void
    {
        $changes = [];

        foreach ($model->getChanges() as $attribute => $newValue) {
            if (in_array($attribute, self::IGNORED_ATTRIBUTES, true)) {
                continue;
            }

            $changes[$attribute] = [
                'old' => $model->getOriginal($attribute),
                'new' => $newValue,
            ];
        }

        if ($changes === []) {
            return;
        }

        $this->write($model, 'updated', $changes);
    }

    public function deleted(Model $model): void
    {
        $this->write($model, 'deleted', [], [
            'attributes' => $this->filter($model->getOriginal()),
        ]);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    private function write(Model $model, string $action, array $changes = [], array $properties = []): void
    {
        try {
            AuditWriter::record($model, $action, $changes, $properties);
        } catch (Throwable $e) {
            // Auditing must never break the business operation it observes.
            Log::warning('Audit log write failed', [
                'model' => $model::class,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filter(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::IGNORED_ATTRIBUTES));
    }
}
