<?php

namespace App\Support;

use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\ActivityExecution;
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
            fn (): ?array => $this->claimReadyTask(
                $namespace,
                $taskQueue,
                $leaseOwner,
                $buildId,
                $worker,
                $limit,
                $supportedActivityTypes,
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

        // Server-owned dispatch fallback for typed activity polls.
        // Symmetric with WorkflowTaskPoller::claimReadyTask: when a
        // worker registered specific supportedActivityTypes but the
        // bridge surfaced no candidate, run the same join in app code
        // so the shared-queue routing rule is server-authoritative on
        // both task kinds. The bridge stays authoritative for the
        // claim transaction; this only identifies candidates and
        // returns the row shape the existing claim path consumes.
        if ($readyTasks === [] && $supportedActivityTypes !== []) {
            $readyTasks = $this->pollReadyTasksFromDispatch(
                namespace: $namespace,
                taskQueue: $taskQueue,
                buildId: $buildId,
                limit: $limit,
                supportedActivityTypes: $supportedActivityTypes,
            );
        }

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

            try {
                $claim = DB::transaction(function () use ($namespace, $worker, $taskId, $leaseOwner): ?array {
                    $claim = $this->bridge->claimStatus($taskId, $leaseOwner);

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
     * Server-owned dispatch fallback for typed activity polls.
     *
     * Mirrors WorkflowTaskPoller::pollReadyTasksFromDispatch on the
     * activity side: when a worker registered specific
     * supportedActivityTypes but the bridge poll returns nothing, run
     * the workflow_tasks ↔ activity_executions join in app code so the
     * registered worker still sees its matching task on the next poll
     * regardless of which predicate shape the bridge ships in a given
     * release. The bridge is authoritative for the claim transaction;
     * this method only identifies candidates and returns the same row
     * shape the bridge poll already produces, so the downstream
     * matchesActivityType + claimStatus chain runs unchanged.
     *
     * @param  list<string>  $supportedActivityTypes
     * @return list<array<string, mixed>>
     */
    private function pollReadyTasksFromDispatch(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        int $limit,
        array $supportedActivityTypes,
    ): array {
        if ($supportedActivityTypes === []) {
            return [];
        }

        $availabilityCutoff = now()->addSecond();

        $query = NamespaceWorkflowScope::taskQuery($namespace)
            ->select('workflow_tasks.*')
            ->where('workflow_tasks.task_type', TaskType::Activity->value)
            ->where('workflow_tasks.status', TaskStatus::Ready->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->where(function ($builder) use ($availabilityCutoff): void {
                $builder->whereNull('workflow_tasks.available_at')
                    ->orWhere('workflow_tasks.available_at', '<=', $availabilityCutoff);
            })
            ->whereIn(
                'workflow_tasks.payload->activity_execution_id',
                ActivityExecution::query()
                    ->select('id')
                    ->whereIn('activity_type', $supportedActivityTypes),
            )
            ->orderBy('workflow_tasks.priority')
            ->orderBy('workflow_tasks.available_at')
            ->orderBy('workflow_tasks.id')
            ->limit(max(1, $limit));

        if ($buildId !== null) {
            $query->where('workflow_tasks.compatibility', $buildId);
        }

        $tasks = $query->get();

        return $tasks->map(static function (WorkflowTask $task): array {
            $executionId = $task->payload['activity_execution_id'] ?? null;

            /** @var ActivityExecution|null $execution */
            $execution = is_string($executionId)
                ? ActivityExecution::query()->find($executionId)
                : null;

            return [
                'task_id' => $task->id,
                'workflow_run_id' => $task->workflow_run_id,
                'workflow_instance_id' => '',
                'activity_execution_id' => $execution?->id,
                'activity_type' => self::nullableString($execution?->activity_type),
                'activity_class' => self::nullableString($execution?->activity_class),
                'connection' => self::nullableString($task->connection),
                'queue' => self::nullableString($task->queue),
                'compatibility' => self::nullableString($task->compatibility),
                'available_at' => $task->available_at?->toJSON(),
                'priority' => is_int($task->priority) ? $task->priority : 5,
                'fairness_key' => self::nullableString($task->fairness_key ?? null),
                'fairness_weight' => is_int($task->fairness_weight) ? $task->fairness_weight : 1,
            ];
        })->values()->all();
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
