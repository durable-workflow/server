<?php

namespace Tests\Unit\Support;

use App\Support\WorkerProtocol;
use App\Support\WorkflowPackageApiFloor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Fixtures\LegacyActivityTaskBridgePollSignature;
use Tests\Fixtures\LegacyWorkflowTaskBridgePollSignature;
use Tests\Fixtures\StaleBackendCapabilities;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ActivityTaskBridge;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Exceptions\WorkflowOutputCodecUnavailableException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\ChildWorkflowNamespaceProjection;
use Workflow\V2\Support\DefaultMatchingRole;
use Workflow\V2\Support\DefaultWorkflowControlPlane;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;
use Workflow\V2\Support\MatchingRoleSnapshot;
use Workflow\V2\Support\PayloadEnvelopeResolver;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\ServiceExecutionContract;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerHistoryPayloadContract;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowQueryContract;
use Workflow\V2\Support\WorkflowTaskLease;

/**
 * Pins the API floor contract the server relies on from
 * durable-workflow/workflow. If one of these assertions fails, the server
 * is running against a workflow package that is too old — upgrade the
 * package, don't relax the check.
 */
class WorkflowPackageApiFloorTest extends TestCase
{
    public function test_assert_passes_on_the_currently_resolved_workflow_package(): void
    {
        WorkflowPackageApiFloor::assert();

        $this->expectNotToPerformAssertions();
    }

    public function test_codec_registry_universal_is_public_static(): void
    {
        $reflection = new ReflectionClass(CodecRegistry::class);
        $method = $reflection->getMethod('universal');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertSame(['avro'], $method->invoke(null));
    }

