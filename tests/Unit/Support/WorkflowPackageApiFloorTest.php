<?php

namespace Tests\Unit\Support;

use App\Support\WorkflowPackageApiFloor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Workflow\Serializers\CodecRegistry;

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
}
