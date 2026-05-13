<?php

namespace App\Support;

class FilesystemDiskAvailability
{
    public static function configured(mixed $disk): bool
    {
        if (! is_string($disk) || $disk === '') {
            return false;
        }

        $configuredDisks = config('filesystems.disks');

        return is_array($configuredDisks)
            && array_key_exists($disk, $configuredDisks)
            && is_array($configuredDisks[$disk]);
    }
}
