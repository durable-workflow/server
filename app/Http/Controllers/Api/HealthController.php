<?php

namespace App\Http\Controllers\Api;

use App\Http\Middleware\Authenticate;
use App\Models\WorkflowNamespace;
use App\Support\AuthCompositionContract;
use App\Support\BridgeAdapterOutcomeContract;
use App\Support\ChildWorkflowRuntimeContract;
use App\Support\ClientCompatibility;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneRequestContract;
use App\Support\CoordinationHealthContract;
use App\Support\FilesystemDiskAvailability;
use App\Support\NamespaceRuntimeContract;
use App\Support\NexusContract;
use App\Support\ReplayVerificationContract;
use App\Support\SearchAttributeRuntimeContract;
use App\Support\ServerReadiness;
use App\Support\ServerTopology;
use App\Support\SchedulesRuntimeContract;
use App\Support\SignalQueryRuntimeContract;
use App\Support\TaskQueueBuildIdRolloutSnapshot;
use App\Support\WorkerVersioningRuntimeContract;
use App\Support\WorkerProtocol;
use App\Support\WorkflowStreamsContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\OperatorMetrics;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\PlatformProtocolSpecs;
use Workflow\V2\Support\SdkNeutralityContract;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\StructuralLimits;
use Workflow\V2\Support\SurfaceStabilityContract;
use Workflow\V2\Support\TaskRepairCandidates;
use Workflow\V2\Support\TaskRepairPolicy;

class HealthController
{
    private ?array $cachedProvenance = null;

