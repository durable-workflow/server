<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\CommandContext;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\HistoryExport;

final class WorkflowQueryTaskBroker
{
    private const CACHE_PREFIX = 'server:workflow-query-task:';
    private const QUERY_TASKS_CAPABILITY = 'query_tasks';

    public function __construct(
        private readonly ServerPollingCache $cache,
        private readonly LongPoller $longPoller,
        private readonly LongPollSignalStore $signals,
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
        private readonly QueryTaskPollRequestStore $pollRequests,
    ) {}

    public function hasWorkerFor(string $namespace, WorkflowRun $run): bool
    {
        return $this->queryRoute($namespace, $run)['servable'];
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
        ?CommandContext $commandContext = null,
    ): array {
        if ($run->status->isTerminal() && $run->status !== RunStatus::Completed) {
            return $this->queryFailed(
                $run,
                $queryName,
                'run_not_active',
                sprintf(
                    'Workflow query [%s] cannot execute because run [%s] is terminal with status [%s].',
                    $queryName,
                    $run->id,
                    $run->status->value,
                ),
                409,
                extra: [
                    'run_status' => $run->status->value,
                    'is_terminal' => true,
                ],
            );
        }

        $route = $this->queryRoute($namespace, $run);

        if (! $route['servable']) {
            return $this->queryFailed(
                $run,
                $queryName,
                $route['reason'] ?? 'query_worker_unavailable',
                $route['message'] ?? sprintf(
                    'No compatible query-capable worker is available for workflow type [%s] on task queue [%s].',
                    $this->stringValue($run->workflow_type) ?? 'unknown',
                    $this->taskQueue($run),
                ),
                409,
            );
        }

        try {
            $task = $this->enqueue($namespace, $run, $queryName, $queryArguments, $commandContext);
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
            $resultEnvelope = is_array($result['result_envelope'] ?? null)
                ? $this->resultEnvelope($namespace, $result['result_envelope'])
                : null;

            $payload = [
                'success' => true,
                'workflow_instance_id' => $run->workflow_instance_id,
                'workflow_id' => $run->workflow_instance_id,
                'run_id' => $run->id,
                'target_scope' => 'instance',
                'query_name' => $queryName,
                'result' => $result['result'] ?? null,
                'result_envelope' => $resultEnvelope,
                'reason' => null,
                'status' => 200,
            ];

            $principal = $this->taskPrincipal($task);
            if ($principal !== null) {
                $payload['principal'] = $principal;
            }

            return $payload;
        }

        if (($result['status'] ?? null) === 'failed') {
            return $this->queryFailed(
                $run,
                $queryName,
                $this->stringValue($result['reason'] ?? null) ?? 'query_rejected',
                $this->stringValue($result['message'] ?? null) ?? 'Query failed on the worker.',
                (int) ($result['http_status'] ?? 409),
                $this->validationErrors($result['validation_errors'] ?? null),
            );
        }

        $timeoutTask = is_array($result) ? $result : $task;
        $timeout = $this->timeoutFailure($namespace, $run, $queryName, $timeoutTask);

        $this->markTimedOut((string) $task['query_task_id']);

        return $this->queryFailed(
            $run,
            $queryName,
            $timeout['reason'],
            $timeout['message'],
            $timeout['status'],
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
        ?CommandContext $commandContext = null,
    ): array {
        $queryTaskId = Str::ulid()->toBase32();
        $taskQueue = $this->taskQueue($run);
        $commandContextAttributes = $commandContext?->attributes();
        $principal = $this->commandContextPrincipal($commandContextAttributes);
        $task = [
            'query_task_id' => $queryTaskId,
            'status' => 'pending',
            'namespace' => $namespace,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
            'workflow_definition_fingerprint' => $this->recordedWorkflowDefinitionFingerprint($run),
            'task_queue' => $taskQueue,
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'query_name' => $queryName,
            'query_arguments' => $queryArguments,
            'attempt_count' => 0,
            'created_at' => now()->toJSON(),
        ];

        if ($commandContextAttributes !== null) {
            $task['command_context'] = $commandContextAttributes;
        }

        if ($principal !== null) {
            $task['principal'] = $principal;
        }

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
        ?string $pollRequestId = null,
    ): ?array {
        $pollRequestId = $this->stringValue($pollRequestId);
        $taskQueue = (string) $worker->task_queue;
        $buildId = $this->stringValue($worker->build_id);
        $supportedWorkflowTypes = $this->stringArray($worker->supported_workflow_types);
        $workflowDefinitionFingerprints = $this->fingerprintMap($worker->workflow_definition_fingerprints);

        $this->rememberQueryTaskPollingWorker($namespace, $taskQueue, $worker->worker_id);

        if ($pollRequestId !== null) {
            $this->pollRequests->markCurrentIfFresh($namespace, $taskQueue, $buildId, $worker->worker_id, $pollRequestId);

            return $this->coordinatedPoll(
                $namespace,
                $taskQueue,
                $buildId,
                $worker->worker_id,
                $pollRequestId,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
            );
        }

        return $this->performPoll(
            $namespace,
            $taskQueue,
            $worker->worker_id,
            $supportedWorkflowTypes,
            $workflowDefinitionFingerprints,
        );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function coordinatedPoll(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
    ): ?array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $cached = $this->cachedPollResult($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

            if ($cached['resolved']) {
                return $cached['task'];
            }

            if ($this->pollRequests->tryStart($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)) {
                return $this->runCoordinatedPollLeader(
                    $namespace,
                    $taskQueue,
                    $buildId,
                    $leaseOwner,
                    $pollRequestId,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                );
            }

            $observed = $this->pollRequests->waitForResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            );

            if ($observed['resolved']) {
                return $observed['task'];
            }
        }

        return $this->cachedPollResult($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)['task'];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function runCoordinatedPollLeader(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
    ): ?array {
        try {
            $task = $this->performPoll(
                $namespace,
                $taskQueue,
                $leaseOwner,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                $pollRequestId,
            );
        } catch (\Throwable $exception) {
            $this->pollRequests->forgetPending($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

            throw $exception;
        }

        $this->pollRequests->rememberResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            $task,
            is_array($task) ? 'leased' : 'empty',
        );

        return $task;
    }

    /**
     * @return array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}
     */
    private function cachedPollResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): array {
        $cached = $this->pollRequests->result($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId);

        if (! $cached['resolved']) {
            return $cached;
        }

        if ($this->cachedTaskStillDeliverable($namespace, $taskQueue, $leaseOwner, $cached['task'])) {
            return $cached;
        }

        $this->pollRequests->rememberResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            null,
            'empty',
        );

