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
    private const FIXTURE_FORMAT = 'codec-regression-v1';

    public function test_checked_in_codec_regression_corpus_uses_the_official_php_binding(): void
    {
        foreach (self::fixturePaths() as $path) {
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

    /** @return list<string> */
    private static function fixturePaths(): array
    {
        $root = dirname(__DIR__, 2);
        $policy = json_decode(
            (string) file_get_contents($root.'/regression-corpus-policy.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($policy);
        self::assertSame('server', $policy['repository'] ?? null);
        self::assertSame('php', $policy['binding'] ?? null);

        $selectors = $policy['categories']['codec']['fixtures'] ?? null;
        self::assertIsArray($selectors);
        self::assertNotSame([], $selectors);

        $paths = [];
        foreach ($selectors as $selector) {
            self::assertIsArray($selector);
            self::assertSame(
                self::FIXTURE_FORMAT,
                $selector['format'] ?? null,
                'The server codec policy contains a format without an official PHP executor.',
            );
            $glob = $selector['glob'] ?? null;
            self::assertIsString($glob);
            self::assertMatchesRegularExpression(
                '/\A(?:[A-Za-z0-9_-][A-Za-z0-9._-]*\/)*(?:[A-Za-z0-9_-][A-Za-z0-9._-]*|\*)\.json\z/D',
                $glob,
                'The codec fixture selector is not portable to the official PHP runner.',
            );
            array_push($paths, ...(glob($root.'/'.$glob) ?: []));
        }

        self::assertNotSame([], $paths);
        self::assertSame(
            count($paths),
            count(array_unique($paths)),
            'A codec fixture is selected more than once by the server policy.',
        );
        sort($paths);

        return $paths;
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
