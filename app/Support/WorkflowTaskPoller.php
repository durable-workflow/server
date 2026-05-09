<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryPayloadCompression;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskFairnessScheduler;
use Workflow\V2\Support\TaskFairnessState;

final class WorkflowTaskPoller
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly WorkflowTaskBridge $bridge,
        private readonly LongPollSignalStore $signals,
        private readonly WorkflowTaskLeaseRecovery $leaseRecovery,
        private readonly WorkflowTaskPollRequestStore $pollRequests,
        private readonly ServerPollingCache $cache,
        private readonly TaskQueueAdmission $admission,
        private readonly TaskFairnessState $fairnessState,
    ) {}

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
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
    ): array {
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
     * @param  list<string>  $supportedWorkflowTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
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
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $cached = $this->cachedPollResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            );

            if ($cached['resolved']) {
                return [
                    'task' => $cached['task'],
                    'poll_status' => $cached['poll_status'] ?? $this->defaultPollStatus($cached['task']),
                ];
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
                return [
                    'task' => $observed['task'],
                    'poll_status' => $observed['poll_status'] ?? $this->defaultPollStatus($observed['task']),
                ];
            }
        }

        $cached = $this->cachedPollResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
        );

        return [
            'task' => $cached['task'],
            'poll_status' => $cached['poll_status'] ?? $this->defaultPollStatus($cached['task']),
        ];
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
                    $cached['poll_status'] ?? $this->defaultPollStatus($refreshedTask),
                );
            }

            return [
                'resolved' => true,
                'task' => $refreshedTask,
                'poll_status' => $cached['poll_status'] ?? $this->defaultPollStatus($refreshedTask),
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
            'poll_status' => null,
        ];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
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
    ): array {
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
            $task['task'] ?? null,
            $task['poll_status'] ?? $this->defaultPollStatus($task['task'] ?? null),
        );

        return $task;
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
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
    ): array {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $nextProbeAt = null;
        $resolvedResult = [
            'task' => null,
            'poll_status' => 'empty',
            'next_probe_at' => null,
        ];

        $task = $this->longPoller->until(
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
                &$resolvedResult,
            ): ?array {
                $resolvedResult = $this->nextTask(
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
                $nextProbeAt = $resolvedResult['next_probe_at'] ?? null;

                return $resolvedResult['task'] ?? null;
            },
            static fn (?array $task): bool => is_array($task),
            wakeChannels: $this->signals->workflowTaskPollChannels($namespace, null, $taskQueue),
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
        );

        return [
            'task' => $task,
            'poll_status' => $resolvedResult['poll_status'] ?? $this->defaultPollStatus($task),
        ];
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}
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

        $task = $this->admission->withLeaseAdmission(
            $namespace,
            $taskQueue,
            TaskQueueAdmission::WORKFLOW_TASKS,
            fn (): ?array => $this->claimReadyTask(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                limit: $limit,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            ),
        );

        if (is_array($task)) {
            return [
                'task' => $task,
                'poll_status' => 'leased',
                'next_probe_at' => null,
            ];
        }

        if ($this->recoverExpiredLeases($request, $namespace, $taskQueue)) {
            $task = $this->admission->withLeaseAdmission(
                $namespace,
                $taskQueue,
                TaskQueueAdmission::WORKFLOW_TASKS,
                fn (): ?array => $this->claimReadyTask(
                    namespace: $namespace,
                    taskQueue: $taskQueue,
                    leaseOwner: $leaseOwner,
                    buildId: $buildId,
                    limit: $limit,
                    historyPageSize: $historyPageSize,
                    acceptHistoryEncoding: $acceptHistoryEncoding,
                    supportedWorkflowTypes: $supportedWorkflowTypes,
                ),
            );

            if (is_array($task)) {
                return [
                    'task' => $task,
                    'poll_status' => 'leased',
                    'next_probe_at' => null,
                ];
            }
        }

        return [
            'task' => null,
            'poll_status' => $this->emptyPollStatus($namespace, $taskQueue, TaskQueueAdmission::WORKFLOW_TASKS),
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
        $readyTasks = $this->pollReadyTasks(
            namespace: $namespace,
            taskQueue: $taskQueue,
            limit: $limit,
            supportedWorkflowTypes: $supportedWorkflowTypes,
        );

        // Server-owned dispatch is authoritative for typed polls: union
        // the bridge candidates with an app-level workflow_tasks ↔
        // workflow_runs join filtered by the worker's registered types,
        // so the shared-queue routing rule cannot depend on the bridge's
        // current predicate shape. The earlier gate — fall back only when
        // $readyTasks === [] — left a hole on shared queues: when the
        // bridge surfaced a list of candidates whose workflow_type did
        // not match the polling worker's supported list, every entry was
        // dropped by the per-task matchesWorkflowType filter, the
        // fallback never ran, and a real matching task already in
        // workflow_tasks/workflow_runs stayed unclaimed. Running the
        // app-level query unconditionally for typed polls and merging
        // by task_id closes that hole, so a worker registered for the
        // run's stored workflow_type still sees its matching task even
        // when the bridge returns an unrelated candidate set for the
        // same queue. The merged candidate set is what the fairness
        // reorder pass below operates on, so dispatch routing is fixed
        // before fairness rebalances within priority tiers.
        if ($supportedWorkflowTypes !== []) {
            $dispatchTasks = $this->pollReadyTasksFromDispatch(
                namespace: $namespace,
                taskQueue: $taskQueue,
                limit: $limit,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            );

            $readyTasks = $this->mergeReadyTasksByTaskId($readyTasks, $dispatchTasks);
        }

        // Apply the fairness reorder pass: within a priority tier the
        // batch is rebalanced across distinct fairness-key classes so a
        // single noisy class can't starve its peers under saturation.
        // Priority order is preserved across tiers — urgent work always
        // leads — and tasks without a fairness key share an implicit
        // default class so unmarked tenants are never crowded out. The
        // dispatch-side union above runs first so the merged candidate
        // set is what fairness reorders.
        $readyTasks = $this->reorderForFairness($taskQueue, $readyTasks);

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
                // Authoritative routing on the run's stored workflow_type:
                // even if the bridge returned this task (because of a stale
                // index, a relaxed predicate, or a future bridge change),
                // a worker that did not advertise this type at register
                // time must never claim it. Without this guard, a polyglot
                // task whose type-key is not in the worker's registered
                // list could be leased to the wrong worker and the run
                // would stall pending until lease recovery.
                \Log::debug('[WorkflowTaskPoller] Skipping task: workflow_type not in supported list', [
                    'taskId' => $readyTask['task_id'] ?? null,
                    'workflowType' => $readyTask['workflow_type'] ?? null,
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

            // Record the dispatch against the shared fairness state so
            // future polls (in this process or another) see the deficit
            // and continue rebalancing toward under-served classes. This
            // happens after the claim succeeds so failed claims do not
            // count against a class's fairness budget.
            $this->recordFairnessDispatch($taskQueue, $readyTask);

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

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @return list<array<string, mixed>>
     */
    private function pollReadyTasks(
        string $namespace,
        string $taskQueue,
        int $limit,
        array $supportedWorkflowTypes = [],
    ): array {
        return $this->bridge->poll(
            null,
            $taskQueue,
            $limit,
            null,
            $namespace,
            $supportedWorkflowTypes,
        );
    }

    /**
     * Server-owned dispatch identification for typed workflow polls.
     *
     * For typed polls (worker registered a non-empty
     * supportedWorkflowTypes list), the dispatch path runs a direct
     * query that joins workflow_tasks to workflow_runs and filters by
     * the run's stored workflow_type, then unions the result with the
     * bridge's candidate set in claimReadyTask(). Owning this query in
     * app code makes the shared-queue routing rule authoritative on
     * the server side: a worker that registered for a workflow_type
     * still sees the matching task on the next poll, even when the
     * package bridge surfaces an unrelated candidate set for the same
     * queue (the polyglot two-worker shared-queue case) or its
     * predicate drifts across releases. The bridge stays authoritative
     * for the claim transaction — this method only identifies
     * candidates and returns the same row shape pollReadyTasks()
     * already produces, so the downstream filter chain
     * (matchesCompatibility, matchesWorkflowType, claimStatus) runs
     * unchanged on the same payload.
     *
     * @param  list<string>  $supportedWorkflowTypes
     * @return list<array<string, mixed>>
     */
    private function pollReadyTasksFromDispatch(
        string $namespace,
        string $taskQueue,
        int $limit,
        array $supportedWorkflowTypes,
    ): array {
        if ($supportedWorkflowTypes === []) {
            return [];
        }

        $availabilityCutoff = now()->addSecond();

        $tasks = NamespaceWorkflowScope::taskQuery($namespace)
            ->select('workflow_tasks.*')
            ->join(
                'workflow_runs',
                'workflow_runs.id',
                '=',
                'workflow_tasks.workflow_run_id',
            )
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where(function ($builder) use ($availabilityCutoff): void {
                $builder->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $availabilityCutoff);
            })
            ->whereIn('workflow_runs.workflow_type', $supportedWorkflowTypes)
            ->orderBy('workflow_tasks.priority')
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id')
            ->limit(max(1, $limit))
            ->get();

        return $tasks->map(function (WorkflowTask $task): array {
            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()->find($task->workflow_run_id);

            return [
                'task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'workflow_instance_id' => is_string($run?->workflow_instance_id)
                    ? $run->workflow_instance_id
                    : '',
                'workflow_type' => $this->nonEmptyString($run?->workflow_type),
                'workflow_class' => $this->nonEmptyString($run?->workflow_class),
                'connection' => $this->nonEmptyString($task->connection),
                'queue' => $this->nonEmptyString($task->queue),
                'compatibility' => $this->nonEmptyString($task->compatibility),
                'sticky_worker_id' => $this->nonEmptyString($task->sticky_worker_id ?? null),
                'sticky_until' => $task->sticky_until?->toJSON(),
                'available_at' => $task->available_at?->toJSON(),
                'priority' => is_int($task->priority) ? $task->priority : 5,
                'fairness_key' => $this->nonEmptyString($task->fairness_key ?? null),
                'fairness_weight' => is_int($task->fairness_weight) ? $task->fairness_weight : 1,
            ];
        })->values()->all();
    }

    /**
     * Union two ready-task lists by task_id, preserving order and
     * preferring the first occurrence's payload. The bridge's payload
     * shape is preserved when both sources surface the same task, so
     * downstream consumers (claim, history fetch) keep seeing the
     * payload variant they have always seen on the happy path. Tasks
     * the bridge surfaced that the dispatch query didn't are kept;
     * tasks the dispatch query surfaced that the bridge missed are
     * appended.
     *
     * @param  list<array<string, mixed>>  $primary
     * @param  list<array<string, mixed>>  $secondary
     * @return list<array<string, mixed>>
     */
    private function mergeReadyTasksByTaskId(array $primary, array $secondary): array
    {
        $seen = [];
        $merged = [];

        foreach ($primary as $task) {
            $taskId = is_string($task['task_id'] ?? null) ? $task['task_id'] : null;

            if ($taskId === null) {
                $merged[] = $task;

                continue;
            }

            if (isset($seen[$taskId])) {
                continue;
            }

            $seen[$taskId] = true;
            $merged[] = $task;
        }

        foreach ($secondary as $task) {
            $taskId = is_string($task['task_id'] ?? null) ? $task['task_id'] : null;

            if ($taskId === null) {
                continue;
            }

            if (isset($seen[$taskId])) {
                continue;
            }

            $seen[$taskId] = true;
            $merged[] = $task;
        }

        return $merged;
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
        } catch (Throwable) {
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
     * Compare the worker's registered workflow types against the task's
     * stored workflow_type. The match is exact-string against the column
     * the run was created with at start-time — no class-resolution, no
     * canonicalization. Workers that registered an empty list are already
     * short-circuited at the controller, so an empty $supported here means
     * "no capability filter requested by this caller" (used by the
     * lease-recovery probe path).
     *
     * @param  list<string>  $supported
     */
    private function matchesWorkflowType(array $supported, mixed $workflowType): bool
    {
        if ($supported === []) {
            return true;
        }

        if (! is_string($workflowType) || trim($workflowType) === '') {
            return false;
        }

        return in_array(trim($workflowType), $supported, true);
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
                ? ['codec' => $claim['payload_codec'] ?? CodecRegistry::defaultCodec(), 'blob' => $history['arguments']]
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

        $payload = array_merge($payload, $this->workflowTaskResumeContext((string) $claim['task_id']));

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
     * Expose only stable resume-source fields from the package task payload.
     *
     * These fields tell external workers whether a leased workflow task is
     * applying an accepted update, signal, child resolution, or timer-backed
     * wait without leaking arbitrary internal payload values.
     *
     * @return array<string, mixed>
     */
    private function workflowTaskResumeContext(string $taskId): array
    {
        $context = [
            'workflow_wait_kind' => null,
            'open_wait_id' => null,
            'resume_source_kind' => null,
            'resume_source_id' => null,
            'workflow_update_id' => null,
            'workflow_signal_id' => null,
            'signal_name' => null,
            'signal_wait_id' => null,
            'workflow_command_id' => null,
            'activity_execution_id' => null,
            'activity_attempt_id' => null,
            'activity_type' => null,
            'child_call_id' => null,
            'child_workflow_run_id' => null,
            'workflow_sequence' => null,
            'workflow_event_type' => null,
            'timer_id' => null,
            'condition_wait_id' => null,
            'condition_key' => null,
            'condition_definition_fingerprint' => null,
        ];

        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()->find($taskId);
        $payload = $task?->payload;

        if (! is_array($payload)) {
            return $context;
        }

        foreach ($context as $field => $_) {
            $value = $payload[$field] ?? null;

            if ($field === 'workflow_sequence') {
                $context[$field] = is_int($value) ? $value : null;

                continue;
            }

            $context[$field] = $this->nonEmptyString($value);
        }

        return $context;
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

    /**
     * Reorder the candidate batch so that, within each priority tier,
     * dispatch is rebalanced across distinct fairness-key classes. The
     * fairness scheduler is a no-op when the batch has zero or one
     * candidate (or only one fairness class is present), so the common
     * case carries no extra cost.
     *
     * @param  list<array<string, mixed>>  $readyTasks
     * @return list<array<string, mixed>>
     */
    private function reorderForFairness(string $taskQueue, array $readyTasks): array
    {
        if (count($readyTasks) <= 1) {
            return $readyTasks;
        }

        $scheduler = new TaskFairnessScheduler($this->fairnessState);

        return $scheduler->reorder(
            TaskQueuePriorityFairnessSurface::BUCKET_WORKFLOW_TASK,
            $taskQueue,
            $readyTasks,
        );
    }

    /**
     * Record a successful workflow-task dispatch against the shared
     * fairness state. The bucket isolates workflow-task counters from
     * activity-task counters so the two surfaces stay independent.
     *
     * @param  array<string, mixed>  $task
     */
    private function recordFairnessDispatch(string $taskQueue, array $task): void
    {
        $class = TaskFairnessKey::classFor(
            isset($task['fairness_key']) && is_string($task['fairness_key']) && $task['fairness_key'] !== ''
                ? $task['fairness_key']
                : null,
        );
        $weight = isset($task['fairness_weight']) && is_int($task['fairness_weight']) && $task['fairness_weight'] >= 1
            ? $task['fairness_weight']
            : 1;

        $this->fairnessState->recordDispatch(
            TaskQueuePriorityFairnessSurface::BUCKET_WORKFLOW_TASK,
            $taskQueue,
            $class,
            $weight,
        );
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function defaultPollStatus(?array $task): string
    {
        return is_array($task) ? 'leased' : 'empty';
    }

    private function emptyPollStatus(string $namespace, string $taskQueue, string $taskKind): string
    {
        $status = $this->admission->budget($namespace, $taskQueue, $taskKind)['status'] ?? null;

        return in_array($status, ['throttled', 'unavailable'], true)
            ? $status
            : 'empty';
    }
}
