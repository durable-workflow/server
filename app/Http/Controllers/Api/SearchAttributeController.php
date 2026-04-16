<?php

namespace App\Http\Controllers\Api;

use App\Models\SearchAttributeDefinition;
use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchAttributeController
{
    /**
     * List registered search attribute definitions.
     *
     * Returns system attributes (always available) and any custom
     * attributes registered for the current namespace.
     */
    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $customAttributes = SearchAttributeDefinition::query()
            ->where('namespace', $namespace)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (SearchAttributeDefinition $attr) => [
                $attr->name => $attr->type,
            ])
            ->all();

        return response()->json([
            'system_attributes' => SearchAttributeDefinition::SYSTEM_ATTRIBUTES,
            'custom_attributes' => $customAttributes,
        ]);
    }

    /**
     * Register a custom search attribute.
     *
     * The name must not collide with a system attribute or an existing
     * custom attribute in the same namespace.
     */
    public function store(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'type' => ['required', 'string', 'in:'.implode(',', SearchAttributeDefinition::ALLOWED_TYPES)],
        ]);

        if (array_key_exists($validated['name'], SearchAttributeDefinition::SYSTEM_ATTRIBUTES)) {
            return response()->json([
                'message' => sprintf(
                    'The name [%s] is reserved as a system search attribute.',
                    $validated['name'],
                ),
                'reason' => 'name_reserved',
            ], 409);
        }

        $existing = SearchAttributeDefinition::query()
            ->where('namespace', $namespace)
            ->where('name', $validated['name'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => sprintf(
                    'A custom search attribute [%s] already exists in namespace [%s].',
                    $validated['name'],
                    $namespace,
                ),
                'reason' => 'attribute_already_exists',
                'name' => $existing->name,
                'type' => $existing->type,
            ], 409);
        }

        $maxAttributes = (int) config('server.limits.max_search_attributes', 100);
        $currentCount = SearchAttributeDefinition::query()
            ->where('namespace', $namespace)
            ->count();

        if ($currentCount >= $maxAttributes) {
            return response()->json([
                'message' => sprintf(
                    'Namespace [%s] has reached the maximum of %d custom search attributes.',
                    $namespace,
                    $maxAttributes,
                ),
                'reason' => 'search_attribute_limit_reached',
                'limit' => $maxAttributes,
            ], 422);
        }

        $definition = SearchAttributeDefinition::create([
            'namespace' => $namespace,
            'name' => $validated['name'],
            'type' => $validated['type'],
        ]);

        return response()->json([
            'name' => $definition->name,
            'type' => $definition->type,
            'outcome' => 'created',
        ], 201);
    }

    /**
     * Remove a custom search attribute.
     *
     * System attributes cannot be removed. Returns 404 if the attribute
     * does not exist as a custom attribute in the current namespace.
     */
    public function destroy(Request $request, string $name): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (array_key_exists($name, SearchAttributeDefinition::SYSTEM_ATTRIBUTES)) {
            return response()->json([
                'message' => sprintf(
                    'The system search attribute [%s] cannot be removed.',
                    $name,
                ),
                'reason' => 'system_attribute',
            ], 409);
        }

        $definition = SearchAttributeDefinition::query()
            ->where('namespace', $namespace)
            ->where('name', $name)
            ->first();

        if (! $definition) {
            return response()->json([
                'message' => sprintf(
                    'Custom search attribute [%s] not found in namespace [%s].',
                    $name,
                    $namespace,
                ),
                'reason' => 'attribute_not_found',
            ], 404);
        }

        $definition->delete();

        return response()->json([
            'name' => $name,
            'outcome' => 'deleted',
        ]);
    }
}
