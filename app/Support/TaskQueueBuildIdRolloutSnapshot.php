<?php

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StandaloneWorkerVisibility;

final class TaskQueueBuildIdRolloutSnapshot
{
    public function __construct(
        private readonly WorkerBuildIdNewStartSelector $newStartSelector,
    ) {}

    /**
     * @return array{
     *     namespace: string,
     *     task_queue: string,
     *     stale_after_seconds: int,
     *     build_ids: list<array<string, mixed>>
     * }
     */
    public function forTaskQueue(string $namespace, string $taskQueue): array
    {
        $staleAfter = $this->workerStaleAfterSeconds();

        return [
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'stale_after_seconds' => $staleAfter,
            'build_ids' => $this->buildIdEntriesForTaskQueue($namespace, $taskQueue, $staleAfter),
        ];
    }

    public function isNewStartSelected(WorkerBuildIdRollout $rollout): bool
    {
        return $this->newStartSelector->isSelected($rollout);
    }

    public function isBuildIdSelectedForNewStarts(string $namespace, string $taskQueue, ?string $buildId): bool
    {
        $selectedKey = $this->newStartSelector->selectedKeyForTaskQueue($namespace, $taskQueue);

        return $selectedKey !== null
            && $selectedKey === WorkerBuildIdRollout::buildIdKey($buildId);
    }

    /**
     * @return array{
     *     queues_with_drains: int,
     *     draining_build_id_count: int,
     *     active_worker_count: int,
     *     draining_worker_count: int,
     *     stale_worker_count: int,
     *     queues: list<array<string, mixed>>
     * }
     */
    public function routingDrains(?string $namespace = null): array
    {
        $summary = [
            'queues_with_drains' => 0,
            'draining_build_id_count' => 0,
            'active_worker_count' => 0,
            'draining_worker_count' => 0,
            'stale_worker_count' => 0,
            'queues' => [],
        ];

        $scopes = $this->taskQueueScopes($namespace);
        if ($scopes === []) {
            return $summary;
        }

        $staleAfter = $this->workerStaleAfterSeconds();
        $queues = [];

        foreach ($scopes as $scope) {
            $buildIds = $this->buildIdEntriesForTaskQueue(
                $scope['namespace'],
                $scope['task_queue'],
                $staleAfter,
            );

            $drainingBuildIds = array_values(array_filter(
                $buildIds,
                static fn (array $entry): bool => ($entry['drain_intent'] ?? null) === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            ));

            if ($drainingBuildIds === []) {
                continue;
            }

            $activeWorkerCount = $this->sumCount($buildIds, 'active_worker_count');
            $drainingWorkerCount = $this->sumCount($buildIds, 'draining_worker_count');
            $staleWorkerCount = $this->sumCount($buildIds, 'stale_worker_count');

            $queues[] = [
                'namespace' => $scope['namespace'],
                'task_queue' => $scope['task_queue'],
                'active_build_id_count' => count(array_filter(
                    $buildIds,
                    static fn (array $entry): bool => in_array(
                        $entry['rollout_status'] ?? '',
                        ['active', 'active_with_draining'],
                        true,
                    ),
                )),
                'draining_build_id_count' => count($drainingBuildIds),
                'active_worker_count' => $activeWorkerCount,
                'draining_worker_count' => $drainingWorkerCount,
                'stale_worker_count' => $staleWorkerCount,
                'latest_drained_at' => $this->latestTimestamp($drainingBuildIds, 'drained_at'),
                'build_ids' => $drainingBuildIds,
            ];

            $summary['queues_with_drains']++;
            $summary['draining_build_id_count'] += count($drainingBuildIds);
            $summary['active_worker_count'] += $activeWorkerCount;
            $summary['draining_worker_count'] += $drainingWorkerCount;
            $summary['stale_worker_count'] += $staleWorkerCount;
        }

        usort($queues, function (array $a, array $b): int {
            $latestA = is_string($a['latest_drained_at'] ?? null) ? $a['latest_drained_at'] : null;
            $latestB = is_string($b['latest_drained_at'] ?? null) ? $b['latest_drained_at'] : null;

            if ($latestA !== $latestB) {
                if ($latestA === null) {
                    return 1;
                }

                if ($latestB === null) {
                    return -1;
                }

                return strcmp($latestB, $latestA);
            }

            $namespaceCompare = strcmp(
                (string) ($a['namespace'] ?? ''),
                (string) ($b['namespace'] ?? ''),
            );
            if ($namespaceCompare !== 0) {
                return $namespaceCompare;
            }

            return strcmp(
                (string) ($a['task_queue'] ?? ''),
                (string) ($b['task_queue'] ?? ''),
            );
        });

        $summary['queues'] = $queues;

        return $summary;
    }

