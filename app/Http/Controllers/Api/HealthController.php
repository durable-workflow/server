<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\StandaloneWorkerFleet;
use App\Support\WorkerProtocol;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController
{
    public function __construct(
        private readonly StandaloneWorkerFleet $workerFleet,
    ) {}

    public function check(): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            $dbHealthy = true;
        } catch (\Throwable) {
            $dbHealthy = false;
        }

        $status = $dbHealthy ? 'serving' : 'degraded';

        return response()->json([
            'status' => $status,
            'timestamp' => now()->toIso8601String(),
            'checks' => [
                'database' => $dbHealthy ? 'ok' : 'unavailable',
            ],
        ], $dbHealthy ? 200 : 503);
    }

    public function clusterInfo(Request $request): JsonResponse
    {
        $namespace = (string) ($request->attributes->get('namespace') ?: config('server.default_namespace'));

        return response()->json([
            'server_id' => config('server.server_id'),
            'version' => config('app.version', '0.1.0'),
            'default_namespace' => config('server.default_namespace'),
            'supported_sdk_versions' => [
                'php' => '>=1.0',
                'python' => '>=0.1',
            ],
            'capabilities' => [
                'workflow_tasks' => true,
                'activity_tasks' => true,
                'signals' => true,
                'queries' => true,
                'updates' => true,
                'schedules' => true,
                'search_attributes' => true,
                'history_export' => true,
                'continue_as_new' => true,
                'child_workflows' => true,
            ],
            'worker_fleet' => $this->workerFleet->summary($namespace),
            'control_plane' => ControlPlaneProtocol::info(),
            'worker_protocol' => WorkerProtocol::info(),
        ]);
    }
}
