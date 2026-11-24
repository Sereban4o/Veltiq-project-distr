<?php

namespace App\Extensions\Mail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MailSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MailSignatureController extends Controller
{
    public function index(): JsonResponse
    {
        $signatures = MailSignature::where('user_id', Auth::id())
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $signatures]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'body_html' => 'required|string|max:200000',
            'is_default' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
        ]);

        $signature = DB::transaction(function () use ($validated): MailSignature {
            $isDefault = (bool) ($validated['is_default'] ?? false);

            if ($isDefault) {
                MailSignature::where('user_id', Auth::id())->update(['is_default' => false]);
            }

            return MailSignature::create([
                'user_id' => Auth::id(),
                'name' => $validated['name'],
                'body_html' => $validated['body_html'],
                'is_default' => $isDefault,
                'position' => $validated['position']
                    ?? ((int) MailSignature::where('user_id', Auth::id())->max('position') + 1),
            ]);
        });

        return response()->json(['data' => $signature], 201);
    }

    public function update(Request $request, MailSignature $signature): JsonResponse
    {
        $this->ensureOwned($signature);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'body_html' => 'sometimes|required|string|max:200000',
            'is_default' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
        ]);

        DB::transaction(function () use ($signature, $validated): void {
            if (($validated['is_default'] ?? false) === true) {
                MailSignature::where('user_id', Auth::id())
                    ->where('id', '!=', $signature->id)
                    ->update(['is_default' => false]);
            }

            $signature->update($validated);
        });

        return response()->json(['data' => $signature->fresh()]);
    }

    public function destroy(MailSignature $signature): JsonResponse
    {
        $this->ensureOwned($signature);

        $signature->delete();

        return response()->json(['message' => 'Подпись удалена']);
    }

    private function ensureOwned(MailSignature $signature): void
    {
        if ((int) $signature->user_id !== (int) Auth::id()) {
            abort(404, 'Подпись не найдена');
        }
    }
}
