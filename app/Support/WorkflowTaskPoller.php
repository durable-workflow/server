<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\HistoryPayloadCompression;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskFairnessScheduler;
use Workflow\V2\Support\TaskFairnessState;
use Workflow\V2\Support\WorkerCompatibilityFleet;

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
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
        private readonly WorkerPollClaimGate $claimGate,
        private readonly WorkflowQueryTaskBroker $queryTasks,
    ) {}

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    public function poll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        ?string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
        array $workflowDefinitionFingerprints = [],
        bool $acceptsQueryTasks = false,
        ?int $timeoutSeconds = null,
    ): array {
        $pollRequestId = $this->nonEmptyString($pollRequestId);

        if ($pollRequestId === null) {
            return $this->performPoll(
                request: $request,
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                worker: $worker,
                pollRequestId: null,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
                workflowDefinitionFingerprints: $workflowDefinitionFingerprints,
                acceptsQueryTasks: $acceptsQueryTasks,
                timeoutSeconds: $timeoutSeconds,
            );
        }

        return $this->coordinatedPoll(
            request: $request,
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            worker: $worker,
            pollRequestId: $pollRequestId,
            historyPageSize: $historyPageSize,
            acceptHistoryEncoding: $acceptHistoryEncoding,
            supportedWorkflowTypes: $supportedWorkflowTypes,
            workflowDefinitionFingerprints: $workflowDefinitionFingerprints,
            acceptsQueryTasks: $acceptsQueryTasks,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function coordinatedPoll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
        array $workflowDefinitionFingerprints = [],
        bool $acceptsQueryTasks = false,
        ?int $timeoutSeconds = null,
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
                    worker: $worker,
                    pollRequestId: $pollRequestId,
                    historyPageSize: $historyPageSize,
                    acceptHistoryEncoding: $acceptHistoryEncoding,
                    supportedWorkflowTypes: $supportedWorkflowTypes,
                    workflowDefinitionFingerprints: $workflowDefinitionFingerprints,
                    acceptsQueryTasks: $acceptsQueryTasks,
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
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function runCoordinatedPollLeader(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
        array $workflowDefinitionFingerprints = [],
        bool $acceptsQueryTasks = false,
        ?int $timeoutSeconds = null,
    ): array {
        try {
            $task = $this->performPoll(
                request: $request,
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                worker: $worker,
                pollRequestId: $pollRequestId,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
                workflowDefinitionFingerprints: $workflowDefinitionFingerprints,
                acceptsQueryTasks: $acceptsQueryTasks,
                timeoutSeconds: $timeoutSeconds,
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
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string}
     */
    private function performPoll(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        ?string $pollRequestId,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
        array $workflowDefinitionFingerprints = [],
        bool $acceptsQueryTasks = false,
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
        $supportsQueryTasks = $this->queryTasks->workerSupportsQueryTasks($namespace, $worker);

        $pollResult = $this->longPoller->until(
            function () use (
                $request,
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $historyPageSize,
                $acceptHistoryEncoding,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $acceptsQueryTasks,
                $supportsQueryTasks,
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
                    $request,
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $limit,
                    $historyPageSize,
                    $acceptHistoryEncoding,
                    $supportedWorkflowTypes,
                    $workflowDefinitionFingerprints,
                    $acceptsQueryTasks,
                    $supportsQueryTasks,
                    $workerPollFence,
                );
                $nextProbeAt = $resolvedResult['next_probe_at'] ?? null;

                if (in_array(
                    $resolvedResult['poll_status'] ?? null,
                    ['query_task_pending', 'compatibility_blocked', 'no_compatible_worker', 'stale_worker_registration'],
                    true,
                )) {
                    return $resolvedResult;
                }

                return $resolvedResult['task'] ?? null;
            },
            static fn (?array $result): bool => is_array($result),
            timeoutSeconds: $timeoutSeconds,
            wakeChannels: [
                ...$this->signals->workflowTaskPollChannels($namespace, null, $taskQueue),
                ...$this->signals->queryTaskPollChannels($namespace, $taskQueue),
            ],
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
            reserveWorkerWaitSlot: true,
        );

        if (in_array(
            $pollResult['poll_status'] ?? null,
            ['query_task_pending', 'compatibility_blocked', 'no_compatible_worker', 'stale_worker_registration'],
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
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
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
        array $workflowDefinitionFingerprints = [],
        bool $acceptsQueryTasks = false,
        bool $supportsQueryTasks = false,
        array $workerPollFence = [],
    ): array {
        return $this->withWorkerCompatibility(
            $namespace,
            $buildId,
            function () use (
                $request,
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $limit,
                $historyPageSize,
                $acceptHistoryEncoding,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $acceptsQueryTasks,
                $supportsQueryTasks,
                $workerPollFence,
            ): array {
                $this->runDueServiceModeTimers($namespace, $taskQueue, $buildId);

                // A registered query-capable worker must let pending queries
                // preempt ready workflow tasks even before its first query-poll
                // marker is current; otherwise an initial query can sit behind
                // the just-started workflow task.
                if ($this->queryTasks->hasClaimablePendingTaskForPoller(
                    $namespace,
                    $taskQueue,
                    $supportedWorkflowTypes,
                    $buildId,
                    $acceptsQueryTasks || $supportsQueryTasks,
                    $workflowDefinitionFingerprints,
                    $leaseOwner,
                )) {
                    return [
                        'task' => null,
                        'poll_status' => 'query_task_pending',
                        'next_probe_at' => null,
                    ];
                }

                if (
                    $supportsQueryTasks
                    && $this->queryTasks->hasPendingTaskForActiveWorkflowLeaseOwner(
                        $namespace,
                        $taskQueue,
                        $supportedWorkflowTypes,
                        $buildId,
                        $workflowDefinitionFingerprints,
                        $leaseOwner,
                    )
                ) {
                    return [
                        'task' => null,
                        'poll_status' => 'query_task_pending',
                        'next_probe_at' => null,
                    ];
                }

                $task = $this->admission->withLeaseAdmission(
                    $namespace,
                    $taskQueue,
                    TaskQueueAdmission::WORKFLOW_TASKS,
                    fn (): ?array => $this->claimGate->forSqliteClaim(
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
                            workerPollFence: $workerPollFence,
                        ),
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
                        fn (): ?array => $this->claimGate->forSqliteClaim(
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
                                workerPollFence: $workerPollFence,
                            ),
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

                if ($this->queryTasks->hasPendingTaskForPoller($namespace, $taskQueue, $supportedWorkflowTypes, $buildId)) {
                    return [
                        'task' => null,
                        'poll_status' => 'query_task_pending',
                        'next_probe_at' => null,
                    ];
                }

                return [
                    'task' => null,
                    'poll_status' => $this->emptyPollStatus(
                        $namespace,
                        $taskQueue,
                        TaskQueueAdmission::WORKFLOW_TASKS,
                        $buildId,
                        $supportedWorkflowTypes,
                    ),
                    'next_probe_at' => $this->nextVisibleReadyOrTimerAt($namespace, $taskQueue, $buildId),
                ];
            },
        );
    }

    private function runDueServiceModeTimers(string $namespace, string $taskQueue, ?string $buildId): void
    {
        if (
            config('server.mode') !== 'service'
            || config('workflows.v2.task_dispatch_mode') !== 'poll'
        ) {
            return;
        }

        $limit = max(1, (int) config('server.polling.due_timer_recovery_scan_limit', 5));
        $availabilityCutoff = now()
            ->addSeconds(DefaultWorkflowTaskBridge::AVAILABILITY_CEILING_SECONDS);

        $timerTasks = NamespaceWorkflowScope::taskQuery($namespace)
            ->select('workflow_tasks.*')
            ->leftJoin('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->where('workflow_tasks.task_type', TaskType::Timer->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where(function ($builder) use ($availabilityCutoff): void {
                $builder->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $availabilityCutoff);
            })
            ->where(function ($builder) use ($buildId): void {
                $this->whereEffectiveCompatibilityMatches($builder, $buildId);
            })
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id')
            ->limit($limit)
            ->get();

        foreach ($timerTasks as $timerTask) {
            $timerTaskId = is_string($timerTask->id) ? $timerTask->id : null;

            if ($timerTaskId === null || $timerTaskId === '') {
                continue;
            }

            // The query uses the same availability tolerance as workflow
            // polling to survive backend timestamp precision drift. The
            // in-memory check keeps timers from firing before their durable
            // fire time when the tolerance only found a near-future row.
            if ($timerTask->available_at instanceof \DateTimeInterface && $timerTask->available_at > now()) {
                continue;
            }

            try {
                app()->call([new RunTimerTask($timerTaskId), 'handle']);
            } catch (Throwable $throwable) {
                report($throwable);
            }
        }
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
        array $workerPollFence = [],
    ): ?array {
        $readyTasks = $this->pollReadyTasks(
            namespace: $namespace,
            taskQueue: $taskQueue,
            limit: $limit,
            supportedWorkflowTypes: $supportedWorkflowTypes,
        );

        // The workflow package bridge owns ready-task discovery,
        // including the workflow-type predicate for typed polls. The
        // server keeps only post-poll guards, fairness, and claiming so
        // shared-queue routing has a single SQL source of truth.
        //
        // Apply the fairness reorder pass: within a priority tier the
        // batch is rebalanced across distinct fairness-key classes so a
        // single noisy class can't starve its peers under saturation.
        // Priority order is preserved across tiers — urgent work always
        // leads — and tasks without a fairness key share an implicit
        // default class so unmarked tenants are never crowded out.
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

            $effectiveCompatibility = $this->effectiveReadyTaskCompatibility($namespace, $readyTask);
            $this->backfillReadyTaskCompatibility($namespace, $readyTask, $effectiveCompatibility);

            if (! $this->matchesCompatibility($buildId, $effectiveCompatibility)) {
                \Log::debug('[WorkflowTaskPoller] Skipping task: build_id mismatch', [
                    'taskId' => $readyTask['task_id'] ?? null,
                    'workerBuildId' => $buildId,
                    'taskCompatibility' => $readyTask['compatibility'] ?? null,
                    'effectiveCompatibility' => $effectiveCompatibility,
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

            $claim = DB::transaction(function () use ($taskId, $leaseOwner, $workerPollFence): array {
                if ($workerPollFence !== [] && ! WorkerPollFence::isCurrentForUpdate($workerPollFence)) {
                    return ['claimed' => false, 'reason' => 'stale_worker_registration'];
                }

                return $this->bridge->claimStatus($taskId, $leaseOwner);
            });

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

            $history = $this->fetchHistory($namespace, $taskId, $historyPageSize, $acceptHistoryEncoding);

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

            return $this->taskPayload($namespace, $claim, $attempt, $history, $workflowId);
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
     * Resolve the same compatibility marker that claimStatus() will enforce,
     * but before the worker enters the claim path. This prevents an
     * incompatible poller from touching a legacy or repaired task row whose
     * task-level compatibility is blank while the run itself remains pinned.
     *
     * @param  array<string, mixed>  $readyTask
     */
    private function effectiveReadyTaskCompatibility(string $namespace, array $readyTask): ?string
    {
        $taskCompatibility = $this->nonEmptyString($readyTask['compatibility'] ?? null);

        if ($taskCompatibility !== null) {
            return $taskCompatibility;
        }

        $runId = $this->nonEmptyString($readyTask['workflow_run_id'] ?? null);

        if ($runId === null) {
            return null;
        }

        $runCompatibility = WorkflowRun::query()
            ->whereKey($runId)
            ->where('namespace', $namespace)
            ->value('compatibility');

        return $this->nonEmptyString($runCompatibility);
    }

    /**
     * @param  array<string, mixed>  $readyTask
     */
    private function backfillReadyTaskCompatibility(string $namespace, array &$readyTask, ?string $compatibility): void
    {
        if ($compatibility === null || $this->nonEmptyString($readyTask['compatibility'] ?? null) !== null) {
            return;
        }

        $taskId = $this->nonEmptyString($readyTask['task_id'] ?? null);

        if ($taskId === null) {
            return;
        }

        $updated = WorkflowTask::query()
            ->whereKey($taskId)
            ->where('namespace', $namespace)
            ->where('task_type', TaskType::Workflow->value)
            ->where(function ($query): void {
                $query->whereNull('compatibility')
                    ->orWhere('compatibility', '');
            })
            ->update(['compatibility' => $compatibility]);

        if ($updated > 0) {
            $readyTask['compatibility'] = $compatibility;
        }
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

    private function nextVisibleReadyOrTimerAt(string $namespace, string $taskQueue, ?string $buildId): ?\DateTimeInterface
    {
        $nextWorkflowAt = $this->nextVisibleReadyAt($namespace, $taskQueue, $buildId);
        $nextTimerAt = $this->nextDueTimerProbeAt($namespace, $taskQueue, $buildId);

        if (! $nextWorkflowAt instanceof \DateTimeInterface) {
            return $nextTimerAt;
        }

        if (! $nextTimerAt instanceof \DateTimeInterface) {
            return $nextWorkflowAt;
        }

        return $nextTimerAt < $nextWorkflowAt ? $nextTimerAt : $nextWorkflowAt;
    }

    private function nextDueTimerProbeAt(string $namespace, string $taskQueue, ?string $buildId): ?\DateTimeInterface
    {
        if (
            config('server.mode') !== 'service'
            || config('workflows.v2.task_dispatch_mode') !== 'poll'
        ) {
            return null;
        }

        /** @var WorkflowTask|null $task */
        $task = NamespaceWorkflowScope::taskQuery($namespace)
            ->select('workflow_tasks.*')
            ->leftJoin('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->where('workflow_tasks.task_type', TaskType::Timer->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereNotNull('workflow_tasks.available_at')
            ->where('workflow_tasks.available_at', '>', now())
            ->where(function ($builder) use ($buildId): void {
                $this->whereEffectiveCompatibilityMatches($builder, $buildId);
            })
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id')
            ->first();

        return $task?->available_at;
    }

    private function whereEffectiveCompatibilityMatches(mixed $builder, ?string $buildId): void
    {
        $builder->where(function ($compatibility) use ($buildId): void {
            $compatibility->where(function ($fallbackToRun) use ($buildId): void {
                $fallbackToRun->where(function ($taskCompatibility): void {
                    $taskCompatibility->whereNull('workflow_tasks.compatibility')
                        ->orWhere('workflow_tasks.compatibility', '');
                })->where(function ($runCompatibility) use ($buildId): void {
                    $runCompatibility->whereNull('workflow_runs.compatibility')
                        ->orWhere('workflow_runs.compatibility', '');

                    if ($buildId !== null) {
                        $runCompatibility->orWhere('workflow_runs.compatibility', $buildId);
                    }
                });
            });

            if ($buildId !== null) {
                $compatibility->orWhere('workflow_tasks.compatibility', $buildId);
            }
        });
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
        string $namespace,
        string $taskId,
        ?int $historyPageSize,
        ?string $acceptHistoryEncoding,
    ): ?array {
        try {
            if ($historyPageSize !== null) {
                $history = $this->bridge->historyPayloadPaginated($taskId, 0, $historyPageSize);
            } else {
                $history = $this->bridge->historyPayload($taskId);
            }
        } catch (InvalidArgumentException $exception) {
            if (! str_contains($exception->getMessage(), 'Unknown payload codec')) {
                throw $exception;
            }

            $history = $this->rawHistoryPayload($taskId, $historyPageSize);
        }

        if (! is_array($history)) {
            return null;
        }

        $payloadCodec = $this->nonEmptyString($history['payload_codec'] ?? null) ?? CodecRegistry::defaultCodec();
        $history['history_events'] = $this->historyEventsWithSignalArguments(
            $history['history_events'] ?? [],
            $namespace,
            $payloadCodec,
        );

        if ($acceptHistoryEncoding !== null) {
            $history = HistoryPayloadCompression::compress($history, $acceptHistoryEncoding);
        }

        return $history;
    }

    /**
     * Build the worker-facing history payload without asking the package to
     * decode or canonicalize the run's payload codec. Non-PHP workers may be
     * able to handle codecs that this process cannot decode locally.
     *
     * @return array<string, mixed>|null
     */
    private function rawHistoryPayload(string $taskId, ?int $historyPageSize): ?array
    {
        /** @var WorkflowTask|null $task */
        $task = WorkflowTask::query()->find($taskId);

        if ($task === null || $task->task_type !== TaskType::Workflow) {
            return null;
        }

        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->find($task->workflow_run_id);

        if (! $run instanceof WorkflowRun) {
            return null;
        }

        $query = WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence');

        $pageSize = $historyPageSize !== null
            ? max(1, min($historyPageSize, 500))
            : null;

        if ($pageSize !== null) {
            $events = $query->limit($pageSize + 1)->get();
            $hasMore = $events->count() > $pageSize;
            $events = $hasMore ? $events->take($pageSize) : $events;
            $lastEventSequence = $events->isNotEmpty()
                ? (int) $events->last()->sequence
                : null;
        } else {
            $events = $query->get();
            $hasMore = false;
            $lastEventSequence = null;
        }

        return array_filter([
            'task_id' => $task->id,
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_type' => $this->nonEmptyString($run->workflow_type),
            'workflow_class' => $this->nonEmptyString($run->workflow_class),
            'payload_codec' => $run->payload_codec ?? CodecRegistry::defaultCodec(),
            'arguments' => $this->nonEmptyString($run->arguments),
            'run_status' => $run->status->value,
            'sticky_worker_id' => $this->nonEmptyString($task->sticky_worker_id),
            'sticky_until' => $task->sticky_until?->toJSON(),
            'sticky_replay_mode' => $this->nonEmptyString($task->sticky_replay_mode),
            'last_history_sequence' => (int) ($run->last_history_sequence ?? 0),
            'after_sequence' => $pageSize !== null ? 0 : null,
            'page_size' => $pageSize,
            'has_more' => $pageSize !== null ? $hasMore : null,
            'next_after_sequence' => $hasMore ? $lastEventSequence : null,
            'history_events' => $events->map(static fn (WorkflowHistoryEvent $event) => [
                'id' => $event->id,
                'sequence' => (int) $event->sequence,
                'event_type' => $event->event_type->value,
                'payload' => is_array($event->payload) ? $event->payload : [],
                'workflow_task_id' => is_string($event->workflow_task_id) && $event->workflow_task_id !== ''
                    ? $event->workflow_task_id
                    : null,
                'workflow_command_id' => is_string($event->workflow_command_id) && $event->workflow_command_id !== ''
                    ? $event->workflow_command_id
                    : null,
                'recorded_at' => $event->recorded_at?->toJSON(),
            ])->values()->all(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $claim
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private function taskPayload(
        string $namespace,
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
                ? $this->payloadEnvelopes->workerEnvelope(
                    $namespace,
                    $claim['payload_codec'] ?? CodecRegistry::defaultCodec(),
                    $history['arguments'],
                )
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

        $payload = array_merge($payload, $this->workflowTaskResumeContext($namespace, (string) $claim['task_id']));

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
    private function workflowTaskResumeContext(string $namespace, string $taskId): array
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
            'signal_arguments' => null,
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
            if ($field === 'signal_arguments') {
                continue;
            }

            $value = $payload[$field] ?? null;

            if ($field === 'workflow_sequence') {
                $context[$field] = is_int($value) ? $value : null;

                continue;
            }

            $context[$field] = $this->nonEmptyString($value);
        }

        $context['signal_arguments'] = $this->signalArgumentsEnvelope($context['workflow_signal_id'], $namespace);

        return $context;
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, mixed>
     */
    public function historyEventsWithSignalArguments(
        array $events,
        ?string $namespace = null,
        ?string $fallbackCodec = null,
    ): array {
        $events = $this->payloadEnvelopes->historyEvents($namespace, $events, $fallbackCodec);

        return $this->historyEventsWithSignalArgumentEnvelopes($events, $namespace);
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array<int, mixed>
     */
    private function historyEventsWithSignalArgumentEnvelopes(array $events, ?string $namespace): array
    {
        $signalIds = [];

        foreach ($events as $event) {
            if (! is_array($event) || ($event['event_type'] ?? null) !== 'SignalReceived') {
                continue;
            }

            $payload = $event['payload'] ?? null;
            if (! is_array($payload)) {
                continue;
            }

            $signalId = $this->nonEmptyString($payload['signal_id'] ?? null);
            if ($signalId !== null) {
                $signalIds[] = $signalId;
            }
        }

        $signalIds = array_values(array_unique($signalIds));
        if ($signalIds === []) {
            return $events;
        }

        /** @var array<string, WorkflowSignal> $signals */
        $signals = WorkflowSignal::query()
            ->whereIn('id', $signalIds)
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($events as $index => $event) {
            if (! is_array($event) || ($event['event_type'] ?? null) !== 'SignalReceived') {
                continue;
            }

            $payload = $event['payload'] ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            $signalId = $this->nonEmptyString($payload['signal_id'] ?? null);
            $signal = $signalId === null ? null : ($signals[$signalId] ?? null);
            $envelope = $signal instanceof WorkflowSignal
                ? $this->signalArgumentsEnvelopeFromRecord($signal, $namespace)
                : null;
            $changed = false;

            if ($signal instanceof WorkflowSignal && is_int($signal->workflow_sequence)) {
                $payload['workflow_sequence'] ??= $signal->workflow_sequence;
                $changed = true;
            }

            if ($envelope !== null) {
                $payload['payload_codec'] ??= $envelope['codec'];
                $payload['arguments'] ??= $envelope;
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $event['payload'] = $payload;
            $events[$index] = $event;
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function signalArgumentsEnvelope(?string $signalId, ?string $namespace): ?array
    {
        if ($signalId === null) {
            return null;
        }

        /** @var WorkflowSignal|null $signal */
        $signal = WorkflowSignal::query()->find($signalId);

        return $signal instanceof WorkflowSignal
            ? $this->signalArgumentsEnvelopeFromRecord($signal, $namespace)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function signalArgumentsEnvelopeFromRecord(WorkflowSignal $signal, ?string $namespace): ?array
    {
        if (! is_string($signal->arguments) || $signal->arguments === '') {
            return null;
        }

        return $this->payloadEnvelopes->workerEnvelope(
            $namespace,
            $this->nonEmptyString($signal->payload_codec) ?? CodecRegistry::defaultCodec(),
            $signal->arguments,
        );
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

    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function emptyPollStatus(
        string $namespace,
        string $taskQueue,
        string $taskKind,
        ?string $buildId = null,
        array $supportedWorkflowTypes = [],
    ): string {
        $status = $this->admission->budget($namespace, $taskQueue, $taskKind)['status'] ?? null;

        if (in_array($status, ['throttled', 'unavailable'], true)) {
            return $status;
        }

        $compatibilityBlockedStatus = $this->compatibilityBlockedPollStatus(
            $namespace,
            $taskQueue,
            $buildId,
            $supportedWorkflowTypes,
        );

        if ($compatibilityBlockedStatus !== null) {
            return $compatibilityBlockedStatus;
        }

        return 'empty';
    }

    /**
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function compatibilityBlockedPollStatus(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        array $supportedWorkflowTypes,
    ): ?string {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $availabilityCutoff = now()
            ->addSeconds(DefaultWorkflowTaskBridge::AVAILABILITY_CEILING_SECONDS);
        $tasks = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where(function ($query) use ($availabilityCutoff): void {
                $query->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $availabilityCutoff);
            })
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.created_at')
            ->orderBy('workflow_tasks.id')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            $run = WorkflowRun::query()
                ->whereKey($task->workflow_run_id)
                ->where('namespace', $namespace)
                ->first(['id', 'workflow_type', 'compatibility']);

            if (! $run instanceof WorkflowRun) {
                continue;
            }

            if (! $this->matchesWorkflowType($supportedWorkflowTypes, $run->workflow_type)) {
                continue;
            }

            $compatibility = $this->nonEmptyString($task->compatibility)
                ?? $this->nonEmptyString($run->compatibility);

            if (! $this->matchesCompatibility($buildId, $compatibility)) {
                if (
                    $compatibility !== null
                    && ! $this->hasCompatibleWorkerAvailable(
                        $namespace,
                        $compatibility,
                        $this->nonEmptyString($task->connection),
                        $this->nonEmptyString($task->queue) ?? $taskQueue,
                    )
                ) {
                    return 'no_compatible_worker';
                }

                return 'compatibility_blocked';
            }
        }

        return null;
    }

    private function hasCompatibleWorkerAvailable(
        string $namespace,
        string $compatibility,
        ?string $connection,
        ?string $taskQueue,
    ): bool {
        $workers = WorkerCompatibilityFleet::detailsForNamespace(
            $namespace,
            $compatibility,
            $connection,
            $taskQueue,
        );

        foreach ($workers as $worker) {
            if (($worker['supports_required'] ?? false) === true) {
                return true;
            }
        }

        return $this->hasCompatibleWorkerRegistration($namespace, $compatibility, $taskQueue);
    }

    private function hasCompatibleWorkerRegistration(
        string $namespace,
        string $compatibility,
        ?string $taskQueue,
    ): bool {
        if (! Schema::hasTable('workflow_worker_registrations')) {
            return false;
        }

        $staleAfter = StandaloneWorkerVisibility::staleAfterSeconds(
            is_numeric(config('server.workers.stale_after_seconds'))
                ? (int) config('server.workers.stale_after_seconds')
                : null,
            is_numeric(config('server.polling.timeout'))
                ? (int) config('server.polling.timeout')
                : null,
        );
        $cutoff = now()->subSeconds($staleAfter);

        $query = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->whereIn('build_id', [$compatibility, '*'])
            ->where(function ($builder): void {
                $builder->whereNull('status')
                    ->orWhere('status', 'active');
            })
            ->where(function ($builder) use ($cutoff): void {
                $builder->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '>=', $cutoff);
            });

        if ($taskQueue !== null && $taskQueue !== '') {
            $query->where('task_queue', $taskQueue);
        }

        return $query->exists();
    }
}
