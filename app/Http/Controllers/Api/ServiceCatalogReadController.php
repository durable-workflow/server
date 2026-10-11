<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\ServiceCatalogReadContract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Support\ServiceCallView;
use Workflow\V2\Support\ServiceCatalog;
use Workflow\V2\Support\ServiceEndpointView;
use Workflow\V2\Support\ServiceOperationView;
use Workflow\V2\Support\ServiceView;

class ServiceCatalogReadController
{
    public function endpointsIndex(Request $request): JsonResponse
    {
        $filters = $this->filters($request);

        return $this->page($request, ServiceCatalog::endpointsQuery($this->namespace($request)), $filters, ServiceEndpointView::listItem(...));
    }

    public function endpointShow(Request $request, string $endpointId): JsonResponse
    {
        $this->filters($request, detail: true);
        $namespace = $this->namespace($request);
        $endpoint = ServiceCatalog::findEndpoint($endpointId, $namespace);
        if ($endpoint === null) {
            return $this->notFound();
        }

        [$services, $moreServices] = $this->related(
            ServiceCatalog::servicesQuery($namespace)->where('workflow_service_endpoint_id', $endpointId),
            ServiceView::listItem(...),
        );
        [$operations, $moreOperations] = $this->related(
            ServiceCatalog::operationsQuery($namespace)->where('workflow_service_endpoint_id', $endpointId),
            ServiceOperationView::listItem(...),
        );

        return ControlPlaneProtocol::json(ServiceEndpointView::listItem($endpoint) + [
            'services' => $services,
            'operations' => $operations,
            'related_has_more' => ['services' => $moreServices, 'operations' => $moreOperations],
        ]);
    }

    public function servicesIndex(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['endpoint_id']);
        $query = ServiceCatalog::servicesQuery($this->namespace($request));
        $this->catalogFilters($query, $filters);

