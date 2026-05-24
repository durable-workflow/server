<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
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
            'deployment' => $this->deploymentFromRollout($rollout)
                ->withState(DeploymentLifecycleState::Promoted),
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
     *     replay_safety_messages: list<string>
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

        $active = 0;
        $supporting = 0;
        $advertised = [];

        foreach ($details as $entry) {
            $active++;
            if ($entry['supports_required'] ?? false) {
                $supporting++;
            }
            foreach ($entry['supported'] ?? [] as $marker) {
                if (is_string($marker) && $marker !== '') {
                    $advertised[$marker] = true;
                }
            }
        }

        $advertisedList = array_keys($advertised);
        sort($advertisedList);

        return [
            'active_worker_count' => $active,
            'active_workers_supporting_required' => $supporting,
            'advertised_compatibility' => $advertisedList,
            'advertised_fingerprints' => [],
            'replay_safety_severity' => null,
            'replay_safety_messages' => [],
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
}
