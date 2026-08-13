<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class PayloadCodecContract
{
    public const CODEC = 'avro';

    public static function canonicalize(mixed $codec): string
    {
        if ($codec === null || $codec === '') {
            return self::CODEC;
        }

        if ($codec === self::CODEC) {
            return self::CODEC;
        }

        $rendered = is_string($codec) ? $codec : get_debug_type($codec);

        throw new InvalidArgumentException(sprintf(
            'unsupported_payload_codec: workflow payload codec "%s" is not supported by Durable Workflow 2.0; use codec="avro" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec.',
            $rendered,
        ));
    }

    /** @return list<string> */
    public static function universal(): array
    {
        return [self::CODEC];
    }
}
