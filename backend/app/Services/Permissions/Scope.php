<?php

namespace App\Services\Permissions;

use App\Models\Project;
use App\Models\Space;

/**
 * Value object describing where a permission is being checked.
 */
final class Scope
{
    public function __construct(
        public readonly string $type,
        public readonly ?int $id = null,
    ) {
    }

    public static function global(): self
    {
        return new self('global', null);
    }

    public static function project(Project $project): self
    {
        return new self('project', (int) $project->getKey());
    }

    public static function space(Space $space): self
    {
        return new self('space', (int) $space->getKey());
    }

    public static function fromModel(mixed $model): ?self
    {
        return match (true) {
            $model instanceof self => $model,
            $model instanceof Project => self::project($model),
            $model instanceof Space => self::space($model),
            default => null,
        };
    }

    public function key(): string
    {
        return $this->id === null ? $this->type : "{$this->type}:{$this->id}";
    }

    public function isGlobal(): bool
    {
        return $this->type === 'global';
    }
}
