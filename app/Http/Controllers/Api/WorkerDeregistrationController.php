<?php

namespace App\Http\Controllers\Api;

use App\Support\BackendLockPressure;
use App\Support\BackendUnavailable;
use App\Support\FencedWorkerDeregistration;
use App\Support\WorkerProtocol;
use App\Support\WorkerRegistrationDeregistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkerDeregistrationController
{
    public function __construct(
        private readonly WorkerRegistrationDeregistrar $registrar,
    ) {}

    public function destroy(Request $request, string $workerId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');
        $recoveredWorkflowTaskCount = $this->registrar->deregister($request, $namespace, $workerId);

        if ($recoveredWorkflowTaskCount === null) {
            return WorkerProtocol::json([
                'message' => sprintf(
                    'Worker [%s] not found in namespace [%s].',
                    $workerId,
                    $namespace,
                ),
                'reason' => 'worker_not_found',
            ], 404);
        }

        return WorkerProtocol::json([
            'worker_id' => $workerId,
            'outcome' => 'deregistered',
            'recovered_workflow_task_count' => $recoveredWorkflowTaskCount,
        ]);
    }

    public function destroyFenced(Request $request, string $workerId, FencedWorkerDeregistration $deregistration): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }
        $validated = $request->validate([
            'registration_token' => ['required', 'string', 'size:32', 'regex:/^[a-f0-9]{32}$/D'],
        ]);
        $token = $validated['registration_token'];
        try {
            $result = $deregistration->complete(
                $request, (string) $request->attributes->get('namespace'), $workerId, $token,
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception) && ! BackendUnavailable::is($exception)) {
                throw $exception;
            }

            return WorkerProtocol::json([
                'reason' => BackendLockPressure::is($exception) ? 'backend_lock_pressure' : 'backend_unavailable',
                'operation' => 'deregister_worker',
                'worker_id' => $workerId,
                'registration_token' => $token,
                'outcome' => 'unknown',
                'retryable' => true,
                'retry_after_seconds' => 1,
            ], 503)->header('Retry-After', '1');
        }

        return WorkerProtocol::json($result['body'], $result['status']);
    }
}
