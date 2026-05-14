<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
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
        private readonly LongPollSignalStore $signals,
        private readonly TaskQueueAdmission $admission,
        private readonly WorkerSessionRegistry $workerSessions,
        private readonly TaskFairnessState $fairnessState,
        private readonly WorkerPollClaimGate $claimGate,
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
        array $supportedActivityTypes = [],
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
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $worker,
                $supportedActivityTypes,
                $limit,
                &$nextProbeAt,
                &$resolvedResult,
            ): ?array {
                $resolvedResult = $this->nextTask(
                    $namespace,
                    $taskQueue,
                    $leaseOwner,
                    $buildId,
                    $worker,
                    $limit,
                    $supportedActivityTypes,
                );
                $nextProbeAt = $resolvedResult['next_probe_at'] ?? null;

                return $resolvedResult['task'] ?? null;
            },
            static fn (?array $task): bool => is_array($task),
            wakeChannels: $this->signals->activityTaskPollChannels($namespace, null, $taskQueue),
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
        array $supportedActivityTypes = [],
    ): array {
        $this->applyWorkerCompatibility($namespace, $buildId);

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
                    $supportedActivityTypes,
                ),
            ),
        );

        return [
            'task' => $task,
            'poll_status' => is_array($task)
                ? 'leased'
                : $this->emptyPollStatus($namespace, $taskQueue, TaskQueueAdmission::ACTIVITY_TASKS),
            'next_probe_at' => $task === null
                ? $this->nextVisibleReadyAt($namespace, $taskQueue, $buildId)
                : null,
        ];
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
        array $supportedActivityTypes = [],
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
                    ! WorkerProtocol::workerSessionsSupported()
                    || ! $this->workerCanSatisfySession($worker, $workerSession)
                )
            ) {
                continue;
            }

            try {
                $claim = DB::transaction(function () use ($namespace, $worker, $taskId, $leaseOwner): ?array {
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
                        if (! WorkerProtocol::workerSessionsSupported()) {
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
        try {
            return $this->bridge->claimStatus($taskId, $leaseOwner);
        } catch (InvalidArgumentException $exception) {
            if (! str_contains($exception->getMessage(), 'Unknown payload codec')) {
                throw $exception;
            }

            return $this->rawActivityClaimPayload($taskId, $leaseOwner);
        }
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

        if ($task === null || $task->task_type !== TaskType::Activity || $task->lease_owner !== $leaseOwner) {
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

        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->find($execution->workflow_run_id);

        if (! $run instanceof WorkflowRun) {
            return null;
        }

        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->where('workflow_task_id', $task->id)
            ->where('activity_execution_id', $execution->id)
            ->latest('attempt_number')
            ->first();

        return [
            'claimed' => true,
            'task_id' => $task->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'activity_execution_id' => $execution->id,
            'activity_attempt_id' => $attempt?->id,
            'attempt_number' => $attempt instanceof ActivityAttempt ? max(1, (int) $attempt->attempt_number) : max(1, (int) $task->attempt_count),
            'activity_type' => $this->nonEmptyString($execution->activity_type),
            'activity_class' => $this->nonEmptyString($execution->activity_class),
            'idempotency_key' => $execution->id,
            'payload_codec' => $execution->payload_codec ?? $run->payload_codec ?? 'json',
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

    private function applyWorkerCompatibility(string $namespace, ?string $buildId): void
    {
        config([
            'workflows.v2.compatibility.namespace' => $namespace,
            'workflows.v2.compatibility.current' => $buildId,
            'workflows.v2.compatibility.supported' => $buildId === null ? [] : [$buildId],
        ]);
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
