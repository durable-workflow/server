<?php

namespace Tests\Unit;

use App\Support\ExternalPayloadObjectOversized;
use App\Support\ExternalPayloadStream;
use Tests\TestCase;

class ExternalPayloadStreamTest extends TestCase
{
    public function test_exact_default_transport_limit_has_bounded_php_memory(): void
    {
        $source = tmpfile();
        $this->assertIsResource($source);
        $chunk = str_repeat('x', 8192);
        $hash = hash_init('sha256');
        for ($i = 0; $i < 8192; $i++) {
            fwrite($source, $chunk);
            hash_update($hash, $chunk);
        }
        rewind($source);
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        try {
            $payload = ExternalPayloadStream::capture($source, 64 * 1024 * 1024);
            $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
            $this->assertSame(64 * 1024 * 1024, $payload->sizeBytes);
            $this->assertSame(hash_final($hash), $payload->sha256);
            $this->assertSame($chunk, fread($payload->rewind(), 8192));
            $payload->close();
        } finally {
            fclose($source);
        }
    }

    public function test_rejected_stream_stops_at_one_byte_over_limit(): void
    {
        $source = tmpfile();
        fwrite($source, str_repeat('x', 8192));
        rewind($source);

        try {
            ExternalPayloadStream::capture($source, 1024);
            $this->fail('Oversized stream should fail.');
        } catch (ExternalPayloadObjectOversized) {
            $this->assertSame(1025, ftell($source));
        } finally {
            fclose($source);
        }
    }

    public function test_verified_snapshot_is_independent_of_the_backing_stream(): void
    {
        $source = tmpfile();
        fwrite($source, "verified\x00bytes");
        rewind($source);
        $payload = ExternalPayloadStream::capture($source, 1024);
        rewind($source);
        fwrite($source, 'corrupted data');
        fclose($source);

        $this->assertSame("verified\x00bytes", stream_get_contents($payload->rewind()));
        $this->assertSame(hash('sha256', "verified\x00bytes"), $payload->sha256);
        $payload->close();
        $payload->close();
        $this->expectException(\RuntimeException::class);
        $payload->rewind();
    }
}
