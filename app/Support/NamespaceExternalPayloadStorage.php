<?php

namespace App\Support;

use App\Models\WorkflowNamespace;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;

class NamespaceExternalPayloadStorage implements ExternalPayloadStoragePolicy
{
    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
    {
        $namespace = $namespace ?: (string) config('server.default_namespace', 'default');
        $driver = $this->untrackedDriverFor($namespace);

        return $driver === null
            ? null
            : new RuntimeTrackedExternalPayloadStorage(strtolower($namespace), $driver);
    }

    public function untrackedDriverFor(?string $namespace): ?RuntimeExternalPayloadStorageDriver
    {
        $namespace = $namespace ?: (string) config('server.default_namespace', 'default');
        $policy = $this->policyFor($namespace);

        if ($policy === [] || ($policy['enabled'] ?? true) === false) {
            return null;
        }

        $driver = $policy['driver'] ?? null;

        if ($driver === 'local') {
            return $this->guard(new RuntimeLocalExternalPayloadStorage($this->localRoot($policy, $namespace)));
        }

        if (in_array($driver, ['s3', 'gcs', 'azure', 'custom'], true)) {
            $disk = $policy['config']['disk'] ?? null;
            $bucket = $policy['config']['bucket']
                ?? $policy['config']['container']
                ?? $policy['config']['name']
                ?? null;
            $scheme = $driver === 'custom'
                ? ($policy['config']['scheme'] ?? null)
                : $driver;

            if (! FilesystemDiskAvailability::configured($disk)
                || ! is_string($bucket) || $bucket === ''
                || ! is_string($scheme) || $scheme === ''
            ) {
                return null;
            }

            return $this->guard(new FilesystemExternalPayloadStorage(
                disk: $disk,
                scheme: $scheme,
                bucket: $bucket,
                prefix: $this->prefix($policy),
            ));
        }

        return null;
    }

    public function thresholdBytesFor(?string $namespace): ?int
    {
        $namespace = $namespace ?: (string) config('server.default_namespace', 'default');
        $policy = $this->policyFor($namespace);

        if ($policy === [] || ($policy['enabled'] ?? true) === false) {
            return null;
        }

        $threshold = $policy['threshold_bytes'] ?? null;
        if (is_int($threshold) && $threshold > 0) {
            return $threshold;
        }

        $default = (int) config('server.limits.max_payload_bytes', 2 * 1024 * 1024);

        return $default > 0 ? $default : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function policyFor(string $namespace): array
    {
        $ns = WorkflowNamespace::query()->where('name', $namespace)->first();
        $policy = $ns?->external_payload_storage;

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function localRoot(array $policy, string $namespace): string
    {
        $uri = $policy['config']['uri'] ?? null;

        if (is_string($uri) && str_starts_with($uri, 'file://')) {
            return rtrim(substr($uri, 7), '/');
        }

        return storage_path('app/external-payloads/'.$namespace);
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    private function prefix(array $policy): string
    {
        $prefix = $policy['config']['prefix'] ?? '';

        if (! is_string($prefix) || $prefix === '') {
            return '';
        }

        return trim($prefix, '/').'/';
    }

    private function guard(RuntimeExternalPayloadStorageDriver $driver): RuntimeExternalPayloadStorageDriver
    {
        return new GuardedExternalPayloadStorage($driver);
    }
}
