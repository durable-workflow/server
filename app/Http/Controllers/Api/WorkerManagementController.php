<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\V2\Support\WorkerCompatibilityFleet;

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

        $workers = $query->get()->map(fn (WorkerRegistration $worker): array => $this->workerSummary($worker, $staleAfter))->all();

        return ControlPlaneProtocol::json([
            'workers' => $workers,
            'stale_after_seconds' => $staleAfter,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function workerSummary(WorkerRegistration $worker, int $staleAfter): array
    {
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
            'capabilities' => $worker->capabilities ?? [],
            'max_concurrent_workflow_tasks' => $worker->max_concurrent_workflow_tasks,
            'max_concurrent_activity_tasks' => $worker->max_concurrent_activity_tasks,
            'max_concurrent_worker_sessions' => $worker->max_concurrent_worker_sessions,
            'task_slots' => [
                'workflow_available' => $worker->available_workflow_slots,
                'activity_available' => $worker->available_activity_slots,
                'session_available' => $worker->available_session_slots,
                'workflow_capacity' => $worker->max_concurrent_workflow_tasks,
                'activity_capacity' => $worker->max_concurrent_activity_tasks,
                'session_capacity' => $worker->max_concurrent_worker_sessions,
            ],
            'process_metrics' => $worker->process_metrics ?? null,
            'heartbeat_interval_seconds' => $worker->heartbeat_interval_seconds,
            'status' => $isStale ? 'stale' : $worker->status,
            'last_heartbeat_at' => $worker->last_heartbeat_at?->toJSON(),
            'registered_at' => $worker->created_at?->toJSON(),
        ];
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

        $payload = $this->workerSummary($worker, $staleAfter);
        $payload['updated_at'] = $worker->updated_at?->toJSON();
        $payload['stale_after_seconds'] = $staleAfter;

        return ControlPlaneProtocol::json($payload);
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
        $this->forgetCompatibilityWorker($namespace, $workerId);

        return ControlPlaneProtocol::json([
            'worker_id' => $workerId,
            'outcome' => 'deregistered',
        ]);
    }

    private function forgetCompatibilityWorker(string $namespace, string $workerId): void
    {
        if (method_exists(WorkerCompatibilityFleet::class, 'forgetWorkerForNamespace')) {
            WorkerCompatibilityFleet::forgetWorkerForNamespace($namespace, $workerId);

            return;
        }

        $this->deleteCompatibilityHeartbeats($namespace, $workerId);
    }

    private function deleteCompatibilityHeartbeats(string $namespace, string $workerId): void
    {
        $connection = config('workflows.storage.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        $schema = $connection === null ? Schema::getFacadeRoot() : Schema::connection($connection);

        if (! $schema->hasTable('workflow_worker_compatibility_heartbeats')) {
            return;
        }

        DB::connection($connection)
            ->table('workflow_worker_compatibility_heartbeats')
            ->where('namespace', $namespace)
            ->where('worker_id', $workerId)
            ->delete();
    }
}
