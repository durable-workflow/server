<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\NamespaceLifecycleCleanup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NamespaceController
{
    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespaces = WorkflowNamespace::all();

        return ControlPlaneProtocol::json([
            'namespaces' => $namespaces->map(fn (WorkflowNamespace $ns) => $this->serializeNamespace($ns)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'retention_mode' => ['sometimes', 'string', Rule::in(WorkflowNamespace::retentionModes())],
            'retention_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $validated['name'] = strtolower($validated['name']);
        $retention = $this->retentionForCreate($validated);

        if (WorkflowNamespace::where('name', $validated['name'])->exists()) {
            return ControlPlaneProtocol::json([
                'message' => 'Namespace already exists.',
                'reason' => 'namespace_already_exists',
                'namespace' => $validated['name'],
            ], 409);
        }

        $namespace = WorkflowNamespace::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'retention_mode' => $retention['retention_mode'],
            'retention_days' => $retention['retention_days'],
            'status' => 'active',
        ]);

        return ControlPlaneProtocol::json($this->serializeNamespace($namespace), 201);
    }

    public function show(Request $request, string $namespace): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $ns = WorkflowNamespace::where('name', strtolower($namespace))->first();

        if (! $ns) {
            return $this->namespaceNotFound($namespace);
        }

        return ControlPlaneProtocol::json($this->serializeNamespace($ns));
    }

    public function update(Request $request, string $namespace): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $ns = WorkflowNamespace::where('name', strtolower($namespace))->first();

        if (! $ns) {
            return $this->namespaceNotFound($namespace);
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'retention_mode' => ['sometimes', 'string', Rule::in(WorkflowNamespace::retentionModes())],
            'retention_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $retention = $this->retentionForUpdate($ns, $validated);
        $updates = [];

        if (($validated['description'] ?? null) !== null) {
            $updates['description'] = $validated['description'];
        }

        $updates['retention_mode'] = $retention['retention_mode'];
        $updates['retention_days'] = $retention['retention_days'];

        $ns->update($updates);

        return ControlPlaneProtocol::json($this->serializeNamespace($ns));
    }

    public function updateExternalStorage(Request $request, string $namespace): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $ns = WorkflowNamespace::where('name', strtolower($namespace))->first();

        if (! $ns) {
            return $this->namespaceNotFound($namespace);
        }

        $validated = $request->validate([
            'driver' => ['required', 'string', Rule::in(['local', 's3', 'gcs', 'azure', 'custom'])],
            'enabled' => ['sometimes', 'boolean'],
            'threshold_bytes' => ['nullable', 'integer', 'min:1'],
            'config' => ['nullable', 'array'],
            'config.uri' => ['nullable', 'string', 'max:2048'],
            'config.bucket' => ['nullable', 'string', 'max:255'],
            'config.container' => ['nullable', 'string', 'max:255'],
            'config.name' => ['nullable', 'string', 'max:255'],
            'config.disk' => ['nullable', 'string', 'max:255'],
            'config.prefix' => ['nullable', 'string', 'max:1024'],
            'config.scheme' => ['nullable', 'string', 'max:64'],
            'config.region' => ['nullable', 'string', 'max:128'],
            'config.endpoint' => ['nullable', 'string', 'max:2048'],
            'config.auth_profile' => ['nullable', 'string', 'max:255'],
        ]);

        $policy = [
            'driver' => $validated['driver'],
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ];

        if (array_key_exists('threshold_bytes', $validated) && $validated['threshold_bytes'] !== null) {
            $policy['threshold_bytes'] = (int) $validated['threshold_bytes'];
        }

        $config = array_filter(
            $validated['config'] ?? [],
            static fn ($value): bool => $value !== null && $value !== '',
        );

        if ($config !== []) {
            $policy['config'] = $config;
        }

        $ns->update(['external_payload_storage' => $policy]);

        return ControlPlaneProtocol::json($this->serializeNamespace($ns->refresh()));
    }

    public function destroy(Request $request, string $namespace, NamespaceLifecycleCleanup $cleanup): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $normalized = strtolower($namespace);

        try {
            $payload = DB::transaction(function () use ($cleanup, $normalized): ?array {
                $ns = WorkflowNamespace::where('name', $normalized)->lockForUpdate()->first();

                if (! $ns) {
                    return null;
                }

                $deleted = $cleanup->cleanup($normalized);
                $ns->delete();

                return [
                    'name' => $normalized,
                    'status' => 'deleted',
                    'deleted' => $deleted,
                ];
            });
        } catch (ExternalPayloadStorageUnavailable $e) {
            return ControlPlaneProtocol::json([
                'message' => 'Namespace cleanup requires deleting external payloads, but external payload storage is unavailable.',
                'reason' => 'external_payload_storage_driver_unavailable',
                'namespace' => $normalized,
                'error' => $e->getMessage(),
            ], 503);
        }

        if ($payload === null) {
            return $this->namespaceNotFound($namespace);
        }

        return ControlPlaneProtocol::json($payload);
    }

    private function namespaceNotFound(string $namespace): JsonResponse
    {
        $normalized = strtolower($namespace);

        return ControlPlaneProtocol::json([
            'message' => sprintf('Namespace [%s] not found.', $normalized),
            'reason' => 'namespace_not_found',
            'namespace' => $normalized,
        ], 404);
    }

    private function serializeNamespace(WorkflowNamespace $ns): array
    {
        return [
            'name' => $ns->name,
            'description' => $ns->description,
            'retention_mode' => $ns->retention_mode,
            'retention_days' => $ns->retention_days,
            'status' => $ns->status,
            'external_payload_storage' => $ns->external_payload_storage,
            'created_at' => $ns->created_at?->toIso8601String(),
            'updated_at' => $ns->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{retention_mode: string, retention_days: int|null}
     */
    private function retentionForCreate(array $validated): array
    {
        $mode = $validated['retention_mode'] ?? WorkflowNamespace::RETENTION_MODE_BOUNDED;
        $hasDays = array_key_exists('retention_days', $validated);
        $days = $validated['retention_days'] ?? null;

        $this->validateRetentionCombination($mode, $hasDays, $days);

        return [
            'retention_mode' => $mode,
            'retention_days' => $mode === WorkflowNamespace::RETENTION_MODE_FOREVER
                ? null
                : ($days ?? (int) config('server.history.retention_days')),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{retention_mode: string, retention_days: int|null}
     */
    private function retentionForUpdate(WorkflowNamespace $namespace, array $validated): array
    {
        $mode = $validated['retention_mode'] ?? $namespace->retention_mode;
        $hasDays = array_key_exists('retention_days', $validated);
        $days = $hasDays ? $validated['retention_days'] : $namespace->retention_days;

        $this->validateRetentionCombination($mode, $hasDays, $days);

        if ($mode === WorkflowNamespace::RETENTION_MODE_BOUNDED && $days === null) {
            throw ValidationException::withMessages([
                'retention_days' => ['retention_days is required when changing a forever namespace to bounded retention.'],
            ]);
        }

        return [
            'retention_mode' => $mode,
            'retention_days' => $mode === WorkflowNamespace::RETENTION_MODE_FOREVER ? null : (int) $days,
        ];
    }

    private function validateRetentionCombination(string $mode, bool $hasDays, mixed $days): void
    {
        if ($mode === WorkflowNamespace::RETENTION_MODE_FOREVER && $hasDays && $days !== null) {
            throw ValidationException::withMessages([
                'retention_days' => ['retention_days must be omitted or null when retention_mode is forever.'],
            ]);
        }

        if ($mode === WorkflowNamespace::RETENTION_MODE_BOUNDED && $hasDays && $days === null) {
            throw ValidationException::withMessages([
                'retention_days' => ['retention_days cannot be null when retention_mode is bounded.'],
            ]);
        }
    }
}
