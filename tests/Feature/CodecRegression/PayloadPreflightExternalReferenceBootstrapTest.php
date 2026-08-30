<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV11;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class PayloadPreflightExternalReferenceBootstrapTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createNamespace('default');
    }

    public function test_canonical_external_reference_survives_bootstrap_at_the_claimed_boundary(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV11::exerciseCanonicalStoredReference($this);
        });
    }
}
