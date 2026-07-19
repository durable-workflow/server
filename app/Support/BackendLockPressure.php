<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

                if (is_array($errorInfo) && self::hasConcurrencyErrorCode($errorInfo)) {
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

    public static function controlPlaneResponse(Request $request, ?string $errorId = null): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => 'The database backend is temporarily locked while applying the control-plane operation. Retry with backoff.',
            'reason' => 'backend_lock_pressure',
            'retryable' => true,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'error_id' => $errorId,
            'backend' => [
                'driver' => self::workflowDriverName(),
                'lock_pressure' => true,
            ],
        ], 503)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    public static function workerRegistrationResponse(Request $request, string $workerId): JsonResponse
    {
        return WorkerProtocol::json([
            'message' => 'The database backend is temporarily locked while registering the worker. Retry with backoff.',
            'reason' => 'backend_lock_pressure',
            'operation' => 'register_worker',
            'worker_id' => $workerId,
            'task_queue' => $request->input('task_queue'),
            'registered' => false,
            'retryable' => true,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'backend' => [
                'driver' => self::workflowDriverName(),
                'lock_pressure' => true,
            ],
        ], 503)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    /**
     * Keep exhausted heartbeat pressure in the worker protocol's operation
     * outcome instead of turning it into a transport failure. Released workers
     * treat non-2xx acknowledgement failures as fatal, while renewed=false and
     * retryable=true let retry-aware callers back off without losing the lease
     * fencing fields that identify the attempted renewal.
     */
    public static function workflowTaskHeartbeatResponse(
        string $taskId,
        int $workflowTaskAttempt,
        string $leaseOwner,
    ): JsonResponse {
        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'lease_owner' => $leaseOwner,
            'renewed' => false,
            'lease_expires_at' => null,
            'run_status' => null,
            'task_status' => null,
            'reason' => 'backend_lock_pressure',
            'message' => 'The database backend is temporarily locked while renewing the workflow task lease. Retry the heartbeat with backoff.',
            'retryable' => true,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'backend' => [
                'driver' => self::workflowDriverName(),
                'lock_pressure' => true,
            ],
        ]);
    }

    /**
     * Activity heartbeat progress is advisory and safe to defer while SQLite
     * is locked. Returning the task fence in a successful worker-protocol
     * envelope keeps released synchronous workers alive so their subsequent
     * completion can retry after the writer clears.
     */
    public static function activityTaskHeartbeatResponse(
        string $taskId,
        string $activityAttemptId,
        string $leaseOwner,
    ): JsonResponse {
        return WorkerProtocol::json([
            'task_id' => $taskId,
            'activity_attempt_id' => $activityAttemptId,
            'lease_owner' => $leaseOwner,
            'cancel_requested' => false,
            'can_continue' => true,
            'heartbeat_recorded' => false,
            'lease_expires_at' => null,
            'last_heartbeat_at' => null,
            'reason' => 'backend_lock_pressure',
            'message' => 'The database backend is temporarily locked while recording activity progress. Retry the heartbeat with backoff.',
            'retryable' => true,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'backend' => [
                'driver' => self::workflowDriverName(),
                'lock_pressure' => true,
            ],
        ]);
    }

    /**
     * Worker liveness heartbeats are safe to acknowledge as deferred under
     * exhausted SQLite pressure. Existing managed workers treat a non-2xx
     * response as fatal, while the next scheduled heartbeat retries the same
     * idempotent registration refresh.
     */
    public static function workerHeartbeatResponse(Request $request): JsonResponse
    {
        return WorkerProtocol::json([
            'worker_id' => $request->input('worker_id'),
            'acknowledged' => false,
            'heartbeat_interval_seconds' => max(
                1,
                min(3600, (int) config('server.workers.heartbeat_interval_seconds', 10)),
            ),
            'reason' => 'backend_lock_pressure',
            'message' => 'The database backend is temporarily locked while refreshing worker liveness. Retry the heartbeat with backoff.',
            'retryable' => true,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'backend' => [
                'driver' => self::workflowDriverName(),
                'lock_pressure' => true,
            ],
        ]);
    }

    /**
     * Keep an unhandled backend write conflict inside the worker protocol.
     * Mutation endpoints remain fenced by their task/attempt identifiers, so
     * retry-aware clients can reconcile or repeat the request without
     * recording a second task outcome.
     */
    public static function workerOperationResponse(Request $request, ?bool $recorded = null): JsonResponse
    {
        return WorkerProtocol::json(array_filter([
            'message' => 'The database backend is temporarily locked while applying the worker operation. Retry with backoff.',
            'reason' => 'backend_lock_pressure',
            'operation' => self::workerOperation($request),
            'task_id' => $request->route('taskId'),
            'query_task_id' => $request->route('queryTaskId'),
            'workflow_task_attempt' => $request->input('workflow_task_attempt'),
            'query_task_attempt' => $request->input('query_task_attempt'),
            'activity_attempt_id' => $request->input('activity_attempt_id'),
            'lease_owner' => $request->input('lease_owner'),
            'outcome' => 'deferred',
            'recorded' => $recorded,
            'retryable' => true,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
            'backend' => [
                'driver' => self::workflowDriverName(),
                'lock_pressure' => true,
            ],
        ], static fn (mixed $value): bool => $value !== null), 503)
            ->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    public static function isSqliteBackend(): bool
    {
        return self::workflowDriverName() === 'sqlite';
    }

    /**
     * @param  array<int, mixed>  $errorInfo
     */
    private static function hasConcurrencyErrorCode(array $errorInfo): bool
    {
        foreach ($errorInfo as $part) {
            if (in_array($part, [5, 6, 1205, 1213, '40001', '40P01', '55P03'], true)) {
                return true;
            }

            if (is_string($part) && in_array((int) $part, [5, 6, 1205, 1213], true)) {
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

    private static function workflowDriverName(): ?string
    {
        try {
            $connection = config('workflows.storage.connection');

            return DB::connection(is_string($connection) && $connection !== '' ? $connection : null)
                ->getDriverName();
        } catch (Throwable) {
            return null;
        }
    }

    private static function workerOperation(Request $request): string
    {
        $action = $request->route()?->getActionMethod();

        if (is_string($action) && $action !== '') {
            return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $action));
        }

        return 'worker_request';
    }
}
