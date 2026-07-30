<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;
use Workflow\Serializers\Avro;
use Workflow\Serializers\Serializer;

/**
 * Trusted codec-call proxy used only by instrumented counterfactual snapshots.
 *
 * The validator rewrites the claimed application boundary in a temporary
 * source tree to call this proxy with an immutable, encoded fixture and a
 * per-run evidence token. No verifier state is shared with candidate-authored
 * proof code.
 */
final class ServerCodecRegressionBoundary
{
    public static function serializeWithCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        ?string $codec,
        mixed $data,
    ): string {
        [$codec, $data] = self::encodeArguments(
            $encodedFixture,
            $boundaryPath,
            $evidence,
            $codec,
            $data,
        );

        return Serializer::serializeWithCodec($codec, $data);
    }

    public static function unserializeWithCodec(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        ?string $codec,
        string $data,
    ): mixed {
        [$codec, $data] = self::decodeArguments(
            $encodedFixture,
            $boundaryPath,
            $evidence,
            $codec,
            $data,
        );

        return Serializer::unserializeWithCodec($codec, $data);
    }

    public static function serialize(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        mixed $data,
    ): string {
        [, $data] = self::encodeArguments(
            $encodedFixture,
            $boundaryPath,
            $evidence,
            'avro',
            $data,
        );

        return Avro::serialize($data);
    }

    public static function unserialize(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        string $data,
    ): mixed {
        [, $data] = self::decodeArguments(
            $encodedFixture,
            $boundaryPath,
            $evidence,
            'avro',
            $data,
        );

        return Avro::unserialize($data);
    }

    /** @return array{0: ?string, 1: mixed} */
    private static function encodeArguments(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        ?string $codec,
        mixed $data,
    ): array {
        $fixture = ServerCodecRegressionFixtureExecutor::exerciseEncoded($encodedFixture);
        if (! in_array($fixture->operation, ['round_trip', 'encode_reject'], true)) {
            return [$codec, $data];
        }

        self::recordEvidence($boundaryPath, $evidence);

        return [$fixture->codec, $fixture->value];
    }

    /** @return array{0: ?string, 1: string} */
    private static function decodeArguments(
        string $encodedFixture,
        string $boundaryPath,
        string $evidence,
        ?string $codec,
        string $data,
    ): array {
        $fixture = ServerCodecRegressionFixtureExecutor::exerciseEncoded($encodedFixture);
        if (! in_array($fixture->operation, ['round_trip', 'decode_reject'], true)) {
            return [$codec, $data];
        }

        Assert::assertIsString($fixture->wire);
        self::recordEvidence($boundaryPath, $evidence);

        return [$fixture->codec, $fixture->wire];
    }

    private static function recordEvidence(string $boundaryPath, string $evidence): void
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['file'] ?? null) === $boundaryPath) {
                if (
                    preg_match(
                        '/\Adurable-workflow-codec-boundary\/v1:[a-f0-9]{64}\z/D',
                        $evidence,
                    ) !== 1
                ) {
                    throw new RuntimeException('Invalid server codec boundary evidence token.');
                }
                fwrite(STDERR, $evidence.PHP_EOL);

                return;
            }
        }

        throw new RuntimeException(
            "The counted fixture was invoked outside the claimed codec boundary {$boundaryPath}.",
        );
    }
}
