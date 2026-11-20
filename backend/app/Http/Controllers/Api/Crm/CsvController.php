<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmCsvService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export/import endpoints shared by companies, contacts and deals.
 * The target entity is bound per-route.
 */
class CsvController extends Controller
{
    public function __construct(
        private CrmCsvService $csv,
    ) {
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->csv->export($this->entity($request), $request);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|max:10240',
            'mapping' => 'nullable',
            'dry_run' => 'sometimes|boolean',
            'update_existing' => 'sometimes|boolean',
            'delimiter' => 'nullable|string|in:,,;,tab',
        ]);

        $delimiter = match ($validated['delimiter'] ?? null) {
            'tab' => "\t",
            default => $validated['delimiter'] ?? null,
        };

        $result = $this->csv->import(
            $this->entity($request),
            $request->file('file'),
            $this->mapping($validated['mapping'] ?? null),
            (bool) ($validated['dry_run'] ?? false),
            (bool) ($validated['update_existing'] ?? false),
            $delimiter,
        );

        $status = (! $result['dry_run'] && $result['errors'] !== []) ? 422 : 200;

        return response()->json(['data' => $result], $status);
    }

    private function entity(Request $request): string
    {
        $entity = (string) $request->route('entity');
        abort_unless($this->csv->isValidEntity($entity), 404);

        return $entity;
    }

    /**
     * Accept the column mapping either as an array or as a JSON-encoded string
     * (multipart form uploads send it as a string).
     *
     * @return array<string, mixed>
     */
    private function mapping(mixed $mapping): array
    {
        if (is_string($mapping)) {
            $decoded = json_decode($mapping, true);
            $mapping = is_array($decoded) ? $decoded : [];
        }

        return is_array($mapping) ? $mapping : [];
    }
}
