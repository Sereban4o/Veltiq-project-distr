<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Models\Company;
use App\Services\Integration\OneC\CounterpartySoapClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * JSON API for the React «Контрагенты» page. Backed by `crm_companies`
 * (extended with 1С fields) and proxies live lookups to the 1С SOAP service.
 */
class CounterpartyController extends Controller
{
    public function __construct(
        private CounterpartySoapClient $client,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);

        $query = Company::query()
            ->with('owner:id,first_name,last_name')
            ->withCount(['contacts', 'deals'])
            ->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('full_name', 'ilike', "%{$search}%")
                    ->orWhere('inn', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function searchOneC(Request $request): JsonResponse
    {
        if (!$this->client->configured()) {
            return response()->json([
                'message' => 'Интеграция с 1С не настроена',
                'configured' => false,
            ], 503);
        }

        $q = trim((string) $request->input('q', ''));
        $inn = trim((string) $request->input('inn', ''));

        // A 10/12-digit query is treated as an INN, otherwise as a name substring.
        if ($inn === '' && preg_match('/^\d{10}$|^\d{12}$/', $q)) {
            $inn = $q;
            $q = '';
        }

        $portion = min(max((int) $request->input('portion', 25), 1), 100);

        $payload = $this->client->search(
            $inn !== '' ? $inn : null,
            $q !== '' ? $q : null,
            $portion,
        );

        if ($payload === null) {
            return response()->json([
                'message' => 'Не удалось получить данные из 1С. Проверьте адрес и учётные данные.',
                'configured' => true,
            ], 502);
        }

        return response()->json([
            'data' => [
                'configured' => true,
                'total' => $payload['Total'],
                'offset' => $payload['Offset'],
                'portion' => $payload['Portion'],
                'items' => $payload['Counterparties'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $ref = $data['ref_1c'] ?? null;

        if ($ref) {
            $data['synced_at'] = now();
        }

        try {
            $company = $this->persist($data, $ref);
        } catch (QueryException $e) {
            // Unique race on ref_1c: retry as an update of the existing row.
            $existing = $ref ? Company::withTrashed()->where('ref_1c', $ref)->first() : null;

            if (!$existing) {
                throw $e;
            }

            $existing->fill($data)->save();
            $company = $existing;
        }

        $status = $company->wasRecentlyCreated ? 201 : 200;

        return response()->json(['data' => $company], $status);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate($this->rules());

        if (array_key_exists('ref_1c', $data) && $data['ref_1c']) {
            $data['synced_at'] = now();
        }

        $company->update($data);

        return response()->json(['data' => $company->fresh()]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?string $ref): Company
    {
        if ($ref) {
            $company = Company::withTrashed()->where('ref_1c', $ref)->first();

            if ($company) {
                $company->fill($data)->save();

                if ($company->trashed()) {
                    $company->restore();
                }

                return $company;
            }
        }

        return Company::create($data + ['owner_id' => Auth::id()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'ref_1c' => 'nullable|uuid',
            'name' => 'required|string|max:255',
            'full_name' => 'nullable|string|max:500',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:12',
            'code_1c' => 'nullable|string|max:32',
            'legal_physical_person' => 'nullable|string|max:32',
            'registration_country' => 'nullable|string|max:128',
            'phone' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65000',
            'contact_info' => 'nullable|array',
            'contact_info.*' => 'array',
            'deleted_in_1c' => 'sometimes|boolean',
        ];
    }
}
