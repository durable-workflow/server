<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunTimerTask;
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
use Workflow\V2\Support\WorkerProtocolVersion;

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
        private readonly PollRequestLeaseBinding $pollLeaseBindings,
        private readonly WorkflowUpdateValidationTaskBroker $updateValidationTasks,
        private readonly WorkflowTaskKindSelector $taskKindSelector,
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
        array $taskKinds = ['workflow'],
    ): array {
        $pollRequestId = $this->nonEmptyString($pollRequestId);
        $taskKinds = WorkflowTaskPollRequestStore::normalizeTaskKinds($taskKinds);
        $protocolVersion = WorkerProtocol::requestVersion($request);

        if (! WorkerPollFence::isFresh($worker)) {
            return [
                'task' => null,
                'poll_status' => 'stale_worker_registration',
            ];
        }

        if ($pollRequestId !== null && $this->cache->available()) {
            $taskKinds = $this->pollRequests->bindTaskKinds(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
                $taskKinds,
            );
        }

        if ($pollRequestId !== null && in_array('workflow', $taskKinds, true)) {
            $task = $this->activeLeasedTaskForWorker(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                buildId: $buildId,
                historyPageSize: $historyPageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                pollRequestId: $pollRequestId,
                supportedWorkflowTypes: $supportedWorkflowTypes,
                protocolVersion: $protocolVersion,
            );

            if (is_array($task)) {
                return [
                    'task' => $this->withTaskKind($task, 'workflow'),
                    'poll_status' => 'leased',
                ];
            }
        }

        if ($pollRequestId === null || ! $this->cache->available()) {
            return $this->performPoll(
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
                taskKinds: $taskKinds,
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
            taskKinds: $taskKinds,
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
        array $taskKinds = ['workflow'],
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
                $taskKinds,
                WorkerProtocol::requestVersion($request),
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
                $taskKinds,
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
                    taskKinds: $taskKinds,
                );
            }

            $observed = $this->pollRequests->waitForResult(
                $namespace,
                $taskQueue,
                $buildId,
                $leaseOwner,
                $pollRequestId,
                taskKinds: $taskKinds,
            );

            if ($observed['resolved']) {
                if (! WorkerPollFence::isCurrent($workerPollFence)) {
                    return [
                        'task' => null,
                        'poll_status' => 'stale_worker_registration',
                    ];
                }

                $this->assertTaskKindRequested(
                    $pollRequestId,
                    $taskKinds,
                    $observed['task'],
                );

                if (! $this->cachedTaskStillDeliverable(
                    namespace: $namespace,
                    taskQueue: $taskQueue,
                    buildId: $buildId,
                    leaseOwner: $leaseOwner,
                    task: $observed['task'],
                    protocolVersion: WorkerProtocol::requestVersion($request),
                )) {
                    $this->pollRequests->forgetResult(
                        $namespace,
                        $taskQueue,
                        $buildId,
                        $leaseOwner,
                        $pollRequestId,
                    );

                    continue;
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
            $taskKinds,
            WorkerProtocol::requestVersion($request),
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
        array $taskKinds,
        ?string $protocolVersion,
    ): array {
        $cached = $this->pollRequests->result(
            $namespace,
            $taskQueue,
            $buildId,
            $leaseOwner,
            $pollRequestId,
            $taskKinds,
        );

        if (! $cached['resolved']) {
            return $cached;
        }

        $this->assertTaskKindRequested($pollRequestId, $taskKinds, $cached['task']);

        if ($this->cachedTaskStillDeliverable(
            namespace: $namespace,
            taskQueue: $taskQueue,
            buildId: $buildId,
            leaseOwner: $leaseOwner,
            task: $cached['task'],
            protocolVersion: $protocolVersion,
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
                    $taskKinds,
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
        array $taskKinds = ['workflow'],
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
                taskKinds: $taskKinds,
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
            $taskKinds,
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
        array $taskKinds = ['workflow'],
    ): array {
        $limit = max(10, max(1, (int) config('server.polling.max_tasks_per_poll', 1)) * 10);
        $nextProbeAt = null;
        $resolvedResult = [
            'task' => null,
            'poll_status' => 'empty',
            'next_probe_at' => null,
        ];
        $workerPollFence = [
            ...WorkerPollFence::snapshot($worker),
            'protocol_version' => WorkerProtocol::requestVersion($request),
        ];
        $supportsQueryTasks = in_array('workflow', $taskKinds, true)
            && $this->cache->available()
            && $this->queryTasks->workerSupportsQueryTasks($namespace, $worker);
        $wakeChannels = [];

        if (in_array('workflow', $taskKinds, true)) {
            $wakeChannels = [
                ...$wakeChannels,
                ...$this->signals->workflowTaskPollChannels($namespace, null, $taskQueue),
                ...$this->signals->queryTaskPollChannels($namespace, $taskQueue),
            ];
        }

        if (in_array('update_validation', $taskKinds, true)) {
            $wakeChannels = [
                ...$wakeChannels,
                ...$this->signals->updateValidationTaskPollChannels($namespace, $taskQueue),
            ];
        }

        $pollResult = $this->longPoller->until(
            function () use (
                $request,
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $pollRequestId,
                $historyPageSize,
                $acceptHistoryEncoding,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $acceptsQueryTasks,
                $supportsQueryTasks,
                $worker,
                $taskKinds,
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

                $resolvedResult = $this->nextRequestedTask(
                    request: $request,
                    namespace: $namespace,
                    taskQueue: $taskQueue,
                    leaseOwner: $leaseOwner,
                    buildId: $buildId,
                    worker: $worker,
                    taskKinds: $taskKinds,
                    limit: $limit,
                    pollRequestId: $pollRequestId,
                    historyPageSize: $historyPageSize,
                    acceptHistoryEncoding: $acceptHistoryEncoding,
                    supportedWorkflowTypes: $supportedWorkflowTypes,
                    workflowDefinitionFingerprints: $workflowDefinitionFingerprints,
                    acceptsQueryTasks: $acceptsQueryTasks,
                    supportsQueryTasks: $supportsQueryTasks,
                    workerPollFence: $workerPollFence,
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
            wakeChannels: $wakeChannels,
            nextProbeAt: function () use (&$nextProbeAt): mixed {
                return $nextProbeAt;
            },
            reserveWorkerWaitSlot: true,
            waitSlotNamespace: $namespace,
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
     * Select and lease at most one task across the requested task kinds.
     * Multiplexed polls durably alternate their first claim attempt so neither
     * kind can monopolize a validator-capable worker. A failed first attempt
     * falls through to the other kind in the same non-blocking probe.
     *
     * @param  list<string>  $taskKinds
     * @param  list<string>  $supportedWorkflowTypes
     * @param  array<string, string>  $workflowDefinitionFingerprints
     * @return array{task: array<string, mixed>|null, poll_status: string, next_probe_at: \DateTimeInterface|null}
     */
    private function nextRequestedTask(
        Request $request,
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        WorkerRegistration $worker,
        array $taskKinds,
        int $limit,
        ?string $pollRequestId = null,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
        array $workflowDefinitionFingerprints = [],
        bool $acceptsQueryTasks = false,
        bool $supportsQueryTasks = false,
        array $workerPollFence = [],
    ): array {
        return $this->taskKindSelector->select(
            $namespace,
            $taskQueue,
            $taskKinds,
            function (string $taskKind) use (
                $request,
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $worker,
                $limit,
                $pollRequestId,
                $historyPageSize,
                $acceptHistoryEncoding,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $acceptsQueryTasks,
                $supportsQueryTasks,
                $workerPollFence,
            ): array {
                if ($taskKind === 'update_validation') {
                    $validationTask = $this->updateValidationTasks->claimAvailable($namespace, $worker);

                    return [
                        'task' => $validationTask,
                        'poll_status' => is_array($validationTask) ? 'leased' : 'empty',
                        'next_probe_at' => null,
                    ];
                }

                $result = $this->nextTask(
                    request: $request,
                    namespace: $namespace,
                    taskQueue: $taskQueue,
                    leaseOwner: $leaseOwner,
                    buildId: $buildId,
                    limit: $limit,
                    pollRequestId: $pollRequestId,
                    historyPageSize: $historyPageSize,
                    acceptHistoryEncoding: $acceptHistoryEncoding,
                    supportedWorkflowTypes: $supportedWorkflowTypes,
                    workflowDefinitionFingerprints: $workflowDefinitionFingerprints,
                    acceptsQueryTasks: $acceptsQueryTasks,
                    supportsQueryTasks: $supportsQueryTasks,
                    workerPollFence: $workerPollFence,
                );

                if (is_array($result['task'] ?? null)) {
                    $result['task'] = $this->withTaskKind($result['task'], 'workflow');
                }

                return $result;
            },
        );
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
        ?string $pollRequestId = null,
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
                $pollRequestId,
                $historyPageSize,
                $acceptHistoryEncoding,
                $supportedWorkflowTypes,
                $workflowDefinitionFingerprints,
                $acceptsQueryTasks,
                $supportsQueryTasks,
                $workerPollFence,
            ): array {
                $this->runDueServiceModeTimers($namespace, $taskQueue, $buildId);

                if ($pollRequestId !== null) {
                    $task = $this->activeLeasedTaskForWorker(
                        namespace: $namespace,
                        taskQueue: $taskQueue,
                        leaseOwner: $leaseOwner,
                        buildId: $buildId,
                        historyPageSize: $historyPageSize,
                        acceptHistoryEncoding: $acceptHistoryEncoding,
                        pollRequestId: $pollRequestId,
                        supportedWorkflowTypes: $supportedWorkflowTypes,
                        protocolVersion: $this->nonEmptyString($workerPollFence['protocol_version'] ?? null),
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
                                pollRequestId: $pollRequestId,
                                historyPageSize: $historyPageSize,
                                acceptHistoryEncoding: $acceptHistoryEncoding,
                                supportedWorkflowTypes: $supportedWorkflowTypes,
                                workerPollFence: $workerPollFence,
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
                            historyPageSize: $historyPageSize,
                            acceptHistoryEncoding: $acceptHistoryEncoding,
                            pollRequestId: $pollRequestId,
                            supportedWorkflowTypes: $supportedWorkflowTypes,
                            protocolVersion: $this->nonEmptyString($workerPollFence['protocol_version'] ?? null),
                        );
                }

                if (is_array($task)) {
                    return [
                        'task' => $task,
                        'poll_status' => 'leased',
                        'next_probe_at' => null,
                    ];
                }

                if ($this->recoverUnavailableLeases($request, $namespace, $taskQueue)) {
                    try {
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
                                    pollRequestId: $pollRequestId,
                                    historyPageSize: $historyPageSize,
                                    acceptHistoryEncoding: $acceptHistoryEncoding,
                                    supportedWorkflowTypes: $supportedWorkflowTypes,
                                    workerPollFence: $workerPollFence,
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
                                historyPageSize: $historyPageSize,
                                acceptHistoryEncoding: $acceptHistoryEncoding,
                                pollRequestId: $pollRequestId,
                                supportedWorkflowTypes: $supportedWorkflowTypes,
                                protocolVersion: $this->nonEmptyString($workerPollFence['protocol_version'] ?? null),
                            );
                    }

                    if (is_array($task)) {
                        return [
                            'task' => $task,
                            'poll_status' => 'leased',
                            'next_probe_at' => null,
                        ];
                    }
                }

                // A registered query-capable worker may let pending queries
                // preempt an otherwise idle workflow poll, but only after
                // proving no workflow task was claimable. Returning
                // query_task_pending before the claim attempt can hide ready
                // workflow tasks from other runs on the same queue and strand
                // ordered signal/query delivery behind a query that needs the
                // workflow worker to advance first.
                if (
                    $this->cache->available()
                    && $this->queryTasks->hasClaimablePendingTaskForPoller(
                        $namespace,
                        $taskQueue,
                        $supportedWorkflowTypes,
                        $buildId,
                        $acceptsQueryTasks || $supportsQueryTasks,
                        $workflowDefinitionFingerprints,
                    )
                ) {
                    return [
                        'task' => null,
                        'poll_status' => 'query_task_pending',
                        'next_probe_at' => null,
                    ];
                }

                if (
                    $this->cache->available()
                    && $supportsQueryTasks
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

                if (
                    $this->cache->available()
                    && $this->queryTasks->hasPendingTaskForPoller($namespace, $taskQueue, $supportedWorkflowTypes, $buildId)
                ) {
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
        ?string $pollRequestId = null,
        ?int $historyPageSize = null,
        ?string $acceptHistoryEncoding = null,
        array $supportedWorkflowTypes = [],
        array $workerPollFence = [],
    ): ?array {
        $readyTasks = $this->pollReplayEligibleReadyTasks(
            namespace: $namespace,
            taskQueue: $taskQueue,
            leaseOwner: $leaseOwner,
            buildId: $buildId,
            limit: $limit,
            supportedWorkflowTypes: $supportedWorkflowTypes,
            protocolVersion: $this->nonEmptyString($workerPollFence['protocol_version'] ?? null),
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

            $effectiveCompatibility = $this->effectiveReadyTaskCompatibility($namespace, $readyTask);
            $this->backfillReadyTaskCompatibility($namespace, $readyTask, $effectiveCompatibility);

            if (! $this->matchesCompatibility($buildId, $effectiveCompatibility)) {
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
                continue;
            }

            $runId = $this->nonEmptyString($readyTask['workflow_run_id'] ?? null);

            if (
                $runId === null
                || ! $this->workerCanReplayRun(
                    $namespace,
                    $leaseOwner,
                    $runId,
                    $this->nonEmptyString($workerPollFence['protocol_version'] ?? null),
                )
            ) {
                continue;
            }

            $taskId = is_string($readyTask['task_id'] ?? null)
                ? $readyTask['task_id']
                : null;

            if ($taskId === null) {
                continue;
            }

            $claim = DB::transaction(function () use (
                $namespace,
                $taskQueue,
                $taskId,
                $leaseOwner,
                $buildId,
                $pollRequestId,
                $workerPollFence,
                $runId,
            ): array {
                if ($workerPollFence !== [] && ! WorkerPollFence::isCurrentForUpdate($workerPollFence)) {
                    return ['claimed' => false, 'reason' => 'stale_worker_registration'];
                }

                if (! $this->workerCanReplayRun(
                    $namespace,
                    $leaseOwner,
                    $runId,
                    $this->nonEmptyString($workerPollFence['protocol_version'] ?? null),
                )) {
                    return ['claimed' => false, 'reason' => 'workflow_metadata_capability_not_advertised'];
                }

                $this->pollLeaseBindings->ensureUnbound(
                    $namespace,
                    TaskType::Workflow,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $pollRequestId,
                );

                $claim = $this->bridge->claimStatus($taskId, $leaseOwner);

                if (($claim['claimed'] ?? false) === true) {
                    $this->pollLeaseBindings->bindClaimedTask(
                        $namespace,
                        $taskId,
                        $leaseOwner,
                        $pollRequestId,
                    );
                }

                return $claim;
            });

            if (($claim['claimed'] ?? false) !== true) {
                continue;
            }

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

            $history = $this->fetchHistory(
                $namespace,
                $taskId,
                $historyPageSize,
                $acceptHistoryEncoding,
                $this->nonEmptyString($claim['payload_codec'] ?? null),
            );

            if (! is_array($history)) {
                \Log::warning('[WorkflowTaskPoller] Task claimed but history fetch failed', [
                    'taskId' => $taskId,
                ]);

                continue;
            }

            return $this->taskPayload($namespace, $claim, $attempt, $history, $workflowId);
        }

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
     * Continue past saturated bridge batches that contain only histories the
     * polling worker cannot replay. The public bridge does not expose a cursor,
     * so subsequent pages mirror the default bridge's ordered ready-task query.
     * Custom bridge implementations remain the sole candidate source because
     * Server cannot safely infer their pagination or filtering semantics.
     *
     * @param  list<string>  $supportedWorkflowTypes
     * @return list<array<string, mixed>>
     */
    private function pollReplayEligibleReadyTasks(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        int $limit,
        array $supportedWorkflowTypes,
        ?string $protocolVersion,
    ): array {
        $readyTasks = $this->pollReadyTasks(
            namespace: $namespace,
            taskQueue: $taskQueue,
            limit: $limit,
            supportedWorkflowTypes: $supportedWorkflowTypes,
        );

        if (! $this->bridge instanceof DefaultWorkflowTaskBridge) {
            return $readyTasks;
        }

        $pageSize = min($limit, DefaultWorkflowTaskBridge::POLL_BATCH_CAP);
        $pageCount = count($readyTasks);
        $offset = $pageCount;

        while (
            $pageCount === $pageSize
            && ! $this->pageContainsEligibleReadyTask(
                $namespace,
                $leaseOwner,
                $buildId,
                $readyTasks,
                $supportedWorkflowTypes,
                $protocolVersion,
            )
        ) {
            $readyTasks = $this->pollDefaultReadyTasksPage(
                namespace: $namespace,
                taskQueue: $taskQueue,
                limit: $pageSize,
                offset: $offset,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            );
            $pageCount = count($readyTasks);
            $offset += $pageCount;

            if ($pageCount < $pageSize) {
                break;
            }
        }

        return $readyTasks;
    }

    /**
     * @param  list<array<string, mixed>>  $readyTasks
     * @param  list<string>  $supportedWorkflowTypes
     */
    private function pageContainsEligibleReadyTask(
        string $namespace,
        string $leaseOwner,
        ?string $buildId,
        array $readyTasks,
        array $supportedWorkflowTypes,
        ?string $protocolVersion,
    ): bool {
        foreach ($readyTasks as $readyTask) {
            if ($this->availableAtIsFuture($readyTask['available_at'] ?? null)) {
                continue;
            }

            $workflowId = $this->nonEmptyString($readyTask['workflow_instance_id'] ?? null);

            if ($workflowId === null || ! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
                continue;
            }

            if (! $this->matchesCompatibility(
                $buildId,
                $this->effectiveReadyTaskCompatibility($namespace, $readyTask),
            )) {
                continue;
            }

            if (! $this->matchesWorkflowType($supportedWorkflowTypes, $readyTask['workflow_type'] ?? null)) {
                continue;
            }

            $runId = $this->nonEmptyString($readyTask['workflow_run_id'] ?? null);

            if (
                $runId !== null
                && $this->workerCanReplayRun($namespace, $leaseOwner, $runId, $protocolVersion)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirror the default bridge's ready-task ordering after its saturated first
     * page. Claiming and its transactional readiness checks remain bridge-owned.
     *
     * @param  list<string>  $supportedWorkflowTypes
     * @return list<array<string, mixed>>
     */
    private function pollDefaultReadyTasksPage(
        string $namespace,
        string $taskQueue,
        int $limit,
        int $offset,
        array $supportedWorkflowTypes,
    ): array {
        $availabilityCutoff = now()
            ->addSeconds(DefaultWorkflowTaskBridge::AVAILABILITY_CEILING_SECONDS);
        $query = NamespaceWorkflowScope::taskQuery($namespace)
            ->select('workflow_tasks.*')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where(function ($query) use ($availabilityCutoff): void {
                $query->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $availabilityCutoff);
            })
            ->orderBy('workflow_tasks.priority')
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id')
            ->offset($offset)
            ->limit($limit);

        if ($supportedWorkflowTypes !== []) {
            $query->whereIn('workflow_runs.workflow_type', $supportedWorkflowTypes);
        }

        return $query->get()
            ->map(function (WorkflowTask $task): array {
                /** @var WorkflowRun|null $run */
                $run = WorkflowRun::query()->find($task->workflow_run_id);

                return [
                    'task_id' => (string) $task->id,
                    'workflow_run_id' => (string) $task->workflow_run_id,
                    'workflow_instance_id' => $run instanceof WorkflowRun
                        ? (string) $run->workflow_instance_id
                        : '',
                    'workflow_type' => $this->nonEmptyString($run?->workflow_type),
                    'workflow_class' => $this->nonEmptyString($run?->workflow_class),
                    'connection' => $this->nonEmptyString($task->connection),
                    'queue' => $this->nonEmptyString($task->queue),
                    'compatibility' => $this->nonEmptyString($task->compatibility),
                    'sticky_worker_id' => $this->nonEmptyString($task->sticky_worker_id),
                    'sticky_until' => $task->sticky_until?->toJSON(),
                    'available_at' => $task->available_at?->toJSON(),
                    'priority' => is_int($task->priority) ? $task->priority : 0,
                    'fairness_key' => is_string($task->fairness_key) ? $task->fairness_key : null,
                    'fairness_weight' => is_int($task->fairness_weight) ? $task->fairness_weight : 1,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Rebuild the live workflow lease assigned to this logical poll.
     *
     * @param  list<string>  $supportedWorkflowTypes
     * @return array<string, mixed>|null
     */
    private function activeLeasedTaskForWorker(
        string $namespace,
        string $taskQueue,
        string $leaseOwner,
        ?string $buildId,
        ?int $historyPageSize,
        ?string $acceptHistoryEncoding,
        string $pollRequestId,
        array $supportedWorkflowTypes = [],
        ?string $protocolVersion = null,
    ): ?array {
        $tasks = collect(array_filter([
            $this->pollLeaseBindings->activeTask(
                $namespace,
                TaskType::Workflow,
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

            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()->find($task->workflow_run_id);

            if (
                ! $run instanceof WorkflowRun
                || ! $this->matchesWorkflowType($supportedWorkflowTypes, $run->workflow_type)
                || ! $this->workerCanReplayRun(
                    $namespace,
                    $leaseOwner,
                    (string) $run->id,
                    $protocolVersion,
                )
            ) {
                continue;
            }

            $history = $this->fetchHistory(
                $namespace,
                (string) $task->id,
                $historyPageSize,
                $acceptHistoryEncoding,
                $this->nonEmptyString($run->payload_codec),
            );

            if (! is_array($history)) {
                continue;
            }

            return $this->taskPayload(
                $namespace,
                [
                    'task_id' => (string) $task->id,
                    'workflow_instance_id' => (string) $run->workflow_instance_id,
                    'workflow_run_id' => (string) $run->id,
                    'workflow_type' => (string) $run->workflow_type,
                    'payload_codec' => PayloadCodecContract::canonicalize($this->nonEmptyString($run->payload_codec)),
                    'queue' => $this->nonEmptyString($task->queue),
                    'connection' => $this->nonEmptyString($task->connection),
                    'compatibility' => $this->nonEmptyString($task->compatibility),
                    'lease_owner' => $leaseOwner,
                    'lease_expires_at' => $task->lease_expires_at?->toJSON(),
                ],
                $this->packageAttemptCount((string) $task->id),
                $history,
                (string) $run->workflow_instance_id,
            );
        }

        return null;
    }

    private function recoverUnavailableLeases(
        Request $request,
        string $namespace,
        string $taskQueue,
    ): bool {
        $limit = max(1, (int) config('server.polling.expired_workflow_task_recovery_scan_limit', 5));

        $queueLeaseOwners = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereNotNull('workflow_tasks.lease_owner')
            ->distinct()
            ->pluck('workflow_tasks.lease_owner')
            ->filter(static fn (mixed $workerId): bool => is_string($workerId) && $workerId !== '')
            ->values()
            ->all();

        $freshLeaseOwners = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->whereIn('worker_id', $queueLeaseOwners)
            ->get()
            ->filter(static fn (WorkerRegistration $worker): bool => WorkerPollFence::isFresh($worker))
            ->pluck('worker_id')
            ->filter(static fn (mixed $workerId): bool => is_string($workerId) && $workerId !== '')
            ->values()
            ->all();

        $leasedTasks = NamespaceWorkflowScope::taskQuery($namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.status', TaskStatus::Leased->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereNotNull('workflow_tasks.lease_owner')
            ->where(function ($query) use ($freshLeaseOwners): void {
                $query->where(function ($query): void {
                    $query->whereNotNull('workflow_tasks.lease_expires_at')
                        ->where('workflow_tasks.lease_expires_at', '<=', now());
                });

                if ($freshLeaseOwners === []) {
                    $query->orWhereNotNull('workflow_tasks.lease_owner');

                    return;
                }

                $query->orWhereNotIn('workflow_tasks.lease_owner', $freshLeaseOwners);
            })
            ->orderBy('workflow_tasks.lease_expires_at')
            ->limit($limit)
            ->get();

        $recovered = false;

        foreach ($leasedTasks as $task) {
            $leaseExpired = $task->lease_expires_at !== null && $task->lease_expires_at->lte(now());
            $leaseOwner = $this->nonEmptyString($task->lease_owner);
            $owner = $leaseOwner === null
                ? null
                : WorkerRegistration::query()
                    ->where('namespace', $namespace)
                    ->where('worker_id', $leaseOwner)
                    ->first();
            $ownerUnavailable = ! $owner instanceof WorkerRegistration || ! WorkerPollFence::isFresh($owner);

            if (! $leaseExpired && ! $ownerUnavailable) {
                continue;
            }

            if (! $this->markRecoveryAttempt($task->id)) {
                continue;
            }

            if ($leaseExpired) {
                $this->leaseRecovery->recoverExpiredTaskLease($request, $namespace, $task);
                $recovered = true;

                continue;
            }

            $recovered = $this->leaseRecovery->recoverAbandonedTaskLease($request, $namespace, $task)
                || $recovered;
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

        if (! $this->cache->available()) {
            return true;
        }

        try {
            return $this->cache->store()->add(
                $this->recoveryKey($taskId),
                now()->toJSON(),
                now()->addSeconds($ttl),
            );
        } catch (Throwable) {
            // The durable lease transition remains guarded by the database.
            return true;
        }
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
        ?string $protocolVersion,
    ): bool {
        if ($task === null) {
            return true;
        }

        if (($task['task_kind'] ?? null) === 'update_validation') {
            return $this->updateValidationTasks->cachedTaskStillDeliverable(
                namespace: $namespace,
                taskQueue: $taskQueue,
                leaseOwner: $leaseOwner,
                payload: $task,
            );
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

        $runId = $this->nonEmptyString($workflowTask->workflow_run_id);

        if (
            $runId === null
            || ! $this->workerCanReplayRun($namespace, $leaseOwner, $runId, $protocolVersion)
        ) {
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

        if (($task['task_kind'] ?? null) === 'update_validation') {
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
        ?string $payloadCodec,
        int $afterSequence = 0,
    ): ?array {
        PayloadCodecContract::canonicalize($payloadCodec);

        $acceptedHistoryEncoding = $acceptHistoryEncoding === null
            ? null
            : HistoryPayloadCompression::resolveEncoding($acceptHistoryEncoding);
        $pageSize = $historyPageSize;

        // Compression is page-oriented even when the worker does not choose
        // an explicit size. This keeps compression bounded while preserving
        // the same cursor metadata used by explicit pagination.
        if ($pageSize === null && $acceptedHistoryEncoding !== null) {
            $pageSize = max(1, min(
                (int) config(
                    'server.worker_protocol.history_page_size_default',
                    WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
                ),
                (int) config(
                    'server.worker_protocol.history_page_size_max',
                    WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
                ),
                WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            ));
        }

        if ($pageSize !== null) {
            $history = $this->bridge->historyPayloadPaginated($taskId, $afterSequence, $pageSize);
        } else {
            $history = $this->bridge->historyPayload($taskId);
        }

        if (! is_array($history)) {
            return null;
        }

        $payloadCodec = PayloadCodecContract::canonicalize(
            $this->nonEmptyString($history['payload_codec'] ?? null),
        );
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
     * Fetch one bounded continuation page through the same bridge and codec
     * path used by the initial poll response.
     *
     * @return array<string, mixed>|null
     */
    public function historyPage(
        string $namespace,
        string $taskId,
        int $afterSequence,
        int $pageSize,
        ?string $acceptHistoryEncoding,
    ): ?array {
        $payloadCodec = null;

        if ($this->bridge instanceof DefaultWorkflowTaskBridge) {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()->find($taskId);
            /** @var WorkflowRun|null $run */
            $run = $task instanceof WorkflowTask
                ? WorkflowRun::query()->find($task->workflow_run_id)
                : null;
            $payloadCodec = $run instanceof WorkflowRun
                ? $this->nonEmptyString($run->payload_codec)
                : null;
        }

        return $this->fetchHistory(
            $namespace,
            $taskId,
            $pageSize,
            $acceptHistoryEncoding,
            $payloadCodec,
            $afterSequence,
        );
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
                    PayloadCodecContract::canonicalize($claim['payload_codec'] ?? null),
                    $history['arguments'],
                )
                : null,
            'run_status' => $history['run_status'] ?? null,
            'last_history_sequence' => $history['last_history_sequence'],
            'total_history_events' => $history['total_history_events'],
            'history_size_bytes' => $history['history_size_bytes'],
            'history_fan_out' => $history['history_fan_out'],
            'continue_as_new_recommended' => $history['continue_as_new_recommended'],
            'history_budget_pressure' => $history['history_budget_pressure'],
            'history_budget_pressure_dimensions' => $history['history_budget_pressure_dimensions'],
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

        $context['signal_arguments'] = app(MessageStreamWorkerDelivery::class)->signalArguments(
            $namespace,
            $context['signal_name'],
            $this->signalArgumentsEnvelope($context['workflow_signal_id'], $namespace),
        );

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

        $events = $this->historyEventsWithSignalArgumentEnvelopes($events, $namespace);

        return app(MessageStreamWorkerDelivery::class)->historyEvents($namespace, $events);
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
            PayloadCodecContract::canonicalize($this->nonEmptyString($signal->payload_codec)),
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

    private function workerCanReplayRun(
        string $namespace,
        string $workerId,
        string $runId,
        ?string $protocolVersion,
    ): bool {
        $worker = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $workerId)
            ->first();

        if (! $worker instanceof WorkerRegistration || ! WorkerPollFence::isFresh($worker)) {
            return false;
        }

        $capabilities = is_array($worker->capabilities)
            ? array_values(array_filter(
                $worker->capabilities,
                static fn (mixed $capability): bool => is_string($capability) && trim($capability) !== '',
            ))
            : [];

        return WorkflowMetadataCapabilityPolicy::canReplayRun(
            $runId,
            $capabilities,
            $protocolVersion,
        );
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
     * Fail closed if a stale or rolling-deploy cache entry predates the
     * normalized request-shape binding.
     *
     * @param  list<string>  $taskKinds
     * @param  array<string, mixed>|null  $task
     */
    private function assertTaskKindRequested(
        string $pollRequestId,
        array $taskKinds,
        ?array $task,
    ): void {
        if (! is_array($task)) {
            return;
        }

        $taskKind = $task['task_kind'] ?? null;

        if (is_string($taskKind) && in_array($taskKind, $taskKinds, true)) {
            return;
        }

        throw new CachedPollTaskKindConflict(
            $pollRequestId,
            $taskKinds,
            is_string($taskKind) && $taskKind !== '' ? $taskKind : null,
        );
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function withTaskKind(array $task, string $taskKind): array
    {
        return ['task_kind' => $taskKind, ...$task];
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
