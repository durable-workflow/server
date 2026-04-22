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

        if ($driver !== 'local') {
            return null;
        }

        return new LocalFilesystemExternalPayloadStorage($this->localRoot($policy, $namespace));
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
}
