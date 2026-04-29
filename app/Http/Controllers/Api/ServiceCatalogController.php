<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;

class ServiceCatalogController
{
    private const NAME_PATTERN = '/^[a-zA-Z0-9._-]+$/';

    private const OPERATION_MODES = ['sync', 'async'];

    private const HANDLER_BINDING_KINDS = [
        'start_workflow',
        'signal_workflow',
        'update_workflow',
        'query_workflow',
        'activity_execution',
        'invocable_http',
    ];

    public function endpointIndex(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $this->namespace($request);

        $endpoints = WorkflowServiceEndpoint::query()
            ->where('namespace', $namespace)
            ->orderBy('endpoint_name')
            ->get()
            ->map(fn (WorkflowServiceEndpoint $endpoint) => $this->serializeEndpoint($endpoint))
            ->values();

        return ControlPlaneProtocol::json([
            'service_endpoints' => $endpoints,
        ]);
    }

    public function endpointStore(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $this->namespace($request);

        $validated = $request->validate([
            'endpoint_name' => $this->catalogNameRules(191),
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $endpointName = $this->normalizeCatalogName($validated['endpoint_name']);

        $existing = WorkflowServiceEndpoint::query()
            ->where('namespace', $namespace)
            ->where('endpoint_name', $endpointName)
            ->first();

        if ($existing) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'A service endpoint [%s] already exists in namespace [%s].',
                    $endpointName,
                    $namespace,
                ),
                'reason' => 'endpoint_already_exists',
                'namespace' => $namespace,
                'endpoint_name' => $endpointName,
                'endpoint_id' => $existing->id,
            ], 409);
        }

        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => $namespace,
            'endpoint_name' => $endpointName,
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return ControlPlaneProtocol::json($this->serializeEndpoint($endpoint), 201);
    }

    public function endpointShow(Request $request, string $endpointName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        return ControlPlaneProtocol::json($this->serializeEndpoint($endpoint));
    }

    public function endpointUpdate(Request $request, string $endpointName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $updates = [];

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('metadata', $validated)) {
            $updates['metadata'] = $validated['metadata'];
        }

        if ($updates !== []) {
            $endpoint->update($updates);
        }

        return ControlPlaneProtocol::json($this->serializeEndpoint($endpoint->refresh()));
    }

    public function endpointDestroy(Request $request, string $endpointName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        if ($endpoint->services()->exists()) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Service endpoint [%s] in namespace [%s] still has registered services.',
                    $endpoint->endpoint_name,
                    $endpoint->namespace,
                ),
                'reason' => 'endpoint_has_services',
                'namespace' => $endpoint->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
            ], 409);
        }

        if ($endpoint->operations()->exists()) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Service endpoint [%s] in namespace [%s] still has registered operations.',
                    $endpoint->endpoint_name,
                    $endpoint->namespace,
                ),
                'reason' => 'endpoint_has_operations',
                'namespace' => $endpoint->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
            ], 409);
        }

        if ($endpoint->serviceCalls()->exists()) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Service endpoint [%s] in namespace [%s] still has recorded service calls.',
                    $endpoint->endpoint_name,
                    $endpoint->namespace,
                ),
                'reason' => 'endpoint_has_service_calls',
                'namespace' => $endpoint->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
            ], 409);
        }

        $normalized = $endpoint->endpoint_name;
        $endpoint->delete();

        return ControlPlaneProtocol::json([
            'namespace' => $this->namespace($request),
            'endpoint_name' => $normalized,
            'outcome' => 'deleted',
        ]);
    }

    public function serviceIndex(Request $request, string $endpointName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $services = $endpoint->services()
            ->orderBy('service_name')
            ->get()
            ->map(fn (WorkflowService $service) => $this->serializeService($service, $endpoint))
            ->values();

        return ControlPlaneProtocol::json([
            'services' => $services,
        ]);
    }

    public function serviceStore(Request $request, string $endpointName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $validated = $request->validate([
            'service_name' => $this->catalogNameRules(191),
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $serviceName = $this->normalizeCatalogName($validated['service_name']);

        $existing = WorkflowService::query()
            ->where('namespace', $endpoint->namespace)
            ->where('workflow_service_endpoint_id', $endpoint->id)
            ->where('service_name', $serviceName)
            ->first();

        if ($existing) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'A service [%s] already exists under endpoint [%s] in namespace [%s].',
                    $serviceName,
                    $endpoint->endpoint_name,
                    $endpoint->namespace,
                ),
                'reason' => 'service_already_exists',
                'namespace' => $endpoint->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
                'service_name' => $serviceName,
                'service_id' => $existing->id,
            ], 409);
        }

        $service = WorkflowService::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'namespace' => $endpoint->namespace,
            'service_name' => $serviceName,
            'description' => $validated['description'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return ControlPlaneProtocol::json($this->serializeService($service, $endpoint), 201);
    }

    public function serviceShow(Request $request, string $endpointName, string $serviceName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        return ControlPlaneProtocol::json($this->serializeService($service, $endpoint));
    }

    public function serviceUpdate(Request $request, string $endpointName, string $serviceName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $updates = [];

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('metadata', $validated)) {
            $updates['metadata'] = $validated['metadata'];
        }

        if ($updates !== []) {
            $service->update($updates);
        }

        return ControlPlaneProtocol::json($this->serializeService($service->refresh(), $endpoint));
    }

    public function serviceDestroy(Request $request, string $endpointName, string $serviceName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        if ($service->operations()->exists()) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Service [%s] under endpoint [%s] in namespace [%s] still has registered operations.',
                    $service->service_name,
                    $endpoint->endpoint_name,
                    $service->namespace,
                ),
                'reason' => 'service_has_operations',
                'namespace' => $service->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
                'service_name' => $service->service_name,
            ], 409);
        }

        if ($service->serviceCalls()->exists()) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Service [%s] under endpoint [%s] in namespace [%s] still has recorded service calls.',
                    $service->service_name,
                    $endpoint->endpoint_name,
                    $service->namespace,
                ),
                'reason' => 'service_has_service_calls',
                'namespace' => $service->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
                'service_name' => $service->service_name,
            ], 409);
        }

        $normalized = $service->service_name;
        $service->delete();

        return ControlPlaneProtocol::json([
            'namespace' => $endpoint->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $normalized,
            'outcome' => 'deleted',
        ]);
    }

    public function operationIndex(Request $request, string $endpointName, string $serviceName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $operations = $service->operations()
            ->orderBy('operation_name')
            ->get()
            ->map(fn (WorkflowServiceOperation $operation) => $this->serializeOperation($operation, $endpoint, $service))
            ->values();

        return ControlPlaneProtocol::json([
            'operations' => $operations,
        ]);
    }

    public function operationStore(Request $request, string $endpointName, string $serviceName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $validated = $this->validateOperationPayload($request, false);
        $operationName = $this->normalizeCatalogName($validated['operation_name']);

        $existing = WorkflowServiceOperation::query()
            ->where('namespace', $service->namespace)
            ->where('workflow_service_id', $service->id)
            ->where('operation_name', $operationName)
            ->first();

        if ($existing) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'An operation [%s] already exists under service [%s] at endpoint [%s] in namespace [%s].',
                    $operationName,
                    $service->service_name,
                    $endpoint->endpoint_name,
                    $service->namespace,
                ),
                'reason' => 'operation_already_exists',
                'namespace' => $service->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
                'service_name' => $service->service_name,
                'operation_name' => $operationName,
                'operation_id' => $existing->id,
            ], 409);
        }

        $operation = WorkflowServiceOperation::query()->create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'namespace' => $service->namespace,
            'operation_name' => $operationName,
            'description' => $validated['description'] ?? null,
            'operation_mode' => $validated['operation_mode'],
            'handler_binding_kind' => $validated['handler_binding_kind'],
            'handler_target_reference' => $validated['handler_target_reference'] ?? null,
            'handler_binding' => $validated['handler_binding'] ?? null,
            'deadline_policy' => $validated['deadline_policy'] ?? null,
            'idempotency_policy' => $validated['idempotency_policy'] ?? null,
            'cancellation_policy' => $validated['cancellation_policy'] ?? null,
            'retry_policy' => $validated['retry_policy'] ?? null,
            'boundary_policy' => $validated['boundary_policy'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return ControlPlaneProtocol::json($this->serializeOperation($operation, $endpoint, $service), 201);
    }

    public function operationShow(
        Request $request,
        string $endpointName,
        string $serviceName,
        string $operationName,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $operation = $this->findOperation($request, $service, $operationName);

        if (! $operation) {
            return $this->operationNotFound($endpoint, $service, $operationName);
        }

        return ControlPlaneProtocol::json($this->serializeOperation($operation, $endpoint, $service));
    }

    public function operationUpdate(
        Request $request,
        string $endpointName,
        string $serviceName,
        string $operationName,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $operation = $this->findOperation($request, $service, $operationName);

        if (! $operation) {
            return $this->operationNotFound($endpoint, $service, $operationName);
        }

        $validated = $this->validateOperationPayload($request, true);

        $updates = [];
        foreach ([
            'description',
            'operation_mode',
            'handler_binding_kind',
            'handler_target_reference',
            'handler_binding',
            'deadline_policy',
            'idempotency_policy',
            'cancellation_policy',
            'retry_policy',
            'boundary_policy',
            'metadata',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $updates[$field] = $validated[$field];
            }
        }

        if (
            array_key_exists('handler_target_reference', $updates)
            || array_key_exists('handler_binding', $updates)
        ) {
            $targetReference = array_key_exists('handler_target_reference', $updates)
                ? $updates['handler_target_reference']
                : $operation->handler_target_reference;
            $binding = array_key_exists('handler_binding', $updates)
                ? $updates['handler_binding']
                : $operation->handler_binding;

            $this->assertOperationBindingTargetOrPayload($targetReference, $binding);
        }

        if ($updates !== []) {
            $operation->update($updates);
        }

        return ControlPlaneProtocol::json($this->serializeOperation($operation->refresh(), $endpoint, $service));
    }

    public function serviceCallShow(
        Request $request,
        string $endpointName,
        string $serviceName,
        string $operationName,
        string $serviceCallId,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $operation = $this->findOperation($request, $service, $operationName);

        if (! $operation) {
            return $this->operationNotFound($endpoint, $service, $operationName);
        }

        $serviceCall = $this->findServiceCall($request, $operation, $serviceCallId);

        if (! $serviceCall) {
            return $this->serviceCallNotFound($endpoint, $service, $operation, $serviceCallId);
        }

        return ControlPlaneProtocol::json($this->serializeServiceCall($serviceCall, $endpoint, $service, $operation));
    }

    public function operationDestroy(
        Request $request,
        string $endpointName,
        string $serviceName,
        string $operationName,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $endpoint = $this->findEndpoint($request, $endpointName);

        if (! $endpoint) {
            return $this->endpointNotFound($request, $endpointName);
        }

        $service = $this->findService($request, $endpoint, $serviceName);

        if (! $service) {
            return $this->serviceNotFound($endpoint, $serviceName);
        }

        $operation = $this->findOperation($request, $service, $operationName);

        if (! $operation) {
            return $this->operationNotFound($endpoint, $service, $operationName);
        }

        if ($operation->serviceCalls()->exists()) {
            return ControlPlaneProtocol::json([
                'message' => sprintf(
                    'Operation [%s] under service [%s] at endpoint [%s] in namespace [%s] still has recorded service calls.',
                    $operation->operation_name,
                    $service->service_name,
                    $endpoint->endpoint_name,
                    $operation->namespace,
                ),
                'reason' => 'operation_has_service_calls',
                'namespace' => $operation->namespace,
                'endpoint_name' => $endpoint->endpoint_name,
                'service_name' => $service->service_name,
                'operation_name' => $operation->operation_name,
            ], 409);
        }

        $normalized = $operation->operation_name;
        $operation->delete();

        return ControlPlaneProtocol::json([
            'namespace' => $endpoint->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $normalized,
            'outcome' => 'deleted',
        ]);
    }

    private function namespace(Request $request): string
    {
        return (string) $request->attributes->get('namespace');
    }

    /**
     * @return list<string>
     */
    private function catalogNameRules(int $maxLength): array
    {
        return ['required', 'string', 'max:'.$maxLength, 'regex:'.self::NAME_PATTERN];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateOperationPayload(Request $request, bool $partial): array
    {
        $maxOperationName = min((int) config('server.limits.max_operation_name_length', 256), 191);

        $rules = [
            'description' => ['nullable', 'string', 'max:1000'],
            'operation_mode' => [$partial ? 'sometimes' : 'required', 'string', 'in:'.implode(',', self::OPERATION_MODES)],
            'handler_binding_kind' => [$partial ? 'sometimes' : 'required', 'string', 'in:'.implode(',', self::HANDLER_BINDING_KINDS)],
            'handler_target_reference' => ['nullable', 'string', 'max:191'],
            'handler_binding' => ['nullable', 'array'],
            'deadline_policy' => ['nullable', 'array'],
            'idempotency_policy' => ['nullable', 'array'],
            'cancellation_policy' => ['nullable', 'array'],
            'retry_policy' => ['nullable', 'array'],
            'boundary_policy' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];

        if ($partial) {
            $rules['operation_name'] = ['sometimes', 'prohibited'];
        } else {
            $rules['operation_name'] = $this->catalogNameRules($maxOperationName);
        }

        $validated = $request->validate($rules);

        if (! $partial || array_key_exists('handler_target_reference', $validated) || array_key_exists('handler_binding', $validated)) {
            $this->assertOperationBindingTargetOrPayload(
                $validated['handler_target_reference'] ?? null,
                $validated['handler_binding'] ?? null,
            );
        }

        return $validated;
    }

    private function assertOperationBindingTargetOrPayload(mixed $targetReference, mixed $binding): void
    {
        $hasTargetReference = is_string($targetReference) && trim($targetReference) !== '';
        $hasBinding = is_array($binding) && $binding !== [];

        if ($hasTargetReference || $hasBinding) {
            return;
        }

        throw ValidationException::withMessages([
            'handler_target_reference' => [
                'Provide handler_target_reference or a non-empty handler_binding.',
            ],
        ]);
    }

    private function findEndpoint(Request $request, string $endpointName): ?WorkflowServiceEndpoint
    {
        return WorkflowServiceEndpoint::query()
            ->where('namespace', $this->namespace($request))
            ->where('endpoint_name', $this->normalizeCatalogName($endpointName))
            ->first();
    }

    private function findService(
        Request $request,
        WorkflowServiceEndpoint $endpoint,
        string $serviceName,
    ): ?WorkflowService {
        return WorkflowService::query()
            ->where('namespace', $this->namespace($request))
            ->where('workflow_service_endpoint_id', $endpoint->id)
            ->where('service_name', $this->normalizeCatalogName($serviceName))
            ->first();
    }

    private function findOperation(
        Request $request,
        WorkflowService $service,
        string $operationName,
    ): ?WorkflowServiceOperation {
        return WorkflowServiceOperation::query()
            ->where('namespace', $this->namespace($request))
            ->where('workflow_service_id', $service->id)
            ->where('operation_name', $this->normalizeCatalogName($operationName))
            ->first();
    }

    private function findServiceCall(
        Request $request,
        WorkflowServiceOperation $operation,
        string $serviceCallId,
    ): ?WorkflowServiceCall {
        return WorkflowServiceCall::query()
            ->where('namespace', $this->namespace($request))
            ->where('workflow_service_operation_id', $operation->id)
            ->where('id', trim($serviceCallId))
            ->first();
    }

    private function endpointNotFound(Request $request, string $endpointName): JsonResponse
    {
        $namespace = $this->namespace($request);
        $normalized = $this->normalizeCatalogName($endpointName);

        return ControlPlaneProtocol::json([
            'message' => sprintf(
                'Service endpoint [%s] not found in namespace [%s].',
                $normalized,
                $namespace,
            ),
            'reason' => 'endpoint_not_found',
            'namespace' => $namespace,
            'endpoint_name' => $normalized,
        ], 404);
    }

    private function serviceNotFound(WorkflowServiceEndpoint $endpoint, string $serviceName): JsonResponse
    {
        $normalized = $this->normalizeCatalogName($serviceName);

        return ControlPlaneProtocol::json([
            'message' => sprintf(
                'Service [%s] not found under endpoint [%s] in namespace [%s].',
                $normalized,
                $endpoint->endpoint_name,
                $endpoint->namespace,
            ),
            'reason' => 'service_not_found',
            'namespace' => $endpoint->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $normalized,
        ], 404);
    }

    private function operationNotFound(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        string $operationName,
    ): JsonResponse {
        $normalized = $this->normalizeCatalogName($operationName);

        return ControlPlaneProtocol::json([
            'message' => sprintf(
                'Operation [%s] not found under service [%s] at endpoint [%s] in namespace [%s].',
                $normalized,
                $service->service_name,
                $endpoint->endpoint_name,
                $service->namespace,
            ),
            'reason' => 'operation_not_found',
            'namespace' => $service->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $normalized,
        ], 404);
    }

    private function serviceCallNotFound(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        string $serviceCallId,
    ): JsonResponse {
        $normalizedId = trim($serviceCallId);

        return ControlPlaneProtocol::json([
            'message' => sprintf(
                'Service call [%s] not found under operation [%s] for service [%s] at endpoint [%s] in namespace [%s].',
                $normalizedId,
                $operation->operation_name,
                $service->service_name,
                $endpoint->endpoint_name,
                $service->namespace,
            ),
            'reason' => 'service_call_not_found',
            'namespace' => $service->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'service_call_id' => $normalizedId,
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEndpoint(WorkflowServiceEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'namespace' => $endpoint->namespace,
            'endpoint_name' => $endpoint->endpoint_name,
            'description' => $endpoint->description,
            'metadata' => $endpoint->metadata,
            'created_at' => $endpoint->created_at?->toIso8601String(),
            'updated_at' => $endpoint->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeService(WorkflowService $service, WorkflowServiceEndpoint $endpoint): array
    {
        return [
            'id' => $service->id,
            'namespace' => $service->namespace,
            'endpoint_id' => $endpoint->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'description' => $service->description,
            'metadata' => $service->metadata,
            'created_at' => $service->created_at?->toIso8601String(),
            'updated_at' => $service->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOperation(
        WorkflowServiceOperation $operation,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
    ): array {
        return [
            'id' => $operation->id,
            'namespace' => $operation->namespace,
            'endpoint_id' => $endpoint->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_id' => $service->id,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'description' => $operation->description,
            'operation_mode' => $operation->operation_mode,
            'handler_binding_kind' => $operation->handler_binding_kind,
            'handler_target_reference' => $operation->handler_target_reference,
            'handler_binding' => $operation->handler_binding,
            'deadline_policy' => $operation->deadline_policy,
            'idempotency_policy' => $operation->idempotency_policy,
            'cancellation_policy' => $operation->cancellation_policy,
            'retry_policy' => $operation->retry_policy,
            'boundary_policy' => $operation->boundary_policy,
            'metadata' => $operation->metadata,
            'created_at' => $operation->created_at?->toIso8601String(),
            'updated_at' => $operation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeServiceCall(
        WorkflowServiceCall $serviceCall,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
    ): array {
        return [
            'id' => $serviceCall->id,
            'namespace' => $serviceCall->namespace,
            'endpoint_id' => $endpoint->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_id' => $service->id,
            'service_name' => $service->service_name,
            'operation_id' => $operation->id,
            'operation_name' => $operation->operation_name,
            'caller_namespace' => $serviceCall->caller_namespace,
            'caller_workflow_instance_id' => $serviceCall->caller_workflow_instance_id,
            'caller_workflow_run_id' => $serviceCall->caller_workflow_run_id,
            'target_namespace' => $serviceCall->target_namespace,
            'linked_workflow_instance_id' => $serviceCall->linked_workflow_instance_id,
            'linked_workflow_run_id' => $serviceCall->linked_workflow_run_id,
            'linked_workflow_update_id' => $serviceCall->linked_workflow_update_id,
            'status' => $serviceCall->status,
            'operation_mode' => $serviceCall->operation_mode,
            'resolved_binding_kind' => $serviceCall->resolved_binding_kind,
            'resolved_target_reference' => $serviceCall->resolved_target_reference,
            'payload_codec' => $serviceCall->payload_codec,
            'input_payload_reference' => $serviceCall->input_payload_reference,
            'output_payload_reference' => $serviceCall->output_payload_reference,
            'failure_payload_reference' => $serviceCall->failure_payload_reference,
            'failure_message' => $serviceCall->failure_message,
            'idempotency_key' => $serviceCall->idempotency_key,
            'deadline_policy' => $serviceCall->deadline_policy,
            'idempotency_policy' => $serviceCall->idempotency_policy,
            'cancellation_policy' => $serviceCall->cancellation_policy,
            'retry_policy' => $serviceCall->retry_policy,
            'boundary_policy' => $serviceCall->boundary_policy,
            'metadata' => $serviceCall->metadata,
            'accepted_at' => $serviceCall->accepted_at?->toIso8601String(),
            'started_at' => $serviceCall->started_at?->toIso8601String(),
            'completed_at' => $serviceCall->completed_at?->toIso8601String(),
            'failed_at' => $serviceCall->failed_at?->toIso8601String(),
            'cancelled_at' => $serviceCall->cancelled_at?->toIso8601String(),
            'created_at' => $serviceCall->created_at?->toIso8601String(),
            'updated_at' => $serviceCall->updated_at?->toIso8601String(),
        ];
    }

    private function normalizeCatalogName(string $name): string
    {
        return strtolower($name);
    }
}