        return [
            'resolved' => true,
            'task' => null,
            'poll_status' => 'empty',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function cachedTaskStillDeliverable(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?array $task,
    ): bool {
        if ($task === null) {
            return true;
        }

        $queryTaskId = $this->stringValue($task['query_task_id'] ?? null);

        if ($queryTaskId === null) {
            return false;
        }

        if (($task['task_queue'] ?? null) !== $taskQueue || ($task['lease_owner'] ?? null) !== $leaseOwner) {
            return false;
        }

        $current = $this->task($queryTaskId);

        if (! is_array($current) || ($current['status'] ?? null) !== 'leased') {
            return false;
        }

        if (
            ($current['namespace'] ?? null) !== $namespace
            || ($current['task_queue'] ?? null) !== $taskQueue
            || ($current['lease_owner'] ?? null) !== $leaseOwner
        ) {
            return false;
        }

        $leaseExpiresAt = $this->timestamp($current['lease_expires_at'] ?? null);

        if (! $leaseExpiresAt instanceof Carbon || $leaseExpiresAt->lte(now())) {
            return false;
        }

        return (int) ($current['attempt_count'] ?? 0) === (int) ($task['query_task_attempt'] ?? 0);
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function performPoll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
    ): ?array {
        $result = $this->longPoller->until(
            function () use ($namespace, $taskQueue, $leaseOwner, $supportedWorkflowTypes, $workflowDefinitionFingerprints, $buildId, $pollRequestId): ?array {
                if (
                    $pollRequestId !== null
                    && ! $this->pollRequests->isCurrent($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)
                ) {
                    return ['poll_status' => 'superseded'];
                }

                return $this->claimNext(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                    $buildId,
                    $pollRequestId,
                );
            },
            static fn (?array $task): bool => $task !== null,
            wakeChannels: $this->signals->queryTaskPollChannels($namespace, $taskQueue),
            reserveWorkerWaitSlot: true,
            waitSlotPool: 'query-task',
        );

        return ($result['poll_status'] ?? null) === 'superseded' ? null : $result;
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
     * @return array<string, mixed>|null
     */
    public function guardCompletion(
        string $namespace,
        string $queryTaskId,
        string $leaseOwner,
        int $queryTaskAttempt,
    ): ?array {
        return $this->guardLease(
            $this->task($queryTaskId),
            $namespace,
            $queryTaskId,
            $leaseOwner,
            $queryTaskAttempt,
        );
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
        $validationErrors = $this->validationErrors($failure['validation_errors'] ?? null);
        $task['status'] = 'failed';
        $task['reason'] = $reason;
        $task['message'] = $this->stringValue($failure['message'] ?? null) ?? 'Query failed on the worker.';
        $task['failure_type'] = $this->stringValue($failure['type'] ?? null);
        $task['validation_errors'] = $validationErrors;
        $task['http_status'] = match ($reason) {
            'rejected_unknown_query' => 404,
            'invalid_query_arguments' => 422,
            default => 409,
        };
        $task['failed_at'] = now()->toJSON();

        $this->putTask($task);
        $this->signals->signalQueryTaskResult($queryTaskId);

        return [
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $queryTaskAttempt,
            'outcome' => 'failed',
            'reason' => $reason,
            'validation_errors' => $validationErrors === [] ? null : $validationErrors,
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
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    public function queryRoute(string $namespace, WorkflowRun $run): array
    {
        $taskQueue = $this->taskQueue($run);
        $workflowType = $this->stringValue($run->workflow_type);
        $recordedFingerprint = $this->recordedWorkflowDefinitionFingerprint($run);

        $activeWorkers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('status', 'active')
            ->get()
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerIsFresh($worker))
            ->values();

        if ($activeWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_unavailable',
                sprintf('No active worker is registered on task queue [%s].', $taskQueue),
                $taskQueue,
                0,
                0,
                0,
                0,
            );
        }

        $queryWorkers = $activeWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerAcceptsQueryTasks($namespace, $worker))
            ->values();

        if ($queryWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_unavailable',
                sprintf(
                    'Active workers are registered on task queue [%s], but none are accepting workflow query tasks.',
                    $taskQueue,
                ),
                $taskQueue,
                $activeWorkers->count(),
                0,
                0,
                0,
            );
        }

        $typeWorkers = $queryWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->matchesWorkflowType(
                $this->stringArray($worker->supported_workflow_types),
                $workflowType,
            ))
            ->values();

