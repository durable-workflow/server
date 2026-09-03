<?php

namespace App\Support;

use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class FilesystemDiskAvailability
{
    public static function configured(mixed $disk): bool
    {
        return self::configurationError($disk) === null;
    }

    public static function configurationError(mixed $disk): ?string
    {
        if (! is_string($disk) || $disk === '') {
            return 'filesystem_disk_name_missing';
        }

        $config = self::configuration($disk);
        if ($config === null) {
            return 'filesystem_disk_not_configured';
        }

        if (($config['driver'] ?? null) !== 's3') {
            return null;
        }

        if (! class_exists(AwsS3V3Adapter::class)) {
            return 's3_adapter_unavailable';
        }

        if (self::stringOrNull($config['bucket'] ?? null) === null) {
            return 's3_bucket_missing';
        }

        if (self::stringOrNull($config['region'] ?? null) === null) {
            return 's3_region_missing';
        }

        $key = self::stringOrNull($config['key'] ?? null);
        $secret = self::stringOrNull($config['secret'] ?? null);
        $token = self::stringOrNull($config['token'] ?? null);

        if (($key === null) !== ($secret === null) || ($token !== null && ($key === null || $secret === null))) {
            return 's3_credentials_incomplete';
        }

        return null;
    }

    public static function bucket(mixed $disk): ?string
    {
        if (! is_string($disk) || $disk === '') {
            return null;
        }

        return self::stringOrNull(self::configuration($disk)['bucket'] ?? null);
    }

    public static function driver(mixed $disk): ?string
    {
        if (! is_string($disk) || $disk === '') {
            return null;
        }

        return self::stringOrNull(self::configuration($disk)['driver'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function configuration(string $disk): ?array
    {
        $configuredDisks = config('filesystems.disks');

        if (! is_array($configuredDisks)
            || ! array_key_exists($disk, $configuredDisks)
            || ! is_array($configuredDisks[$disk])
        ) {
            return null;
        }

        return $configuredDisks[$disk];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
