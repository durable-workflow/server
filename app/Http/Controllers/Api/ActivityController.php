<?php

namespace App\Http\Controllers\Api;

use App\Support\ControlPlaneProtocol;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\TaskQueueRoutingGate;
use App\Support\WorkflowCommandContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StandaloneActivity\StandaloneActivityHostType;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\PayloadEnvelopeResolver;
use Workflow\V2\Support\StandaloneActivityStartService;

/**
 * HTTP surface for standalone activities — activities run as top-level
 * durable jobs rather than as steps inside an authored Workflow.
 *
 * The same Activity class/function used inside a Workflow is reusable
 * here without rewriting: the server records the activity inside a
 * server-managed host run that anchors the durable retry, deadline, and
 * history machinery, so dispatch, completion, and observability surfaces
 * continue to work unchanged.
 */
class ActivityController
{
    public function __construct(
        private readonly StandaloneActivityStartService $startService,
        private readonly TaskQueueRoutingGate $taskQueueRoutingGate,
        private readonly WorkflowCommandContextFactory $commandContexts,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
    ) {}

    public function start(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validator = Validator::make($request->all(), [
            'activity_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'activity_type' => ['required', 'string', 'max:255'],
            'activity_class' => ['nullable', 'string', 'max:255'],
            'task_queue' => ['nullable', 'string', 'max:255'],
            'input' => ['nullable', 'array'],
            'business_key' => ['nullable', 'string', 'max:255'],
            'retry_policy' => ['nullable', 'array'],
            'retry_policy.max_attempts' => ['nullable', 'integer', 'min:1'],
            'retry_policy.backoff_seconds' => ['nullable', 'array'],
            'retry_policy.backoff_seconds.*' => ['integer', 'min:0'],
            'retry_policy.non_retryable_error_types' => ['nullable', 'array'],
            'retry_policy.non_retryable_error_types.*' => ['string'],
            'start_to_close_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'schedule_to_start_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'schedule_to_close_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'heartbeat_timeout_seconds' => ['nullable', 'integer', 'min:1'],
        ]);

        $validated = $validator->validate();

        $activityId = $validated['activity_id'] ?? null;

        if ($activityId !== null && $this->activityIdReservedElsewhere($namespace, $activityId)) {
            return ControlPlaneProtocol::jsonForRequest(
                $request,
                [
                    'message' => sprintf(
                        'Identifier [%s] is already reserved in another namespace.',
                        $activityId,
                    ),
                    'reason' => 'activity_id_reserved_in_namespace',
                    'activity_id' => $activityId,
                    'command_status' => 'rejected',
                    'rejection_reason' => 'activity_id_reserved_in_namespace',
                ],
                409,
            );
        }

        $taskQueue = isset($validated['task_queue']) && trim((string) $validated['task_queue']) !== ''
            ? trim((string) $validated['task_queue'])
            : $this->defaultTaskQueue();

        $routingBlock = $this->taskQueueRoutingGate->workflowStartBlock((string) $namespace, $taskQueue);

        if ($routingBlock !== null) {
            return ControlPlaneProtocol::jsonForRequest(
                $request,
                [
                    'message' => sprintf(
                        'Task queue [%s] is draining and cannot accept new standalone activity starts until an active worker cohort is available.',
                        $taskQueue,
                    ),
                    'reason' => 'task_queue_draining',
                    'activity_type' => $validated['activity_type'],
                    'task_queue' => $taskQueue,
                    'routing_status' => $routingBlock['routing_status'],
                    'active_worker_count' => $routingBlock['active_worker_count'],
                    'draining_worker_count' => $routingBlock['draining_worker_count'],
                    'stale_worker_count' => $routingBlock['stale_worker_count'],
                    'draining_build_ids' => $routingBlock['draining_build_ids'],
                    'drain_intent' => 'draining',
                    'command_status' => 'rejected',
                    'rejection_reason' => 'task_queue_draining',
                ],
                409,
            );
        }

        $defaultCodec = CodecRegistry::defaultCodec();

        $commandContext = $this->commandContexts->make(
            $request,
            workflowId: $activityId ?? 'pending',
            commandName: 'standalone_activity_start',
            metadata: array_filter([
                'activity_type' => $validated['activity_type'],
                'task_queue' => $taskQueue,
            ], static fn (mixed $value): bool => $value !== null),
        );

        try {
            $envelope = PayloadEnvelopeResolver::resolve(
                $validated['input'] ?? null,
                'input',
                $this->externalPayloadStorage->driverFor($namespace),
            );
            $payloadCodec = $envelope['codec'] ?? $defaultCodec;
            $arguments = $envelope['blob'] ?? null;

            if (is_string($arguments)) {
                $arguments = ExternalPayloads::externalizeForNamespace($arguments, $payloadCodec, $namespace);
            }

            $start = $this->startService->start([
                'namespace' => $namespace,
                'activity_id' => $activityId,
                'activity_type' => $validated['activity_type'],
                'activity_class' => $validated['activity_class'] ?? null,
                'task_queue' => $taskQueue,
                'arguments' => $arguments,
                'payload_codec' => $payloadCodec,
                'business_key' => $validated['business_key'] ?? null,
                'retry_policy' => $validated['retry_policy'] ?? null,
                'start_to_close_timeout_seconds' => $validated['start_to_close_timeout_seconds'] ?? null,
                'schedule_to_start_timeout_seconds' => $validated['schedule_to_start_timeout_seconds'] ?? null,
                'schedule_to_close_timeout_seconds' => $validated['schedule_to_close_timeout_seconds'] ?? null,
                'heartbeat_timeout_seconds' => $validated['heartbeat_timeout_seconds'] ?? null,
                'command_context' => $commandContext,
            ]);
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return ControlPlaneProtocol::jsonForRequest(
                $request,
                [
                    'message' => $exception->getMessage(),
                    'reason' => 'external_payload_storage_unavailable',
                    'activity_type' => $validated['activity_type'],
                    'task_queue' => $taskQueue,
                    'command_status' => 'rejected',
                    'rejection_reason' => 'external_payload_storage_unavailable',
                    'command_source' => 'control_plane',
                ],
                503,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'activity_id' => [$exception->getMessage()],
            ]);
        }

