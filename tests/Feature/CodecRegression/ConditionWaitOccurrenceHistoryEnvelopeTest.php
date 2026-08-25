<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Fixtures\ExternalGreetingWorkflow;
use Tests\Support\ServerCodecRegressionBoundaryV7;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class ConditionWaitOccurrenceHistoryEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config([
            'server.polling.timeout' => 0,
            'workflows.v2.types.workflows' => [
                'tests.external-greeting-workflow' => ExternalGreetingWorkflow::class,
            ],
        ]);
    }

    public function test_condition_wait_occurrence_history_is_not_leased_below_its_protocol_floor(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV7::exerciseConditionWaitOccurrenceRouting($this);
        });
    }
}
