<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use Illuminate\Support\Facades\Schema;

final class WorkerBuildIdNewStartSelector
{
    public function selectedRolloutForTaskQueue(string $namespace, string $taskQueue): ?WorkerBuildIdRollout
    {
        if (! Schema::hasTable('workflow_worker_build_id_rollouts')) {
            return null;
        }

        $query = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('drain_intent', WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE);

        if (Schema::hasColumn('workflow_worker_build_id_rollouts', 'rolled_back_at')) {
            $query->whereNull('rolled_back_at');
        }

        if (Schema::hasColumn('workflow_worker_build_id_rollouts', 'promoted_at')) {
            $query->whereNotNull('promoted_at')
                ->orderByDesc('promoted_at');
        }

        $rollout = $query->orderByDesc('id')->first();

        return $rollout instanceof WorkerBuildIdRollout ? $rollout : null;
    }

    public function selectedKeyForTaskQueue(string $namespace, string $taskQueue): ?string
    {
        $rollout = $this->selectedRolloutForTaskQueue($namespace, $taskQueue);

        return $rollout instanceof WorkerBuildIdRollout ? (string) $rollout->build_id : null;
    }

    /**
     * @param iterable<string, WorkerBuildIdRollout> $rollouts
     */
    public function selectedKeyFromRollouts(iterable $rollouts): ?string
    {
        $selectedKey = null;
        $selectedRollout = null;

        foreach ($rollouts as $key => $rollout) {
            if (! $this->isSelectable($rollout)) {
                continue;
            }

            if (
                $selectedRollout === null
                || $rollout->promoted_at->gt($selectedRollout->promoted_at)
                || (
                    $rollout->promoted_at->equalTo($selectedRollout->promoted_at)
                    && $this->rolloutId($rollout) > $this->rolloutId($selectedRollout)
                )
            ) {
                $selectedKey = (string) $key;
                $selectedRollout = $rollout;
            }
        }

        return $selectedKey;
    }

    public function isSelected(WorkerBuildIdRollout $rollout): bool
    {
        $namespace = is_string($rollout->namespace) ? trim($rollout->namespace) : '';
        $taskQueue = is_string($rollout->task_queue) ? trim($rollout->task_queue) : '';

        if ($namespace === '' || $taskQueue === '') {
            return false;
        }

        $selected = $this->selectedRolloutForTaskQueue($namespace, $taskQueue);

        return $selected !== null
            && (string) $selected->build_id === (string) $rollout->build_id
            && $this->isSelectable($rollout);
    }

    private function isSelectable(WorkerBuildIdRollout $rollout): bool
    {
        return $rollout->drain_intent === WorkerBuildIdRollout::DRAIN_INTENT_ACTIVE
            && $rollout->promoted_at !== null
            && $rollout->rolled_back_at === null;
    }

    private function rolloutId(WorkerBuildIdRollout $rollout): int
    {
        return is_numeric($rollout->getKey()) ? (int) $rollout->getKey() : 0;
    }
}
