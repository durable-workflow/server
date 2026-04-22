<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\ActivityTaskPoller;
use App\Support\ExternalExecutorConfigContract;
use App\Support\NamespaceWorkflowScope;
use App\Support\WorkerProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ActivityTaskBridge as ActivityTaskBridgeContract;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Support\PayloadEnvelopeResolver;

class ActivityTaskController
{
    public function __construct(
        private readonly ActivityTaskPoller $activityTaskPoller,
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
        ]);

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

        $claim = $this->activityTaskPoller->poll(
            namespace: $namespace,
            taskQueue: $validated['task_queue'],
            leaseOwner: $validated['worker_id'],
            buildId: $registeredBuildId,
            supportedActivityTypes: $this->nonEmptyStringArray($worker->supported_activity_types),
        );

        return WorkerProtocol::json([
            'task' => $claim === null ? null : array_filter([
                'task_id' => $claim['task_id'],
                'workflow_id' => $claim['workflow_instance_id'],
                'run_id' => $claim['workflow_run_id'],
                'activity_execution_id' => $claim['activity_execution_id'],
                'activity_attempt_id' => $claim['activity_attempt_id'],
                'attempt_number' => $claim['attempt_number'],
                'activity_type' => $claim['activity_type'],
                'payload_codec' => $claim['payload_codec'],
                'arguments' => $claim['arguments'] !== null
                    ? ['codec' => $claim['payload_codec'] ?? CodecRegistry::defaultCodec(), 'blob' => $claim['arguments']]
                    : null,
                'retry_policy' => $claim['retry_policy'],
                'task_queue' => $claim['queue'],
                'connection' => $claim['connection'],
                'lease_owner' => $claim['lease_owner'],
                'lease_expires_at' => $claim['lease_expires_at'],
                'deadlines' => $this->executionDeadlines($claim['activity_execution_id'] ?? null),
                'external_executor' => ExternalExecutorConfigContract::resolveActivityMapping(
                    (string) $claim['queue'],
                    (string) $claim['activity_type'],
                ),
            ], static fn (mixed $v): bool => $v !== null),
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
        $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
            $validated['result'] ?? null,
            'result',
        );
        $outcome = $bridge->complete(
            $validated['activity_attempt_id'],
            $resolved['payload'],
            $resolved['codec'],
        );
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
        $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
            $validated['failure']['details'] ?? null,
            'failure.details',
        );
        $failure = $validated['failure'];

        if (array_key_exists('details', $failure)) {
            $failure['details'] = $resolved['payload'];

            if ($resolved['codec'] !== null) {
                $failure['details_payload_codec'] = $resolved['codec'];
            }
        }

        $outcome = $bridge->fail($validated['activity_attempt_id'], $failure, $resolved['codec']);
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
        $status = $bridge->heartbeat(
            $validated['activity_attempt_id'],
            $this->heartbeatProgress($validated),
        );

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

        return $worker;
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
        $status = $bridge->status($attemptId);

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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
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
            'schedule_to_start' => $execution->schedule_deadline_at?->toIso8601String(),
            'start_to_close' => $execution->close_deadline_at?->toIso8601String(),
            'schedule_to_close' => $execution->schedule_to_close_deadline_at?->toIso8601String(),
            'heartbeat' => $execution->heartbeat_deadline_at?->toIso8601String(),
        ], static fn (mixed $v): bool => $v !== null);

        return $deadlines !== [] ? $deadlines : null;
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
