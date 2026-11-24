<?php

namespace App\Http\Middleware;

use App\Services\Extensions\ExtensionRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses routes that belong to an extension which is switched off.
 *
 * This gate is independent of the license gate: an extension can be disabled by
 * the administrator regardless of licensing, and a disabled extension's whole
 * HTTP surface answers 403 (docs/11-extensions.md).
 *
 * Applied as `extension.enabled:<id>`. It fails open: when the core (the
 * authority on extension state) is unreachable and nothing is cached, the
 * extension stays available — a control-plane outage must not take the product
 * down.
 */
class EnsureExtensionEnabled
{
    public function __construct(private readonly ExtensionRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $extension = ''): Response
    {
        // Automated tests must not depend on the operator's live extension state:
        // parity tests talk to a real core, whose extensions can be switched off
        // in the admin panel. Gate verification tests opt in via
        // `extensions.enforce_in_tests`.
        if (app()->runningUnitTests() && ! config('extensions.enforce_in_tests', false)) {
            return $next($request);
        }

        if ($extension === '' || $this->registry->isEnabled($extension)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'code' => 'extension_disabled',
                'extension' => $extension,
                'message' => 'Расширение выключено администратором.',
            ], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_NOT_FOUND);
    }
}
