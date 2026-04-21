<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final class RouteAuthorizationResource
{
    /**
     * @param  list<string>  $allowedRoles
     * @return array<string, mixed>
     */
    public function make(Request $request, array $allowedRoles): array
    {
        $routeParameters = $this->routeParameters($request->route());
        $inputIdentifiers = $this->inputIdentifiers($request);
        $identifiers = $routeParameters + $inputIdentifiers;
        $defaultNamespace = $this->namespaceValue(config('server.default_namespace'));
        $requestedNamespace = $this->requestedNamespace($request, $defaultNamespace);
        $operationFamily = $this->operationFamily($request);
        $targetNamespace = $this->targetNamespace($request, $routeParameters, $operationFamily);

        return array_filter([
            'allowed_roles' => $allowedRoles,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route_name' => $request->route()?->getName(),
            'route_uri' => $request->route()?->uri(),
            'route_parameters' => $routeParameters,
            'operation_family' => $operationFamily,
            'operation_name' => $this->operationName($request),
            'default_namespace' => $defaultNamespace,
            'requested_namespace' => $requestedNamespace,
            'namespace' => $requestedNamespace,
            'target_namespace' => $targetNamespace,
            'namespace_name' => $targetNamespace,
        ] + $this->namedIdentifiers($identifiers, $operationFamily), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function requestedNamespace(Request $request, string $defaultNamespace): string
    {
        return $this->namespaceValue($request->header(
            'X-Namespace',
            $request->query('namespace', $defaultNamespace),
        ));
    }

    private function namespaceValue(mixed $value): string
    {
        return strtolower((string) $value);
    }

    /**
     * @param  array<string, string|int|float|bool>  $routeParameters
     */
    private function targetNamespace(Request $request, array $routeParameters, ?string $operationFamily): ?string
    {
        if ($operationFamily !== 'namespace') {
            return null;
        }

        if (array_key_exists('namespace', $routeParameters)) {
            return $this->namespaceIdentifier($routeParameters['namespace']);
        }

        return $this->namespaceIdentifier($request->input('name'));
    }

    private function namespaceIdentifier(mixed $value): ?string
    {
        $identifier = $this->identifierValue($value);

        if ($identifier === null || is_bool($identifier)) {
            return null;
        }

        return strtolower((string) $identifier);
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private function routeParameters(?Route $route): array
    {
        if (! $route instanceof Route) {
            return [];
        }

        $parameters = [];

        foreach ($route->parameters() as $name => $value) {
            $normalized = $this->identifierValue($value);

            if ($normalized !== null) {
                $parameters[Str::snake((string) $name)] = $normalized;
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    private function inputIdentifiers(Request $request): array
    {
        $identifiers = [];

        foreach ([
            'activity_attempt_id',
            'build_id',
            'lease_owner',
            'poll_request_id',
            'query_task_attempt',
            'runtime',
            'task_queue',
            'worker_id',
            'workflow_id',
            'workflow_task_attempt',
            'workflow_type',
        ] as $name) {
            $value = $this->identifierValue($request->input($name));

            if ($value !== null) {
                $identifiers[$name] = $value;
            }
        }

        return $identifiers;
    }

    /**
     * @param  array<string, string|int|float|bool>  $identifiers
     * @return array<string, string|int|float|bool>
     */
    private function namedIdentifiers(array $identifiers, ?string $operationFamily): array
    {
        $fields = [];

        foreach ([
            'activity_attempt_id',
            'build_id',
            'lease_owner',
            'poll_request_id',
            'query_task_attempt',
            'query_task_id',
            'run_id',
            'runtime',
            'schedule_id',
            'signal_name',
            'task_id',
            'task_queue',
            'update_name',
            'worker_id',
            'workflow_id',
            'workflow_task_attempt',
            'workflow_type',
        ] as $name) {
            if (array_key_exists($name, $identifiers)) {
                $fields[$name] = $identifiers[$name];
            }
        }

        if (array_key_exists('name', $identifiers) && $operationFamily === 'search_attribute') {
            $fields['search_attribute_name'] = $identifiers['name'];
        }

        if (array_key_exists('query_name', $identifiers)) {
            $fields['query_name'] = $identifiers['query_name'];
        }

        return $fields;
    }

    private function identifierValue(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }

    private function operationFamily(Request $request): ?string
    {
        return $this->operationFamilyFromPath('/'.ltrim($request->path(), '/'));
    }

    private function operationFamilyFromPath(string $path): ?string
    {
        return match (explode('/', trim($path, '/'))[1] ?? null) {
            'cluster' => 'cluster',
            'namespaces' => 'namespace',
            'workflows' => 'workflow',
            'worker' => 'worker',
            'workers' => 'worker_management',
            'task-queues' => 'task_queue',
            'schedules' => 'schedule',
            'search-attributes' => 'search_attribute',
            'system' => 'system',
            default => null,
        };
    }

    private function operationName(Request $request): ?string
    {
        $controlPlaneOperation = ControlPlaneOperation::fromRequest($request);

        if ($controlPlaneOperation instanceof ControlPlaneOperation) {
            return $controlPlaneOperation->operation;
        }

        $method = $request->route()?->getActionMethod();

        if (! is_string($method) || $method === '') {
            return null;
        }

        return Str::snake($method);
    }
}
