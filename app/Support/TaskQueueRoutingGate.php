<?php

namespace App\Support;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use Workflow\V2\Support\StandaloneWorkerVisibility;

final class TaskQueueRoutingGate
{
    /**
     * @return array{
     *     routing_status: string,
     *     active_worker_count: int,
     *     draining_worker_count: int,
     *     stale_worker_count: int,
     *     draining_build_ids: list<string|null>
     * }|null
     */
    public function workflowStartBlock(string $namespace, string $taskQueue): ?array
    {
        $cutoff = now()->subSeconds($this->workerStaleAfterSeconds());
        $activeWorkerCount = 0;
        $drainingWorkerCount = 0;
        $staleWorkerCount = 0;

        $workers = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->get(['status', 'last_heartbeat_at']);

        foreach ($workers as $worker) {
            $status = is_string($worker->status) ? $worker->status : 'active';
            $heartbeat = $worker->last_heartbeat_at;

            if ($heartbeat !== null && $heartbeat->lt($cutoff)) {
                $staleWorkerCount++;

                continue;
            }

            if ($status === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
                $drainingWorkerCount++;

                continue;
            }

            $activeWorkerCount++;
        }

        $drainingBuildIds = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('drain_intent', WorkerBuildIdRollout::DRAIN_INTENT_DRAINING)
            ->orderBy('build_id')
            ->get()
            ->map(static fn (WorkerBuildIdRollout $rollout): ?string => $rollout->publicBuildId())
            ->values()
            ->all();

        if ($activeWorkerCount > 0 || ($drainingWorkerCount === 0 && $drainingBuildIds === [])) {
            return null;
        }

        return [
            'routing_status' => 'draining',
            'active_worker_count' => $activeWorkerCount,
            'draining_worker_count' => $drainingWorkerCount,
            'stale_worker_count' => $staleWorkerCount,
            'draining_build_ids' => $drainingBuildIds,
        ];
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
