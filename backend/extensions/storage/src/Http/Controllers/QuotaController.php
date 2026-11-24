<?php

namespace App\Extensions\Storage\Http\Controllers;

use App\Extensions\Storage\Http\Controllers\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Extensions\Storage\Services\StorageQuotaService;
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
