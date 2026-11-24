<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        $query = Contact::query()
            ->with(['company:id,name', 'owner:id,first_name,last_name'])
            ->orderBy('first_name');

        if ($companyId = $request->input('company_id')) {
            $query->where('company_id', $companyId);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            });
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $contact = Contact::create($this->validateData($request) + ['owner_id' => Auth::id()]);

        return response()->json(['data' => $contact->load('company:id,name')], 201);
    }

    public function show(Contact $contact): JsonResponse
    {
        return response()->json([
            'data' => $contact->load([
                'company:id,name',
                'deals' => fn ($q) => $q->with('stage:id,name,color')->latest(),
                'activities' => fn ($q) => $q->latest(),
                'owner:id,first_name,last_name',
            ]),
        ]);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $contact->update($this->validateData($request));

        return response()->json(['data' => $contact->fresh('company:id,name')]);
    }

    public function destroy(Contact $contact): JsonResponse
    {
        $contact->delete();

        return response()->json(['message' => 'Контакт удалён']);
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:64',
            'position' => 'nullable|string|max:255',
            'company_id' => 'nullable|exists:crm_companies,id',
            'notes' => 'nullable|string|max:65000',
        ]);
    }
}
