<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PhpSdkSignalInputDecoderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2).'/scripts/conformance/php-sdk-signal-input-decoder.php';
    }

    public function test_requested_signal_name_is_not_filtered_by_other_signal_events(): void
    {
        $events = [
            $this->signalEvent('increment', [3]),
            $this->signalEvent('release', [7]),
            $this->signalEvent('increment', [5]),
        ];
        $decodedEnvelopes = [];
        $decodeEnvelope = static function (array|string $envelope) use (&$decodedEnvelopes): mixed {
            $decodedEnvelopes[] = $envelope;

            return $envelope;
        };

        $this->assertSame([7], php_sdk_decoded_signal_inputs($events, 'release', $decodeEnvelope));
        $this->assertSame([[7]], $decodedEnvelopes);

        $decodedEnvelopes = [];
        $this->assertSame([3, 5], php_sdk_decoded_signal_inputs($events, 'increment', $decodeEnvelope));
        $this->assertSame([[3], [5]], $decodedEnvelopes);
    }

    public function test_empty_requested_signal_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        php_sdk_decoded_signal_inputs([], '', static fn (array|string $envelope): mixed => $envelope);
    }

    /** @param list<int> $arguments */
    private function signalEvent(string $signalName, array $arguments): array
    {
        return [
            'event_type' => 'SignalReceived',
            'payload' => [
                'signal_name' => $signalName,
                'arguments' => $arguments,
            ],
        ];
    }
}
