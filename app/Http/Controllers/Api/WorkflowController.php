<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowNamespaceWorkflow;
use App\Support\ControlPlaneProtocol;
use App\Support\ControlPlaneResponseContract;
use App\Support\ControlPlaneResultMapper;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkflowCommandContextFactory;
use App\Support\WorkflowStartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowRun;

class WorkflowController
{
    public function __construct(
        private readonly WorkflowStartService $workflowStartService,
        private readonly WorkflowControlPlane $workflowControlPlane,
        private readonly WorkflowCommandContextFactory $commandContexts,
        private readonly ControlPlaneResultMapper $resultMapper,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $query = $request->validate([
            'workflow_type' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:running,completed,failed'],
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
            ->when(
                isset($query['status']),
                static fn ($builder) => $builder->where('workflow_run_summaries.status_bucket', $query['status']),
            )
            ->when(isset($query['query']), function ($builder) use ($query) {
                $term = trim((string) $query['query']);

                if ($term === '') {
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

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflows' => $page->map(static fn ($summary) => [
                'workflow_id' => $summary->workflow_instance_id,
                'run_id' => $summary->id,
                'workflow_type' => $summary->workflow_type,
                'business_key' => $summary->business_key,
                'status' => $summary->status,
                'status_bucket' => $summary->status_bucket,
                'task_queue' => $summary->queue,
                'started_at' => $summary->started_at?->toJSON(),
                'closed_at' => $summary->closed_at?->toJSON(),
                'search_attributes' => $summary->search_attributes ?? [],
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
            'business_key' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'array'],
            'search_attributes' => ['nullable', 'array'],
            'duplicate_policy' => ['nullable', 'string', 'in:fail,use-existing'],
        ], [
            'duplicate_policy.in' => 'The duplicate_policy field only supports fail or use-existing.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            foreach ([
                'workflow_execution_timeout' => 'The workflow_execution_timeout field is not supported by the v2 workflow start API.',
                'workflow_run_timeout' => 'The workflow_run_timeout field is not supported by the v2 workflow start API.',
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

        $workflowId = $validated['workflow_id'] ?? null;

        if ($workflowId !== null && $this->workflowIdReservedElsewhere($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'workflow_id' => $workflowId,
                'message' => sprintf(
                    'Workflow [%s] is already reserved in another namespace.',
                    $workflowId,
                ),
                'reason' => 'workflow_id_reserved_in_namespace',
            ], 409);
        }

        try {
            $start = $this->workflowStartService->start($validated);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'workflow_type' => [$exception->getMessage()],
            ]);
        }

        $workflowId = $start['workflow_id'];

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
            'outcome' => $start['outcome'],
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
            return ControlPlaneProtocol::jsonForRequest($request, ['message' => 'Workflow not found.'], 404);
        }

        return ControlPlaneProtocol::jsonForRequest($request, $this->formatRun(
            $run,
            $namespace,
            $this->workflowControlPlane->describe($workflowId),
        ));
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

        $runs = NamespaceWorkflowScope::runQuery($namespace, $workflowId)
            ->orderBy('workflow_runs.run_number')
            ->get();

        return ControlPlaneProtocol::jsonForRequest($request, [
            'workflow_id' => $workflowId,
            'run_count' => $runs->count(),
            'runs' => $runs->map(fn (WorkflowRun $run) => [
                'run_id' => $run->id,
                'run_number' => $run->run_number,
                'workflow_type' => $run->workflow_type,
                'business_key' => $run->business_key,
                'status' => $run->status->value,
                'task_queue' => $run->queue,
                'started_at' => $run->started_at?->toJSON(),
                'closed_at' => $run->closed_at?->toJSON(),
            ])->all(),
        ]);
    }

