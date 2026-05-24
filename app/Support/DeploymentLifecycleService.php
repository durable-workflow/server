<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Workflow\V2\Enums\DeploymentBlockageReason;
use Workflow\V2\Enums\DeploymentLifecycleState;
use Workflow\V2\Support\DeploymentBlockage;
use Workflow\V2\Support\DeploymentLifecyclePlan;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkerDeployment;

/**
 * Server-side authority that projects the legacy
 * {@see WorkerBuildIdRollout} rows into first-class
 * {@see WorkerDeployment} objects, runs the lifecycle plan against
 * the live fleet snapshot, and applies promote/drain/resume/rollback
 * transitions.
 *
 * The service is the single read/write surface the deployment HTTP
 * controller and the Waterline backend consult so the legacy
 * `/api/task-queues/{taskQueue}/build-ids/drain` route and the new
 * `/api/deployments/{name}/drain` route mutate the same durable
 * surface in compatible ways.
 */
final class DeploymentLifecycleService
{
    public function __construct(
        private readonly WorkerBuildIdNewStartSelector $newStartSelector,
    ) {}

    /**
     * Parse a deployment name in the form `namespace/task_queue@build_id`
     * (or `@unversioned` for the pre-rollout cohort) into the tuple the
     * rest of the surface uses. The format mirrors {@see WorkerDeployment::name()}.
     *
     * @return array{namespace: string, task_queue: string, build_id: string|null}
     */
    public function parseName(string $name): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Deployment name must not be empty.');
        }

        $atPos = strrpos($name, '@');

        if ($atPos === false) {
            throw new InvalidArgumentException(
                'Deployment name must be of the form namespace/task_queue@build_id (use "@unversioned" for the unversioned cohort).',
            );
        }

        $scope = substr($name, 0, $atPos);
        $buildSegment = substr($name, $atPos + 1);

        $slashPos = strpos($scope, '/');

        if ($slashPos === false) {
            throw new InvalidArgumentException(
                'Deployment name must include a namespace and task queue separated by "/".',
            );
        }

        $namespace = trim(substr($scope, 0, $slashPos));
        $taskQueue = trim(substr($scope, $slashPos + 1));
        $buildSegment = trim($buildSegment);

        if ($namespace === '' || $taskQueue === '' || $buildSegment === '') {
            throw new InvalidArgumentException(
                'Deployment name must include namespace, task queue, and build id (or "unversioned").',
            );
        }

        return [
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $buildSegment === 'unversioned' ? null : $buildSegment,
        ];
    }

    /**
     * @return list<WorkerDeployment>
     */
    public function listForNamespace(string $namespace): array
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return $this->deploymentsFromWorkerRegistrations($namespace, []);
        }

        $rollouts = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->orderBy('task_queue')
            ->orderBy('build_id')
            ->get();

        $rolloutMap = [];

        foreach ($rollouts as $row) {
            $key = sprintf('%s|%s', (string) $row->task_queue, (string) $row->build_id);
            $rolloutMap[$key] = $row;
        }

        return $this->deploymentsFromWorkerRegistrations($namespace, $rolloutMap);
    }

    public function find(string $namespace, string $taskQueue, ?string $buildId): ?WorkerDeployment
    {
        $rollout = $this->loadRollout($namespace, $taskQueue, $buildId);

        if ($rollout !== null) {
            return $this->deploymentFromRollout($rollout);
        }

        $worker = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->when(
                $buildId !== null,
                fn ($q) => $q->where('build_id', $buildId),
                fn ($q) => $q->where(function ($w) {
                    $w->whereNull('build_id')->orWhere('build_id', '');
                }),
            )
            ->orderByDesc('last_heartbeat_at')
            ->first();

        if ($worker === null) {
            return null;
        }

        return WorkerDeployment::fromRolloutRow([
            'namespace' => $namespace,
            'task_queue' => $taskQueue,
            'build_id' => $buildId,
            'drain_intent' => 'active',
            'required_compatibility' => $this->resolveBuildIdAsCompatibility($worker),
        ]);
    }

    /**
     * @return array{
     *     deployment: WorkerDeployment|null,
     *     blockages: list<array<string, mixed>>
     * }
     */
    public function promote(string $namespace, string $taskQueue, ?string $buildId): array
    {
        $deployment = $this->find($namespace, $taskQueue, $buildId);

        if ($deployment === null) {
            return [
                'deployment' => null,
                'blockages' => [
                    (new DeploymentBlockage(
                        reason: \Workflow\V2\Enums\DeploymentBlockageReason::UnknownDeployment,
                        message: sprintf(
                            'No deployment is registered for %s/%s@%s.',
                            $namespace,
                            $taskQueue,
                            $buildId ?? 'unversioned',
                        ),
                        scope: [
                            'namespace' => $namespace,
                            'task_queue' => $taskQueue,
                            'build_id' => $buildId,
                        ],
                        expectedResolution: 'Roll a worker that heartbeats this build id, then retry promotion.',
                    ))->toArray(),
                ],
            ];
        }

        $fleet = $this->fleetSnapshot($namespace, $taskQueue, $deployment->requiredCompatibility);
        $blockages = DeploymentLifecyclePlan::evaluatePromote($deployment, $fleet);
        $blockages = $this->normalizePromotionBlockages($deployment, $fleet, $blockages);

        if ($blockages !== []) {
            return [
                'deployment' => $deployment,
                'blockages' => array_map(static fn (DeploymentBlockage $b) => $b->toArray(), $blockages),
            ];
        }

        $rollout = $this->upsertRollout($namespace, $taskQueue, $buildId, function (WorkerBuildIdRollout $row) {
            $row->drain_intent = WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;
            $row->drained_at = null;
            $row->promoted_at = now();
            $row->rolled_back_at = null;
        });

        return [
            'deployment' => $this->deploymentFromRollout($rollout),
            'blockages' => [],
        ];
    }

    /**
     * @return array{
     *     deployment: WorkerDeployment|null,
     *     blockages: list<array<string, mixed>>
     * }
     */
    public function drain(string $namespace, string $taskQueue, ?string $buildId): array
    {
        $deployment = $this->find($namespace, $taskQueue, $buildId)
            ?? WorkerDeployment::forActiveBuild($namespace, $taskQueue, $buildId);

        $blockages = DeploymentLifecyclePlan::evaluateDrain($deployment);

        if ($blockages !== []) {
            return [
                'deployment' => $deployment,
                'blockages' => array_map(static fn (DeploymentBlockage $b) => $b->toArray(), $blockages),
            ];
        }

        $rollout = $this->upsertRollout($namespace, $taskQueue, $buildId, function (WorkerBuildIdRollout $row) {
            $wasDraining = $row->drain_intent === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING;
            $row->drain_intent = WorkerBuildIdRollout::DRAIN_INTENT_DRAINING;
            $row->drained_at = $wasDraining ? $row->drained_at : now();
        });

        $this->stampWorkerDrainStatus(
            $namespace,
            $taskQueue,
            $buildId,
            WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            onlyDraining: false,
        );

        return [
            'deployment' => $this->deploymentFromRollout($rollout),
            'blockages' => [],
        ];
    }

    /**
     * @return array{
     *     deployment: WorkerDeployment|null,
     *     blockages: list<array<string, mixed>>
     * }
     */
    public function resume(string $namespace, string $taskQueue, ?string $buildId): array
    {
        $deployment = $this->find($namespace, $taskQueue, $buildId)
            ?? WorkerDeployment::forActiveBuild($namespace, $taskQueue, $buildId);

        $blockages = DeploymentLifecyclePlan::evaluateResume($deployment);

        if ($blockages !== []) {
            return [
                'deployment' => $deployment,
                'blockages' => array_map(static fn (DeploymentBlockage $b) => $b->toArray(), $blockages),
            ];
        }

        $rollout = $this->upsertRollout($namespace, $taskQueue, $buildId, function (WorkerBuildIdRollout $row) {
            $row->drain_intent = WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;
            $row->drained_at = null;
        });

        $this->stampWorkerDrainStatus($namespace, $taskQueue, $buildId, 'active', onlyDraining: true);

        return [
            'deployment' => $this->deploymentFromRollout($rollout),
            'blockages' => [],
        ];
    }

    /**
     * @return array{
     *     deployment: WorkerDeployment|null,
     *     blockages: list<array<string, mixed>>
     * }
     */
    public function rollback(string $namespace, string $taskQueue, ?string $buildId): array
    {
        $deployment = $this->find($namespace, $taskQueue, $buildId);

        if ($deployment === null) {
            return [
                'deployment' => null,
                'blockages' => [
                    (new DeploymentBlockage(
                        reason: \Workflow\V2\Enums\DeploymentBlockageReason::UnknownDeployment,
                        message: sprintf(
                            'No deployment is registered for %s/%s@%s; nothing to roll back.',
                            $namespace,
                            $taskQueue,
                            $buildId ?? 'unversioned',
                        ),
                        scope: [
                            'namespace' => $namespace,
                            'task_queue' => $taskQueue,
                            'build_id' => $buildId,
                        ],
                    ))->toArray(),
                ],
            ];
        }

        $blockages = DeploymentLifecyclePlan::evaluateRollback($deployment);

        if ($blockages !== []) {
            return [
                'deployment' => $deployment,
                'blockages' => array_map(static fn (DeploymentBlockage $b) => $b->toArray(), $blockages),
            ];
        }

        $rollout = $this->upsertRollout($namespace, $taskQueue, $buildId, function (WorkerBuildIdRollout $row) {
            $row->rolled_back_at = now();
            $row->promoted_at = null;
        });

        return [
            'deployment' => $this->deploymentFromRollout($rollout)
                ->withState(DeploymentLifecycleState::RolledBack),
            'blockages' => [],
        ];
    }

    /**
     * @return array{
     *     active_worker_count: int,
     *     active_workers_supporting_required: int,
     *     advertised_compatibility: list<string>,
     *     advertised_fingerprints: list<string>,
     *     replay_safety_severity: string|null,
     *     replay_safety_messages: list<string>,
     *     stale_workers_supporting_required: int,
     *     last_stale_required_heartbeat_at: string|null
     * }
     */
    public function fleetSnapshot(string $namespace, string $taskQueue, ?string $required): array
    {
        $details = WorkerCompatibilityFleet::detailsForNamespace(
            $namespace,
            $required,
            connection: null,
            queue: $taskQueue,
        );

        $activeWorkers = [];
        $supportingWorkers = [];
        $advertised = [];

        foreach ($details as $entry) {
            $workerKey = $this->fleetWorkerKey($entry['worker_id'] ?? null, 'compatibility', count($activeWorkers));
            $activeWorkers[$workerKey] = true;

            if ($entry['supports_required'] ?? false) {
                $supportingWorkers[$workerKey] = true;
            }
            foreach ($entry['supported'] ?? [] as $marker) {
                if (is_string($marker) && $marker !== '') {
                    $advertised[$marker] = true;
                }
            }
        }

        $registrationFleet = $this->workerRegistrationFleetSnapshot($namespace, $taskQueue, $required);

        foreach ($registrationFleet['active_workers'] as $workerId => $worker) {
            $activeWorkers[$workerId] = true;

            if (($worker['supports_required'] ?? false) === true) {
                $supportingWorkers[$workerId] = true;
            }

            $buildId = $worker['build_id'] ?? null;
            if (is_string($buildId) && $buildId !== '') {
                $advertised[$buildId] = true;
            }
        }

        $advertisedList = array_keys($advertised);
        sort($advertisedList);

        return [
            'active_worker_count' => count($activeWorkers),
            'active_workers_supporting_required' => count($supportingWorkers),
            'advertised_compatibility' => $advertisedList,
            'advertised_fingerprints' => [],
            'replay_safety_severity' => null,
            'replay_safety_messages' => [],
            'stale_workers_supporting_required' => $registrationFleet['stale_workers_supporting_required'],
            'last_stale_required_heartbeat_at' => $registrationFleet['last_stale_required_heartbeat_at'],
        ];
    }

    public function deploymentPayload(WorkerDeployment $deployment): array
    {
        return [
            ...$deployment->toArray(),
            'new_start_selected' => $this->newStartSelector->selectedKeyForTaskQueue(
                $deployment->namespace,
                $deployment->taskQueue,
            ) === WorkerBuildIdRollout::buildIdKey($deployment->buildId),
        ];
    }

    private function deploymentFromRollout(WorkerBuildIdRollout $row): WorkerDeployment
    {
        return WorkerDeployment::fromRolloutRow([
            'namespace' => (string) $row->namespace,
            'task_queue' => (string) $row->task_queue,
            'build_id' => $row->publicBuildId(),
            'drain_intent' => $row->drain_intent,
            'drained_at' => $row->drained_at,
            'promoted_at' => $row->promoted_at ?? null,
            'rolled_back_at' => $row->rolled_back_at ?? null,
            'required_compatibility' => $row->required_compatibility ?? $row->publicBuildId(),
            'recorded_fingerprint' => $row->recorded_fingerprint ?? null,
            'workflow_types' => is_array($row->workflow_types ?? null) ? $row->workflow_types : [],
            'compatibility_policy' => $row->compatibility_policy ?? null,
        ]);
    }

    private function loadRollout(string $namespace, string $taskQueue, ?string $buildId): ?WorkerBuildIdRollout
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return null;
        }

        return WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('build_id', WorkerBuildIdRollout::buildIdKey($buildId))
            ->first();
    }

    private function upsertRollout(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        callable $apply,
    ): WorkerBuildIdRollout {
        return DB::transaction(function () use ($namespace, $taskQueue, $buildId, $apply): WorkerBuildIdRollout {
            $rollout = WorkerBuildIdRollout::query()->firstOrNew([
                'namespace' => $namespace,
                'task_queue' => $taskQueue,
                'build_id' => WorkerBuildIdRollout::buildIdKey($buildId),
            ]);

            if (! $rollout->exists) {
                $rollout->drain_intent = WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;
            }

            $apply($rollout);
            $rollout->save();

            return $rollout->refresh();
        });
    }

    private function stampWorkerDrainStatus(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
        string $status,
        bool $onlyDraining,
    ): void {
        WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->when(
                $buildId !== null,
                fn ($query) => $query->where('build_id', $buildId),
                fn ($query) => $query->where(function ($worker) {
                    $worker->whereNull('build_id')->orWhere('build_id', '');
                }),
            )
            ->when(
                $onlyDraining,
                fn ($query) => $query->where('status', WorkerBuildIdRollout::DRAIN_INTENT_DRAINING),
            )
            ->update(['status' => $status]);
    }

    /**
     * @param array<string, WorkerBuildIdRollout> $rolloutMap
     * @return list<WorkerDeployment>
     */
    private function deploymentsFromWorkerRegistrations(string $namespace, array $rolloutMap): array
    {
        $seen = [];
        $deployments = [];

        if (Schema::hasTable('workflow_worker_registrations')) {
            $workers = WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->orderBy('task_queue')
                ->orderBy('build_id')
                ->get();

            foreach ($workers as $worker) {
                $taskQueue = is_string($worker->task_queue) ? trim($worker->task_queue) : '';

                if ($taskQueue === '') {
                    continue;
                }

                $buildId = is_string($worker->build_id) && trim($worker->build_id) !== ''
                    ? trim($worker->build_id)
                    : null;

                $key = sprintf('%s|%s', $taskQueue, $buildId ?? '');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;

                $rolloutKey = sprintf('%s|%s', $taskQueue, WorkerBuildIdRollout::buildIdKey($buildId));
                $rollout = $rolloutMap[$rolloutKey] ?? null;

                $deployments[] = $rollout !== null
                    ? $this->deploymentFromRollout($rollout)
                    : WorkerDeployment::fromRolloutRow([
                        'namespace' => $namespace,
                        'task_queue' => $taskQueue,
                        'build_id' => $buildId,
                        'drain_intent' => 'active',
                        'required_compatibility' => $this->resolveBuildIdAsCompatibility($worker),
                    ]);
            }
        }

        foreach ($rolloutMap as $key => $rollout) {
            if (isset($seen[$key])) {
                continue;
            }

            $deployments[] = $this->deploymentFromRollout($rollout);
        }

        return $deployments;
    }

    private function resolveBuildIdAsCompatibility(WorkerRegistration $worker): ?string
    {
        $buildId = is_string($worker->build_id) && trim($worker->build_id) !== ''
            ? trim($worker->build_id)
            : null;

        return $buildId;
    }

    /**
     * @param  list<DeploymentBlockage>  $blockages
     * @return list<DeploymentBlockage>
     */
    private function normalizePromotionBlockages(
        WorkerDeployment $deployment,
        array $fleet,
        array $blockages,
    ): array {
        $staleSupporting = (int) ($fleet['stale_workers_supporting_required'] ?? 0);

        return array_map(function (DeploymentBlockage $blockage) use ($deployment, $fleet, $staleSupporting): DeploymentBlockage {
            if (
                $blockage->reason === DeploymentBlockageReason::NoCompatibleWorkers
                && $deployment->requiredCompatibility !== null
                && $staleSupporting > 0
            ) {
                return $this->missingWorkerHeartbeatBlockage($deployment, $fleet, $staleSupporting);
            }

            if (
                $blockage->reason === DeploymentBlockageReason::MissingWorkerHeartbeat
                && $deployment->requiredCompatibility !== null
                && $staleSupporting === 0
            ) {
                return $this->noCompatibleWorkersBlockage($deployment, $fleet);
            }

            return $blockage;
        }, $blockages);
    }

    private function noCompatibleWorkersBlockage(WorkerDeployment $deployment, array $fleet): DeploymentBlockage
    {
        $advertised = $this->stringList($fleet['advertised_compatibility'] ?? []);
        $advertisedText = $advertised === [] ? 'none' : implode(', ', $advertised);

        return new DeploymentBlockage(
            reason: DeploymentBlockageReason::NoCompatibleWorkers,
            message: sprintf(
                'No active worker on %s/%s advertises required compatibility [%s]; active workers advertise [%s].',
                $deployment->namespace,
                $deployment->taskQueue,
                $deployment->requiredCompatibility,
                $advertisedText,
            ),
            scope: $this->blockageScope($deployment),
            expectedResolution: 'Start at least one worker that advertises compatibility ['
                .$deployment->requiredCompatibility
                .'] for this task queue, then retry promotion.',
        );
    }

    private function missingWorkerHeartbeatBlockage(
        WorkerDeployment $deployment,
        array $fleet,
        int $staleSupporting,
    ): DeploymentBlockage {
        $lastHeartbeat = $fleet['last_stale_required_heartbeat_at'] ?? null;
        $suffix = is_string($lastHeartbeat) && $lastHeartbeat !== ''
            ? sprintf(' Last matching heartbeat was %s.', $lastHeartbeat)
            : '';

        return new DeploymentBlockage(
            reason: DeploymentBlockageReason::MissingWorkerHeartbeat,
            message: sprintf(
                '%d worker(s) for %s/%s advertise required compatibility [%s], but none have a fresh heartbeat.%s',
                $staleSupporting,
                $deployment->namespace,
                $deployment->taskQueue,
                $deployment->requiredCompatibility,
                $suffix,
            ),
            scope: $this->blockageScope($deployment),
            expectedResolution: 'Restart or heartbeat a worker that advertises compatibility ['
                .$deployment->requiredCompatibility
                .'] for this task queue, then retry promotion.',
        );
    }

    /**
     * @return array{
     *     active_workers: array<string, array{build_id: string|null, supports_required: bool}>,
     *     stale_workers_supporting_required: int,
     *     last_stale_required_heartbeat_at: string|null
     * }
     */
    private function workerRegistrationFleetSnapshot(string $namespace, string $taskQueue, ?string $required): array
    {
        $summary = [
            'active_workers' => [],
            'stale_workers_supporting_required' => 0,
            'last_stale_required_heartbeat_at' => null,
        ];

        if (! Schema::hasTable('workflow_worker_registrations')) {
            return $summary;
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

        foreach (WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->orderBy('worker_id')
            ->get() as $worker) {
            $buildId = is_string($worker->build_id) && trim($worker->build_id) !== ''
                ? trim($worker->build_id)
                : null;
            $supportsRequired = $required === null || $buildId === $required;
            $heartbeat = $worker->last_heartbeat_at;
            $isStale = $heartbeat !== null && $heartbeat->lt($cutoff);
            $status = is_string($worker->status) && trim($worker->status) !== ''
                ? trim($worker->status)
                : WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE;

            if ($isStale) {
                if ($supportsRequired && $required !== null) {
                    $summary['stale_workers_supporting_required']++;
                    $current = $summary['last_stale_required_heartbeat_at'];
                    if ($heartbeat !== null && ($current === null || $heartbeat->gt($current))) {
                        $summary['last_stale_required_heartbeat_at'] = $heartbeat;
                    }
                }

                continue;
            }

            if ($status === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
                continue;
            }

            $summary['active_workers'][$this->fleetWorkerKey($worker->worker_id, 'registration', count($summary['active_workers']))] = [
                'build_id' => $buildId,
                'supports_required' => $supportsRequired,
            ];
        }

        $summary['last_stale_required_heartbeat_at'] = $summary['last_stale_required_heartbeat_at']?->toJSON();

        return $summary;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function blockageScope(WorkerDeployment $deployment): array
    {
        $scope = [
            'namespace' => $deployment->namespace,
            'task_queue' => $deployment->taskQueue,
            'build_id' => $deployment->buildId,
            'state' => $deployment->state->value,
        ];

        if ($deployment->requiredCompatibility !== null) {
            $scope['required_compatibility'] = $deployment->requiredCompatibility;
        }

        return $scope;
    }

    private function fleetWorkerKey(mixed $workerId, string $source, int $offset): string
    {
        if (is_string($workerId) && trim($workerId) !== '') {
            return trim($workerId);
        }

        return sprintf('%s:%d', $source, $offset);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $entry = trim($entry);

            if ($entry !== '') {
                $strings[$entry] = true;
            }
        }

        $strings = array_keys($strings);
        sort($strings);

        return $strings;
    }
}
