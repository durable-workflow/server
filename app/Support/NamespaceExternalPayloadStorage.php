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
            $filesystem = $this->filesystemPolicy($policy, $driver);

            if ($filesystem['error'] !== null) {
                return null;
            }

            return $this->guard(new FilesystemExternalPayloadStorage(
                disk: $filesystem['disk'],
                scheme: $filesystem['scheme'],
                bucket: $filesystem['bucket'],
                prefix: $this->prefix($policy),
            ));
        }

        return null;
    }

    public function configurationErrorFor(?string $namespace): ?string
    {
        $namespace = $namespace ?: (string) config('server.default_namespace', 'default');

        return $this->configurationErrorForPolicy($this->policyFor($namespace));
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public function configurationErrorForPolicy(array $policy): ?string
    {
        if ($policy === [] || ($policy['enabled'] ?? true) === false) {
            return null;
        }

        $driver = $policy['driver'] ?? null;
        if ($driver === 'local') {
            return null;
        }

        if (! is_string($driver) || ! in_array($driver, ['s3', 'gcs', 'azure', 'custom'], true)) {
            return 'external_payload_storage_driver_unsupported';
        }

        return $this->filesystemPolicy($policy, $driver)['error'];
    }

    /**
     * @param  array<string, mixed>  $policy
     */
    public function policyResolvable(array $policy): bool
    {
        return $policy !== []
            && ($policy['enabled'] ?? true) !== false
            && $this->configurationErrorForPolicy($policy) === null;
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

    /**
     * @param  array<string, mixed>  $policy
     * @return array{disk: string, bucket: string, scheme: string, error: ?string}
     */
    private function filesystemPolicy(array $policy, string $driver): array
    {
        $config = is_array($policy['config'] ?? null) ? $policy['config'] : [];
        $disk = $config['disk'] ?? null;

        if ((! is_string($disk) || $disk === '') && $driver === 's3') {
            $disk = (string) config('server.external_payload_transport.s3_disk', 'external-payload-s3');
        }

        $diskError = FilesystemDiskAvailability::configurationError($disk);
        $bucket = $config['bucket']
            ?? $config['container']
            ?? $config['name']
            ?? ($driver === 's3' ? FilesystemDiskAvailability::bucket($disk) : null);
        $scheme = $driver === 'custom' ? ($config['scheme'] ?? null) : $driver;

        $error = $diskError;
        if ($error === null && (! is_string($bucket) || $bucket === '')) {
            $error = 'external_payload_storage_bucket_missing';
        }
        if ($error === null && (! is_string($scheme) || $scheme === '')) {
            $error = 'external_payload_storage_scheme_missing';
        }
        if ($error === null
            && $driver === 's3'
            && FilesystemDiskAvailability::driver($disk) === 's3'
            && ! hash_equals((string) FilesystemDiskAvailability::bucket($disk), (string) $bucket)
        ) {
            $error = 's3_bucket_mismatch';
        }

        return [
            'disk' => is_string($disk) ? $disk : '',
            'bucket' => is_string($bucket) ? $bucket : '',
            'scheme' => is_string($scheme) ? $scheme : '',
            'error' => $error,
        ];
    }

    private function guard(RuntimeExternalPayloadStorageDriver $driver): RuntimeExternalPayloadStorageDriver
    {
        return new GuardedExternalPayloadStorage($driver);
    }
}
