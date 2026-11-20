<?php

namespace App\Http\Middleware;

use App\Services\Assets\BundleIntegrity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Compares the bundle id reported by the web client with the pinned release id.
 * Mismatches are logged and audited; the request always continues (§9).
 */
class VerifyBundleIntegrity
{
    public function __construct(private readonly BundleIntegrity $integrity)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $result = $this->integrity->check($request->header('X-Veltiq-Bundle'));

            if ($result !== null && ! $result['ok']) {
                $this->integrity->reportMismatch($result, $request->ip());
            }
        } catch (Throwable) {
            // Integrity reporting must never break a request.
        }

        return $next($request);
    }
}