    public function showRun(Request $request, string $workflowId, string $runId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, ['message' => 'Workflow run not found.'], 404);
        }

        $run = NamespaceWorkflowScope::run($namespace, $workflowId, $runId);

        if (! $run) {
            return ControlPlaneProtocol::jsonForRequest($request, ['message' => 'Workflow run not found.'], 404);
        }

        return ControlPlaneProtocol::jsonForRequest($request, $this->formatRun(
            $run,
            $namespace,
            $this->workflowControlPlane->describe($workflowId, ['run_id' => $runId]),
        ));
    }

    public function signal(Request $request, string $workflowId, string $signalName): JsonResponse
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

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->signal(
            $workflowId,
            $signalName,
            [
                'arguments' => $validated['input'] ?? [],
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

        return $this->resultMapper->signal($workflowId, $signalName, $result);
    }

    public function query(Request $request, string $workflowId, string $queryName): JsonResponse
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

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
        ]);

        $result = $this->workflowControlPlane->query(
            $workflowId,
            $queryName,
            [
                'arguments' => $validated['input'] ?? [],
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->query($workflowId, $queryName, $result);
    }

    public function update(Request $request, string $workflowId, string $updateName): JsonResponse
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

        $validated = $request->validate([
            'input' => ['nullable', 'array'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'wait_for' => ['nullable', 'string', 'in:accepted,completed'],
            'wait_timeout_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->rejectLegacyUpdateFields($request);

        $result = $this->workflowControlPlane->update(
            $workflowId,
            $updateName,
            [
                'arguments' => $validated['input'] ?? [],
                'command_context' => $this->commandContexts->make(
                    $request,
                    workflowId: $workflowId,
                    commandName: 'update',
                    metadata: array_filter([
                        'request_id' => $validated['request_id'] ?? null,
                        'update_name' => $updateName,
                        'wait_for' => $validated['wait_for'] ?? 'accepted',
                    ], static fn (mixed $value): bool => $value !== null),
                ),
                'wait_for' => $validated['wait_for'] ?? 'accepted',
                'wait_timeout_seconds' => $validated['wait_timeout_seconds'] ?? null,
                'strict_configured_type_validation' => true,
            ],
        );

        return $this->resultMapper->update(
            workflowId: $workflowId,
            updateName: $updateName,
            waitFor: $validated['wait_for'] ?? 'accepted',
            result: $result,
        );
    }

    public function cancel(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, ['message' => 'Workflow not found.'], 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->cancel(
            $workflowId,
            [
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

        return $this->resultMapper->cancel($workflowId, $result);
    }

    public function terminate(Request $request, string $workflowId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        if (! NamespaceWorkflowScope::workflowBound($namespace, $workflowId)) {
            return ControlPlaneProtocol::jsonForRequest($request, ['message' => 'Workflow not found.'], 404);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->workflowControlPlane->terminate(
            $workflowId,
            [
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

        return $this->resultMapper->terminate($workflowId, $result);
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

        return $handler();
    }

    /**
     * @param  array<string, mixed>  $description
     */
    private function formatRun(WorkflowRun $run, string $namespace, array $description = []): array
    {
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
            ];

        return [
            'workflow_id' => $run->workflow_instance_id,
            'run_id' => $run->id,
            'namespace' => $namespace,
            'workflow_type' => $run->workflow_type,
            'business_key' => $description['business_key'] ?? $run->business_key,
            'status' => $run->status->value,
            'status_bucket' => $runDescription['status_bucket'] ?? null,
            'closed_reason' => $runDescription['closed_reason'] ?? null,
            'task_queue' => $run->queue,
            'run_number' => $runDescription['run_number'] ?? (int) $run->run_number,
            'run_count' => $description['run_count'] ?? null,
            'is_current_run' => $runDescription['is_current_run'] ?? null,
            'compatibility' => $runDescription['compatibility'] ?? $run->compatibility,
            'input' => $run->workflowArguments(),
            'output' => $run->workflowOutput(),
            'started_at' => $run->started_at?->toJSON(),
            'closed_at' => $run->closed_at?->toJSON(),
            'last_progress_at' => $runDescription['last_progress_at'] ?? $run->last_progress_at?->toJSON(),
            'wait_kind' => $runDescription['wait_kind'] ?? null,
            'wait_reason' => $runDescription['wait_reason'] ?? null,
            'memo' => $run->memo ?? [],
            'search_attributes' => $run->search_attributes ?? [],
            'actions' => $actions,
        ];
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

    private function startStatusCode(?string $outcome): int
    {
        return match ($outcome) {
            'started_new' => 201,
            'returned_existing_active' => 200,
            default => 409,
        };
    }

    private function workflowIdReservedElsewhere(string $namespace, string $workflowId): bool
    {
        return WorkflowNamespaceWorkflow::query()
            ->where('workflow_instance_id', $workflowId)
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
}
