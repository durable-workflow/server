<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use App\Support\WorkerProtocol;
use App\Support\WorkerSessionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkerSessionController
{
    public function __construct(
        private readonly WorkerSessionRegistry $sessions,
    ) {}

    public function create(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        if ($response = WorkerProtocol::rejectWorkerSessionsUnavailable($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        $validated = $this->validateSessionRequest($request, workerIdRequired: true);

        $worker = $this->resolveRegisteredWorker(
            $namespace,
            $validated['worker_id'],
            $validated['queue'] ?? null,
        );

        if ($worker instanceof JsonResponse) {
            return $worker;
        }

        $options = $this->sessions->normalizeOptions(
            $validated,
            $validated['queue'] ?? $worker->task_queue,
            $validated['connection'] ?? null,
        );

        if ($options === null) {
            return WorkerProtocol::json([
                'error' => 'Worker session requires a non-empty session_id.',
                'reason' => 'invalid_worker_session',
            ], 422);
        }

        $result = $this->sessions->createOrReacquire($namespace, $worker, $options);

        return WorkerProtocol::json($result, (int) $result['status']);
    }

    public function heartbeat(Request $request, string $sessionId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        if ($response = WorkerProtocol::rejectWorkerSessionsUnavailable($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        $validated = $request->validate([
            'worker_id' => ['required', 'string', 'max:255'],
            'lease_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->sessions->heartbeat(
            $namespace,
            $validated['worker_id'],
            $sessionId,
            $validated['lease_seconds'] ?? null,
        );

        return WorkerProtocol::json($result, (int) $result['status']);
    }

    public function close(Request $request, string $sessionId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        if ($response = WorkerProtocol::rejectWorkerSessionsUnavailable($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        $validated = $request->validate([
            'worker_id' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->sessions->close($namespace, $validated['worker_id'], $sessionId);

        return WorkerProtocol::json($result, (int) $result['status']);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json($this->sessions->visibility($namespace));
    }

    public function show(Request $request, string $sessionId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $visibility = $this->sessions->visibility($namespace, $sessionId);

        if ($visibility['sessions'] === []) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Worker session [%s] not found in namespace [%s].',
                    $sessionId,
                    $namespace,
                ),
                'reason' => 'worker_session_not_found',
            ], 404);
        }

        return ControlPlaneProtocol::json([
            'namespace' => $namespace,
            'session' => $visibility['sessions'][0],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSessionRequest(Request $request, bool $workerIdRequired): array
    {
        return $request->validate([
            'worker_id' => [$workerIdRequired ? 'required' : 'nullable', 'string', 'max:255'],
            'session_id' => ['required', 'string', 'max:255'],
            'connection' => ['nullable', 'string', 'max:255'],
            'queue' => ['nullable', 'string', 'max:255'],
            'requirements' => ['nullable', 'array'],
            'requirements.*' => ['string', 'max:255'],
            'lease_seconds' => ['nullable', 'integer', 'min:1'],
            'ttl_seconds' => ['nullable', 'integer', 'min:1'],
            'max_concurrent_activities' => ['nullable', 'integer', 'min:1'],
            'create_if_missing' => ['nullable', 'boolean'],
            'allow_reacquire_after_failure' => ['nullable', 'boolean'],
        ]);
    }

    private function resolveRegisteredWorker(
        string $namespace,
        string $workerId,
        ?string $sessionQueue,
    ): WorkerRegistration|JsonResponse {
        $worker = WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace)
            ->first();

        if (! $worker) {
            return WorkerProtocol::json([
                'error' => 'Worker must be registered before creating a worker session.',
                'reason' => 'worker_not_registered',
                'worker_id' => $workerId,
            ], 412);
        }

        if ($sessionQueue !== null && $worker->task_queue !== $sessionQueue) {
            return WorkerProtocol::json([
                'error' => sprintf(
                    'Worker [%s] is registered for task queue [%s], not session queue [%s].',
                    $workerId,
                    $worker->task_queue,
                    $sessionQueue,
                ),
                'reason' => 'session_queue_mismatch',
                'worker_id' => $workerId,
                'registered_task_queue' => $worker->task_queue,
                'requested_session_queue' => $sessionQueue,
            ], 409);
        }

        return $worker;
    }
}
