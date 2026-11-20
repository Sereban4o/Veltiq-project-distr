<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\QrLoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * QR login: the web app issues a one-time code, the mobile app claims it and
 * receives a Sanctum token.
 */
class QrLoginController extends Controller
{
    public function __construct(private readonly QrLoginService $qr)
    {
    }

    /** Issue a code (authenticated web session) and build the QR payload. */
    public function start(Request $request): JsonResponse
    {
        $result = $this->qr->start($request->user());

        $payload = 'veltiq://login?' . http_build_query([
            'code' => $result['code'],
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'https' => $request->isSecure() ? 1 : 0,
        ]);

        return response()->json([
            'data' => [
                'code' => $result['code'],
                'expires_at' => $result['expires_at']->toIso8601String(),
                'payload' => $payload,
            ],
        ]);
    }

    /** Redeem a scanned code (unauthenticated) and return a token. */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:200',
            'device_name' => 'sometimes|nullable|string|max:255',
        ]);

        $result = $this->qr->claim($validated['code'], $validated['device_name'] ?? null);

        if ($result === null) {
            return response()->json([
                'message' => 'Код недействителен или истёк. Обновите код на сайте и попробуйте снова.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user' => $result['user'],
            ],
        ]);
    }
}
