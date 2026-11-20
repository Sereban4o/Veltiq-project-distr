<?php

namespace App\Http\Controllers\Api\Storage\Concerns;

use App\Services\Storage\StorageConflictException;
use App\Services\Storage\StorageNotFoundException;
use App\Services\Storage\StorageQuotaException;
use App\Services\Storage\StorageShareUnavailableException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Translates storage domain exceptions into the API error contract:
 * 413 quota, 409 conflict, 410 gone, 404 not found, 403 forbidden, 422 invalid.
 */
trait MapsStorageErrors
{
    protected function storage(callable $callback)
    {
        try {
            return $callback();
        } catch (StorageQuotaException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'quota_exceeded',
                'data' => $e->payload(),
            ], 413);
        } catch (StorageConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->conflictCode,
                'data' => $e->payload(),
            ], 409);
        } catch (StorageShareUnavailableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'gone'], 410);
        } catch (StorageNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'forbidden'], 403);
        } catch (HttpExceptionInterface $e) {
            // abort(403)/abort(404) inside a storage handler must keep its own
            // status instead of falling through to the generic 422 mapping.
            $status = $e->getStatusCode();

            return response()->json([
                'message' => $e->getMessage(),
                'code' => match ($status) {
                    403 => 'forbidden',
                    404 => 'not_found',
                    410 => 'gone',
                    default => 'error',
                },
            ], $status);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_path'], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Не удалось выполнить операцию с файлом'], 500);
        }
    }
}
