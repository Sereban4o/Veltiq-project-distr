<?php

namespace App\Http\Controllers\Api\Storage;

use App\Http\Controllers\Api\Storage\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Services\Storage\StorageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuotaController extends Controller
{
    use MapsStorageErrors;

    public function __construct(private StorageQuotaService $quota)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return $this->storage(function () use ($request) {
            return response()->json(['data' => $this->quota->summary($request->user())]);
        });
    }
}
