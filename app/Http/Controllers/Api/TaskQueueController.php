<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\StandaloneWorkerVisibility;

class TaskQueueController
{
    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            StandaloneWorkerVisibility::queueSnapshot(
                $namespace,
                WorkerRegistration::class,
                now(),
                $this->workerStaleAfterSeconds(),
            )->toArray(),
        );
    }

    public function show(Request $request, string $taskQueue): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = (string) $request->attributes->get('namespace');

        return ControlPlaneProtocol::json(
            StandaloneWorkerVisibility::queueDetail(
                $namespace,
                $taskQueue,
                WorkerRegistration::class,
                now(),
                $this->workerStaleAfterSeconds(),
            )->toArray(),
        );
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