        if ($typeWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_incompatible',
                sprintf(
                    'Query-capable workers on task queue [%s] do not advertise workflow type [%s].',
                    $taskQueue,
                    $workflowType ?? 'unknown',
                ),
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                0,
                0,
            );
        }

        $compatibleWorkers = $typeWorkers
            ->filter(fn (WorkerRegistration $worker): bool => $this->matchesWorkflowDefinitionFingerprint(
                $this->fingerprintMap($worker->workflow_definition_fingerprints),
                $workflowType,
                $recordedFingerprint,
            ))
            ->values();

        if ($compatibleWorkers->isEmpty()) {
            return $this->queryRouteResult(
                false,
                'query_worker_incompatible',
                sprintf(
                    'Query-capable workers on task queue [%s] support workflow type [%s], but none advertise the recorded workflow definition fingerprint.',
                    $taskQueue,
                    $workflowType ?? 'unknown',
                ),
                $taskQueue,
                $activeWorkers->count(),
                $queryWorkers->count(),
                $typeWorkers->count(),
                0,
            );
        }

        return $this->queryRouteResult(
            true,
            null,
            null,
            $taskQueue,
            $activeWorkers->count(),
            $queryWorkers->count(),
            $typeWorkers->count(),
            $compatibleWorkers->count(),
        );
    }

    /**
     * @return array{
     *     servable: bool,
     *     reason: string|null,
     *     message: string|null,
     *     task_queue: string,
     *     active_worker_count: int,
     *     query_capable_worker_count: int,
     *     workflow_type_worker_count: int,
     *     compatible_worker_count: int
     * }
     */
    private function queryRouteResult(
        bool $servable,
        ?string $reason,
        ?string $message,
        string $taskQueue,
        int $activeWorkerCount,
        int $queryCapableWorkerCount,
        int $workflowTypeWorkerCount,
        int $compatibleWorkerCount,
    ): array {
        return [
            'servable' => $servable,
            'reason' => $reason,
            'message' => $message,
            'task_queue' => $taskQueue,
            'active_worker_count' => $activeWorkerCount,
            'query_capable_worker_count' => $queryCapableWorkerCount,
            'workflow_type_worker_count' => $workflowTypeWorkerCount,
            'compatible_worker_count' => $compatibleWorkerCount,
        ];
    }

    /**
     * @return array{
     *     budget_source: string,
     *     max_pending_per_queue: int,
     *     approximate_pending_count: int,
     *     remaining_pending_capacity: int,
     *     lock_required: bool,
     *     lock_supported: bool,
     *     status: string
     * }
     */
    public function queueAdmission(string $namespace, string $taskQueue): array
    {
        $maxPending = $this->maxPendingPerQueue();
        $pendingCount = count($this->pendingTaskIds($namespace, $taskQueue));
        $lockSupported = $this->store()->getStore() instanceof LockProvider;

        return [
            'budget_source' => 'server.query_tasks.max_pending_per_queue',
            'max_pending_per_queue' => $maxPending,
            'approximate_pending_count' => $pendingCount,
            'remaining_pending_capacity' => max(0, $maxPending - $pendingCount),
            'lock_required' => true,
            'lock_supported' => $lockSupported,
            'status' => $this->queueAdmissionStatus($pendingCount, $maxPending, $lockSupported),
        ];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    public function hasPendingTaskForPoller(
        string $namespace,
        string $taskQueue,
        array $supportedWorkflowTypes,
    ): bool {
        foreach ($this->pendingTaskIds($namespace, $taskQueue) as $queryTaskId) {
            $task = $this->task($queryTaskId);

            if (! is_array($task) || ($task['status'] ?? null) !== 'pending') {
                continue;
            }

            if ($this->matchesWorkflowType($supportedWorkflowTypes, $task['workflow_type'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function claimNext(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
    ): ?array {
        $task = $this->withQueueLock(
            $namespace,
            $taskQueue,
            fn (): ?array => $this->claimNextPendingTask(
                $namespace,
                $taskQueue,
                $leaseOwner,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $buildId,
                $pollRequestId,
            ),
        );

        if (($task['poll_status'] ?? null) === 'superseded') {
            return $task;
        }

        return is_array($task) ? $this->queryTaskPayload($task) : null;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array<string, mixed>|null
     */
    private function claimNextPendingTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        array $supportedWorkflowTypes,
        array $workflowDefinitionFingerprints,
        ?string $buildId = null,
        ?string $pollRequestId = null,
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

            if (! $this->matchesWorkflowDefinitionFingerprint(
                $workflowDefinitionFingerprints,
                $task['workflow_type'] ?? null,
                $task['workflow_definition_fingerprint'] ?? null,
            )) {
                $remaining[] = $queryTaskId;

                continue;
            }

            if (
                $pollRequestId !== null
                && ! $this->pollRequests->isCurrent($namespace, $taskQueue, $buildId, $leaseOwner, $pollRequestId)
            ) {
                return ['poll_status' => 'superseded'];
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

        $payload = [
            'query_task_id' => $task['query_task_id'],
            'query_task_attempt' => (int) ($task['attempt_count'] ?? 1),
            'workflow_id' => $task['workflow_id'],
            'run_id' => $task['run_id'],
            'workflow_type' => $task['workflow_type'],
            'workflow_class' => $run?->workflow_class,
            'query_name' => $task['query_name'],
            'payload_codec' => $task['payload_codec'],
            'workflow_arguments' => $run instanceof WorkflowRun && is_string($run->arguments)
                ? $this->payloadEnvelopes->workerEnvelope(
                    is_string($task['namespace'] ?? null) ? $task['namespace'] : null,
                    $run->payload_codec ?? CodecRegistry::defaultCodec(),
                    $run->arguments,
                )
                : null,
            'query_arguments' => $this->queryArgumentsEnvelope($task),
            'run_status' => $run?->status?->value,
            'last_history_sequence' => (int) ($run?->last_history_sequence ?? 0),
            'history_events' => $run instanceof WorkflowRun ? $this->historyEvents($run) : [],
            'history_export' => $run instanceof WorkflowRun ? HistoryExport::forRun($run) : null,
            'task_queue' => $task['task_queue'],
            'lease_owner' => $task['lease_owner'] ?? null,
            'lease_expires_at' => $task['lease_expires_at'] ?? null,
        ];

        $principal = $this->taskPrincipal($task);
        if ($principal !== null) {
            $payload['principal'] = $principal;
        }

        if (is_array($task['command_context'] ?? null)) {
            $payload['command_context'] = $task['command_context'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $attributes
     * @return array{type: string, id: string, label?: string}|null
     */
    private function commandContextPrincipal(?array $attributes): ?array
    {
        $context = $attributes['context'] ?? null;

        if (! is_array($context)) {
            return null;
        }

        return $this->principalPayload($context['principal'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{type: string, id: string, label?: string}|null
     */
    private function taskPrincipal(array $task): ?array
    {
        return $this->principalPayload($task['principal'] ?? null);
    }

    /**
     * @return array{type: string, id: string, label?: string}|null
     */
    private function principalPayload(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $type = $this->stringValue($value['type'] ?? null);
        $id = $this->stringValue($value['id'] ?? null);

        if ($type === null || $id === null) {
            return null;
        }

        $principal = [
            'type' => $type,
            'id' => $id,
        ];

        $label = $this->stringValue($value['label'] ?? null);
        if ($label !== null) {
            $principal['label'] = $label;
        }

        return $principal;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function queryArgumentsEnvelope(array $task): array
    {
        $arguments = $task['query_arguments'] ?? null;

        if (! is_array($arguments)) {
            return $this->emptyArgumentsEnvelope();
        }

        $blob = $arguments['blob'] ?? null;
        if ($blob === null && ! array_key_exists('external_storage', $arguments)) {
            return $this->emptyArgumentsEnvelope($arguments['codec'] ?? null);
        }

        if (! is_string($blob)) {
            return $arguments;
        }

        return $this->payloadEnvelopes->workerEnvelope(
            is_string($task['namespace'] ?? null) ? $task['namespace'] : null,
            is_string($arguments['codec'] ?? null) ? $arguments['codec'] : CodecRegistry::defaultCodec(),
            $blob,
        ) ?? ['codec' => CodecRegistry::defaultCodec(), 'blob' => null];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function emptyArgumentsEnvelope(mixed $codec = null): array
    {
        $codec = is_string($codec) && $codec !== ''
            ? $codec
            : CodecRegistry::defaultCodec();

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, []),
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>|null
     */
    private function resultEnvelope(string $namespace, array $envelope): ?array
    {
        $blob = $envelope['blob'] ?? null;

        if (! is_string($blob)) {
            return $envelope;
        }

        return $this->payloadEnvelopes->workerEnvelope(
            $namespace,
            is_string($envelope['codec'] ?? null) ? $envelope['codec'] : CodecRegistry::defaultCodec(),
            $blob,
        );
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
            ->map(fn (WorkflowHistoryEvent $event): array => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload)
                    ? $this->payloadEnvelopes->historyPayload(
                        $run->namespace,
                        $event->payload,
                        $run->payload_codec,
                        $event->event_type->value,
                    )
                    : [],
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

        if (($task['status'] ?? null) === 'timed_out') {
            return [
                'query_task_id' => $queryTaskId,
                'outcome' => 'rejected',
                'reason' => 'query_task_timed_out',
                'error' => 'Query task timed out before completion.',
                'timed_out_at' => $task['timed_out_at'] ?? null,
                'status' => 409,
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
            return false;
        }

        return is_string($workflowType) && in_array($workflowType, $supportedTypes, true);
    }

    /**
     * @param  array<string, string>  $workflowDefinitionFingerprints
     */
    private function matchesWorkflowDefinitionFingerprint(
        array $workflowDefinitionFingerprints,
        mixed $workflowType,
        mixed $recordedFingerprint,
    ): bool {
        $recordedFingerprint = $this->stringValue($recordedFingerprint);

        if ($recordedFingerprint === null) {
            return true;
        }

        $workflowType = $this->stringValue($workflowType);

        if ($workflowType === null) {
            return false;
        }

        $advertisedFingerprint = $this->stringValue($workflowDefinitionFingerprints[$workflowType] ?? null);

        return $advertisedFingerprint !== null && hash_equals($recordedFingerprint, $advertisedFingerprint);
    }

    private function workerAcceptsQueryTasks(string $namespace, WorkerRegistration $worker): bool
    {
        if (in_array(self::QUERY_TASKS_CAPABILITY, $this->stringArray($worker->capabilities), true)) {
            return true;
        }

        return $this->store()->get($this->queryPollingWorkerKey(
            $namespace,
            (string) $worker->task_queue,
            $worker->worker_id,
        )) === true;
    }

    private function rememberQueryTaskPollingWorker(string $namespace, string $taskQueue, string $workerId): void
    {
        $this->store()->put(
            $this->queryPollingWorkerKey($namespace, $taskQueue, $workerId),
            true,
            now()->addSeconds($this->queryPollingWorkerTtlSeconds()),
        );
    }

    private function recordedWorkflowDefinitionFingerprint(WorkflowRun $run): ?string
    {
        if (! $run->exists) {
            return null;
        }

        /** @var WorkflowHistoryEvent|null $event */
        $event = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->where('event_type', 'WorkflowStarted')
            ->orderBy('sequence')
            ->first();

        return $this->stringValue($event?->payload['workflow_definition_fingerprint'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function fingerprintMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fingerprints = [];

        foreach ($value as $workflowType => $fingerprint) {
            if (! is_string($workflowType) || ! is_string($fingerprint)) {
                continue;
            }

            $workflowType = trim($workflowType);
            $fingerprint = trim($fingerprint);

            if ($workflowType === '' || $fingerprint === '') {
                continue;
            }

            $fingerprints[$workflowType] = $fingerprint;
        }

        ksort($fingerprints);

        return $fingerprints;
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
        array $validationErrors = [],
        array $extra = [],
    ): array {
        $payload = array_merge([
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
        ], $extra);

        if ($validationErrors !== []) {
            $payload['validation_errors'] = $validationErrors;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array{reason: string, message: string, status: int}
     */
    private function timeoutFailure(string $namespace, WorkflowRun $run, string $queryName, array $task): array
    {
        $status = $this->stringValue($task['status'] ?? null) ?? 'unknown';

        if ($status === 'pending') {
            $route = $this->queryRoute($namespace, $run);

            if (! $route['servable']) {
                return [
                    'reason' => $route['reason'] ?? 'query_worker_unavailable',
                    'message' => $route['message'] ?? 'No compatible query-capable worker is available for this workflow query.',
                    'status' => 409,
                ];
            }

            return [
                'reason' => 'query_task_not_claimed',
                'message' => sprintf(
                    'Timed out waiting for a compatible worker to claim workflow query [%s] on task queue [%s].',
                    $queryName,
                    $route['task_queue'],
                ),
                'status' => 504,
            ];
        }

        if ($status === 'leased') {
            $leaseOwner = $this->stringValue($task['lease_owner'] ?? null);

            return [
                'reason' => 'query_worker_execution_timeout',
                'message' => $leaseOwner === null
                    ? sprintf('Timed out waiting for the worker that leased workflow query [%s] to complete it.', $queryName)
                    : sprintf('Timed out waiting for worker [%s] to complete workflow query [%s].', $leaseOwner, $queryName),
                'status' => 504,
            ];
        }

        return [
            'reason' => 'query_worker_timeout',
            'message' => sprintf(
                'Timed out waiting for workflow query [%s] to complete; last query task status was [%s].',
                $queryName,
                $status,
            ),
            'status' => 504,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationErrors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    $errors[(string) $field][] = $message;
                }
            }
        }

        return $errors;
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
        $configured = max(1, (int) config('server.query_tasks.lease_timeout', config('server.lease.workflow_task_timeout', 60)));
        $queryTimeout = $this->queryTimeoutSeconds();

        if ($queryTimeout === 0) {
            return $configured;
        }

        return max($configured, $queryTimeout + $this->leaseGraceSeconds());
    }

    private function taskTtlSeconds(): int
    {
        return max(
            60,
            (int) config('server.query_tasks.ttl_seconds', 0),
            $this->queryTimeoutSeconds() + $this->leaseTtlSeconds() + 60,
        );
    }

    private function maxPendingPerQueue(): int
    {
        return max(1, min(10000, (int) config('server.query_tasks.max_pending_per_queue', 1024)));
    }

    private function queueAdmissionStatus(int $pendingCount, int $maxPending, bool $lockSupported): string
    {
        if (! $lockSupported) {
            return 'unavailable';
        }

        return $pendingCount >= $maxPending ? 'full' : 'accepting';
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

    private function leaseGraceSeconds(): int
    {
        return 5;
    }

    private function queryPollingWorkerTtlSeconds(): int
    {
        return max($this->staleAfterSeconds(), $this->queryTimeoutSeconds() + $this->leaseGraceSeconds());
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

    private function queryPollingWorkerKey(string $namespace, string $taskQueue, string $workerId): string
    {
        return self::CACHE_PREFIX.'query-poller:'.sha1($namespace.'|'.$taskQueue.'|'.$workerId);
    }

    private function store(): CacheRepository
    {
        return $this->cache->store();
    }
}
