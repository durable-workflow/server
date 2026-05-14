<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

final class BackendLockPressure
{
    private const RETRY_AFTER_SECONDS = 1;

    /**
     * SQLite reports concurrent write pressure as SQLSTATE HY000 with
     * SQLITE_BUSY/SQLITE_LOCKED messages. Other backends can surface the
     * same operational condition with lock-timeout or deadlock wording.
     */
    public static function is(Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof BackendLockPressureException) {
                return true;
            }

            $message = strtolower($current->getMessage());

            if (
                str_contains($message, 'database is locked')
                || str_contains($message, 'database table is locked')
                || str_contains($message, 'database schema is locked')
                || str_contains($message, 'sqlite_busy')
                || str_contains($message, 'sqlite_locked')
                || str_contains($message, 'lock wait timeout exceeded')
                || str_contains($message, 'deadlock found when trying to get lock')
                || str_contains($message, 'canceling statement due to lock timeout')
                || str_contains($message, 'could not obtain lock')
            ) {
                return true;
            }

            if ($current instanceof QueryException || $current instanceof PDOException) {
                $errorInfo = $current->errorInfo ?? null;

                if (is_array($errorInfo) && self::hasSqliteBusyCode($errorInfo)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function workerPollResponse(
        string $taskKind,
        string $namespace,
        string $taskQueue,
    ): JsonResponse {
        return WorkerProtocol::json([
            'task' => null,
            'poll_status' => 'backend_lock_pressure',
            'error' => 'Worker poll backend is temporarily locked.',
            'reason' => 'backend_lock_pressure',
            'message' => 'The database backend is under lock pressure while claiming a task. '
                .'Retry the poll with backoff; if this persists on SQLite, keep the quickstart '
                .'to one server container or use MySQL/PostgreSQL with Redis for multi-node '
                .'deployments.',
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'task_kind' => $taskKind,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'backend' => [
                'driver' => self::driverName(),
                'lock_pressure' => true,
            ],
        ], 503)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    /**
     * @param  array<int, mixed>  $errorInfo
     */
    private static function hasSqliteBusyCode(array $errorInfo): bool
    {
        foreach ($errorInfo as $part) {
            if ($part === 5 || $part === 6) {
                return true;
            }

            if (is_string($part) && in_array((int) $part, [5, 6], true)) {
                return true;
            }
        }

        return false;
    }

    private static function driverName(): ?string
    {
        try {
            return DB::connection()->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }
}
