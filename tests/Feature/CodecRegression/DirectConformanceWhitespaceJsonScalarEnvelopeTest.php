<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Tests\Support\ServerCodecRegressionBoundaryV10;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class DirectConformanceWhitespaceJsonScalarEnvelopeTest extends TestCase
{
    public function test_direct_completion_rejects_whitespace_json_scalars_and_accepts_avro_envelopes(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV10::exerciseDirectCompletion();
        });
    }
}
