<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class WorkerPollBackpressure
{
    private const RETRY_AFTER_SECONDS = 1;

    public static function response(
        string $taskKind,
        string $namespace,
        string $taskQueue,
        LongPollCapacityExhaustedException $exception,
        ?string $workerRuntime = null,
    ): JsonResponse {
        // Workflow package releases predating poll backpressure treat any
        // non-2xx worker response as fatal. Preserve their existing bounded
        // idle-loop behavior while newer/non-PHP workers use HTTP-level
        // backpressure and their established error retry path.
        $phpCompatibility = strtolower(trim((string) $workerRuntime)) === 'php';
        $status = $phpCompatibility ? 200 : 429;

        return WorkerProtocol::json([
            'task' => null,
            'poll_status' => $phpCompatibility ? 'empty' : 'unavailable',
            'error' => 'Worker poll wait capacity is temporarily exhausted.',
            'reason' => 'long_poll_capacity_exhausted',
            'message' => 'Retry the poll after the advertised delay so idle workers do not starve health and control-plane requests.',
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'task_kind' => $taskKind,
            'wait_pool' => $exception->pool,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
        ], $status)->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }
}
