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
    ): JsonResponse {
        // Published workers from every supported runtime predate HTTP-level
        // poll backpressure and may treat a non-2xx response as terminal. They
        // also do not all inspect Retry-After on a successful empty poll, so
        // hold the compatibility response for the advertised cooldown. This
        // bounds immediate repolling without admitting another full long wait.
        usleep(self::RETRY_AFTER_SECONDS * 1_000_000);

        return WorkerProtocol::json([
            'task' => null,
            'poll_status' => 'empty',
            'error' => 'Worker poll wait capacity is temporarily exhausted.',
            'reason' => 'long_poll_capacity_exhausted',
            'message' => 'Retry the poll after the advertised delay so idle workers do not starve health and control-plane requests.',
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'task_kind' => $taskKind,
            'wait_pool' => $exception->pool,
            'retry_after_seconds' => self::RETRY_AFTER_SECONDS,
        ])->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }
}
