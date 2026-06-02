<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\BackendLockPressure;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\HistoryRetentionEnforcer;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\QueryTaskQueueUnavailableException;
use App\Support\SearchAttributeValueValidator;
use App\Support\WorkerProtocol;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskLeaseRecovery;
use App\Support\WorkflowTaskPoller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\HistoryPayloadCompression;
use Workflow\V2\Support\PayloadEnvelopeResolver;
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
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly SearchAttributeValueValidator $searchAttributeValues,
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
            'runtime' => ['required', 'string', 'in:php,python,rust,typescript,go,java,external'],
            'sdk_version' => ['nullable', 'string', 'max:64'],
            'build_id' => ['nullable', 'string', 'max:255'],
            'supported_workflow_types' => ['nullable', 'array'],
            'supported_workflow_types.*' => ['string'],
            'workflow_definition_fingerprints' => ['nullable', 'array'],
            'workflow_definition_fingerprints.*' => ['string', 'max:255'],
            'supported_activity_types' => ['nullable', 'array'],
            'supported_activity_types.*' => ['string'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'max:255'],
            'max_concurrent_workflow_tasks' => ['nullable', 'integer', 'min:1'],
            'max_concurrent_activity_tasks' => ['nullable', 'integer', 'min:1'],
            'max_concurrent_worker_sessions' => ['nullable', 'integer', 'min:1'],
            'task_slots' => ['nullable', 'array'],
            'task_slots.workflow_available' => ['nullable', 'integer', 'min:0'],
            'task_slots.activity_available' => ['nullable', 'integer', 'min:0'],
            'task_slots.session_available' => ['nullable', 'integer', 'min:0'],
            'process_metrics' => ['nullable', 'array'],
            'process_metrics.cpu_percent' => ['nullable', 'numeric', 'min:0'],
            'process_metrics.memory_bytes' => ['nullable', 'integer', 'min:0'],
            'process_metrics.process_uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'process_metrics.process_id' => ['nullable', 'integer', 'min:0'],
            'process_metrics.host' => ['nullable', 'string', 'max:255'],
            'process_metrics.process_started_at' => ['nullable', 'string', 'max:64'],
            'heartbeat_interval_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
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

        $registrationStatus = $this->workerRegistrationStatus(
            $namespace,
            $validated['task_queue'],
            $validated['build_id'] ?? null,
        );

        $maxWorkflowTasks = $validated['max_concurrent_workflow_tasks'] ?? 100;
        $maxActivityTasks = $validated['max_concurrent_activity_tasks'] ?? 100;
        $maxWorkerSessions = $validated['max_concurrent_worker_sessions'] ?? 10;
        $taskSlots = is_array($validated['task_slots'] ?? null) ? $validated['task_slots'] : [];
        $processMetrics = $this->normalizeProcessMetrics($validated['process_metrics'] ?? null);
        $releaseLeasesForRegistration = $this->shouldReleaseLeasesForWorkerRegistration($existing, $processMetrics);

        $registration = WorkerRegistration::updateOrCreate(
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
                'capabilities' => $this->nonEmptyStringArray($validated['capabilities'] ?? []),
                'max_concurrent_workflow_tasks' => $maxWorkflowTasks,
                'max_concurrent_activity_tasks' => $maxActivityTasks,
                'max_concurrent_worker_sessions' => $maxWorkerSessions,
                'available_workflow_slots' => $this->boundedSlotCount(
                    $taskSlots['workflow_available'] ?? null,
                    $maxWorkflowTasks,
                ),
                'available_activity_slots' => $this->boundedSlotCount(
                    $taskSlots['activity_available'] ?? null,
                    $maxActivityTasks,
                ),
                'available_session_slots' => $this->boundedSlotCount(
                    $taskSlots['session_available'] ?? null,
                    $maxWorkerSessions,
                ),
                'process_metrics' => $processMetrics,
                'heartbeat_interval_seconds' => $validated['heartbeat_interval_seconds'] ?? null,
                'last_heartbeat_at' => now(),
                'status' => $registrationStatus,
            ]
        );

        if ($releaseLeasesForRegistration) {
            $this->releaseLeasedWorkflowTasksForReplacedWorker($namespace, $workerId);
            $this->releaseLeasedActivityTasksForReplacedWorker($namespace, $workerId);
        }

        StandaloneWorkerVisibility::recordCompatibility(
            namespace: $namespace,
            workerId: $workerId,
            taskQueue: $validated['task_queue'],
            buildId: $validated['build_id'] ?? null,
        );

        return WorkerProtocol::json([
            'worker_id' => $workerId,
            'registered' => true,
            'namespace' => $registration->namespace,
            'task_queue' => $registration->task_queue,
            'runtime' => $registration->runtime,
            'build_id' => $registration->build_id,
            'status' => $registration->status,
            'heartbeat_interval_seconds' => $this->advertisedHeartbeatIntervalSeconds(),
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
     *
     * In addition to refreshing last_heartbeat_at, the worker may report its
     * current task-slot availability and basic process-level metrics so that
     * operators can see — via the worker management API, CLI, and Waterline —
     * which workers are alive on each task queue, how many free slots each
     * has, and basic process health. All non-identity fields are optional so
     * older clients that only know the original heartbeat shape continue to
     * work unchanged.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'task_slots' => ['nullable', 'array'],
            'task_slots.workflow_available' => ['nullable', 'integer', 'min:0'],
            'task_slots.activity_available' => ['nullable', 'integer', 'min:0'],
            'task_slots.session_available' => ['nullable', 'integer', 'min:0'],
            'process_metrics' => ['nullable', 'array'],
            'process_metrics.cpu_percent' => ['nullable', 'numeric', 'min:0'],
            'process_metrics.memory_bytes' => ['nullable', 'integer', 'min:0'],
            'process_metrics.process_uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'process_metrics.process_id' => ['nullable', 'integer', 'min:0'],
            'process_metrics.host' => ['nullable', 'string', 'max:255'],
            'process_metrics.process_started_at' => ['nullable', 'string', 'max:64'],
            'heartbeat_interval_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
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

        $heartbeatStatus = $this->workerRegistrationStatus(
            $worker->namespace,
            $worker->task_queue,
            is_string($worker->build_id) ? $worker->build_id : null,
        );

        $update = [
            'last_heartbeat_at' => now(),
            'status' => $heartbeatStatus,
        ];

        $taskSlots = is_array($validated['task_slots'] ?? null) ? $validated['task_slots'] : [];

        if (array_key_exists('workflow_available', $taskSlots)) {
            $update['available_workflow_slots'] = $this->boundedSlotCount(
                $taskSlots['workflow_available'],
                $worker->max_concurrent_workflow_tasks,
            );
        }

        if (array_key_exists('activity_available', $taskSlots)) {
            $update['available_activity_slots'] = $this->boundedSlotCount(
                $taskSlots['activity_available'],
                $worker->max_concurrent_activity_tasks,
            );
        }

        if (array_key_exists('session_available', $taskSlots)) {
            $update['available_session_slots'] = $this->boundedSlotCount(
                $taskSlots['session_available'],
                $worker->max_concurrent_worker_sessions,
            );
        }

        if (array_key_exists('process_metrics', $validated)) {
            $update['process_metrics'] = $this->normalizeProcessMetrics($validated['process_metrics']);
        }

        if (array_key_exists('heartbeat_interval_seconds', $validated)
            && $validated['heartbeat_interval_seconds'] !== null) {
            $update['heartbeat_interval_seconds'] = $validated['heartbeat_interval_seconds'];
        }

        $worker->update($update);

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
            'heartbeat_interval_seconds' => $this->advertisedHeartbeatIntervalSeconds(),
            'stale_after_seconds' => $this->workerStaleAfterSeconds(),
            'retention' => $retention,
        ]);
    }

    private function boundedSlotCount(mixed $value, mixed $max): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $count = max(0, (int) $value);

        if (is_int($max) && $max >= 0) {
            $count = min($count, $max);
        }

        return $count;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeProcessMetrics(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $allowed = [
            'cpu_percent',
            'memory_bytes',
            'process_uptime_seconds',
            'process_id',
            'host',
            'process_started_at',
        ];
        $normalized = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $entry = $value[$key];

            if ($entry === null) {
                continue;
            }

            if ($key === 'host' || $key === 'process_started_at') {
                if (is_string($entry) && trim($entry) !== '') {
                    $normalized[$key] = mb_substr(trim($entry), 0, 255);
                }

                continue;
            }

            if ($key === 'cpu_percent') {
                if (is_int($entry) || is_float($entry)) {
                    $normalized[$key] = max(0.0, (float) $entry);
                }

                continue;
            }

            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $normalized[$key] = max(0, (int) $entry);
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $incomingProcessMetrics
     */
    private function shouldReleaseLeasesForWorkerRegistration(
        ?WorkerRegistration $existing,
        ?array $incomingProcessMetrics,
    ): bool {
        if (! $existing instanceof WorkerRegistration || $existing->status !== 'active') {
            return false;
        }

        $incomingIdentity = $this->workerProcessIdentity($incomingProcessMetrics);

        if ($incomingIdentity === []) {
            // Registration is the worker process lifecycle boundary. Older or
            // hand-rolled workers may not publish process metrics, but a fresh
            // registration with the same worker_id still has to reclaim work
            // left leased by the previous process instead of waiting for the
            // full lease timeout.
            return true;
        }

        $existingIdentity = $this->workerProcessIdentity($existing->process_metrics);

        if ($existingIdentity === []) {
            return true;
        }

        return $existingIdentity !== $incomingIdentity;
    }

    /**
     * @return array<string, int|string>
     */
    private function workerProcessIdentity(mixed $processMetrics): array
    {
        if (! is_array($processMetrics)) {
            return [];
        }

        $identity = [];

        foreach (['host', 'process_started_at'] as $key) {
            $value = $processMetrics[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $identity[$key] = trim($value);
            }
        }

        $processId = $processMetrics['process_id'] ?? null;

        if (is_int($processId) || (is_string($processId) && ctype_digit($processId))) {
            $identity['process_id'] = (int) $processId;
        }

        return $identity;
    }

    private function releaseLeasedWorkflowTasksForReplacedWorker(string $namespace, string $workerId): void
    {
        WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', $workerId)
            ->update([
                'status' => TaskStatus::Ready->value,
                'leased_at' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'sticky_replay_mode' => null,
                'sticky_claimed_at' => null,
                'last_claim_failed_at' => null,
                'last_claim_error' => null,
            ]);
    }

    private function releaseLeasedActivityTasksForReplacedWorker(string $namespace, string $workerId): void
    {
        WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('task_type', TaskType::Activity->value)
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', $workerId)
            ->get()
            ->each(function (WorkflowTask $task) use ($workerId): void {
                $this->expireLeasedActivityAttemptForReplacedWorker($task, $workerId);

                $task->forceFill([
                    'status' => TaskStatus::Ready,
                    'leased_at' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'last_claim_failed_at' => null,
                    'last_claim_error' => null,
                ])->save();
            });
    }

    private function expireLeasedActivityAttemptForReplacedWorker(WorkflowTask $task, string $workerId): void
    {
        $executionId = is_array($task->payload ?? null)
            ? ($task->payload['activity_execution_id'] ?? null)
            : null;

        if (! is_string($executionId) || $executionId === '') {
            return;
        }

        /** @var ActivityExecution|null $execution */
        $execution = ActivityExecution::query()->find($executionId);

        if (! $execution instanceof ActivityExecution) {
            return;
        }

        /** @var ActivityAttempt|null $attempt */
        $attempt = ActivityAttempt::query()
            ->where('workflow_task_id', $task->id)
            ->where('activity_execution_id', $execution->id)
            ->where('lease_owner', $workerId)
            ->where('status', ActivityAttemptStatus::Running->value)
            ->latest('attempt_number')
            ->first();

        if (! $attempt instanceof ActivityAttempt) {
            return;
        }

        $attempt->forceFill([
            'status' => ActivityAttemptStatus::Expired,
            'lease_expires_at' => null,
            'closed_at' => $attempt->closed_at ?? now(),
        ])->save();
    }

    private function advertisedHeartbeatIntervalSeconds(): int
    {
        $configured = (int) config('server.workers.heartbeat_interval_seconds', 60);

        return max(1, min(3600, $configured));
    }

    private function workerStaleAfterSeconds(): int
    {
        $configured = config('server.workers.stale_after_seconds');

        return max(1, is_numeric($configured) ? (int) $configured : 300);
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

        $supportedWorkflowTypes = $this->nonEmptyStringArray($worker->supported_workflow_types);

        // Registered capabilities are authoritative for routing: a worker
        // that did not advertise any workflow types at register time is not
        // a workflow worker, so the server must never deliver workflow
        // tasks to it — even if it shares a task queue with workers that
        // do handle workflow tasks.
        if ($supportedWorkflowTypes === []) {
            return WorkerProtocol::json([
                'task' => null,
                'poll_status' => 'no_workflow_capability',
            ]);
        }

        try {
            $poll = $this->workflowTaskPoller->poll(
                request: $request,
                namespace: $namespace,
                taskQueue: $validated['task_queue'],
                leaseOwner: $validated['worker_id'],
                buildId: $registeredBuildId,
                pollRequestId: $validated['poll_request_id'] ?? null,
                historyPageSize: $pageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
            );
        } catch (\Throwable $exception) {
            if (BackendLockPressure::is($exception)) {
                return BackendLockPressure::workerPollResponse(
                    'workflow_task',
                    $namespace,
                    $validated['task_queue'],
                );
            }

            throw $exception;
        }

        $task = $this->formatTaskHistoryPagination($poll['task'] ?? null);

        return WorkerProtocol::json([
            'task' => $task,
            'poll_status' => is_string($poll['poll_status'] ?? null)
                ? $poll['poll_status']
                : ($task === null ? 'empty' : 'leased'),
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

        $history['history_events'] = $this->workflowTaskPoller->historyEventsWithSignalArguments(
            $history['history_events'] ?? [],
            $namespace,
            is_string($history['payload_codec'] ?? null) ? $history['payload_codec'] : null,
        );

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
            'commands.*.worker_session' => ['nullable', 'array'],
            'commands.*.worker_session.session_id' => ['nullable', 'string', 'max:255'],
            'commands.*.worker_session.connection' => ['nullable', 'string', 'max:255'],
            'commands.*.worker_session.queue' => ['nullable', 'string', 'max:255'],
            'commands.*.worker_session.requirements' => ['nullable', 'array'],
            'commands.*.worker_session.requirements.*' => ['string', 'max:255'],
            'commands.*.worker_session.lease_seconds' => ['nullable', 'integer', 'min:1'],
            'commands.*.worker_session.ttl_seconds' => ['nullable', 'integer', 'min:1'],
            'commands.*.worker_session.max_concurrent_activities' => ['nullable', 'integer', 'min:1'],
            'commands.*.worker_session.create_if_missing' => ['nullable', 'boolean'],
            'commands.*.worker_session.allow_reacquire_after_failure' => ['nullable', 'boolean'],
            'commands.*.execution_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'commands.*.run_timeout_seconds' => ['nullable', 'integer', 'min:1'],
            'commands.*.workflow_type' => ['nullable', 'string'],
            'commands.*.delay_seconds' => ['nullable', 'integer', 'min:0'],
            'commands.*.message' => ['nullable', 'string'],
            'commands.*.payload_codec' => ['nullable', 'string'],
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
        $commands = $this->applyWorkerSessionRoutingDefaults($commands);

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

        if ($response = $this->guardWorkerSessionCommandsAvailable(
            $request,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $commands,
        )) {
            return $response;
        }

        try {
            $commands = $this->resolveWorkflowTaskCommandPayloadReferences($commands, $namespace);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (ExternalPayloadIntegrityException $exception) {
            return $this->externalPayloadFailure($taskId, (int) $validated['workflow_task_attempt'], $exception, 422);
        } catch (\Throwable $exception) {
            return $this->externalPayloadFailure($taskId, (int) $validated['workflow_task_attempt'], $exception, 503);
        }

        $commands = $this->validateWorkflowTaskSearchAttributeCommands(
            $commands,
            is_string($namespace) ? $namespace : null,
        );

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
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return $this->externalPayloadFailure($taskId, (int) $validated['workflow_task_attempt'], $exception, 503);
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
     */
    private function guardWorkerSessionCommandsAvailable(
        Request $request,
        string $taskId,
        int $workflowTaskAttempt,
        array $commands,
    ): ?JsonResponse {
        if (
            ! $this->commandsUseWorkerSessions($commands)
            || WorkerProtocol::workerSessionsAvailableForRequest($request)
        ) {
            return null;
        }

        $minimum = WorkerProtocol::workerSessionMinimumProtocolVersion();

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => 'worker_sessions_unavailable',
            'error' => sprintf(
                'Worker-session activity commands require worker protocol %s or newer.',
                $minimum,
            ),
            'requested_version' => WorkerProtocol::requestVersion($request),
            'minimum_protocol_version' => $minimum,
            'remediation' => sprintf(
                'Complete worker-session workflow tasks through a server node advertising worker protocol %s or newer.',
                $minimum,
            ),
        ], 409);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function commandsUseWorkerSessions(array $commands): bool
    {
        foreach ($commands as $command) {
            if ($this->hasCommandValue($command, 'worker_session')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function validateWorkflowTaskSearchAttributeCommands(array $commands, ?string $namespace): array
    {
        foreach ($commands as $index => $command) {
            if (($command['type'] ?? null) !== 'upsert_search_attributes') {
                continue;
            }

            if (! is_array($command['attributes'] ?? null)) {
                continue;
            }

            $commands[$index]['attribute_types'] = $this->searchAttributeValues->validateForNamespace(
                $namespace,
                $command['attributes'],
                "commands.{$index}.attributes",
            );
        }

        return $commands;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function applyWorkerSessionRoutingDefaults(array $commands): array
    {
        foreach ($commands as $index => $command) {
            if (($command['type'] ?? null) !== 'schedule_activity') {
                continue;
            }

            $workerSession = is_array($command['worker_session'] ?? null)
                ? $command['worker_session']
                : null;

            if ($workerSession === null) {
                continue;
            }

            foreach (['connection', 'queue'] as $field) {
                if ($this->hasCommandValue($command, $field)) {
                    continue;
                }

                if (is_string($workerSession[$field] ?? null) && trim($workerSession[$field]) !== '') {
                    $commands[$index][$field] = trim($workerSession[$field]);
                }
            }
        }

        return $commands;
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

            if ($this->hasCommandValue($command, 'worker_session') && $type !== 'schedule_activity') {
                $errors["commands.{$index}.worker_session"][] =
                    'worker_session is only supported for schedule_activity commands.';
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
            'worker_session.lease_seconds',
            'worker_session.ttl_seconds',
            'worker_session.max_concurrent_activities',
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
                if (str_contains($field, '.')) {
                    [$parent, $child] = explode('.', $field, 2);

                    if (isset($command[$parent]) && is_array($command[$parent]) && array_key_exists($child, $command[$parent])) {
                        $commands[$index][$parent][$child] = $this->normalizeValidatedInteger($command[$parent][$child]);
                    }

                    continue;
                }

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

    /**
     * @param  list<array<string, mixed>>  $commands
     * @return list<array<string, mixed>>
     */
    private function resolveWorkflowTaskCommandPayloadReferences(array $commands, string $namespace): array
    {
        $driver = $this->externalPayloadStorage->driverFor($namespace);

        foreach ($commands as $index => $command) {
            $commandType = $command['type'] ?? null;

            foreach (['arguments', 'result'] as $field) {
                if (! array_key_exists($field, $command) || ! is_array($command[$field])) {
                    continue;
                }

                $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                    $command[$field],
                    "commands.{$index}.{$field}",
                    $driver,
                );

                if ($resolved['codec'] === null) {
                    $commands[$index][$field] = $resolved['payload'];

                    continue;
                }

                $normalizerAcceptsPayloadEnvelope = is_string($commandType)
                    && WorkflowCommandNormalizer::acceptsPayloadEnvelope($commandType, $field);

                if (! $normalizerAcceptsPayloadEnvelope) {
                    unset($commands[$index]['payload_codec']);
                    $commands[$index][$field] = $resolved['payload'];

                    continue;
                }

                $commands[$index][$field] = [
                    'codec' => $resolved['codec'],
                    'blob' => $resolved['payload'],
                ];

                if (($commands[$index]['payload_codec'] ?? null) === null) {
                    $commands[$index]['payload_codec'] = $resolved['codec'];
                }
            }
        }

        return $commands;
    }

    private function externalPayloadFailure(
        string $taskId,
        int $workflowTaskAttempt,
        \Throwable $exception,
        int $status,
    ): JsonResponse {
        $integrityFailure = $status === 422;

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => $integrityFailure
                ? 'external_payload_integrity_failed'
                : 'external_payload_storage_unavailable',
            'error' => $exception->getMessage(),
        ], $status);
    }

    private function externalQueryPayloadFailure(
        string $queryTaskId,
        int $queryTaskAttempt,
        \Throwable $exception,
        int $status,
    ): JsonResponse {
        $integrityFailure = $status === 422;

        return WorkerProtocol::json([
            'query_task_id' => $queryTaskId,
            'query_task_attempt' => $queryTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => $integrityFailure
                ? 'external_payload_integrity_failed'
                : 'external_payload_storage_unavailable',
            'error' => $exception->getMessage(),
        ], $status);
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
        try {
            $outcome = $bridge->fail($taskId, $validated['failure']);
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return $this->externalPayloadFailure($taskId, (int) $validated['workflow_task_attempt'], $exception, 503);
        }

        $nextTaskId = is_string($outcome['next_task_id'] ?? null)
            ? $outcome['next_task_id']
            : null;

        if (
            ($outcome['recorded'] ?? false) === true
            && ($outcome['reason'] ?? null) === null
            && $nextTaskId === null
        ) {
            if ($this->workflowTaskFailureBlocksReplay($validated['failure'])) {
                $this->markWorkflowTaskReplayBlocked($namespace, $taskId, $validated['failure']);
            } else {
                $nextTaskId = $this->createRetryWorkflowTask($namespace, $taskId);
            }
        }

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'outcome' => 'failed',
            'recorded' => $outcome['recorded'],
            'reason' => $outcome['reason'],
            'next_task_id' => $nextTaskId,
        ], $this->workflowOutcomeStatus($outcome['reason']));
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    private function workflowTaskFailureBlocksReplay(array $failure): bool
    {
        $message = strtolower((string) ($failure['message'] ?? ''));
        $type = strtolower((string) ($failure['type'] ?? ''));
        $text = $type.' '.$message;

        foreach ([
            'nondetermin',
            'non-determin',
            'determinism',
            'replay error',
            'replay failed',
            'history shape',
            'history mismatch',
            'invalidargument',
            'servererror',
            'unexpected history',
            'validationexception',
            'cannot decode workflow start input',
            'cannot replay workflow history',
            'unsupported payload codec',
            'workflow task completion failed after commands were produced',
            'no workflow registered',
        ] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    private function markWorkflowTaskReplayBlocked(string $namespace, string $taskId, array $failure): void
    {
        DB::transaction(function () use ($namespace, $taskId, $failure): void {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->whereKey($taskId)
                ->where('namespace', $namespace)
                ->first();

            if (! $task instanceof WorkflowTask
                || $task->task_type !== TaskType::Workflow
                || $task->status !== TaskStatus::Failed) {
                return;
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $payload['replay_blocked'] = true;
            $payload['replay_blocked_reason'] = 'worker_reported_replay_failure';

            if (is_string($failure['type'] ?? null) && trim($failure['type']) !== '') {
                $payload['replay_blocked_failure_type'] = trim($failure['type']);
            }

            $task->forceFill(['payload' => $payload])->save();

            $this->projectWorkflowRun((string) $task->workflow_run_id);
        });
    }

    private function createRetryWorkflowTask(string $namespace, string $failedTaskId): ?string
    {
        return DB::transaction(function () use ($namespace, $failedTaskId): ?string {
            /** @var WorkflowTask|null $failedTask */
            $failedTask = WorkflowTask::query()
                ->lockForUpdate()
                ->whereKey($failedTaskId)
                ->where('namespace', $namespace)
                ->first();

            if (! $failedTask instanceof WorkflowTask
                || $failedTask->task_type !== TaskType::Workflow
                || $failedTask->status !== TaskStatus::Failed) {
                return null;
            }

            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->find($failedTask->workflow_run_id);

            if (! $run instanceof WorkflowRun || $run->status->isTerminal()) {
                return null;
            }

            $hasOpenWorkflowTask = WorkflowTask::query()
                ->where('workflow_run_id', $run->id)
                ->where('task_type', TaskType::Workflow->value)
                ->whereIn('status', [TaskStatus::Ready->value, TaskStatus::Leased->value])
                ->exists();

            if ($hasOpenWorkflowTask) {
                return null;
            }

            $payload = is_array($failedTask->payload) ? $failedTask->payload : [];
            $payload['workflow_task_retry_of'] = $failedTask->id;
            $payload['workflow_task_retry_after_error'] = $failedTask->last_error;
            $attemptCount = is_numeric($failedTask->attempt_count)
                ? max(0, (int) $failedTask->attempt_count)
                : 0;

            /** @var WorkflowTask $retryTask */
            $retryTask = WorkflowTask::query()->create([
                'workflow_run_id' => $run->id,
                'namespace' => $run->namespace,
                'task_type' => TaskType::Workflow->value,
                'status' => TaskStatus::Ready->value,
                'attempt_count' => $attemptCount,
                'available_at' => now(),
                'payload' => $payload,
                'connection' => $failedTask->connection ?? $run->connection,
                'queue' => $failedTask->queue ?? $run->queue,
                'compatibility' => $failedTask->compatibility ?? $run->compatibility,
                'priority' => $failedTask->priority ?? $run->priority ?? 5,
                'fairness_key' => $failedTask->fairness_key ?? $run->fairness_key,
                'fairness_weight' => $failedTask->fairness_weight ?? $run->fairness_weight ?? 1,
            ]);

            $this->projectWorkflowRun($run->id);

            return (string) $retryTask->id;
        });
    }

    private function projectWorkflowRun(string $runId): void
    {
        /** @var WorkflowRun|null $run */
        $run = WorkflowRun::query()->find($runId);

        if (! $run instanceof WorkflowRun) {
            return;
        }

        app(HistoryProjectionRole::class)->projectRun($run->fresh([
            'instance',
            'tasks',
            'activityExecutions',
            'timers',
            'failures',
            'historyEvents',
        ]) ?? $run);
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
            'poll_request_id' => ['nullable', 'string', 'max:255'],
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
            $task = $this->queryTasks->poll($namespace, $worker, $validated['poll_request_id'] ?? null);
        } catch (QueryTaskQueueUnavailableException $exception) {
            return WorkerProtocol::json([
                'task' => null,
                'poll_status' => 'unavailable',
                'error' => 'Query task queue is temporarily unavailable.',
                'reason' => 'query_task_queue_unavailable',
                'message' => $exception->getMessage(),
                'namespace' => $namespace,
                'task_queue' => $validated['task_queue'],
            ], 503);
        } catch (\Throwable $exception) {
            if (BackendLockPressure::is($exception)) {
                return BackendLockPressure::workerPollResponse(
                    'query_task',
                    $namespace,
                    $validated['task_queue'],
                );
            }

            throw $exception;
        }

        return WorkerProtocol::json([
            'task' => $task,
            'poll_status' => is_array($task) ? 'leased' : 'empty',
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
            'result_envelope.blob' => ['nullable', 'string'],
            'result_envelope.external_storage' => ['nullable', 'array'],
        ]);

        $resultEnvelope = null;
        $hasInlineResult = array_key_exists('result', $request->all());

        $guard = $this->queryTasks->guardCompletion(
            $namespace,
            $queryTaskId,
            $validated['lease_owner'],
            (int) $validated['query_task_attempt'],
        );

        if ($guard !== null) {
            return WorkerProtocol::json(
                array_filter($guard, static fn (mixed $value): bool => $value !== null),
                (int) ($guard['status'] ?? 409),
            );
        }

        if (($validated['result_envelope'] ?? null) !== null) {
            $candidate = [
                'codec' => $validated['result_envelope']['codec'] ?? null,
            ];

            if (array_key_exists('external_storage', $validated['result_envelope'])) {
                $candidate['external_storage'] = $validated['result_envelope']['external_storage'];
            } else {
                $candidate['blob'] = $validated['result_envelope']['blob'] ?? null;
            }

            try {
                $resolved = PayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                    $candidate,
                    'result_envelope',
                    $this->externalPayloadStorage->driverFor($namespace),
                );
            } catch (ValidationException $exception) {
                // The envelope is optional metadata for query callers; the
                // inline result is still authoritative.
                if ($hasInlineResult) {
                    $resultEnvelope = null;
                } else {
                    throw $exception;
                }
            } catch (ExternalPayloadIntegrityException $exception) {
                if (! $hasInlineResult) {
                    return $this->externalQueryPayloadFailure($queryTaskId, (int) $validated['query_task_attempt'], $exception, 422);
                }
            } catch (\Throwable $exception) {
                if (! $hasInlineResult) {
                    return $this->externalQueryPayloadFailure($queryTaskId, (int) $validated['query_task_attempt'], $exception, 503);
                }
            }

            if (isset($resolved)) {
                $resultEnvelope = [
                    'codec' => $resolved['codec'],
                    'blob' => $resolved['payload'],
                ];
            }
        }

        $outcome = $this->queryTasks->complete(
            $namespace,
            $queryTaskId,
            $validated['lease_owner'],
            (int) $validated['query_task_attempt'],
            $validated['result'] ?? null,
            $resultEnvelope,
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
            'failure.validation_errors' => ['nullable', 'array'],
            'failure.validation_errors.*' => ['array'],
            'failure.validation_errors.*.*' => ['string'],
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

        if ($worker->status === WorkerBuildIdRollout::DRAIN_INTENT_DRAINING) {
            return $this->drainingWorkerPollResponse($workerId, $taskQueue, $registeredBuildId);
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

    /**
     * Derive the worker status to stamp on register/heartbeat from operator
     * rollout intent. If an operator has marked this build_id cohort as
     * draining, incoming worker rows stay draining across heartbeats so the
     * drain intent cannot be clobbered by ordinary polling traffic.
     */
    private function workerRegistrationStatus(
        string $namespace,
        string $taskQueue,
        ?string $buildId,
    ): string {
        $key = WorkerBuildIdRollout::buildIdKey($buildId);

        $rollout = WorkerBuildIdRollout::query()
            ->where('namespace', $namespace)
            ->where('task_queue', $taskQueue)
            ->where('build_id', $key)
            ->first();

        if ($rollout instanceof WorkerBuildIdRollout && $rollout->isDraining()) {
            return WorkerBuildIdRollout::DRAIN_INTENT_DRAINING;
        }

        return 'active';
    }
}
