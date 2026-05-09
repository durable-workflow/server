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
 *   2. Single-build fleet fallback: if no rollout has been promoted
 *      yet, but the live fleet for the queue has exactly one
 *      non-empty, non-draining build id, pin to it. This keeps the
 *      common single-version deployment correctly pinned without
 *      requiring an upfront promote.
 *   3. Otherwise: null. The run stays unversioned and any worker may
 *      claim it (legacy behavior).
 */
final class WorkflowStartVersionPin
{
    public function resolve(string $namespace, string $taskQueue): ?string
    {
        $promoted = $this->promotedBuildId($namespace, $taskQueue);

        if ($promoted !== null) {
            return $promoted;
        }

        return $this->singleActiveBuildId($namespace, $taskQueue);
    }

    private function promotedBuildId(string $namespace, string $taskQueue): ?string
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return null;
        }

        $hasPromotedAt = Schema::hasColumn('workflow_worker_build_id_rollouts', 'promoted_at');
        $hasRolledBackAt = Schema::hasColumn('workflow_worker_build_id_rollouts', 'rolled_back_at');

        $query = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('drain_intent', WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE)
            ->where('build_id', '!=', WorkerBuildIdRollout::UNVERSIONED_KEY);

        if ($hasRolledBackAt) {
            $query->whereNull('rolled_back_at');
        }

        if ($hasPromotedAt) {
            $query->whereNotNull('promoted_at')->orderByDesc('promoted_at');
        }

        $rollout = $query->orderByDesc('id')->first();

        return $rollout?->publicBuildId();
    }

    private function singleActiveBuildId(string $namespace, string $taskQueue): ?string
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
            ->whereNotNull('build_id')
            ->where('build_id', '!=', '')
            ->where(function ($builder) use ($cutoff): void {
                $builder->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '>=', $cutoff);
            })
            ->where(function ($builder): void {
                $builder->whereNull('status')
                    ->orWhere('status', '!=', WorkerBuildIdRollout::DRAIN_INTENT_DRAINING);
            })
            ->get(['build_id']);

        $buildIds = [];

        foreach ($workers as $worker) {
            $buildId = is_string($worker->build_id) ? trim($worker->build_id) : '';

            if ($buildId === '') {
                continue;
            }

            $buildIds[$buildId] = true;
        }

        if (count($buildIds) !== 1) {
            return null;
        }

        return (string) array_key_first($buildIds);
    }
}
