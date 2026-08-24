<?php

declare(strict_types=1);

namespace Tests\Feature\CodecRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ServerTestHelpers;
use Tests\Support\ServerCodecRegressionBoundaryV4;
use Tests\Support\ServerCodecRegressionFixtureExecutor;
use Tests\TestCase;

final class MessageStreamNestedEnvelopeTest extends TestCase
{
    use RefreshDatabase;
    use ServerTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createNamespace('default');
        config(['server.polling.timeout' => 0]);
    }

    public function test_nested_message_payload_codec_is_checked_on_every_history_delivery_path(): void
    {
        ServerCodecRegressionFixtureExecutor::exercise(function (): void {
            ServerCodecRegressionBoundaryV4::exerciseHistoryDelivery($this);
        });
    }
}
