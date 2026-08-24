<?php

namespace App\Support;

use Illuminate\Http\Request;

final class RuntimeExternalPayloadUploadBody
{
    private const ATTRIBUTE = 'runtime_external_payload.upload_body';

    private const CHUNK_BYTES = 8192;

    public static function read(Request $request, int $maxBytes): string
    {
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($cached)) {
            return $cached;
        }

        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            throw self::unavailable('Runtime external payload upload body is not readable.');
        }

        $maxBytes = max(1, $maxBytes);
        $readLimit = $maxBytes === PHP_INT_MAX ? PHP_INT_MAX : $maxBytes + 1;
        stream_set_timeout(
            $stream,
            max(1, (int) config('server.external_payload_transport.request_timeout_seconds', 30)),
        );

        $data = '';
        while (! feof($stream) && strlen($data) < $readLimit) {
            $chunk = fread($stream, min(self::CHUNK_BYTES, $readLimit - strlen($data)));

            if ($chunk === false) {
                throw self::unavailable('Runtime external payload upload body could not be read.');
            }

            if ($chunk === '') {
                $metadata = stream_get_meta_data($stream);
                if (($metadata['timed_out'] ?? false) === true) {
                    throw self::unavailable('Runtime external payload upload body timed out.');
                }

                if (! feof($stream)) {
                    throw self::unavailable('Runtime external payload upload body stopped before reaching EOF.');
                }

                break;
            }

            $data .= $chunk;
        }

        $request->attributes->set(self::ATTRIBUTE, $data);

        return $data;
    }

    private static function unavailable(string $message): RuntimeExternalPayloadException
    {
        return new RuntimeExternalPayloadException(
            'external_payload_unavailable',
            503,
            true,
            $message,
        );
    }
}
