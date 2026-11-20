<?php

namespace App\Services\Crm;

use App\Models\Crm\Company;
use App\Models\Crm\Contact;
use App\Models\Crm\Deal;
use App\Models\Crm\PipelineStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export/import for CRM contacts, companies and deals.
 *
 * Uses native CSV functions (no extra package). Imports are all-or-nothing:
 * any invalid row aborts the whole batch and the errors are reported back.
 */
class CrmCsvService
{
    public const MAX_ROWS = 5000;

    public const ENTITIES = ['companies', 'contacts', 'deals'];

    /**
     * Column definitions: field => Russian header.
     *
     * @return array<string, string>
     */
    public function headers(string $entity): array
    {
        return match ($entity) {
            'companies' => [
                'name' => 'Название',
                'full_name' => 'Полное наименование',
                'inn' => 'ИНН',
                'kpp' => 'КПП',
                'website' => 'Сайт',
                'phone' => 'Телефон',
                'email' => 'Email',
                'address' => 'Адрес',
                'industry' => 'Сфера',
                'notes' => 'Заметки',
            ],
            'contacts' => [
                'first_name' => 'Имя',
                'last_name' => 'Фамилия',
                'email' => 'Email',
                'phone' => 'Телефон',
                'position' => 'Должность',
                'company' => 'Компания',
                'notes' => 'Заметки',
            ],
            'deals' => [
                'title' => 'Название',
                'company' => 'Компания',
                'contact' => 'Контакт (email)',
                'stage' => 'Этап',
                'amount' => 'Сумма',
                'currency' => 'Валюта',
                'probability' => 'Вероятность',
                'expected_close_date' => 'Дата закрытия',
                'description' => 'Описание',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function export(string $entity, Request $request): StreamedResponse
    {
        $headers = $this->headers($entity);
        $rows = $this->exportRows($entity, $request);

        $filename = $entity . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Cyrillic correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($headers), ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array<int, array<int, string|int|float|null>>
     */
    private function exportRows(string $entity, Request $request): array
    {
        if ($entity === 'companies') {
            $query = Company::query()->orderBy('name');
            if ($search = trim((string) $request->input('search'))) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('full_name', 'ilike', "%{$search}%")
                        ->orWhere('inn', 'ilike', "%{$search}%");
                });
            }

            return $query->get()->map(fn (Company $c) => [
                $c->name, $c->full_name, $c->inn, $c->kpp, $c->website,
                $c->phone, $c->email, $c->address, $c->industry, $c->notes,
            ])->all();
        }

        if ($entity === 'contacts') {
            $query = Contact::query()->with('company:id,name')->orderBy('first_name');
            if ($companyId = $request->input('company_id')) {
                $query->where('company_id', $companyId);
            }
            if ($search = trim((string) $request->input('search'))) {
                $query->where(function ($q) use ($search) {
                    $q->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            }

            return $query->get()->map(fn (Contact $c) => [
                $c->first_name, $c->last_name, $c->email, $c->phone,
                $c->position, $c->company?->name, $c->notes,
            ])->all();
        }

        $query = Deal::query()
            ->with(['company:id,name', 'contact:id,email', 'stage:id,name'])
            ->orderByDesc('updated_at');
        if ($stageId = $request->input('stage_id')) {
            $query->where('stage_id', $stageId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'ilike', "%{$search}%");
        }

        return $query->get()->map(fn (Deal $d) => [
            $d->title, $d->company?->name, $d->contact?->email, $d->stage?->name,
            $d->amount, $d->currency, $d->probability,
            $d->expected_close_date?->toDateString(), $d->description,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $mapping  field => column index or header name
     * @return array<string, mixed>
     */
    public function import(
        string $entity,
        UploadedFile $file,
        array $mapping,
        bool $dryRun = false,
        bool $updateExisting = false,
        ?string $delimiter = null,
    ): array {
        $definitions = $this->headers($entity);
        [$header, $dataRows] = $this->readCsv($file, $delimiter);

        $index = $this->resolveMapping($definitions, $header, $mapping);

        $total = count($dataRows);
        $errors = [];
        $duplicates = [];
        $prepared = [];

        if ($total > self::MAX_ROWS) {
            return $this->result($total, 0, 0, 0, [[
                'row' => 0,
                'message' => 'Файл содержит более ' . self::MAX_ROWS . ' строк',
            ]], [], $dryRun, $definitions);
        }

        foreach ($dataRows as $offset => $line) {
            $rowNumber = $offset + 2; // +1 header, +1 one-based
            $attrs = $this->extract($line, $index);

            $validator = Validator::make($attrs, $this->importRules($entity));
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = ['row' => $rowNumber, 'message' => $message];
                }
                continue;
            }

            $foreign = $this->resolveForeign($entity, $attrs, $rowNumber, $dryRun);
            if ($foreign['errors'] !== []) {
                $errors = array_merge($errors, $foreign['errors']);
                continue;
            }

            $prepared[] = ['row' => $rowNumber, 'attributes' => array_merge($attrs, $foreign['attributes'])];
        }

        if ($errors !== []) {
            return $this->result($total, count($prepared), 0, 0, $errors, [], $dryRun, $definitions);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        if ($dryRun) {
            // Detect duplicates for the preview without writing anything.
            foreach ($prepared as $item) {
                if ($this->findDuplicate($entity, $item['attributes'])) {
                    $duplicates[] = ['row' => $item['row'], 'message' => 'Дубликат — будет пропущен или обновлён'];
                }
            }

            return $this->result($total, count($prepared), 0, 0, [], $duplicates, true, $definitions, $prepared);
        }

        DB::transaction(function () use ($entity, $prepared, $updateExisting, &$created, &$updated, &$skipped, &$duplicates) {
            foreach ($prepared as $item) {
                $existing = $this->findDuplicate($entity, $item['attributes']);

                if ($existing) {
                    if ($updateExisting) {
                        $existing->fill($this->writable($item['attributes']))->save();
                        $updated++;
                    } else {
                        $duplicates[] = ['row' => $item['row'], 'message' => 'Дубликат — пропущено'];
                        $skipped++;
                    }
                    continue;
                }

                $this->modelFor($entity)::create($this->writable($item['attributes']) + ['owner_id' => Auth::id()]);
                $created++;
            }
        });

        return $this->result($total, count($prepared), $created, $updated, [], $duplicates, false, $definitions, $prepared);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $prepared
     * @return array<string, mixed>
     */
    private function result(
        int $total,
        int $valid,
        int $created,
        int $updated,
        array $errors,
        array $duplicates,
        bool $dryRun,
        array $definitions,
        ?array $prepared = null,
    ): array {
        $preview = [];
        if ($dryRun && $prepared !== null) {
            foreach (array_slice($prepared, 0, 50) as $item) {
                $preview[] = $item['attributes'];
            }
        }

        return [
            'total' => $total,
            'valid' => $valid - $updated,
            'created' => $created,
            'updated' => $updated,
            'skipped' => max(0, $valid - $created - $updated),
            'errors' => $errors,
            'duplicates' => $duplicates,
            'dry_run' => $dryRun,
            'headers' => array_keys($definitions),
            'preview' => $preview,
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function readCsv(UploadedFile $file, ?string $delimiter): array
    {
        $content = (string) file_get_contents($file->getRealPath());
        // Strip a UTF-8 BOM if present.
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        if ($content !== '' && ! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));

        if ($lines === []) {
            return [[], []];
        }

        $delimiter = $delimiter ?: $this->detectDelimiter($lines[0]);

        $header = str_getcsv((string) array_shift($lines), $delimiter);
        $rows = array_map(fn ($line) => str_getcsv((string) $line, $delimiter), $lines);

        return [$header, $rows];
    }

    private function detectDelimiter(string $line): string
    {
        return substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
    }

    /**
     * @param  array<string, string>  $definitions
     * @param  array<int, string>  $header
     * @param  array<string, mixed>  $mapping
     * @return array<string, int>
     */
    private function resolveMapping(array $definitions, array $header, array $mapping): array
    {
        $index = [];

        foreach ($definitions as $field => $label) {
            if (array_key_exists($field, $mapping) && $mapping[$field] !== null && $mapping[$field] !== '') {
                $candidate = $mapping[$field];
                if (is_numeric($candidate)) {
                    $index[$field] = (int) $candidate;
                } else {
                    $pos = array_search((string) $candidate, $header, true);
                    if ($pos !== false) {
                        $index[$field] = (int) $pos;
                    }
                }
                continue;
            }

            // Auto-map by matching the CSV header against our Russian labels.
            $pos = array_search($label, $header, true);
            if ($pos !== false) {
                $index[$field] = (int) $pos;
            }
        }

        return $index;
    }

    /**
     * @param  array<int, string>  $line
     * @param  array<string, int>  $index
     * @return array<string, mixed>
     */
    private function extract(array $line, array $index): array
    {
        $attrs = [];
        foreach ($index as $field => $position) {
            $value = $line[$position] ?? null;
            $attrs[$field] = is_string($value) ? trim($value) : $value;
        }

        // Normalise empty strings to null so nullable validation passes.
        return array_map(fn ($v) => $v === '' ? null : $v, $attrs);
    }

    /**
     * @return array<string, string>
     */
    private function importRules(string $entity): array
    {
        return match ($entity) {
            'companies' => [
                'name' => 'required|string|max:255',
                'full_name' => 'nullable|string|max:500',
                'inn' => 'nullable|string|max:12',
                'kpp' => 'nullable|string|max:12',
                'website' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:64',
                'email' => 'nullable|email|max:255',
                'address' => 'nullable|string|max:255',
                'industry' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:65000',
            ],
            'contacts' => [
                'first_name' => 'required|string|max:255',
                'last_name' => 'nullable|string|max:255',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:64',
                'position' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:65000',
            ],
            'deals' => [
                'title' => 'required|string|max:255',
                'amount' => 'nullable|numeric|min:0',
                'currency' => 'nullable|string|max:8',
                'probability' => 'nullable|integer|min:0|max:100',
                'expected_close_date' => 'nullable|date',
                'description' => 'nullable|string|max:65000',
            ],
            default => [],
        };
    }

    /**
     * Resolve foreign references (company/contact/stage) to ids.
     *
     * @param  array<string, mixed>  $attrs
     * @return array{attributes: array<string, mixed>, errors: array<int, array<string, mixed>>}
     */
    private function resolveForeign(string $entity, array $attrs, int $rowNumber, bool $dryRun): array
    {
        $attributes = [];
        $errors = [];

        if ($entity === 'contacts' && ! empty($attrs['company'])) {
            $company = Company::firstOrCreate(
                ['name' => $attrs['company']],
                ['owner_id' => Auth::id()],
            );
            $attributes['company_id'] = $company->id;
        }

        if ($entity === 'deals') {
            if (! empty($attrs['company'])) {
                $company = Company::firstOrCreate(
                    ['name' => $attrs['company']],
                    ['owner_id' => Auth::id()],
                );
                $attributes['company_id'] = $company->id;
            }

            if (! empty($attrs['contact'])) {
                $contact = Contact::where('email', 'ilike', $attrs['contact'])->first();
                if ($contact) {
                    $attributes['contact_id'] = $contact->id;
                } else {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Контакт с email ' . $attrs['contact'] . ' не найден'];
                }
            }

            if (! empty($attrs['stage'])) {
                $stage = PipelineStage::where('name', 'ilike', $attrs['stage'])->first();
                if ($stage) {
                    $attributes['stage_id'] = $stage->id;
                } else {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Этап «' . $attrs['stage'] . '» не найден'];
                }
            }
        }

        return ['attributes' => $attributes, 'errors' => $errors];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function findDuplicate(string $entity, array $attributes): ?Model
    {
        if ($entity === 'contacts') {
            if (empty($attributes['email'])) {
                return null;
            }

            return Contact::where('email', 'ilike', $attributes['email'])->first();
        }

        if ($entity === 'companies') {
            if (! empty($attributes['email'])) {
                return Company::where('email', 'ilike', $attributes['email'])->first();
            }
            if (! empty($attributes['inn'])) {
                return Company::where('inn', $attributes['inn'])->first();
            }

            return Company::where('name', 'ilike', $attributes['name'] ?? '')->first();
        }

        return Deal::where('title', 'ilike', $attributes['title'] ?? '')
            ->when(
                ! empty($attributes['company_id']),
                fn ($q) => $q->where('company_id', $attributes['company_id']),
                fn ($q) => $q->whereNull('company_id'),
            )
            ->first();
    }

    /**
     * Keep only attributes the target model accepts.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        unset($attributes['company'], $attributes['contact'], $attributes['stage']);

        return $attributes;
    }

    private function modelFor(string $entity): string
    {
        return match ($entity) {
            'companies' => Company::class,
            'contacts' => Contact::class,
            'deals' => Deal::class,
        };
    }

    public function isValidEntity(string $entity): bool
    {
        return in_array($entity, self::ENTITIES, true);
    }
}