    public function __construct(
        private readonly ServerReadiness $readiness,
        private readonly TaskQueueBuildIdRolloutSnapshot $buildIdRollouts,
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
            'topology' => ServerTopology::healthSummary(),
        ], $dbHealthy ? 200 : 503);
    }

    public function ready(): JsonResponse
    {
        $snapshot = $this->readiness->snapshot();
        $ready = $snapshot['ready'];

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'timestamp' => now()->toIso8601String(),
            'checks' => $snapshot['checks'],
            'topology' => ServerTopology::healthSummary(),
        ], $ready ? 200 : 503);
    }

    public function clusterInfo(Request $request): JsonResponse
    {
        $namespace = (string) ($request->attributes->get('namespace') ?: config('server.default_namespace'));
        $embeddedV2Importer = 'Workflow\\V2\\Support\\EmbeddedV2HistoryImport';
        $embeddedV2ImportContract = 'Workflow\\V2\\Support\\EmbeddedV2ImportContract';
        $serviceExecutionContract = 'Workflow\\V2\\Support\\ServiceExecutionContract';
        $embeddedV2ImportAvailable = class_exists($embeddedV2Importer);
        $serviceExecutionAvailable = class_exists($serviceExecutionContract);

        $capabilities = [
            'workflow_tasks' => true,
            'activity_tasks' => true,
            'signals' => true,
            'queries' => true,
            'updates' => true,
            'schedules' => true,
            'schedule_jitter' => true,
            'schedule_max_runs' => true,
            'search_attributes' => true,
            'history_export' => true,
            'continue_as_new' => true,
            'child_workflows' => true,
            'namespace_runtime_contract' => true,
            'activity_timeouts' => true,
            'activity_retry_policy' => true,
            'child_workflow_retry_policy' => true,
            'child_workflow_timeouts' => true,
            'parent_close_policy' => true,
            'non_retryable_failures' => true,
            'worker_sessions' => WorkerProtocol::workerSessionsSupported(),
            'history_retention' => true,
            'payload_codec_envelope' => true,
            'payload_codec_envelope_responses' => true,
            'bridge_adapter_outcome_contract' => true,
            'external_executor_config_contract' => true,
            'invocable_carrier_contract' => true,
            'service_catalog' => true,
            'service_execution' => $serviceExecutionAvailable,
            'nexus' => $serviceExecutionAvailable,
            'workflow_streams' => true,
            'replay_verification_contract' => true,
            'search_attribute_runtime_contract' => true,
            'schedules_runtime_contract' => true,
            'child_workflow_runtime_contract' => true,
            'worker_versioning_runtime_contract' => true,
            'embedded_v2_import' => $embeddedV2ImportAvailable,
            'payload_codecs' => CodecRegistry::universal(),
            'response_compression' => (bool) config('server.compression.enabled', true)
                ? ['gzip', 'deflate']
                : [],
        ];

        $engineSpecificCodecs = CodecRegistry::engineSpecific();
        if ($engineSpecificCodecs !== []) {
            $capabilities['payload_codecs_engine_specific'] = $engineSpecificCodecs;
        }

        $response = [
            'server_id' => config('server.server_id'),
            'version' => env('APP_VERSION', '2.0.0'),
            'default_namespace' => config('server.default_namespace'),
            'namespace' => $this->namespacePolicy($namespace),
            'supported_sdk_versions' => ClientCompatibility::supportedSdkVersions(),
            'capabilities' => $capabilities,
            'worker_fleet' => StandaloneWorkerVisibility::fleetSummary($namespace),
            'operator_metrics' => OperatorMetrics::snapshot(null, $namespace),
            'task_repair' => $this->taskRepairDiagnostics(),
            'limits' => [
                'max_payload_bytes' => (int) config('server.limits.max_payload_bytes', 2 * 1024 * 1024),
                'max_memo_bytes' => (int) config('server.limits.max_memo_bytes', 256 * 1024),
                'max_search_attributes' => (int) config('server.limits.max_search_attributes', 100),
                'max_search_attribute_key_length' => (int) config('server.limits.max_search_attribute_key_length', 128),
                'max_search_attribute_value_bytes' => (int) config('server.limits.max_search_attribute_value_bytes', 2048),
                'max_operation_name_length' => (int) config('server.limits.max_operation_name_length', 256),
                'max_pending_activities' => (int) config('server.limits.max_pending_activities', 2000),
                'max_pending_children' => (int) config('server.limits.max_pending_children', 2000),
            ],
            'structural_limits' => StructuralLimits::snapshot(),
            'topology' => ServerTopology::info(),
            'coordination_health' => CoordinationHealthContract::manifest(
                $this->readiness->workflowStatus(),
                $this->buildIdRollouts->routingDrains(),
            ),
            'client_compatibility' => ClientCompatibility::info(),
            'surface_stability_contract' => SurfaceStabilityContract::manifest(),
            'platform_protocol_specs' => PlatformProtocolSpecs::manifest(),
            'platform_conformance_suite' => PlatformConformanceSuite::manifest(),
            'signal_query_runtime_contract' => SignalQueryRuntimeContract::manifest(),
            'search_attribute_runtime_contract' => SearchAttributeRuntimeContract::manifest(),
            'schedules_runtime_contract' => SchedulesRuntimeContract::manifest(),
            'child_workflow_runtime_contract' => ChildWorkflowRuntimeContract::manifest(),
            'worker_versioning_runtime_contract' => WorkerVersioningRuntimeContract::manifest(),
            'namespace_runtime_contract' => NamespaceRuntimeContract::manifest(),
            'auth_composition_contract' => AuthCompositionContract::manifest(),
            'control_plane' => ControlPlaneProtocol::info(),
            'worker_protocol' => WorkerProtocol::info(),
            'bridge_adapter_outcome_contract' => BridgeAdapterOutcomeContract::manifest(),
            'replay_verification_contract' => ReplayVerificationContract::manifest(),
        ];

        if (class_exists($embeddedV2ImportContract)) {
            $response['embedded_v2_import_contract'] = $embeddedV2ImportContract::manifest();
        }

        if (class_exists(SdkNeutralityContract::class)) {
            $response['sdk_neutrality_contract'] = SdkNeutralityContract::manifest();
        }

        if ($serviceExecutionAvailable) {
            $response['service_execution_contract'] = array_merge(
                $serviceExecutionContract::manifest(),
                [
                    'durable_response_fields' => ControlPlaneRequestContract::manifest()['operations']['service_execute']['durable_response_fields'],
                ],
            );
            $response['nexus_contract'] = NexusContract::manifest();
        }

        $response['workflow_streams_contract'] = WorkflowStreamsContract::manifest();

        if ($this->shouldExposePackageProvenance($request)) {
            $provenance = $this->packageProvenance();
            if ($provenance !== null) {
                $response['package_provenance'] = $provenance;
            }
        }

        return response()->json($response);
    }

    private function shouldExposePackageProvenance(Request $request): bool
    {
        if (! (bool) config('server.expose_package_provenance', false)) {
            return false;
        }

        $role = $request->attributes->get(Authenticate::ATTRIBUTE_ROLE);

        return $role === null || $role === 'admin';
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
     * @return array<string, mixed>
     */
    private function namespacePolicy(string $namespace): array
    {
        $normalized = strtolower($namespace);
        $ns = WorkflowNamespace::query()->where('name', $normalized)->first();

        return [
            'name' => $normalized,
            'exists' => $ns !== null,
            'status' => $ns?->status,
            'retention_days' => $ns?->retention_days,
            'external_payload_storage' => $this->externalPayloadStoragePolicy($ns),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function externalPayloadStoragePolicy(?WorkflowNamespace $ns): array
    {
        $policy = is_array($ns?->external_payload_storage) ? $ns->external_payload_storage : [];
        $driver = $this->stringOrNull($policy['driver'] ?? null);
        $enabled = $policy !== [] && ($policy['enabled'] ?? true) !== false;
        $threshold = $policy['threshold_bytes'] ?? config('server.limits.max_payload_bytes', 2 * 1024 * 1024);
        $config = is_array($policy['config'] ?? null) ? $policy['config'] : [];
        $resolvedDriver = $enabled && $this->externalPayloadStorageResolvable($driver, $config);

        return [
            'schema' => ExternalPayloadReference::SCHEMA,
            'version' => 1,
            'configured' => $policy !== [],
            'enabled' => $enabled,
            'status' => $this->externalPayloadStorageStatus($policy, $enabled, $resolvedDriver),
            'driver' => $driver,
            'threshold_bytes' => (int) $threshold,
            'reference_uri_scheme' => $this->externalPayloadReferenceScheme($driver, $config),
            'supported_drivers' => ['local', 's3', 'gcs', 'azure', 'custom'],
            'custom_driver_configurable' => true,
            'config_redacted' => $config !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function externalPayloadStorageStatus(array $policy, bool $enabled, bool $resolved): string
    {
        if ($policy === []) {
            return 'unconfigured';
        }

        if (! $enabled) {
            return 'disabled';
        }

        return $resolved ? 'available' : 'driver_unavailable';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function externalPayloadReferenceScheme(?string $driver, array $config): ?string
    {
        if ($driver === null) {
            return null;
        }

        if ($driver === 'local') {
            return 'file';
        }

        if ($driver === 'custom') {
            return $this->stringOrNull($config['scheme'] ?? null);
        }

        return in_array($driver, ['s3', 'gcs', 'azure'], true) ? $driver : null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function externalPayloadStorageResolvable(?string $driver, array $config): bool
    {
        if ($driver === 'local') {
            return true;
        }

        if (! in_array($driver, ['s3', 'gcs', 'azure', 'custom'], true)) {
            return false;
        }

        $disk = $this->stringOrNull($config['disk'] ?? null);
        $bucket = $this->stringOrNull(
            $config['bucket']
            ?? $config['container']
            ?? $config['name']
            ?? null,
        );
        $scheme = $driver === 'custom'
            ? $this->stringOrNull($config['scheme'] ?? null)
            : $driver;

        return FilesystemDiskAvailability::configured($disk) && $bucket !== null && $scheme !== null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{source: string, ref: string, commit: string}|null
     */
    private function packageProvenance(): ?array
    {
        if ($this->cachedProvenance !== null) {
            return $this->cachedProvenance !== [] ? $this->cachedProvenance : null;
        }

        $path = (string) config('server.package_provenance_path', base_path('.package-provenance'));

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
