<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WorkerRegistration;

final class TaskQueuePollers
{
    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function forNamespace(string $namespace): array
    {
        $pollers = [];

        WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->orderBy('task_queue')
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('worker_id')
            ->get()
            ->each(function (WorkerRegistration $worker) use (&$pollers): void {
                $taskQueue = is_string($worker->task_queue) && $worker->task_queue !== ''
                    ? $worker->task_queue
                    : null;

                if ($taskQueue === null) {
                    return;
                }

                $pollers[$taskQueue] ??= [];
                $pollers[$taskQueue][] = $this->poller($worker);
            });

        return $pollers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forQueue(string $namespace, string $taskQueue): array
    {
        return WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->orderByDesc('last_heartbeat_at')
            ->orderBy('worker_id')
            ->get()
            ->map(fn (WorkerRegistration $worker): array => $this->poller($worker))
            ->all();
    }

    public function staleAfterSeconds(): int
    {
        return max(
            1,
            (int) config(
                'server.workers.stale_after_seconds',
                max((int) config('server.polling.timeout', 30) * 2, 60),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function poller(WorkerRegistration $worker): array
    {
        return [
            'worker_id' => $worker->worker_id,
            'runtime' => $worker->runtime,
            'sdk_version' => $worker->sdk_version,
            'build_id' => $worker->build_id,
            'last_heartbeat_at' => $worker->last_heartbeat_at,
            'status' => is_string($worker->status) && $worker->status !== '' ? $worker->status : 'active',
            'supported_workflow_types' => is_array($worker->supported_workflow_types)
                ? array_values($worker->supported_workflow_types)
                : [],
            'supported_activity_types' => is_array($worker->supported_activity_types)
                ? array_values($worker->supported_activity_types)
                : [],
            'max_concurrent_workflow_tasks' => (int) $worker->max_concurrent_workflow_tasks,
            'max_concurrent_activity_tasks' => (int) $worker->max_concurrent_activity_tasks,
        ];
    }
}
