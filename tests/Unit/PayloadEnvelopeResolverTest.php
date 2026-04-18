<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\PayloadEnvelopeResolver;

class PayloadEnvelopeResolverTest extends TestCase
{
    public function test_resolve_to_array_returns_empty_for_null(): void
    {
        $this->assertSame([], PayloadEnvelopeResolver::resolveToArray(null));
    }

    public function test_resolve_to_array_returns_empty_for_empty_array(): void
    {
        $this->assertSame([], PayloadEnvelopeResolver::resolveToArray([]));
    }

    public function test_resolve_to_array_returns_plain_positional_args(): void
    {
        $this->assertSame(['Ada', 42], PayloadEnvelopeResolver::resolveToArray(['Ada', 42]));
    }

    public function test_resolve_to_array_preserves_named_keys(): void
    {
        $input = ['name' => 'Ada', 'age' => 42];
        $this->assertSame($input, PayloadEnvelopeResolver::resolveToArray($input));
    }

    public function test_resolve_to_array_decodes_avro_envelope(): void
    {
        $result = PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', ['hello', 'world']),
        ]);

        $this->assertSame(['hello', 'world'], $result);
    }

    public function test_resolve_to_array_rejects_undecodable_envelope(): void
    {
        $this->expectException(ValidationException::class);

        PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'workflow-serializer-y',
            'blob' => 'serialized-data',
        ]);
    }

    public function test_resolve_to_array_rejects_non_array_blob(): void
    {
        $this->expectException(ValidationException::class);

        PayloadEnvelopeResolver::resolveToArray([
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', 'just-a-string'),
        ]);
    }

    public function test_resolve_to_array_rejects_non_array_input(): void
    {
        $this->expectException(ValidationException::class);

        PayloadEnvelopeResolver::resolveToArray('not-an-array');
    }

    public function test_resolve_command_payload_returns_null_for_null(): void
    {
        $this->assertNull(PayloadEnvelopeResolver::resolveCommandPayload(null));
    }

    public function test_resolve_command_payload_passes_through_string(): void
    {
        $this->assertSame('raw-value', PayloadEnvelopeResolver::resolveCommandPayload('raw-value'));
    }

    public function test_resolve_command_payload_passes_through_non_envelope_array(): void
    {
        $input = ['foo' => 'bar'];
        $this->assertSame($input, PayloadEnvelopeResolver::resolveCommandPayload($input));
    }

    public function test_resolve_command_payload_extracts_blob_from_envelope(): void
    {
        $blob = Serializer::serializeWithCodec('avro', ['result' => 'ok']);

        $result = PayloadEnvelopeResolver::resolveCommandPayload([
            'codec' => 'avro',
            'blob' => $blob,
        ]);

        $this->assertSame($blob, $result);
    }

    public function test_resolve_command_payload_accepts_legacy_codec(): void
    {
        $result = PayloadEnvelopeResolver::resolveCommandPayload([
            'codec' => 'workflow-serializer-y',
            'blob' => 'php-serialized-data',
        ]);

        $this->assertSame('php-serialized-data', $result);
    }

    public function test_resolve_command_payload_rejects_unknown_codec(): void
    {
        $this->expectException(ValidationException::class);

        PayloadEnvelopeResolver::resolveCommandPayload([
            'codec' => 'unknown-codec',
            'blob' => 'data',
        ]);
    }

    public function test_resolve_command_payload_rejects_empty_codec(): void
    {
        $this->expectException(ValidationException::class);

        PayloadEnvelopeResolver::resolveCommandPayload([
            'codec' => '',
            'blob' => 'data',
        ]);
    }
}
