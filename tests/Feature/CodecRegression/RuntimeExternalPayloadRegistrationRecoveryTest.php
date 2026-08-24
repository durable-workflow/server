<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV5;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class RuntimeExternalPayloadRegistrationRecoveryTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config([
            'server.external_payload_transport.abandoned_upload_expiry_seconds' => 60,
            'server.external_payload_transport.max_payload_bytes' => 4096,
        ]);
    }

    public function test_failed_registration_remains_reclaimable_at_the_claimed_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV5::exerciseClaimedBoundary();
        });
    }
}