        NamespaceWorkflowScope::bind(
            $namespace,
            $start['activity_id'],
            $start['workflow_type'],
        );

        return ControlPlaneProtocol::jsonForRequest($request, [
            'activity_id' => $start['activity_id'],
            'activity_execution_id' => $start['activity_execution_id'],
            'workflow_id' => $start['activity_id'],
            'workflow_run_id' => $start['workflow_run_id'],
            'workflow_type' => $start['workflow_type'],
            'activity_type' => $start['activity_type'],
            'activity_class' => $start['activity_class'],
            'task_queue' => $start['task_queue'],
            'namespace' => $namespace,
            'status' => $start['status'],
            'payload_codec' => $start['payload_codec'],
            'started_at' => $start['started_at'],
            'schedule_to_start_deadline_at' => $start['schedule_to_start_deadline_at'],
            'schedule_to_close_deadline_at' => $start['schedule_to_close_deadline_at'],
            'command_status' => $start['started'] ? 'accepted' : 'rejected',
            'command_source' => 'control_plane',
        ], $start['started'] ? 201 : 409);
    }

    public function show(Request $request, string $activityId): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $run = NamespaceWorkflowScope::currentRun($namespace, $activityId);

        if (! $run instanceof WorkflowRun || ! StandaloneActivityHostType::isHostRun($run)) {
            return ControlPlaneProtocol::jsonForRequest($request, [
                'message' => 'Standalone activity not found.',
                'reason' => 'activity_not_found',
            ], 404);
        }

        return ControlPlaneProtocol::jsonForRequest($request, $this->formatActivity($run, $namespace));
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = ControlPlaneProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $query = $request->validate([
            'status' => ['nullable', 'string', 'in:running,completed,failed'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:200'],
            'next_page_token' => ['nullable', 'string'],
        ]);

        $pageSize = $query['page_size'] ?? 50;
        $offset = $this->decodePageToken($query['next_page_token'] ?? null) ?? 0;

        $rows = NamespaceWorkflowScope::runSummaryQuery($namespace)
            ->where('workflow_run_summaries.workflow_type', StandaloneActivityHostType::WORKFLOW_TYPE)
            ->when(
                isset($query['status']),
                static fn ($builder) => $builder->where('workflow_run_summaries.status_bucket', $query['status']),
            )
            ->orderByDesc('workflow_run_summaries.sort_timestamp')
            ->orderByDesc('workflow_run_summaries.id')
            ->offset($offset)
            ->limit($pageSize + 1)
            ->get();

        $hasMore = $rows->count() > $pageSize;
        $page = $hasMore ? $rows->slice(0, $pageSize)->values() : $rows->values();

        return ControlPlaneProtocol::jsonForRequest($request, [
            'activities' => $page->map(function ($summary): array {
                /** @var ActivityExecution|null $execution */
                $execution = ActivityExecution::query()
                    ->where('workflow_run_id', $summary->id)
                    ->orderBy('sequence')
                    ->first();

                return [
                    'activity_id' => $summary->workflow_instance_id,
                    'workflow_run_id' => $summary->id,
                    'activity_type' => $execution?->activity_type,
                    'activity_class' => $execution?->activity_class,
                    'task_queue' => $summary->queue,
                    'status' => $summary->status,
                    'status_bucket' => $summary->status_bucket,
                    'business_key' => $summary->business_key,
                    'started_at' => $summary->started_at?->toJSON(),
                    'closed_at' => $summary->closed_at?->toJSON(),
                ];
            })->all(),
            'activity_count' => $page->count(),
            'next_page_token' => $hasMore ? $this->encodePageToken($offset + $pageSize) : null,
        ]);
    }

    private function activityIdReservedElsewhere(string $namespace, string $activityId): bool
    {
        $existing = NamespaceWorkflowScope::namespaceForWorkflow($activityId);

        return $existing !== null && $existing !== $namespace;
    }

    private function formatActivity(WorkflowRun $run, ?string $namespace): array
    {
        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->first();

        $resultEnvelope = null;

        if ($execution instanceof ActivityExecution
            && $execution->status === ActivityStatus::Completed
            && is_string($execution->result)
        ) {
            $resultEnvelope = $this->payloadEnvelopes->workerEnvelope(
                $namespace,
                $execution->payload_codec ?? CodecRegistry::defaultCodec(),
                $execution->result,
            );
        }

        return [
            'activity_id' => $run->workflow_instance_id,
            'workflow_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'workflow_type' => $run->workflow_type,
            'namespace' => $namespace,
            'activity_type' => $execution?->activity_type,
            'activity_class' => $execution?->activity_class,
            'activity_execution_id' => $execution?->id,
            'activity_status' => $execution?->status?->value,
            'attempt_count' => $execution?->attempt_count,
            'task_queue' => $run->queue,
            'business_key' => $run->business_key,
            'status' => $run->status->value,
            'closed_reason' => $run->closed_reason,
            'started_at' => $run->started_at?->toJSON(),
            'closed_at' => $run->closed_at?->toJSON(),
            'last_progress_at' => $run->last_progress_at?->toJSON(),
            'compatibility' => $run->compatibility,
            'payload_codec' => $run->payload_codec,
            'schedule_to_start_deadline_at' => $execution?->schedule_deadline_at?->toJSON(),
            'schedule_to_close_deadline_at' => $execution?->schedule_to_close_deadline_at?->toJSON(),
            'last_heartbeat_at' => $execution?->last_heartbeat_at?->toJSON(),
            'result' => $resultEnvelope,
        ];
    }

    private function encodePageToken(int $offset): string
    {
        return base64_encode((string) $offset);
    }

    private function decodePageToken(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        $decoded = base64_decode($token, true);

        if ($decoded === false || ! ctype_digit($decoded)) {
            return null;
        }

        return (int) $decoded;
    }

    private function defaultTaskQueue(): string
    {
        $connection = config('queue.default');

        if (! is_string($connection) || trim($connection) === '') {
            return 'default';
        }

        $queue = config('queue.connections.'.trim($connection).'.queue', 'default');

        return is_string($queue) && trim($queue) !== ''
            ? trim($queue)
            : 'default';
    }
}
