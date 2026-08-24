<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\ServerCodecRegressionBoundaryV4;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class RuntimeExternalPayloadReferenceEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config([
            'server.external_payload_transport.max_payload_bytes' => 4096,
            'server.polling.timeout' => 0,
            'workflows.v2.types.workflows' => [
                'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            ],
        ]);
    }

    public function test_runtime_reference_replaces_provider_storage_at_the_claimed_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV4::exerciseClaimedBoundary($this);
        });
    }
}