        return $this->page($request, $query, $filters, ServiceView::listItem(...));
    }

    public function serviceShow(Request $request, string $serviceId): JsonResponse
    {
        $this->filters($request, detail: true);
        $namespace = $this->namespace($request);
        $service = ServiceCatalog::findService($serviceId, $namespace);
        if ($service === null) {
            return $this->notFound();
        }

        $endpoint = ServiceCatalog::findEndpoint((string) $service->workflow_service_endpoint_id, $namespace);
        [$operations, $more] = $this->related(
            ServiceCatalog::operationsQuery($namespace)->where('workflow_service_id', $serviceId),
            ServiceOperationView::listItem(...),
        );

        return ControlPlaneProtocol::json(ServiceView::listItem($service) + [
            'endpoint' => $endpoint === null ? null : ServiceEndpointView::listItem($endpoint),
            'operations' => $operations,
            'related_has_more' => ['operations' => $more],
        ]);
    }

    public function operationsIndex(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['endpoint_id', 'service_id']);
        $query = ServiceCatalog::operationsQuery($this->namespace($request));
        $this->catalogFilters($query, $filters);

        return $this->page($request, $query, $filters, ServiceOperationView::listItem(...));
    }

    public function operationShow(Request $request, string $operationId): JsonResponse
    {
        $this->filters($request, detail: true);
        $namespace = $this->namespace($request);
        $operation = ServiceCatalog::findOperation($operationId, $namespace);

        return $operation === null
            ? $this->notFound()
            : ControlPlaneProtocol::json(ServiceOperationView::detail($operation, $namespace));
    }

    public function callsIndex(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['endpoint_id', 'service_id', 'operation_id'], calls: true);
        $scope = $filters['scope'] ?? ServiceCatalog::SCOPE_RELEVANT;
        $status = $filters['status'] ?? null;
        $outcome = $filters['outcome'] ?? null;
        $statusBucket = $filters['status_bucket'] ?? $filters['bucket'] ?? null;
        $outcomeBucket = $filters['outcome_bucket'] ?? null;
        $query = ServiceCatalog::serviceCallsQuery($this->namespace($request), $scope, $status, $outcome);
        $this->catalogFilters($query, $filters);
        if ($statusBucket !== null && $status === null) {
            $query->whereIn('status', ServiceCallStatus::buckets()[$statusBucket]);
        }
        if ($outcomeBucket !== null && $outcome === null) {
            $query->whereIn('outcome', ServiceCallOutcome::buckets()[$outcomeBucket]);
        }

        return $this->page($request, $query, $filters, ServiceCallView::listItem(...), [
            'scope' => $scope,
            'bucket' => $statusBucket,
            'status_bucket' => $statusBucket,
            'outcome' => $outcome,
            'outcome_bucket' => $outcomeBucket,
            'statuses' => array_column(ServiceCallStatus::cases(), 'value'),
            'outcomes' => array_column(ServiceCallOutcome::cases(), 'value'),
            'status_buckets' => ServiceCallStatus::buckets(),
            'outcome_buckets' => ServiceCallOutcome::buckets(),
        ]);
    }

    public function callShow(Request $request, string $callId): JsonResponse
    {
        $this->filters($request, detail: true);
        $namespace = $this->namespace($request);
        $call = ServiceCatalog::findServiceCall($callId, $namespace);
        if ($call === null) {
            return $this->notFound();
        }

        $payload = ServiceCallView::detail($call, $namespace);
        $payload['payloads_in_observer_namespace'] = $call->namespace === $namespace;
        if (! $payload['payloads_in_observer_namespace']) {
            foreach (['input_payload_reference', 'output_payload_reference', 'failure_payload_reference'] as $field) {
                $payload[$field] = null;
            }
        }
        $outcome = is_array($call->outcome_metadata) ? $call->outcome_metadata : [];
        $payload['failure_type'] = is_string($outcome['failure_type'] ?? null) ? $outcome['failure_type'] : null;

        return ControlPlaneProtocol::json($payload);
    }

    private function namespace(Request $request): string
    {
        $namespace = $request->attributes->get('namespace');
        if (! is_string($namespace) || $namespace === '') {
            throw new \LogicException('Catalog reads require a resolved observer namespace.');
        }

        return $namespace;
    }

    private function filters(Request $request, array $ids = [], bool $calls = false, bool $detail = false): array
    {
        $rules = ['namespace' => ['sometimes', 'string', 'max:191']];
        if (! $detail) {
            $rules += [
                'page' => ['sometimes', 'integer', 'min:1'],
                'per_page' => ['sometimes', 'integer', 'between:1,'.ServiceCatalogReadContract::MAX_PAGE_SIZE],
            ];
        }
        foreach ($ids as $id) {
            $rules[$id] = ['sometimes', 'required', 'string', 'max:191'];
        }
        if ($calls) {
            $rules += [
                'scope' => ['sometimes', 'string', Rule::in(ServiceCatalog::SCOPES)],
                'status' => ['sometimes', 'string', Rule::enum(ServiceCallStatus::class)],
                'status_bucket' => ['sometimes', 'string', Rule::in(array_keys(ServiceCallStatus::buckets()))],
                'bucket' => ['sometimes', 'string', Rule::in(array_keys(ServiceCallStatus::buckets()))],
                'outcome' => ['sometimes', 'string', Rule::enum(ServiceCallOutcome::class)],
                'outcome_bucket' => ['sometimes', 'string', Rule::in(array_keys(ServiceCallOutcome::buckets()))],
            ];
        }
        $unknown = array_diff(array_keys($request->query()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported catalog filter.'));
        }

        $filters = validator($request->query(), $rules)->validate();
        if (isset($filters['bucket'], $filters['status_bucket']) && $filters['bucket'] !== $filters['status_bucket']) {
            throw ValidationException::withMessages(['bucket' => 'Must match status_bucket when both are supplied.']);
        }

        return $filters;
    }

    private function catalogFilters(Builder $query, array $filters): void
    {
        foreach (['endpoint_id' => 'workflow_service_endpoint_id', 'service_id' => 'workflow_service_id', 'operation_id' => 'workflow_service_operation_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
    }

    private function page(Request $request, Builder $query, array $filters, callable $shape, array $extra = []): JsonResponse
    {
        /** @var LengthAwarePaginator $page */
        $page = $query->paginate((int) ($filters['per_page'] ?? ServiceCatalogReadContract::DEFAULT_PAGE_SIZE), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return ControlPlaneProtocol::json([
            'data' => array_map($shape, $page->items()),
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
            'namespace' => $this->namespace($request),
        ] + $extra);
    }

    private function related(Builder $query, callable $shape): array
    {
        $items = $query->limit(ServiceCatalogReadContract::RELATED_LIMIT + 1)->get();

        return [$items->take(ServiceCatalogReadContract::RELATED_LIMIT)->map($shape)->values()->all(), $items->count() > ServiceCatalogReadContract::RELATED_LIMIT];
    }

    private function notFound(): JsonResponse
    {
        return ControlPlaneProtocol::json(['message' => 'Catalog object not found.', 'reason' => 'catalog_object_not_found'], 404);
    }
}