    /**
     * @return list<array{namespace: string, task_queue: string}>
     */
    private function taskQueueScopes(?string $namespace = null): array
    {
        $scopes = [];

        if (Schema::hasTable('workflow_worker_registrations')) {
            $query = WorkerRegistration::query()
                ->select('namespace', 'task_queue')
                ->distinct();

            if ($namespace !== null) {
                $query->where('namespace', $namespace);
            }

            foreach ($query->get() as $row) {
                $rowNamespace = is_string($row->namespace) ? $row->namespace : null;
                $taskQueue = is_string($row->task_queue) && trim($row->task_queue) !== ''
                    ? trim($row->task_queue)
                    : null;

                if ($rowNamespace === null || $taskQueue === null) {
                    continue;
                }

                $scopes[$rowNamespace."\0".$taskQueue] = [
                    'namespace' => $rowNamespace,
                    'task_queue' => $taskQueue,
                ];
            }
        }

        if (Schema::hasTable('workflow_worker_build_id_rollouts')) {
            $query = WorkerBuildIdRollout::query()
                ->select('namespace', 'task_queue')
                ->distinct();

            if ($namespace !== null) {
                $query->where('namespace', $namespace);
            }

            foreach ($query->get() as $row) {
                $rowNamespace = is_string($row->namespace) ? $row->namespace : null;
                $taskQueue = is_string($row->task_queue) && trim($row->task_queue) !== ''
                    ? trim($row->task_queue)
                    : null;

                if ($rowNamespace === null || $taskQueue === null) {
                    continue;
                }

                $scopes[$rowNamespace."\0".$taskQueue] = [
                    'namespace' => $rowNamespace,
                    'task_queue' => $taskQueue,
                ];
            }
        }

        ksort($scopes);

        return array_values($scopes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildIdEntriesForTaskQueue(string $namespace, string $taskQueue, int $staleAfter): array
    {
        if (
            ! Schema::hasTable('workflow_worker_registrations')
            && ! Schema::hasTable('workflow_worker_build_id_rollouts')
        ) {
            return [];
        }

        $now = now();
        $groups = [];

        if (Schema::hasTable('workflow_worker_registrations')) {
            $workers = WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->where('task_queue', $taskQueue)
                ->orderByDesc('last_heartbeat_at')
                ->orderBy('worker_id')
                ->get();

            foreach ($workers as $worker) {
                $buildId = is_string($worker->build_id) && trim($worker->build_id) !== ''
                    ? trim($worker->build_id)
                    : null;
                $key = $buildId ?? '__unversioned__';

                $heartbeat = $worker->last_heartbeat_at;
                $isStale = $heartbeat && $heartbeat->lt($now->copy()->subSeconds($staleAfter));
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
                    'workflow_definition_fingerprints' => [],
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

                foreach ($this->workflowDefinitionFingerprints($worker->workflow_definition_fingerprints ?? []) as $workflowType => $fingerprint) {
                    $groups[$key]['workflow_definition_fingerprints'][$workflowType] ??= [];
                    $groups[$key]['workflow_definition_fingerprints'][$workflowType][$fingerprint] = true;
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
        }

        $rolloutMap = $this->rolloutsForTaskQueue($namespace, $taskQueue);
        $selectedNewStartKey = $this->newStartSelector->selectedKeyFromRollouts($rolloutMap);
        $pendingWorkflowTasks = $this->pendingWorkflowTaskCountsForTaskQueue($namespace, $taskQueue);
        $buildIds = [];

        foreach ($pendingWorkflowTasks as $key => $pending) {
            $groupKey = $key === '' ? '__unversioned__' : $key;

            if (isset($groups[$groupKey]) || isset($rolloutMap[$key])) {
                continue;
            }

            $groups[$groupKey] = [
                'build_id' => $pending['build_id'],
                'active_worker_count' => 0,
                'stale_worker_count' => 0,
                'draining_worker_count' => 0,
                'total_worker_count' => 0,
                'runtimes' => [],
                'sdk_versions' => [],
                'workflow_definition_fingerprints' => [],
                'last_heartbeat_at' => null,
                'first_seen_at' => null,
            ];
        }

        foreach ($groups as $group) {
            $runtimes = array_keys($group['runtimes']);
            sort($runtimes);
            $sdkVersions = array_keys($group['sdk_versions']);
            sort($sdkVersions);

            $rolloutKey = WorkerBuildIdRollout::buildIdKey($group['build_id']);
            $rollout = $rolloutMap[$rolloutKey] ?? null;
            $drainIntent = $rollout?->drain_intent ?? WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;
            $fingerprints = $this->fingerprintSummary($group['workflow_definition_fingerprints'] ?? []);
            $pending = $pendingWorkflowTasks[$rolloutKey] ?? $this->emptyPendingWorkflowTasks($group['build_id']);

            $buildIds[] = [
                'build_id' => $group['build_id'],
                'rollout_status' => $this->buildIdRolloutStatus(
                    $group['active_worker_count'],
                    $group['draining_worker_count'],
                    $group['stale_worker_count'],
                    $drainIntent,
                ),
                'drain_intent' => $drainIntent,
                'drained_at' => ControlPlaneTimestamp::zuluSecond($rollout?->drained_at),
                'promoted_at' => ControlPlaneTimestamp::zuluSecond($rollout?->promoted_at),
                'rolled_back_at' => ControlPlaneTimestamp::zuluSecond($rollout?->rolled_back_at),
                'new_start_selected' => $rolloutKey === $selectedNewStartKey,
                'active_worker_count' => $group['active_worker_count'],
                'draining_worker_count' => $group['draining_worker_count'],
                'stale_worker_count' => $group['stale_worker_count'],
                'total_worker_count' => $group['total_worker_count'],
                'runtimes' => $runtimes,
                'sdk_versions' => $sdkVersions,
                'workflow_definition_fingerprint_count' => $fingerprints['count'],
                'workflow_definition_fingerprint_conflicts' => $fingerprints['conflicts'],
                'last_heartbeat_at' => $group['last_heartbeat_at']?->toJSON(),
                'first_seen_at' => $group['first_seen_at']?->toJSON(),
                'pending_workflow_tasks' => $this->pendingWorkflowTaskDiagnostic(
                    $pending,
                    $group['active_worker_count'],
                ),
            ];
        }

        foreach ($rolloutMap as $key => $rollout) {
            if (isset($groups[$key === '' ? '__unversioned__' : $key])) {
                continue;
            }

            $pending = $pendingWorkflowTasks[$key] ?? $this->emptyPendingWorkflowTasks($rollout->publicBuildId());

            $buildIds[] = [
                'build_id' => $rollout->publicBuildId(),
                'rollout_status' => $this->buildIdRolloutStatus(
                    0,
                    0,
                    0,
                    $rollout->drain_intent,
                ),
                'drain_intent' => $rollout->drain_intent,
                'drained_at' => ControlPlaneTimestamp::zuluSecond($rollout->drained_at),
                'promoted_at' => ControlPlaneTimestamp::zuluSecond($rollout->promoted_at),
                'rolled_back_at' => ControlPlaneTimestamp::zuluSecond($rollout->rolled_back_at),
                'new_start_selected' => $key === $selectedNewStartKey,
                'active_worker_count' => 0,
                'draining_worker_count' => 0,
                'stale_worker_count' => 0,
                'total_worker_count' => 0,
                'runtimes' => [],
                'sdk_versions' => [],
                'workflow_definition_fingerprint_count' => 0,
                'workflow_definition_fingerprint_conflicts' => [],
                'last_heartbeat_at' => null,
                'first_seen_at' => null,
                'pending_workflow_tasks' => $this->pendingWorkflowTaskDiagnostic($pending, 0),
            ];
        }

        usort($buildIds, function (array $a, array $b): int {
            $rankA = $this->buildIdRolloutRank($a);
            $rankB = $this->buildIdRolloutRank($b);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return strcmp(
                (string) ($b['last_heartbeat_at'] ?? ''),
                (string) ($a['last_heartbeat_at'] ?? ''),
            );
        });

        return $buildIds;
    }

    /**
     * @return array<string, array{
     *     build_id: string|null,
     *     total_count: int,
     *     ready_count: int,
     *     leased_count: int
     * }>
     */
    private function pendingWorkflowTaskCountsForTaskQueue(string $namespace, string $taskQueue): array
    {
        if (! Schema::hasTable('workflow_tasks') || ! Schema::hasTable('workflow_runs')) {
            return [];
        }

        $compatibilityExpression = "COALESCE(NULLIF(TRIM(workflow_tasks.compatibility), ''), "
            ."NULLIF(TRIM(workflow_runs.compatibility), ''))";

        $rows = WorkflowTask::query()
            ->toBase()
            ->select('workflow_tasks.status')
            ->selectRaw($compatibilityExpression.' as effective_compatibility')
            ->selectRaw('COUNT(*) as task_count')
            ->leftJoin('workflow_runs', 'workflow_runs.id', '=', 'workflow_tasks.workflow_run_id')
            ->where('workflow_tasks.namespace', $namespace)
            ->where('workflow_tasks.task_type', TaskType::Workflow->value)
            ->where('workflow_tasks.queue', $taskQueue)
            ->whereIn('workflow_tasks.status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
            ->groupBy('workflow_tasks.status')
            ->groupByRaw($compatibilityExpression)
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $compatibility = $this->nonEmptyString($row->effective_compatibility ?? null);
            $key = WorkerBuildIdRollout::buildIdKey($compatibility);
            $counts[$key] ??= $this->emptyPendingWorkflowTasks($compatibility);

            $taskCount = max(0, (int) ($row->task_count ?? 0));
            if ($taskCount === 0) {
                continue;
            }

            $counts[$key]['total_count'] += $taskCount;

            $status = $this->nonEmptyString($row->status ?? null);
            if ($status === TaskStatus::Leased->value) {
                $counts[$key]['leased_count'] += $taskCount;
            } else {
                $counts[$key]['ready_count'] += $taskCount;
            }
        }

        return $counts;
    }

    /**
     * @return array{build_id: string|null, total_count: int, ready_count: int, leased_count: int}
     */
    private function emptyPendingWorkflowTasks(?string $buildId): array
    {
        return [
            'build_id' => $buildId,
            'total_count' => 0,
            'ready_count' => 0,
            'leased_count' => 0,
        ];
    }

    /**
     * @param array{build_id: string|null, total_count: int, ready_count: int, leased_count: int} $pending
     * @return array{
     *     status: string,
     *     operator_visible_signal: string|null,
     *     message: string|null,
     *     total_count: int,
     *     ready_count: int,
     *     leased_count: int
     * }
     */
    private function pendingWorkflowTaskDiagnostic(array $pending, int $activeWorkerCount): array
    {
        $total = max(0, (int) $pending['total_count']);
        $ready = max(0, (int) $pending['ready_count']);
        $leased = max(0, (int) $pending['leased_count']);
        $buildId = $pending['build_id'];
        $status = 'idle';
        $signal = null;
        $message = null;

        if ($total > 0) {
            $status = 'pending';

            if ($buildId !== null && $activeWorkerCount === 0) {
                $status = 'no_compatible_worker';
                $signal = 'no_compatible_worker';
                $message = sprintf(
                    'This build id has %d pending workflow task%s but no active compatible worker.',
                    $total,
                    $total === 1 ? '' : 's',
                );
            }
        }

        return [
            'status' => $status,
            'operator_visible_signal' => $signal,
            'message' => $message,
            'total_count' => $total,
            'ready_count' => $ready,
            'leased_count' => $leased,
        ];
    }

    /**
     * @return array<string, WorkerBuildIdRollout>
     */
    private function rolloutsForTaskQueue(string $namespace, string $taskQueue): array
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return [];
        }

        $map = [];

        foreach (WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->get() as $rollout) {
            $map[(string) $rollout->build_id] = $rollout;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function workflowDefinitionFingerprints(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $fingerprints = [];

        foreach ($value as $workflowType => $fingerprint) {
            if (! is_string($workflowType) || ! is_string($fingerprint)) {
                continue;
            }

            $workflowType = trim($workflowType);
            $fingerprint = trim($fingerprint);

            if ($workflowType === '' || $fingerprint === '') {
                continue;
            }

            $fingerprints[$workflowType] = $fingerprint;
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    /**
     * @param array<string, array<string, true>> $fingerprintsByWorkflowType
     * @return array{count: int, conflicts: list<array{workflow_type: string, fingerprint_count: int}>}
     */
    private function fingerprintSummary(array $fingerprintsByWorkflowType): array
    {
        $count = 0;
        $conflicts = [];

        foreach ($fingerprintsByWorkflowType as $workflowType => $fingerprints) {
            if (! is_array($fingerprints)) {
                continue;
            }

            $fingerprintCount = count($fingerprints);
            $count += $fingerprintCount;

            if ($fingerprintCount > 1) {
                $conflicts[] = [
                    'workflow_type' => (string) $workflowType,
                    'fingerprint_count' => $fingerprintCount,
                ];
            }
        }

        usort(
            $conflicts,
            static fn (array $a, array $b): int => strcmp($a['workflow_type'], $b['workflow_type']),
        );

        return [
            'count' => $count,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function sumCount(array $entries, string $field): int
    {
        return array_reduce($entries, static function (int $carry, array $entry) use ($field): int {
            return $carry + (is_numeric($entry[$field] ?? null) ? (int) $entry[$field] : 0);
        }, 0);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function latestTimestamp(array $entries, string $field): ?string
    {
        $timestamps = array_values(array_filter(
            array_map(
                static fn (array $entry): ?string => is_string($entry[$field] ?? null) ? $entry[$field] : null,
                $entries,
            ),
            static fn (?string $value): bool => $value !== null && $value !== '',
        ));

        if ($timestamps === []) {
            return null;
        }

        rsort($timestamps);

        return $timestamps[0];
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
            return 'draining';
        }

        return $stale > 0 ? 'stale_only' : 'no_workers';
    }

    /**
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

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
