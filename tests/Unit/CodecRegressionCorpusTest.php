<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Avro;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\CodecDecodeException;

final class CodecRegressionCorpusTest extends TestCase
{
    public function test_checked_in_codec_regression_corpus_uses_the_official_php_binding(): void
    {
        $paths = glob(__DIR__.'/../Fixtures/CodecRegression/*.json') ?: [];
        sort($paths);
        self::assertNotSame([], $paths);

        foreach ($paths as $path) {
            $fixture = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('durable-workflow.codec-regression/v1', $fixture['fixture_schema'] ?? null);
            self::assertContains('php', $fixture['bindings'] ?? []);
            self::assertSame(Avro::valueSchemaFingerprint(), $fixture['protocol']['fingerprint'] ?? null);

            $value = self::taggedValue($fixture['value']);
            $wire = $fixture['framing']['wire_base64'] ?? null;
            $operation = $fixture['failure_policy']['operation'] ?? null;
            $error = $fixture['failure_policy']['error'] ?? null;

            if ($operation === 'round_trip') {
                self::assertIsString($wire);
                self::assertSame($wire, Avro::serialize($value), $fixture['id']);
                $decoded = Avro::unserialize($wire);
                self::assertEquals($value, $decoded, $fixture['id']);
                self::assertSame($wire, Avro::serialize($decoded), $fixture['id']);

                continue;
            }

            try {
                if ($operation === 'decode_reject') {
                    self::assertIsString($wire);
                    Avro::unserialize($wire);
                } elseif ($operation === 'encode_reject') {
                    Avro::serialize($value);
                } else {
                    self::fail("Unsupported failure policy in {$path}.");
                }
                self::fail("Expected {$fixture['id']} to be rejected.");
            } catch (InvalidArgumentException|CodecDecodeException $exception) {
                self::assertIsString($error);
                self::assertStringContainsString($error, $exception->getMessage());
            }
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
