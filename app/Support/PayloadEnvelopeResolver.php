<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;

/**
 * Resolves `input` request fields into a concrete `(codec, blob)` envelope.
 *
 * The worker protocol carries every payload as `{codec, blob}`. Clients may
 * send `input` in two shapes on the HTTP API:
 *
 *   1. A plain JSON array of arguments  →  codec "json", blob = JSON bytes
 *   2. An explicit envelope object `{codec: "<name>", blob: "<opaque>"}`
 *      →  codec = the declared name, blob = the opaque string as-is
 *
 * Shape 2 lets PHP clients that already have SerializableClosure-encoded
 * payloads (or any other codec) preserve the exact bytes they produced.
 *
 * @see docs/configuration/worker-protocol.md
 */
final class PayloadEnvelopeResolver
{
    /**
     * @param  mixed  $input    the `input` field from a validated request (array or null)
     * @return array{codec: string|null, blob: string|null}
     *         codec/blob are null when the client sent no input — callers
     *         should fall through to the configured default codec.
     */
    public static function resolve($input, string $field = 'input'): array
    {
        if ($input === null || $input === []) {
            // No input to resolve: let the server fall back to the configured
            // default codec for this run. This preserves legacy behavior for
            // clients that don't send an input field, and ensures the run's
            // `arguments` column matches what the existing PHP codec produced
            // for an empty arg list (tests assert against this).
            return ['codec' => null, 'blob' => null];
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages([
                $field => [sprintf('The %s field must be an array or an envelope object.', $field)],
            ]);
        }

        if (self::looksLikeEnvelope($input)) {
            return self::resolveExplicitEnvelope($input, $field);
        }

        // Plain array of positional arguments — treat as JSON payload.
        $values = array_values($input);

        return [
            'codec' => 'json',
            'blob' => Serializer::serializeWithCodec('json', $values),
        ];
    }

    /**
     * Detect the `{codec, blob}` envelope shape.
     *
     * The array must be associative with keys exactly {codec, blob} (order-independent).
     */
    private static function looksLikeEnvelope(array $input): bool
    {
        if ($input === []) {
            return false;
        }

        if (! array_key_exists('codec', $input) || ! array_key_exists('blob', $input)) {
            return false;
        }

        $extra = array_diff(array_keys($input), ['codec', 'blob']);

        return $extra === [];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private static function resolveExplicitEnvelope(array $input, string $field): array
    {
        $codec = $input['codec'] ?? null;
        $blob = $input['blob'] ?? null;

        if (! is_string($codec) || $codec === '') {
            throw ValidationException::withMessages([
                $field . '.codec' => ['The payload envelope codec must be a non-empty string.'],
            ]);
        }

        if (! is_string($blob)) {
            throw ValidationException::withMessages([
                $field . '.blob' => ['The payload envelope blob must be a string.'],
            ]);
        }

        try {
            $canonical = CodecRegistry::canonicalize($codec);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                $field . '.codec' => [sprintf(
                    'Unknown payload codec "%s". Known codecs: %s.',
                    $codec,
                    implode(', ', CodecRegistry::names()),
                )],
            ]);
        }

        return ['codec' => $canonical, 'blob' => $blob];
    }
}
