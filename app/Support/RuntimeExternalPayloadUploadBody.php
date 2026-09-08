<?php

namespace App\Support;

use Illuminate\Http\Request;

final class RuntimeExternalPayloadUploadBody
{
    private const ATTRIBUTE = 'runtime_external_payload.upload_body';

    public static function read(Request $request, int $maxBytes): ExternalPayloadStream
    {
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if ($cached instanceof ExternalPayloadStream) {
            return $cached;
        }

        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            throw self::unavailable('Runtime external payload upload body is not readable.');
        }

        try {
            $data = ExternalPayloadStream::capture($stream, $maxBytes);
        } catch (ExternalPayloadObjectOversized $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw self::unavailable('Runtime external payload upload body could not be read.');
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
