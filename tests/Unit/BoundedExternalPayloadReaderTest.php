<?php

namespace Tests\Unit;

use App\Support\BoundedExternalPayloadReader;
use App\Support\ExternalPayloadObjectOversized;
use Tests\TestCase;

class BoundedExternalPayloadReaderTest extends TestCase
{
    public function test_oversized_stream_is_stopped_after_the_limit_probe_byte(): void
    {
        $stream = tmpfile();
        $this->assertIsResource($stream);
        fwrite($stream, str_repeat('x', 32 * 1024));
        rewind($stream);

        try {
            BoundedExternalPayloadReader::read($stream, 1024);
            $this->fail('Expected an oversized backing object to be rejected.');
        } catch (ExternalPayloadObjectOversized) {
            $this->assertSame(1025, ftell($stream));
        } finally {
            fclose($stream);
        }
    }
}
