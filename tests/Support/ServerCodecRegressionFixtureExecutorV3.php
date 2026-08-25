<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;

/** Avro map-normalization extension for the immutable codec fixture executors. */
final class ServerCodecRegressionFixtureExecutorV3
{
    public static function exercisePath(string $fixturePath): ServerCodecRegressionFixture
    {
        $contents = file_get_contents($fixturePath);
        Assert::assertIsString($contents);
        $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        Assert::assertIsArray($document);

        if (($document['protocol']['codec'] ?? null) !== 'avro'
            || ($document['value']['type'] ?? null) !== 'map'
        ) {
            return ServerCodecRegressionFixtureExecutorV2::exercisePath($fixturePath);
        }

        Assert::assertSame('durable-workflow.codec-regression/v1', $document['fixture_schema'] ?? null);
        Assert::assertContains('php', $document['bindings'] ?? []);
        Assert::assertSame(Avro::valueSchemaFingerprint(), $document['protocol']['fingerprint'] ?? null);
        Assert::assertSame('round_trip', $document['failure_policy']['operation'] ?? null);
        Assert::assertNull($document['failure_policy']['error'] ?? null);

        $id = $document['id'] ?? null;
        $wire = $document['framing']['wire_base64'] ?? null;
        $taggedValue = $document['value'] ?? null;
        Assert::assertIsString($id);
        Assert::assertIsString($wire);
        Assert::assertIsArray($taggedValue);

        $value = self::taggedValue($taggedValue);
        Assert::assertSame($wire, Avro::serialize($value), $id);

        // Faithful string-keyed Avro maps decode as native PHP arrays, while
        // ambiguous maps retain AvroMapValue. Exact bytes are their shared,
        // lossless typed identity across that binding normalization.
        $decoded = Avro::unserialize($wire);
        Assert::assertSame($wire, Avro::serialize($decoded), $id);

        return new ServerCodecRegressionFixture(
            id: $id,
            codec: 'avro',
            value: $value,
            wire: $wire,
            operation: 'round_trip',
            error: null,
        );
    }

    /** @param array<string, mixed> $value */
    private static function taggedValue(array $value): mixed
    {
        return match ($value['type'] ?? null) {
            'null' => null,
            'boolean' => (bool) $value['value'],
            'long' => (int) $value['value'],
            'double' => (float) $value['value'],
            'bytes' => AvroBinaryValue::fromBytes(
                (string) base64_decode((string) $value['base64'], true),
            ),
            'string' => (string) $value['value'],
            'array' => array_map(
                self::taggedValue(...),
                is_array($value['items'] ?? null) ? $value['items'] : [],
            ),
            'map' => AvroMapValue::fromPairs(array_map(
                static fn (array $entry): array => [
                    (string) $entry['key'],
                    self::taggedValue($entry['value']),
                ],
                is_array($value['entries'] ?? null) ? $value['entries'] : [],
            )),
            default => throw new InvalidArgumentException('Unsupported tagged corpus value.'),
        };
    }
}
