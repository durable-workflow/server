<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV3;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class WorkflowStreamItemEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config(['server.polling.timeout' => 0]);
    }

    public function test_workflow_stream_item_codec_is_checked_at_the_worker_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV3::exerciseWorkflowStreamItem($this);
        });
    }
}
