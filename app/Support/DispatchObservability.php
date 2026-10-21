<?php

namespace App\Support;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\QueueManager;
use Throwable;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunActivityTask;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskTraceCarrier;

/** Observe an actual queue producer, independently of business task authority. */
final class DispatchObservability extends DatabaseQueue
{
    public static function install(): void
    {
        $callback = [self::class, 'payload'];
        if (! in_array($callback, self::$createPayloadCallbacks, true)) {
            self::createPayloadUsing($callback);
        }
    }

    public static function payload(mixed $connection, mixed $queue, array $payload): array
    {
        if (! WorkflowObservability::enabled()) {
            return [];
        }
        try {
            $job = $payload['data']['command'] ?? null;
            if ((! $job instanceof RunWorkflowTask && ! $job instanceof RunActivityTask && ! $job instanceof RunTimerTask)
                || ! is_string($job->taskId) || ! property_exists($job, 'dispatchObservabilityContext')) {
                return [];
            }
            $value = $job->dispatchObservabilityContext;
            $context = TaskTraceCarrier::fromJob($value, $job->taskId);

            return $context === null ? [] : ['dispatch_observability_context' => array_replace($value, ['context' => $context])];
        } catch (Throwable) {
            return [];
        }
    }

    /** Called only after the poller's original task/attempt/lease fences. */
    public static function forTask(WorkflowTask $task): ?array
    {
        if (! WorkflowObservability::enabled()) {
            return null;
        }
        try {
            $raw = $task->getRawOriginal('queue_delivery_reference');
            if (! is_string($raw) || strlen($raw) > 2048) {
                return null;
            }
            $reference = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $manager = app('queue');
            if ($manager::class !== QueueManager::class) {
                return null;
            }
            $queue = $manager->connection($task->connection);
            if (! $queue instanceof DatabaseQueue || ! is_array($reference) || ! self::matches($reference, $task, $queue)) {
                return null;
            }
            $row = $queue->database->table($queue->table)->useWritePdo()
                ->where('id', $reference['job_id'])->where('queue', $reference['queue'])->first(['payload']);
            if ($row === null || ! is_string($row->payload) || strlen($row->payload) > 65536) {
                return null;
            }
            $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
            $jobClass = match ($task->task_type) {
                TaskType::Workflow => RunWorkflowTask::class,
                TaskType::Activity => RunActivityTask::class,
                TaskType::Timer => RunTimerTask::class,
            };
            if (($payload['uuid'] ?? null) !== $reference['payload_uuid'] || ($payload['data']['commandName'] ?? null) !== $jobClass) {
                return null;
            }
            // The command remains an opaque serialized string. No job is unserialized.
            $value = $payload['dispatch_observability_context'] ?? null;
            $context = TaskTraceCarrier::fromJob($value, $task->id);

            return $context === null ? null : array_replace($value, ['context' => $context]);
        } catch (Throwable) {
            return null;
        }
    }

    private static function matches(array $reference, WorkflowTask $task, DatabaseQueue $queue): bool
    {
        return ($reference['version'] ?? null) === 1 && ($reference['task_id'] ?? null) === $task->id
            && ($reference['run_id'] ?? null) === $task->workflow_run_id && ($reference['namespace'] ?? null) === $task->namespace
            && ($reference['connection'] ?? null) === $queue->getConnectionName()
            && ($reference['queue'] ?? null) === $queue->getQueue($task->queue)
            && ($reference['database'] ?? null) === $queue->database->getName()
            && ($reference['table'] ?? null) === $queue->table
            && is_string($reference['payload_uuid'] ?? null) && $reference['payload_uuid'] !== '' && strlen($reference['payload_uuid']) <= 128
            && is_string($reference['job_id'] ?? null)
            && filter_var($reference['job_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            && preg_match('/^[1-9]\d{0,19}$/D', $reference['job_id']) === 1;
    }
}
