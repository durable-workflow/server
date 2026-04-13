<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\NamespaceWorkflowScope;
use App\Support\StandaloneWorkerFleet;
use App\Support\WorkflowTaskLeaseRecovery;
use App\Support\WorkerProtocol;
use App\Support\WorkflowTaskPoller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryPayloadCompression;

class WorkerController
{
    public function __construct(
        private readonly WorkflowTaskPoller $workflowTaskPoller,
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
            'history_page_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'accept_history_encoding' => ['nullable', 'string', 'max:64'],
        ]);

        $maxPageSize = (int) config('server.worker_protocol.history_page_size_max', 1000);
        $defaultPageSize = (int) config('server.worker_protocol.history_page_size_default', 500);
        $requestedPageSize = $validated['history_page_size'] ?? null;
        $pageSize = min($requestedPageSize ?? $defaultPageSize, $maxPageSize);

        $acceptHistoryEncoding = $validated['accept_history_encoding'] ?? null;

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
        // request parameter.  The resolveRegisteredWorker() guard already
        // rejects mismatches, so by this point the registration is trusted.
        $registeredBuildId = is_string($worker->build_id) && $worker->build_id !== ''
            ? $worker->build_id
            : null;

        $task = $this->workflowTaskPoller->poll(
            request: $request,
            namespace: $namespace,
            taskQueue: $validated['task_queue'],
            leaseOwner: $validated['worker_id'],
            buildId: $registeredBuildId,
            pollRequestId: $validated['poll_request_id'] ?? null,
            historyPageSize: $pageSize,
            acceptHistoryEncoding: $acceptHistoryEncoding,
            supportedWorkflowTypes: $this->nonEmptyStringArray($worker->supported_workflow_types),
        );

        $task = $this->formatTaskHistoryPagination($task);

        return WorkerProtocol::json([
            'task' => $task,
        ]);
    }

    /**
     * Fetch a subsequent page of history events for a leased workflow task.
     *
     * Workers that received a next_history_page_token in the poll response
     * use this endpoint to retrieve additional pages before completing replay.
     */
    public function workflowTaskHistory(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'workflow_task_attempt' => ['required', 'integer', 'min:1'],
            'next_history_page_token' => ['required', 'string'],
            'history_page_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'accept_history_encoding' => ['nullable', 'string', 'max:64'],
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

        $afterSequence = self::decodeHistoryPageToken($validated['next_history_page_token']);

        if ($afterSequence === null) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'error' => 'Invalid history page token.',
                'reason' => 'invalid_page_token',
            ], 400);
        }

        $maxPageSize = (int) config('server.worker_protocol.history_page_size_max', 1000);
        $defaultPageSize = (int) config('server.worker_protocol.history_page_size_default', 500);
        $pageSize = min($validated['history_page_size'] ?? $defaultPageSize, $maxPageSize);

        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        $history = $bridge->historyPayloadPaginated($taskId, $afterSequence, $pageSize);

        if (! is_array($history)) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'error' => 'Workflow task history not available.',
                'reason' => 'history_not_available',
            ], 404);
        }

        $acceptHistoryEncoding = $validated['accept_history_encoding'] ?? null;

        if ($acceptHistoryEncoding !== null) {
            $history = HistoryPayloadCompression::compress($history, $acceptHistoryEncoding);
        }

        $hasMore = $history['has_more'] ?? false;
        $nextAfterSequence = $history['next_after_sequence'] ?? null;

        $response = [
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'history_events' => $history['history_events'] ?? [],
            'total_history_events' => $history['last_history_sequence'] ?? 0,
            'next_history_page_token' => $hasMore && $nextAfterSequence !== null
                ? self::encodeHistoryPageToken((int) $nextAfterSequence)
                : null,
        ];

        if (isset($history['history_events_compressed'])) {
            $response['history_events_compressed'] = $history['history_events_compressed'];
            $response['history_events_encoding'] = $history['history_events_encoding'];
        }

        return WorkerProtocol::json($response);
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
            'commands.*.change_id' => ['nullable', 'string'],
            'commands.*.version' => ['nullable', 'integer'],
            'commands.*.min_supported' => ['nullable', 'integer'],
            'commands.*.max_supported' => ['nullable', 'integer'],
            'commands.*.attributes' => ['nullable', 'array'],
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

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'outcome' => 'completed',
            'recorded' => $outcome['completed'],
            'run_id' => $outcome['workflow_run_id'],
            'run_status' => $outcome['run_status'],
            'created_task_ids' => $outcome['created_task_ids'] ?? [],
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

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'outcome' => 'failed',
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
        ], $this->workflowOutcomeStatus($outcome['reason']));
    }

    /**
     * Convert bridge pagination metadata to protocol page tokens.
     *
     * The poller now fetches history via historyPayloadPaginated() which
     * provides has_more / next_after_sequence. This method converts those
     * into the protocol's token-based pagination (total_history_events and
     * next_history_page_token).
     *
     * @param  array<string, mixed>|null  $task
     * @return array<string, mixed>|null
     */
    private function formatTaskHistoryPagination(?array $task): ?array
    {
        if ($task === null) {
            return null;
        }

        $hasMore = $task['has_more'] ?? false;
        $nextAfterSequence = $task['next_after_sequence'] ?? null;

        // total_history_events is set by the poller from last_history_sequence
        // when pagination metadata is present, or defaults to event count.
        if (! isset($task['total_history_events'])) {
            $task['total_history_events'] = count($task['history_events'] ?? []);
        }

        $task['next_history_page_token'] = ($hasMore && $nextAfterSequence !== null)
            ? self::encodeHistoryPageToken((int) $nextAfterSequence)
            : null;

        // Remove internal pagination fields not part of the protocol.
        unset($task['has_more'], $task['next_after_sequence']);

        return $task;
    }

    private static function encodeHistoryPageToken(int $sequence): string
    {
        return base64_encode((string) $sequence);
    }

    private static function decodeHistoryPageToken(?string $token): ?int
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

    /**
     * Verify workflow task ownership directly against the package's WorkflowTask
     * table. This eliminates mirror-table reads and reconciliation writes from
     * the heartbeat/complete/fail hot path (TD-S006 narrowing). The mirror table
     * is only consulted for expired-lease recovery when a mirror row exists.
     */
    private function guardWorkflowTaskOwnership(
        Request $request,
        string $namespace,
        string $taskId,
        int $workflowTaskAttempt,
        string $leaseOwner,
    ): ?JsonResponse {
        $task = NamespaceWorkflowScope::task($namespace, $taskId);

        if (! $task instanceof WorkflowTask || $task->task_type !== TaskType::Workflow) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task not found.',
                'reason' => 'task_not_found',
            ], 404);
        }

        if ($task->status !== TaskStatus::Leased) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task is not currently leased.',
                'reason' => 'task_not_leased',
            ], 409);
        }

        $taskLeaseOwner = is_string($task->lease_owner) && trim($task->lease_owner) !== ''
            ? trim($task->lease_owner)
            : null;
        $taskLeaseExpiresAt = $task->lease_expires_at;

        if ($taskLeaseOwner === null || $taskLeaseExpiresAt === null) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task is not currently leased.',
                'reason' => 'task_not_leased',
            ], 409);
        }

        if ($taskLeaseExpiresAt->lte(now())) {
            $this->workflowTaskLeaseRecovery->recoverExpiredTaskLease($request, $namespace, $task);

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease has expired and is waiting for recovery.',
                'reason' => 'lease_expired',
                'task_status' => 'leased',
                'lease_owner' => $taskLeaseOwner,
                'lease_expires_at' => $taskLeaseExpiresAt->toJSON(),
            ], 409);
        }

        if ($taskLeaseOwner !== $leaseOwner) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease is owned by another worker.',
                'reason' => 'lease_owner_mismatch',
                'lease_owner' => $taskLeaseOwner,
            ], 409);
        }

        // Normalize the package's attempt_count the same way the poller
        // does: treat 0 or null as 1 so the guard matches the protocol
        // value the worker received in the poll response.
        $packageAttempt = is_int($task->attempt_count) && $task->attempt_count > 0
            ? (int) $task->attempt_count
            : null;

        if ($packageAttempt !== null && $packageAttempt !== $workflowTaskAttempt) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease attempt does not match the current claim.',
                'reason' => 'workflow_task_attempt_mismatch',
                'current_attempt' => $packageAttempt,
            ], 409);
        }

        return null;
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

            if ($type === 'record_side_effect') {
                if (! is_string($command['result'] ?? null)) {
                    $errors["commands.{$index}.result"] = [
                        'Record side effect commands require a string result.',
                    ];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'result' => $command['result'],
                ];

                continue;
            }

            if ($type === 'record_version_marker') {
                $markerErrors = [];

                if (! is_string($command['change_id'] ?? null) || trim((string) $command['change_id']) === '') {
                    $markerErrors[] = 'change_id is required';
                }
                if (! is_int($command['version'] ?? null)) {
                    $markerErrors[] = 'version must be an integer';
                }
                if (! is_int($command['min_supported'] ?? null)) {
                    $markerErrors[] = 'min_supported must be an integer';
                }
                if (! is_int($command['max_supported'] ?? null)) {
                    $markerErrors[] = 'max_supported must be an integer';
                }

                if ($markerErrors !== []) {
                    $errors["commands.{$index}"] = $markerErrors;

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'change_id' => trim($command['change_id']),
                    'version' => (int) $command['version'],
                    'min_supported' => (int) $command['min_supported'],
                    'max_supported' => (int) $command['max_supported'],
                ];

                continue;
            }

            if ($type === 'upsert_search_attributes') {
                if (! is_array($command['attributes'] ?? null) || $command['attributes'] === []) {
                    $errors["commands.{$index}.attributes"] = [
                        'Upsert search attributes commands require a non-empty attributes object.',
                    ];

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'attributes' => $command['attributes'],
                ];

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

    /**
     * Resolve a registered worker for the given namespace and task queue.
     *
     * Returns the WorkerRegistration on success, or a JsonResponse rejection
     * when the worker is not registered.
     */
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

    private function workflowOutcomeStatus(?string $reason): int
    {
        return match ($reason) {
            null => 200,
            'task_not_found' => 404,
            default => 409,
        };
    }
}
