<?php

namespace App\Support;

use JsonException;

final class StoragePressure
{
    public const SCHEMA = 'durable-workflow.storage-pressure.v1';

    /** @return array{enabled: bool, state: string, reason: ?string, observed_at: ?int} */
    public function snapshot(): array
    {
        $path = config('server.storage_admission.file');
        $source = config('server.storage_admission.source');
        $maxAge = config('server.storage_admission.max_age_seconds');

        if (($path === null || $path === '') && ($source === null || $source === '')) {
            return ['enabled' => false, 'state' => 'normal', 'reason' => null, 'observed_at' => null];
        }

        $unavailable = ['enabled' => true, 'state' => 'fenced', 'reason' => 'storage_admission_unavailable', 'observed_at' => null];
        if (! is_string($path) || ! str_starts_with($path, '/') || ! is_string($source) || $source === ''
            || ! is_int($maxAge) || $maxAge < 1 || $maxAge > 300) {
            return $unavailable;
        }

        // Read a bounded regular file, not a FIFO/device or a cached observation.
        // The operator must own the parent directory and mount it read-only.
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0022) !== 0) {
            return $unavailable;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $unavailable;
        }

        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']
                || $opened['size'] > 4096 || ($opened['mode'] & 0022) !== 0) {
                return $unavailable;
            }

            $bytes = stream_get_contents($handle, 4097);
            if ($bytes === false || strlen($bytes) > 4096) {
                return $unavailable;
            }

            $value = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $unavailable;
        } finally {
            fclose($handle);
        }

        if (! is_array($value) || ($value['schema'] ?? null) !== self::SCHEMA
            || ($value['source'] ?? null) !== $source
            || ! in_array($value['state'] ?? null, ['normal', 'draining', 'fenced'], true)
            || ! is_int($value['observed_at'] ?? null)) {
            return $unavailable;
        }

        $age = now()->getTimestamp() - $value['observed_at'];
        if ($age < 0 || $age > $maxAge) {
            return $unavailable;
        }

        return [
            'enabled' => true,
            'state' => $value['state'],
            'reason' => $value['state'] === 'normal' ? null : 'storage_pressure',
            'observed_at' => $value['observed_at'],
        ];
    }

    public function acceptsNewWork(): bool
    {
        return $this->snapshot()['state'] === 'normal';
    }

    public function requireNewWork(): void
    {
        $snapshot = $this->snapshot();
        if ($snapshot['state'] !== 'normal') {
            throw new StorageAdmissionPaused($snapshot);
        }
    }
}
