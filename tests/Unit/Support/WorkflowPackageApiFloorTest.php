<?php

namespace Tests\Unit\Support;

use App\Support\WorkflowPackageApiFloor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Fixtures\LegacyWorkflowTaskBridgePollSignature;
use Tests\Fixtures\StaleBackendCapabilities;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\MatchingRole;
use Workflow\V2\Contracts\ServiceControlPlane;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Support\BackendCapabilities;
use Workflow\V2\Support\ChildWorkflowNamespaceProjection;
use Workflow\V2\Support\DefaultMatchingRole;
use Workflow\V2\Support\MatchingRoleSnapshot;
use Workflow\V2\Support\ServiceExecutionContract;

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
    }

    public function test_codec_registry_engine_specific_is_public_static(): void
    {
        $reflection = new ReflectionClass(CodecRegistry::class);
        $method = $reflection->getMethod('engineSpecific');

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

    private function invokeConfirmsPollModeDemotion(string $class, string $method): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsPollModeDemotion');

        /** @var bool $result */
        $result = $reflection->invoke(null, $class, $method);

        return $result;
    }
}
