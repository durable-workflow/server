<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class SourceRelease
{
    private const SCHEMA = 'durable-workflow.server.source-release/v1';

    public static function version(): string
    {
        static $version;

        if (is_string($version)) {
            return $version;
        }

        $path = base_path('resources/release/source-release.json');
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Source release manifest {$path} is not readable.");
        }

        try {
            $release = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException("Source release manifest {$path} is invalid.", 0, $error);
        }

        $candidate = $release['server']['version'] ?? null;
        if (($release['schema'] ?? null) !== self::SCHEMA
            || ! is_string($candidate)
            || preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|rc)\.(0|[1-9]\d*))?$/', $candidate) !== 1) {
            throw new RuntimeException("Source release manifest {$path} does not declare a valid Server release.");
        }

        return $version = $candidate;
    }
}
