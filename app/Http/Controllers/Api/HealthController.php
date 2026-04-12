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

    private ?array $cachedProvenance = null;

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

        return response()->json(array_filter([
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
                'response_compression' => (bool) config('server.compression.enabled', true)
                    ? ['gzip', 'deflate']
                    : [],
            ],
            'worker_fleet' => $this->workerFleet->summary($namespace),
            'control_plane' => ControlPlaneProtocol::info(),
            'worker_protocol' => WorkerProtocol::info(),
            'package_provenance' => $this->packageProvenance(),
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return array{source: string, ref: string, commit: string}|null
     */
    private function packageProvenance(): ?array
    {
        if ($this->cachedProvenance !== null) {
            return $this->cachedProvenance !== [] ? $this->cachedProvenance : null;
        }

        $path = base_path('.package-provenance');

        if (! is_file($path)) {
            $this->cachedProvenance = [];

            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (! is_array($lines) || count($lines) < 3) {
            $this->cachedProvenance = [];

            return null;
        }

        $this->cachedProvenance = [
            'source' => trim($lines[0]),
            'ref' => trim($lines[1]),
            'commit' => trim($lines[2]),
        ];

        return $this->cachedProvenance;
    }
}
