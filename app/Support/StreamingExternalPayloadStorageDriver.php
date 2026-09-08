<?php

namespace App\Support;

interface StreamingExternalPayloadStorageDriver extends RuntimeExternalPayloadStorageDriver
{
    /** @param resource $stream Rewound, verified bytes; ownership remains with the caller. */
    public function putStream($stream, string $sha256, string $codec): string;

    /** @return resource The caller must close this stream. */
    public function readStream(string $uri);
}
