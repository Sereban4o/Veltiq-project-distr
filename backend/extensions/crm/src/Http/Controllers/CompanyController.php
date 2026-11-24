<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $query = Company::query()
            ->with('owner:id,first_name,last_name')
            ->withCount(['contacts', 'deals'])
            ->orderBy('name');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('full_name', 'ilike', "%{$search}%")
                    ->orWhere('inn', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);
        $ref = $validated['ref_1c'] ?? null;

        if ($ref) {
            $validated['synced_at'] = now();

            // A counterparty may already exist locally (e.g. imported from 1С).
            $existing = Company::withTrashed()->where('ref_1c', $ref)->first();

            if ($existing) {
                $existing->fill($validated)->save();

                if ($existing->trashed()) {
                    $existing->restore();
                }

                return response()->json(['data' => $existing], 200);
            }
        }

        $company = Company::create($validated + ['owner_id' => Auth::id()]);

        return response()->json(['data' => $company], 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json([
            'data' => $company->load([
                'contacts:id,first_name,last_name,email,phone,position,company_id',
                'deals' => fn ($q) => $q->with('stage:id,name,color')->latest(),
                'activities' => fn ($q) => $q->latest(),
                'owner:id,first_name,last_name',
            ]),
        ]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $this->validateData($request);

        if (!empty($data['ref_1c'])) {
            $data['synced_at'] = now();
        }

        $company->update($data);

        return response()->json(['data' => $company->fresh()]);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json(['message' => 'Компания удалена']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'industry' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:65000',
            // 1С counterparty fields
            'ref_1c' => 'nullable|uuid',
            'full_name' => 'nullable|string|max:500',
            'inn' => 'nullable|string|max:12',
            'kpp' => 'nullable|string|max:12',
            'code_1c' => 'nullable|string|max:32',
            'legal_physical_person' => 'nullable|string|max:32',
            'registration_country' => 'nullable|string|max:128',
            'contact_info' => 'nullable|array',
            'contact_info.*' => 'array',
            'deleted_in_1c' => 'sometimes|boolean',
        ]);
    }
}
