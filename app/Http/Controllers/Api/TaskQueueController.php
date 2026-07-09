<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneTimestamp;
use App\Support\DeploymentLifecycleService;
use App\Support\TaskQueueAdmission;
use App\Support\TaskQueueBuildIdRolloutSnapshot;
use App\Support\TaskQueuePriorityFairnessSurface;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StandaloneWorkerVisibility;

class TaskQueueController
{
    public function __construct(
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly TaskQueueAdmission $admission,
        private readonly TaskQueueBuildIdRolloutSnapshot $buildIdRollouts,
        private readonly TaskQueuePriorityFairnessSurface $priorityFairness,
        private readonly DeploymentLifecycleService $deployments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $snapshot = StandaloneWorkerVisibility::queueSnapshot(
            $namespace,
            WorkerRegistration::class,
            now(),
            $this->workerStaleAfterSeconds(),
        );

        $payload = [
            'namespace' => $snapshot->namespace,
            'task_queues' => array_map(function ($detail) use ($namespace): array {
                $summary = $this->withRecentTaskFlow($namespace, $detail->name, $detail->toSummaryArray());
                $summary['pollers'] = $detail->pollers();
                $summary = $this->withAdmission($namespace, $summary);
                unset($summary['pollers']);

                return $summary;
            }, $snapshot->taskQueues()),
        ];

        return ControlPlaneProtocol::json($payload);
    }

