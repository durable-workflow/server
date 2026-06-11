<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Support\StandaloneWorkerVisibility;

/**
 * Resolve the worker build that a new workflow run should be pinned
 * to at start time.
 *
 * The pin is the durable answer to "which worker version started
 * this workflow?" — once stamped on the run, the workflow-task
 * poller uses it to refuse delivery to workers running a different
 * build, so a v1-started workflow keeps replaying on v1 even after
 * v2 workers join the same task queue.
 *
 * Resolution order:
 *
 *   1. Promoted rollout: the most-recently `promoted_at` rollout for
 *      `(namespace, task_queue)` that has not been rolled back and is
 *      not draining. This is the explicit operator command for "new
 *      starts go here."
 *   2. Single-cohort fleet fallback: if no rollout has been promoted
 *      yet, but the live fleet for the queue has exactly one active
 *      build-id cohort, pin to it. If the live fleet is unversioned
 *      only, keep the run unpinned while still exposing the
 *      unversioned contract scope for command-contract enrichment.
 *   3. Otherwise: null with no contract scope. The run stays
 *      unversioned and any worker may claim it (legacy behavior).
 */
final class WorkflowStartVersionPin
{
    public const CONTRACT_SCOPE_BUILD_ID = 'build_id';

    public const CONTRACT_SCOPE_UNVERSIONED = 'unversioned';

    public const CONTRACT_SCOPE_NONE = 'none';

    public function __construct(
        private readonly WorkerBuildIdNewStartSelector $newStartSelector,
    ) {}

    public function resolve(string $namespace, string $taskQueue): ?string
    {
        return $this->resolveForStart($namespace, $taskQueue)['build_id'];
    }

    /**
     * @return array{
     *     build_id: string|null,
     *     contract_build_id: string|null,
     *     contract_scope: string,
     * }
     */
    public function resolveForStart(string $namespace, string $taskQueue): array
    {
        $promoted = $this->promotedBuildId($namespace, $taskQueue);

        if ($promoted['found']) {
            return [
                'build_id' => $promoted['build_id'],
                'contract_build_id' => $promoted['build_id'],
                'contract_scope' => $promoted['build_id'] === null
                    ? self::CONTRACT_SCOPE_UNVERSIONED
                    : self::CONTRACT_SCOPE_BUILD_ID,
            ];
        }

        $singleActiveCohort = $this->singleActiveCohort($namespace, $taskQueue);

        if ($singleActiveCohort !== null) {
            return [
                'build_id' => $singleActiveCohort['build_id'],
                'contract_build_id' => $singleActiveCohort['contract_build_id'],
                'contract_scope' => $singleActiveCohort['contract_scope'],
            ];
        }

        return [
            'build_id' => null,
            'contract_build_id' => null,
            'contract_scope' => self::CONTRACT_SCOPE_NONE,
        ];
    }

    /**
     * @return array{found: bool, build_id: string|null}
     */
    private function promotedBuildId(string $namespace, string $taskQueue): array
    {
        $rollout = $this->newStartSelector->selectedRolloutForTaskQueue($namespace, $taskQueue);

        if (! $rollout instanceof WorkerBuildIdRollout) {
            return ['found' => false, 'build_id' => null];
        }

        return ['found' => true, 'build_id' => $rollout->publicBuildId()];
    }

    /**
     * @return array{
     *     build_id: string|null,
     *     contract_build_id: string|null,
     *     contract_scope: string,
     * }|null
     */
    private function singleActiveCohort(string $namespace, string $taskQueue): ?array
    {
        if (! Schema::hasTable('workflow_worker_registrations')) {
            return null;
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

        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where(function ($builder) use ($cutoff): void {
                $builder->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '>=', $cutoff);
            })
            ->where(function ($builder): void {
                $builder->whereNull('status')
                    ->orWhere('status', WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE);
            })
            ->get(['build_id']);

        $buildIds = [];
        $hasUnversionedWorker = false;

        foreach ($workers as $worker) {
            $buildId = is_string($worker->build_id) ? trim($worker->build_id) : '';

            if ($buildId === '') {
                $hasUnversionedWorker = true;

                continue;
            }

            $buildIds[$buildId] = true;
        }

        if (count($buildIds) === 1) {
            $buildId = (string) array_key_first($buildIds);

            return [
                'build_id' => $buildId,
                'contract_build_id' => $buildId,
                'contract_scope' => self::CONTRACT_SCOPE_BUILD_ID,
            ];
        }

        if ($hasUnversionedWorker && count($buildIds) === 0) {
            return [
                'build_id' => null,
                'contract_build_id' => null,
                'contract_scope' => self::CONTRACT_SCOPE_UNVERSIONED,
            ];
        }

        return null;
    }
}
