<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerManagementController
{
    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $query = WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->orderBy('last_heartbeat_at', 'desc');

        if ($request->query('task_queue')) {
            $query->where('task_queue', $request->query('task_queue'));
        }

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        $staleAfter = (int) config('server.workers.stale_after_seconds', 300);

        $workers = $query->get()->map(function (WorkerRegistration $worker) use ($staleAfter): array {
            $isStale = $worker->last_heartbeat_at
                && $worker->last_heartbeat_at->lt(now()->subSeconds($staleAfter));

            return [
                'worker_id' => $worker->worker_id,
                'namespace' => $worker->namespace,
                'task_queue' => $worker->task_queue,
                'runtime' => $worker->runtime,
                'sdk_version' => $worker->sdk_version,
                'build_id' => $worker->build_id,
                'supported_workflow_types' => $worker->supported_workflow_types ?? [],
                'workflow_definition_fingerprints' => $worker->workflow_definition_fingerprints ?? [],
                'supported_activity_types' => $worker->supported_activity_types ?? [],
                'max_concurrent_workflow_tasks' => $worker->max_concurrent_workflow_tasks,
                'max_concurrent_activity_tasks' => $worker->max_concurrent_activity_tasks,
                'status' => $isStale ? 'stale' : $worker->status,
                'last_heartbeat_at' => $worker->last_heartbeat_at?->toJSON(),
                'registered_at' => $worker->created_at?->toJSON(),
            ];
        })->all();

        return ControlPlaneProtocol::json([
            'workers' => $workers,
        ]);
    }

    public function show(Request $request, string $workerId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $worker = WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace)
            ->first();

        if (! $worker) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Worker [%s] not found in namespace [%s].',
                    $workerId,
                    $namespace,
                ),
                'reason' => 'worker_not_found',
            ], 404);
        }

        $staleAfter = (int) config('server.workers.stale_after_seconds', 300);
        $isStale = $worker->last_heartbeat_at
            && $worker->last_heartbeat_at->lt(now()->subSeconds($staleAfter));

        return ControlPlaneProtocol::json([
            'worker_id' => $worker->worker_id,
            'namespace' => $worker->namespace,
            'task_queue' => $worker->task_queue,
            'runtime' => $worker->runtime,
            'sdk_version' => $worker->sdk_version,
            'build_id' => $worker->build_id,
            'supported_workflow_types' => $worker->supported_workflow_types ?? [],
            'workflow_definition_fingerprints' => $worker->workflow_definition_fingerprints ?? [],
            'supported_activity_types' => $worker->supported_activity_types ?? [],
            'max_concurrent_workflow_tasks' => $worker->max_concurrent_workflow_tasks,
            'max_concurrent_activity_tasks' => $worker->max_concurrent_activity_tasks,
            'status' => $isStale ? 'stale' : $worker->status,
            'last_heartbeat_at' => $worker->last_heartbeat_at?->toJSON(),
            'registered_at' => $worker->created_at?->toJSON(),
            'updated_at' => $worker->updated_at?->toJSON(),
        ]);
    }

    public function destroy(Request $request, string $workerId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $worker = WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace)
            ->first();

        if (! $worker) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Worker [%s] not found in namespace [%s].',
                    $workerId,
                    $namespace,
                ),
                'reason' => 'worker_not_found',
            ], 404);
        }

        $worker->delete();

        return ControlPlaneProtocol::json([
            'worker_id' => $workerId,
            'outcome' => 'deregistered',
        ]);
    }
}
