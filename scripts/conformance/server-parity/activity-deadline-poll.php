<?php

declare(strict_types=1);

use DurableWorkflow\Exception\ServerException;

// This fixture observes expiry through ordinary polls. Pressure must not grant
// another observer budget or another poll identity while a claim is uncertain.
function activityDeadlinePoll(Closure $poll, string $worker, string $queue, string $kind,
    float $deadline, array &$retries): ?array
{
    $requestId = 'php-'.$kind.'-poll-'.bin2hex(random_bytes(16));
    while (true) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Original activity deadline observation expired');
        }
        try {
            $response = $poll($requestId);
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Original activity deadline observation expired');
            }
            return isset($response['task']) && is_array($response['task']) ? $response['task'] : null;
        } catch (ServerException $exception) {
            $details = $exception->details;
            if ($exception->status !== 503 || $exception->reason !== 'backend_lock_pressure'
                || !is_array($details) || array_is_list($details)
                || !array_key_exists('task', $details) || $details['task'] !== null
                || ($details['reason'] ?? null) !== 'backend_lock_pressure'
                || ($details['poll_status'] ?? null) !== 'backend_lock_pressure'
                || ($details['namespace'] ?? null) !== 'default'
                || ($details['task_queue'] ?? null) !== $queue
                || ($details['task_kind'] ?? null) !== $kind.'_task'
                || ($details['backend']['lock_pressure'] ?? null) !== true
                || !is_int($details['retry_after_seconds'] ?? null)
                || $details['retry_after_seconds'] <= 0) {
                throw $exception;
            }
            $failedAt = microtime(true);
            $delay = $details['retry_after_seconds'];
            if ($delay >= $deadline - $failedAt) {
                throw $exception;
            }
            usleep($delay * 1000000);
            $retryAt = microtime(true);
            if ($retryAt >= $deadline) {
                throw $exception;
            }
            $retries[] = ['poll_request_id'=>$requestId, 'worker_id'=>$worker,
                'task_queue'=>$queue, 'task_kind'=>$kind.'_task', 'namespace'=>'default',
                'reason'=>'backend_lock_pressure', 'retry_after_seconds'=>$delay,
                'failed_at'=>$failedAt, 'retry_at'=>$retryAt, 'observer_deadline'=>$deadline];
        }
    }
}
