<?php

namespace App\Support;

use RuntimeException;

final class BoundedExternalPayloadReader
{
    private const CHUNK_BYTES = 8192;

    /** @param resource $stream */
    public static function read($stream, int $maxBytes): string
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('External payload storage did not return a readable stream.');
        }

        $maxBytes = max(1, $maxBytes);
        $readLimit = $maxBytes === PHP_INT_MAX ? PHP_INT_MAX : $maxBytes + 1;
        $timeoutSeconds = max(
            1,
            (int) config('server.external_payload_transport.request_timeout_seconds', 30),
        );
        stream_set_timeout($stream, $timeoutSeconds);

        $data = '';
        while (! feof($stream) && strlen($data) < $readLimit) {
            $chunk = fread($stream, min(self::CHUNK_BYTES, $readLimit - strlen($data)));

            if ($chunk === false) {
                throw new RuntimeException('External payload storage stream could not be read.');
            }

            if ($chunk === '') {
                $metadata = stream_get_meta_data($stream);
                if (($metadata['timed_out'] ?? false) === true) {
                    throw new RuntimeException('External payload storage stream timed out.');
                }

                if (! feof($stream)) {
                    throw new RuntimeException('External payload storage stream stopped before reaching EOF.');
                }

                break;
            }

            $data .= $chunk;
        }

        if (strlen($data) > $maxBytes) {
            throw new ExternalPayloadObjectOversized(
                'External payload backing object exceeds the runtime transport size limit.',
            );
        }

        return $data;
    }
}
