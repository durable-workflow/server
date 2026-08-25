<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\ServerCodecRegressionBoundaryV4;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class WorkflowMemoExternalPayloadEnvelopeTest extends TestCase
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

    public function test_runtime_resolves_opaque_memo_entries_before_recording_history(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV4::exerciseWorkflowMemoResolution($this);
        });
    }
}
