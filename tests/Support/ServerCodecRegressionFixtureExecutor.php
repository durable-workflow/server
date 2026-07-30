<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\CodecDecodeException;

final class ServerCodecRegressionFixtureExecutor
{
    /**
     * Exercise the selected fixture through the claimed server boundary.
     *
     * The callback deliberately receives no fixture data. The validator
     * embeds the fixture in its instrumented application snapshot, outside
     * candidate-authored proof control flow.
     *
     * @param  callable(): mixed  $boundary
     */
    public static function exercise(callable $boundary): mixed
    {
        return $boundary();
    }

    public static function exercisePath(string $fixturePath): ServerCodecRegressionFixture
    {
        return self::exerciseJson(
            (string) file_get_contents($fixturePath),
        );
    }

    public static function exerciseEncoded(string $encodedFixture): ServerCodecRegressionFixture
    {
        $fixture = base64_decode($encodedFixture, true);
        if (! is_string($fixture)) {
            throw new InvalidArgumentException('Invalid encoded server codec fixture.');
        }

        return self::exerciseJson($fixture);
    }

    private static function exerciseJson(string $fixtureJson): ServerCodecRegressionFixture
    {
        $fixture = json_decode(
            $fixtureJson,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        Assert::assertIsArray($fixture);
        Assert::assertSame(
            'durable-workflow.codec-regression/v1',
            $fixture['fixture_schema'] ?? null,
        );
        Assert::assertContains('php', $fixture['bindings'] ?? []);
        Assert::assertSame(
            Avro::valueSchemaFingerprint(),
            $fixture['protocol']['fingerprint'] ?? null,
        );

        $id = $fixture['id'] ?? null;
        $codec = $fixture['protocol']['codec'] ?? null;
        $taggedValue = $fixture['value'] ?? null;
        $wire = $fixture['framing']['wire_base64'] ?? null;
        $operation = $fixture['failure_policy']['operation'] ?? null;
        $error = $fixture['failure_policy']['error'] ?? null;
        Assert::assertIsString($id);
        Assert::assertIsString($codec);
        Assert::assertIsArray($taggedValue);
        Assert::assertIsString($operation);
        Assert::assertTrue($wire === null || is_string($wire));
        Assert::assertTrue($error === null || is_string($error));

        $value = self::taggedValue($taggedValue);
        self::exerciseOfficialBinding($id, $value, $wire, $operation, $error);

        return new ServerCodecRegressionFixture(
            id: $id,
            codec: $codec,
            value: $value,
            wire: $wire,
            operation: $operation,
            error: $error,
        );
    }

    private static function exerciseOfficialBinding(
        string $id,
        mixed $value,
        ?string $wire,
        string $operation,
        ?string $error,
    ): void {
        if ($operation === 'round_trip') {
            Assert::assertIsString($wire);
            Assert::assertSame($wire, Avro::serialize($value), $id);
            $decoded = Avro::unserialize($wire);
            Assert::assertEquals($value, $decoded, $id);
            Assert::assertSame($wire, Avro::serialize($decoded), $id);

            return;
        }

        try {
            if ($operation === 'decode_reject') {
                Assert::assertIsString($wire);
                Avro::unserialize($wire);
            } elseif ($operation === 'encode_reject') {
                Avro::serialize($value);
            } else {
                Assert::fail("Unsupported failure policy for {$id}.");
            }
            Assert::fail("Expected {$id} to be rejected.");
        } catch (InvalidArgumentException|CodecDecodeException $exception) {
            Assert::assertIsString($error);
            Assert::assertStringContainsString($error, $exception->getMessage());
        }
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
