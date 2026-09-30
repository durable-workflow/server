<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;
use Throwable;

final class DatabaseStorageExhausted
{
    public static function is(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException || ! is_array($current->errorInfo)) {
                continue;
            }

            [$state, $code] = $current->errorInfo + [null, null];

            // PDO driver fields are independent of SQL and customer bindings.
            // HY000/1114 is MySQL's full table/tablespace; HY000/13 is SQLITE_FULL.
            if ($state === '53100'
                || ($state === 'HY000'
                    && (is_int($code) || (is_string($code) && ctype_digit($code)))
                    && in_array((int) $code, [1114, 13], true))) {
                return true;
            }
        }

        return false;
    }

    public static function response(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $payload = [
            'reason' => 'database_storage_exhausted',
            'message' => 'Database storage capacity is exhausted. Restore capacity before retrying.',
            'resource' => 'database',
            'retryable' => false,
            'remediation' => 'Inspect database and temporary storage capacity and configured tablespace or table limits. Restore enough capacity, then inspect the workflow or task before retrying the operation.',
        ];

        if (WorkerProtocol::isWorkerPlaneRequest($request)
            && WorkerProtocol::requestUsesCompatibleProtocolVersion($request)) {
            return WorkerProtocol::json($payload, 503);
        }

        if (ControlPlaneProtocol::requestVersion($request) === ControlPlaneProtocol::VERSION) {
            return ControlPlaneProtocol::jsonForRequest($request, $payload, 503);
        }

        return response()->json($payload, 503);
    }
}
