<?php

namespace App\Support;

use App\Models\WorkflowNamespace;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Support\LocalFilesystemExternalPayloadStorage;

class NamespaceExternalPayloadStorage
{
    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
    {
        $namespace = $namespace ?: (string) config('server.default_namespace', 'default');
        $ns = WorkflowNamespace::query()->where('name', $namespace)->first();
        $policy = is_array($ns?->external_payload_storage) ? $ns->external_payload_storage : [];

        if ($policy === [] || ($policy['enabled'] ?? true) === false) {
            return null;
        }

        $driver = $policy['driver'] ?? null;

        if ($driver === 'local') {
            return new LocalFilesystemExternalPayloadStorage($this->localRoot($policy, $namespace));
        }

        if (in_array($driver, ['s3', 'gcs', 'azure'], true)) {
            $disk = $policy['config']['disk'] ?? null;
            $bucket = $policy['config']['bucket'] ?? null;

            if (! is_string($disk) || $disk === '' || ! is_string($bucket) || $bucket === '') {
                return null;
            }

            return new FilesystemExternalPayloadStorage(
                disk: $disk,
                scheme: $driver,
                bucket: $bucket,
                prefix: $this->prefix($policy),
            );
        }

        return null;
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
}
