<?php

namespace App\Support;

use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

interface RuntimeExternalPayloadStorageDriver extends ExternalPayloadStorageDriver
{
    /**
     * Return the stable provider URI that put() will use for these bytes.
     *
     * Runtime uploads register this location before writing so an interrupted
     * storage commit always remains discoverable by retention cleanup.
     */
    public function uriFor(string $sha256, string $codec): string;
}
