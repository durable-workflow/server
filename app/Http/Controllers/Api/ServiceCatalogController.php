<?php

namespace App\Http\Controllers\Api;

use App\Auth\Principal;
use App\Http\Middleware\Authenticate;
use App\Support\ControlPlaneProtocol;
use App\Support\PayloadCodecContract;
use App\Support\SearchAttributeValueValidator;
use App\Support\ServiceCallAdmission;
use App\Support\ServiceCallBoundary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\ServiceControlPlane;
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

    public function executeOperation(
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

        $validated = $request->validate([
            'arguments' => ['nullable'],
            'payload_codec' => ['nullable', 'string', 'max:191'],
            'mode_override' => ['nullable', 'string', 'in:'.implode(',', self::OPERATION_MODES)],
            'wait_for' => ['nullable', 'string', 'in:accepted,completed'],
            'wait_timeout_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'caller_namespace' => ['nullable', 'string', 'max:255'],
            'caller_workflow_instance_id' => ['nullable', 'string', 'max:191'],
            'caller_workflow_run_id' => ['nullable', 'string', 'max:26'],
            'target_workflow_instance_id' => ['nullable', 'string', 'max:191'],
            'target_workflow_run_id' => ['nullable', 'string', 'max:26'],
            'connection' => ['nullable', 'string', 'max:191'],
            'queue' => ['nullable', 'string', 'max:191'],
            'business_key' => ['nullable', 'string', 'max:191'],
            'labels' => ['nullable', 'array'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'duplicate_start_policy' => [
                'nullable',
                'string',
                'in:reject_duplicate,return_existing_active',
            ],
        ]);

        try {
            $validated['payload_codec'] = PayloadCodecContract::canonicalize(
                $validated['payload_codec'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'payload_codec' => [$exception->getMessage()],
            ]);
        }

        if (isset($validated['search_attributes'])) {
            $validated['search_attribute_types'] = $this->searchAttributeValues()->validateForNamespace(
                $this->namespace($request),
                $validated['search_attributes'],
            );
        }

        $callerNamespace = $validated['caller_namespace'] ?? $this->namespace($request);

        $executionOptions = array_filter(
            [
                'namespace' => $this->namespace($request),
                'arguments' => $validated['arguments'] ?? null,
                'payload_codec' => $validated['payload_codec'] ?? null,
                'mode_override' => $validated['mode_override'] ?? null,
                'wait_for' => $validated['wait_for'] ?? null,
                'wait_timeout_seconds' => $validated['wait_timeout_seconds'] ?? null,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'caller_namespace' => $validated['caller_namespace'] ?? null,
                'caller_workflow_instance_id' => $validated['caller_workflow_instance_id'] ?? null,
                'caller_workflow_run_id' => $validated['caller_workflow_run_id'] ?? null,
                'target_workflow_instance_id' => $validated['target_workflow_instance_id'] ?? null,
                'target_workflow_run_id' => $validated['target_workflow_run_id'] ?? null,
                'connection' => $validated['connection'] ?? null,
                'queue' => $validated['queue'] ?? null,
                'business_key' => $validated['business_key'] ?? null,
                'labels' => $validated['labels'] ?? null,
                'memo' => $validated['memo'] ?? null,
                'search_attributes' => $validated['search_attributes'] ?? null,
                'search_attribute_types' => $validated['search_attribute_types'] ?? null,
                'duplicate_start_policy' => $validated['duplicate_start_policy'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        $idempotentCall = $this->findIdempotentServiceCall(
            $request,
            $operation,
            $validated['idempotency_key'] ?? null,
            $callerNamespace,
        );
        if ($idempotentCall) {
            $replayRejection = $this->serviceCallBoundary()->replayRejectionFor(
                principal: $this->principal($request),
                callerNamespace: $callerNamespace,
                operation: $operation,
                endpointName: $endpoint->endpoint_name,
                serviceName: $service->service_name,
                callerWorkflowInstanceId: $validated['caller_workflow_instance_id'] ?? null,
                callerWorkflowRunId: $validated['caller_workflow_run_id'] ?? null,
                idempotencyKey: $validated['idempotency_key'] ?? null,
                operationModeOverride: $validated['mode_override'] ?? null,
                endpointBoundaryPolicy: $this->arrayValue($endpoint->boundary_policy),
                serviceBoundaryPolicy: $this->arrayValue($service->boundary_policy),
                operationBoundaryPolicy: $this->arrayValue($operation->boundary_policy),
                deadlinePolicy: $this->arrayValueOrNull($operation->deadline_policy),
                idempotencyPolicy: $this->arrayValueOrNull($operation->idempotency_policy),
                cancellationPolicy: $this->arrayValueOrNull($operation->cancellation_policy),
                retryPolicy: $this->arrayValueOrNull($operation->retry_policy),
            );

            if ($replayRejection !== null) {
                return ControlPlaneProtocol::json(
                    $this->serializeAdmissionRejection($replayRejection, $endpoint, $service, $operation),
                    $replayRejection->httpStatus(),
                );
            }

            if ((string) $idempotentCall->status === 'accepted'
                && ! $this->acceptedCallHasIssuedHandler($idempotentCall)) {
                $options = $executionOptions;
                $options['service_call_id'] = $idempotentCall->id;
                $options['boundary_policy_outcome'] = 'accepted';

                $result = $this->serviceControlPlane()->execute(
                    $endpoint->endpoint_name,
                    $service->service_name,
                    $operation->operation_name,
                    $options,
                );
                $result['idempotent_replay'] = true;
            } else {
                $result = array_replace(
                    [
                        'accepted' => ! in_array((string) $idempotentCall->status, ['failed', 'cancelled'], true),
                        'idempotent_replay' => true,
                        'reason' => null,
                    ],
                    $this->serviceControlPlane()->describeCall($idempotentCall->id, [
                        'namespace' => $this->namespace($request),
                    ]),
                );
            }

            return ControlPlaneProtocol::json($result, ($result['accepted'] ?? false) === true ? 200 : 409);
        }

        $admission = $this->serviceCallBoundary()->admitFor(
            principal: $this->principal($request),
            callerNamespace: $callerNamespace,
            operation: $operation,
            endpointName: $endpoint->endpoint_name,
            serviceName: $service->service_name,
            callerWorkflowInstanceId: $validated['caller_workflow_instance_id'] ?? null,
            callerWorkflowRunId: $validated['caller_workflow_run_id'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            operationModeOverride: $validated['mode_override'] ?? null,
            endpointBoundaryPolicy: $this->arrayValue($endpoint->boundary_policy),
            serviceBoundaryPolicy: $this->arrayValue($service->boundary_policy),
            operationBoundaryPolicy: $this->arrayValue($operation->boundary_policy),
            deadlinePolicy: $this->arrayValueOrNull($operation->deadline_policy),
            idempotencyPolicy: $this->arrayValueOrNull($operation->idempotency_policy),
            cancellationPolicy: $this->arrayValueOrNull($operation->cancellation_policy),
            retryPolicy: $this->arrayValueOrNull($operation->retry_policy),
        );

        if ($admission->rejected()) {
            return ControlPlaneProtocol::json(
                $this->serializeAdmissionRejection($admission, $endpoint, $service, $operation),
                $admission->httpStatus(),
            );
        }

        $options = $executionOptions;
        $options['service_call_id'] = $admission->call->id;
        $options['boundary_policy_outcome'] = $admission->decision->outcome->value;

        try {
            $result = $this->serviceControlPlane()->execute(
                $endpoint->endpoint_name,
                $service->service_name,
                $operation->operation_name,
                $options,
            );
        } finally {
            if ($admission->request->operationMode->value === 'sync') {
                $this->serviceCallBoundary()->release($admission->request);
            }
        }

        $status = ($result['accepted'] ?? false) === true ? 200 : 409;

        $serviceCall = $admission->call->refresh();

        return ControlPlaneProtocol::json(
            array_replace(
                $this->serializeServiceCall($serviceCall, $endpoint, $service, $operation),
                $result,
            ),
            $status,
        );
    }

    public function serviceCallCancel(
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

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->serviceControlPlane()->cancelCall(
            $serviceCall->id,
            array_filter([
                'namespace' => $this->namespace($request),
                'reason' => $validated['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        );

        $status = ($result['accepted'] ?? false) === true ? 200 : 409;

        $serviceCall->refresh();

        return ControlPlaneProtocol::json(
            array_replace(
                $this->serializeServiceCall($serviceCall, $endpoint, $service, $operation),
                $result,
            ),
            $status,
        );
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

        $result = $this->serviceControlPlane()->describeCall($serviceCall->id, [
            'namespace' => $this->namespace($request),
        ]);

        return ControlPlaneProtocol::json(
            array_replace(
                $this->serializeServiceCall($serviceCall, $endpoint, $service, $operation),
                $result,
            ),
            ($result['found'] ?? false) === false ? 404 : 200,
        );
    }

    /**
     * Caller-indexed Nexus operation history for a workflow instance.
     *
     * Returns every cross-namespace service call (Nexus operation) the
     * workflow run scheduled, regardless of the target endpoint / service /
     * operation triple. The shape mirrors the per-call describe payload but
     * is keyed by the caller workflow instance instead of by the durable
     * service-call id, so an operator debugging a failed workflow can
     * answer "what cross-namespace calls did this run make?" from a single
     * surface.
     */
    public function nexusOperationsForWorkflow(
        Request $request,
        string $workflowId,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $this->namespace($request);
        $limit = $this->nexusListLimit($request);

        $calls = WorkflowServiceCall::query()
            ->where('caller_namespace', $namespace)
            ->where('caller_workflow_instance_id', $workflowId)
            ->orderByDesc('accepted_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return ControlPlaneProtocol::json([
            'workflow_id' => $workflowId,
            'caller_namespace' => $namespace,
            'count' => $calls->count(),
            'limit' => $limit,
            'nexus_operations' => $calls
                ->map(fn (WorkflowServiceCall $call) => $this->serializeCallerCall($call))
                ->values(),
        ]);
    }

    /**
     * Caller-indexed Nexus operation history scoped to a single run id.
     * Same shape as {@see nexusOperationsForWorkflow()} but filters to the
     * specific run, so a caller workflow that has continued-as-new can
     * inspect each run's outbound Nexus traffic separately.
     */
    public function nexusOperationsForRun(
        Request $request,
        string $workflowId,
        string $runId,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $this->namespace($request);
        $limit = $this->nexusListLimit($request);

        $calls = WorkflowServiceCall::query()
            ->where('caller_namespace', $namespace)
            ->where('caller_workflow_instance_id', $workflowId)
            ->where('caller_workflow_run_id', $runId)
            ->orderByDesc('accepted_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return ControlPlaneProtocol::json([
            'workflow_id' => $workflowId,
            'workflow_run_id' => $runId,
            'caller_namespace' => $namespace,
            'count' => $calls->count(),
            'limit' => $limit,
            'nexus_operations' => $calls
                ->map(fn (WorkflowServiceCall $call) => $this->serializeCallerCall($call))
                ->values(),
        ]);
    }

    private function nexusListLimit(Request $request): int
    {
        $configured = (int) config('server.limits.max_nexus_operations_per_caller', 200);
        $configured = $configured > 0 ? $configured : 200;
        $requested = $request->query('limit');

        if ($requested === null) {
            return $configured;
        }

        if (! is_numeric($requested)) {
            return $configured;
        }

        $value = (int) $requested;

        if ($value < 1) {
            return 1;
        }

        return min($value, $configured);
    }

    /**
     * Caller-side Nexus row shape. Lighter than the per-call describe
     * envelope (no payload references, no full policy snapshot) so a
     * workflow with hundreds of outbound calls fits in one response.
     *
     * @return array<string, mixed>
     */
    private function serializeCallerCall(WorkflowServiceCall $call): array
    {
        return [
            'service_call_id' => $call->id,
            'caller_namespace' => $call->caller_namespace,
            'caller_workflow_instance_id' => $call->caller_workflow_instance_id,
            'caller_workflow_run_id' => $call->caller_workflow_run_id,
            'target_namespace' => $call->target_namespace,
            'endpoint_name' => $call->endpoint_name,
            'service_name' => $call->service_name,
            'operation_name' => $call->operation_name,
            'operation_mode' => $call->operation_mode,
            'status' => $call->status,
            'outcome' => $this->scalarValue($call->outcome),
            'outcome_category' => $call->outcome_category,
            'outcome_reason' => $call->outcome_reason,
            'outcome_message' => $call->outcome_message,
            'outcome_metadata' => $call->outcome_metadata,
            'service_error_type' => $this->metadataString($call->outcome_metadata, 'service_error_type'),
            'caller_observed_error_type' => $this->metadataString($call->outcome_metadata, 'caller_observed_error_type'),
            'typed_error_message' => $this->metadataString($call->outcome_metadata, 'typed_error_message'),
            'resolved_binding_kind' => $call->resolved_binding_kind,
            'resolved_target_reference' => $call->resolved_target_reference,
            'linked_workflow_instance_id' => $call->linked_workflow_instance_id,
            'linked_workflow_run_id' => $call->linked_workflow_run_id,
            'linked_workflow_update_id' => $call->linked_workflow_update_id,
            'idempotency_key' => $call->idempotency_key,
            'retry_policy' => $call->retry_policy,
            'service_call_attempts' => $this->serviceCallAttempts($call),
            'retry_attempt_count' => count($this->serviceCallAttempts($call)),
            'failure_message' => $call->failure_message,
            'caller_principal_subject' => $call->caller_principal_subject,
            'caller_principal_method' => $call->caller_principal_method,
            'accepted_at' => $call->accepted_at?->toIso8601String(),
            'started_at' => $call->started_at?->toIso8601String(),
            'completed_at' => $call->completed_at?->toIso8601String(),
            'failed_at' => $call->failed_at?->toIso8601String(),
            'cancelled_at' => $call->cancelled_at?->toIso8601String(),
        ];
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

    private function principal(Request $request): Principal
    {
        return Authenticate::principal($request)
            ?? Principal::role('operator', 'none', subject: 'unknown');
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayValueOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    private function scalarValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAdmissionRejection(
        ServiceCallAdmission $admission,
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
    ): array {
        $payload = [
            'accepted' => false,
            'service_call_id' => $admission->call->id,
            'namespace' => $admission->call->namespace,
            'status' => $admission->call->status,
            'outcome' => $admission->decision->outcome->value,
            'error_type' => $admission->decision->outcome->value,
            'outcome_category' => $admission->call->outcome_category,
            'reason' => $admission->decision->reason,
            'message' => $this->admissionRejectionMessage($admission),
            'retry_after_seconds' => $admission->decision->retryAfterSeconds,
            'policy_name' => $admission->decision->policyName,
            'outcome_metadata' => $this->admissionRejectionMetadata($admission),
            'caller_namespace' => $admission->call->caller_namespace,
            'caller_principal_subject' => $admission->call->caller_principal_subject,
            'linked_workflow_instance_id' => null,
            'linked_workflow_run_id' => null,
            'linked_workflow_update_id' => null,
        ];

        if (! $this->redactAdmissionTarget($admission)) {
            $payload = [
                'accepted' => $payload['accepted'],
                'service_call_id' => $payload['service_call_id'],
                'namespace' => $payload['namespace'],
                'endpoint_id' => $endpoint->id,
                'endpoint_name' => $endpoint->endpoint_name,
                'service_id' => $service->id,
                'service_name' => $service->service_name,
                'operation_id' => $operation->id,
                'operation_name' => $operation->operation_name,
                'operation_mode' => $admission->call->operation_mode,
            ] + array_diff_key($payload, array_flip([
                'accepted',
                'service_call_id',
                'namespace',
            ]));
        }

        return $payload;
    }

    private function redactAdmissionTarget(ServiceCallAdmission $admission): bool
    {
        return $admission->decision->outcome->value === 'rejected_forbidden';
    }

    private function admissionRejectionMessage(ServiceCallAdmission $admission): ?string
    {
        if ($this->redactAdmissionTarget($admission)) {
            return 'Nexus operation is not permitted for this caller.';
        }

        return $admission->decision->message;
    }

    /**
     * @return array<string, mixed>
     */
    private function admissionRejectionMetadata(ServiceCallAdmission $admission): array
    {
        if (! $this->redactAdmissionTarget($admission)) {
            return $admission->decision->metadata;
        }

        $failureReason = $admission->decision->metadata['failure_reason'] ?? 'policy_rejection';

        return [
            'failure_reason' => is_scalar($failureReason) ? (string) $failureReason : 'policy_rejection',
        ];
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

    private function serviceControlPlane(): ServiceControlPlane
    {
        // Catalog registration shares this controller with execution routes.
        // Avoid building the execution graph in Apache children that only
        // create or inspect endpoint, service, and operation records.
        return app(ServiceControlPlane::class);
    }

    private function serviceCallBoundary(): ServiceCallBoundary
    {
        return app(ServiceCallBoundary::class);
    }

    private function searchAttributeValues(): SearchAttributeValueValidator
    {
        return app(SearchAttributeValueValidator::class);
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

    private function findIdempotentServiceCall(
        Request $request,
        WorkflowServiceOperation $operation,
        mixed $idempotencyKey,
        string $callerNamespace,
    ): ?WorkflowServiceCall {
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return null;
        }

        return WorkflowServiceCall::query()
            ->where('namespace', $this->namespace($request))
            ->where('workflow_service_operation_id', $operation->id)
            ->where('caller_namespace', $callerNamespace)
            ->where('idempotency_key', trim($idempotencyKey))
            ->oldest('created_at')
            ->oldest('id')
            ->first();
    }

    private function acceptedCallHasIssuedHandler(WorkflowServiceCall $call): bool
    {
        $metadata = $this->arrayValue($call->metadata);

        return match ((string) $call->resolved_binding_kind) {
            'activity_execution' => isset($metadata['activity_execution_id'])
                && is_string($metadata['activity_execution_id'])
                && trim($metadata['activity_execution_id']) !== '',
            'invocable_carrier_request' => isset($metadata['carrier_request_id'])
                && is_string($metadata['carrier_request_id'])
                && trim($metadata['carrier_request_id']) !== '',
            default => false,
        };
    }

    private function endpointNotFound(Request $request, string $endpointName): JsonResponse
    {
        $namespace = $this->namespace($request);
        $normalized = $this->normalizeCatalogName($endpointName);

        return ControlPlaneProtocol::json([
            'accepted' => false,
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
            'outcome' => $this->scalarValue($serviceCall->outcome),
            'outcome_category' => $serviceCall->outcome_category,
            'outcome_reason' => $serviceCall->outcome_reason,
            'outcome_message' => $serviceCall->outcome_message,
            'outcome_metadata' => $serviceCall->outcome_metadata,
            'service_error_type' => $this->metadataString($serviceCall->outcome_metadata, 'service_error_type'),
            'caller_observed_error_type' => $this->metadataString($serviceCall->outcome_metadata, 'caller_observed_error_type'),
            'typed_error_message' => $this->metadataString($serviceCall->outcome_metadata, 'typed_error_message'),
            'policy_name' => $serviceCall->policy_name,
            'retry_after_seconds' => $serviceCall->retry_after_seconds,
            'operation_mode' => $serviceCall->operation_mode,
            'resolved_binding_kind' => $serviceCall->resolved_binding_kind,
            'resolved_target_reference' => $serviceCall->resolved_target_reference,
            'caller_principal_subject' => $serviceCall->caller_principal_subject,
            'caller_principal_method' => $serviceCall->caller_principal_method,
            'caller_principal_roles' => $serviceCall->caller_principal_roles,
            'caller_principal_tenant' => $serviceCall->caller_principal_tenant,
            'caller_principal_claims' => $serviceCall->caller_principal_claims,
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
            'service_call_attempts' => $this->serviceCallAttempts($serviceCall),
            'retry_attempt_count' => count($this->serviceCallAttempts($serviceCall)),
            'accepted_at' => $serviceCall->accepted_at?->toIso8601String(),
            'started_at' => $serviceCall->started_at?->toIso8601String(),
            'completed_at' => $serviceCall->completed_at?->toIso8601String(),
            'failed_at' => $serviceCall->failed_at?->toIso8601String(),
            'cancelled_at' => $serviceCall->cancelled_at?->toIso8601String(),
            'created_at' => $serviceCall->created_at?->toIso8601String(),
            'updated_at' => $serviceCall->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serviceCallAttempts(WorkflowServiceCall $call): array
    {
        foreach ([
            is_array($call->metadata) ? $call->metadata : [],
            is_array($call->outcome_metadata) ? $call->outcome_metadata : [],
        ] as $container) {
            if (isset($container['service_call_attempts']) && is_array($container['service_call_attempts'])) {
                return array_values(array_filter($container['service_call_attempts'], 'is_array'));
            }
        }

        return [];
    }

    private function metadataString(mixed $metadata, string $key): ?string
    {
        if (! is_array($metadata)) {
            return null;
        }

        $value = $metadata[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function normalizeCatalogName(string $name): string
    {
        return strtolower($name);
    }
}
