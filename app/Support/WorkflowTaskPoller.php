<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryPayloadCompression;

final class WorkflowTaskPoller
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly WorkflowTaskBridge $bridge,
        private readonly LongPollSignalStore $signals,
        private readonly WorkflowTaskLeaseRecovery $leaseRecovery,
        private readonly WorkflowTaskPollRequestStore $pollRequests,
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    public function poll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
    ): ?array {
        $pollRequestId = $this->nonEmptyString($pollRequestId);

        if ($pollRequestId === null) {
            return $this->performPoll(
                request: $request,
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                pollRequestId: null,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            );
        }

        return $this->coordinatedPoll(
            request: $request,
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            pollRequestId: $pollRequestId,
            historyPageSize: $historyPageSize,
            acceptHistoryEncoding: $acceptHistoryEncoding,
            supportedWorkflowTypes: $supportedWorkflowTypes,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function coordinatedPoll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
    ): ?array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $cached = $this->cachedPollResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            );

            if ($cached['resolved']) {
                return $cached['task'];
            }

            if ($this->pollRequests->tryStart(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            )) {
                return $this->runCoordinatedPollLeader(
                    request: $request,
                    namespace: $namespace,
                    taskQueue: $taskQueue,
                    leaseOwner: $leaseOwner,
                    buildId: $buildId,
                    pollRequestId: $pollRequestId,
                    historyPageSize: $historyPageSize,
                    acceptHistoryEncoding: $acceptHistoryEncoding,
                    supportedWorkflowTypes: $supportedWorkflowTypes,
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

        $cached = $this->cachedPollResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
        );

        return $cached['task'];
    }

    /**
     * @return array{resolved: bool, task: array<string, mixed>|null}
     */
    private function cachedPollResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
    ): array {
        $cached = $this->pollRequests->result(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
        );

        if (! $cached['resolved']) {
            return $cached;
        }

        if ($this->cachedTaskStillDeliverable(
            namespace: $namespace,
            taskQueue: $taskQueue,
            buildId: $buildId,
            leaseOwner: $leaseOwner,
            task: $cached['task'],
        )) {
            $refreshedTask = $this->refreshCachedTaskPayload(
                namespace: $namespace,
                task: $cached['task'],
            );

            if ($refreshedTask !== $cached['task']) {
                $this->pollRequests->rememberResult(
                    $namespace,
                    $taskQueue,
                    $buildId,
                    $leaseOwner,
                    $pollRequestId,
                    $refreshedTask,
                );
            }

            return [
                'resolved' => true,
                'task' => $refreshedTask,
            ];
        }

        $this->pollRequests->forgetResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
        );

        return [
            'resolved' => false,
            'task' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function runCoordinatedPollLeader(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
    ): ?array {
        try {
            $task = $this->performPoll(
                request: $request,
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                pollRequestId: $pollRequestId,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            );
        } catch (Throwable $exception) {
            $this->pollRequests->forgetPending(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            );

            throw $exception;
        }

        $this->pollRequests->rememberResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            $task,
        );

        return $task;
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function performPoll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
    ): ?array {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $nextProbeAt = null;

        return $this->longPoller->until(
            function () use (
                $request,
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $historyPageSize,
                $acceptHistoryEncoding,
                $supportedWorkflowTypes,
                $limit,
                &$nextProbeAt,
            ): ?array {
                $result = $this->nextTask(
                    $request,
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $limit,
                    $historyPageSize,
                    $acceptHistoryEncoding,
                    $supportedWorkflowTypes,
                );
                $nextProbeAt = $result['next_probe_at'] ?? null;

                return $result['task'] ?? null;
            },
            static fn (?array $task): bool => is_array($task),
            wakeChannels: $this->signals->workflowTaskPollChannels($namespace, null, $taskQueue),
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
        );
    }

    /**
     * @return array{task: array<string, mixed>|null, next_probe_at: \DateTimeInterface|null}
     */
    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function nextTask(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
    ): array {
        $this->applyWorkerCompatibility($namespace, $buildId);

        $task = $this->claimReadyTask(
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            limit: $limit,
            historyPageSize: $historyPageSize,
            acceptHistoryEncoding: $acceptHistoryEncoding,
            supportedWorkflowTypes: $supportedWorkflowTypes,
        );

        if (is_array($task)) {
            return [
                'task' => $task,
                'next_probe_at' => null,
            ];
        }

        if ($this->recoverExpiredLeases($request, $namespace, $taskQueue)) {
            $task = $this->claimReadyTask(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                limit: $limit,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            );

            if (is_array($task)) {
                return [
                    'task' => $task,
                    'next_probe_at' => null,
                ];
            }
        }

        return [
            'task' => null,
            'next_probe_at' => $this->nextVisibleReadyAt($namespace, $taskQueue, $buildId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function claimReadyTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
    ): ?array {
        $readyTasks = $this->bridge->poll(
            connection: null,
            queue: $taskQueue,
            limit: $limit,
            compatibility: null,
            namespace: $namespace,
        );

        \Log::info('[WorkflowTaskPoller] claimReadyTask called', [
            'namespace' => $namespace,
            'taskQueue' => $taskQueue,
            'leaseOwner' => $leaseOwner,
            'buildId' => $buildId,
            'supportedWorkflowTypes' => $supportedWorkflowTypes,
            'readyTasksCount' => count($readyTasks),
        ]);

        foreach ($readyTasks as $readyTask) {
            \Log::debug('[WorkflowTaskPoller] Checking task', [
                'taskId' => $readyTask['task_id'] ?? null,
                'workflowType' => $readyTask['workflow_type'] ?? null,
                'compatibility' => $readyTask['compatibility'] ?? null,
                'availableAt' => $readyTask['available_at'] ?? null,
            ]);

            if ($this->availableAtIsFuture($readyTask['available_at'] ?? null)) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: available_at is in the future', [
                    'taskId' => $readyTask['task_id'] ?? null,
                    'availableAt' => $readyTask['available_at'] ?? null,
                ]);
                continue;
            }

            $workflowId = is_string($readyTask['workflow_instance_id'] ?? null)
                ? $readyTask['workflow_instance_id']
                : null;

            if ($workflowId === null || ! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: workflow not bound to namespace', [
                    'taskId' => $readyTask['task_id'] ?? null,
                    'workflowId' => $workflowId,
                    'namespace' => $namespace,
                ]);
                continue;
            }

            if (! $this->matchesCompatibility($buildId, $readyTask['compatibility'] ?? null)) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: build_id mismatch', [
                    'taskId' => $readyTask['task_id'] ?? null,
                    'workerBuildId' => $buildId,
                    'taskCompatibility' => $readyTask['compatibility'] ?? null,
                ]);
                continue;
            }

            if (! $this->matchesWorkflowType($supportedWorkflowTypes, $readyTask['workflow_type'] ?? null)) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: workflow type not supported', [
                    'taskId' => $readyTask['task_id'] ?? null,
                    'taskWorkflowType' => $readyTask['workflow_type'] ?? null,
                    'supportedWorkflowTypes' => $supportedWorkflowTypes,
                ]);
                continue;
            }

            $taskId = is_string($readyTask['task_id'] ?? null)
                ? $readyTask['task_id']
                : null;

            if ($taskId === null) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: task_id is null');
                continue;
            }

            \Log::debug('[WorkflowTaskPoller] Task passed all checks, attempting to claim', [
                'taskId' => $taskId,
                'leaseOwner' => $leaseOwner,
            ]);

            $claim = $this->bridge->claimStatus($taskId, $leaseOwner);

            if (($claim['claimed'] ?? false) !== true) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: claim failed', [
                    'taskId' => $taskId,
                    'claimResult' => $claim,
                ]);
                continue;
            }

            \Log::info('[WorkflowTaskPoller] Task claimed successfully', [
                'taskId' => $taskId,
                'leaseOwner' => $leaseOwner,
                'workflowType' => $readyTask['workflow_type'] ?? null,
            ]);

            // Source the fencing token from the package's authoritative attempt
            // counter. The package increments WorkflowTask.attempt_count
            // atomically inside claimStatus().
            $attempt = $this->packageAttemptCount($taskId);

            $history = $this->fetchHistory($taskId, $historyPageSize, $acceptHistoryEncoding);

            if (! is_array($history)) {
                \Log::warning('[WorkflowTaskPoller] Task claimed but history fetch failed', [
                    'taskId' => $taskId,
                ]);
                continue;
            }

            \Log::info('[WorkflowTaskPoller] Returning task to worker', [
                'taskId' => $taskId,
                'workflowType' => $readyTask['workflow_type'] ?? null,
                'historyEventsCount' => count($history['history_events'] ?? []),
            ]);

            return $this->taskPayload($claim, $attempt, $history, $workflowId);
        }

        \Log::debug('[WorkflowTaskPoller] No tasks claimed (examined all ready tasks)');
        return null;
    }

    private function recoverExpiredLeases(
        Request $request,
        string $namespace,
        string $taskQueue,
    ): bool {
        $limit = max(1, (int) config('server.polling.expired_workflow_task_recovery_scan_limit', 5));

        $expiredTasks = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereNotNull('workflow_tasks.lease_owner')
            ->whereNotNull('workflow_tasks.lease_expires_at')
            ->where('workflow_tasks.lease_expires_at', '<=', now())
            ->orderBy('workflow_tasks.lease_expires_at')
            ->limit($limit)
            ->get();

        $recovered = false;

        foreach ($expiredTasks as $task) {
            if (! $this->markRecoveryAttempt($task->id)) {
                continue;
            }

            $this->leaseRecovery->recoverExpiredTaskLease($request, $namespace, $task);
            $recovered = true;
        }

        return $recovered;
    }

    private function applyWorkerCompatibility(string $namespace, ?string $buildId): void
    {
        config([
            'workflows.v2.compatibility.namespace' => $namespace,
            'workflows.v2.compatibility.current' => $buildId,
            'workflows.v2.compatibility.supported' => $buildId === null ? [] : [$buildId],
        ]);
    }

    private function availableAtIsFuture(mixed $availableAt): bool
    {
        if ($availableAt instanceof \DateTimeInterface) {
            return $availableAt > now();
        }

        if (! is_string($availableAt) || trim($availableAt) === '') {
            return false;
        }

        try {
            return now()->lt(Carbon::parse($availableAt));
        } catch (\Throwable) {
            return false;
        }
    }

    private function matchesCompatibility(?string $buildId, mixed $compatibility): bool
    {
        if (! is_string($compatibility) || trim($compatibility) === '') {
            return true;
        }

        return $buildId !== null && $compatibility === $buildId;
    }

    /**
     * @param  list<string>  $supportedTypes
     */
    private function matchesWorkflowType(array $supportedTypes, mixed $workflowType): bool
    {
        if ($supportedTypes === []) {
            return true;
        }

        if (! is_string($workflowType) || trim($workflowType) === '') {
            return true;
        }

        return in_array($workflowType, $supportedTypes, true);
    }

    private function nextVisibleReadyAt(string $namespace, string $taskQueue, ?string $buildId): ?\DateTimeInterface
    {
        $query = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereNotNull('workflow_tasks.available_at')
            ->where('workflow_tasks.available_at', '>', now())
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id');

        if ($buildId === null) {
            $query->where(function ($builder): void {
                $builder->whereNull('workflow_tasks.compatibility')
                    ->orWhere('workflow_tasks.compatibility', '');
            });
        } else {
            $query->where(function ($builder) use ($buildId): void {
                $builder->whereNull('workflow_tasks.compatibility')
                    ->orWhere('workflow_tasks.compatibility', '')
                    ->orWhere('workflow_tasks.compatibility', $buildId);
            });
        }

        /** @var WorkflowTask|null $task */
        $task = $query->first();

        return $task?->available_at;
    }

    private function markRecoveryAttempt(string $taskId): bool
    {
        $ttl = max(1, (int) config('server.polling.expired_workflow_task_recovery_ttl_seconds', 5));

        return $this->cache->store()->add(
            $this->recoveryKey($taskId),
            now()->toJSON(),
            now()->addSeconds($ttl),
        );
    }

    private function recoveryKey(string $taskId): string
    {
        return sprintf('server:workflow-task-expired-lease-recovery:%s', $taskId);
    }

    /**
     * Verify a cached poll result is still deliverable by checking the
     * package's WorkflowTask directly. The attempt_count check fences
     * against reclaimed tasks, replacing the former mirror table's
     * last_poll_request_id check.
     *
     * @param  array<string, mixed>|null  $task
     */
    private function cachedTaskStillDeliverable(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        ?array $task,
    ): bool {
        if ($task === null) {
            return true;
        }

        $taskId = $this->nonEmptyString($task['task_id'] ?? null);

        if ($taskId === null) {
            return false;
        }

        $workflowTask = NamespaceWorkflowScope::task($namespace, $taskId);

        if (! $workflowTask instanceof WorkflowTask || $workflowTask->task_type !== TaskType::Workflow) {
            return false;
        }

        if ($workflowTask->status !== TaskStatus::Leased) {
            return false;
        }

        if ($this->nonEmptyString($workflowTask->queue) !== $taskQueue) {
            return false;
        }

        if (! $this->matchesCompatibility($buildId, $workflowTask->compatibility)) {
            return false;
        }

        if ($this->nonEmptyString($workflowTask->lease_owner) !== $leaseOwner) {
            return false;
        }

        if ($workflowTask->lease_expires_at === null || $workflowTask->lease_expires_at->lte(now())) {
            return false;
        }

        $workflowTaskAttempt = is_numeric($task['workflow_task_attempt'] ?? null)
            ? (int) $task['workflow_task_attempt']
            : null;

        if (
            $workflowTaskAttempt !== null
            && is_int($workflowTask->attempt_count)
            && (int) $workflowTask->attempt_count !== $workflowTaskAttempt
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @return array<string, mixed>|null
     */
    private function refreshCachedTaskPayload(string $namespace, ?array $task): ?array
    {
        if (! is_array($task)) {
            return $task;
        }

        $taskId = $this->nonEmptyString($task['task_id'] ?? null);

        if ($taskId === null) {
            return $task;
        }

        $workflowTask = NamespaceWorkflowScope::task($namespace, $taskId);

        if (! $workflowTask instanceof WorkflowTask || $workflowTask->task_type !== TaskType::Workflow) {
            return $task;
        }

        $payload = $task;

        // Source workflow_task_attempt from the package's authoritative counter.
        if (is_int($workflowTask->attempt_count) && $workflowTask->attempt_count > 0) {
            $payload['workflow_task_attempt'] = (int) $workflowTask->attempt_count;
        }

        // Resolve workflow_instance_id through the package's run relationship.
        $workflowInstanceId = $workflowTask->run?->workflow_instance_id;

        if (is_string($workflowInstanceId) && $workflowInstanceId !== '') {
            $payload['workflow_id'] = $workflowInstanceId;
        }

        if ($this->nonEmptyString($workflowTask->workflow_run_id) !== null) {
            $payload['run_id'] = $workflowTask->workflow_run_id;
        }

        $payload['task_queue'] = $this->nonEmptyString($workflowTask->queue)
            ?? ($payload['task_queue'] ?? null);
        $payload['connection'] = $this->nonEmptyString($workflowTask->connection)
            ?? ($payload['connection'] ?? null);
        $payload['compatibility'] = $this->nonEmptyString($workflowTask->compatibility)
            ?? ($payload['compatibility'] ?? null);
        $payload['lease_owner'] = $this->nonEmptyString($workflowTask->lease_owner)
            ?? ($payload['lease_owner'] ?? null);
        $payload['lease_expires_at'] = $workflowTask->lease_expires_at?->toJSON()
            ?? ($payload['lease_expires_at'] ?? null);

        return $payload;
    }

    /**
     * Fetch history for a claimed task, using database-level pagination
     * and protocol-level compression when requested.
     *
     * @return array<string, mixed>|null
     */
    private function fetchHistory(
        string $taskId,
        ?int $historyPageSize,
        ?string $acceptHistoryEncoding,
    ): ?array {
        if ($historyPageSize !== null) {
            $history = $this->bridge->historyPayloadPaginated($taskId, 0, $historyPageSize);
        } else {
            $history = $this->bridge->historyPayload($taskId);
        }

        if (! is_array($history)) {
            return null;
        }

        if ($acceptHistoryEncoding !== null) {
            $history = HistoryPayloadCompression::compress($history, $acceptHistoryEncoding);
        }

        return $history;
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function taskPayload(
        array $claim,
        int $attempt,
        array $history,
        ?string $workflowIdFallback,
    ): array {
        $payload = [
            'task_id' => $claim['task_id'],
            'workflow_id' => $history['workflow_instance_id']
                ?? $claim['workflow_instance_id']
                ?? $workflowIdFallback,
            'run_id' => $claim['workflow_run_id'],
            'workflow_task_attempt' => $attempt,
            'workflow_type' => $claim['workflow_type'],
            'payload_codec' => $claim['payload_codec'],
            'arguments' => ($history['arguments'] ?? null) !== null
                ? ['codec' => $claim['payload_codec'] ?? 'json', 'blob' => $history['arguments']]
                : null,
            'run_status' => $history['run_status'] ?? null,
            'last_history_sequence' => $history['last_history_sequence'] ?? 0,
            'history_events' => $history['history_events'] ?? [],
            'task_queue' => $claim['queue'],
            'connection' => $claim['connection'],
            'compatibility' => $claim['compatibility'],
            'lease_owner' => $claim['lease_owner'],
            'lease_expires_at' => $claim['lease_expires_at'],
        ];

        // Include pagination metadata when history was fetched via
        // historyPayloadPaginated() so the controller can build page tokens.
        if (array_key_exists('has_more', $history)) {
            $payload['total_history_events'] = $history['last_history_sequence'] ?? count($history['history_events'] ?? []);
            $payload['has_more'] = $history['has_more'];
            $payload['next_after_sequence'] = $history['next_after_sequence'] ?? null;
        }

        // Include compression envelope fields when history was compressed
        // by HistoryPayloadCompression.
        if (isset($history['history_events_compressed'])) {
            $payload['history_events_compressed'] = $history['history_events_compressed'];
            $payload['history_events_encoding'] = $history['history_events_encoding'];
        }

        return $payload;
    }

    /**
     * Read the package's authoritative attempt counter for a workflow task.
     */
    private function packageAttemptCount(string $taskId): int
    {
        $count = WorkflowTask::query()
            ->whereKey($taskId)
            ->value('attempt_count');

        return is_int($count) && $count > 0 ? $count : 1;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
