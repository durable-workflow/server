<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\PayloadCodecContract;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/** Avro-only extension for JSON-tagged negative corpus fixtures. */
final class ServerCodecRegressionFixtureExecutorV2
{
    public static function exercisePath(string $fixturePath): ServerCodecRegressionFixture
    {
        $contents = file_get_contents($fixturePath);
        Assert::assertIsString($contents);
        $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($document);

        if (($document['protocol']['codec'] ?? null) === 'avro') {
            return ServerCodecRegressionFixtureExecutor::exercisePath($fixturePath);
        }

        Assert::assertSame('json', $document['protocol']['codec'] ?? null);
        Assert::assertSame('decode_reject', $document['failure_policy']['operation'] ?? null);
        Assert::assertSame(
            'unsupported_payload_codec',
            $document['failure_policy']['error'] ?? null,
        );

        try {
            PayloadCodecContract::canonicalize($document['protocol']['codec']);
            Assert::fail('Expected the JSON-tagged workflow payload to be rejected.');
        } catch (InvalidArgumentException $exception) {
            Assert::assertStringContainsString(
                'unsupported_payload_codec',
                $exception->getMessage(),
            );
        }

        return new ServerCodecRegressionFixture(
            id: (string) $document['id'],
            codec: 'json',
            value: $document['value'],
            wire: is_string($document['framing']['wire_base64'] ?? null)
                ? $document['framing']['wire_base64']
                : null,
            operation: 'decode_reject',
            error: 'unsupported_payload_codec',
        );
    }
}
