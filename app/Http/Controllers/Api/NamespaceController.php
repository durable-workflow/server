<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowNamespace;
use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NamespaceController
{
    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespaces = WorkflowNamespace::all();

        return ControlPlaneProtocol::json([
            'namespaces' => $namespaces->map(fn (WorkflowNamespace $ns) => [
                'name' => $ns->name,
                'description' => $ns->description,
                'retention_days' => $ns->retention_days,
                'status' => $ns->status,
                'created_at' => $ns->created_at?->toIso8601String(),
                'updated_at' => $ns->updated_at?->toIso8601String(),
            ]),
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
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $validated['name'] = strtolower($validated['name']);

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
            'retention_days' => $validated['retention_days'] ?? config('server.history.retention_days'),
            'status' => 'active',
        ]);

        return ControlPlaneProtocol::json([
            'name' => $namespace->name,
            'description' => $namespace->description,
            'retention_days' => $namespace->retention_days,
            'status' => $namespace->status,
            'created_at' => $namespace->created_at?->toIso8601String(),
        ], 201);
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

        return ControlPlaneProtocol::json([
            'name' => $ns->name,
            'description' => $ns->description,
            'retention_days' => $ns->retention_days,
            'status' => $ns->status,
            'created_at' => $ns->created_at?->toIso8601String(),
            'updated_at' => $ns->updated_at?->toIso8601String(),
        ]);
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
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $ns->update(array_filter($validated, fn ($v) => $v !== null));

        return ControlPlaneProtocol::json([
            'name' => $ns->name,
            'description' => $ns->description,
            'retention_days' => $ns->retention_days,
            'status' => $ns->status,
            'updated_at' => $ns->updated_at?->toIso8601String(),
        ]);
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
}
