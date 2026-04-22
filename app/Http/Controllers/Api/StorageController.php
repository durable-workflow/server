<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorageController
{
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

        if ($driver !== 'local') {
            return $this->diagnosticError(
                'storage_driver_unavailable',
                'The server can persist this storage policy, but only the local diagnostic driver can run a round-trip test in this release.',
                $namespace,
                $driver,
                ['supported_diagnostic_drivers' => ['local']],
            );
        }

        $directory = $this->localDirectory($policy, $namespace);

        return ControlPlaneProtocol::json([
            'status' => 'passed',
            'namespace' => $namespace,
            'driver' => $driver,
            'small_payload' => $this->roundTrip($directory, 'small', (int) $validated['small_payload_bytes']),
            'large_payload' => $this->roundTrip($directory, 'large', (int) $validated['large_payload_bytes']),
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

    private function localDirectory(array $policy, string $namespace): string
    {
        $uri = $policy['config']['uri'] ?? null;
        if (is_string($uri) && str_starts_with($uri, 'file://')) {
            return rtrim(substr($uri, 7), '/');
        }

        return storage_path('app/external-payloads/'.$namespace);
    }

    private function roundTrip(string $directory, string $kind, int $bytes): array
    {
        File::ensureDirectoryExists($directory);

        $path = $directory.'/storage-test-'.$kind.'-'.(string) Str::uuid().'.bin';
        $payload = str_repeat($kind === 'small' ? 's' : 'l', $bytes);
        $expectedHash = hash('sha256', $payload);

        file_put_contents($path, $payload);
        $read = file_get_contents($path);
        @unlink($path);

        if ($read !== $payload) {
            throw new \RuntimeException('External storage round trip failed integrity verification.');
        }

        return [
            'status' => 'passed',
            'bytes' => $bytes,
            'sha256' => $expectedHash,
            'reference_uri' => 'file://'.$path,
        ];
    }
}
