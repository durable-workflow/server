<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;
use RedisException;
use Throwable;

final class BackendUnavailable
{
    public static function is(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RedisException && self::isRedisConnectionFailure($current)) {
                return true;
            }

            if (! $current instanceof PDOException) {
                continue;
            }

            // PDO can replace the original driver error while rolling back a
            // nested transaction after connection loss. That exception has no
            // errorInfo; accept only the exact driver-generated 2006 message.
            if (! is_array($current->errorInfo)) {
                if ($current->getMessage() === 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away') {
                    return true;
                }

                continue;
            }

            [$state, $code] = $current->errorInfo + [null, null];

            // Use driver fields, never SQL text or customer-controlled bindings.
            if (($state === 'HY000' && in_array((int) $code, [2002, 2003, 2006, 2013, 2055], true))
                || in_array($state, ['08000', '08001', '08003', '08006', '08007', '08S01', '57P01', '57P02', '57P03'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function workerResponse(Request $request): ?JsonResponse
    {
        if (! WorkerProtocol::isWorkerPlaneRequest($request)
            || ! WorkerProtocol::requestUsesCompatibleProtocolVersion($request)) {
            return null;
        }

        $operation = match (true) {
            $request->is('api/worker/workflow-tasks/poll') => 'poll_workflow_task',
            $request->is('api/worker/activity-tasks/poll') => 'poll_activity_task',
            $request->is('api/worker/query-tasks/poll') => 'poll_query_task',
            $request->is('api/worker/update-validation-tasks/poll') => 'poll_update_validation_task',
            $request->is('api/worker/register') => 'register_worker',
            $request->is('api/worker/heartbeat') => 'heartbeat_worker',
            $request->is('api/worker/workflow-tasks/*/heartbeat') => 'heartbeat_workflow_task',
            $request->is('api/worker/workflow-tasks/*/complete') => 'complete_workflow_task',
            default => null,
        };
        if ($operation === null) {
            return null;
        }

        $fencedWorkflowTask = in_array($operation, ['heartbeat_workflow_task', 'complete_workflow_task'], true);
        $taskId = $fencedWorkflowTask ? self::identity($request->route('taskId')) : null;
        $leaseOwner = $fencedWorkflowTask ? self::identity($request->input('lease_owner')) : null;
        $taskAttempt = $request->input('workflow_task_attempt');
        $taskAttempt = $fencedWorkflowTask && is_int($taskAttempt) && $taskAttempt > 0 ? $taskAttempt : null;
        $workerId = $fencedWorkflowTask ? $leaseOwner : self::identity($request->input('worker_id'));
        $taskQueue = self::identity($request->input('task_queue'));
        $pollId = self::identity($request->input('poll_request_id'));
        $isPoll = str_starts_with($operation, 'poll_');
        $payload = [
            'reason' => 'backend_unavailable',
            'message' => 'A required backend is temporarily unavailable. Retry the same request with backoff; its outcome may be unknown.',
            'operation' => $operation,
            'outcome' => 'unknown',
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'retryable' => $fencedWorkflowTask
                ? $taskId !== null && $leaseOwner !== null && $taskAttempt !== null
                : $workerId !== null
                    && ($operation === 'heartbeat_worker' || $taskQueue !== null)
                    && (! $isPoll || $pollId !== null),
            'retry_after_seconds' => 1,
        ];
        if ($isPoll) {
            // A lost connection can follow COMMIT. Reusing this ID lets native
            // lease bindings reconcile it; task=null is not proof of no lease.
            $payload += [
                'task' => null,
                'poll_status' => 'backend_unavailable',
                'poll_request_id' => $pollId,
                'retry_same_poll_request_id' => true,
            ];
        }
        if ($fencedWorkflowTask) {
            $payload += [
                'task_id' => $taskId,
                'lease_owner' => $leaseOwner,
                'workflow_task_attempt' => $taskAttempt,
            ];
        }

        return WorkerProtocol::json($payload, 503)->header('Retry-After', '1');
    }

    private static function identity(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 255 ? $value : null;
    }

    private static function isRedisConnectionFailure(RedisException $exception): bool
    {
        // phpredis has no structured transport error code; only match its
        // connection failures, not authentication, command, or memory errors.
        return preg_match(
            '/(?:getaddrinfo|failed to connect|connection (?:refused|reset|lost|timed out)|read error on connection|redis server(?:\s+\S+)?\s+went away|socket error|no route to host)/i',
            $exception->getMessage(),
        ) === 1;
    }
}
