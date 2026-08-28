<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerRegistrationDeregistrar;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\StandaloneWorkerVisibility;

class WorkerManagementController
{
    public function __construct(
        private readonly WorkerRegistrationDeregistrar $registrar,
    ) {}

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

        $statusFilter = $this->statusFilter($request->query('status'));
        $staleAfter = $this->workerStaleAfterSeconds();

        $workers = $query->get()
            ->filter(fn (WorkerRegistration $worker): bool => $this->workerMatchesStatus(
                $worker,
                $staleAfter,
                $statusFilter,
            ))
            ->map(fn (WorkerRegistration $worker): array => $this->workerSummary($worker, $staleAfter))
            ->values()
            ->all();

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
        $isStale = $this->workerIsStale($worker, $staleAfter);

        return [
            'worker_id' => $worker->worker_id,
            'namespace' => $worker->namespace,
            'task_queue' => $worker->task_queue,
            'runtime' => $worker->runtime,
            'sdk_version' => $worker->sdk_version,
            'build_id' => $worker->build_id,
            'supported_workflow_types' => $worker->supported_workflow_types ?? [],
            'workflow_definition_fingerprints' => $worker->workflow_definition_fingerprints ?? [],
            'workflow_command_contracts' => $worker->workflow_command_contracts ?? [],
            'supported_activity_types' => $worker->supported_activity_types ?? [],
            'capabilities' => $worker->capabilities ?? [],
            'capability_manifest' => $worker->capability_manifest ?? [],
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
            'status' => $isStale ? 'stale' : $this->storedWorkerStatus($worker),
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

        $staleAfter = $this->workerStaleAfterSeconds();

        $payload = $this->workerSummary($worker, $staleAfter);
        $payload['updated_at'] = $worker->updated_at?->toJSON();
        $payload['stale_after_seconds'] = $staleAfter;

        return ControlPlaneProtocol::json($payload);
    }

    private function statusFilter(mixed $status): ?string
    {
        if (! is_string($status)) {
            return null;
        }

        $status = strtolower(trim($status));

        return $status !== '' ? $status : null;
    }

    private function workerMatchesStatus(WorkerRegistration $worker, int $staleAfter, ?string $statusFilter): bool
    {
        $isStale = $this->workerIsStale($worker, $staleAfter);

        if ($statusFilter === null) {
            return ! $isStale;
        }

        if ($statusFilter === 'stale') {
            return $isStale;
        }

        if ($isStale) {
            return false;
        }

        return $this->storedWorkerStatus($worker) === $statusFilter;
    }

    private function workerIsStale(WorkerRegistration $worker, int $staleAfter): bool
    {
        $heartbeat = $worker->last_heartbeat_at;

        if (! ($heartbeat instanceof CarbonInterface)) {
            return true;
        }

        return $heartbeat->lt(now()->subSeconds($staleAfter));
    }

    private function storedWorkerStatus(WorkerRegistration $worker): string
    {
        $status = is_string($worker->status) ? strtolower(trim($worker->status)) : '';

        return $status !== '' ? $status : 'active';
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

    public function destroy(Request $request, string $workerId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        $recoveredWorkflowTaskCount = $this->registrar->deregister($request, $namespace, $workerId);

        if ($recoveredWorkflowTaskCount === null) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Worker [%s] not found in namespace [%s].',
                    $workerId,
                    $namespace,
                ),
                'reason' => 'worker_not_found',
            ], 404);
        }

        return ControlPlaneProtocol::json([
            'worker_id' => $workerId,
            'outcome' => 'deregistered',
            'recovered_workflow_task_count' => $recoveredWorkflowTaskCount,
        ]);
    }
}
