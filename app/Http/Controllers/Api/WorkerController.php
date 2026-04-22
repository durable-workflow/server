<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerRegistration;
use App\Support\HistoryRetentionEnforcer;
use App\Support\NamespaceWorkflowScope;
use App\Support\QueryTaskQueueUnavailableException;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskLeaseRecovery;
use App\Support\WorkflowTaskPoller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryPayloadCompression;
use Workflow\V2\Support\StandaloneWorkerVisibility;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowTaskOwnership;

class WorkerController
{
    public function __construct(
        private readonly WorkflowTaskPoller $workflowTaskPoller,
        private readonly WorkflowTaskLeaseRecovery $workflowTaskLeaseRecovery,
        private readonly WorkflowTaskOwnership $taskOwnership,
        private readonly WorkflowQueryTaskBroker $queryTasks,
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
            'workflow_definition_fingerprints' => ['nullable', 'array'],
            'workflow_definition_fingerprints.*' => ['string', 'max:255'],
            'supported_activity_types' => ['nullable', 'array'],
            'supported_activity_types.*' => ['string'],
            'max_concurrent_workflow_tasks' => ['nullable', 'integer', 'min:1'],
            'max_concurrent_activity_tasks' => ['nullable', 'integer', 'min:1'],
        ]);

        $workerId = $validated['worker_id'] ?? Str::ulid()->toBase32();
        $workflowDefinitionFingerprints = $this->workflowDefinitionFingerprints(
            $validated['workflow_definition_fingerprints'] ?? []
        );

        $existing = WorkerRegistration::query()
            ->where('worker_id', $workerId)
            ->where('namespace', $namespace)
            ->first();

        if ($existing instanceof WorkerRegistration && $existing->status === 'active') {
            $currentWorkflowDefinitionFingerprints = $this->workflowDefinitionFingerprints(
                $existing->workflow_definition_fingerprints ?? []
            );
            $conflict = $this->firstWorkflowDefinitionFingerprintConflict(
                $currentWorkflowDefinitionFingerprints,
                $workflowDefinitionFingerprints,
            );

            if ($conflict !== null) {
                return WorkerProtocol::json([
                    'error' => 'Worker attempted to re-register a changed workflow definition.',
                    'reason' => 'workflow_definition_changed',
                    'workflow_type' => $conflict,
                    'remediation' => 'Restart the worker with a new worker_id before registering a changed workflow class definition.',
                ], 409);
            }

            $workflowDefinitionFingerprints = $this->preserveAdvertisedWorkflowDefinitionFingerprints(
                $currentWorkflowDefinitionFingerprints,
                $workflowDefinitionFingerprints,
                $validated['supported_workflow_types'] ?? null,
            );
        }

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
                'workflow_definition_fingerprints' => $workflowDefinitionFingerprints,
                'supported_activity_types' => $validated['supported_activity_types'] ?? [],
                'max_concurrent_workflow_tasks' => $validated['max_concurrent_workflow_tasks'] ?? 100,
                'max_concurrent_activity_tasks' => $validated['max_concurrent_activity_tasks'] ?? 100,
                'last_heartbeat_at' => now(),
                'status' => 'active',
            ]
        );

        StandaloneWorkerVisibility::recordCompatibility(
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
     * @param  array<array-key, mixed>  $fingerprints
     * @return array<string, string>
     */
    private function workflowDefinitionFingerprints(array $fingerprints): array
    {
        $normalized = [];

        foreach ($fingerprints as $workflowType => $fingerprint) {
            if (! is_string($workflowType) || ! is_string($fingerprint)) {
                continue;
            }

            $workflowType = trim($workflowType);
            $fingerprint = trim($fingerprint);

            if ($workflowType === '' || $fingerprint === '') {
                continue;
            }

            $normalized[$workflowType] = $fingerprint;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $incoming
     * @param  array<array-key, mixed>|null  $supportedWorkflowTypes
     * @return array<string, string>
     */
    private function preserveAdvertisedWorkflowDefinitionFingerprints(
        array $current,
        array $incoming,
        ?array $supportedWorkflowTypes,
    ): array {
        $advertisedWorkflowTypes = [];

        foreach ($supportedWorkflowTypes ?? array_keys($current) as $workflowType) {
            if (! is_string($workflowType)) {
                continue;
            }

            $workflowType = trim($workflowType);

            if ($workflowType === '') {
                continue;
            }

            $advertisedWorkflowTypes[$workflowType] = true;
        }

        foreach ($current as $workflowType => $fingerprint) {
            if (isset($advertisedWorkflowTypes[$workflowType]) && ! isset($incoming[$workflowType])) {
                $incoming[$workflowType] = $fingerprint;
            }
        }

        ksort($incoming);

        return $incoming;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $incoming
     */
    private function firstWorkflowDefinitionFingerprintConflict(array $current, array $incoming): ?string
    {
        foreach ($incoming as $workflowType => $fingerprint) {
            if (isset($current[$workflowType]) && $current[$workflowType] !== $fingerprint) {
                return $workflowType;
            }
        }

        return null;
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
            return WorkerProtocol::json([
                'error' => 'Worker not registered.',
                'reason' => 'worker_not_registered',
                'worker_id' => $validated['worker_id'],
            ], 404);
        }

        $worker->update([
            'last_heartbeat_at' => now(),
            'status' => 'active',
        ]);

        StandaloneWorkerVisibility::recordCompatibility(
            namespace: $worker->namespace,
            workerId: $worker->worker_id,
            taskQueue: $worker->task_queue,
            buildId: is_string($worker->build_id) ? $worker->build_id : null,
        );

        $retention = HistoryRetentionEnforcer::runInlinePass($namespace);

        return WorkerProtocol::json([
            'worker_id' => $worker->worker_id,
            'acknowledged' => true,
            'retention' => $retention,
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
            'history_page_size' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            ],
            'accept_history_encoding' => ['nullable', 'string', 'max:64'],
        ]);

        $maxPageSize = (int) config(
            'server.worker_protocol.history_page_size_max',
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
        );
        $defaultPageSize = (int) config(
            'server.worker_protocol.history_page_size_default',
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
        );
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
            'history_page_size' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            ],
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

        $maxPageSize = (int) config(
            'server.worker_protocol.history_page_size_max',
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
        );
        $defaultPageSize = (int) config(
            'server.worker_protocol.history_page_size_default',
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
        );
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
            'commands.*.arguments' => ['nullable'],
            'commands.*.connection' => ['nullable', 'string'],
            'commands.*.queue' => ['nullable', 'string'],
            'commands.*.retry_policy' => ['nullable', 'array'],
            'commands.*.retry_policy.max_attempts' => ['nullable', 'integer', 'min:1'],
            'commands.*.retry_policy.backoff_seconds' => ['nullable', 'array'],
            'commands.*.retry_policy.backoff_seconds.*' => ['integer', 'min:0'],
            'commands.*.retry_policy.non_retryable_error_types' => ['nullable', 'array'],
            'commands.*.retry_policy.non_retryable_error_types.*' => ['string'],
            'commands.*.start_to_close_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.schedule_to_start_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.schedule_to_close_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.heartbeat_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.execution_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'commands.*.run_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'commands.*.workflow_type' => ['nullable', 'string'],
            'commands.*.delay_seconds' => ['nullable', 'integer', 'min:0'],
            'commands.*.message' => ['nullable', 'string'],
            'commands.*.update_id' => ['nullable', 'string'],
            'commands.*.exception_class' => ['nullable', 'string'],
            'commands.*.exception_type' => ['nullable', 'string'],
            'commands.*.change_id' => ['nullable', 'string'],
            'commands.*.version' => ['nullable', 'integer'],
            'commands.*.min_supported' => ['nullable', 'integer'],
            'commands.*.max_supported' => ['nullable', 'integer'],
            'commands.*.attributes' => ['nullable', 'array'],
            'commands.*.non_retryable' => ['nullable', 'boolean'],
            'commands.*.parent_close_policy' => ['nullable', 'string'],
            'commands.*.condition_key' => ['nullable', 'string'],
            'commands.*.condition_definition_fingerprint' => ['nullable', 'string'],
            'commands.*.timeout_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $commands = $this->normalizeWorkflowTaskCommandIntegerFields($validated['commands']);

        $this->validateWorkflowTaskCommandScopes($commands);

        if ($response = $this->guardWorkflowTaskOwnership(
            $request,
            $namespace,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $validated['lease_owner'],
        )) {
            return $response;
        }

        $commands = WorkflowCommandNormalizer::normalize($commands);

        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);

        try {
            $outcome = $bridge->complete($taskId, $commands);
        } catch (StructuralLimitExceededException $e) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'error' => $e->getMessage(),
                'reason' => 'structural_limit_exceeded',
                'limit_kind' => $e->limitKind->value,
                'current_value' => $e->currentValue,
                'configured_limit' => $e->configuredLimit,
            ], 422);
        }

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
     * @param  list<array<string, mixed>>  $commands
     *
     * @throws ValidationException
     */
    private function validateWorkflowTaskCommandScopes(array $commands): void
    {
        $errors = [];

        foreach ($commands as $index => $command) {
            $type = $command['type'] ?? null;

            if (! is_string($type)) {
                continue;
            }

            if ($this->hasCommandValue($command, 'retry_policy')
                && ! in_array($type, ['schedule_activity', 'start_child_workflow'], true)
            ) {
                $errors["commands.{$index}.retry_policy"][] =
                    'retry_policy is only supported for schedule_activity and start_child_workflow commands.';
            }

            foreach (['start_to_close_timeout', 'schedule_to_start_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout'] as $field) {
                if ($this->hasCommandValue($command, $field) && $type !== 'schedule_activity') {
                    $errors["commands.{$index}.{$field}"][] =
                        "{$field} is only supported for schedule_activity commands.";
                }
            }

            foreach (['execution_timeout_seconds', 'run_timeout_seconds'] as $field) {
                if ($this->hasCommandValue($command, $field) && $type !== 'start_child_workflow') {
                    $errors["commands.{$index}.{$field}"][] =
                        "{$field} is only supported for start_child_workflow commands.";
                }
            }

            if ($this->hasCommandValue($command, 'non_retryable')
                && ! in_array($type, ['fail_workflow', 'fail_update'], true)
            ) {
                $errors["commands.{$index}.non_retryable"][] =
                    'non_retryable is only supported for fail_workflow and fail_update commands.';
            }

            if ($type === 'schedule_activity') {
                $this->validateActivityTimeoutEnvelope($command, $index, $errors);
            }

            if ($type === 'start_child_workflow') {
                $this->validateChildWorkflowTimeoutEnvelope($command, $index, $errors);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function hasCommandValue(array $command, string $field): bool
    {
        return array_key_exists($field, $command) && $command[$field] !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function normalizeWorkflowTaskCommandIntegerFields(array $commands): array
    {
        $integerFields = [
            'start_to_close_timeout',
            'schedule_to_start_timeout',
            'schedule_to_close_timeout',
            'heartbeat_timeout',
            'execution_timeout_seconds',
            'run_timeout_seconds',
            'delay_seconds',
            'version',
            'min_supported',
            'max_supported',
            'timeout_seconds',
        ];

        foreach ($commands as $index => $command) {
            foreach ($integerFields as $field) {
                if (array_key_exists($field, $command)) {
                    $commands[$index][$field] = $this->normalizeValidatedInteger($command[$field]);
                }
            }

            $retryPolicy = $command['retry_policy'] ?? null;
            if (! is_array($retryPolicy)) {
                continue;
            }

            if (array_key_exists('max_attempts', $retryPolicy)) {
                $retryPolicy['max_attempts'] = $this->normalizeValidatedInteger($retryPolicy['max_attempts']);
            }

            $backoffSeconds = $retryPolicy['backoff_seconds'] ?? null;
            if (is_array($backoffSeconds)) {
                foreach ($backoffSeconds as $backoffIndex => $backoffSecond) {
                    $backoffSeconds[$backoffIndex] = $this->normalizeValidatedInteger($backoffSecond);
                }

                $retryPolicy['backoff_seconds'] = $backoffSeconds;
            }

            $commands[$index]['retry_policy'] = $retryPolicy;
        }

        return $commands;
    }

    private function normalizeValidatedInteger(mixed $value): mixed
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private function validateActivityTimeoutEnvelope(array $command, int $index, array &$errors): void
    {
        $startToClose = $this->optionalCommandInt($command, 'start_to_close_timeout');
        $scheduleToStart = $this->optionalCommandInt($command, 'schedule_to_start_timeout');
        $scheduleToClose = $this->optionalCommandInt($command, 'schedule_to_close_timeout');
        $heartbeat = $this->optionalCommandInt($command, 'heartbeat_timeout');

        if ($heartbeat !== null && $startToClose !== null && $heartbeat > $startToClose) {
            $errors["commands.{$index}.heartbeat_timeout"][] =
                'heartbeat_timeout cannot exceed start_to_close_timeout.';
        }

        if ($startToClose !== null && $scheduleToClose !== null && $startToClose > $scheduleToClose) {
            $errors["commands.{$index}.start_to_close_timeout"][] =
                'start_to_close_timeout cannot exceed schedule_to_close_timeout.';
        }

        if ($scheduleToStart !== null && $scheduleToClose !== null && $scheduleToStart > $scheduleToClose) {
            $errors["commands.{$index}.schedule_to_start_timeout"][] =
                'schedule_to_start_timeout cannot exceed schedule_to_close_timeout.';
        }
    }

    /**
     * @param  array<string, mixed>  $command
     * @param  array<string, list<string>>  $errors
     */
    private function validateChildWorkflowTimeoutEnvelope(array $command, int $index, array &$errors): void
    {
        $executionTimeout = $this->optionalCommandInt($command, 'execution_timeout_seconds');
        $runTimeout = $this->optionalCommandInt($command, 'run_timeout_seconds');

        if ($executionTimeout !== null && $runTimeout !== null && $runTimeout > $executionTimeout) {
            $errors["commands.{$index}.run_timeout_seconds"][] =
                'run_timeout_seconds cannot exceed execution_timeout_seconds.';
        }
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function optionalCommandInt(array $command, string $field): ?int
    {
        return is_int($command[$field] ?? null) ? $command[$field] : null;
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
            'next_task_id' => $outcome['next_task_id'] ?? null,
        ], $this->workflowOutcomeStatus($outcome['reason']));
    }

    public function pollQueryTasks(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'task_queue' => ['required', 'string'],
        ]);

        $worker = $this->resolveRegisteredWorker(
            $namespace,
            $validated['worker_id'],
            $validated['task_queue'],
        );

        if ($worker instanceof JsonResponse) {
            return $worker;
        }

        try {
            $task = $this->queryTasks->poll($namespace, $worker);
        } catch (QueryTaskQueueUnavailableException $exception) {
            return WorkerProtocol::json([
                'task' => null,
                'error' => 'Query task queue is temporarily unavailable.',
                'reason' => 'query_task_queue_unavailable',
                'message' => $exception->getMessage(),
                'namespace' => $namespace,
                'task_queue' => $validated['task_queue'],
            ], 503);
        }

        return WorkerProtocol::json([
            'task' => $task,
        ]);
    }

    public function completeQueryTask(Request $request, string $queryTaskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'query_task_attempt' => ['required', 'integer', 'min:1'],
            'result' => ['nullable'],
            'result_envelope' => ['nullable', 'array'],
            'result_envelope.codec' => ['required_with:result_envelope', 'string', 'max:64'],
            'result_envelope.blob' => ['required_with:result_envelope', 'string'],
        ]);

        $outcome = $this->queryTasks->complete(
            $namespace,
            $queryTaskId,
            $validated['lease_owner'],
            (int) $validated['query_task_attempt'],
            $validated['result'] ?? null,
            $validated['result_envelope'] ?? null,
        );

        return WorkerProtocol::json(
            array_filter($outcome, static fn (mixed $value): bool => $value !== null),
            (int) ($outcome['status'] ?? 200),
        );
    }

    public function failQueryTask(Request $request, string $queryTaskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'query_task_attempt' => ['required', 'integer', 'min:1'],
            'failure' => ['required', 'array'],
            'failure.message' => ['required', 'string'],
            'failure.reason' => ['nullable', 'string'],
            'failure.type' => ['nullable', 'string'],
            'failure.stack_trace' => ['nullable', 'string'],
        ]);

        $outcome = $this->queryTasks->fail(
            $namespace,
            $queryTaskId,
            $validated['lease_owner'],
            (int) $validated['query_task_attempt'],
            $validated['failure'],
        );

        return WorkerProtocol::json(
            array_filter($outcome, static fn (mixed $value): bool => $value !== null),
            (int) ($outcome['status'] ?? 200),
        );
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
     * Guard workflow task ownership and lease validity.
     *
     * Delegates validation to WorkflowTaskOwnership (package-level guard).
     * Converts structured outcomes to HTTP responses and dispatches recovery
     * for expired leases.
     */
    private function guardWorkflowTaskOwnership(
        Request $request,
        string $namespace,
        string $taskId,
        int $workflowTaskAttempt,
        string $leaseOwner,
    ): ?JsonResponse {
        $result = $this->taskOwnership->guard(
            fn (string $ns, string $id) => NamespaceWorkflowScope::task($ns, $id),
            $namespace,
            $taskId,
            $workflowTaskAttempt,
            $leaseOwner,
        );

        if ($result['valid']) {
            return null;
        }

        // Handle expired lease recovery
        if ($result['reason'] === 'lease_expired' && $result['task'] instanceof WorkflowTask) {
            $this->workflowTaskLeaseRecovery->recoverExpiredTaskLease($request, $namespace, $result['task']);

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease has expired and is waiting for recovery.',
                'reason' => 'lease_expired',
                'task_status' => 'leased',
                'lease_owner' => $result['status']['lease_owner'] ?? null,
                'lease_expires_at' => $result['status']['lease_expires_at'] ?? null,
            ], 409);
        }

        // Convert package-level outcomes to HTTP responses
        return match ($result['reason']) {
            'task_not_found' => WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task not found.',
                'reason' => 'task_not_found',
            ], 404),

            'task_not_leased' => WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task is not currently leased.',
                'reason' => 'task_not_leased',
            ], 409),

            'lease_owner_mismatch' => WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease is owned by another worker.',
                'reason' => 'lease_owner_mismatch',
                'lease_owner' => $result['status']['lease_owner'] ?? null,
            ], 409),

            'workflow_task_attempt_mismatch' => WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease attempt does not match the current claim.',
                'reason' => 'workflow_task_attempt_mismatch',
                'current_attempt' => $result['status']['attempt_count'] ?? null,
            ], 409),

            'run_closed' => WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow run is already closed.',
                'reason' => 'run_closed',
                'stop_reason' => $this->workflowTaskStopReason($result['status']['run_status'] ?? null),
                'cancel_requested' => $this->workflowTaskCancelRequested($result['status']['run_status'] ?? null),
                'can_continue' => false,
                'run_status' => $result['status']['run_status'] ?? null,
                'run_closed_reason' => $result['status']['run_closed_reason'] ?? null,
                'run_closed_at' => $result['status']['run_closed_at'] ?? null,
                'task_status' => $result['status']['task_status'] ?? null,
                'lease_owner' => $result['status']['lease_owner'] ?? null,
                'lease_expires_at' => $result['status']['lease_expires_at'] ?? null,
            ], 409),

            default => WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task validation failed.',
                'reason' => $result['reason'] ?? 'unknown',
            ], 409),
        };
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

    private function workflowTaskCancelRequested(mixed $runStatus): bool
    {
        return is_string($runStatus)
            && in_array($runStatus, ['cancelled', 'terminated'], true);
    }

    private function workflowTaskStopReason(mixed $runStatus): string
    {
        return match ($runStatus) {
            'cancelled' => 'run_cancelled',
            'terminated' => 'run_terminated',
            'completed' => 'run_completed',
            'failed' => 'run_failed',
            default => 'run_closed',
        };
    }
}
