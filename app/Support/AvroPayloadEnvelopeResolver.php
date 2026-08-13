<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Support\PayloadEnvelopeResolver;

/** Server-owned guard that keeps Avro-only admission independent of package landing order. */
final class AvroPayloadEnvelopeResolver
{
    public static function resolve(
        mixed $input,
        string $field = 'input',
        ?ExternalPayloadStorageDriver $externalStorage = null,
    ): array {
        self::assertEnvelope($input, $field);
        $resolved = PayloadEnvelopeResolver::resolve($input, $field, $externalStorage);
        self::assertResolvedCodec($resolved['codec'] ?? null, $field);

        return $resolved;
    }

    public static function resolveToArray(
        mixed $input,
        string $field = 'input',
        ?ExternalPayloadStorageDriver $externalStorage = null,
    ): array {
        self::assertEnvelope($input, $field);

        return PayloadEnvelopeResolver::resolveToArray($input, $field, $externalStorage);
    }

    /** @return array{payload: mixed, codec: string|null} */
    public static function resolveCommandPayloadWithCodec(
        mixed $value,
        string $field = 'result',
        ?ExternalPayloadStorageDriver $externalStorage = null,
    ): array {
        self::assertEnvelope($value, $field);
        $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec($value, $field, $externalStorage);
        self::assertResolvedCodec($resolved['codec'] ?? null, $field);

        return $resolved;
    }

    private static function assertEnvelope(mixed $value, string $field): void
    {
        if (! is_array($value) || ! array_key_exists('codec', $value)) {
            return;
        }

        self::validateCodec($value['codec'], $field.'.codec');

        $reference = $value['external_storage'] ?? null;
        if (is_array($reference) && array_key_exists('codec', $reference)) {
            self::validateCodec($reference['codec'], $field.'.external_storage.codec');
        }
    }

    private static function assertResolvedCodec(mixed $codec, string $field): void
    {
        if ($codec !== null) {
            self::validateCodec($codec, $field.'.codec');
        }
    }

    private static function validateCodec(mixed $codec, string $field): void
    {
        try {
            PayloadCodecContract::canonicalize($codec);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => [$exception->getMessage()]]);
        }
    }
}
