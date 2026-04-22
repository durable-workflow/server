<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\NamespaceExternalPayloadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

class StorageController
{
    public function __construct(
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
    ) {}

    public function test(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'driver' => ['nullable', 'string', Rule::in(['local', 's3', 'gcs', 'azure'])],
            'small_payload_bytes' => ['required', 'integer', 'min:1', 'max:1048576'],
            'large_payload_bytes' => ['required', 'integer', 'min:1', 'max:16777216'],
        ]);

        $namespace = (string) $request->attributes->get('namespace', config('server.default_namespace'));
        $ns = WorkflowNamespace::where('name', $namespace)->firstOrFail();
        $policy = is_array($ns->external_payload_storage) ? $ns->external_payload_storage : [];
        $driver = $validated['driver'] ?? ($policy['driver'] ?? null);

        if (! is_string($driver) || $driver === '') {
            return $this->diagnosticError('external_storage_not_configured', 'External payload storage is not configured for this namespace.', $namespace);
        }

        if (($policy['enabled'] ?? true) === false) {
            return $this->diagnosticError('external_storage_disabled', 'External payload storage is disabled for this namespace.', $namespace, $driver);
        }

        if ($driver !== ($policy['driver'] ?? null)) {
            return $this->diagnosticError(
                'storage_driver_unavailable',
                'The requested external payload storage driver is not configured for this namespace.',
                $namespace,
                $driver,
                ['supported_diagnostic_drivers' => ['local']],
            );
        }

        $storage = $this->externalPayloadStorage->driverFor($namespace);

        if ($storage === null) {
            return $this->diagnosticError(
                'storage_driver_unavailable',
                'The server can persist this storage policy, but the configured storage driver is not available in this runtime.',
                $namespace,
                $driver,
                ['supported_diagnostic_drivers' => ['local', 's3', 'gcs', 'azure']],
            );
        }

        return ControlPlaneProtocol::json([
            'status' => 'passed',
            'namespace' => $namespace,
            'driver' => $driver,
            'small_payload' => $this->roundTrip($storage, 'small', (int) $validated['small_payload_bytes']),
            'large_payload' => $this->roundTrip($storage, 'large', (int) $validated['large_payload_bytes']),
        ]);
    }

    private function diagnosticError(
        string $reason,
        string $message,
        string $namespace,
        ?string $driver = null,
        array $extra = [],
    ): JsonResponse {
        return ControlPlaneProtocol::json([
            'status' => 'failed',
            'reason' => $reason,
            'message' => $message,
            'namespace' => $namespace,
            'driver' => $driver,
        ] + $extra, 422);
    }

    private function roundTrip(ExternalPayloadStorageDriver $storage, string $kind, int $bytes): array
    {
        $payload = str_repeat($kind === 'small' ? 's' : 'l', $bytes);
        $expectedHash = hash('sha256', $payload);
        $uri = $storage->put($payload, $expectedHash, 'storage-test-'.$kind);
        $read = $storage->get($uri);
        $storage->delete($uri);

        if ($read !== $payload) {
            throw new \RuntimeException('External storage round trip failed integrity verification.');
        }

        return [
            'status' => 'passed',
            'bytes' => $bytes,
            'sha256' => $expectedHash,
            'reference_uri' => $uri,
        ];
    }
}
