<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV2;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class JsonTaggedPayloadCodecRejectedTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config(['server.polling.timeout' => 0]);
    }

    public function test_json_tagged_payload_is_rejected_at_the_claimed_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV2::exerciseClaimedBoundary($this);
        });
    }
}
