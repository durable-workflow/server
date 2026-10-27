<?php

namespace Tests\Unit;

use App\Support\JsonProjection;
use PHPUnit\Framework\TestCase;

class JsonProjectionTest extends TestCase
{
    public function test_projection_preserves_number_tokens_and_quoted_content(): void
    {
        $raw = <<<'JSON'
{"replace":"fixture","numbers":[1.0,-0,9223372036854775808,1.2300e+4,1e400,-7,0.5],"quoted":"escaped \"123\" and \u0031.0","123":{},"empty":[]}
JSON;
        $projected = JsonProjection::map($raw, static function (object $body): object {
            $body->replace = ['external_payload' => 'opaque'];

            return $body;
        });
        $this->assertStringContainsString('"numbers":[1.0,-0,9223372036854775808,1.2300e+4,1e400,-7,0.5]', $projected);
        $this->assertStringContainsString('"123":{}', $projected);
        $this->assertStringContainsString('"empty":[]', $projected);
        $decoded = json_decode($projected, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(json_decode($raw, flags: JSON_THROW_ON_ERROR)->quoted, $decoded->quoted);
        $this->assertSame('opaque', $decoded->replace->external_payload);
    }

    public function test_large_inline_payload_is_not_tokenized_as_numbers(): void
    {
        $blob = str_repeat('0123456789', 100000);
        $projected = JsonProjection::map(json_encode(['blob' => $blob]), static fn ($body) => $body);
        $this->assertSame($blob, json_decode($projected, true, flags: JSON_THROW_ON_ERROR)['blob']);
    }

    public function test_original_encoding_options_are_preserved(): void
    {
        $this->assertSame('{"float":1.0,"text":"é/"}', JsonProjection::map('{"float":1.0,"text":"é/"}',
            static fn ($body) => $body, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
