<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowQueryTaskBroker
{
    private const CACHE_PREFIX = 'server:workflow-query-task:';

    public function __construct(
        private readonly ServerPollingCache $cache,
        private readonly LongPoller $longPoller,
        private readonly LongPollSignalStore $signals,
    ) {}

    public function hasWorkerFor(string $namespace, WorkflowRun $run): bool
    {
        return $this->queryWorkers($namespace, $run)->isNotEmpty();
    }

    /**
     * @param  array{codec: string, blob: string}  $queryArguments
     * @return array<string, mixed>
     */
    public function query(
        string $namespace,
        WorkflowRun $run,
        string $queryName,
        array $queryArguments,
    ): array {
        if (! $this->hasWorkerFor($namespace, $run)) {
            return $this->queryFailed(
                $run,
                $queryName,
                'query_worker_unavailable',
                sprintf(
                    'No active non-PHP worker is registered for workflow type [%s] on task queue [%s].',
                    $run->workflow_type,
                    $this->taskQueue($run),
                ),
                409,
            );
        }

        try {
            $task = $this->enqueue($namespace, $run, $queryName, $queryArguments);
        } catch (QueryTaskQueueFullException $exception) {
            return $this->queryFailed(
                $run,
                $queryName,
                'query_task_queue_full',
                $exception->getMessage(),
                429,
            );
        } catch (QueryTaskQueueUnavailableException $exception) {
            return $this->queryFailed(
                $run,
                $queryName,
                'query_task_queue_unavailable',
                $exception->getMessage(),
                503,
            );
        }

        $result = $this->waitForResult((string) $task['query_task_id']);

        if (($result['status'] ?? null) === 'completed') {
            return [
                'success' => true,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_id' => $run->workflow_instance_id,
                'run_id' => $run->id,
                'target_scope' => 'instance',
                'query_name' => $queryName,
                'result' => $result['result'] ?? null,
                'result_envelope' => $result['result_envelope'] ?? null,
                'reason' => null,
                'status' => 200,
            ];
        }

        if (($result['status'] ?? null) === 'failed') {
            return $this->queryFailed(
                $run,
                $queryName,
                $this->stringValue($result['reason'] ?? null) ?? 'query_rejected',
                $this->stringValue($result['message'] ?? null) ?? 'Query failed on the worker.',
                (int) ($result['http_status'] ?? 409),
            );
        }

        $this->markTimedOut((string) $task['query_task_id']);

        return $this->queryFailed(
            $run,
            $queryName,
            'query_worker_timeout',
            'Timed out waiting for a worker to execute the workflow query.',
            504,
        );
    }

    private function markTimedOut(string $queryTaskId): void
    {
        $task = $this->task($queryTaskId);

        if (! is_array($task)) {
            return;
        }

        if (in_array($task['status'] ?? null, ['completed', 'failed'], true)) {
            return;
        }

        $task['status'] = 'timed_out';
        $task['timed_out_at'] = now()->toJSON();

        $this->putTask($task);
        $this->store()->forget($this->leaseKey($queryTaskId));
        $this->signals->signalQueryTaskResult($queryTaskId);
    }

    /**
     * @param  array{codec: string, blob: string}  $queryArguments
     * @return array<string, mixed>
     */
    public function enqueue(
        string $namespace,
        WorkflowRun $run,
        string $queryName,
        array $queryArguments,
    ): array {
        $queryTaskId = Str::ulid()->toBase32();
        $taskQueue = $this->taskQueue($run);
        $task = [
            'query_task_id' => $queryTaskId,
            'status' => 'pending',
            'namespace' => $namespace,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
            'task_queue' => $taskQueue,
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'query_name' => $queryName,
            'query_arguments' => $queryArguments,
            'attempt_count' => 0,
            'created_at' => now()->toJSON(),
        ];

        $this->putTask($task);

        try {
            $this->appendPendingTask($namespace, $taskQueue, $queryTaskId);
        } catch (QueryTaskQueueFullException|QueryTaskQueueUnavailableException $exception) {
            $this->store()->forget($this->taskKey($queryTaskId));

            throw $exception;
        }

        $this->signals->signalQueryTaskQueue($namespace, $taskQueue);

        return $task;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function poll(
        string $namespace,
        WorkerRegistration $worker,
    ): ?array {
        $taskQueue = (string) $worker->task_queue;
        $supportedWorkflowTypes = $this->stringArray($worker->supported_workflow_types);

        return $this->longPoller->until(
            fn (): ?array => $this->claimNext($namespace, $taskQueue, $worker->worker_id, $supportedWorkflowTypes),
            static fn (?array $task): bool => $task !== null,
            null,
            null,
            $this->signals->queryTaskPollChannels($namespace, $taskQueue),
        );
    }

    /**
     * @param  array{codec: string, blob: string}|null  $resultEnvelope
     * @return array<string, mixed>
     */
    public function complete(
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
        mixed $result,
        ?array $resultEnvelope,
    ): array {
        $task = $this->task($queryTaskId);
        $guard = $this->guardLease($task, $namespace, $queryTaskId, $leaseOwner, $queryTaskAttempt);

        if ($guard !== null) {
            return $guard;
        }

        $task['status'] = 'completed';
        $task['result'] = $result;
        $task['result_envelope'] = $resultEnvelope;
        $task['completed_at'] = now()->toJSON();

        $this->putTask($task);
        $this->signals->signalQueryTaskResult($queryTaskId);

        return [
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $queryTaskAttempt,
            'outcome' => 'completed',
            'reason' => null,
            'status' => 200,
        ];
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array<string, mixed>
     */
    public function fail(
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
        array $failure,
    ): array {
        $task = $this->task($queryTaskId);
        $guard = $this->guardLease($task, $namespace, $queryTaskId, $leaseOwner, $queryTaskAttempt);

        if ($guard !== null) {
            return $guard;
        }

        $reason = $this->stringValue($failure['reason'] ?? null) ?? 'query_rejected';
        $task['status'] = 'failed';
        $task['reason'] = $reason;
        $task['message'] = $this->stringValue($failure['message'] ?? null) ?? 'Query failed on the worker.';
        $task['failure_type'] = $this->stringValue($failure['type'] ?? null);
        $task['http_status'] = $reason === 'rejected_unknown_query' ? 404 : 409;
        $task['failed_at'] = now()->toJSON();

        $this->putTask($task);
        $this->signals->signalQueryTaskResult($queryTaskId);

        return [
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $queryTaskAttempt,
            'outcome' => 'failed',
            'reason' => $reason,
            'status' => 200,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function task(string $queryTaskId): ?array
    {
        $task = $this->store()->get($this->taskKey($queryTaskId));

        return is_array($task) ? $task : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function waitForResult(string $queryTaskId): ?array
    {
        return $this->longPoller->until(
            fn (): ?array => $this->task($queryTaskId),
            static function (?array $task): bool {
                $status = $task['status'] ?? null;

                return $status === 'completed' || $status === 'failed';
            },
            $this->queryTimeoutSeconds(),
            null,
            [$this->signals->queryTaskResultChannel($queryTaskId)],
        );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array<string, mixed>|null
     */
    private function claimNext(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
    ): ?array {
        $task = $this->withQueueLock(
            $namespace,
            $taskQueue,
            fn (): ?array => $this->claimNextPendingTask($namespace, $taskQueue, $leaseOwner, $supportedWorkflowTypes),
        );

        return is_array($task) ? $this->queryTaskPayload($task) : null;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array<string, mixed>|null
     */
    private function claimNextPendingTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
    ): ?array {
        $ids = $this->pendingTaskIds($namespace, $taskQueue);
        $remaining = [];

        foreach ($ids as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if (! $this->matchesWorkflowType($supportedWorkflowTypes, $task['workflow_type'] ?? null)) {
                $remaining[] = $queryTaskId;

                continue;
            }

            if (! $this->store()->add($this->leaseKey($queryTaskId), $leaseOwner, now()->addSeconds($this->leaseTtlSeconds()))) {
                $remaining[] = $queryTaskId;

                continue;
            }

            $attempt = ((int) ($task['attempt_count'] ?? 0)) + 1;
            $task['status'] = 'leased';
            $task['lease_owner'] = $leaseOwner;
            $task['lease_expires_at'] = now()->addSeconds($this->leaseTtlSeconds())->toJSON();
            $task['attempt_count'] = $attempt;
            $task['leased_at'] = now()->toJSON();

            $this->putTask($task);
            $this->storePendingTaskIds(
                $namespace,
                $taskQueue,
                array_values(array_filter(
                    $ids,
                    static fn (string $id): bool => $id !== $queryTaskId,
                )),
            );

            return $task;
        }

        $this->storePendingTaskIds($namespace, $taskQueue, $remaining);

        return null;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function queryTaskPayload(array $task): array
    {
        $run = WorkflowRun::query()->find($task['run_id'] ?? null);

        return [
            'query_task_id' => $task['query_task_id'],
            'query_task_attempt' => (int) ($task['attempt_count'] ?? 1),
            'workflow_id' => $task['workflow_id'],
            'run_id' => $task['run_id'],
            'workflow_type' => $task['workflow_type'],
            'query_name' => $task['query_name'],
            'payload_codec' => $task['payload_codec'],
            'workflow_arguments' => $run instanceof WorkflowRun && is_string($run->arguments)
                ? ['codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(), 'blob' => $run->arguments]
                : null,
            'query_arguments' => $task['query_arguments'] ?? ['codec' => CodecRegistry::defaultCodec(), 'blob' => null],
            'run_status' => $run?->status?->value,
            'last_history_sequence' => (int) ($run?->last_history_sequence ?? 0),
            'history_events' => $run instanceof WorkflowRun ? $this->historyEvents($run) : [],
            'task_queue' => $task['task_queue'],
            'lease_owner' => $task['lease_owner'] ?? null,
            'lease_expires_at' => $task['lease_expires_at'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyEvents(WorkflowRun $run): array
    {
        return WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (WorkflowHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_task_id' => $event->workflow_task_id,
                'workflow_command_id' => $event->workflow_command_id,
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @return array<string, mixed>|null
     */
    private function guardLease(
        ?array $task,
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
    ): ?array {
        if (! is_array($task) || ($task['namespace'] ?? null) !== $namespace) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_not_found',
                'error' => 'Query task not found.',
                'status' => 404,
            ];
        }

        if (($task['status'] ?? null) !== 'leased') {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_not_leased',
                'error' => 'Query task is not currently leased.',
                'status' => 409,
            ];
        }

        if (($task['lease_owner'] ?? null) !== $leaseOwner) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'lease_owner_mismatch',
                'error' => 'Query task lease is owned by another worker.',
                'lease_owner' => $task['lease_owner'] ?? null,
                'status' => 409,
            ];
        }

        if ((int) ($task['attempt_count'] ?? 0) !== $queryTaskAttempt) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_attempt_mismatch',
                'error' => 'Query task lease attempt does not match the current claim.',
                'current_attempt' => (int) ($task['attempt_count'] ?? 0),
                'status' => 409,
            ];
        }

        $leaseExpiresAt = $this->timestamp($task['lease_expires_at'] ?? null);

        if ($leaseExpiresAt instanceof Carbon && $leaseExpiresAt->lte(now())) {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'lease_expired',
                'error' => 'Query task lease has expired.',
                'lease_expires_at' => $leaseExpiresAt->toJSON(),
                'status' => 409,
            ];
        }

        return null;
    }

    /**
     * @return Collection<int, WorkerRegistration>
     */
    private function queryWorkers(string $namespace, WorkflowRun $run)
    {
        return WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $this->taskQueue($run))
            ->where('status', 'active')
            ->where('runtime', '!=', 'php')
            ->get()
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerIsFresh($worker))
            ->filter(fn (WorkerRegistration $worker): bool => $this->matchesWorkflowType(
                $this->stringArray($worker->supported_workflow_types),
                $run->workflow_type,
            ))
            ->values();
    }

    private function workerIsFresh(WorkerRegistration $worker): bool
    {
        $heartbeat = $worker->last_heartbeat_at;

        if (! $heartbeat instanceof \DateTimeInterface) {
            return false;
        }

        return Carbon::instance($heartbeat)->gt(now()->subSeconds($this->staleAfterSeconds()));
    }

    /**
     * @param  list<string>  $supportedTypes
     */
    private function matchesWorkflowType(array $supportedTypes, mixed $workflowType): bool
    {
        if ($supportedTypes === []) {
            return true;
        }

        return is_string($workflowType) && in_array($workflowType, $supportedTypes, true);
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function putTask(array $task): void
    {
        $this->store()->put($this->taskKey((string) $task['query_task_id']), $task, now()->addSeconds($this->taskTtlSeconds()));
    }

    private function appendPendingTask(string $namespace, string $taskQueue, string $queryTaskId): void
    {
        $this->withQueueLock($namespace, $taskQueue, function () use ($namespace, $taskQueue, $queryTaskId): void {
            $ids = $this->pendingTaskIds($namespace, $taskQueue);

            if (! in_array($queryTaskId, $ids, true) && count($ids) >= $this->maxPendingPerQueue()) {
                throw new QueryTaskQueueFullException($namespace, $taskQueue, $this->maxPendingPerQueue());
            }

            $ids[] = $queryTaskId;

            $this->storePendingTaskIds($namespace, $taskQueue, array_values(array_unique($ids)));
        });
    }

    /**
     * @return list<string>
     */
    private function pendingTaskIds(string $namespace, string $taskQueue): array
    {
        $ids = $this->stringArray($this->store()->get($this->queueKey($namespace, $taskQueue)));
        $pending = [];

        foreach ($ids as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (is_array($task) && ($task['status'] ?? null) === 'pending') {
                $pending[] = $queryTaskId;
            }
        }

        if ($pending !== $ids) {
            $this->storePendingTaskIds($namespace, $taskQueue, $pending);
        }

        return $pending;
    }

    /**
     * @param  list<string>  $ids
     */
    private function storePendingTaskIds(string $namespace, string $taskQueue, array $ids): void
    {
        $this->store()->put($this->queueKey($namespace, $taskQueue), array_values($ids), now()->addSeconds($this->taskTtlSeconds()));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withQueueLock(string $namespace, string $taskQueue, Closure $callback): mixed
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new QueryTaskQueueUnavailableException($namespace, $taskQueue, 'The configured polling cache store does not support atomic locks.');
        }

        try {
            return $store
                ->lock($this->queueLockKey($namespace, $taskQueue), $this->queueLockTtlSeconds())
                ->block($this->queueLockWaitSeconds(), $callback);
        } catch (LockTimeoutException $exception) {
            throw new QueryTaskQueueUnavailableException($namespace, $taskQueue, 'Timed out waiting for the query task queue lock.', $exception);
        }
    }

    private function queryFailed(
        WorkflowRun $run,
        string $queryName,
        string $reason,
        string $message,
        int $status,
    ): array {
        return [
            'success' => false,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'target_scope' => 'instance',
            'query_name' => $queryName,
            'result' => null,
            'reason' => $reason,
            'message' => $message,
            'status' => $status,
        ];
    }

    private function taskQueue(WorkflowRun $run): string
    {
        return $this->stringValue($run->queue) ?? 'default';
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item): ?string => $this->stringValue($item), $value),
            static fn (?string $item): bool => $item !== null,
        ));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function queryTimeoutSeconds(): int
    {
        return max(0, (int) config('server.query_tasks.timeout', config('server.polling.timeout', 30)));
    }

    private function leaseTtlSeconds(): int
    {
        return max(1, (int) config('server.query_tasks.lease_timeout', config('server.lease.workflow_task_timeout', 60)));
    }

    private function taskTtlSeconds(): int
    {
        return max(60, (int) config('server.query_tasks.ttl_seconds', $this->queryTimeoutSeconds() + $this->leaseTtlSeconds() + 60));
    }

    private function maxPendingPerQueue(): int
    {
        return max(1, min(10000, (int) config('server.query_tasks.max_pending_per_queue', 1024)));
    }

    private function queueLockTtlSeconds(): int
    {
        return 10;
    }

    private function queueLockWaitSeconds(): int
    {
        return 5;
    }

    private function staleAfterSeconds(): int
    {
        return max(1, (int) config('server.workers.stale_after_seconds', 60));
    }

    private function taskKey(string $queryTaskId): string
    {
        return self::CACHE_PREFIX.'task:'.$queryTaskId;
    }

    private function leaseKey(string $queryTaskId): string
    {
        return self::CACHE_PREFIX.'lease:'.$queryTaskId;
    }

    private function queueKey(string $namespace, string $taskQueue): string
    {
        return self::CACHE_PREFIX.'queue:'.sha1($namespace.'|'.$taskQueue);
    }

    private function queueLockKey(string $namespace, string $taskQueue): string
    {
        return self::CACHE_PREFIX.'queue-lock:'.sha1($namespace.'|'.$taskQueue);
    }

    private function store(): CacheRepository
    {
        return $this->cache->store();
    }
}
