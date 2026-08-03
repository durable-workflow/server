<?php

namespace App\Http\Controllers\Api;

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
}
