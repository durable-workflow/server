<?php

namespace App\Support;

use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

interface RuntimeExternalPayloadStorageDriver extends ExternalPayloadStorageDriver
{
    /**
     * Deletion must be replay-safe: an already-absent object is success.
     * The runtime also accepts ExternalPayloadObjectMissing as confirmation
     * of absence. Other errors retain the durable deletion for retry.
     */
    public function delete(string $uri): void;

    /**
     * Return the stable provider URI that put() will use for these bytes.
     *
     * Runtime uploads register this location before writing so an interrupted
     * storage commit always remains discoverable by retention cleanup.
     */
    public function uriFor(string $sha256, string $codec): string;
}
