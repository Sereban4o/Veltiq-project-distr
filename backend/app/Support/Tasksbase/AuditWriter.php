<?php

namespace App\Support\Tasksbase;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Central writer for the polymorphic activity log.
 */
class AuditWriter
{
    /**
     * Record a single activity entry for a subject.
     *
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        Model $subject,
        string $action,
        array $changes = [],
        array $properties = [],
        ?string $description = null,
        ?int $userId = null,
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $userId ?? self::actorId(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'changes' => $changes ?: null,
            'properties' => $properties ?: null,
            'description' => $description,
        ]);
    }

    /**
     * Identifier of the acting user, or null when nobody is logged in.
     *
     * The guard is only asked when it already holds a user: resolving it would
     * start the session and could pick up the identity of another request (in
     * queue workers, Octane or between test requests).
     */
    private static function actorId(): ?int
    {
        $guard = Auth::guard();

        if (! $guard->hasUser()) {
            return null;
        }

        $id = $guard->id();

        return $id !== null ? (int) $id : null;
    }

    /**
     * Build an [attribute => ['old' => x, 'new' => y]] map from two snapshots,
     * omitting unchanged and non-existing keys.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public static function diff(array $original, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $key => $value) {
            if (! array_key_exists($key, $original)) {
                continue;
            }

            if (data_get($original, $key) != $value) {
                $changes[$key] = [
                    'old' => $original[$key],
                    'new' => $value,
                ];
            }
        }

        return $changes;
    }
}
