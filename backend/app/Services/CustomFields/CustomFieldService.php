<?php

namespace App\Services\CustomFields;

use App\Models\CustomField;
use Illuminate\Support\Collection;

/**
 * Validates and normalises admin-defined custom fields and the values users
 * write into them. Values are stored in the entity's `custom_fields` JSON
 * column, so an unknown or removed field never breaks the entity.
 */
class CustomFieldService
{
    /**
     * @return Collection<int, CustomField>
     */
    public function definitions(string $entity, bool $onlyActive = true): Collection
    {
        return CustomField::query()
            ->forEntity($this->entity($entity))
            ->when($onlyActive, fn ($query) => $query->active())
            ->ordered()
            ->get();
    }

    public function entity(string $entity): string
    {
        return in_array($entity, CustomField::ENTITIES, true) ? $entity : 'issue';
    }

    /**
     * Field definition as shown in the UI.
     *
     * @return array<string, mixed>
     */
    public function present(CustomField $field): array
    {
        return [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'options' => $field->type === 'select' ? array_values($field->options ?? []) : [],
            'required' => (bool) $field->required,
        ];
    }

    /**
     * Validate values against the definitions; unknown keys are dropped.
     *
     * @param  array<string, mixed>  $values
     * @return array{values:array<string,mixed>, errors:array<string,string>}
     */
    public function validate(array $values, Collection $definitions): array
    {
        $clean = [];
        $errors = [];

        foreach ($definitions as $field) {
            $raw = $values[$field->key] ?? null;
            $isEmpty = $raw === null || $raw === '' || (is_array($raw) && $raw === []);

            if ($isEmpty) {
                if ($field->required) {
                    $errors[$field->key] = 'Поле «' . $field->label . '» обязательно';
                } else {
                    $clean[$field->key] = null;
                }

                continue;
            }

            $value = match ($field->type) {
                'number' => is_numeric($raw) ? (float) $raw : null,
                'checkbox' => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                'date' => $this->date($raw),
                'select' => in_array((string) $raw, array_map('strval', $field->options ?? []), true) ? (string) $raw : null,
                'textarea' => mb_substr(strip_tags((string) $raw), 0, 5000),
                default => mb_substr(strip_tags((string) $raw), 0, 500),
            };

            if ($value === null) {
                $errors[$field->key] = 'Некорректное значение для «' . $field->label . '»';

                continue;
            }

            $clean[$field->key] = $value;
        }

        return ['values' => $clean, 'errors' => $errors];
    }

    /**
     * Sanitise an admin field definition; null when it cannot be saved.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function sanitizeDefinition(array $data): ?array
    {
        $key = preg_replace('/[^a-z0-9_]+/i', '_', (string) ($data['key'] ?? '')) ?? '';
        $key = trim(mb_strtolower($key), '_');

        $type = (string) ($data['type'] ?? 'text');
        if ($key === '' || mb_strlen($key) > 64 || ! in_array($type, CustomField::TYPES, true)) {
            return null;
        }

        $label = trim(strip_tags((string) ($data['label'] ?? '')));

        return [
            'entity' => $this->entity((string) ($data['entity'] ?? 'issue')),
            'key' => $key,
            'label' => $label !== '' ? mb_substr($label, 0, 120) : $key,
            'type' => $type,
            'options' => $type === 'select' ? $this->options($data['options'] ?? null) : null,
            'required' => (bool) ($data['required'] ?? false),
            'position' => (int) ($data['position'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return list<string>
     */
    private function options(mixed $value): array
    {
        $items = is_array($value) ? $value : (preg_split('/\r?\n/', (string) $value) ?: []);

        $result = [];
        foreach ($items as $item) {
            $item = trim(strip_tags((string) $item));
            if ($item !== '') {
                $result[] = mb_substr($item, 0, 120);
            }
        }

        return array_slice(array_values(array_unique($result)), 0, 50);
    }

    private function date(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