    public function test_worker_history_payload_contract_manifest_is_public_static(): void
    {
        $reflection = new ReflectionClass(WorkerHistoryPayloadContract::class);
        $method = $reflection->getMethod('manifest');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function test_matching_role_snapshot_current_is_public_static(): void
    {
        $reflection = new ReflectionClass(MatchingRoleSnapshot::class);
        $method = $reflection->getMethod('current');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
    }

    public function test_workflow_task_lease_authority_is_public_package_api(): void
    {
        $reflection = new ReflectionClass(WorkflowTaskLease::class);

        foreach (['seconds', 'expiresAt'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertTrue($method->isStatic());
        }

        foreach (['CONFIG_KEY', 'DEFAULT_SECONDS'] as $constantName) {
            $constant = $reflection->getReflectionConstant($constantName);

            $this->assertNotFalse($constant);
            $this->assertTrue($constant->isPublic());
        }

        $this->assertSame('workflows.v2.workflow_task_lease_seconds', WorkflowTaskLease::CONFIG_KEY);
    }

    public function test_backend_capabilities_class_exists(): void
    {
        $this->assertTrue(class_exists(WorkflowPackageApiFloor::POLL_MODE_DEMOTION_CLASS));
    }

    public function test_child_workflow_namespace_projection_is_public_instance_api(): void
    {
        $reflection = new ReflectionClass(ChildWorkflowNamespaceProjection::class);

        foreach (['projectLink', 'projectLineageEntry'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertFalse($method->isStatic());
        }
    }

    public function test_matching_role_contract_is_public_instance_api(): void
    {
        $reflection = new ReflectionClass(MatchingRole::class);

        foreach (['wake', 'runPass'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertFalse($method->isStatic());
        }
    }

    public function test_service_control_plane_contract_is_public_instance_api(): void
    {
        $reflection = new ReflectionClass(ServiceControlPlane::class);

        foreach (['execute', 'describeCall', 'cancelCall'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertFalse($method->isStatic());
        }
    }

    public function test_service_execution_contract_manifest_is_public_static(): void
    {
        $reflection = new ReflectionClass(ServiceExecutionContract::class);
        $method = $reflection->getMethod('manifest');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());

        $manifest = ServiceExecutionContract::manifest();

        $this->assertSame('durable-workflow.v2.service-execution.contract', $manifest['schema'] ?? null);
        $this->assertContains('start_workflow', $manifest['handler_binding_kinds'] ?? []);
        $this->assertContains('invocable_http', $manifest['handler_binding_kinds'] ?? []);
        $this->assertArrayHasKey('workflow_run', $manifest['resolved_target_binding_kinds'] ?? []);
        $this->assertArrayHasKey('invocable_carrier_request', $manifest['resolved_target_binding_kinds'] ?? []);
    }

    public function test_worker_session_protocol_contract_is_public_static(): void
    {
        $reflection = new ReflectionClass(WorkerProtocolVersion::class);

        foreach (['workerSessionVerbs', 'workerSessionSemantics', 'localActivitySemantics', 'describe'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertTrue($method->isStatic());
        }

        $this->assertSame(['create', 'heartbeat', 'close'], WorkerProtocolVersion::workerSessionVerbs());
        $this->assertSame(
            '1.8',
            WorkerProtocolVersion::workerSessionSemantics()['minimum_protocol_version'] ?? null,
        );
        $this->assertSame('worker_session', WorkerProtocolVersion::workerSessionSemantics()['command_field'] ?? null);
        $this->assertTrue(WorkerProtocolVersion::localActivitySemantics()['supported'] ?? false);
        $this->assertIsArray(WorkerProtocolVersion::describe()['sticky_execution'] ?? null);
    }

    public function test_worker_query_task_protocol_contract_is_public_static(): void
    {
        $reflection = new ReflectionClass(WorkerProtocolVersion::class);

        foreach (['queryTaskVerbs', 'workerCapabilities', 'queryTaskSemantics'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertTrue($method->isStatic());
        }

        $capability = $reflection->getReflectionConstant('CAPABILITY_QUERY_TASKS');
        $this->assertNotFalse($capability);
        $this->assertTrue($capability->isPublic());

        $this->assertSame(WorkerProtocolVersion::VERSION, WorkerProtocol::VERSION);
        $this->assertSame('query_tasks', WorkerProtocolVersion::CAPABILITY_QUERY_TASKS);
        $this->assertSame(['poll', 'complete', 'fail'], WorkerProtocolVersion::queryTaskVerbs());
        $this->assertContains(WorkerProtocolVersion::CAPABILITY_QUERY_TASKS, WorkerProtocolVersion::workerCapabilities());
        $this->assertSame(
            '/api/worker/query-tasks',
            WorkerProtocolVersion::queryTaskSemantics()['path_prefix'] ?? null,
        );
        $this->assertContains(
            'timeout_seconds',
            WorkerProtocolVersion::queryTaskSemantics()['endpoints']['poll']['request_fields'] ?? [],
        );
    }

    public function test_external_payload_reference_constants_are_public_package_api(): void
    {
        $referenceReflection = new ReflectionClass(ExternalPayloadReference::class);
        $schema = $referenceReflection->getReflectionConstant('SCHEMA');

        $this->assertNotFalse($schema);
        $this->assertTrue($schema->isPublic());
        $this->assertSame(
            'durable-workflow.v2.external-payload-reference.v1',
            ExternalPayloadReference::SCHEMA,
        );

        $payloadsReflection = new ReflectionClass(ExternalPayloads::class);
        $prefix = $payloadsReflection->getReflectionConstant('STORED_REFERENCE_PREFIX');

        $this->assertNotFalse($prefix);
        $this->assertTrue($prefix->isPublic());
        $this->assertSame('dw-external-payload:v1:', ExternalPayloads::STORED_REFERENCE_PREFIX);
    }

    public function test_search_attribute_storage_constants_are_public_package_api(): void
    {
        $reflection = new ReflectionClass(WorkflowSearchAttribute::class);

        foreach ([
            'MAX_KEYWORD_LENGTH' => 255,
            'TYPE_STRING' => 'string',
            'TYPE_FLOAT' => 'float',
            'TYPE_KEYWORD_LIST' => 'keyword_list',
        ] as $constantName => $expectedValue) {
            $constant = $reflection->getReflectionConstant($constantName);

            $this->assertNotFalse($constant);
            $this->assertTrue($constant->isPublic());
            $this->assertSame($expectedValue, $constant->getValue());
        }
    }

    public function test_external_payload_protocol_classes_are_available(): void
    {
        $this->assertTrue(interface_exists(ExternalPayloadStorageDriver::class));
        $this->assertTrue(interface_exists(ExternalPayloadStoragePolicy::class));
        $this->assertTrue(class_exists(ExternalPayloadIntegrityException::class));
        $this->assertTrue(class_exists(LocalFilesystemExternalPayloadStorage::class));
    }

    public function test_terminal_output_projection_apis_are_listed_in_the_package_floor(): void
    {
        $floor = new ReflectionClass(WorkflowPackageApiFloor::class);

        $classes = $this->privateConstant($floor, 'REQUIRED_CLASSES');
        $this->assertContains(WorkflowOutputCodecUnavailableException::class, $classes);

        $apis = $this->privateConstant($floor, 'REQUIRED_INSTANCE_APIS');
        $this->assertContains([WorkflowRun::class, 'outputEnvelope'], $apis);
        $this->assertContains([WorkflowRun::class, 'outputPayloadCodec'], $apis);
        $this->assertContains([WorkflowRun::class, 'workflowOutput'], $apis);
    }

    public function test_external_payload_apis_are_listed_in_the_package_floor(): void
    {
        $floor = new ReflectionClass(WorkflowPackageApiFloor::class);

        $apis = $this->privateConstant($floor, 'REQUIRED_APIS');
        $this->assertContains([WorkerProtocolVersion::class, 'queryTaskVerbs'], $apis);
        $this->assertContains([WorkerProtocolVersion::class, 'workerCapabilities'], $apis);
        $this->assertContains([WorkerProtocolVersion::class, 'queryTaskSemantics'], $apis);
        $this->assertContains([PayloadEnvelopeResolver::class, 'resolve'], $apis);
        $this->assertContains([PayloadEnvelopeResolver::class, 'resolveToArray'], $apis);
        $this->assertContains([PayloadEnvelopeResolver::class, 'resolveCommandPayload'], $apis);
        $this->assertContains([PayloadEnvelopeResolver::class, 'resolveCommandPayloadWithCodec'], $apis);
        $this->assertContains([ExternalPayloads::class, 'externalizeForNamespace'], $apis);
        $this->assertContains([ExternalPayloads::class, 'isStoredReference'], $apis);
        $this->assertContains([ExternalPayloads::class, 'wireEnvelope'], $apis);
        $this->assertContains([ExternalPayloads::class, 'encodeStoredEnvelope'], $apis);
        $this->assertContains([ExternalPayloads::class, 'historyValue'], $apis);
        $this->assertContains([ExternalPayloads::class, 'storedEnvelope'], $apis);

        $constants = $this->privateConstant($floor, 'REQUIRED_CLASS_CONSTANTS');
        $this->assertContains([RunCommandContract::class, 'SOURCE_DURABLE_HISTORY'], $constants);
        $this->assertContains([RunCommandContract::class, 'SOURCE_UNAVAILABLE'], $constants);
        $this->assertContains([WorkerProtocolVersion::class, 'CAPABILITY_QUERY_TASKS'], $constants);
        $this->assertContains([ExternalPayloadReference::class, 'SCHEMA'], $constants);
        $this->assertContains([ExternalPayloads::class, 'STORED_REFERENCE_PREFIX'], $constants);
        $this->assertContains([WorkflowSearchAttribute::class, 'MAX_KEYWORD_LENGTH'], $constants);
        $this->assertContains([WorkflowSearchAttribute::class, 'TYPE_STRING'], $constants);
        $this->assertContains([WorkflowSearchAttribute::class, 'TYPE_FLOAT'], $constants);
        $this->assertContains([WorkflowSearchAttribute::class, 'TYPE_KEYWORD_LIST'], $constants);

        $classes = $this->privateConstant($floor, 'REQUIRED_CLASSES');
        $this->assertContains(ExternalPayloadIntegrityException::class, $classes);
        $this->assertContains(LocalFilesystemExternalPayloadStorage::class, $classes);

        $instances = $this->privateConstant($floor, 'REQUIRED_INSTANCE_APIS');
        $this->assertContains([DefaultWorkflowControlPlane::class, 'runtimeSignal'], $instances);

        $interfaces = $this->privateConstant($floor, 'REQUIRED_INTERFACE_APIS');
        $this->assertNotContains([WorkflowControlPlane::class, 'runtimeSignal'], $interfaces);
        $this->assertContains([ExternalPayloadStorageDriver::class, 'put'], $interfaces);
        $this->assertContains([ExternalPayloadStorageDriver::class, 'get'], $interfaces);
        $this->assertContains([ExternalPayloadStorageDriver::class, 'delete'], $interfaces);
        $this->assertContains([ExternalPayloadStoragePolicy::class, 'driverFor'], $interfaces);
        $this->assertContains([ExternalPayloadStoragePolicy::class, 'thresholdBytesFor'], $interfaces);
    }

    public function test_command_contract_apis_are_listed_in_the_package_floor(): void
    {
        $floor = new ReflectionClass(WorkflowPackageApiFloor::class);

        $apis = $this->privateConstant($floor, 'REQUIRED_APIS');
        $this->assertContains([RunCommandContract::class, 'forRun'], $apis);
        $this->assertContains([RunCommandContract::class, 'signalContract'], $apis);
        $this->assertContains([TypeRegistry::class, 'resolveWorkflowClass'], $apis);
        $this->assertContains([WorkflowDefinition::class, 'hasSignal'], $apis);
        $this->assertContains([WorkflowDefinition::class, 'signalContract'], $apis);
        $this->assertContains([WorkflowQueryContract::class, 'resolveTargetForRun'], $apis);
        $this->assertContains([WorkflowQueryContract::class, 'validatedArgumentsForRun'], $apis);
    }

    public function test_payload_envelope_resolver_external_storage_signature_matches_api_floor(): void
    {
        $confirms = $this->invokeConfirmsPayloadEnvelopeResolverSignature();

        $this->assertTrue(
            $confirms,
            'PayloadEnvelopeResolver no longer matches the server API floor. The server requires '
            .'the external-storage envelope signatures for workflow start, signal, query, update, '
            .'activity completion, and worker command payloads.'
        );
    }

    public function test_external_payload_helpers_match_api_floor(): void
    {
        $confirms = $this->invokeConfirmsExternalPayloadsSignature();

        $this->assertTrue(
            $confirms,
            'ExternalPayloads no longer matches the server API floor. The server requires stored '
            .'reference detection, worker/history envelope projection, and namespace externalization.'
        );
    }

    public function test_external_payload_storage_interfaces_match_api_floor(): void
    {
        $confirms = $this->invokeConfirmsExternalPayloadStorageInterfaces();

        $this->assertTrue(
            $confirms,
            'External payload storage interfaces no longer match the server API floor.'
        );
    }

    public function test_workflow_command_normalizer_payload_envelope_contract_matches_api_floor(): void
    {
        $reflection = new ReflectionClass(WorkflowCommandNormalizer::class);

        $this->assertSame(100, $reflection->getConstant('MAX_LOCAL_ACTIVITY_ATTEMPTS'));
        $this->assertSame(1000, $reflection->getConstant('MAX_LOCAL_ACTIVITY_HEARTBEATS_PER_ATTEMPT'));
        $this->assertSame(1000, $reflection->getConstant('MAX_LOCAL_ACTIVITY_HEARTBEATS'));
        $this->assertSame(
            '1.19',
            (new ReflectionClass(WorkerProtocolVersion::class))
                ->getConstant('LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION'),
        );

        foreach ([
            'payloadEnvelopeFields',
            'acceptsPayloadEnvelope',
            'parallelMetadataValidationRules',
            'preflightParallelMetadata',
            'normalize',
        ] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertTrue($method->isStatic());
        }

        $this->assertSame([
            'complete_workflow' => ['result'],
            'schedule_activity' => ['arguments'],
            'start_child_workflow' => ['arguments'],
            'continue_as_new' => ['arguments'],
            'complete_update' => ['result'],
            'record_side_effect' => ['result'],
            'record_local_activity' => ['arguments', 'result'],
            'start_service_operation' => ['request_payload'],
            'upsert_memo' => ['entries'],
        ], WorkflowCommandNormalizer::payloadEnvelopeFields());

        $this->assertTrue($this->invokeConfirmsWorkflowCommandNormalizerPayloadEnvelopeContract());
    }

    public function test_run_command_contract_signature_matches_api_floor(): void
    {
        $reflection = new ReflectionClass(RunCommandContract::class);
        $method = $reflection->getMethod('forRun');

        $this->assertTrue($method->isPublic());
        $this->assertTrue($method->isStatic());
        $this->assertTrue(
            $this->invokeConfirmsRunCommandContractSignature(),
            'RunCommandContract::forRun() no longer matches the server API floor. If this fails, '
            .'the installed workflow package can pass boot and then fail during query routing.'
        );
    }

    public function test_signal_preview_validation_signatures_match_api_floor(): void
    {
        $this->assertTrue((new ReflectionClass(RunCommandContract::class))->getMethod('signalContract')->isStatic());
        $this->assertTrue((new ReflectionClass(TypeRegistry::class))->getMethod('resolveWorkflowClass')->isStatic());
        $this->assertTrue((new ReflectionClass(WorkflowDefinition::class))->getMethod('hasSignal')->isStatic());
        $this->assertTrue((new ReflectionClass(WorkflowDefinition::class))->getMethod('signalContract')->isStatic());

        $this->assertTrue(
            $this->invokeConfirmsSignalPreviewValidationSignatures(),
            'Signal dry-run preview validation APIs no longer match the server API floor.'
        );
    }

    public function test_workflow_query_contract_signature_matches_api_floor(): void
    {
        $reflection = new ReflectionClass(WorkflowQueryContract::class);

        foreach (['resolveTargetForRun', 'validatedArgumentsForRun'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertTrue($method->isStatic());
        }

        $this->assertTrue(
            $this->invokeConfirmsWorkflowQueryContractSignature(),
            'WorkflowQueryContract no longer matches the server API floor. If this fails, '
            .'external query validation can fatal after boot on stale workflow package snapshots.'
        );
    }

    public function test_workflow_task_bridge_poll_signature_matches_api_floor(): void
    {
        $confirms = $this->invokeConfirmsWorkflowTaskPollSignature(WorkflowTaskBridge::class, 'poll');

        $this->assertTrue(
            $confirms,
            'WorkflowTaskBridge::poll() no longer matches the server API floor. If this fails, '
            .'either the installed workflow package is stale or the polling contract changed '
            .'without updating the shared release contract.'
        );
    }

    public function test_workflow_task_bridge_poll_signature_rejects_legacy_fixture(): void
    {
        $confirms = $this->invokeConfirmsWorkflowTaskPollSignature(LegacyWorkflowTaskBridgePollSignature::class, 'poll');

        $this->assertFalse(
            $confirms,
            'Legacy poll fixture was accepted by the workflow-task poll floor check — the server '
            .'would silently permit a stale workflow package baseline again.'
        );
    }

    public function test_activity_task_bridge_poll_signature_matches_api_floor(): void
    {
        $confirms = $this->invokeConfirmsActivityTaskPollSignature(ActivityTaskBridge::class, 'poll');

        $this->assertTrue(
            $confirms,
            'ActivityTaskBridge::poll() no longer matches the server API floor. If this fails, '
            .'either the installed workflow package is stale or the polling contract changed '
            .'without updating the shared release contract.'
        );
    }

    public function test_activity_task_bridge_poll_signature_rejects_legacy_fixture(): void
    {
        $confirms = $this->invokeConfirmsActivityTaskPollSignature(LegacyActivityTaskBridgePollSignature::class, 'poll');

        $this->assertFalse(
            $confirms,
            'Legacy activity poll fixture was accepted by the activity-task poll floor check — '
            .'the server would silently permit a stale workflow package baseline again.'
        );
    }

    public function test_default_matching_role_exposes_public_instance_repair_methods(): void
    {
        $reflection = new ReflectionClass(DefaultMatchingRole::class);

        foreach (['wake', 'runPass'] as $methodName) {
            $method = $reflection->getMethod($methodName);

            $this->assertTrue($method->isPublic());
            $this->assertFalse($method->isStatic());
        }
    }

    public function test_poll_mode_demotion_check_accepts_current_workflow_package(): void
    {
        $confirms = $this->invokeConfirmsPollModeDemotion(BackendCapabilities::class, 'queue');

        $this->assertTrue(
            $confirms,
            'BackendCapabilities::queue() in the currently resolved workflow package does not '
            .'contain the poll-mode demotion keywords. If this fails, either the package is stale '
            .'or the method body was refactored in a way that no longer matches the floor check.'
        );
    }

    public function test_poll_mode_demotion_check_rejects_stale_fixture(): void
    {
        // StaleBackendCapabilities::queue() reproduces the pre-f666b25 body
        // (no task_dispatch_mode read, no 'info' demotion). The functional
        // check must reject it so an old workflow install cannot silently
        // satisfy the API floor.
        $confirms = $this->invokeConfirmsPollModeDemotion(StaleBackendCapabilities::class, 'queue');

        $this->assertFalse(
            $confirms,
            'Stale fixture was accepted by the poll-mode demotion check — the check is no longer '
            .'specific enough to catch pre-f666b25 installs.'
        );
    }

    private function invokeConfirmsWorkflowTaskPollSignature(string $class, string $method): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsWorkflowTaskPollSignature');

        /** @var bool $result */
        $result = $reflection->invoke(null, $class, $method);

        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    private function privateConstant(ReflectionClass $reflection, string $name): array
    {
        $constant = $reflection->getReflectionConstant($name);

        $this->assertNotFalse($constant);

        /** @var array<int, mixed> $value */
        $value = $constant->getValue();

        return $value;
    }

    private function invokeConfirmsPayloadEnvelopeResolverSignature(): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsPayloadEnvelopeResolverSignature');

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsExternalPayloadsSignature(): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsExternalPayloadsSignature');

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsExternalPayloadStorageInterfaces(): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsExternalPayloadStorageInterfaces');

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsWorkflowCommandNormalizerPayloadEnvelopeContract(): bool
    {
        $reflection = new ReflectionMethod(
            WorkflowPackageApiFloor::class,
            'confirmsWorkflowCommandNormalizerPayloadEnvelopeContract',
        );

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsRunCommandContractSignature(): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsRunCommandContractSignature');

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsSignalPreviewValidationSignatures(): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsSignalPreviewValidationSignatures');

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsWorkflowQueryContractSignature(): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsWorkflowQueryContractSignature');

        /** @var bool $result */
        $result = $reflection->invoke(null);

        return $result;
    }

    private function invokeConfirmsActivityTaskPollSignature(string $class, string $method): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsActivityTaskPollSignature');

        /** @var bool $result */
        $result = $reflection->invoke(null, $class, $method);

        return $result;
    }

    private function invokeConfirmsPollModeDemotion(string $class, string $method): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsPollModeDemotion');

        /** @var bool $result */
        $result = $reflection->invoke(null, $class, $method);

        return $result;
    }
}
