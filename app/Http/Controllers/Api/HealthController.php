<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\StandaloneWorkerFleet;
use App\Support\WorkerProtocol;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Support\StructuralLimits;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;

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
                'activity_timeouts' => true,
                'parent_close_policy' => true,
                'non_retryable_failures' => true,
                'history_retention' => true,
                'payload_codec_envelope' => true,
                'payload_codecs' => \Workflow\Serializers\CodecRegistry::names(),
                'response_compression' => (bool) config('server.compression.enabled', true)
                    ? ['gzip', 'deflate']
                    : [],
            ],
            'worker_fleet' => $this->workerFleet->summary($namespace),
            'task_repair' => $this->taskRepairDiagnostics(),
            'limits' => [
                'max_payload_bytes' => (int) config('server.limits.max_payload_bytes', 2 * 1024 * 1024),
                'max_memo_bytes' => (int) config('server.limits.max_memo_bytes', 256 * 1024),
                'max_search_attributes' => (int) config('server.limits.max_search_attributes', 100),
                'max_pending_activities' => (int) config('server.limits.max_pending_activities', 2000),
                'max_pending_children' => (int) config('server.limits.max_pending_children', 2000),
            ],
            'structural_limits' => StructuralLimits::snapshot(),
            'control_plane' => ControlPlaneProtocol::info(),
            'worker_protocol' => WorkerProtocol::info(),
            'package_provenance' => $this->packageProvenance(),
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRepairDiagnostics(): array
    {
        return [
            'policy' => TaskRepairPolicy::snapshot(),
            'candidates' => TaskRepairCandidates::snapshot(),
        ];
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
