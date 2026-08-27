<?php

namespace App\Http\Controllers\Api;

use App\Auth\Principal;
use App\Http\Middleware\Authenticate;
use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneResponseContract;
use App\Support\ControlPlaneResultMapper;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\ExternalWorkflowUpdateAdmission;
use App\Support\LegacyV1Projection;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\SearchAttributeValueValidator;
use App\Support\TaskQueueRoutingGate;
use App\Support\WorkflowCommandContextFactory;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowRunDiagnostics;
use App\Support\WorkflowStartService;
use App\Support\WorkflowVisibilityQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use Workflow\Serializers\AvroValueJsonProjection;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\FailureSnapshots;
use Workflow\V2\Support\RunCommandContract;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Workflow;

class WorkflowController
{
    public function __construct(
        private readonly WorkflowStartService $workflowStartService,
        private readonly WorkflowControlPlane $workflowControlPlane,
        private readonly TaskQueueRoutingGate $taskQueueRoutingGate,
        private readonly WorkflowCommandContextFactory $commandContexts,
        private readonly ControlPlaneResultMapper $resultMapper,
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly WorkflowRunDiagnostics $diagnostics,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
        private readonly SearchAttributeValueValidator $searchAttributeValues,
        private readonly WorkflowVisibilityQuery $visibilityQuery,
        private readonly ExternalWorkflowUpdateAdmission $externalUpdates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $query = $request->validate([
            'workflow_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:running,completed,failed,cancelled,terminated'],
            'query' => ['nullable', 'string'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
            'next_page_token' => ['nullable', 'string'],
        ]);

        $pageSize = $query['page_size'] ?? 50;
        $offset = $this->decodePageToken($query['next_page_token'] ?? null) ?? 0;

        $workflows = NamespaceWorkflowScope::runSummaryQuery($namespace)
            ->when(
                isset($query['workflow_type']),
                static fn ($builder) => $builder->where('workflow_run_summaries.workflow_type', $query['workflow_type']),
            )
            ->when(isset($query['status']), static function ($builder) use ($query) {
                $status = (string) $query['status'];

                return in_array($status, ['cancelled', 'terminated'], true)
                    ? $builder->where('workflow_run_summaries.status', $status)
                    : $builder->where('workflow_run_summaries.status_bucket', $status);
            })
            ->when(isset($query['query']), function ($builder) use ($query, $namespace) {
                $term = trim((string) $query['query']);

                if ($term === '') {
                    return;
                }

                if ($this->visibilityQuery->apply($builder, (string) $namespace, $term)) {
                    return;
                }

                $builder->where(function ($scoped) use ($term) {
                    $scoped->where('workflow_run_summaries.workflow_instance_id', 'like', '%'.$term.'%')
                        ->orWhere('workflow_run_summaries.business_key', 'like', '%'.$term.'%');
                });
            })
            ->orderByDesc('workflow_run_summaries.sort_timestamp')
            ->orderByDesc('workflow_run_summaries.id')
            ->offset($offset)
            ->limit($pageSize + 1)
            ->get();

        $hasMore = $workflows->count() > $pageSize;
        $page = $hasMore ? $workflows->slice(0, $pageSize)->values() : $workflows->values();
        $page->load('searchAttributes');

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflows' => $page->map(fn ($summary) => [
                'workflow_id' => $summary->workflow_instance_id,
                'run_id' => $summary->id,
                'workflow_type' => $summary->workflow_type,
                'business_key' => $summary->business_key,
                'status' => $summary->status,
                'status_bucket' => $summary->status_bucket,
                'task_queue' => $summary->queue,
                'is_terminal' => RunStatus::from($summary->status)->isTerminal(),
                // The worker build the run is pinned to, surfaced so
                // operators can see which version is in flight across a
                // mixed worker-version pool without drilling into the
                // run detail.
                'compatibility' => $summary->compatibility,
                'compatibility_status' => $this->summaryCompatibilityStatus((string) $namespace, $summary),
                'compatibility_supported_in_fleet' => $this->summaryCompatibilitySupportedInFleet((string) $namespace, $summary),
                'compatibility_fleet_reason' => $this->summaryCompatibilityFleetReason((string) $namespace, $summary),
                'started_at' => $summary->started_at?->toJSON(),
                'closed_at' => $summary->closed_at?->toJSON(),
                'search_attributes' => $summary->getTypedSearchAttributes(),
            ])->all(),
            'workflow_count' => $page->count(),
            'next_page_token' => $hasMore ? $this->encodePageToken($offset + $pageSize) : null,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validator = Validator::make($request->all(), [
            'workflow_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'workflow_type' => ['required', 'string', 'max:255'],
            'task_queue' => ['nullable', 'string', 'max:255'],
            'input' => ['nullable', 'array'],
            'payload_codec' => ['nullable', 'string', 'max:64'],
            'business_key' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'duplicate_policy' => ['nullable', 'string', 'in:fail,use-existing'],
            'execution_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'run_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            // Dispatch-shaping fields. Lower priority numbers run first when
            // workers on a shared queue are saturated; fairness_key tags the
            // workload class so dispatch is rebalanced across distinct
            // tenants/teams within a priority tier; fairness_weight gives a
            // class a proportionally larger dispatch share.
            'priority' => ['nullable', 'integer', 'min:0', 'max:9'],
            'fairness_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]{1,64}$/'],
            'fairness_weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ], [
            'duplicate_policy.in' => 'The duplicate_policy field only supports fail or use-existing.',
            'priority.min' => 'The priority field must be an integer in the range 0..9 (lower runs first).',
            'priority.max' => 'The priority field must be an integer in the range 0..9 (lower runs first).',
            'fairness_key.regex' => 'The fairness_key field must be 1-64 URL-safe characters using letters, numbers, ".", "_", "-", or ":".',
            'fairness_weight.min' => 'The fairness_weight field must be an integer in the range 1..1000.',
            'fairness_weight.max' => 'The fairness_weight field must be an integer in the range 1..1000.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            foreach ([
                'workflow_execution_timeout' => 'Use execution_timeout_seconds instead of workflow_execution_timeout.',
                'workflow_run_timeout' => 'Use run_timeout_seconds instead of workflow_run_timeout.',
                'workflow_task_timeout' => 'The workflow_task_timeout field is not supported by the v2 workflow start API.',
                'retry_policy' => 'The retry_policy field is not supported by the v2 workflow start API.',
                'idempotency_key' => 'The idempotency_key field is not supported by the v2 workflow start API.',
                'request_id' => 'The request_id field is not supported by the v2 workflow start API.',
            ] as $field => $message) {
                if (array_key_exists($field, $request->all())) {
                    $validator->errors()->add($field, $message);
                }
            }
        });

        $validated = $validator->validate();

        try {
            $validated['payload_codec'] = PayloadCodecContract::canonicalize(
                $validated['payload_codec'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'payload_codec' => [$exception->getMessage()],
            ]);
        }

        if (isset($validated['memo'])) {
            $memoSize = strlen(json_encode($validated['memo']));
            $maxMemoBytes = (int) config('server.limits.max_memo_bytes', 256 * 1024);

            if ($memoSize > $maxMemoBytes) {
                throw ValidationException::withMessages([
                    'memo' => [sprintf('The memo exceeds the maximum allowed size of %d bytes.', $maxMemoBytes)],
                ]);
            }
        }

        if (isset($validated['search_attributes'])) {
            $validated['search_attribute_types'] = $this->searchAttributeValues->validateForNamespace(
                is_string($namespace) ? $namespace : null,
                $validated['search_attributes'],
            );
        }

        $workflowId = $validated['workflow_id'] ?? null;

        if ($workflowId !== null && $this->workflowIdReservedElsewhere($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest(
                $request,
                $this->startRejectionPayload(
                    workflowId: $workflowId,
                    reason: 'workflow_id_reserved_in_namespace',
                    outcome: 'rejected_workflow_id_reserved_in_namespace',
                    message: sprintf(
                        'Workflow [%s] is already reserved in another namespace.',
                        $workflowId,
                    ),
                ),
                409,
            );
        }

        try {
            $taskQueue = $this->workflowStartService->resolveTaskQueue(
                $validated['workflow_type'],
                $validated['task_queue'] ?? null,
            );
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'workflow_type' => [$exception->getMessage()],
            ]);
        }

        $routingBlock = $this->taskQueueRoutingGate->workflowStartBlock((string) $namespace, $taskQueue);

        if ($routingBlock !== null) {
            return ControlPlaneProtocol::jsonForRequest(
                $request,
                $this->startRejectionPayload(
                    workflowId: $workflowId,
                    reason: 'task_queue_draining',
                    outcome: 'rejected_task_queue_draining',
                    message: sprintf(
                        'Task queue [%s] is draining and cannot accept new workflow starts until an active worker cohort is available.',
                        $taskQueue,
                    ),
                    extra: [
                        'workflow_type' => $validated['workflow_type'],
                        'task_queue' => $taskQueue,
                        'routing_status' => $routingBlock['routing_status'],
                        'active_worker_count' => $routingBlock['active_worker_count'],
                        'draining_worker_count' => $routingBlock['draining_worker_count'],
                        'stale_worker_count' => $routingBlock['stale_worker_count'],
                        'draining_build_ids' => $routingBlock['draining_build_ids'],
                        'drain_intent' => 'draining',
                    ],
                ),
                409,
            );
        }

        try {
            $start = $this->workflowStartService->start(
                $validated,
                $namespace,
                $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId ?? 'pending',
                    commandName: 'start',
                    metadata: array_filter([
                        'workflow_type' => $validated['workflow_type'],
                        'task_queue' => $taskQueue,
                        'duplicate_policy' => $validated['duplicate_policy'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
            );
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return ControlPlaneProtocol::jsonForRequest(
                $request,
                $this->startRejectionPayload(
                    workflowId: $workflowId,
                    reason: 'external_payload_storage_unavailable',
                    outcome: 'rejected_external_payload_storage_unavailable',
                    message: $exception->getMessage(),
                    extra: [
                        'workflow_type' => $validated['workflow_type'],
                        'task_queue' => $taskQueue,
                    ],
                ),
                503,
            );
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'workflow_type' => [$exception->getMessage()],
            ]);
        }

        $workflowId = $start['workflow_id'];
        $started = (bool) ($start['started'] ?? false);

        NamespaceWorkflowScope::bind(
            $namespace,
            $workflowId,
            $start['workflow_type'],
        );

        $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'run_id' => $start['run_id'],
            'workflow_type' => $start['workflow_type'],
            'namespace' => $namespace,
            'status' => $run?->status?->value,
            'business_key' => $run?->business_key,
            'payload_codec' => $run?->payload_codec,
            'outcome' => $start['outcome'],
            'command_status' => $started ? 'accepted' : 'rejected',
            'command_source' => 'control_plane',
            'reason' => $start['reason'],
            'rejection_reason' => $start['rejection_reason'],
            'message' => $start['message'],
        ], $this->startStatusCode($start['outcome']));
    }

    public function show(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        if (! $run) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $description = LegacyV1Projection::isProjectedRun($run)
            ? []
            : $this->workflowControlPlane->describe($workflowId, ['namespace' => $namespace]);

        return ControlPlaneProtocol::jsonForRequest($request, $this->formatRun($run, $namespace, $description));
    }

    public function runs(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $currentRunId = NamespaceWorkflowScope::currentRun($namespace, $workflowId)?->id;
        $runs = NamespaceWorkflowScope::runQuery($namespace, $workflowId)
            ->with('memos')
            ->orderBy('workflow_runs.run_number')
            ->get();

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'run_count' => $runs->count(),
            'runs' => $runs->map(fn (WorkflowRun $run) => $this->formatRunListEntry(
                $run,
                (string) $namespace,
                is_string($currentRunId) ? $currentRunId : null,
            ))->all(),
        ]);
    }

    public function showRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return $this->runNotFound($request, $workflowId, $runId);
        }

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return $this->runNotFound($request, $workflowId, $runId);
        }

        $description = LegacyV1Projection::isProjectedRun($run)
            ? []
            : $this->workflowControlPlane->describe($workflowId, [
                'namespace' => $namespace,
                'run_id' => $runId,
            ]);

        return ControlPlaneProtocol::jsonForRequest($request, $this->formatRun($run, $namespace, $description));
    }

    public function debug(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        if (! $run) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        return ControlPlaneProtocol::jsonForRequest(
            $request,
            $this->diagnostics->forRun($namespace, $run, $this->includeLastEventPayload($request)),
        );
    }

    public function debugRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return $this->runNotFound($request, $workflowId, $runId);
        }

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return $this->runNotFound($request, $workflowId, $runId);
        }

        return ControlPlaneProtocol::jsonForRequest(
            $request,
            $this->diagnostics->forRun($namespace, $run, $this->includeLastEventPayload($request)),
        );
    }

    private function includeLastEventPayload(Request $request): bool
    {
        return $request->boolean('include_last_event_payload');
    }

    public function signal(Request $request, string $workflowId, string $signalName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateOperationName($signalName, 'signal');

        $namespace = $request->attributes->get('namespace');
        $dryRun = $request->boolean('dry_run');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, array_filter([
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
                'signal_name' => $dryRun ? $signalName : null,
                'command_status' => $dryRun ? 'rejected' : null,
                'command_source' => $dryRun ? 'control_plane' : null,
                'outcome' => $dryRun ? 'rejected_not_found' : null,
                'rejection_reason' => $dryRun ? 'instance_not_found' : null,
                'rejection_category' => $dryRun ? 'admission' : null,
                'dry_run' => $dryRun ? true : null,
                'preview' => $dryRun ? [
                    'would_record_signal' => false,
                ] : null,
            ], static fn (mixed $value): bool => $value !== null), 404);
        }

        if ($response = $this->rejectProjectedV1Operation($request, $namespace, $workflowId, 'signal')) {
            return $response;
        }

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        if ($dryRun) {
            return $this->resultMapper->signal(
                $workflowId,
                $signalName,
                $this->signalPreviewPayload($namespace, $workflowId, $signalName, $validated),
                $this->controlPlaneRunId($request),
            );
        }

        $externalStorage = $this->externalPayloadStorage->driverFor($namespace);
        $envelope = AvroPayloadEnvelopeResolver::resolve($validated['input'] ?? null, 'input', $externalStorage);

        $result = $this->workflowControlPlane->signal(
            $workflowId,
            $signalName,
            [
                'namespace' => $namespace,
                'arguments' => AvroPayloadEnvelopeResolver::resolveToArray($validated['input'] ?? null, 'input', $externalStorage),
                'payload_codec' => $envelope['codec'],
                'payload_blob' => $envelope['blob'],
                'command_context' => $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'signal',
                    metadata: array_filter([
                        'request_id' => $validated['request_id'] ?? null,
                        'signal_name' => $signalName,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->signal(
            $workflowId,
            $signalName,
            $result,
            $this->controlPlaneRunId($request),
        );
    }

    public function query(Request $request, string $workflowId, string $queryName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateOperationName($queryName, 'query');

        $namespace = $request->attributes->get('namespace');

        Log::info('workflow_query_request_admitted', [
            'namespace' => $namespace,
            'workflow_id' => $workflowId,
            'query_name' => $queryName,
        ]);

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
        ]);

        $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        if (LegacyV1Projection::isProjectedRun($run)) {
            return $this->projectedV1OperationResponse($request, $run, 'query');
        }

        if ($run instanceof WorkflowRun && $this->rejectsTerminalQuery($run)) {
            return $this->resultMapper->query(
                $workflowId,
                $queryName,
                $this->terminalRunQueryFailure($run, $queryName),
                $this->controlPlaneRunId($request),
            );
        }

        $externalStorage = $this->externalPayloadStorage->driverFor($namespace);
        $queryEnvelope = AvroPayloadEnvelopeResolver::resolve($validated['input'] ?? null, 'input', $externalStorage);
        $commandContext = $this->commandContexts->make(
            $request,
            workflowId: $workflowId,
            commandName: 'query',
            metadata: array_filter([
                'query_name' => $queryName,
            ], static fn (mixed $value): bool => $value !== null),
        );

        if ($run instanceof WorkflowRun
            && ($this->queryTasks->hasWorkerFor($namespace, $run) || $this->requiresQueryTaskRouting($run))) {
            return $this->resultMapper->query(
                $workflowId,
                $queryName,
                $this->queryTasks->query($namespace, $run, $queryName, $queryEnvelope, $commandContext),
                $this->controlPlaneRunId($request),
            );
        }

        $result = $this->workflowControlPlane->query(
            $workflowId,
            $queryName,
            [
                'namespace' => $namespace,
                'arguments' => AvroPayloadEnvelopeResolver::resolveToArray($validated['input'] ?? null, 'input', $externalStorage),
                'command_context' => $commandContext,
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->query(
            $workflowId,
            $queryName,
            $result,
            $this->controlPlaneRunId($request),
        );
    }

    private function canReplayQueryInProcess(WorkflowRun $run): bool
    {
        $workflowClass = is_string($run->workflow_class) ? trim($run->workflow_class) : '';

        return $workflowClass !== ''
            && class_exists($workflowClass)
            && is_subclass_of($workflowClass, Workflow::class);
    }

    private function requiresQueryTaskRouting(WorkflowRun $run): bool
    {
        return $this->nonEmptyString($run->compatibility) !== null
            || ! $this->canReplayQueryInProcess($run);
    }

    private function rejectsTerminalQuery(WorkflowRun $run): bool
    {
        return $run->status->isTerminal()
            && $run->status !== RunStatus::Completed;
    }

    private function canServeQuery(string $namespace, WorkflowRun $run): bool
    {
        if ($this->rejectsTerminalQuery($run)) {
            return false;
        }

        return ! $this->requiresQueryTaskRouting($run)
            || $this->queryTasks->hasWorkerFor($namespace, $run);
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalRunQueryFailure(WorkflowRun $run, string $queryName): array
    {
        return [
            'success' => false,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'target_scope' => 'instance',
            'query_name' => $queryName,
            'result' => null,
            'reason' => 'run_not_active',
            'message' => sprintf(
                'Workflow query [%s] cannot execute because run [%s] is terminal with status [%s].',
                $queryName,
                $run->id,
                $run->status->value,
            ),
            'run_status' => $run->status->value,
            'is_terminal' => true,
            'status' => 409,
        ];
    }

    public function update(Request $request, string $workflowId, string $updateName): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $this->validateOperationName($updateName, 'update');

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        if ($response = $this->rejectProjectedV1Operation($request, $namespace, $workflowId, 'update')) {
            return $response;
        }

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'wait_for' => ['nullable', 'string', 'in:accepted,completed'],
            'wait_timeout_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->rejectLegacyUpdateFields($request);
        $externalStorage = $this->externalPayloadStorage->driverFor($namespace);
        $arguments = AvroPayloadEnvelopeResolver::resolveToArray($validated['input'] ?? null, 'input', $externalStorage);
        $waitFor = $validated['wait_for'] ?? 'accepted';
        $commandContext = $this->commandContexts->make(
            $request,
            workflowId: $workflowId,
            commandName: 'update',
            metadata: array_filter([
                'request_id' => $validated['request_id'] ?? null,
                'update_name' => $updateName,
                'wait_for' => $waitFor,
            ], static fn (mixed $value): bool => $value !== null),
        );

        $externalResult = $this->externalUpdates->admit(
            namespace: (string) $namespace,
            workflowId: $workflowId,
            updateName: $updateName,
            arguments: $arguments,
            commandContext: $commandContext,
            waitFor: $waitFor,
            waitTimeoutSeconds: $validated['wait_timeout_seconds'] ?? null,
        );

        if ($externalResult !== null) {
            return $this->resultMapper->update(
                workflowId: $workflowId,
                updateName: $updateName,
                waitFor: $waitFor,
                result: $this->withAuthenticatedPrincipal($request, $externalResult),
                runId: $this->controlPlaneRunId($request),
            );
        }

        $result = $this->workflowControlPlane->update(
            $workflowId,
            $updateName,
            [
                'namespace' => $namespace,
                'arguments' => $arguments,
                'command_context' => $commandContext,
                'wait_for' => $waitFor,
                'wait_timeout_seconds' => $validated['wait_timeout_seconds'] ?? null,
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->update(
            workflowId: $workflowId,
            updateName: $updateName,
            waitFor: $waitFor,
            result: $this->withAuthenticatedPrincipal($request, $result),
            runId: $this->controlPlaneRunId($request),
        );
    }

    public function cancel(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        if ($response = $this->rejectProjectedV1Operation($request, $namespace, $workflowId, 'cancel')) {
            return $response;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->cancel(
            $workflowId,
            [
                'namespace' => $namespace,
                'reason' => $validated['reason'] ?? null,
                'command_context' => $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'cancel',
                    metadata: array_filter([
                        'request_id' => $validated['request_id'] ?? null,
                        'reason' => $validated['reason'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->cancel(
            $workflowId,
            $result,
            $this->controlPlaneRunId($request),
        );
    }

    public function terminate(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        if ($response = $this->rejectProjectedV1Operation($request, $namespace, $workflowId, 'terminate')) {
            return $response;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->terminate(
            $workflowId,
            [
                'namespace' => $namespace,
                'reason' => $validated['reason'] ?? null,
                'command_context' => $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'terminate',
                    metadata: array_filter([
                        'request_id' => $validated['request_id'] ?? null,
                        'reason' => $validated['reason'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->terminate(
            $workflowId,
            $result,
            $this->controlPlaneRunId($request),
        );
    }

    public function repair(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        if ($response = $this->rejectProjectedV1Operation($request, $namespace, $workflowId, 'repair')) {
            return $response;
        }

        $validated = $request->validate([
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->repair(
            $workflowId,
            [
                'namespace' => $namespace,
                'command_context' => $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'repair',
                    metadata: array_filter([
                        'request_id' => $validated['request_id'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->repair(
            $workflowId,
            $result,
            $this->controlPlaneRunId($request),
        );
    }

    public function archive(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        if ($response = $this->rejectProjectedV1Operation($request, $namespace, $workflowId, 'archive')) {
            return $response;
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->archive(
            $workflowId,
            [
                'namespace' => $namespace,
                'reason' => $validated['reason'] ?? null,
                'command_context' => $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'archive',
                    metadata: array_filter([
                        'request_id' => $validated['request_id'] ?? null,
                        'reason' => $validated['reason'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->archive(
            $workflowId,
            $result,
            $this->controlPlaneRunId($request),
        );
    }

    // ── Run-Targeted Commands ────────────────────────────────────────
    //
    // These methods accept an explicit run ID in the URL. When the run
    // is the current run, the command is forwarded to the instance-targeted
    // package API. When the run is historical, the request is rejected
    // with a clear error so callers know the targeting scope.

    public function signalRun(Request $request, string $workflowId, string $runId, string $signalName): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'signal', $signalName, function () use ($request, $workflowId, $signalName) {
            return $this->signal($request, $workflowId, $signalName);
        });
    }

    public function queryRun(Request $request, string $workflowId, string $runId, string $queryName): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'query', $queryName, function () use ($request, $workflowId, $queryName) {
            return $this->query($request, $workflowId, $queryName);
        });
    }

    public function updateRun(Request $request, string $workflowId, string $runId, string $updateName): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'update', $updateName, function () use ($request, $workflowId, $updateName) {
            return $this->update($request, $workflowId, $updateName);
        });
    }

    public function cancelRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'cancel', null, function () use ($request, $workflowId) {
            return $this->cancel($request, $workflowId);
        });
    }

    public function terminateRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'terminate', null, function () use ($request, $workflowId) {
            return $this->terminate($request, $workflowId);
        });
    }

    public function repairRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'repair', null, function () use ($request, $workflowId) {
            return $this->repair($request, $workflowId);
        });
    }

    public function archiveRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return $this->withCurrentRunGuard($request, $workflowId, $runId, 'archive', null, function () use ($request, $workflowId) {
            return $this->archive($request, $workflowId);
        });
    }

    private function withCurrentRunGuard(
        Request $request,
        string $workflowId,
        string $runId,
        string $operation,
        ?string $operationName,
        callable $handler,
    ): JsonResponse {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        $currentRun = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        if ($currentRun === null) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Workflow not found.',
                'reason' => 'instance_not_found',
            ], 404);
        }

        if ((string) $currentRun->id !== $runId) {
            return ControlPlaneProtocol::json(
                ControlPlaneResponseContract::attach(
                    operation: $operation,
                    operationName: $operationName,
                    payload: [
                        'message' => 'Commands cannot target historical runs. Use the instance-level endpoint to command the current run, or omit the run ID.',
                        'workflow_id' => $workflowId,
                        'run_id' => $runId,
                        'reason' => 'historical_run_command_rejected',
                        'target_scope' => 'run',
                    ],
                ),
                409,
            );
        }

        $request->attributes->set('control_plane_run_id', $runId);

        try {
            return $handler();
        } finally {
            $request->attributes->remove('control_plane_run_id');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRunListEntry(WorkflowRun $run, string $namespace, ?string $currentRunId): array
    {
        $payload = [
            'run_id' => $run->id,
            'run_number' => $run->run_number,
            'workflow_type' => $run->workflow_type,
            'business_key' => $run->business_key,
            'status' => $run->status->value,
            'status_bucket' => $run->status->statusBucket()->value,
            'is_terminal' => $run->status->isTerminal(),
            'is_current_run' => (string) $run->id === (string) $currentRunId,
            'task_queue' => $run->queue,
            'compatibility' => $run->compatibility,
            'compatibility_status' => $this->compatibilityStatus($namespace, $run),
            'compatibility_supported_in_fleet' => $this->compatibilitySupportedInFleet($namespace, $run),
            'compatibility_fleet_reason' => $this->compatibilityFleetReason($namespace, $run),
            'started_at' => $run->started_at?->toJSON(),
            'closed_at' => $run->closed_at?->toJSON(),
        ];

        if (! LegacyV1Projection::isProjectedRun($run)) {
            $payload['memo'] = AvroValueJsonProjection::project($run->typedMemos());
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $description
     */
    private function formatRun(WorkflowRun $run, string $namespace, array $description = []): array
    {
        $migrationProjection = LegacyV1Projection::metadataForRun($run);
        $runDescription = is_array($description['run'] ?? null)
            ? $description['run']
            : [];
        $actions = is_array($description['actions'] ?? null)
            ? $description['actions']
            : [
                'can_signal' => false,
                'can_query' => false,
                'can_update' => false,
                'can_cancel' => false,
                'can_terminate' => false,
                'can_repair' => false,
                'can_archive' => false,
            ];
        $isCurrentRun = ($runDescription['is_current_run'] ?? null) !== false;
        $actions['can_query'] = $migrationProjection === null
            && $isCurrentRun
            && $this->canServeQuery($namespace, $run);
        if ($migrationProjection !== null) {
            foreach (array_keys($actions) as $action) {
                $actions[$action] = false;
            }
        }
        $terminalFailure = $this->terminalFailurePayload($run);
        $outputEnvelope = $run->outputEnvelope();

        $payload = [
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $namespace,
            'workflow_type' => $run->workflow_type,
            'business_key' => $description['business_key'] ?? $run->business_key,
            'status' => $run->status->value,
            'status_bucket' => $runDescription['status_bucket'] ?? $run->status->statusBucket()->value,
            'is_terminal' => $run->status->isTerminal(),
            'closed_reason' => $runDescription['closed_reason'] ?? $run->closed_reason,
            'task_queue' => $run->queue,
            'run_number' => $runDescription['run_number'] ?? (int) $run->run_number,
            'run_count' => $description['run_count'] ?? ($migrationProjection !== null ? 1 : null),
            'is_current_run' => $runDescription['is_current_run'] ?? ($migrationProjection !== null ? true : null),
            'compatibility' => $runDescription['compatibility'] ?? $run->compatibility,
            'compatibility_status' => $this->compatibilityStatus($namespace, $run),
            'compatibility_supported_in_fleet' => $this->compatibilitySupportedInFleet($namespace, $run),
            'compatibility_fleet_reason' => $this->compatibilityFleetReason($namespace, $run),
            'payload_codec' => $run->payload_codec,
            'execution_timeout_seconds' => $description['execution_timeout_seconds'] ?? null,
            'run_timeout_seconds' => $runDescription['run_timeout_seconds'] ?? null,
            'execution_deadline_at' => $runDescription['execution_deadline_at'] ?? null,
            'run_deadline_at' => $runDescription['run_deadline_at'] ?? null,
            'input' => AvroValueJsonProjection::project($this->workflowArguments($run)),
            'output' => AvroValueJsonProjection::project($run->workflowOutput()),
            'input_envelope' => $this->workerEnvelope(
                $namespace,
                $run->payload_codec,
                is_string($run->arguments) ? $run->arguments : null,
            ),
            'output_envelope' => $outputEnvelope,
            'started_at' => $run->started_at?->toJSON(),
            'closed_at' => $run->closed_at?->toJSON(),
            'last_progress_at' => $runDescription['last_progress_at'] ?? $run->last_progress_at?->toJSON(),
            'wait_kind' => $runDescription['wait_kind'] ?? null,
            'wait_reason' => $runDescription['wait_reason'] ?? null,
            'memo' => AvroValueJsonProjection::project($run->typedMemos()),
            'search_attributes' => $run->typedSearchAttributes(),
            'actions' => $actions,
            'commands_scope' => 'selected_run',
            'commands' => $this->runCommandDiagnostics($run),
            'updates_scope' => 'selected_run',
            'updates' => $this->runUpdateDiagnostics($run),
        ];

        if ($migrationProjection !== null) {
            $payload['migration_projection'] = $migrationProjection;
            $payload['action_blocked_reason'] = 'v1_projection_read_only';
            $payload['input'] = null;
            $payload['output'] = null;
        }

        if ($terminalFailure !== null) {
            $payload['error'] = $terminalFailure['message'] ?? null;
            $payload['failure'] = $terminalFailure;
            $payload['exception'] = $terminalFailure['exception'] ?? null;
            $payload['failures'] = $terminalFailure['failures'] ?? [];
        }

        return $payload;
    }

    private function rejectProjectedV1Operation(
        Request $request,
        string $namespace,
        string $workflowId,
        string $operation,
    ): ?JsonResponse {
        $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        return LegacyV1Projection::isProjectedRun($run)
            ? $this->projectedV1OperationResponse($request, $run, $operation)
            : null;
    }

    private function projectedV1OperationResponse(Request $request, WorkflowRun $run, string $operation): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => sprintf(
                'Operation [%s] is unavailable because this execution is a read-only projection owned by the source v1 runtime.',
                $operation,
            ),
            'reason' => 'v1_projection_read_only',
            'remediation' => 'Run commands against the source v1 application until it completes; repeat projection never dispatches or advances standalone execution.',
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'operation' => $operation,
            'execution_owner' => 'v1',
        ], 409);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runCommandDiagnostics(WorkflowRun $run): array
    {
        /** @var Collection<string, WorkflowUpdate> $updatesByCommandId */
        $updatesByCommandId = WorkflowUpdate::query()
            ->where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('workflow_command_id');

        return WorkflowCommand::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('command_sequence')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (WorkflowCommand $command) use ($updatesByCommandId): array {
                $update = $updatesByCommandId->get($command->id);
                $context = $command->commandContext();

                return $this->withoutNullOrEmptyArrays([
                    'id' => $command->id,
                    'sequence' => $command->command_sequence,
                    'type' => $this->enumOrString($command->command_type),
                    'target_scope' => $command->target_scope,
                    'requested_run_id' => $command->requestedRunId(),
                    'resolved_run_id' => $command->resolvedRunId(),
                    'target_name' => $command->targetName(),
                    'source' => $command->source,
                    'context' => $this->commandPublicContext($context),
                    'caller_label' => $this->contextString($context, ['caller', 'label']),
                    'principal_type' => $command->principalType(),
                    'principal_id' => $command->principalId(),
                    'principal_label' => $command->principalLabel(),
                    'principal' => $this->commandPrincipal($command),
                    'auth_status' => $this->contextString($context, ['auth', 'status']),
                    'auth_method' => $this->contextString($context, ['auth', 'method']),
                    'request_method' => $this->contextString($context, ['request', 'method']),
                    'request_path' => $this->contextString($context, ['request', 'path']),
                    'request_route_name' => $this->contextString($context, ['request', 'route_name']),
                    'request_fingerprint' => $this->contextString($context, ['request', 'fingerprint']),
                    'request_id' => $this->commandContextRequestId($context),
                    'correlation_id' => $this->contextString($context, ['request', 'correlation_id']),
                    'status' => $this->enumOrString($command->status),
                    'outcome' => $this->enumOrString($command->outcome),
                    'reason' => $command->commandReason(),
                    'rejection_reason' => $command->rejection_reason,
                    'validation_errors' => $command->validationErrors(),
                    'workflow_type' => $command->workflow_type,
                    'workflow_class' => $command->workflow_class,
                    'accepted_at' => $command->accepted_at?->toJSON(),
                    'applied_at' => $command->applied_at?->toJSON(),
                    'rejected_at' => $command->rejected_at?->toJSON(),
                    'update_id' => $update instanceof WorkflowUpdate ? $update->id : null,
                    'update_status' => $update instanceof WorkflowUpdate ? $this->enumOrString($update->status) : null,
                    'failure_id' => $update instanceof WorkflowUpdate ? $update->failure_id : null,
                    'failure_message' => $update instanceof WorkflowUpdate ? $update->failure_message : null,
                    'completed_at' => $update instanceof WorkflowUpdate ? $update->closed_at?->toJSON() : null,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runUpdateDiagnostics(WorkflowRun $run): array
    {
        /** @var Collection<string, WorkflowCommand> $commandsById */
        $commandsById = WorkflowCommand::query()
            ->where('workflow_run_id', $run->id)
            ->get()
            ->keyBy('id');

        return WorkflowUpdate::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('command_sequence')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (WorkflowUpdate $update) use ($commandsById): array {
                $command = $commandsById->get($update->workflow_command_id);

                return $this->withoutNullOrEmptyArrays([
                    'id' => $update->id,
                    'command_id' => $update->workflow_command_id,
                    'workflow_id' => $update->workflow_instance_id,
                    'run_id' => $update->workflow_run_id,
                    'target_scope' => $update->target_scope,
                    'requested_run_id' => $update->requested_workflow_run_id,
                    'resolved_run_id' => $update->resolved_workflow_run_id,
                    'update_name' => $update->update_name,
                    'status' => $this->enumOrString($update->status),
                    'outcome' => $this->enumOrString($update->outcome),
                    'command_sequence' => $update->command_sequence,
                    'workflow_sequence' => $update->workflow_sequence,
                    'rejection_reason' => $update->rejection_reason,
                    'validation_errors' => $update->validation_errors,
                    'failure_id' => $update->failure_id,
                    'failure_message' => $update->failure_message,
                    'principal' => $command instanceof WorkflowCommand ? $this->commandPrincipal($command) : null,
                    'request_id' => $command instanceof WorkflowCommand
                        ? $this->commandContextRequestId($command->commandContext())
                        : null,
                    'accepted_at' => $update->accepted_at?->toJSON(),
                    'applied_at' => $update->applied_at?->toJSON(),
                    'rejected_at' => $update->rejected_at?->toJSON(),
                    'closed_at' => $update->closed_at?->toJSON(),
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @return array{type?: string, id?: string, label?: string}|null
     */
    private function commandPrincipal(WorkflowCommand $command): ?array
    {
        $principal = array_filter([
            'type' => $command->principalType(),
            'id' => $command->principalId(),
            'label' => $command->principalLabel(),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return $principal === [] ? null : $principal;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function commandContextRequestId(array $context): ?string
    {
        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        $server = is_array($context['server'] ?? null) ? $context['server'] : [];
        $metadata = is_array($server['metadata'] ?? null) ? $server['metadata'] : [];
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        foreach ([
            $request['request_id'] ?? null,
            $metadata['request_id'] ?? null,
            $headers['x_request_id'] ?? null,
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function commandPublicContext(array $context): ?array
    {
        $payload = $this->withoutNullOrEmptyArrays([
            'caller' => $this->withoutNullOrEmptyArrays([
                'type' => $this->contextString($context, ['caller', 'type']),
                'label' => $this->contextString($context, ['caller', 'label']),
            ]),
            'auth' => $this->withoutNullOrEmptyArrays([
                'status' => $this->contextString($context, ['auth', 'status']),
                'method' => $this->contextString($context, ['auth', 'method']),
                'role' => $this->contextString($context, ['auth', 'role']),
            ]),
            'principal' => $this->withoutNullOrEmptyArrays([
                'type' => $this->contextString($context, ['principal', 'type']),
                'id' => $this->contextString($context, ['principal', 'id']),
                'label' => $this->contextString($context, ['principal', 'label']),
            ]),
        ]);

        return $payload === [] ? null : $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $path
     */
    private function contextString(array $context, array $path): ?string
    {
        $value = $context;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function enumOrString(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function workflowArguments(WorkflowRun $run): array
    {
        try {
            return $run->workflowArguments();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{codec: string, blob: string}|array{codec: string, external_storage: array<string, mixed>}|null
     */
    private function workerEnvelope(?string $namespace, ?string $codec, ?string $blob): ?array
    {
        if ($blob === null) {
            return null;
        }

        try {
            return $this->payloadEnvelopes->workerEnvelope($namespace, $codec, $blob);
        } catch (Throwable) {
            return [
                'codec' => PayloadCodecContract::canonicalize($this->nonEmptyString($codec)),
                'blob' => $blob,
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function terminalFailurePayload(WorkflowRun $run): ?array
    {
        if ($run->status !== RunStatus::Failed) {
            return null;
        }

        $failures = $this->compactFailureSnapshots(FailureSnapshots::forRun($run));
        $activityFailures = $this->activityFailureSummaries($run);

        if ($failures === [] && $activityFailures === []) {
            return null;
        }

        $terminal = $this->terminalFailureSnapshot($failures);
        $message = $this->nonEmptyString($terminal['message'] ?? null)
            ?? $this->lastActivityFailureMessage($activityFailures);
        $exception = $this->failureExceptionPayload($terminal);

        return $this->withoutNullOrEmptyArrays([
            'message' => $message,
            'reason' => $this->nonEmptyString($terminal['reason'] ?? null),
            'exception_type' => $this->nonEmptyString($terminal['exception_type'] ?? null),
            'exception_class' => $this->nonEmptyString($terminal['exception_class'] ?? null),
            'failure_category' => $this->nonEmptyString($terminal['failure_category'] ?? null),
            'non_retryable' => $terminal['non_retryable'] ?? null,
            'source_kind' => $this->nonEmptyString($terminal['source_kind'] ?? null),
            'source_id' => $this->nonEmptyString($terminal['source_id'] ?? null),
            'event_sequence' => $terminal['event_sequence'] ?? null,
            'exception' => $exception,
            'activity_failures' => $activityFailures,
            'failures' => $failures,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @return array<string, mixed>
     */
    private function terminalFailureSnapshot(array $failures): array
    {
        for ($index = count($failures) - 1; $index >= 0; $index--) {
            $failure = $failures[$index];

            if (($failure['source_kind'] ?? null) === 'workflow_run'
                || ($failure['propagation_kind'] ?? null) === 'terminal'
            ) {
                return $failure;
            }
        }

        return $failures === [] ? [] : $failures[count($failures) - 1];
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     * @return list<array<string, mixed>>
     */
    private function compactFailureSnapshots(array $failures): array
    {
        return array_values(array_map(function (array $failure): array {
            return $this->withoutNullOrEmptyArrays([
                'id' => $this->nonEmptyString($failure['id'] ?? null),
                'source_kind' => $this->nonEmptyString($failure['source_kind'] ?? null),
                'source_id' => $this->nonEmptyString($failure['source_id'] ?? null),
                'propagation_kind' => $this->nonEmptyString($failure['propagation_kind'] ?? null),
                'reason' => $this->nonEmptyString($failure['reason'] ?? null),
                'failure_category' => $this->nonEmptyString($failure['failure_category'] ?? null),
                'non_retryable' => $failure['non_retryable'] ?? null,
                'handled' => $failure['handled'] ?? null,
                'exception_type' => $this->nonEmptyString($failure['exception_type'] ?? null),
                'exception_class' => $this->nonEmptyString($failure['exception_class'] ?? null),
                'message' => $this->nonEmptyString($failure['message'] ?? null),
                'exception_payload' => $this->compactExceptionPayload($failure['exception_payload'] ?? null),
                'event_sequence' => $failure['event_sequence'] ?? null,
                'history_authority' => $this->nonEmptyString($failure['history_authority'] ?? null),
            ]);
        }, $failures));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function compactExceptionPayload(mixed $exception): ?array
    {
        if (! is_array($exception)) {
            return null;
        }

        $payload = $this->withoutNullOrEmptyArrays([
            'type' => $this->nonEmptyString($exception['type'] ?? null),
            'class' => $this->nonEmptyString($exception['__constructor'] ?? null)
                ?? $this->nonEmptyString($exception['class'] ?? null),
            'message' => $this->nonEmptyString($exception['message'] ?? null),
        ]);

        return $payload === [] ? null : $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityFailureSummaries(WorkflowRun $run): array
    {
        $run->loadMissing('historyEvents');

        return $run->historyEvents
            ->filter(static fn ($event): bool => $event instanceof WorkflowHistoryEvent
                && in_array($event->event_type, [
                    HistoryEventType::ActivityFailed,
                    HistoryEventType::ActivityTimedOut,
                ], true))
            ->map(function (WorkflowHistoryEvent $event): array {
                $payload = is_array($event->payload) ? $event->payload : [];

                return $this->withoutNullOrEmptyArrays([
                    'event_sequence' => $event->sequence,
                    'event_type' => $event->event_type->value,
                    'activity_type' => $this->nonEmptyString($payload['activity_type'] ?? null),
                    'activity_class' => $this->nonEmptyString($payload['activity_class'] ?? null),
                    'activity_execution_id' => $this->nonEmptyString($payload['activity_execution_id'] ?? null),
                    'activity_attempt_id' => $this->nonEmptyString($payload['activity_attempt_id'] ?? null),
                    'failure_id' => $this->nonEmptyString($payload['failure_id'] ?? null),
                    'failure_category' => $this->nonEmptyString($payload['failure_category'] ?? null),
                    'exception_type' => $this->nonEmptyString($payload['exception_type'] ?? null),
                    'exception_class' => $this->nonEmptyString($payload['exception_class'] ?? null),
                    'message' => $this->nonEmptyString($payload['message'] ?? null),
                    'non_retryable' => $payload['non_retryable'] ?? null,
                ]);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $activityFailures
     */
    private function lastActivityFailureMessage(array $activityFailures): ?string
    {
        for ($index = count($activityFailures) - 1; $index >= 0; $index--) {
            $message = $this->nonEmptyString($activityFailures[$index]['message'] ?? null);

            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array<string, mixed>|null
     */
    private function failureExceptionPayload(array $failure): ?array
    {
        $exception = is_array($failure['exception_payload'] ?? null)
            ? $failure['exception_payload']
            : [];

        $payload = $this->withoutNullOrEmptyArrays([
            'type' => $this->nonEmptyString($exception['type'] ?? null)
                ?? $this->nonEmptyString($failure['exception_type'] ?? null),
            'class' => $this->nonEmptyString($exception['class'] ?? null)
                ?? $this->nonEmptyString($exception['__constructor'] ?? null)
                ?? $this->nonEmptyString($failure['exception_class'] ?? null),
            'message' => $this->nonEmptyString($exception['message'] ?? null)
                ?? $this->nonEmptyString($failure['message'] ?? null),
        ]);

        return $payload === [] ? null : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutNullOrEmptyArrays(array $payload): array
    {
        return array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function signalPreviewPayload(
        string $namespace,
        string $workflowId,
        string $signalName,
        array $validated,
    ): array {
        $run = NamespaceWorkflowScope::currentRun($namespace, $workflowId);

        if (! $run instanceof WorkflowRun) {
            return $this->signalPreviewRejection(
                $workflowId,
                null,
                $namespace,
                $signalName,
                'run_not_active',
                'rejected_not_active',
                'admission',
                'Workflow signal preview cannot record a signal because the workflow has no active current run.',
                409,
            );
        }

        if (
            is_string($run->workflow_type)
            && ($message = $this->configuredWorkflowValidationMessage($run->workflow_type)) !== null
        ) {
            return $this->signalPreviewRejection(
                $workflowId,
                $run,
                $namespace,
                $signalName,
                'configured_workflow_type_invalid',
                'rejected_configured_workflow_type_invalid',
                'admission',
                $message,
                409,
                [
                    'workflow_type' => $run->workflow_type,
                    'blocked_reason' => 'configured_workflow_type_invalid',
                ],
            );
        }

        if (! $this->runIsActiveForSignal($run)) {
            return $this->signalPreviewRejection(
                $workflowId,
                $run,
                $namespace,
                $signalName,
                'run_not_active',
                'rejected_not_active',
                'admission',
                sprintf(
                    'Workflow signal [%s] cannot be recorded because run [%s] is terminal with status [%s].',
                    $signalName,
                    $run->id,
                    $run->status->value,
                ),
                409,
            );
        }

        $admission = $this->signalAdmissionForPreview($run, $signalName);

        if (($admission['allowed'] ?? false) !== true) {
            return $this->signalPreviewRejection(
                $workflowId,
                $run,
                $namespace,
                $signalName,
                'unknown_signal',
                'rejected_unknown_signal',
                'admission',
                (string) ($admission['message'] ?? 'Workflow signal is unknown.'),
                404,
                is_array($admission['payload'] ?? null) ? $admission['payload'] : [],
            );
        }

        $arguments = $this->signalPreviewArguments($namespace, $validated['input'] ?? null);
        $validatedArguments = $this->validatedSignalArgumentsForPreview($run, $signalName, $arguments);
        $validationErrors = $this->validationErrors($validatedArguments['validation_errors'] ?? null);

        if ($validationErrors !== []) {
            return $this->signalPreviewRejection(
                $workflowId,
                $run,
                $namespace,
                $signalName,
                'invalid_signal_arguments',
                'rejected_invalid_arguments',
                'validation',
                sprintf('Workflow signal [%s] arguments failed validation.', $signalName),
                422,
                [
                    'validation_errors' => $validationErrors,
                ],
            );
        }

        return [
            'workflow_id' => $workflowId,
            'run_id' => $run->id,
            'namespace' => $namespace,
            'signal_name' => $signalName,
            'outcome' => 'signal_preview',
            'command_status' => 'preview',
            'command_source' => 'control_plane',
            'dry_run' => true,
            'preview' => [
                'input_present' => array_key_exists('input', $validated),
                'request_id_present' => isset($validated['request_id']),
                'would_record_signal' => true,
            ],
            'status' => 200,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function signalPreviewRejection(
        string $workflowId,
        ?WorkflowRun $run,
        string $namespace,
        string $signalName,
        string $reason,
        string $outcome,
        string $category,
        string $message,
        int $status,
        array $extra = [],
    ): array {
        return array_filter([
            'workflow_id' => $workflowId,
            'run_id' => $run?->id,
            'namespace' => $namespace,
            'signal_name' => $signalName,
            'outcome' => $outcome,
            'command_status' => 'rejected',
            'command_source' => 'control_plane',
            'dry_run' => true,
            'reason' => $reason,
            'rejection_reason' => $reason,
            'rejection_category' => $category,
            'message' => $message,
            'preview' => [
                'would_record_signal' => false,
            ],
            ...$extra,
            'status' => $status,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function runIsActiveForSignal(WorkflowRun $run): bool
    {
        return in_array($run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true);
    }

    private function configuredWorkflowValidationMessage(string $workflowType): ?string
    {
        if (class_exists($workflowType) && is_subclass_of($workflowType, Workflow::class)) {
            return null;
        }

        $configured = config('workflows.v2.types.workflows', []);

        if (! is_array($configured) || ! array_key_exists($workflowType, $configured)) {
            return null;
        }

        $workflowClass = $configured[$workflowType];

        if (! is_string($workflowClass) || ! class_exists($workflowClass) || ! is_subclass_of(
            $workflowClass,
            Workflow::class,
        )) {
            return sprintf(
                'Configured durable workflow type [%s] points to [%s], which is not a loadable workflow class.',
                $workflowType,
                is_scalar($workflowClass) ? (string) $workflowClass : get_debug_type($workflowClass),
            );
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function signalPreviewArguments(string $namespace, mixed $input): array
    {
        if (! is_array($input) || ! $this->looksLikePayloadEnvelope($input)) {
            return AvroPayloadEnvelopeResolver::resolveToArray($input, 'input');
        }

        return AvroPayloadEnvelopeResolver::resolveToArray(
            $input,
            'input',
            $this->externalPayloadStorage->driverFor($namespace),
        );
    }

    /**
     * @param  array<mixed>  $input
     */
    private function looksLikePayloadEnvelope(array $input): bool
    {
        if ($input === [] || ! array_key_exists('codec', $input)) {
            return false;
        }

        $keys = array_keys($input);
        sort($keys);

        return $keys === ['blob', 'codec'] || $keys === ['codec', 'external_storage'];
    }

    /**
     * @return array{
     *     allowed: bool,
     *     payload?: array<string, mixed>,
     *     message?: string
     * }
     */
    private function signalAdmissionForPreview(WorkflowRun $run, string $signalName): array
    {
        $contract = RunCommandContract::forRun($run);
        $diagnostics = $this->signalContractDiagnostics($contract);

        if (in_array($signalName, $contract['signals'] ?? [], true)) {
            return [
                'allowed' => true,
                'payload' => [
                    ...$diagnostics,
                    'signal_admission' => 'declared_signal',
                ],
            ];
        }

        if (($contract['source'] ?? null) !== RunCommandContract::SOURCE_DURABLE_HISTORY) {
            try {
                $workflowClass = TypeRegistry::resolveWorkflowClass((string) $run->workflow_class, $run->workflow_type);
            } catch (LogicException) {
                return [
                    'allowed' => true,
                    'payload' => [
                        ...$diagnostics,
                        'signal_admission' => 'external_contract_unavailable',
                    ],
                ];
            }

            if (WorkflowDefinition::hasSignal($workflowClass, $signalName)) {
                return [
                    'allowed' => true,
                    'payload' => [
                        ...$diagnostics,
                        'signal_admission' => 'loadable_workflow_class',
                    ],
                ];
            }

            $diagnostics = [
                ...$diagnostics,
                'signal_admission' => 'handler_not_declared_in_loadable_class',
            ];
            $message = $this->unknownSignalPreviewMessage($run, $signalName, $diagnostics);

            return [
                'allowed' => false,
                'payload' => [
                    ...$diagnostics,
                    'reason' => 'unknown_signal',
                    'message' => $message,
                ],
                'message' => $message,
            ];
        }

        $diagnostics = [
            ...$diagnostics,
            'signal_admission' => 'handler_not_declared',
        ];
        $message = $this->unknownSignalPreviewMessage($run, $signalName, $diagnostics);

        return [
            'allowed' => false,
            'payload' => [
                ...$diagnostics,
                'reason' => 'unknown_signal',
                'message' => $message,
            ],
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{
     *     command_contract_source: string|null,
     *     command_contract_backfill_needed: bool,
     *     command_contract_backfill_available: bool,
     *     declared_signals: list<string>
     * }
     */
    private function signalContractDiagnostics(array $contract): array
    {
        $signals = $contract['signals'] ?? [];

        return [
            'command_contract_source' => is_string($contract['source'] ?? null)
                ? $contract['source']
                : null,
            'command_contract_backfill_needed' => ($contract['backfill_needed'] ?? false) === true,
            'command_contract_backfill_available' => ($contract['backfill_available'] ?? false) === true,
            'declared_signals' => is_array($signals)
                ? array_values(array_filter($signals, static fn (mixed $signal): bool => is_string($signal) && $signal !== ''))
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function unknownSignalPreviewMessage(WorkflowRun $run, string $signalName, array $diagnostics): string
    {
        $declaredSignals = $diagnostics['declared_signals'] ?? [];
        $declaredSummary = is_array($declaredSignals) && $declaredSignals !== []
            ? implode(', ', $declaredSignals)
            : 'none';
        $contractSource = is_string($diagnostics['command_contract_source'] ?? null)
            ? $diagnostics['command_contract_source']
            : RunCommandContract::SOURCE_UNAVAILABLE;
        $admission = is_string($diagnostics['signal_admission'] ?? null)
            ? $diagnostics['signal_admission']
            : 'handler_not_declared';

        $detail = $admission === 'handler_not_declared'
            ? 'the durable command contract does not declare that handler'
            : 'the server did not receive durable signal declarations and the loadable workflow class does not declare that handler';

        return sprintf(
            'Workflow signal [%s] is unknown for workflow type [%s]: %s. Command contract source [%s], declared signals [%s].',
            $signalName,
            $run->workflow_type,
            $detail,
            $contractSource,
            $declaredSummary,
        );
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function validatedSignalArgumentsForPreview(WorkflowRun $run, string $signalName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
            $contract = RunCommandContract::signalContract($run, $signalName);

            if ($contract === null) {
                try {
                    $workflowClass = TypeRegistry::resolveWorkflowClass((string) $run->workflow_class, $run->workflow_type);
                } catch (LogicException) {
                    return [
                        'arguments' => $normalized,
                        'validation_errors' => [],
                    ];
                }

                $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);
            }

            return $contract === null
                ? [
                    'arguments' => $normalized,
                    'validation_errors' => [],
                ]
                : $this->normalizePositionalCommandArguments($contract, $arguments, 'signal');
        }

        $contract = RunCommandContract::signalContract($run, $signalName);

        if ($contract !== null) {
            return $this->normalizeNamedCommandArguments($contract, $arguments);
        }

        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass((string) $run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return [
                'arguments' => [],
                'validation_errors' => [
                    'arguments' => ['Named arguments require a durable or loadable workflow signal contract.'],
                ],
            ];
        }

        $contract = WorkflowDefinition::signalContract($workflowClass, $signalName);

        return $contract === null
            ? [
                'arguments' => [$arguments],
                'validation_errors' => [],
            ]
            : $this->normalizeNamedCommandArguments($contract, $arguments);
    }

    /**
     * @param  array{name: string, parameters: list<array<string, mixed>>}  $contract
     * @param  array<int, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizePositionalCommandArguments(array $contract, array $arguments, string $commandType): array
    {
        $normalized = [];
        $errors = [];
        $providedCount = count($arguments);
        $consumed = 0;

        foreach ($contract['parameters'] as $parameter) {
            if (($parameter['variadic'] ?? false) === true) {
                while ($consumed < $providedCount) {
                    $normalized[] = $arguments[$consumed];
                    $this->appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                    $consumed++;
                }

                continue;
            }

            if ($consumed < $providedCount) {
                $normalized[] = $arguments[$consumed];
                $this->appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                $consumed++;

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$parameter['name']][] = sprintf('The %s argument is required.', $parameter['name']);
            }
        }

        if ($consumed < $providedCount) {
            $errors['arguments'][] = sprintf(
                'Too many arguments were provided for %s [%s].',
                $commandType,
                $contract['name'],
            );
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param  array{name: string, parameters: list<array<string, mixed>>}  $contract
     * @param  array<string, mixed>  $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private function normalizeNamedCommandArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $knownParameters = [];

        foreach ($contract['parameters'] as $parameter) {
            $name = $parameter['name'];
            $knownParameters[] = $name;

            if (($parameter['variadic'] ?? false) === true) {
                if (! array_key_exists($name, $arguments)) {
                    continue;
                }

                $values = $arguments[$name];

                if (is_array($values)) {
                    foreach (array_values($values) as $value) {
                        $normalized[] = $value;
                        $this->appendParameterValidationErrors($errors, $parameter, $value);
                    }
                } else {
                    $normalized[] = $values;
                    $this->appendParameterValidationErrors($errors, $parameter, $values);
                }

                continue;
            }

            if (array_key_exists($name, $arguments)) {
                $normalized[] = $arguments[$name];
                $this->appendParameterValidationErrors($errors, $parameter, $arguments[$name]);

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$name][] = sprintf('The %s argument is required.', $name);
            }
        }

        foreach (array_keys($arguments) as $name) {
            if (in_array($name, $knownParameters, true)) {
                continue;
            }

            $errors[(string) $name][] = sprintf('Unknown argument [%s].', (string) $name);
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $parameter
     */
    private function appendParameterValidationErrors(array &$errors, array $parameter, mixed $value): void
    {
        $name = is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : 'argument';

        foreach ($this->validationErrorsForParameterValue($parameter, $value) as $message) {
            $errors[$name][] = $message;
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationErrors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $errors = [];

        foreach ($value as $field => $messages) {
            if (! is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    $errors[(string) $field][] = $message;
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @return list<string>
     */
    private function validationErrorsForParameterValue(array $parameter, mixed $value): array
    {
        $name = is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : 'argument';

        if ($value === null) {
            return $this->parameterAllowsNull($parameter)
                ? []
                : [sprintf('The %s argument cannot be null.', $name)];
        }

        $type = is_string($parameter['type'] ?? null)
            ? trim($parameter['type'])
            : null;

        if ($type === null || $type === '' || $type === 'mixed') {
            return [];
        }

        if ($this->valueMatchesDeclaredType($value, $type)) {
            return [];
        }

        return [sprintf('The %s argument must be of type %s.', $name, $type)];
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function parameterAllowsNull(array $parameter): bool
    {
        if (is_bool($parameter['allows_null'] ?? null)) {
            return $parameter['allows_null'];
        }

        $type = is_string($parameter['type'] ?? null)
            ? trim($parameter['type'])
            : null;

        if ($type === null || $type === '') {
            return true;
        }

        return str_starts_with($type, '?')
            || in_array('null', $this->splitDeclaredType($type, '|'), true);
    }

    private function valueMatchesDeclaredType(mixed $value, string $type): bool
    {
        $type = trim($type);

        if ($type === '' || $type === 'mixed') {
            return true;
        }

        if (str_starts_with($type, '?')) {
            return $value === null || $this->valueMatchesDeclaredType($value, substr($type, 1));
        }

        $unionTypes = $this->splitDeclaredType($type, '|');

        if (count($unionTypes) > 1) {
            foreach ($unionTypes as $unionType) {
                if ($this->valueMatchesDeclaredType($value, $unionType)) {
                    return true;
                }
            }

            return false;
        }

        $intersectionTypes = $this->splitDeclaredType($type, '&');

        if (count($intersectionTypes) > 1) {
            foreach ($intersectionTypes as $intersectionType) {
                if (! $this->valueMatchesDeclaredType($value, $intersectionType)) {
                    return false;
                }
            }

            return true;
        }

        $type = trim($type, "() \t\n\r\0\x0B");

        return match ($type) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            'scalar' => is_scalar($value),
            'true' => $value === true,
            'false' => $value === false,
            'null' => $value === null,
            'mixed' => true,
            'never', 'void' => false,
            'self', 'static', 'parent' => is_object($value),
            default => is_object($value)
                && (
                    ! class_exists($type)
                    && ! interface_exists($type)
                    && ! enum_exists($type)
                    || $value instanceof $type
                ),
        };
    }

    /**
     * @return list<string>
     */
    private function splitDeclaredType(string $type, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($index = 0, $length = strlen($type); $index < $length; $index++) {
            $character = $type[$index];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
            }

            if ($character === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return $parts;
    }

    private function compatibilityStatus(string $namespace, WorkflowRun $run): string
    {
        if ($run->compatibility === null || $this->compatibilitySupportedInFleet($namespace, $run)) {
            return 'compatible';
        }

        return 'no_compatible_worker';
    }

    private function compatibilitySupportedInFleet(string $namespace, WorkflowRun $run): bool
    {
        if ($run->compatibility === null) {
            return true;
        }

        foreach (WorkerCompatibilityFleet::detailsForNamespace($namespace, $run->compatibility, $run->connection, $run->queue) as $worker) {
            if (($worker['supports_required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function compatibilityFleetReason(string $namespace, WorkflowRun $run): ?string
    {
        if ($run->compatibility === null || $this->compatibilitySupportedInFleet($namespace, $run)) {
            return null;
        }

        $advertised = [];
        foreach (WorkerCompatibilityFleet::detailsForNamespace($namespace, $run->compatibility, $run->connection, $run->queue) as $worker) {
            foreach (($worker['supported'] ?? []) as $marker) {
                if (is_string($marker) && trim($marker) !== '') {
                    $advertised[trim($marker)] = true;
                }
            }
        }
        ksort($advertised);

        $suffix = $advertised === []
            ? ''
            : ' Active workers there advertise ['.implode(', ', array_keys($advertised)).'].';

        return sprintf(
            'No active worker heartbeat for task queue [%s] advertises compatibility [%s].%s',
            $run->queue ?? 'default',
            $run->compatibility,
            $suffix,
        );
    }

    private function summaryCompatibilityStatus(string $namespace, mixed $summary): string
    {
        $compatibility = $this->nonEmptyString($summary->compatibility ?? null);

        if ($compatibility === null || $this->summaryCompatibilitySupportedInFleet($namespace, $summary)) {
            return 'compatible';
        }

        return 'no_compatible_worker';
    }

    private function summaryCompatibilitySupportedInFleet(string $namespace, mixed $summary): bool
    {
        $compatibility = $this->nonEmptyString($summary->compatibility ?? null);

        if ($compatibility === null) {
            return true;
        }

        $connection = $this->nonEmptyString($summary->connection ?? null);
        $queue = $this->nonEmptyString($summary->queue ?? null);

        foreach (WorkerCompatibilityFleet::detailsForNamespace($namespace, $compatibility, $connection, $queue) as $worker) {
            if (($worker['supports_required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function summaryCompatibilityFleetReason(string $namespace, mixed $summary): ?string
    {
        $compatibility = $this->nonEmptyString($summary->compatibility ?? null);

        if ($compatibility === null || $this->summaryCompatibilitySupportedInFleet($namespace, $summary)) {
            return null;
        }

        $queue = $this->nonEmptyString($summary->queue ?? null);

        return sprintf(
            'No active worker heartbeat for task queue [%s] advertises compatibility [%s].',
            $queue ?? 'default',
            $compatibility,
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function decodePageToken(?string $token): ?int
    {
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $decoded = base64_decode($token, true);

        if (! is_string($decoded) || ! ctype_digit($decoded)) {
            return null;
        }

        return (int) $decoded;
    }

    private function encodePageToken(int $offset): string
    {
        return base64_encode((string) $offset);
    }

    private function controlPlaneRunId(Request $request): ?string
    {
        $runId = $request->attributes->get('control_plane_run_id');

        return is_string($runId) && trim($runId) !== ''
            ? $runId
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withAuthenticatedPrincipal(Request $request, array $payload): array
    {
        if (isset($payload['principal']) && is_array($payload['principal'])) {
            return $payload;
        }

        $principal = Authenticate::principal($request);

        if (! $principal instanceof Principal || trim($principal->subject()) === '') {
            return $payload;
        }

        $method = trim($principal->method());
        $type = $method === '' || $method === 'none'
            ? 'server'
            : 'auth:'.$method;

        $payload['principal'] = array_filter([
            'type' => $type,
            'id' => trim($principal->subject()),
            'label' => $this->principalLabel($principal),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        return $payload;
    }

    private function principalLabel(Principal $principal): ?string
    {
        $claims = $principal->claims();

        foreach (['display_name', 'name', 'email'] as $claim) {
            $value = $claims[$claim] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $role = $principal->primaryRole();

        return is_string($role) && trim($role) !== ''
            ? ucfirst(trim($role))
            : null;
    }

    private function runNotFound(Request $request, string $workflowId, string $runId): JsonResponse
    {
        return ControlPlaneProtocol::jsonForRequest($request, [
            'message' => 'Workflow run not found.',
            'reason' => 'run_not_found',
            'workflow_id' => $workflowId,
            'run_id' => $runId,
        ], 404);
    }

    private function startStatusCode(?string $outcome): int
    {
        return match ($outcome) {
            'started_new' => 201,
            'returned_existing_active' => 200,
            default => 409,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function startRejectionPayload(
        ?string $workflowId,
        string $reason,
        string $outcome,
        string $message,
        array $extra = [],
    ): array {
        return array_filter([
            'workflow_id' => $workflowId,
            'command_status' => 'rejected',
            'command_source' => 'control_plane',
            'outcome' => $outcome,
            'reason' => $reason,
            'rejection_reason' => $reason,
            'message' => $message,
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function workflowIdReservedElsewhere(string $namespace, string $workflowId): bool
    {
        return WorkflowInstance::query()
            ->whereKey($workflowId)
            ->whereNotNull('namespace')
            ->where('namespace', '!=', $namespace)
            ->exists();
    }

    private function rejectLegacyUpdateFields(Request $request): void
    {
        if (array_key_exists('wait_policy', $request->all())) {
            throw ValidationException::withMessages([
                'wait_policy' => ['The wait_policy field is no longer supported. Use wait_for.'],
            ]);
        }
    }

    /**
     * Fast-fail oversize or malformed signal/update/query names at the
     * request boundary so the control plane never dispatches a command
     * row the downstream path would later reject.
     */
    private function validateOperationName(string $name, string $kind): void
    {
        $max = (int) config('server.limits.max_operation_name_length', 256);
        $field = $kind.'_name';
        $bytes = strlen($name);

        if ($bytes === 0) {
            throw ValidationException::withMessages([
                $field => [sprintf('The %s name must not be empty.', $kind)],
            ]);
        }

        if ($max > 0 && $bytes > $max) {
            throw ValidationException::withMessages([
                $field => [sprintf(
                    'The %s name exceeds the maximum length of %d bytes.',
                    $kind,
                    $max,
                )],
            ]);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw ValidationException::withMessages([
                $field => [sprintf(
                    'The %s name must not contain control characters.',
                    $kind,
                )],
            ]);
        }
    }
}
