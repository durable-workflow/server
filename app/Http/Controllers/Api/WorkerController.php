<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Models\WorkflowTaskProtocolLease;
use App\Support\StandaloneWorkerFleet;
use App\Support\WorkflowTaskLeaseRecovery;
use App\Support\WorkerProtocol;
use App\Support\WorkflowTaskLeaseRegistry;
use App\Support\WorkflowTaskPoller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\WorkflowTaskBridge;

class WorkerController
{
    public function __construct(
        private readonly WorkflowTaskPoller $workflowTaskPoller,
        private readonly WorkflowTaskLeaseRegistry $workflowTaskLeases,
        private readonly WorkflowTaskLeaseRecovery $workflowTaskLeaseRecovery,
        private readonly StandaloneWorkerFleet $workerFleet,
    ) {}

    /**
     * Register a worker with the server.
     *
     * Workers advertise their identity, runtime, supported workflow and activity
     * types, compatibility markers, and task queue. The server uses this for task
     * routing and fleet visibility.
     */
    public function register(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'worker_id' => ['nullable', 'string', 'max:255'],
            'task_queue' => ['required', 'string', 'max:255'],
            'runtime' => ['required', 'string', 'in:php,python,typescript,go,java'],
            'sdk_version' => ['nullable', 'string', 'max:64'],
            'build_id' => ['nullable', 'string', 'max:255'],
            'supported_workflow_types' => ['nullable', 'array'],
            'supported_workflow_types.*' => ['string'],
            'supported_activity_types' => ['nullable', 'array'],
            'supported_activity_types.*' => ['string'],
            'max_concurrent_workflow_tasks' => ['nullable', 'integer', 'min:1'],
            'max_concurrent_activity_tasks' => ['nullable', 'integer', 'min:1'],
        ]);

        $workerId = $validated['worker_id'] ?? Str::ulid()->toBase32();

        WorkerRegistration::updateOrCreate(
            [
                'worker_id' => $workerId,
                'namespace' => $namespace,
            ],
            [
                'task_queue' => $validated['task_queue'],
                'runtime' => $validated['runtime'],
                'sdk_version' => $validated['sdk_version'] ?? null,
                'build_id' => $validated['build_id'] ?? null,
                'supported_workflow_types' => $validated['supported_workflow_types'] ?? [],
                'supported_activity_types' => $validated['supported_activity_types'] ?? [],
                'max_concurrent_workflow_tasks' => $validated['max_concurrent_workflow_tasks'] ?? 100,
                'max_concurrent_activity_tasks' => $validated['max_concurrent_activity_tasks'] ?? 100,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ]
        );

        $this->workerFleet->record(
            namespace: $namespace,
            workerId: $workerId,
            taskQueue: $validated['task_queue'],
            buildId: $validated['build_id'] ?? null,
        );

        return WorkerProtocol::json([
            'worker_id' => $workerId,
            'registered' => true,
        ], 201);
    }

    /**
     * Worker heartbeat to maintain liveness.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
        ]);

        $namespace = $request->attributes->get('namespace');

        $worker = WorkerRegistration::query()
            ->where('worker_id', $validated['worker_id'])
            ->where('namespace', $namespace)
            ->first();

        if (! $worker) {
            return WorkerProtocol::json(['error' => 'Worker not registered.'], 404);
        }

        $worker->update([
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        $this->workerFleet->record(
            namespace: $worker->namespace,
            workerId: $worker->worker_id,
            taskQueue: $worker->task_queue,
            buildId: is_string($worker->build_id) ? $worker->build_id : null,
        );

        return WorkerProtocol::json([
            'worker_id' => $worker->worker_id,
            'acknowledged' => true,
        ]);
    }

    /**
     * Long-poll for available workflow tasks.
     *
     * The server holds the connection open until a workflow task is available
     * or the poll timeout expires. Returns the leased task with history needed
     * for replay plus a server-side lease attempt counter for fencing.
     */
    public function pollWorkflowTasks(Request $request): JsonResponse
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
        ]);

        $task = $this->workflowTaskPoller->poll(
            request: $request,
            namespace: $namespace,
            taskQueue: $validated['task_queue'],
            leaseOwner: $validated['worker_id'],
            buildId: $validated['build_id'] ?? null,
            pollRequestId: $validated['poll_request_id'] ?? null,
        );

        return WorkerProtocol::json([
            'task' => $task,
        ]);
    }

    /**
     * Complete a claimed workflow task with commands emitted by an external worker.
     */
    public function completeWorkflowTask(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
            'commands' => ['required', 'array', 'min:1'],
            'commands.*.type' => ['required', 'string'],
            'commands.*.result' => ['nullable'],
            'commands.*.activity_type' => ['nullable', 'string'],
            'commands.*.arguments' => ['nullable', 'string'],
            'commands.*.connection' => ['nullable', 'string'],
            'commands.*.queue' => ['nullable', 'string'],
            'commands.*.workflow_type' => ['nullable', 'string'],
            'commands.*.delay_seconds' => ['nullable', 'integer', 'min:0'],
            'commands.*.message' => ['nullable', 'string'],
            'commands.*.exception_class' => ['nullable', 'string'],
            'commands.*.exception_type' => ['nullable', 'string'],
        ]);

        if ($response = $this->guardWorkflowTaskOwnership(
            $request,
            $namespace,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $validated['lease_owner'],
        )) {
            return $response;
        }

        $commands = $this->normalizeWorkflowCommands($validated['commands']);

        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        $outcome = $bridge->complete($taskId, $commands);
        $this->reconcileWorkflowTaskLease($taskId, $outcome['reason'] ?? null, clearOnSuccess: true);

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'outcome' => 'completed',
            'recorded' => $outcome['completed'],
            'run_id' => $outcome['workflow_run_id'],
            'run_status' => $outcome['run_status'],
            'reason' => $outcome['reason'],
        ], $this->workflowOutcomeStatus($outcome['reason']));
    }

    /**
     * Heartbeat a claimed workflow task to extend its lease.
     */
    public function heartbeatWorkflowTask(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
        ]);

        if ($response = $this->guardWorkflowTaskOwnership(
            $request,
            $namespace,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $validated['lease_owner'],
        )) {
            return $response;
        }

        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        $status = $bridge->heartbeat($taskId);

        if (($status['renewed'] ?? false) === true) {
            $this->workflowTaskLeases->renewLease(
                namespace: $namespace,
                taskId: $taskId,
                leaseOwner: $validated['lease_owner'],
                workflowTaskAttempt: (int) $validated['workflow_task_attempt'],
                leaseExpiresAt: $status['lease_expires_at'] ?? null,
            );
        } else {
            $this->reconcileWorkflowTaskLease($taskId, $status['reason'] ?? null);
        }

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'lease_owner' => $validated['lease_owner'],
            'renewed' => $status['renewed'],
            'lease_expires_at' => $status['lease_expires_at'],
            'run_status' => $status['run_status'],
            'task_status' => $status['task_status'],
            'reason' => $status['reason'],
        ], $this->workflowOutcomeStatus($status['reason']));
    }

    /**
     * Report a workflow task failure (replay/command error, not workflow failure).
     */
    public function failWorkflowTask(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
            'failure' => ['required', 'array'],
            'failure.message' => ['required', 'string'],
            'failure.type' => ['nullable', 'string'],
            'failure.stack_trace' => ['nullable', 'string'],
        ]);

        if ($response = $this->guardWorkflowTaskOwnership(
            $request,
            $namespace,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $validated['lease_owner'],
        )) {
            return $response;
        }

        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        $outcome = $bridge->fail($taskId, $validated['failure']);
        $this->reconcileWorkflowTaskLease($taskId, $outcome['reason'] ?? null, clearOnSuccess: true);

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'outcome' => 'failed',
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
        ], $this->workflowOutcomeStatus($outcome['reason']));
    }

    private function guardWorkflowTaskOwnership(
        Request $request,
        string $namespace,
        string $taskId,
        int $workflowTaskAttempt,
        string $leaseOwner,
    ): ?JsonResponse {
        $lease = $this->workflowTaskLeases->ownershipLease(
            namespace: $namespace,
            taskId: $taskId,
            expectedLeaseOwner: $leaseOwner,
            workflowTaskAttempt: $workflowTaskAttempt,
        );

        if (! $lease instanceof WorkflowTaskProtocolLease) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task not found.',
                'reason' => 'task_not_found',
            ], 404);
        }

        if (! $lease->hasActiveLease()) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task is not currently leased.',
                'reason' => 'task_not_leased',
            ], 409);
        }

        if ($lease->lease_expires_at !== null && $lease->lease_expires_at->lte(now())) {
            $this->workflowTaskLeaseRecovery->recoverExpiredLease($request, $namespace, $lease);

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease has expired and is waiting for recovery.',
                'reason' => 'lease_expired',
                'task_status' => 'leased',
                'lease_owner' => $lease->lease_owner,
                'lease_expires_at' => $lease->lease_expires_at?->toJSON(),
            ], 409);
        }

        if ((string) $lease->lease_owner !== $leaseOwner) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease is owned by another worker.',
                'reason' => 'lease_owner_mismatch',
                'lease_owner' => $lease->lease_owner,
            ], 409);
        }

        if ((int) $lease->workflow_task_attempt !== $workflowTaskAttempt) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease attempt does not match the current claim.',
                'reason' => 'workflow_task_attempt_mismatch',
                'current_attempt' => (int) $lease->workflow_task_attempt,
            ], 409);
        }

        return null;
    }

    private function reconcileWorkflowTaskLease(string $taskId, ?string $reason, bool $clearOnSuccess = false): void
    {
        if ($clearOnSuccess && $reason === null) {
            $this->workflowTaskLeases->clearActiveLease($taskId);

            return;
        }

        if (in_array($reason, [
            'run_already_closed',
            'run_closed',
            'task_not_active',
            'task_not_found',
            'task_not_leased',
            'task_not_workflow',
        ], true)) {
            $this->workflowTaskLeases->clearActiveLease($taskId);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function normalizeWorkflowCommands(array $commands): array
    {
        $normalized = [];
        $errors = [];

        foreach ($commands as $index => $command) {
            $type = is_array($command) ? ($command['type'] ?? null) : null;

            if (! is_string($type)) {
                $errors["commands.{$index}.type"] = ['Each command must declare a supported type.'];

                continue;
            }

            if ($type === 'complete_workflow') {
                $normalized[] = [
                    'type' => $type,
                    'result' => $command['result'] ?? null,
                ];

                continue;
            }

            if ($type === 'fail_workflow') {
                if (! is_string($command['message'] ?? null) || trim((string) $command['message']) === '') {
                    $errors["commands.{$index}.message"] = [
                        'Fail workflow commands require a non-empty message.',
                    ];

                    continue;
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'message' => $command['message'],
                    'exception_class' => is_string($command['exception_class'] ?? null)
                        ? $command['exception_class']
                        : null,
                    'exception_type' => is_string($command['exception_type'] ?? null)
                        ? $command['exception_type']
                        : null,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'schedule_activity') {
                if (! is_string($command['activity_type'] ?? null) || trim((string) $command['activity_type']) === '') {
                    $errors["commands.{$index}.activity_type"] = [
                        'Schedule activity commands require a non-empty activity_type.',
                    ];

                    continue;
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'activity_type' => trim($command['activity_type']),
                    'arguments' => $this->optionalCommandString($command, 'arguments', $index, $errors),
                    'connection' => $this->optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => $this->optionalCommandString($command, 'queue', $index, $errors),
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'start_timer') {
                if (! is_int($command['delay_seconds'] ?? null) || (int) $command['delay_seconds'] < 0) {
                    $errors["commands.{$index}.delay_seconds"] = [
                        'Start timer commands require a non-negative integer delay_seconds.',
                    ];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'delay_seconds' => (int) $command['delay_seconds'],
                ];

                continue;
            }

            if ($type === 'start_child_workflow') {
                if (! is_string($command['workflow_type'] ?? null) || trim((string) $command['workflow_type']) === '') {
                    $errors["commands.{$index}.workflow_type"] = [
                        'Start child workflow commands require a non-empty workflow_type.',
                    ];

                    continue;
                }

                $normalized[] = array_filter([
                    'type' => $type,
                    'workflow_type' => trim($command['workflow_type']),
                    'arguments' => $this->optionalCommandString($command, 'arguments', $index, $errors),
                    'connection' => $this->optionalCommandString($command, 'connection', $index, $errors),
                    'queue' => $this->optionalCommandString($command, 'queue', $index, $errors),
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            if ($type === 'continue_as_new') {
                $workflowType = $this->optionalCommandString($command, 'workflow_type', $index, $errors);

                $normalized[] = array_filter([
                    'type' => $type,
                    'arguments' => $this->optionalCommandString($command, 'arguments', $index, $errors),
                    'workflow_type' => $workflowType,
                ], static fn (mixed $value): bool => $value !== null);

                continue;
            }

            $errors["commands.{$index}.type"] = [
                sprintf('Workflow task command type [%s] is not supported by the server yet.', $type),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private function optionalCommandString(array $command, string $field, int $index, array &$errors): ?string
    {
        if (! array_key_exists($field, $command) || $command[$field] === null) {
            return null;
        }

        if (! is_string($command[$field]) || trim($command[$field]) === '') {
            $errors["commands.{$index}.{$field}"] = [
                sprintf('Workflow task command field [%s] must be a non-empty string when provided.', $field),
            ];

            return null;
        }

        return trim($command[$field]);
    }

    private function workflowOutcomeStatus(?string $reason): int
    {
        return match ($reason) {
            null => 200,
            'task_not_found' => 404,
            default => 409,
        };
    }
}
