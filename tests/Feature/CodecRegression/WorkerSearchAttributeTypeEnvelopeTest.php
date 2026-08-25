<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV6;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class WorkerSearchAttributeTypeEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config(['server.polling.timeout' => 0]);
    }

    public function test_worker_search_attribute_type_survives_the_payload_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV6::exerciseWorkerSearchAttributeType($this);
        });
    }
}
