<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\TaskQueueAdmission;
use App\Support\WorkflowQueryTaskBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\StandaloneWorkerVisibility;

class TaskQueueController
{
    public function __construct(
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly TaskQueueAdmission $admission,
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
                $summary = $detail->toSummaryArray();
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
            $this->withAdmission($namespace, StandaloneWorkerVisibility::queueDetail(
                $namespace,
                $taskQueue,
                WorkerRegistration::class,
                now(),
                $this->workerStaleAfterSeconds(),
            )->toArray()),
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

        $namespace = (string) $request->attributes->get('namespace');
        $staleAfter = $this->workerStaleAfterSeconds();
        $now = now();

        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('worker_id')
            ->get();

        $groups = [];

        foreach ($workers as $worker) {
            $buildId = is_string($worker->build_id) && trim($worker->build_id) !== ''
                ? trim($worker->build_id)
                : null;
            $key = $buildId ?? '__unversioned__';

            $heartbeat = $worker->last_heartbeat_at;
            $isStale = $heartbeat
                && $heartbeat->lt($now->copy()->subSeconds($staleAfter));
            $declaredStatus = is_string($worker->status) ? $worker->status : 'active';
            $effectiveStatus = $isStale ? 'stale' : $declaredStatus;

            $groups[$key] ??= [
                'build_id' => $buildId,
                'active_worker_count' => 0,
                'stale_worker_count' => 0,
                'draining_worker_count' => 0,
                'total_worker_count' => 0,
                'runtimes' => [],
                'sdk_versions' => [],
                'last_heartbeat_at' => null,
                'first_seen_at' => null,
            ];

            $groups[$key]['total_worker_count']++;
            if ($effectiveStatus === 'stale') {
                $groups[$key]['stale_worker_count']++;
            } elseif ($effectiveStatus === 'draining') {
                $groups[$key]['draining_worker_count']++;
            } else {
                $groups[$key]['active_worker_count']++;
            }

            if (is_string($worker->runtime) && trim($worker->runtime) !== '') {
                $groups[$key]['runtimes'][trim($worker->runtime)] = true;
            }
            if (is_string($worker->sdk_version) && trim($worker->sdk_version) !== '') {
                $groups[$key]['sdk_versions'][trim($worker->sdk_version)] = true;
            }

            if ($heartbeat !== null) {
                $existing = $groups[$key]['last_heartbeat_at'];
                if ($existing === null || $heartbeat->gt($existing)) {
                    $groups[$key]['last_heartbeat_at'] = $heartbeat;
                }
            }

            $createdAt = $worker->created_at;
            if ($createdAt !== null) {
                $existing = $groups[$key]['first_seen_at'];
                if ($existing === null || $createdAt->lt($existing)) {
                    $groups[$key]['first_seen_at'] = $createdAt;
                }
            }
        }

        $rolloutMap = $this->rolloutsForTaskQueue($namespace, $taskQueue);

        $buildIds = [];
        foreach ($groups as $group) {
            $runtimes = array_keys($group['runtimes']);
            sort($runtimes);
            $sdkVersions = array_keys($group['sdk_versions']);
            sort($sdkVersions);

            $rolloutKey = WorkerBuildIdRollout::buildIdKey($group['build_id']);
            $rollout = $rolloutMap[$rolloutKey] ?? null;
            $drainIntent = $rollout?->drain_intent ?? WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;

            $buildIds[] = [
                'build_id' => $group['build_id'],
                'rollout_status' => $this->buildIdRolloutStatus(
                    $group['active_worker_count'],
                    $group['draining_worker_count'],
                    $group['stale_worker_count'],
                    $drainIntent,
                ),
                'drain_intent' => $drainIntent,
                'drained_at' => $rollout?->drained_at?->toJSON(),
                'active_worker_count' => $group['active_worker_count'],
                'draining_worker_count' => $group['draining_worker_count'],
                'stale_worker_count' => $group['stale_worker_count'],
                'total_worker_count' => $group['total_worker_count'],
                'runtimes' => $runtimes,
                'sdk_versions' => $sdkVersions,
                'last_heartbeat_at' => $group['last_heartbeat_at']?->toJSON(),
                'first_seen_at' => $group['first_seen_at']?->toJSON(),
            ];
        }

        // Surface rollout intent even for cohorts whose worker rows have
        // been removed: operators still need to see "this build_id is
        // marked draining" until they explicitly resume it.
        foreach ($rolloutMap as $key => $rollout) {
            if (isset($groups[$key === '' ? '__unversioned__' : $key])) {
                continue;
            }

            $buildIds[] = [
                'build_id' => $rollout->publicBuildId(),
                'rollout_status' => $this->buildIdRolloutStatus(
                    0,
                    0,
                    0,
                    $rollout->drain_intent,
                ),
                'drain_intent' => $rollout->drain_intent,
                'drained_at' => $rollout->drained_at?->toJSON(),
                'active_worker_count' => 0,
                'draining_worker_count' => 0,
                'stale_worker_count' => 0,
                'total_worker_count' => 0,
                'runtimes' => [],
                'sdk_versions' => [],
                'last_heartbeat_at' => null,
                'first_seen_at' => null,
            ];
        }

        usort($buildIds, function (array $a, array $b): int {
            $rankA = $this->buildIdRolloutRank($a);
            $rankB = $this->buildIdRolloutRank($b);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            $heartA = $a['last_heartbeat_at'] ?? '';
            $heartB = $b['last_heartbeat_at'] ?? '';

            return strcmp($heartB, $heartA);
        });

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'stale_after_seconds' => $staleAfter,
            'build_ids' => $buildIds,
        ]);
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

        if (! $draining) {
            // Clear the draining status we stamped on worker rows so the
            // next heartbeat is not forced back to draining by the resume
            // path. Workers that are still running will have their status
            // restamped to active on their next heartbeat anyway, but
            // clearing immediately keeps the read endpoint honest.
            WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->where('task_queue', $taskQueue)
                ->when(
                    $publicBuildId !== null,
                    fn ($query) => $query->where('build_id', $publicBuildId),
                    fn ($query) => $query->where(function ($q) {
                        $q->whereNull('build_id')->orWhere('build_id', '');
                    }),
                )
                ->where('status', WorkerBuildIdRollout::DRAIN_INTENT_DRAINING)
                ->update(['status' => 'active']);
        }

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $publicBuildId,
            'drain_intent' => $rollout->drain_intent,
            'drained_at' => $rollout->drained_at?->toJSON(),
        ]);
    }

    /**
     * @return array<string, WorkerBuildIdRollout>
     */
    private function rolloutsForTaskQueue(string $namespace, string $taskQueue): array
    {
        $rollouts = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->get();

        $map = [];
        foreach ($rollouts as $rollout) {
            $map[(string) $rollout->build_id] = $rollout;
        }

        return $map;
    }

    private function buildIdRolloutStatus(
        int $active,
        int $draining,
        int $stale,
        string $drainIntent = WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE,
    ): string {
        $intentDraining = $drainIntent === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING;

        if ($active > 0) {
            return $intentDraining || $draining > 0 ? 'active_with_draining' : 'active';
        }

        if ($draining > 0) {
            return 'draining';
        }

        if ($intentDraining) {
            // Operator intent is to drain, but no live workers remain to
            // acknowledge it. Keep the cohort visible as draining so the
            // rollout state is clear even after stale workers are purged.
            return 'draining';
        }

        return $stale > 0 ? 'stale_only' : 'no_workers';
    }

    /**
     * Sort key: rollout-active builds first, then draining, then stale.
     * Within each rollout-status bucket the unversioned cohort sorts last
     * so the named builds an operator is rolling out are visible above
     * the legacy default — but a stale named build still sorts below an
     * active unversioned cohort.
     *
     * @param  array<string, mixed>  $entry
     */
    private function buildIdRolloutRank(array $entry): int
    {
        $statusRank = match ($entry['rollout_status'] ?? '') {
            'active' => 0,
            'active_with_draining' => 1,
            'draining' => 2,
            'stale_only' => 3,
            default => 4,
        };

        $rank = $statusRank * 2;
        if (($entry['build_id'] ?? null) === null) {
            $rank += 1;
        }

        return $rank;
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
        $serverBudget = $this->admission->budget($namespace, $taskQueue, $taskKind);

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
