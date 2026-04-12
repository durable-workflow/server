<?php

namespace App\Support;

use App\Models\WorkflowTaskProtocolLease;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Models\WorkflowTask;

final class WorkflowTaskPoller
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly WorkflowTaskBridge $bridge,
        private readonly LongPollSignalStore $signals,
        private readonly WorkflowTaskLeaseRegistry $leases,
        private readonly WorkflowTaskLeaseRecovery $leaseRecovery,
        private readonly WorkflowTaskPollRequestStore $pollRequests,
        private readonly ServerPollingCache $cache,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function poll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
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
            );
        }

        return $this->coordinatedPoll(
            request: $request,
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            pollRequestId: $pollRequestId,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function coordinatedPoll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        string $pollRequestId,
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
            pollRequestId: $pollRequestId,
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
    private function runCoordinatedPollLeader(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        string $pollRequestId,
    ): ?array {
        try {
            $task = $this->performPoll(
                request: $request,
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                pollRequestId: $pollRequestId,
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
    private function performPoll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
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
                $pollRequestId,
                $limit,
                &$nextProbeAt,
            ): ?array {
                $result = $this->nextTask(
                    $request,
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $pollRequestId,
                    $limit,
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
    private function nextTask(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
        int $limit,
    ): array {
        $this->applyWorkerCompatibility($namespace, $buildId);

        $task = $this->redeliverActiveLease(
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            pollRequestId: $pollRequestId,
        );

        if (is_array($task)) {
            return [
                'task' => $task,
                'next_probe_at' => null,
            ];
        }

        $task = $this->claimReadyTask(
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            pollRequestId: $pollRequestId,
            limit: $limit,
        );

        if (is_array($task)) {
            return [
                'task' => $task,
                'next_probe_at' => null,
            ];
        }

        $task = $this->redeliverActiveLease(
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            pollRequestId: $pollRequestId,
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
                pollRequestId: $pollRequestId,
                limit: $limit,
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
    private function claimReadyTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
        int $limit,
    ): ?array {
        $readyTasks = $this->bridge->poll(
            connection: null,
            queue: $taskQueue,
            limit: $limit,
            compatibility: $buildId,
        );

        foreach ($readyTasks as $readyTask) {
            if ($this->availableAtIsFuture($readyTask['available_at'] ?? null)) {
                continue;
            }

            $workflowId = is_string($readyTask['workflow_instance_id'] ?? null)
                ? $readyTask['workflow_instance_id']
                : null;

            if ($workflowId === null || ! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
                continue;
            }

            if (! $this->matchesCompatibility($buildId, $readyTask['compatibility'] ?? null)) {
                continue;
            }

            $taskId = is_string($readyTask['task_id'] ?? null)
                ? $readyTask['task_id']
                : null;

            if ($taskId === null) {
                continue;
            }

            $claim = $this->bridge->claimStatus($taskId, $leaseOwner);

            if (($claim['claimed'] ?? false) !== true) {
                continue;
            }

            $lease = $this->leases->recordClaim($namespace, $claim, $pollRequestId);

            $history = $this->bridge->historyPayload($taskId);

            if (! is_array($history)) {
                $this->leases->clearActiveLease($taskId);

                continue;
            }

            return $this->taskPayload($claim, $lease, $history, $workflowId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function redeliverActiveLease(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?string $pollRequestId,
    ): ?array {
        $pollRequestId = $this->nonEmptyString($pollRequestId);

        if ($pollRequestId === null) {
            return null;
        }

        $lease = $this->leases->activeLeaseForPollRequest(
            namespace: $namespace,
            taskQueue: $taskQueue,
            buildId: $buildId,
            leaseOwner: $leaseOwner,
            pollRequestId: $pollRequestId,
        );

        if (! $lease instanceof WorkflowTaskProtocolLease) {
            return null;
        }

        $task = NamespaceWorkflowScope::task($namespace, $lease->task_id);

        if (! $task instanceof WorkflowTask || $task->task_type !== TaskType::Workflow) {
            return null;
        }

        if ($task->status !== TaskStatus::Leased) {
            $this->leases->syncTaskState($task);

            return null;
        }

        if ($this->nonEmptyString($task->queue) !== $taskQueue) {
            return null;
        }

        if (! $this->matchesCompatibility($buildId, $task->compatibility)) {
            return null;
        }

        if ($this->nonEmptyString($task->lease_owner) !== $leaseOwner) {
            $this->leases->syncTaskState($task);

            return null;
        }

        if ($task->lease_expires_at === null || $task->lease_expires_at->lte(now())) {
            return null;
        }

        $history = $this->bridge->historyPayload($task->id);

        if (! is_array($history)) {
            $this->leases->clearActiveLease($task->id);

            return null;
        }

        return $this->taskPayload([
            'task_id' => $task->id,
            'workflow_run_id' => $lease->workflow_run_id ?? $task->workflow_run_id,
            'workflow_instance_id' => $lease->workflow_instance_id ?? ($history['workflow_instance_id'] ?? null),
            'workflow_type' => $history['workflow_type'] ?? null,
            'workflow_class' => $history['workflow_class'] ?? null,
            'payload_codec' => $history['payload_codec'] ?? config('workflows.serializer'),
            'connection' => $this->nonEmptyString($task->connection),
            'queue' => $this->nonEmptyString($task->queue),
            'compatibility' => $this->nonEmptyString($task->compatibility),
            'lease_owner' => $lease->lease_owner,
            'lease_expires_at' => $lease->lease_expires_at?->toJSON() ?? $task->lease_expires_at?->toJSON(),
        ], $lease, $history, $lease->workflow_instance_id);
    }

    private function recoverExpiredLeases(
        Request $request,
        string $namespace,
        string $taskQueue,
    ): bool {
        $limit = max(1, (int) config('server.polling.expired_workflow_task_recovery_scan_limit', 5));

        // Scan the package's WorkflowTask table directly for expired leases.
        // This is the source of truth for lease state and does not require a
        // mirror table row to exist for recovery to work.
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
            $query->where('workflow_tasks.compatibility', $buildId);
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
     * @param  array<string, mixed>|null  $task
     */
    private function cachedTaskStillDeliverable(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        ?array $task,
    ): bool {
        if ($task === null) {
            return true;
        }

        $taskId = $this->nonEmptyString($task['task_id'] ?? null);

        if ($taskId === null) {
            return false;
        }

        $lease = $this->leases->activeLease($namespace, $taskId);
        $workflowTaskAttempt = is_numeric($task['workflow_task_attempt'] ?? null)
            ? (int) $task['workflow_task_attempt']
            : null;

        if ($lease instanceof WorkflowTaskProtocolLease) {
            if (
                ! $lease->hasActiveLease()
                || $lease->lease_expires_at === null
                || $lease->lease_expires_at->lte(now())
                || $this->nonEmptyString($lease->lease_owner) !== $leaseOwner
                || $this->nonEmptyString($lease->last_poll_request_id) !== $pollRequestId
            ) {
                return false;
            }

            if (
                $workflowTaskAttempt !== null
                && (int) $lease->workflow_task_attempt !== $workflowTaskAttempt
            ) {
                return false;
            }
        }

        $workflowTask = NamespaceWorkflowScope::task($namespace, $taskId);

        if (! $workflowTask instanceof WorkflowTask || $workflowTask->task_type !== TaskType::Workflow) {
            return false;
        }

        if ($workflowTask->status !== TaskStatus::Leased) {
            $this->leases->syncTaskState($workflowTask);

            return false;
        }

        if ($this->nonEmptyString($workflowTask->queue) !== $taskQueue) {
            return false;
        }

        if (! $this->matchesCompatibility($buildId, $workflowTask->compatibility)) {
            return false;
        }

        if ($this->nonEmptyString($workflowTask->lease_owner) !== $leaseOwner) {
            $this->leases->syncTaskState($workflowTask);

            return false;
        }

        if ($workflowTask->lease_expires_at === null || $workflowTask->lease_expires_at->lte(now())) {
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

        $lease = $this->leases->activeLease($namespace, $taskId);
        $payload = $task;

        if ($lease instanceof WorkflowTaskProtocolLease) {
            $payload['workflow_task_attempt'] = (int) $lease->workflow_task_attempt;

            if ($this->nonEmptyString($lease->workflow_instance_id) !== null) {
                $payload['workflow_id'] = $lease->workflow_instance_id;
            }
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
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function taskPayload(
        array $claim,
        WorkflowTaskProtocolLease $lease,
        array $history,
        ?string $workflowIdFallback,
    ): array {
        return [
            'task_id' => $claim['task_id'],
            'workflow_id' => $history['workflow_instance_id']
                ?? $claim['workflow_instance_id']
                ?? $workflowIdFallback,
            'run_id' => $claim['workflow_run_id'],
            'workflow_task_attempt' => (int) $lease->workflow_task_attempt,
            'workflow_type' => $claim['workflow_type'],
            'workflow_class' => $claim['workflow_class'],
            'payload_codec' => $claim['payload_codec'],
            'arguments' => $history['arguments'] ?? null,
            'run_status' => $history['run_status'] ?? null,
            'last_history_sequence' => $history['last_history_sequence'] ?? 0,
            'history_events' => $history['history_events'] ?? [],
            'task_queue' => $claim['queue'],
            'connection' => $claim['connection'],
            'compatibility' => $claim['compatibility'],
            'lease_owner' => $claim['lease_owner'],
            'lease_expires_at' => $claim['lease_expires_at'],
        ];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