    public function show(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            $this->withAdmission(
                $namespace,
                $this->withRecentTaskFlow(
                    $namespace,
                    $taskQueue,
                    StandaloneWorkerVisibility::queueDetail(
                        $namespace,
                        $taskQueue,
                        WorkerRegistration::class,
                        now(),
                        $this->workerStaleAfterSeconds(),
                    )->toArray(),
                ),
            ),
        );
    }

    /**
     * Aggregate worker registrations by build_id for one task queue.
     *
     * Operators use this to answer "which builds can still claim work, and
     * is it safe to drain or remove the older build now?" before deleting
     * stale worker rows or rolling forward to a new build_id. Workers with
     * no build_id are reported under a null build_id row that represents
     * the unversioned cohort (the pre-rollout default).
     */
    public function buildIds(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        return ControlPlaneProtocol::json(
            $this->buildIdRollouts->forTaskQueue(
                (string) $request->attributes->get('namespace'),
                $taskQueue,
            ),
        );
    }

    /**
     * Mark a build_id cohort as draining so operators can cut traffic off
     * a build before deleting its workers. Passing null for build_id drains
     * the unversioned cohort (the pre-rollout default). The call is
     * idempotent: repeated drains return the existing rollout record.
     */
    public function drainBuildId(Request $request, string $taskQueue): JsonResponse
    {
        return $this->setBuildIdDrainIntent(
            $request,
            $taskQueue,
            WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
        );
    }

    /**
     * Promote a build_id cohort so fresh workflow starts on this task
     * queue pin to that build. Existing runs keep their stamped
     * compatibility and continue to route only to compatible workers.
     */
    public function promoteBuildId(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'build_id' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        $namespace = (string) $request->attributes->get('namespace');
        $publicBuildId = is_string($validated['build_id']) && trim($validated['build_id']) !== ''
            ? trim($validated['build_id'])
            : null;

        $result = $this->deployments->promote($namespace, $taskQueue, $publicBuildId);

        if ($result['blockages'] !== []) {
            return ControlPlaneProtocol::json(
                $this->buildIdLifecycleRejectionPayload(
                    $namespace,
                    $taskQueue,
                    $publicBuildId,
                    $result['deployment'] === null
                        ? null
                        : $this->deployments->deploymentPayload($result['deployment']),
                    $result['blockages'],
                ),
                409,
            );
        }

        return ControlPlaneProtocol::json(
            $this->buildIdLifecyclePayload(
                $namespace,
                $taskQueue,
                $publicBuildId,
                $result['deployment'] === null
                    ? []
                    : $this->deployments->deploymentPayload($result['deployment']),
            ),
        );
    }

    /**
     * Revert an earlier drain so the build_id cohort can accept work again
     * (rollback path). Passing null for build_id resumes the unversioned
     * cohort. The call is idempotent.
     */
    public function resumeBuildId(Request $request, string $taskQueue): JsonResponse
    {
        return $this->setBuildIdDrainIntent(
            $request,
            $taskQueue,
            WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
        );
    }

    /**
     * Operator observability surface for task-queue priority + fairness
     * dispatch. Returns ready-task counts grouped by priority tier and
     * fairness class for both workflow and activity tasks, plus the most
     * recent dispatch scores per class so an operator can confirm under
     * load that priority is honored (urgent tiers dominate dispatch counts)
     * and that fairness is applied (counts are roughly balanced subject to
     * declared weights). Workflow-task and activity-task surfaces are
     * reported separately because they keep separate fairness buckets on
     * the dispatch path.
     */
    public function priorityFairness(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            $this->priorityFairness->snapshot($namespace, $taskQueue),
        );
    }

    private function setBuildIdDrainIntent(Request $request, string $taskQueue, string $intent): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'build_id' => ['present', 'nullable', 'string', 'max:255'],
        ]);

        $namespace = (string) $request->attributes->get('namespace');
        $publicBuildId = is_string($validated['build_id']) && trim($validated['build_id']) !== ''
            ? trim($validated['build_id'])
            : null;
        $key = WorkerBuildIdRollout::buildIdKey($publicBuildId);
        $now = now();

        $rollout = WorkerBuildIdRollout::query()->firstOrNew([
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $key,
        ]);

        $draining = $intent === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING;
        $wasDraining = $rollout->drain_intent === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING;

        $rollout->drain_intent = $intent;
        $rollout->drained_at = $draining
            ? ($wasDraining ? $rollout->drained_at : $now)
            : null;
        $rollout->save();

        $this->stampWorkerDrainStatus(
            $namespace,
            $taskQueue,
            $publicBuildId,
            $draining ? WorkerBuildIdRollout::DRAIN_INTENT_DRAINING : 'active',
            $draining,
        );

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $publicBuildId,
            'drain_intent' => $rollout->drain_intent,
            'drained_at' => ControlPlaneTimestamp::zuluSecond($rollout->drained_at),
            'promoted_at' => ControlPlaneTimestamp::zuluSecond($rollout->promoted_at),
            'rolled_back_at' => ControlPlaneTimestamp::zuluSecond($rollout->rolled_back_at),
            'new_start_selected' => $this->buildIdRollouts->isNewStartSelected($rollout),
        ]);
    }

    /**
     * @param array<string, mixed> $deployment
     * @return array<string, mixed>
     */
    private function buildIdLifecyclePayload(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        array $deployment,
    ): array {
        $deployment = $this->normalizeDeploymentLifecycleTimestamps($deployment);

        return [
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $buildId,
            'drain_intent' => ($deployment['state'] ?? null) === 'draining'
                ? WorkerBuildIdRollout::DRAIN_INTENT_DRAINING
                : WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
            'drained_at' => ControlPlaneTimestamp::zuluSecond($deployment['drained_at'] ?? null),
            'promoted_at' => ControlPlaneTimestamp::zuluSecond($deployment['promoted_at'] ?? null),
            'rolled_back_at' => ControlPlaneTimestamp::zuluSecond($deployment['rolled_back_at'] ?? null),
            'new_start_selected' => $this->buildIdRollouts->isBuildIdSelectedForNewStarts(
                $namespace,
                $taskQueue,
                $buildId,
            ),
            'deployment' => $deployment,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $deployment
     * @param  list<array<string, mixed>>  $blockages
     * @return array<string, mixed>
     */
    private function buildIdLifecycleRejectionPayload(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        ?array $deployment,
        array $blockages,
    ): array {
        $this->orderBlockages($blockages);
        $deployment = $deployment === null ? null : $this->normalizeDeploymentLifecycleTimestamps($deployment);

        $primary = $blockages[0] ?? [];
        $reason = is_string($primary['reason'] ?? null) && $primary['reason'] !== ''
            ? $primary['reason']
            : 'deployment_lifecycle_blocked';
        $message = is_string($primary['message'] ?? null) && $primary['message'] !== ''
            ? $primary['message']
            : 'Deployment lifecycle action was rejected.';
        $expectedResolution = is_string($primary['expected_resolution'] ?? null) && $primary['expected_resolution'] !== ''
            ? $primary['expected_resolution']
            : null;
        $outcome = 'rejected_'.$reason;

        return [
            'message' => $message,
            'reason' => $reason,
            'rejection_reason' => $reason,
            'expected_resolution' => $expectedResolution,
            'outcome' => $outcome,
            'control_plane_outcome' => $outcome,
            'command_status' => 'rejected',
            'command_source' => 'control_plane',
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $buildId,
            'deployment' => $deployment,
            'blockages' => $blockages,
        ];
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array<string, mixed>
     */
    private function normalizeDeploymentLifecycleTimestamps(array $deployment): array
    {
        foreach (['drained_at', 'promoted_at', 'rolled_back_at'] as $field) {
            $deployment[$field] = ControlPlaneTimestamp::zuluSecond($deployment[$field] ?? null);
        }

        return $deployment;
    }

    /**
     * @param list<array<string, mixed>> $blockages
     */
    private function orderBlockages(array &$blockages): void
    {
        $rank = static function (string $reason): int {
            return match ($reason) {
                'unknown_deployment',
                'incompatible_policy',
                'fleet_is_draining' => 0,
                'no_compatible_workers',
                'missing_worker_heartbeat',
                'fingerprint_mismatch' => 1,
                'replay_safety_failed' => 2,
                default => 3,
            };
        };

        usort($blockages, static function (array $a, array $b) use ($rank): int {
            return $rank((string) ($a['reason'] ?? '')) <=> $rank((string) ($b['reason'] ?? ''));
        });
    }

    private function stampWorkerDrainStatus(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $status,
        bool $draining,
    ): void {
        WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->when(
                $buildId !== null,
                fn ($query) => $query->where('build_id', $buildId),
                fn ($query) => $query->where(function ($q) {
                    $q->whereNull('build_id')->orWhere('build_id', '');
                }),
            )
            ->when(
                ! $draining,
                fn ($query) => $query->where('status', WorkerBuildIdRollout::DRAIN_INTENT_DRAINING),
            )
            ->update(['status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withAdmission(string $namespace, array $payload): array
    {
        $taskQueue = is_string($payload['name'] ?? null) && trim($payload['name']) !== ''
            ? trim($payload['name'])
            : 'default';
        $pollers = $payload['pollers'] ?? [];
        $stats = $payload['stats'] ?? [];

        $payload['admission'] = [
            'workflow_tasks' => $this->taskAdmission(
                $namespace,
                $taskQueue,
                TaskQueueAdmission::WORKFLOW_TASKS,
                'worker_registration.max_concurrent_workflow_tasks',
                is_array($pollers) ? $pollers : [],
                'max_concurrent_workflow_tasks',
                (int) data_get($stats, 'workflow_tasks.leased_count', 0),
                (int) data_get($stats, 'workflow_tasks.ready_count', 0),
            ),
            'activity_tasks' => $this->taskAdmission(
                $namespace,
                $taskQueue,
                TaskQueueAdmission::ACTIVITY_TASKS,
                'worker_registration.max_concurrent_activity_tasks',
                is_array($pollers) ? $pollers : [],
                'max_concurrent_activity_tasks',
                (int) data_get($stats, 'activity_tasks.leased_count', 0),
                (int) data_get($stats, 'activity_tasks.ready_count', 0),
            ),
            'query_tasks' => $this->queryTasks->queueAdmission($namespace, $taskQueue),
        ];

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $pollers
     * @return array{
     *     budget_source: string,
     *     server_budget_source: string,
     *     active_worker_count: int,
     *     configured_slot_count: int,
     *     leased_count: int,
     *     ready_count: int,
     *     available_slot_count: int,
     *     server_max_active_leases_per_queue: int|null,
     *     server_active_lease_count: int,
     *     server_remaining_active_lease_capacity: int|null,
     *     server_max_active_leases_per_namespace: int|null,
     *     server_namespace_active_lease_count: int,
     *     server_remaining_namespace_active_lease_capacity: int|null,
     *     server_max_dispatches_per_minute: int|null,
     *     server_dispatch_count_this_minute: int,
     *     server_remaining_dispatch_capacity: int|null,
     *     server_max_dispatches_per_minute_per_namespace: int|null,
     *     server_namespace_dispatch_count_this_minute: int,
     *     server_remaining_namespace_dispatch_capacity: int|null,
     *     server_dispatch_budget_group: string|null,
     *     server_max_dispatches_per_minute_per_budget_group: int|null,
     *     server_budget_group_dispatch_count_this_minute: int,
     *     server_remaining_budget_group_dispatch_capacity: int|null,
     *     server_lock_required: bool,
     *     server_lock_supported: bool,
     *     status: string
     * }
     */
    private function taskAdmission(
        string $namespace,
        string $taskQueue,
        string $taskKind,
        string $budgetSource,
        array $pollers,
        string $slotField,
        int $leasedCount,
        int $readyCount,
    ): array {
        $activePollers = array_values(array_filter(
            $pollers,
            static fn (array $poller): bool => ($poller['is_stale'] ?? false) !== true
                && (($poller['status'] ?? 'active') === 'active'),
        ));
        $slotCounts = array_map(
            static fn (array $poller): int => max(0, (int) ($poller[$slotField] ?? 0)),
            $activePollers,
        );
        $configuredSlots = array_sum($slotCounts);
        $activeWorkerCount = count($activePollers);
        // This operator endpoint intentionally pays for unbounded counts. The
        // worker hot path omits them when no admission limit can use them.
        $serverBudget = $this->admission->budget(
            $namespace,
            $taskQueue,
            $taskKind,
            includeUnboundedCounts: true,
        );

        return [
            'budget_source' => $budgetSource,
            'server_budget_source' => $serverBudget['budget_source'],
            'active_worker_count' => $activeWorkerCount,
            'configured_slot_count' => $configuredSlots,
            'leased_count' => max(0, $leasedCount),
            'ready_count' => max(0, $readyCount),
            'available_slot_count' => max(0, $configuredSlots - max(0, $leasedCount)),
            'server_max_active_leases_per_queue' => $serverBudget['max_active_leases_per_queue'],
            'server_active_lease_count' => $serverBudget['active_lease_count'],
            'server_remaining_active_lease_capacity' => $serverBudget['remaining_active_lease_capacity'],
            'server_max_active_leases_per_namespace' => $serverBudget['max_active_leases_per_namespace'],
            'server_namespace_active_lease_count' => $serverBudget['namespace_active_lease_count'],
            'server_remaining_namespace_active_lease_capacity' => $serverBudget['remaining_namespace_active_lease_capacity'],
            'server_max_dispatches_per_minute' => $serverBudget['max_dispatches_per_minute'],
            'server_dispatch_count_this_minute' => $serverBudget['dispatch_count_this_minute'],
            'server_remaining_dispatch_capacity' => $serverBudget['remaining_dispatch_capacity'],
            'server_max_dispatches_per_minute_per_namespace' => $serverBudget['max_dispatches_per_minute_per_namespace'],
            'server_namespace_dispatch_count_this_minute' => $serverBudget['namespace_dispatch_count_this_minute'],
            'server_remaining_namespace_dispatch_capacity' => $serverBudget['remaining_namespace_dispatch_capacity'],
            'server_dispatch_budget_group' => $serverBudget['dispatch_budget_group'],
            'server_max_dispatches_per_minute_per_budget_group' => $serverBudget['max_dispatches_per_minute_per_budget_group'],
            'server_budget_group_dispatch_count_this_minute' => $serverBudget['budget_group_dispatch_count_this_minute'],
            'server_remaining_budget_group_dispatch_capacity' => $serverBudget['remaining_budget_group_dispatch_capacity'],
            'server_lock_required' => $serverBudget['lock_required'],
            'server_lock_supported' => $serverBudget['lock_supported'],
            'status' => $this->taskAdmissionStatus($activeWorkerCount, $configuredSlots, $leasedCount, $serverBudget),
        ];
    }

    /**
     * @param  array<string, mixed>  $serverBudget
     */
    private function taskAdmissionStatus(int $activeWorkerCount, int $configuredSlots, int $leasedCount, array $serverBudget): string
    {
        if (($serverBudget['status'] ?? null) === 'unavailable') {
            return 'unavailable';
        }

        if (($serverBudget['status'] ?? null) === 'throttled') {
            return 'throttled';
        }

        if ($activeWorkerCount === 0) {
            return 'no_active_workers';
        }

        if ($configuredSlots <= 0) {
            return 'no_slots';
        }

        return $leasedCount >= $configuredSlots ? 'saturated' : 'accepting';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withRecentTaskFlow(string $namespace, string $taskQueue, array $payload): array
    {
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];
        $stats = array_merge($stats, $this->recentTaskFlow($namespace, $taskQueue));
        $stats['activity_attempts'] = $this->activityAttemptStateCounts($namespace, $taskQueue);
        $payload['stats'] = $stats;

        return $payload;
    }

    /**
     * @return array{tasks_added_last_minute: int, tasks_dispatched_last_minute: int}
     */
    private function recentTaskFlow(string $namespace, string $taskQueue): array
    {
        $windowStart = now()->subMinute();

        $query = WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('queue', $taskQueue)
            ->whereIn('task_type', [TaskType::Workflow->value, TaskType::Activity->value]);

        return [
            'tasks_added_last_minute' => (clone $query)
                ->where('created_at', '>=', $windowStart)
                ->count(),
            'tasks_dispatched_last_minute' => (clone $query)
                ->whereNotNull('last_dispatched_at')
                ->where('last_dispatched_at', '>=', $windowStart)
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function activityAttemptStateCounts(string $namespace, string $taskQueue): array
    {
        $counts = [
            'total_count' => 0,
            'running_count' => 0,
            'retrying_count' => 0,
            'timed_out_count' => 0,
            'expired_count' => 0,
            'failed_count' => 0,
            'completed_count' => 0,
            'cancelled_count' => 0,
            'open_count' => 0,
            'terminal_count' => 0,
        ];

        $rows = DB::table('activity_attempts')
            ->join('activity_executions', 'activity_executions.id', '=', 'activity_attempts.activity_execution_id')
            ->join('workflow_runs', 'workflow_runs.id', '=', 'activity_attempts.workflow_run_id')
            ->where('workflow_runs.namespace', $namespace)
            ->whereRaw("COALESCE(activity_executions.queue, workflow_runs.queue, 'default') = ?", [$taskQueue])
            ->selectRaw('activity_attempts.status as attempt_status, activity_executions.status as execution_status, workflow_runs.closed_reason as run_closed_reason, COUNT(*) as aggregate_count')
            ->groupBy('activity_attempts.status', 'activity_executions.status', 'workflow_runs.closed_reason')
            ->get();

        foreach ($rows as $row) {
            $attemptStatus = is_string($row->attempt_status ?? null) ? $row->attempt_status : '';
            $executionStatus = is_string($row->execution_status ?? null) ? $row->execution_status : '';
            $closedReason = is_string($row->run_closed_reason ?? null) ? $row->run_closed_reason : '';
            $count = (int) ($row->aggregate_count ?? 0);

            if ($count <= 0) {
                continue;
            }

            $counts['total_count'] += $count;

            if ($attemptStatus === ActivityAttemptStatus::Running->value) {
                $counts['running_count'] += $count;
                $counts['open_count'] += $count;
                continue;
            }

            if (
                $attemptStatus === ActivityAttemptStatus::Failed->value
                && in_array($executionStatus, [ActivityStatus::Pending->value, ActivityStatus::Running->value], true)
            ) {
                $counts['retrying_count'] += $count;
                $counts['open_count'] += $count;
                continue;
            }

            if ($attemptStatus === ActivityAttemptStatus::Expired->value) {
                $counts['timed_out_count'] += $count;
                $counts['expired_count'] += $count;
                $counts['terminal_count'] += $count;
                continue;
            }

            if (
                $attemptStatus === ActivityAttemptStatus::Failed->value
                && $executionStatus === ActivityStatus::Failed->value
                && $closedReason === 'timed_out'
            ) {
                $counts['timed_out_count'] += $count;
                $counts['terminal_count'] += $count;
                continue;
            }

            if ($attemptStatus === ActivityAttemptStatus::Failed->value) {
                $counts['failed_count'] += $count;
                $counts['terminal_count'] += $count;
                continue;
            }

            if ($attemptStatus === ActivityAttemptStatus::Completed->value) {
                $counts['completed_count'] += $count;
                $counts['terminal_count'] += $count;
                continue;
            }

            if ($attemptStatus === ActivityAttemptStatus::Cancelled->value) {
                $counts['cancelled_count'] += $count;
                $counts['terminal_count'] += $count;
            }
        }

        return $counts;
    }

    private function workerStaleAfterSeconds(): int
    {
        $configured = config('server.workers.stale_after_seconds');
        $pollingTimeout = config('server.polling.timeout');

        return StandaloneWorkerVisibility::staleAfterSeconds(
            is_numeric($configured) ? (int) $configured : null,
            is_numeric($pollingTimeout) ? (int) $pollingTimeout : null,
        );
    }
}
