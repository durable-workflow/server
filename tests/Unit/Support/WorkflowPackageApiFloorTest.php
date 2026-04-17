<?php

namespace Tests\Unit\Support;

use App\Support\WorkflowPackageApiFloor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Fixtures\StaleBackendCapabilities;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Support\BackendCapabilities;

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

    public function test_backend_capabilities_class_exists(): void
    {
        $this->assertTrue(class_exists(WorkflowPackageApiFloor::POLL_MODE_DEMOTION_CLASS));
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

    private function invokeConfirmsPollModeDemotion(string $class, string $method): bool
    {
        $reflection = new ReflectionMethod(WorkflowPackageApiFloor::class, 'confirmsPollModeDemotion');
        $reflection->setAccessible(true);

        /** @var bool $result */
        $result = $reflection->invoke(null, $class, $method);

        return $result;
    }
}
