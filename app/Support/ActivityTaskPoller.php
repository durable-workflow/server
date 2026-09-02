<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskFairnessScheduler;
use Workflow\V2\Support\TaskFairnessState;

final class ActivityTaskPoller
{
    public function __construct(
        private readonly LongPoller $longPoller,
        private readonly ActivityTaskBridgeContract $bridge,
        private readonly ActivityTaskPollRequestStore $pollRequests,
        private readonly LongPollSignalStore $signals,
        private readonly TaskQueueAdmission $admission,
        private readonly WorkerSessionRegistry $workerSessions,
        private readonly TaskFairnessState $fairnessState,
        private readonly WorkerPollClaimGate $claimGate,
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly ServerPollingCache $cache,
        private readonly PollRequestLeaseBinding $pollLeaseBindings,
    ) {}

    /**
     * @param  list<string>  $supportedActivityTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    public function poll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        ?string $pollRequestId = null,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
        ?int $timeoutSeconds = null,
    ): array {
        $pollRequestId = $this->nonEmptyString($pollRequestId);

        if (! WorkerPollFence::isFresh($worker)) {
            return [
                'task' => null,
                'poll_status' => 'stale_worker_registration',
            ];
        }

        if ($pollRequestId !== null) {
            $task = $this->activeLeasedTaskForWorker(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                worker: $worker,
                pollRequestId: $pollRequestId,
                supportedActivityTypes: $supportedActivityTypes,
                workerSessionsAvailable: $workerSessionsAvailable,
            );

            if (is_array($task)) {
                return [
                    'task' => $task,
                    'poll_status' => 'leased',
                ];
            }
        }

        if ($pollRequestId === null || ! $this->cache->available()) {
            return $this->performPoll(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                worker: $worker,
                pollRequestId: $pollRequestId,
                supportedActivityTypes: $supportedActivityTypes,
                workerSessionsAvailable: $workerSessionsAvailable,
                timeoutSeconds: $timeoutSeconds,
            );
        }

        return $this->coordinatedPoll(
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            worker: $worker,
            pollRequestId: $pollRequestId,
            supportedActivityTypes: $supportedActivityTypes,
            workerSessionsAvailable: $workerSessionsAvailable,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    /**
     * @param  list<string>  $supportedActivityTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function coordinatedPoll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        string $pollRequestId,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
        ?int $timeoutSeconds = null,
    ): array {
        $workerPollFence = WorkerPollFence::snapshot($worker);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (! WorkerPollFence::isCurrent($workerPollFence)) {
                return [
                    'task' => null,
                    'poll_status' => 'stale_worker_registration',
                ];
            }

            $cached = $this->cachedPollResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
                $workerSessionsAvailable,
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
                    namespace: $namespace,
                    taskQueue: $taskQueue,
                    leaseOwner: $leaseOwner,
                    buildId: $buildId,
                    worker: $worker,
                    pollRequestId: $pollRequestId,
                    supportedActivityTypes: $supportedActivityTypes,
                    workerSessionsAvailable: $workerSessionsAvailable,
                    timeoutSeconds: $timeoutSeconds,
                );
            }

            $observed = $this->pollRequests->waitForResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
            );
            $observed = $this->revalidatedPollResult(
                namespace: $namespace,
                taskQueue: $taskQueue,
                buildId: $buildId,
                leaseOwner: $leaseOwner,
                pollRequestId: $pollRequestId,
                result: $observed,
                workerSessionsAvailable: $workerSessionsAvailable,
            );

            if ($observed['resolved']) {
                if (! WorkerPollFence::isCurrent($workerPollFence)) {
                    return [
                        'task' => null,
                        'poll_status' => 'stale_worker_registration',
                    ];
                }

                return [
                    'task' => $observed['task'],
                    'poll_status' => $observed['poll_status'] ?? $this->defaultPollStatus($observed['task']),
                ];
            }
        }

        if (! WorkerPollFence::isCurrent($workerPollFence)) {
            return [
                'task' => null,
                'poll_status' => 'stale_worker_registration',
            ];
        }

        $cached = $this->cachedPollResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            $workerSessionsAvailable,
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
        bool $workerSessionsAvailable = true,
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

        return $this->revalidatedPollResult(
            namespace: $namespace,
            taskQueue: $taskQueue,
            buildId: $buildId,
            leaseOwner: $leaseOwner,
            pollRequestId: $pollRequestId,
            result: $cached,
            workerSessionsAvailable: $workerSessionsAvailable,
        );
    }

    /**
     * @param  array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}  $result
     * @return array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}
     */
    private function revalidatedPollResult(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        array $result,
        bool $workerSessionsAvailable = true,
    ): array {
        if (! $result['resolved']) {
            return $result;
        }

        if ($this->cachedTaskStillDeliverable(
            namespace: $namespace,
            taskQueue: $taskQueue,
            buildId: $buildId,
            leaseOwner: $leaseOwner,
            task: $result['task'],
            workerSessionsAvailable: $workerSessionsAvailable,
        )) {
            $refreshedTask = $this->refreshCachedTaskPayload($result['task']);

            if ($refreshedTask !== $result['task']) {
                $this->pollRequests->rememberResult(
                    $namespace,
                    $taskQueue,
                    $buildId,
                    $leaseOwner,
                    $pollRequestId,
                    $refreshedTask,
                    $result['poll_status'] ?? $this->defaultPollStatus($refreshedTask),
                );
            }

            return [
                'resolved' => true,
                'task' => $refreshedTask,
                'poll_status' => $result['poll_status'] ?? $this->defaultPollStatus($refreshedTask),
            ];
        }

        if (
            ! $workerSessionsAvailable
            && $this->cachedTaskStillDeliverable(
                namespace: $namespace,
                taskQueue: $taskQueue,
                buildId: $buildId,
                leaseOwner: $leaseOwner,
                task: $result['task'],
                workerSessionsAvailable: true,
            )
            && $this->cachedTaskRequiresWorkerSession($result['task'])
        ) {
            $this->ensurePollResultRecorded(
                namespace: $namespace,
                taskQueue: $taskQueue,
                buildId: $buildId,
                leaseOwner: $leaseOwner,
                pollRequestId: $pollRequestId,
                result: $result,
            );

            return [
                'resolved' => true,
                'task' => null,
                'poll_status' => 'empty',
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
     * Preserve a capable leader's session-bound result when an incapable
     * duplicate waiter observes it through waitForResult().
     *
     * @param  array{resolved: bool, task: array<string, mixed>|null, poll_status: string|null}  $result
     */
    private function ensurePollResultRecorded(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        string $pollRequestId,
        array $result,
    ): void {
        $this->pollRequests->rememberResult(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            $result['task'],
            $result['poll_status'] ?? $this->defaultPollStatus($result['task']),
        );
    }

    /**
     * @param  list<string>  $supportedActivityTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function runCoordinatedPollLeader(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        string $pollRequestId,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
        ?int $timeoutSeconds = null,
    ): array {
        try {
            $task = $this->performPoll(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                worker: $worker,
                pollRequestId: $pollRequestId,
                supportedActivityTypes: $supportedActivityTypes,
                workerSessionsAvailable: $workerSessionsAvailable,
                timeoutSeconds: $timeoutSeconds,
            );
        } catch (\Throwable $exception) {
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
     * @param  list<string>  $supportedActivityTypes
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function performPoll(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        ?string $pollRequestId = null,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
        ?int $timeoutSeconds = null,
    ): array {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $nextProbeAt = null;
        $resolvedResult = [
            'task' => null,
            'poll_status' => 'empty',
            'next_probe_at' => null,
        ];
        $workerPollFence = WorkerPollFence::snapshot($worker);

        $pollResult = $this->longPoller->until(
            function () use (
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $worker,
                $pollRequestId,
                $supportedActivityTypes,
                $workerSessionsAvailable,
                $limit,
                $workerPollFence,
                &$nextProbeAt,
                &$resolvedResult,
            ): ?array {
                if (! WorkerPollFence::isCurrent($workerPollFence)) {
                    $resolvedResult = [
                        'task' => null,
                        'poll_status' => 'stale_worker_registration',
                        'next_probe_at' => null,
                    ];

                    return $resolvedResult;
                }

                $resolvedResult = $this->nextTask(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $worker,
                    $limit,
                    $pollRequestId,
                    $supportedActivityTypes,
                    $workerSessionsAvailable,
                    $workerPollFence,
                );
                $nextProbeAt = $resolvedResult['next_probe_at'] ?? null;

                if (in_array(
                    $resolvedResult['poll_status'] ?? null,
                    ['query_task_pending', 'stale_worker_registration'],
                    true,
                )) {
                    return $resolvedResult;
                }

                return $resolvedResult['task'] ?? null;
            },
            static fn (?array $result): bool => is_array($result),
            timeoutSeconds: $timeoutSeconds,
            wakeChannels: [
                ...$this->signals->activityTaskPollChannels($namespace, null, $taskQueue),
                ...$this->signals->queryTaskPollChannels($namespace, $taskQueue),
            ],
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
            reserveWorkerWaitSlot: true,
            waitSlotNamespace: $namespace,
        );

        if (in_array(
            $pollResult['poll_status'] ?? null,
            ['query_task_pending', 'stale_worker_registration'],
            true,
        )) {
            return [
                'task' => null,
                'poll_status' => (string) $pollResult['poll_status'],
            ];
        }

        return [
            'task' => $pollResult,
            'poll_status' => $resolvedResult['poll_status'] ?? $this->defaultPollStatus($pollResult),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function cachedTaskStillDeliverable(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $leaseOwner,
        ?array $task,
        bool $workerSessionsAvailable = true,
    ): bool {
        if ($task === null) {
            return true;
        }

        $taskId = $this->nonEmptyString($task['task_id'] ?? null);
        $activityAttemptId = $this->nonEmptyString($task['activity_attempt_id'] ?? null);

        if ($taskId === null || $activityAttemptId === null) {
            return false;
        }

        $workflowTask = NamespaceWorkflowScope::task($namespace, $taskId);

        if (! $workflowTask instanceof WorkflowTask || $workflowTask->task_type !== TaskType::Activity) {
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

        $attempt = ActivityAttempt::query()->find($activityAttemptId);

        if (! $attempt instanceof ActivityAttempt) {
            return false;
        }

        if ($attempt->workflow_task_id !== $workflowTask->id) {
            return false;
        }

        if ($this->nonEmptyString($attempt->lease_owner) !== $leaseOwner) {
            return false;
        }

        if ($attempt->status !== ActivityAttemptStatus::Running) {
            return false;
        }

        if ($attempt->closed_at !== null) {
            return false;
        }

        if (
            ! $workerSessionsAvailable
            && $this->workerSessions->optionsForExecution($attempt->activity_execution_id) !== null
        ) {
            return false;
        }

        if ($attempt->lease_expires_at === null || $attempt->lease_expires_at->lte(now())) {
            return false;
        }

        $attemptNumber = is_numeric($task['attempt_number'] ?? null)
            ? (int) $task['attempt_number']
            : null;

        if (
            $attemptNumber !== null
            && is_int($attempt->attempt_number)
            && (int) $attempt->attempt_number !== $attemptNumber
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $task
     */
    private function cachedTaskRequiresWorkerSession(?array $task): bool
    {
        if ($task === null) {
            return false;
        }

        $executionId = $this->nonEmptyString($task['activity_execution_id'] ?? null);

        if ($executionId === null) {
            $attemptId = $this->nonEmptyString($task['activity_attempt_id'] ?? null);
            $attempt = $attemptId === null ? null : ActivityAttempt::query()->find($attemptId);
            $executionId = $attempt instanceof ActivityAttempt
                ? $this->nonEmptyString($attempt->activity_execution_id)
                : null;
        }

        return $this->workerSessions->optionsForExecution($executionId) !== null;
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @return array<string, mixed>|null
     */
    private function refreshCachedTaskPayload(?array $task): ?array
    {
        if (! is_array($task)) {
            return $task;
        }

        $taskId = $this->nonEmptyString($task['task_id'] ?? null);
        $leaseOwner = $this->nonEmptyString($task['lease_owner'] ?? null);

        if ($taskId === null || $leaseOwner === null) {
            return $task;
        }

        $refreshed = $this->rawActivityClaimPayload($taskId, $leaseOwner);

        return is_array($refreshed) ? $refreshed : $task;
    }

    /**
     * @param  list<string>  $supportedActivityTypes
     * @return array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}
     */
    private function nextTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        int $limit,
        ?string $pollRequestId = null,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
        array $workerPollFence = [],
    ): array {
        return $this->withWorkerCompatibility(
            $namespace,
            $buildId,
            function () use (
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $worker,
                $limit,
                $pollRequestId,
                $supportedActivityTypes,
                $workerSessionsAvailable,
                $workerPollFence,
            ): array {
                if ($pollRequestId !== null) {
                    $task = $this->activeLeasedTaskForWorker(
                        namespace: $namespace,
                        taskQueue: $taskQueue,
                        leaseOwner: $leaseOwner,
                        buildId: $buildId,
                        worker: $worker,
                        pollRequestId: $pollRequestId,
                        supportedActivityTypes: $supportedActivityTypes,
                        workerSessionsAvailable: $workerSessionsAvailable,
                    );

                    if (is_array($task)) {
                        return [
                            'task' => $task,
                            'poll_status' => 'leased',
                            'next_probe_at' => null,
                        ];
                    }
                }

                try {
                    $task = $this->admission->withLeaseAdmission(
                        $namespace,
                        $taskQueue,
                        TaskQueueAdmission::ACTIVITY_TASKS,
                        fn (): ?array => $this->claimGate->forSqliteClaim(
                            $namespace,
                            $taskQueue,
                            TaskQueueAdmission::ACTIVITY_TASKS,
                            fn (): ?array => $this->claimReadyTask(
                                $namespace,
                                $taskQueue,
                                $leaseOwner,
                                $buildId,
                                $worker,
                                $limit,
                                $pollRequestId,
                                $supportedActivityTypes,
                                $workerSessionsAvailable,
                                $workerPollFence,
                            ),
                        ),
                    );
                } catch (PollRequestAlreadyBound) {
                    $task = $pollRequestId === null
                        ? null
                        : $this->activeLeasedTaskForWorker(
                            namespace: $namespace,
                            taskQueue: $taskQueue,
                            leaseOwner: $leaseOwner,
                            buildId: $buildId,
                            worker: $worker,
                            pollRequestId: $pollRequestId,
                            supportedActivityTypes: $supportedActivityTypes,
                            workerSessionsAvailable: $workerSessionsAvailable,
                        );
                }

                if (
                    $task === null
                    && $this->cache->available()
                    && $this->queryTasks->hasPendingTaskForPoller(
                        $namespace,
                        $taskQueue,
                        $this->stringList($worker->supported_workflow_types ?? []),
                        $buildId,
                    )
                ) {
                    return [
                        'task' => null,
                        'poll_status' => 'query_task_pending',
                        'next_probe_at' => null,
                    ];
                }

                return [
                    'task' => $task,
                    'poll_status' => is_array($task)
                        ? 'leased'
                        : $this->emptyPollStatus($namespace, $taskQueue, TaskQueueAdmission::ACTIVITY_TASKS),
                    'next_probe_at' => $task === null
                        ? $this->nextVisibleReadyAt($namespace, $taskQueue, $buildId)
                        : null,
                ];
            },
        );
    }

    /**
     * Claim the first available activity task by delegating bulk filtering
     * (availability, compatibility, activity-type) to the bridge's poll
     * query and claim validation to ActivityTaskClaimer (via
     * bridge->claimStatus). The poller still re-checks activity_type
     * against the worker's registered list on each ready task — an
     * authoritative app-level guard that holds the polyglot routing
     * contract even if the bridge filter ever loosens.
     *
     * @param  list<string>  $supportedActivityTypes
     */
    private function claimReadyTask(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        int $limit,
        ?string $pollRequestId = null,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
        array $workerPollFence = [],
    ): ?array {
        $readyTasks = $this->bridge->poll(
            connection: null,
            queue: $taskQueue,
            limit: $limit,
            compatibility: $buildId,
            namespace: $namespace,
            activityTypes: $supportedActivityTypes,
        );

        // The activity bridge owns ready-task discovery, including the
        // activity-type predicate for typed polls. The server keeps only
        // post-poll guards, worker-session admission, fairness, and
        // claiming so shared-queue routing has one SQL source of truth.
        //
        // Apply the fairness reorder pass: within a priority tier the
        // batch is rebalanced across distinct fairness-key classes so a
        // single noisy class can't starve its peers under saturation.
        // Priority order is preserved across tiers — urgent work always
        // leads — and tasks without a fairness key share an implicit
        // default class so unmarked tenants are never crowded out.
        $readyTasks = $this->reorderForFairness($taskQueue, $readyTasks);

        foreach ($readyTasks as $readyTask) {
            $taskId = is_string($readyTask['task_id'] ?? null)
                ? $readyTask['task_id']
                : null;

            if ($taskId === null) {
                continue;
            }

            if (! $this->matchesActivityType($supportedActivityTypes, $readyTask['activity_type'] ?? null)) {
                // Authoritative routing on the execution's stored
                // activity_type: the bridge poll filters at the SQL
                // level, but the server's claim loop must independently
                // re-check the type against the worker's registered
                // list before leasing. A worker that did not advertise
                // this activity type at register time must never claim
                // it, even if the bridge ever returns one (stale index,
                // relaxed predicate, future bridge change).
                continue;
            }

            $workerSession = $this->workerSessions->optionsForExecution(
                is_string($readyTask['activity_execution_id'] ?? null)
                    ? $readyTask['activity_execution_id']
                    : null,
            );

            if (
                $workerSession !== null
                && (
                    ! $workerSessionsAvailable
                    || ! $this->workerCanSatisfySession($worker, $workerSession)
                )
            ) {
                continue;
            }

            try {
                $claim = DB::transaction(function () use (
                    $namespace,
                    $worker,
                    $taskId,
                    $leaseOwner,
                    $workerSessionsAvailable,
                    $workerPollFence,
                    $pollRequestId,
                    $taskQueue,
                    $buildId,
                ): ?array {
                    if ($workerPollFence !== [] && ! WorkerPollFence::isCurrentForUpdate($workerPollFence)) {
                        throw new ActivityTaskClaimRolledBack;
                    }

                    $this->pollLeaseBindings->ensureUnbound(
                        $namespace,
                        TaskType::Activity,
                        $taskQueue,
                        $leaseOwner,
                        $buildId,
                        $pollRequestId,
                    );

                    $claim = $this->claimStatus($taskId, $leaseOwner);

                    if (($claim['claimed'] ?? false) !== true) {
                        return null;
                    }

                    $workerSession = $this->workerSessions->optionsForExecution(
                        is_string($claim['activity_execution_id'] ?? null)
                            ? $claim['activity_execution_id']
                            : null,
                    );

                    if ($workerSession !== null) {
                        if (! $workerSessionsAvailable) {
                            throw new ActivityTaskClaimRolledBack;
                        }

                        $admission = $this->workerSessions->admitActivity(
                            $namespace,
                            $worker,
                            $workerSession,
                            $taskId,
                        );

                        if (($admission['admitted'] ?? false) !== true) {
                            throw new ActivityTaskClaimRolledBack;
                        }
                    }

                    $this->pollLeaseBindings->bindClaimedTask(
                        $namespace,
                        $taskId,
                        $leaseOwner,
                        $pollRequestId,
                    );

                    return $claim;
                });
            } catch (ActivityTaskClaimRolledBack) {
                $claim = null;
            }

            if ($claim !== null) {
                // Record the dispatch against the shared fairness state
                // so future polls see the deficit and continue
                // rebalancing toward under-served classes. Activity
                // tasks keep a separate fairness bucket from workflow
                // tasks on the same queue.
                $this->recordFairnessDispatch($taskQueue, $readyTask);

                return $claim;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimStatus(string $taskId, string $leaseOwner): ?array
    {
        return $this->bridge->claimStatus($taskId, $leaseOwner);
    }

    /**
     * Reconstruct a successful claim response when the package bridge leased
     * the task but could not build a locally-decodable payload envelope.
     *
     * @return array<string, mixed>|null
     */
    private function rawActivityClaimPayload(string $taskId, string $leaseOwner): ?array
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()->find($taskId);

        if (
            $task === null
            || $task->task_type !== TaskType::Activity
            || $task->status !== TaskStatus::Leased
            || $task->lease_owner !== $leaseOwner
            || $task->lease_expires_at === null
            || $task->lease_expires_at->lte(now())
        ) {
            return null;
        }

        $executionId = is_array($task->payload ?? null)
            ? ($task->payload['activity_execution_id'] ?? null)
            : null;

        /** @var ActivityExecution|null $execution */
        $execution = is_string($executionId) && $executionId !== ''
            ? ActivityExecution::query()->find($executionId)
            : null;

        if (! $execution instanceof ActivityExecution) {
            return null;
        }

        $payload = $this->activeActivityClaimPayload($task, $execution, $leaseOwner);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Rebuild the live activity lease assigned to this logical poll.
     *
     * @param  list<string>  $supportedActivityTypes
     * @return array<string, mixed>|null
     */
    private function activeLeasedTaskForWorker(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        string $pollRequestId,
        array $supportedActivityTypes = [],
        bool $workerSessionsAvailable = true,
    ): ?array {
        $tasks = collect(array_filter([
            $this->pollLeaseBindings->activeTask(
                $namespace,
                TaskType::Activity,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $pollRequestId,
            ),
        ]));

        foreach ($tasks as $task) {
            if (! $task instanceof WorkflowTask || ! $this->matchesCompatibility($buildId, $task->compatibility)) {
                continue;
            }

            $executionId = is_array($task->payload ?? null)
                ? ($task->payload['activity_execution_id'] ?? null)
                : null;

            /** @var ActivityExecution|null $execution */
            $execution = is_string($executionId) && $executionId !== ''
                ? ActivityExecution::query()->find($executionId)
                : null;

            if (! $execution instanceof ActivityExecution) {
                continue;
            }

            if (! $this->matchesActivityType($supportedActivityTypes, $execution->activity_type)) {
                continue;
            }

            $workerSession = $this->workerSessions->optionsForExecution($execution->id);

            if (
                $workerSession !== null
                && (
                    ! $workerSessionsAvailable
                    || ! $this->workerCanSatisfySession($worker, $workerSession)
                )
            ) {
                continue;
            }

            $payload = $this->activeActivityClaimPayload($task, $execution, $leaseOwner);

            if (is_array($payload)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeActivityClaimPayload(
        WorkflowTask $task,
        ActivityExecution $execution,
        string $leaseOwner,
    ): ?array {
        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->find($execution->workflow_run_id);

        if (! $run instanceof WorkflowRun) {
            return null;
        }

        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->where('workflow_task_id', $task->id)
            ->where('activity_execution_id', $execution->id)
            ->where('lease_owner', $leaseOwner)
            ->where('status', ActivityAttemptStatus::Running->value)
            ->whereNull('closed_at')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '>', now())
            ->latest('attempt_number')
            ->first();

        if (! $attempt instanceof ActivityAttempt) {
            return null;
        }

        if (
            is_int($task->attempt_count)
            && (int) $task->attempt_count > 0
            && (int) $task->attempt_count !== (int) $attempt->attempt_number
        ) {
            return null;
        }

        return [
            'claimed' => true,
            'task_id' => $task->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt->id,
            'attempt_number' => max(1, (int) $attempt->attempt_number),
            'activity_type' => $this->nonEmptyString($execution->activity_type),
            'activity_class' => $this->nonEmptyString($execution->activity_class),
            'idempotency_key' => $execution->id,
            'payload_codec' => PayloadCodecContract::canonicalize($execution->payload_codec ?? $run->payload_codec),
            'arguments' => $this->nonEmptyString($execution->arguments),
            'retry_policy' => is_array($execution->retry_policy) ? $execution->retry_policy : null,
            'connection' => $this->nonEmptyString($execution->connection),
            'queue' => $this->nonEmptyString($execution->queue),
            'lease_owner' => $this->nonEmptyString($task->lease_owner),
            'lease_expires_at' => $task->lease_expires_at?->toJSON(),
            'reason' => null,
            'reason_detail' => null,
            'retry_after_seconds' => null,
            'backend_error' => null,
            'compatibility_reason' => null,
        ];
    }

    /**
     * Reorder the candidate batch so that, within each priority tier,
     * dispatch is rebalanced across distinct fairness-key classes. The
     * scheduler is a no-op when the batch has zero or one candidate
     * (or only one class is present), so the common case is free.
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
            TaskQueuePriorityFairnessSurface::BUCKET_ACTIVITY_TASK,
            $taskQueue,
            $readyTasks,
        );
    }

    /**
     * Record a successful activity-task dispatch against the shared
     * fairness state. The bucket isolates activity-task counters from
     * workflow-task counters so the two surfaces stay independent.
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
            TaskQueuePriorityFairnessSurface::BUCKET_ACTIVITY_TASK,
            $taskQueue,
            $class,
            $weight,
        );
    }

    /**
     * Compare the worker's registered activity types against the
     * execution's stored activity_type. The match is exact-string —
     * no class-resolution or canonicalization. Workers that registered
     * an empty list are short-circuited at the controller, so an empty
     * $supported here means "no capability filter requested by this
     * caller" and the task is allowed through.
     *
     * @param  list<string>  $supported
     */
    private function matchesActivityType(array $supported, mixed $activityType): bool
    {
        if ($supported === []) {
            return true;
        }

        if (! is_string($activityType) || trim($activityType) === '') {
            return false;
        }

        return in_array(trim($activityType), $supported, true);
    }

    private function matchesCompatibility(?string $buildId, mixed $compatibility): bool
    {
        if (! is_string($compatibility) || trim($compatibility) === '') {
            return true;
        }

        return $buildId !== null && $compatibility === $buildId;
    }

    private function applyWorkerCompatibility(string $namespace, ?string $buildId): void
    {
        config([
            'workflows.v2.compatibility.namespace' => $namespace,
            'workflows.v2.compatibility.current' => $buildId,
            'workflows.v2.compatibility.supported' => $buildId === null ? [] : [$buildId],
        ]);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withWorkerCompatibility(string $namespace, ?string $buildId, callable $callback): mixed
    {
        $previous = [
            'namespace' => config('workflows.v2.compatibility.namespace'),
            'current' => config('workflows.v2.compatibility.current'),
            'supported' => config('workflows.v2.compatibility.supported'),
        ];

        $this->applyWorkerCompatibility($namespace, $buildId);

        try {
            return $callback();
        } finally {
            config([
                'workflows.v2.compatibility.namespace' => $previous['namespace'],
                'workflows.v2.compatibility.current' => $previous['current'],
                'workflows.v2.compatibility.supported' => $previous['supported'],
            ]);
        }
    }

    private function nextVisibleReadyAt(string $namespace, string $taskQueue, ?string $buildId): ?\DateTimeInterface
    {
        $query = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Activity->value)
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

    /**
     * @param  array<string, mixed>  $workerSession
     */
    private function workerCanSatisfySession(WorkerRegistration $worker, array $workerSession): bool
    {
        $queue = $this->nonEmptyString($workerSession['queue'] ?? null);

        if ($queue !== null && $worker->task_queue !== $queue) {
            return false;
        }

        $requirements = $this->stringList($workerSession['requirements'] ?? []);

        if ($requirements === []) {
            return true;
        }

        $capabilities = array_flip($this->stringList($worker->capabilities ?? []));

        foreach ($requirements as $requirement) {
            if (! isset($capabilities[$requirement])) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $item): ?string => is_string($item) && trim($item) !== ''
                    ? trim($item)
                    : null,
                $value,
            ),
            static fn (?string $item): bool => $item !== null,
        ));
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
