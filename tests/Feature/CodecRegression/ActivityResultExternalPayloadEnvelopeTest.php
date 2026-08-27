<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV8;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class ActivityResultExternalPayloadEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_activity_result_replaces_provider_storage_at_the_claimed_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV8::exerciseActivityResult($this);
        });
    }
}
