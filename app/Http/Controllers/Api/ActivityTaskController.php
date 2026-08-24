<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\ActivityHeartbeatRecorder;
use App\Support\ActivityTaskPoller;
use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\BackendLockPressure;
use App\Support\ExternalExecutorConfigContract;
use App\Support\ExternalPayloadEnvelopeService;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\InvocableCarrierContract;
use App\Support\LongPollCapacityExhaustedException;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\RuntimeExternalPayloadAudit;
use App\Support\RuntimeExternalPayloadException;
use App\Support\WorkerPollBackpressure;
use App\Support\WorkerProtocol;
use App\Support\WorkerProtocolMutationRetrier;
use App\Support\WorkerSessionRegistry;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Support\WorkerProtocolVersion;

class ActivityTaskController
{
    public function __construct(
        private readonly ActivityTaskPoller $activityTaskPoller,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly ExternalPayloadEnvelopeService $payloadEnvelopes,
        private readonly WorkerSessionRegistry $workerSessions,
        private readonly WorkerProtocolMutationRetrier $storageMutations,
        private readonly ActivityHeartbeatRecorder $activityHeartbeats,
    ) {}

    /**
     * Long-poll for available activity tasks.
     *
     * Workers poll for activity tasks on a specific task queue. The server
     * holds the connection until a task is available or timeout expires.
     */
    public function poll(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'task_queue' => ['required', 'string'],
            'build_id' => ['nullable', 'string'],
            'poll_request_id' => ['nullable', 'string', 'max:255'],
            'timeout_seconds' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
            ],
        ]);
        $timeoutSeconds = isset($validated['timeout_seconds'])
            ? (int) $validated['timeout_seconds']
            : null;

        $worker = $this->resolveRegisteredWorker(
            $namespace,
            $validated['worker_id'],
            $validated['task_queue'],
            $validated['build_id'] ?? null,
        );

        if ($worker instanceof JsonResponse) {
            return $worker;
        }

        // Derive build-id from the registration record (the authoritative
        // source for compatibility routing) rather than from the poll-time
        // request parameter.
        $registeredBuildId = is_string($worker->build_id) && $worker->build_id !== ''
            ? $worker->build_id
            : null;

        $supportedActivityTypes = $this->nonEmptyStringArray($worker->supported_activity_types);

        // Registered capabilities are authoritative for routing: a worker
        // that did not advertise any activity types at register time is
        // not an activity worker, so the server must never deliver
        // activity tasks to it — even if it shares a task queue with
        // workers that do handle activity tasks.
        if ($supportedActivityTypes === []) {
            return WorkerProtocol::json([
                'task' => null,
                'poll_status' => 'no_activity_capability',
            ]);
        }

        try {
            $poll = $this->activityTaskPoller->poll(
                namespace: $namespace,
                taskQueue: $validated['task_queue'],
                leaseOwner: $validated['worker_id'],
                buildId: $registeredBuildId,
                worker: $worker,
                pollRequestId: $validated['poll_request_id'] ?? null,
                supportedActivityTypes: $supportedActivityTypes,
                workerSessionsAvailable: WorkerProtocol::workerSessionsAvailableForRequest(
                    $request,
                ),
                timeoutSeconds: $timeoutSeconds,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof InvalidArgumentException
                && str_contains($exception->getMessage(), 'unsupported_payload_codec:')) {
                return WorkerProtocol::json([
                    'task' => null,
                    'poll_status' => 'rejected',
                    'reason' => 'unsupported_payload_codec',
                    'error' => $exception->getMessage(),
                ], 422);
            }

            if ($exception instanceof LongPollCapacityExhaustedException) {
                return WorkerPollBackpressure::response(
                    'activity_task',
                    $namespace,
                    $validated['task_queue'],
                    $exception,
                );
            }

            if (BackendLockPressure::is($exception)) {
                return BackendLockPressure::workerPollResponse(
                    'activity_task',
                    $namespace,
                    $validated['task_queue'],
                );
            }

            throw $exception;
        }
        $claim = $poll['task'] ?? null;

        if (is_array($claim)) {
            try {
                PayloadCodecContract::canonicalize(is_string($claim['payload_codec'] ?? null) ? $claim['payload_codec'] : null);
            } catch (InvalidArgumentException $exception) {
                return WorkerProtocol::json([
                    'task' => null,
                    'poll_status' => 'rejected',
                    'reason' => 'unsupported_payload_codec',
                    'error' => $exception->getMessage(),
                ], 422);
            }
        }

        $deadlines = $claim === null ? null : $this->executionDeadlines($claim['activity_execution_id'] ?? null);
        $externalExecutor = $claim === null ? null : $this->externalExecutorMapping(
            (string) $claim['queue'],
            (string) $claim['activity_type'],
            (string) $claim['task_id'],
            (string) $claim['activity_attempt_id'],
            $deadlines,
        );

        return WorkerProtocol::json([
            'task' => $claim === null ? null : array_filter([
                'task_id' => $claim['task_id'],
                'workflow_id' => $claim['workflow_instance_id'],
                'run_id' => $claim['workflow_run_id'],
                'activity_execution_id' => $claim['activity_execution_id'],
                'activity_attempt_id' => $claim['activity_attempt_id'],
                'idempotency_key' => $claim['idempotency_key'] ?? $claim['activity_execution_id'],
                'attempt_number' => $claim['attempt_number'],
                'activity_type' => $claim['activity_type'],
                'payload_codec' => $claim['payload_codec'],
                'arguments' => $claim['arguments'] !== null
                    ? $this->payloadEnvelopes->workerEnvelope(
                        $namespace,
                        PayloadCodecContract::canonicalize($claim['payload_codec'] ?? null),
                        $claim['arguments'],
                    )
                    : null,
                'retry_policy' => $claim['retry_policy'],
                'task_queue' => $claim['queue'],
                'connection' => $claim['connection'],
                'lease_owner' => $claim['lease_owner'],
                'lease_expires_at' => $claim['lease_expires_at'],
                'deadlines' => $deadlines,
                'worker_session' => $this->workerSessions->workerSessionForExecution(
                    is_string($claim['activity_execution_id'] ?? null)
                        ? $claim['activity_execution_id']
                        : null,
                ),
                'external_executor' => $externalExecutor,
            ], static fn (mixed $v): bool => $v !== null),
            'poll_status' => is_string($poll['poll_status'] ?? null)
                ? $poll['poll_status']
                : ($claim === null ? 'empty' : 'leased'),
        ]);
    }

    /**
     * Complete an activity task with a result.
     */
    public function complete(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'activity_attempt_id' => ['required', 'string'],
            'lease_owner' => ['required', 'string'],
            'result' => ['nullable'],
        ]);

        if ($response = $this->guardAttemptOwnership(
            $namespace,
            $taskId,
            $validated['activity_attempt_id'],
            $validated['lease_owner'],
        )) {
            return $response;
        }

        /** @var ActivityTaskBridgeContract $bridge */
        $bridge = app(ActivityTaskBridgeContract::class);
        try {
            $resolved = AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                $validated['result'] ?? null,
                'result',
                $this->externalPayloadStorage->driverFor($namespace),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ExternalPayloadIntegrityException $exception) {
            return $this->externalPayloadFailure($taskId, $validated['activity_attempt_id'], $exception, 422);
        } catch (\Throwable $exception) {
            return $this->externalPayloadFailure($taskId, $validated['activity_attempt_id'], $exception, 503);
        }
        try {
            $outcome = $this->storageMutations->run(
                static fn (): array => $bridge->complete(
                    $validated['activity_attempt_id'],
                    $resolved['payload'],
                    $resolved['codec'],
                ),
            );
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return $this->externalPayloadFailure($taskId, $validated['activity_attempt_id'], $exception, 503);
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }
        $stopStatus = $this->activityStopStatus($bridge, $validated['activity_attempt_id'], $outcome['reason']);

        return WorkerProtocol::json(array_merge([
            'task_id' => $taskId,
            'activity_attempt_id' => $validated['activity_attempt_id'],
            'outcome' => $this->activityOutcomeName('completed', $outcome['reason']),
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
            'next_task_id' => $outcome['next_task_id'],
        ], $stopStatus), $this->outcomeStatus($outcome['reason']));
    }

    /**
     * Report an activity task failure.
     */
    public function fail(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'activity_attempt_id' => ['required', 'string'],
            'lease_owner' => ['required', 'string'],
            'failure' => ['required', 'array'],
            'failure.message' => ['required', 'string'],
            'failure.type' => ['nullable', 'string'],
            'failure.class' => ['nullable', 'string'],
            'failure.code' => ['nullable', 'integer'],
            'failure.stack_trace' => ['nullable', 'string'],
            'failure.non_retryable' => ['nullable', 'boolean'],
            'failure.retryable' => ['nullable', 'boolean'],
            'failure.kind' => [
                'nullable',
                'string',
                Rule::in([
                    'application',
                    'timeout',
                    'cancellation',
                    'malformed_output',
                    'handler_crash',
                    'decode_failure',
                    'unsupported_payload',
                ]),
            ],
            'failure.timeout_type' => [
                'nullable',
                'string',
                Rule::in([
                    'schedule_to_start',
                    'start_to_close',
                    'schedule_to_close',
                    'heartbeat',
                    'deadline_exceeded',
                ]),
            ],
            'failure.cancelled' => ['nullable', 'boolean'],
            'failure.malformed_output' => ['nullable', 'boolean'],
            'failure.details' => ['nullable'],
            'failure.diagnostics' => ['nullable', 'array'],
            'failure.runtime_diagnostics' => ['nullable', 'array'],
        ]);

        if ($response = $this->guardAttemptOwnership(
            $namespace,
            $taskId,
            $validated['activity_attempt_id'],
            $validated['lease_owner'],
        )) {
            return $response;
        }

        /** @var ActivityTaskBridgeContract $bridge */
        $bridge = app(ActivityTaskBridgeContract::class);
        try {
            $resolved = AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                $validated['failure']['details'] ?? null,
                'failure.details',
                $this->externalPayloadStorage->driverFor($namespace),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ExternalPayloadIntegrityException $exception) {
            return $this->externalPayloadFailure($taskId, $validated['activity_attempt_id'], $exception, 422);
        } catch (\Throwable $exception) {
            return $this->externalPayloadFailure($taskId, $validated['activity_attempt_id'], $exception, 503);
        }
        $failure = $validated['failure'];

        if (array_key_exists('details', $failure)) {
            $failure['details'] = $resolved['payload'];

            if ($resolved['codec'] !== null) {
                $failure['details_payload_codec'] = $resolved['codec'];
            }
        }

        try {
            $outcome = $this->storageMutations->run(
                fn (): array => $bridge->fail($validated['activity_attempt_id'], $failure, $resolved['codec']),
            );
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return $this->externalPayloadFailure($taskId, $validated['activity_attempt_id'], $exception, 503);
        } catch (InvalidArgumentException $exception) {
            if (str_contains($exception->getMessage(), 'unsupported_payload_codec:')) {
                return WorkerProtocol::json([
                    'task_id' => $taskId,
                    'activity_attempt_id' => $validated['activity_attempt_id'],
                    'recorded' => false,
                    'reason' => 'unsupported_payload_codec',
                    'error' => $exception->getMessage(),
                ], 422);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }
        $stopStatus = $this->activityStopStatus($bridge, $validated['activity_attempt_id'], $outcome['reason']);

        return WorkerProtocol::json(array_merge([
            'task_id' => $taskId,
            'activity_attempt_id' => $validated['activity_attempt_id'],
            'outcome' => $this->activityOutcomeName('failed', $outcome['reason']),
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
            'next_task_id' => $outcome['next_task_id'],
        ], $stopStatus), $this->outcomeStatus($outcome['reason']));
    }

    /**
     * Heartbeat an in-progress activity task.
     *
     * Extends the activity attempt lease and records liveness metadata.
     * May return a cancellation indicator if the workflow requested cancel.
     */
    public function heartbeat(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'activity_attempt_id' => ['required', 'string'],
            'lease_owner' => ['required', 'string'],
            'message' => ['nullable', 'string'],
            'current' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string'],
            'details' => ['nullable', 'array'],
        ]);

        try {
            if ($response = $this->guardAttemptOwnership(
                $namespace,
                $taskId,
                $validated['activity_attempt_id'],
                $validated['lease_owner'],
            )) {
                return $response;
            }
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::activityTaskHeartbeatResponse(
                $taskId,
                $validated['activity_attempt_id'],
                $validated['lease_owner'],
            );
        }

        /** @var ActivityTaskBridgeContract $bridge */
        $bridge = app(ActivityTaskBridgeContract::class);

        try {
            $workerSession = $this->storageMutations->run(
                fn (): ?array => $this->workerSessions->heartbeatForAttempt(
                    $namespace,
                    $validated['activity_attempt_id'],
                    $validated['lease_owner'],
                ),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::activityTaskHeartbeatResponse(
                $taskId,
                $validated['activity_attempt_id'],
                $validated['lease_owner'],
            );
        }

        if ($workerSession !== null && ($workerSession['admitted'] ?? false) !== true) {
            $status = $bridge->status($validated['activity_attempt_id']);

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'activity_attempt_id' => $validated['activity_attempt_id'],
                'lease_owner' => $status['lease_owner'] ?? $validated['lease_owner'],
                'cancel_requested' => (bool) ($status['cancel_requested'] ?? false),
                'can_continue' => false,
                'reason' => $workerSession['reason'] ?? 'worker_session_renewal_failed',
                'run_closed_reason' => $status['run_closed_reason'] ?? null,
                'run_closed_at' => $status['run_closed_at'] ?? null,
                'heartbeat_recorded' => false,
                'lease_expires_at' => $status['lease_expires_at'] ?? null,
                'last_heartbeat_at' => $status['last_heartbeat_at'] ?? null,
                'error' => $workerSession['error'] ?? 'Worker session renewal failed.',
                'worker_session' => $this->workerSessionSnapshotFromRenewal($workerSession),
                'worker_session_renewal' => $this->workerSessionRenewalStatusPayload($workerSession),
            ], $this->workerSessionRenewalHttpStatus($workerSession));
        }

        try {
            $status = $this->storageMutations->run(
                fn (): array => $this->activityHeartbeats->record(
                    $bridge,
                    $validated['activity_attempt_id'],
                    $this->heartbeatProgress($validated),
                ),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::activityTaskHeartbeatResponse(
                $taskId,
                $validated['activity_attempt_id'],
                $validated['lease_owner'],
            );
        }

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'activity_attempt_id' => $validated['activity_attempt_id'],
            'lease_owner' => $status['lease_owner'],
            'cancel_requested' => $status['cancel_requested'],
            'can_continue' => $status['can_continue'],
            'reason' => $status['reason'],
            'run_closed_reason' => $status['run_closed_reason'] ?? null,
            'run_closed_at' => $status['run_closed_at'] ?? null,
            'heartbeat_recorded' => $status['heartbeat_recorded'],
            'lease_expires_at' => $status['lease_expires_at'],
            'last_heartbeat_at' => $status['last_heartbeat_at'],
            'worker_session' => $workerSession['session'] ?? null,
        ], ($status['reason'] ?? null) === 'attempt_not_found' ? 404 : 200);
    }

    private function resolveRegisteredWorker(
        string $namespace,
        string $workerId,
        string $taskQueue,
        ?string $buildId = null,
    ): WorkerRegistration|JsonResponse {
        $worker = WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace)
            ->first();

        if (! $worker) {
            return WorkerProtocol::json([
                'error' => 'Worker must be registered before polling. Call POST /worker/register first.',
                'reason' => 'worker_not_registered',
                'worker_id' => $workerId,
            ], 412);
        }

        if ($worker->task_queue !== $taskQueue) {
            return WorkerProtocol::json([
                'error' => sprintf(
                    'Worker [%s] is registered for task queue [%s], not [%s].',
                    $workerId,
                    $worker->task_queue,
                    $taskQueue,
                ),
                'reason' => 'task_queue_mismatch',
                'worker_id' => $workerId,
                'registered_task_queue' => $worker->task_queue,
                'requested_task_queue' => $taskQueue,
            ], 409);
        }

        $registeredBuildId = is_string($worker->build_id) && $worker->build_id !== ''
            ? $worker->build_id
            : null;

        if ($registeredBuildId !== null && $buildId !== null && $buildId !== $registeredBuildId) {
            return WorkerProtocol::json([
                'error' => sprintf(
                    'Worker [%s] is registered with build_id [%s], but poll requested build_id [%s]. Re-register to update.',
                    $workerId,
                    $registeredBuildId,
                    $buildId,
                ),
                'reason' => 'build_id_mismatch',
                'worker_id' => $workerId,
                'registered_build_id' => $registeredBuildId,
                'requested_build_id' => $buildId,
            ], 409);
        }

        if ($worker->status === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
            return $this->drainingWorkerPollResponse($workerId, $taskQueue, $registeredBuildId);
        }

        return $worker;
    }

    private function externalPayloadFailure(
        string $taskId,
        string $activityAttemptId,
        \Throwable $exception,
        int $status,
    ): JsonResponse {
        if ($exception instanceof RuntimeExternalPayloadException) {
            app(RuntimeExternalPayloadAudit::class)->record(request(), 'external_payload.rejected', [
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ]);

            return WorkerProtocol::json([
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'task_id' => $taskId,
                'activity_attempt_id' => $activityAttemptId,
                'outcome' => 'rejected',
                'recorded' => false,
                'reason' => $exception->reason,
                'error' => $exception->getMessage(),
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ], $exception->status);
        }

        $integrityFailure = $status === 422;

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'activity_attempt_id' => $activityAttemptId,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => $integrityFailure
                ? 'external_payload_integrity_failed'
                : 'external_payload_storage_unavailable',
            'error' => $exception->getMessage(),
        ], $status);
    }

    /**
     * @return list<string>
     */
    private function nonEmptyStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return $result;
    }

    private function drainingWorkerPollResponse(
        string $workerId,
        string $taskQueue,
        ?string $registeredBuildId,
    ): JsonResponse {
        return WorkerProtocol::json([
            'task' => null,
            'poll_status' => 'draining',
            'error' => sprintf(
                'Worker [%s] is marked draining for task queue [%s] and cannot claim new tasks until the cohort is resumed.',
                $workerId,
                $taskQueue,
            ),
            'reason' => 'worker_draining',
            'worker_id' => $workerId,
            'task_queue' => $taskQueue,
            'registered_build_id' => $registeredBuildId,
            'worker_status' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
            'drain_intent' => WorkerBuildIdRollout::DRAIN_INTENT_DRAINING,
        ], 409);
    }

    private function guardAttemptOwnership(
        string $namespace,
        string $taskId,
        string $attemptId,
        string $leaseOwner,
    ): ?JsonResponse {
        $task = NamespaceWorkflowScope::task($namespace, $taskId);

        if (! $task) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'activity_attempt_id' => $attemptId,
                'error' => 'Activity task not found.',
                'reason' => 'task_not_found',
            ], 404);
        }

        /** @var ActivityTaskBridgeContract $bridge */
        $bridge = app(ActivityTaskBridgeContract::class);
        $status = $this->storageMutations->run(
            static fn (): array => $bridge->status($attemptId),
        );

        if (($status['reason'] ?? null) === 'attempt_not_found') {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'activity_attempt_id' => $attemptId,
                'error' => 'Activity attempt not found.',
                'reason' => 'attempt_not_found',
            ], 404);
        }

        if (($status['workflow_task_id'] ?? null) !== $task->id) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'activity_attempt_id' => $attemptId,
                'error' => 'Activity attempt is not leased for this task.',
                'reason' => 'task_mismatch',
            ], 409);
        }

        if (($status['lease_owner'] ?? null) !== $leaseOwner) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'activity_attempt_id' => $attemptId,
                'error' => 'Activity attempt lease is owned by another worker.',
                'reason' => 'lease_owner_mismatch',
                'lease_owner' => $status['lease_owner'],
            ], 409);
        }

        return null;
    }

    private function outcomeStatus(?string $reason): int
    {
        return match ($reason) {
            null => 200,
            'attempt_not_found' => 404,
            default => 409,
        };
    }

    private function activityOutcomeName(string $default, ?string $reason): string
    {
        return in_array($reason, ['run_cancelled', 'run_terminated'], true)
            ? 'ignored'
            : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function activityStopStatus(
        ActivityTaskBridgeContract $bridge,
        string $attemptId,
        ?string $reason,
    ): array {
        if (! in_array($reason, ['run_cancelled', 'run_terminated'], true)) {
            return [];
        }

        $status = $bridge->status($attemptId);

        return [
            'error' => 'Activity outcome ignored because the workflow run is already closed.',
            'cancel_requested' => $status['cancel_requested'],
            'can_continue' => $status['can_continue'],
            'run_status' => $status['run_status'],
            'run_closed_reason' => $status['run_closed_reason'] ?? null,
            'run_closed_at' => $status['run_closed_at'] ?? null,
            'activity_status' => $status['activity_status'],
            'attempt_status' => $status['attempt_status'],
            'task_status' => $status['task_status'],
            'lease_owner' => $status['lease_owner'],
            'lease_expires_at' => $status['lease_expires_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $workerSession
     * @return array<string, mixed>
     */
    private function workerSessionRenewalStatusPayload(array $workerSession): array
    {
        return array_filter([
            'admitted' => $workerSession['admitted'] ?? false,
            'outcome' => $workerSession['outcome'] ?? null,
            'reason' => $workerSession['reason'] ?? null,
            'error' => $workerSession['error'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $workerSession
     * @return array<string, mixed>|null
     */
    private function workerSessionSnapshotFromRenewal(array $workerSession): ?array
    {
        if (is_array($workerSession['session'] ?? null)) {
            return $workerSession['session'];
        }

        $excluded = [
            'admitted',
            'outcome',
            'error',
            'reason',
            'activity_task_id',
        ];

        if (! is_string($workerSession['status'] ?? null)) {
            $excluded[] = 'status';
        }

        $snapshot = array_diff_key($workerSession, array_flip($excluded));

        return $snapshot === [] ? null : $snapshot;
    }

    /**
     * @param  array<string, mixed>  $workerSession
     */
    private function workerSessionRenewalHttpStatus(array $workerSession): int
    {
        return is_int($workerSession['status'] ?? null)
            ? $workerSession['status']
            : 409;
    }

    /**
     * @return array<string, string>|null
     */
    private function executionDeadlines(?string $executionId): ?array
    {
        if ($executionId === null || $executionId === '') {
            return null;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()->find($executionId);

        if (! $execution) {
            return null;
        }

        $deadlines = array_filter([
            'schedule_to_start' => $this->deadlineTimestamp($execution->schedule_deadline_at),
            'start_to_close' => $this->deadlineTimestamp($execution->close_deadline_at),
            'schedule_to_close' => $this->deadlineTimestamp($execution->schedule_to_close_deadline_at),
            'heartbeat' => $this->deadlineTimestamp($execution->heartbeat_deadline_at),
        ], static fn (mixed $v): bool => $v !== null);

        return $deadlines !== [] ? $deadlines : null;
    }

    private function deadlineTimestamp(?CarbonInterface $deadline): ?string
    {
        return $deadline?->copy()
            ->setTimezone('UTC')
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param  array<string, string>|null  $deadlines
     * @return array<string, mixed>|null
     */
    private function externalExecutorMapping(
        string $taskQueue,
        string $activityType,
        string $taskId,
        string $activityAttemptId,
        ?array $deadlines,
    ): ?array {
        $mapping = ExternalExecutorConfigContract::resolveActivityMapping($taskQueue, $activityType);
        if ($mapping === null) {
            return null;
        }

        $carrier = is_array($mapping['carrier'] ?? null) ? $mapping['carrier'] : [];
        if (($carrier['type'] ?? null) !== InvocableCarrierContract::CARRIER_TYPE) {
            return $mapping;
        }

        $target = is_array($carrier['target'] ?? null) ? $carrier['target'] : [];
        $contract = InvocableCarrierContract::manifest();

        $mapping['dispatch'] = array_filter([
            'state' => 'poll_delivered',
            'carrier_type' => InvocableCarrierContract::CARRIER_TYPE,
            'method' => 'POST',
            'request_content_type' => $contract['request']['content_type'],
            'response_content_type' => $contract['response']['content_type'],
            'timeout_seconds' => is_int($target['timeout_seconds'] ?? null) ? $target['timeout_seconds'] : null,
            'task_deadline_fields' => $deadlines === null ? [] : array_keys($deadlines),
            'idempotency_key' => $activityAttemptId,
            'idempotency_key_source' => 'task.activity_attempt_id',
            'retry_authority' => $contract['rollout_safety']['retry_authority'],
            'transport_retry_policy' => $this->invocableTransportRetryPolicy($target['retry_policy'] ?? null),
            'failure_mapping' => $contract['failure_mapping'],
            'result_reporting' => [
                'complete_path' => "/api/worker/activity-tasks/{$taskId}/complete",
                'fail_path' => "/api/worker/activity-tasks/{$taskId}/fail",
                'ownership_fields' => ['activity_attempt_id', 'lease_owner'],
            ],
        ], static fn (mixed $value): bool => $value !== null);

        return $mapping;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invocableTransportRetryPolicy(mixed $policy): ?array
    {
        if (! is_array($policy)) {
            return null;
        }

        return array_filter([
            'max_attempts' => is_int($policy['max_attempts'] ?? null) ? $policy['max_attempts'] : 1,
            'backoff_seconds' => is_array($policy['backoff_seconds'] ?? null)
                ? array_values(array_filter(
                    $policy['backoff_seconds'],
                    static fn (mixed $seconds): bool => is_int($seconds),
                ))
                : [],
            'retryable_status_codes' => is_array($policy['retryable_status_codes'] ?? null)
                ? array_values(array_filter(
                    $policy['retryable_status_codes'],
                    static fn (mixed $statusCode): bool => is_int($statusCode),
                ))
                : [408, 429, 500, 502, 503, 504],
            'authority' => 'carrier_transport_only',
            'durable_retry_boundary' => 'activity_retry_policy_after_result_reporting',
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function heartbeatProgress(array $validated): array
    {
        $progress = [];

        foreach (['message', 'current', 'total', 'unit'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                $progress[$field] = $validated[$field];
            }
        }

        if (array_key_exists('details', $validated) && is_array($validated['details'])) {
            $progress['details'] = $validated['details'];
        }

        return $progress;
    }
}
