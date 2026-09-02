<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkerBuildIdRollout;
use App\Models\WorkerRegistration;
use App\Support\AvroPayloadEnvelopeResolver;
use App\Support\BackendLockPressure;
use App\Support\CachedPollTaskKindConflict;
use App\Support\ExternalPayloadStorageUnavailable;
use App\Support\HistoryRetentionEnforcer;
use App\Support\LongPollCapacityExhaustedException;
use App\Support\MessageStreamsContract;
use App\Support\MessageStreamService;
use App\Support\NamespaceDurableStateQuota;
use App\Support\NamespaceExternalPayloadStorage;
use App\Support\NamespaceWorkflowScope;
use App\Support\PayloadCodecContract;
use App\Support\PollRequestTaskKindsConflict;
use App\Support\QueryTaskQueueUnavailableException;
use App\Support\RuntimeExternalPayloadAudit;
use App\Support\RuntimeExternalPayloadException;
use App\Support\SearchAttributeValueValidator;
use App\Support\ServiceModeTimerDispatcher;
use App\Support\StreamClosedException;
use App\Support\StreamErroredException;
use App\Support\StreamFullException;
use App\Support\StreamNotFoundException;
use App\Support\WorkerCompatibilityHeartbeatRecorder;
use App\Support\WorkerPollBackpressure;
use App\Support\WorkerPollFence;
use App\Support\WorkerProtocol;
use App\Support\WorkerProtocolMutationRetrier;
use App\Support\WorkerTerminalEventAttribution;
use App\Support\WorkflowMetadataCapabilityPolicy;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowStreamCommandProcessor;
use App\Support\WorkflowStreamService;
use App\Support\WorkflowTaskLeaseRecovery;
use App\Support\WorkflowTaskPoller;
use App\Support\WorkflowUpdateValidationTaskBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Contracts\WorkflowTaskBridge;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Exceptions\ExternalPayloadIntegrityException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\StickyExecution;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowCommandNormalizer;
use Workflow\V2\Support\WorkflowTaskOwnership;

class WorkerController
{
    private const WORKFLOW_TASK_FAILURE_REASON_MAX_LENGTH = 191;

    private const WORKFLOW_TASK_FAILURE_TYPE_MAX_LENGTH = 512;

    private const STRUCTURED_REPLAY_FAILURE_REASONS = [
        'activity_execution_mode_mismatch',
        'activity_identity_mismatch',
        'activity_retry_policy_mismatch',
        'activity_task_queue_mismatch',
        'child_workflow_identity_mismatch',
        'condition_wait_definition_history_mismatch',
        'condition_wait_definition_invalid',
        'condition_wait_id_mismatch',
        'condition_wait_id_missing',
        'condition_wait_key_mismatch',
        'condition_wait_occurrence_history_mismatch',
        'condition_wait_occurrence_id_missing',
        'condition_wait_occurrence_mismatch',
        'condition_wait_predicate_fingerprint_missing',
        'condition_wait_predicate_mismatch',
        'condition_wait_reopened_after_timeout',
        'condition_wait_terminal_conflict',
        'condition_wait_timeout_delay_mismatch',
        'condition_wait_timeout_history_invalid',
        'condition_wait_timeout_identity_mismatch',
        'condition_wait_timeout_mismatch',
        'continue_as_new_sequence_missing',
        'duplicate_memo_upsert_record',
        'duplicate_version_marker',
        'duplicate_version_marker_record',
        'durable_command_sequence_collision',
        'durable_command_sequence_invalid',
        'durable_command_sequence_mismatch',
        'durable_command_sequence_missing',
        'memo_entries_invalid',
        'memo_entries_missing',
        'memo_merged_projection_invalid',
        'memo_merged_projection_missing',
        'memo_sequence_invalid',
        'memo_sequence_missing',
        'memo_update_mismatch',
        'parallel_group_history_conflict',
        'parallel_group_metadata_invalid',
        'parallel_group_metadata_missing',
        'parallel_group_missing_from_workflow',
        'parallel_group_partially_scheduled',
        'parallel_group_shape_mismatch',
        'recorded_command_detail_mismatch',
        'recorded_command_mismatch',
        'recorded_commands_unconsumed',
        'recorded_continue_as_new_unconsumed',
        'search_attribute_type_mismatch',
        'search_attribute_update_missing',
        'search_attribute_value_mismatch',
        'side_effect_result_missing',
        'side_effect_type_mismatch',
        'signal_wait_identity_mismatch',
        'signal_wait_name_missing',
        'timer_delay_mismatch',
        'timer_history_delay_mismatch',
        'timer_history_field_missing',
        'timer_identity_mismatch',
        'unsupported_payload_codec',
        'version_change_id_invalid',
        'version_change_id_mismatch',
        'version_marker_field_missing',
        'version_marker_history_range_invalid',
        'version_marker_incompatible_range',
        'version_marker_kind_mismatch',
        'version_marker_sequence_invalid',
        'version_range_invalid',
        'workflow_nondeterministic',
    ];

    public function __construct(
        private readonly WorkflowTaskPoller $workflowTaskPoller,
        private readonly WorkflowTaskLeaseRecovery $workflowTaskLeaseRecovery,
        private readonly WorkflowTaskOwnership $taskOwnership,
        private readonly WorkflowQueryTaskBroker $queryTasks,
        private readonly WorkflowUpdateValidationTaskBroker $updateValidationTasks,
        private readonly NamespaceExternalPayloadStorage $externalPayloadStorage,
        private readonly SearchAttributeValueValidator $searchAttributeValues,
        private readonly WorkerTerminalEventAttribution $terminalEventAttribution,
        private readonly WorkerCompatibilityHeartbeatRecorder $compatibilityHeartbeats,
        private readonly WorkerProtocolMutationRetrier $storageMutations,
        private readonly MessageStreamService $messageStreams,
        private readonly NamespaceDurableStateQuota $durableStateQuota,
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
            'workflow_command_contracts' => ['nullable', 'array'],
            'supported_activity_types' => ['nullable', 'array'],
            'supported_activity_types.*' => ['string'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'max:255'],
            'capability_manifest' => [
                WorkerProtocol::portableWorkerAffinitySupported(WorkerProtocol::requestVersion($request))
                    ? 'required'
                    : 'nullable',
                'array',
                'required_array_keys:local_activities,worker_sessions,sticky_execution',
            ],
            'capability_manifest.*' => ['array'],
            'capability_manifest.*.supported' => ['required', 'boolean'],
            'capability_manifest.*.minimum_protocol_version' => ['required', 'string', 'max:32'],
            'capability_manifest.*.implementation' => ['nullable', 'string', 'max:255'],
            'capability_manifest.*.reason' => ['nullable', 'string', 'max:255'],
            'max_concurrent_workflow_tasks' => ['nullable', 'integer', 'min:0'],
            'max_concurrent_activity_tasks' => ['nullable', 'integer', 'min:0'],
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

        $workerCapabilities = $this->nonEmptyStringArray($validated['capabilities'] ?? []);
        $capabilityManifest = $this->portableWorkerCapabilityManifest(
            is_array($validated['capability_manifest'] ?? null) ? $validated['capability_manifest'] : [],
        );
        if ($response = $this->guardPortableWorkerCapabilityManifest(
            $request,
            $workerCapabilities,
            $capabilityManifest,
        )) {
            return $response;
        }
        $metadataCapabilityProtocolMismatch = WorkflowMetadataCapabilityPolicy::firstProtocolMismatch(
            $workerCapabilities,
            WorkerProtocol::requestVersion($request),
        );

        if ($metadataCapabilityProtocolMismatch !== null) {
            return WorkerProtocol::json([
                'registered' => false,
                'reason' => 'workflow_metadata_capability_protocol_mismatch',
                'capability' => $metadataCapabilityProtocolMismatch['capability'],
                'requested_version' => WorkerProtocol::requestVersion($request),
                'minimum_protocol_version' => $metadataCapabilityProtocolMismatch['minimum_protocol_version'],
                'remediation' => sprintf(
                    'Advertise %s only while sending worker protocol %s or newer.',
                    $metadataCapabilityProtocolMismatch['capability'],
                    $metadataCapabilityProtocolMismatch['minimum_protocol_version'],
                ),
            ], 409);
        }

        if (in_array(
            MessageStreamsContract::CAPABILITY,
            $workerCapabilities,
            true,
        ) && ! WorkerProtocol::messageStreamsAvailableForRequest($request)) {
            return WorkerProtocol::json([
                'registered' => false,
                'reason' => 'message_streams_unavailable',
                'requested_version' => WorkerProtocol::requestVersion($request),
                'minimum_protocol_version' => MessageStreamsContract::MINIMUM_WORKER_PROTOCOL_VERSION,
                'remediation' => sprintf(
                    'Advertise message_streams only while sending worker protocol %s or newer.',
                    MessageStreamsContract::MINIMUM_WORKER_PROTOCOL_VERSION,
                ),
            ], 409);
        }

        $workerId = $validated['worker_id'] ?? Str::ulid()->toBase32();
        $workflowDefinitionFingerprints = $this->workflowDefinitionFingerprints(
            $validated['workflow_definition_fingerprints'] ?? []
        );
        $workflowCommandContracts = $this->workflowCommandContracts(
            $validated['workflow_command_contracts'] ?? []
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

            if (! $request->has('workflow_command_contracts')) {
                $workflowCommandContracts = $this->workflowCommandContracts(
                    $existing->workflow_command_contracts ?? []
                );
            }
        }

        $workflowCommandContracts = $this->filterWorkflowCommandContracts(
            $workflowCommandContracts,
            $validated['supported_workflow_types'] ?? null,
        );

        $registrationStatus = $this->workerRegistrationStatus(
            $namespace,
            $validated['task_queue'],
            $validated['build_id'] ?? null,
        );

        $maxWorkflowTasks = $validated['max_concurrent_workflow_tasks'] ?? 100;
        $maxActivityTasks = $validated['max_concurrent_activity_tasks'] ?? 100;
        $this->validateWorkerTaskCapacity($maxWorkflowTasks, $maxActivityTasks);
        $maxWorkerSessions = $validated['max_concurrent_worker_sessions'] ?? 10;
        $taskSlots = is_array($validated['task_slots'] ?? null) ? $validated['task_slots'] : [];
        $processMetrics = $this->normalizeProcessMetrics($validated['process_metrics'] ?? null);
        $releaseLeasesForRegistration = $this->shouldReleaseLeasesForWorkerRegistration($existing, $processMetrics);

        try {
            $registration = $this->durableStateQuota->mutate(
                (string) $namespace,
                [NamespaceDurableStateQuota::WORKER_REGISTRATIONS],
                fn (): WorkerRegistration => $this->storageMutations->run(function () use (
                    $namespace,
                    $workerId,
                    $validated,
                    $workflowDefinitionFingerprints,
                    $workflowCommandContracts,
                    $maxWorkflowTasks,
                    $maxActivityTasks,
                    $maxWorkerSessions,
                    $taskSlots,
                    $processMetrics,
                    $registrationStatus,
                    $releaseLeasesForRegistration,
                    $workerCapabilities,
                    $capabilityManifest,
                ): WorkerRegistration {
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
                            'workflow_command_contracts' => $workflowCommandContracts,
                            'supported_activity_types' => $validated['supported_activity_types'] ?? [],
                            'capabilities' => $workerCapabilities,
                            'capability_manifest' => $capabilityManifest,
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

                    $this->compatibilityHeartbeats->record(
                        namespace: $namespace,
                        workerId: $workerId,
                        taskQueue: $validated['task_queue'],
                        buildId: $validated['build_id'] ?? null,
                        force: true,
                    );

                    if (is_string($namespace)) {
                        $this->queryTasks->wakeTaskQueue($namespace, $registration->task_queue);
                    }

                    return $registration;
                }),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerRegistrationResponse($request, $workerId);
        }

        return WorkerProtocol::json([
            'worker_id' => $workerId,
            'registered' => true,
            'namespace' => $registration->namespace,
            'task_queue' => $registration->task_queue,
            'runtime' => $registration->runtime,
            'build_id' => $registration->build_id,
            'capabilities' => $this->nonEmptyStringArray($registration->capabilities),
            'capability_manifest' => $registration->capability_manifest ?? [],
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
     * @param  array<array-key, mixed>  $contracts
     * @return array<string, array<string, mixed>>
     */
    private function workflowCommandContracts(array $contracts): array
    {
        $normalized = [];

        foreach ($contracts as $workflowType => $contract) {
            if (! is_string($workflowType) || ! is_array($contract)) {
                continue;
            }

            $workflowType = trim($workflowType);

            if ($workflowType === '') {
                continue;
            }

            $commandContract = $this->workflowCommandContract($contract);

            if ($commandContract === null) {
                continue;
            }

            $normalized[$workflowType] = $commandContract;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>|null
     */
    private function workflowCommandContract(array $contract): ?array
    {
        $normalized = [
            'queries' => $this->nonEmptyStringArray($contract['queries'] ?? []),
            'query_contracts' => $this->commandHandlerContracts($contract['query_contracts'] ?? []),
            'signals' => $this->nonEmptyStringArray($contract['signals'] ?? []),
            'signal_contracts' => $this->commandHandlerContracts($contract['signal_contracts'] ?? []),
            'updates' => $this->nonEmptyStringArray($contract['updates'] ?? []),
            'update_contracts' => $this->commandHandlerContracts($contract['update_contracts'] ?? []),
        ];

        if (array_key_exists(WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD, $contract)) {
            $normalized[WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD] = $this->nonEmptyStringArray(
                $contract[WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD],
            );
        }

        if ($normalized['queries'] === []
            && $normalized['query_contracts'] === []
            && $normalized['signals'] === []
            && $normalized['signal_contracts'] === []
            && $normalized['updates'] === []
            && $normalized['update_contracts'] === []
        ) {
            return null;
        }

        foreach ($normalized['query_contracts'] as $handlerContract) {
            $normalized['queries'][] = $handlerContract['name'];
        }

        foreach ($normalized['signal_contracts'] as $handlerContract) {
            $normalized['signals'][] = $handlerContract['name'];
        }

        foreach ($normalized['update_contracts'] as $handlerContract) {
            $normalized['updates'][] = $handlerContract['name'];
        }

        $normalized['queries'] = $this->sortedUniqueStrings($normalized['queries']);
        $normalized['signals'] = $this->sortedUniqueStrings($normalized['signals']);
        $normalized['updates'] = $this->sortedUniqueStrings($normalized['updates']);

        if (isset($normalized[WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD])) {
            $normalized[WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD] = $this->sortedUniqueStrings(
                $normalized[WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD],
            );
        }

        return $normalized;
    }

    /**
     * @return list<array{name: string, parameters: list<array<string, mixed>>}>
     */
    private function commandHandlerContracts(mixed $contracts): array
    {
        if (! is_array($contracts) || ! array_is_list($contracts)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($contracts as $contract) {
            if (! is_array($contract)) {
                continue;
            }

            $name = $this->stringValue($contract['name'] ?? null);

            if ($name === null || in_array($name, $seen, true)) {
                continue;
            }

            $seen[] = $name;
            $normalized[] = [
                'name' => $name,
                'parameters' => $this->commandHandlerParameters($contract['parameters'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commandHandlerParameters(mixed $parameters): array
    {
        if (! is_array($parameters) || ! array_is_list($parameters)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        $position = 0;

        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = $this->stringValue($parameter['name'] ?? null);

            if ($name === null || in_array($name, $seen, true)) {
                continue;
            }

            $seen[] = $name;
            $type = $this->stringValue($parameter['type'] ?? null);
            $normalized[] = [
                'name' => $name,
                'position' => $this->intValue($parameter['position'] ?? null) ?? $position,
                'required' => (bool) ($parameter['required'] ?? ! array_key_exists('default', $parameter)),
                'variadic' => (bool) ($parameter['variadic'] ?? false),
                'default_available' => (bool) ($parameter['default_available'] ?? array_key_exists('default', $parameter)),
                'default' => $parameter['default'] ?? null,
                'type' => $type,
                'allows_null' => (bool) ($parameter['allows_null'] ?? true),
            ];
            $position++;
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<string, mixed>>  $contracts
     * @param  array<array-key, mixed>|null  $supportedWorkflowTypes
     * @return array<string, array<string, mixed>>
     */
    private function filterWorkflowCommandContracts(array $contracts, ?array $supportedWorkflowTypes): array
    {
        if ($supportedWorkflowTypes === null) {
            return $contracts;
        }

        $supported = $this->nonEmptyStringArray($supportedWorkflowTypes);

        if ($supported === []) {
            return [];
        }

        return array_intersect_key($contracts, array_flip($supported));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedUniqueStrings(array $values): array
    {
        $values = array_values(array_unique(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));

        sort($values);

        return $values;
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

        if ($worker->status === WorkerRegistration::STATUS_SUPERSEDED) {
            return WorkerProtocol::json([
                'error' => 'Worker registration was fenced after its leases were recovered.',
                'reason' => 'worker_registration_superseded',
                'worker_id' => $worker->worker_id,
                'remediation' => 'Restart and register the worker with a new worker_id.',
            ], 409);
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

        try {
            $retention = $this->storageMutations->run(function () use ($worker, $update, $namespace): array {
                $worker->update($update);

                $this->compatibilityHeartbeats->record(
                    namespace: $worker->namespace,
                    workerId: $worker->worker_id,
                    taskQueue: $worker->task_queue,
                    buildId: is_string($worker->build_id) ? $worker->build_id : null,
                );

                return HistoryRetentionEnforcer::runInlinePass($namespace);
            });
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerHeartbeatResponse($request);
        }

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
     * @throws ValidationException
     */
    private function validateWorkerTaskCapacity(int $maxWorkflowTasks, int $maxActivityTasks): void
    {
        if ($maxWorkflowTasks > 0 || $maxActivityTasks > 0) {
            return;
        }

        throw ValidationException::withMessages([
            'max_concurrent_workflow_tasks' => [
                'At least one of max_concurrent_workflow_tasks or max_concurrent_activity_tasks must be greater than 0.',
            ],
            'max_concurrent_activity_tasks' => [
                'At least one of max_concurrent_workflow_tasks or max_concurrent_activity_tasks must be greater than 0.',
            ],
        ]);
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
        if (! $existing instanceof WorkerRegistration) {
            return false;
        }

        if ($this->workerHasLeasedTasks($existing->namespace, $existing->worker_id)) {
            // A fresh registration is the only immediate signal that a worker
            // process using the same worker_id may have replaced a process that
            // died mid-task. Reclaim its leases before the replacement polls so
            // recovery does not wait for the full task lease timeout.
            return true;
        }

        if ($existing->status !== 'active') {
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

    private function workerHasLeasedTasks(string $namespace, string $workerId): bool
    {
        return WorkflowTask::query()
            ->where('namespace', $namespace)
            ->whereIn('task_type', [TaskType::Workflow->value, TaskType::Activity->value])
            ->where('status', TaskStatus::Leased->value)
            ->where('lease_owner', $workerId)
            ->exists();
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
        WorkflowRun::query()
            ->where('namespace', $namespace)
            ->where('sticky_worker_id', $workerId)
            ->update([
                'sticky_worker_id' => null,
                'sticky_until' => null,
            ]);

        WorkflowTask::query()
            ->where('namespace', $namespace)
            ->where('sticky_worker_id', $workerId)
            ->update([
                'sticky_worker_id' => null,
                'sticky_until' => null,
                'sticky_replay_mode' => StickyExecution::MODE_FORCED_COLD_REPLAY,
                'sticky_claimed_at' => now(),
            ]);

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
                'sticky_replay_mode' => StickyExecution::MODE_FORCED_COLD_REPLAY,
                'sticky_claimed_at' => now(),
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
        $configured = (int) config('server.workers.heartbeat_interval_seconds', 10);

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
            'timeout_seconds' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
            ],
            'history_page_size' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            ],
            'accept_history_encoding' => ['nullable', 'string', 'max:64'],
            'task_kinds' => ['nullable', 'array', 'min:1', 'max:2'],
            'task_kinds.*' => ['string', 'distinct', 'in:workflow,update_validation'],
        ]);

        $maxPageSize = (int) config(
            'server.worker_protocol.history_page_size_max',
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
        );
        $requestedPageSize = $validated['history_page_size'] ?? null;
        $pageSize = $requestedPageSize === null
            ? null
            : min((int) $requestedPageSize, $maxPageSize);

        $acceptHistoryEncoding = $validated['accept_history_encoding'] ?? null;
        $timeoutSeconds = isset($validated['timeout_seconds'])
            ? (int) $validated['timeout_seconds']
            : null;
        $taskKinds = isset($validated['task_kinds']) && is_array($validated['task_kinds'])
            ? array_values($validated['task_kinds'])
            : ['workflow'];

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

        if (in_array('update_validation', $taskKinds, true) && ! in_array(
            WorkflowUpdateValidationTaskBroker::CAPABILITY,
            is_array($worker->capabilities) ? $worker->capabilities : [],
            true,
        )) {
            return WorkerProtocol::json([
                'task' => null,
                'poll_status' => 'unsupported',
                'reason' => 'update_validation_capability_not_advertised',
                'error' => 'Worker registration does not advertise synchronous update validation.',
            ], 409);
        }

        try {
            $poll = $this->workflowTaskPoller->poll(
                request: $request,
                namespace: $namespace,
                taskQueue: $validated['task_queue'],
                leaseOwner: $validated['worker_id'],
                buildId: $registeredBuildId,
                worker: $worker,
                pollRequestId: $validated['poll_request_id'] ?? null,
                historyPageSize: $pageSize,
                acceptHistoryEncoding: $acceptHistoryEncoding,
                supportedWorkflowTypes: $supportedWorkflowTypes,
                workflowDefinitionFingerprints: $this->workflowDefinitionFingerprints(
                    $worker->workflow_definition_fingerprints ?? [],
                ),
                acceptsQueryTasks: $this->queryTasks->workerAcceptsQueryTasks(
                    $namespace,
                    $worker,
                ),
                timeoutSeconds: $timeoutSeconds,
                taskKinds: $taskKinds,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException
                && str_contains($exception->getMessage(), 'unsupported_payload_codec:')) {
                return WorkerProtocol::json([
                    'task' => null,
                    'poll_status' => 'rejected',
                    'reason' => 'unsupported_payload_codec',
                    'error' => $exception->getMessage(),
                ], 422);
            }

            if ($exception instanceof CachedPollTaskKindConflict) {
                return WorkerProtocol::json([
                    'task' => null,
                    'poll_status' => 'conflict',
                    'reason' => 'poll_cached_task_kind_conflict',
                    'error' => $exception->getMessage(),
                    'poll_request_id' => $exception->pollRequestId,
                    'requested_task_kinds' => $exception->requestedTaskKinds,
                    'cached_task_kind' => $exception->cachedTaskKind,
                    'cached_task_kind_state' => $exception->cachedTaskKindState,
                ], 409);
            }

            if ($exception instanceof PollRequestTaskKindsConflict) {
                return WorkerProtocol::json([
                    'task' => null,
                    'poll_status' => 'conflict',
                    'reason' => 'poll_request_task_kinds_conflict',
                    'error' => $exception->getMessage(),
                    'poll_request_id' => $exception->pollRequestId,
                    'requested_task_kinds' => $exception->requestedTaskKinds,
                    'bound_task_kinds' => $exception->boundTaskKinds,
                ], 409);
            }

            if ($exception instanceof LongPollCapacityExhaustedException) {
                return WorkerPollBackpressure::response(
                    'workflow_task',
                    $namespace,
                    $validated['task_queue'],
                    $exception,
                );
            }

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

        $history = $this->workflowTaskPoller->historyPage(
            $namespace,
            $taskId,
            $afterSequence,
            $pageSize,
            $validated['accept_history_encoding'] ?? null,
        );

        if (! is_array($history)) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'error' => 'Workflow task history not available.',
                'reason' => 'history_not_available',
            ], 404);
        }

        $hasMore = $history['has_more'];
        $nextAfterSequence = $history['next_after_sequence'] ?? null;

        $response = [
            'task_id' => $taskId,
            'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
            'history_events' => $history['history_events'] ?? [],
            'total_history_events' => $history['total_history_events'],
            'history_size_bytes' => $history['history_size_bytes'],
            'history_fan_out' => $history['history_fan_out'],
            'continue_as_new_recommended' => $history['continue_as_new_recommended'],
            'history_budget_pressure' => $history['history_budget_pressure'],
            'history_budget_pressure_dimensions' => $history['history_budget_pressure_dimensions'],
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

        $messageStreamCompletion = $request->validate([
            'message_stream_cursors' => ['nullable', 'array', 'max:100'],
            'message_stream_cursors.*.stream_name' => ['required', 'string', 'max:128'],
            'message_stream_cursors.*.through_position' => ['required', 'integer', 'min:0'],
            'message_stream_waits' => ['nullable', 'array', 'max:100'],
            'message_stream_waits.*.stream_name' => ['required', 'string', 'max:128'],
            'message_stream_waits.*.after_position' => ['required', 'integer', 'min:0'],
        ]);

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
            'commands.*.retry_policy.*' => ['nullable'],
            'commands.*.start_to_close_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.schedule_to_start_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.schedule_to_close_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.heartbeat_timeout' => ['nullable', 'integer', 'min:1'],
            'commands.*.execution_mode' => ['nullable', 'string', 'in:local'],
            'commands.*.outcome' => ['nullable', 'string', 'in:completed,failed,timed_out,cancelled'],
            'commands.*.attempts' => ['nullable', 'array', 'max:100'],
            'commands.*.attempts.*.attempt_id' => ['nullable', 'string', 'max:255'],
            'commands.*.attempts.*.attempt_number' => ['required', 'integer', 'min:1'],
            'commands.*.attempts.*.outcome' => ['required', 'string', 'in:completed,failed,timed_out,cancelled'],
            'commands.*.attempts.*.duration_ms' => ['nullable', 'integer', 'min:0'],
            'commands.*.attempts.*.message' => ['nullable', 'string'],
            'commands.*.attempts.*.exception_type' => ['nullable', 'string', 'max:255'],
            'commands.*.attempts.*.non_retryable' => ['nullable', 'boolean'],
            'commands.*.attempts.*.timeout_kind' => ['nullable', 'string', 'in:start_to_close,schedule_to_close,heartbeat'],
            'commands.*.attempts.*.retry_reason' => ['nullable', 'string', 'in:failure,timeout,cold_replay'],
            'commands.*.attempts.*.backoff_seconds' => ['nullable', 'integer', 'min:0'],
            'commands.*.attempts.*.heartbeats' => ['nullable', 'array', 'max:1000'],
            'commands.*.attempts.*.heartbeats.*.details' => ['nullable', 'array'],
            'commands.*.attempts.*.heartbeats.*.elapsed_ms' => ['nullable', 'integer', 'min:0'],
            'commands.*.attempts.*.heartbeats.*.*' => ['nullable'],
            'commands.*.attempts.*.*' => ['nullable'],
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
            'commands.*.timeout_kind' => ['nullable', 'string', 'in:start_to_close,schedule_to_close,heartbeat'],
            'commands.*.payload_codec' => ['nullable', 'string'],
            'commands.*.update_id' => ['nullable', 'string'],
            'commands.*.exception_class' => ['nullable', 'string'],
            'commands.*.exception_type' => ['nullable', 'string'],
            'commands.*.exception' => ['nullable', 'array'],
            'commands.*.change_id' => ['nullable', 'string'],
            'commands.*.version' => ['nullable', 'integer'],
            'commands.*.min_supported' => ['nullable', 'integer'],
            'commands.*.max_supported' => ['nullable', 'integer'],
            'commands.*.attributes' => ['nullable', 'array'],
            'commands.*.attribute_types' => ['nullable', 'array'],
            'commands.*.attribute_types.*' => ['string'],
            'commands.*.entries' => ['nullable', 'array'],
            'commands.*.non_retryable' => ['nullable', 'boolean'],
            'commands.*.parent_close_policy' => ['nullable', 'string'],
            'commands.*.condition_key' => ['nullable', 'string'],
            'commands.*.condition_definition_fingerprint' => ['nullable', 'string'],
            'commands.*.condition_wait_occurrence_id' => ['nullable', 'string'],
            'commands.*.signal_name' => ['nullable', 'string'],
            'commands.*.timeout_seconds' => ['nullable', 'integer', 'min:0'],
            ...WorkflowCommandNormalizer::parallelMetadataValidationRules(),
            'commands.*.workflow_stream' => ['nullable', 'array'],
            'commands.*.workflow_stream.operation' => ['required_with:commands.*.workflow_stream', 'string', 'in:append,close,error'],
            'commands.*.workflow_stream.stream_name' => ['required_with:commands.*.workflow_stream', 'string', 'max:191'],
            'commands.*.workflow_stream.command_identity' => ['required_with:commands.*.workflow_stream', 'string', 'max:191'],
            'commands.*.workflow_stream.command_ordinal' => ['required_with:commands.*.workflow_stream', 'integer', 'min:0'],
            'commands.*.workflow_stream.items' => ['nullable', 'array', 'max:'.WorkflowStreamService::DEFAULT_MAX_ITEMS_PER_APPEND],
            'commands.*.workflow_stream.items.*' => ['array'],
            'commands.*.workflow_stream.items.*.payload' => ['nullable'],
            'commands.*.workflow_stream.items.*.payload_reference' => ['nullable', 'string', 'max:191'],
            'commands.*.workflow_stream.items.*.payload_codec' => ['nullable', 'string', 'max:64'],
            'commands.*.workflow_stream.items.*.idempotency_key' => ['nullable', 'string', 'max:191'],
            'commands.*.workflow_stream.items.*.item_type' => ['nullable', 'string', 'max:64'],
            'commands.*.workflow_stream.items.*.content_type' => ['nullable', 'string', 'max:191'],
            'commands.*.workflow_stream.max_pending_items' => ['nullable', 'integer', 'min:1'],
            'commands.*.workflow_stream.error_reason' => ['nullable', 'string', 'max:191'],
            'commands.*.workflow_stream.retention_seconds' => ['nullable', 'integer', 'min:1'],
            'sticky_cache' => ['nullable', 'array'],
            'sticky_cache.worker_id' => ['required_with:sticky_cache', 'string', 'max:255'],
            'sticky_cache.workflow_id' => ['required_with:sticky_cache', 'string', 'max:255'],
            'sticky_cache.run_id' => ['required_with:sticky_cache', 'string', 'max:255'],
            'sticky_cache.build_id' => ['required_with:sticky_cache', 'string', 'max:255'],
            'sticky_cache.ttl_seconds' => ['required_with:sticky_cache', 'integer', 'min:1', 'max:3600'],
            'sticky_cache.metrics' => ['nullable', 'array'],
            'sticky_cache.metrics.hit' => ['nullable', 'integer', 'min:0'],
            'sticky_cache.metrics.miss' => ['nullable', 'integer', 'min:0'],
            'sticky_cache.metrics.eviction' => ['nullable', 'integer', 'min:0'],
            'sticky_cache.metrics.forced_cold_replay' => ['nullable', 'integer', 'min:0'],
        ]);

        $commands = $this->normalizeWorkflowTaskCommandIntegerFields($validated['commands']);
        $commands = WorkflowCommandNormalizer::preflightParallelMetadata($commands);
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

        if ($response = $this->guardConditionWaitOccurrenceIdentityAvailable(
            $request,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $commands,
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

        if ($response = $this->guardPortableWorkerAffinityCompletion(
            $request,
            (string) $namespace,
            $validated['lease_owner'],
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $commands,
            is_array($validated['sticky_cache'] ?? null) ? $validated['sticky_cache'] : null,
        )) {
            return $response;
        }

        if ($response = $this->guardWorkflowMemoUpdatesAvailable(
            $request,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $commands,
        )) {
            return $response;
        }

        if ($response = $this->guardTypedSearchAttributeCommandsAvailable(
            $request,
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $commands,
        )) {
            return $response;
        }

        if ($response = $this->guardWorkflowMetadataCapabilitiesAvailable(
            $namespace,
            $validated['lease_owner'],
            $taskId,
            (int) $validated['workflow_task_attempt'],
            $commands,
        )) {
            return $response;
        }

        $messageStreamCursors = array_values($messageStreamCompletion['message_stream_cursors'] ?? []);
        $messageStreamWaits = array_values($messageStreamCompletion['message_stream_waits'] ?? []);
        if (($messageStreamCursors !== [] || $messageStreamWaits !== [])
            && ! WorkerProtocol::messageStreamsAvailableForRequest($request)) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'reason' => 'message_streams_unavailable',
                'requested_version' => WorkerProtocol::requestVersion($request),
                'minimum_protocol_version' => MessageStreamsContract::MINIMUM_WORKER_PROTOCOL_VERSION,
                'remediation' => sprintf(
                    'Send the %s header with worker protocol %s or newer.',
                    WorkerProtocol::HEADER,
                    MessageStreamsContract::MINIMUM_WORKER_PROTOCOL_VERSION,
                ),
            ], 409);
        }
        $this->messageStreams->validateCompletion(
            (string) $namespace,
            $taskId,
            $messageStreamCursors,
            $messageStreamWaits,
        );

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

        $commands = $this->promoteWorkflowFailureExceptionPayload($commands);
        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        $workflowTaskQueue = WorkflowTask::query()
            ->whereKey($taskId)
            ->value('queue');

        try {
            $outcome = $this->storageMutations->run(
                function () use (
                    $bridge,
                    $commands,
                    $request,
                    $taskId,
                    $namespace,
                    $validated,
                    $messageStreamCursors,
                    $messageStreamWaits,
                ): array|JsonResponse {
                    return DB::transaction(function () use (
                        $bridge,
                        $commands,
                        $request,
                        $taskId,
                        $namespace,
                        $validated,
                        $messageStreamCursors,
                        $messageStreamWaits,
                    ): array|JsonResponse {
                        $quotaSnapshot = $this->durableStateQuota->snapshotForMutation(
                            (string) $namespace,
                            [
                                NamespaceDurableStateQuota::WORKFLOW_INSTANCES,
                                NamespaceDurableStateQuota::WORKFLOW_RUNS,
                                NamespaceDurableStateQuota::OPEN_WORKFLOW_RUNS,
                            ],
                        );
                        $leaseWorker = WorkerRegistration::query()
                            ->where('namespace', $namespace)
                            ->where('worker_id', $validated['lease_owner'])
                            ->lockForUpdate()
                            ->first();
                        NamespaceWorkflowScope::taskQuery((string) $namespace)
                            ->whereKey($taskId)
                            ->lockForUpdate()
                            ->first();

                        if ($response = $this->guardWorkflowTaskOwnership(
                            $request,
                            (string) $namespace,
                            $taskId,
                            (int) $validated['workflow_task_attempt'],
                            $validated['lease_owner'],
                        )) {
                            return $response;
                        }

                        if ($response = $this->guardWorkflowMetadataCapabilitiesAvailable(
                            $namespace,
                            $validated['lease_owner'],
                            $taskId,
                            (int) $validated['workflow_task_attempt'],
                            $commands,
                            $leaseWorker,
                        )) {
                            return $response;
                        }

                        if ($response = $this->guardPortableWorkerAffinityCompletion(
                            $request,
                            (string) $namespace,
                            $validated['lease_owner'],
                            $taskId,
                            (int) $validated['workflow_task_attempt'],
                            $commands,
                            is_array($validated['sticky_cache'] ?? null) ? $validated['sticky_cache'] : null,
                            $leaseWorker,
                        )) {
                            return $response;
                        }

                        $commands = $this->canonicalizeWorkflowStreamPayloadCodecs($commands);
                        $commands = app(WorkflowStreamCommandProcessor::class)->process(
                            $taskId,
                            (string) $namespace,
                            $commands,
                        );
                        $commands = WorkflowCommandNormalizer::normalize(
                            $commands,
                            WorkerProtocol::requestVersion($request),
                        );
                        $outcome = $bridge->complete($taskId, $commands);
                        $this->applyStickyCacheClaim(
                            $taskId,
                            $validated['lease_owner'],
                            is_array($validated['sticky_cache'] ?? null) ? $validated['sticky_cache'] : null,
                            $leaseWorker,
                            $outcome,
                        );
                        $this->persistTypedSearchAttributeHistoryIdentity($taskId, $commands, $outcome);
                        $this->terminalEventAttribution->record($request, $taskId, $outcome);
                        $this->messageStreams->recordCompletion(
                            (string) $namespace,
                            $taskId,
                            $messageStreamCursors,
                            $messageStreamWaits,
                            $outcome,
                        );
                        $this->durableStateQuota->assertNoIncreasePastLimit($quotaSnapshot);

                        return $outcome;
                    });
                },
            );
        } catch (ValidationException $exception) {
            if (! $this->commandsUseWorkflowMemoUpdates($commands)) {
                throw $exception;
            }

            return $this->workflowMemoValidationFailure(
                $taskId,
                (int) $validated['workflow_task_attempt'],
                $exception,
            );
        } catch (StructuralLimitExceededException $e) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'recorded' => false,
                'error' => $e->getMessage(),
                'reason' => 'structural_limit_exceeded',
                'limit_kind' => $e->limitKind->value,
                'current_value' => $e->currentValue,
                'configured_limit' => $e->configuredLimit,
            ], 422);
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return $this->externalPayloadFailure($taskId, (int) $validated['workflow_task_attempt'], $exception, 503);
        } catch (StreamFullException $exception) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'recorded' => false,
                'reason' => 'stream_full',
                'pending_items' => (int) $exception->stream->pending_items,
                'max_pending_items' => $exception->maxPendingItems,
            ], 429);
        } catch (StreamClosedException|StreamErroredException $exception) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'recorded' => false,
                'reason' => $exception instanceof StreamClosedException ? 'stream_closed' : 'stream_errored',
            ], 409);
        } catch (StreamNotFoundException $exception) {
            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'recorded' => false,
                'reason' => 'stream_not_found',
            ], 404);
        } catch (\InvalidArgumentException $exception) {
            if ($this->commandsUseWorkflowMemoUpdates($commands)) {
                return $this->workflowMemoValidationFailure(
                    $taskId,
                    (int) $validated['workflow_task_attempt'],
                    $exception,
                );
            }

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'rejected',
                'recorded' => false,
                'reason' => 'invalid_workflow_stream_command',
                'error' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }

        if ($outcome instanceof JsonResponse) {
            return $outcome;
        }

        try {
            $this->storageMutations->run(
                static fn () => app(ServiceModeTimerDispatcher::class)
                    ->dispatchCreatedTaskIds($outcome['created_task_ids'] ?? []),
            );
            $this->storageMutations->run(
                fn () => $this->wakeQueryTaskPollersForWorkflowTaskQueue($namespace, $workflowTaskQueue),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, true);
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
     * @param  array<string, mixed>  $manifest
     * @return array<string, array<string, mixed>>
     */
    private function portableWorkerCapabilityManifest(array $manifest): array
    {
        $normalized = [];

        foreach (WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES as $capability) {
            $entry = $manifest[$capability] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $normalized[$capability] = [
                'supported' => (bool) ($entry['supported'] ?? false),
                'minimum_protocol_version' => trim((string) ($entry['minimum_protocol_version'] ?? '')),
            ];

            foreach (['implementation', 'reason'] as $field) {
                $value = $this->stringValue($entry[$field] ?? null);

                if ($value !== null) {
                    $normalized[$capability][$field] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Reject ambiguous or optimistic advertisements at the additive protocol floor.
     *
     * @param  list<string>  $capabilities
     * @param  array<string, array<string, mixed>>  $manifest
     */
    private function guardPortableWorkerCapabilityManifest(
        Request $request,
        array $capabilities,
        array $manifest,
    ): ?JsonResponse {
        $requestedVersion = WorkerProtocol::requestVersion($request);

        foreach (WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES as $capability) {
            $advertised = in_array($capability, $capabilities, true);
            $entry = $manifest[$capability] ?? null;

            if ($entry === null && ! $advertised) {
                continue;
            }

            if ($entry === null || (bool) ($entry['supported'] ?? false) !== $advertised) {
                return WorkerProtocol::json([
                    'registered' => false,
                    'reason' => 'worker_capability_manifest_mismatch',
                    'capability' => $capability,
                    'requested_version' => $requestedVersion,
                    'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                    'remediation' => 'Keep the structured supported flag and the flat routing capability in exact agreement.',
                ], 409);
            }

            $minimum = (string) ($entry['minimum_protocol_version'] ?? '');
            if ($minimum === ''
                || version_compare($minimum, WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION, '<')
                || ($advertised && ($requestedVersion === null || version_compare($requestedVersion, $minimum, '<')))
            ) {
                return WorkerProtocol::json([
                    'registered' => false,
                    'reason' => 'worker_capability_protocol_floor_mismatch',
                    'capability' => $capability,
                    'requested_version' => $requestedVersion,
                    'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                    'remediation' => sprintf(
                        'Advertise %s only from a worker targeting protocol %s or newer.',
                        $capability,
                        WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
                    ),
                ], 409);
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @param  array<string, mixed>|null  $stickyCache
     */
    private function guardPortableWorkerAffinityCompletion(
        Request $request,
        string $namespace,
        string $leaseOwner,
        string $taskId,
        int $workflowTaskAttempt,
        array $commands,
        ?array $stickyCache,
        ?WorkerRegistration $worker = null,
    ): ?JsonResponse {
        $required = [];

        foreach ($commands as $command) {
            if (($command['type'] ?? null) === 'record_local_activity') {
                $required[] = 'local_activities';
            }
        }

        if ($this->commandsUseWorkerSessions($commands)) {
            $required[] = 'worker_sessions';
        }

        if ($stickyCache !== null) {
            $required[] = 'sticky_execution';
        }

        $required = array_values(array_unique($required));

        if ($required === []) {
            return null;
        }

        $requestedVersion = WorkerProtocol::requestVersion($request);
        if ($requestedVersion === null
            || version_compare($requestedVersion, WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION, '<')
        ) {
            return $this->portableWorkerAffinityRejection(
                $taskId,
                $workflowTaskAttempt,
                'portable_worker_affinity_protocol_floor_unavailable',
                $required[0],
                $requestedVersion,
            );
        }

        $worker ??= WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $leaseOwner)
            ->first();

        $capabilities = $this->nonEmptyStringArray($worker?->capabilities);
        $manifest = is_array($worker?->capability_manifest) ? $worker->capability_manifest : [];

        foreach ($required as $capability) {
            $entry = $manifest[$capability] ?? null;

            if (! in_array($capability, $capabilities, true)
                || ! is_array($entry)
                || ($entry['supported'] ?? null) !== true
            ) {
                return $this->portableWorkerAffinityRejection(
                    $taskId,
                    $workflowTaskAttempt,
                    'worker_capability_not_supported',
                    $capability,
                    $requestedVersion,
                );
            }
        }

        if ($stickyCache !== null) {
            $task = WorkflowTask::query()->with('run')->find($taskId);
            $expectedBuildId = $this->stringValue($worker?->build_id)
                ?? $this->stringValue($worker?->sdk_version);

            if ($task === null
                || (string) ($stickyCache['worker_id'] ?? '') !== $leaseOwner
                || (string) ($stickyCache['run_id'] ?? '') !== (string) $task->workflow_run_id
                || (string) ($stickyCache['workflow_id'] ?? '') !== (string) $task->run?->workflow_instance_id
                || $expectedBuildId === null
                || (string) ($stickyCache['build_id'] ?? '') !== $expectedBuildId
            ) {
                return $this->portableWorkerAffinityRejection(
                    $taskId,
                    $workflowTaskAttempt,
                    'sticky_cache_identity_mismatch',
                    'sticky_execution',
                    $requestedVersion,
                );
            }
        }

        return null;
    }

    private function portableWorkerAffinityRejection(
        string $taskId,
        int $workflowTaskAttempt,
        string $reason,
        string $capability,
        ?string $requestedVersion,
    ): JsonResponse {
        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => $reason,
            'capability' => $capability,
            'requested_version' => $requestedVersion,
            'minimum_protocol_version' => WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
            'remediation' => 'Use cold durable replay unless the registered worker manifest explicitly supports the required capability and exact cache identity.',
        ], 409);
    }

    /**
     * @param  array<string, mixed>|null  $stickyCache
     * @param  array<string, mixed>  $outcome
     */
    private function applyStickyCacheClaim(
        string $taskId,
        string $leaseOwner,
        ?array $stickyCache,
        ?WorkerRegistration $worker,
        array $outcome,
    ): void {
        if ($stickyCache === null || $worker === null) {
            return;
        }

        $task = WorkflowTask::query()->find($taskId);

        if ($task === null) {
            return;
        }

        $runStatus = is_string($outcome['run_status'] ?? null)
            ? RunStatus::tryFrom($outcome['run_status'])
            : null;
        $retainAffinity = $runStatus !== null && ! $runStatus->isTerminal();
        $stickyUntil = $retainAffinity
            ? now()->addSeconds((int) $stickyCache['ttl_seconds'])
            : null;

        WorkflowRun::query()
            ->whereKey($task->workflow_run_id)
            ->update([
                'sticky_worker_id' => $retainAffinity ? $leaseOwner : null,
                'sticky_until' => $stickyUntil,
            ]);

        WorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->update([
                'sticky_worker_id' => $retainAffinity ? $leaseOwner : null,
                'sticky_until' => $stickyUntil,
                'sticky_replay_mode' => null,
                'sticky_claimed_at' => null,
            ]);

        $processMetrics = is_array($worker->process_metrics) ? $worker->process_metrics : [];
        $processMetrics['sticky_cache'] = is_array($stickyCache['metrics'] ?? null)
            ? $stickyCache['metrics']
            : [];
        $worker->forceFill(['process_metrics' => $processMetrics])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function guardWorkflowMemoUpdatesAvailable(
        Request $request,
        string $taskId,
        int $workflowTaskAttempt,
        array $commands,
    ): ?JsonResponse {
        if (! $this->commandsUseWorkflowMemoUpdates($commands)) {
            return null;
        }

        $semantics = WorkerProtocol::workflowMemoUpdateSemantics();
        $minimum = (string) ($semantics['minimum_protocol_version'] ?? WorkerProtocol::VERSION);
        $requested = WorkerProtocol::requestVersion($request);

        if (
            WorkerProtocol::workflowMemoUpdatesSupported()
            && $requested !== null
            && WorkerProtocol::workflowMemoUpdatesSupported($requested)
        ) {
            return null;
        }

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => 'workflow_memo_updates_unavailable',
            'error' => sprintf(
                'Workflow memo updates require worker protocol %s or newer.',
                $minimum,
            ),
            'requested_version' => $requested,
            'minimum_protocol_version' => $minimum,
            'remediation' => sprintf(
                'Check server_capabilities.workflow_memo_updates.supported before emitting upsert_memo, and use a worker SDK targeting protocol %s or newer.',
                $minimum,
            ),
        ], 409);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function commandsUseWorkflowMemoUpdates(array $commands): bool
    {
        foreach ($commands as $command) {
            if (($command['type'] ?? null) === 'upsert_memo') {
                return true;
            }
        }

        return false;
    }

    private function workflowMemoValidationFailure(
        string $taskId,
        int $workflowTaskAttempt,
        \Throwable $exception,
    ): JsonResponse {
        $validationErrors = $exception instanceof ValidationException
            ? $exception->errors()
            : [];

        return WorkerProtocol::json(array_filter([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => 'workflow_memo_validation_failed',
            'error' => $validationErrors === []
                ? $exception->getMessage()
                : 'Workflow memo update command validation failed.',
            'validation_errors' => $validationErrors,
        ], static fn (mixed $value): bool => $value !== []), 422);
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
    private function validateWorkflowTaskSearchAttributeCommands(
        array $commands,
        ?string $namespace,
    ): array {
        foreach ($commands as $index => $command) {
            if (($command['type'] ?? null) !== 'upsert_search_attributes') {
                continue;
            }

            if (! is_array($command['attributes'] ?? null)) {
                continue;
            }

            $declaredTypes = is_array($command['attribute_types'] ?? null)
                ? $command['attribute_types']
                : [];
            $registeredTypes = $this->searchAttributeValues->validateForNamespace(
                $namespace,
                $command['attributes'],
                "commands.{$index}.attributes",
                $declaredTypes,
            );
            $commands[$index]['attribute_types'] = $registeredTypes;
        }

        return $commands;
    }

    /**
     * Keep the Server boundary atomic with Workflow package releases that
     * predate typed history metadata. Newer packages already write the same
     * canonical map, which is verified here instead of being overwritten.
     *
     * @param  list<array<string, mixed>>  $commands
     * @param  array<string, mixed>  $outcome
     */
    private function persistTypedSearchAttributeHistoryIdentity(
        string $taskId,
        array $commands,
        array $outcome,
    ): void {
        if (($outcome['completed'] ?? false) !== true) {
            return;
        }

        $upserts = array_values(array_filter(
            $commands,
            static fn (array $command): bool => ($command['type'] ?? null) === 'upsert_search_attributes',
        ));

        if ($upserts === []) {
            return;
        }

        $events = WorkflowHistoryEvent::query()
            ->where('workflow_task_id', $taskId)
            ->where('event_type', HistoryEventType::SearchAttributesUpserted->value)
            ->orderBy('sequence')
            ->get();

        if ($events->count() !== count($upserts)) {
            throw new \RuntimeException('Typed search-attribute commands did not produce a one-to-one history event set.');
        }

        foreach ($events as $index => $event) {
            $command = $upserts[$index];
            $payload = is_array($event->payload) ? $event->payload : [];
            $attributes = is_array($command['attributes'] ?? null) ? $command['attributes'] : [];
            $attributeTypes = is_array($command['attribute_types'] ?? null) ? $command['attribute_types'] : [];

            if (
                ! is_array($payload['attributes'] ?? null)
                || ! self::searchAttributeMapsMatch($payload['attributes'], $attributes)
            ) {
                throw new \RuntimeException('Typed search-attribute history does not match its completed command.');
            }

            if (array_key_exists('attribute_types', $payload)) {
                if (
                    ! is_array($payload['attribute_types'])
                    || ! self::searchAttributeMapsMatch($payload['attribute_types'], $attributeTypes)
                ) {
                    throw new \RuntimeException('Typed search-attribute history contains conflicting type identity.');
                }

                continue;
            }

            $payload['attribute_types'] = $attributeTypes;
            $event->forceFill(['payload' => $payload])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $recorded
     * @param  array<string, mixed>  $completed
     */
    private static function searchAttributeMapsMatch(array $recorded, array $completed): bool
    {
        ksort($recorded);
        ksort($completed);

        return $recorded === $completed;
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function guardTypedSearchAttributeCommandsAvailable(
        Request $request,
        string $taskId,
        int $workflowTaskAttempt,
        array $commands,
    ): ?JsonResponse {
        $usesTypedIdentity = collect($commands)->contains(
            static fn (array $command): bool => ($command['type'] ?? null) === 'upsert_search_attributes'
                && array_key_exists('attribute_types', $command),
        );

        if (! $usesTypedIdentity || WorkerProtocol::typedSearchAttributesAvailableForRequest($request)) {
            return null;
        }

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => 'typed_search_attributes_unavailable',
            'requested_version' => WorkerProtocol::requestVersion($request),
            'minimum_protocol_version' => WorkerProtocol::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
            'remediation' => sprintf(
                'Complete typed search-attribute updates only through server nodes advertising worker protocol %s or newer.',
                WorkerProtocol::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
            ),
        ], 409);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function guardConditionWaitOccurrenceIdentityAvailable(
        Request $request,
        string $taskId,
        int $workflowTaskAttempt,
        array $commands,
    ): ?JsonResponse {
        $authorsOccurrenceIdentity = collect($commands)->contains(
            static fn (array $command): bool => ($command['type'] ?? null) === 'open_condition_wait'
                && array_key_exists('condition_wait_occurrence_id', $command),
        );

        if (
            ! $authorsOccurrenceIdentity
            || WorkerProtocol::versionMeetsMinimum(
                WorkerProtocol::requestVersion($request),
                WorkerProtocol::CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION,
            )
        ) {
            return null;
        }

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => 'condition_wait_occurrence_identity_unavailable',
            'requested_version' => WorkerProtocol::requestVersion($request),
            'minimum_protocol_version' => WorkerProtocol::CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION,
            'command_type' => 'open_condition_wait',
            'command_field' => 'condition_wait_occurrence_id',
            'remediation' => sprintf(
                'Author condition-wait occurrence identity only with worker protocol %s or newer.',
                WorkerProtocol::CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION,
            ),
        ], 409);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function guardWorkflowMetadataCapabilitiesAvailable(
        mixed $namespace,
        string $leaseOwner,
        string $taskId,
        int $workflowTaskAttempt,
        array $commands,
        ?WorkerRegistration $leaseWorker = null,
    ): ?JsonResponse {
        $required = WorkflowMetadataCapabilityPolicy::requiredForCommands($commands);

        if ($required === []) {
            return null;
        }

        $leaseWorker ??= WorkerRegistration::query()
            ->where('namespace', $namespace)
            ->where('worker_id', $leaseOwner)
            ->first();
        $registeredCapabilities = $leaseWorker instanceof WorkerRegistration
            ? $this->nonEmptyStringArray($leaseWorker->capabilities)
            : [];
        $missing = WorkflowMetadataCapabilityPolicy::missingForCommands(
            $commands,
            $registeredCapabilities,
        );

        if ($missing === []) {
            return null;
        }

        $capability = $missing[0];
        $definition = WorkflowMetadataCapabilityPolicy::definitions()[$capability];

        return WorkerProtocol::json([
            'task_id' => $taskId,
            'workflow_task_attempt' => $workflowTaskAttempt,
            'outcome' => 'rejected',
            'recorded' => false,
            'reason' => 'workflow_metadata_capability_not_advertised',
            'capability' => $capability,
            'command_type' => $definition['command_type'],
            'minimum_protocol_version' => $definition['minimum_protocol_version'],
            'remediation' => sprintf(
                'Re-register lease owner %s with the %s capability before emitting %s.',
                $leaseOwner,
                $capability,
                $definition['command_type'],
            ),
        ], 409);
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
                && ! in_array($type, ['schedule_activity', 'record_local_activity', 'start_child_workflow'], true)
            ) {
                $errors["commands.{$index}.retry_policy"][] =
                    'retry_policy is only supported for schedule_activity and start_child_workflow commands.';
            }

            foreach (['start_to_close_timeout', 'schedule_to_start_timeout', 'schedule_to_close_timeout', 'heartbeat_timeout'] as $field) {
                if ($this->hasCommandValue($command, $field)
                    && ! in_array($type, ['schedule_activity', 'record_local_activity'], true)
                ) {
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
                && ! in_array($type, ['fail_workflow', 'fail_update', 'record_local_activity'], true)
            ) {
                $errors["commands.{$index}.non_retryable"][] =
                    'non_retryable is only supported for fail_workflow and fail_update commands.';
            }

            if ($this->hasCommandValue($command, 'exception') && $type !== 'fail_workflow') {
                $errors["commands.{$index}.exception"][] =
                    'exception is only supported for fail_workflow commands.';
            }

            if ($type === 'schedule_activity') {
                $this->validateActivityTimeoutEnvelope($command, $index, $errors);
            }

            if ($type === 'record_local_activity') {
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
    private function promoteWorkflowFailureExceptionPayload(array $commands): array
    {
        foreach ($commands as $index => $command) {
            if (($command['type'] ?? null) !== 'fail_workflow') {
                continue;
            }

            $exception = is_array($command['exception'] ?? null) ? $command['exception'] : null;
            if ($exception === null) {
                continue;
            }

            if (is_string($exception['message'] ?? null) && trim($exception['message']) !== '') {
                $commands[$index]['message'] = trim($exception['message']);
            }

            if (is_string($exception['class'] ?? null) && trim($exception['class']) !== '') {
                $commands[$index]['exception_class'] = trim($exception['class']);
            }

            if (is_string($exception['type'] ?? null) && trim($exception['type']) !== '') {
                $commands[$index]['exception_type'] = trim($exception['type']);
            }
        }

        return $commands;
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
    private function canonicalizeWorkflowStreamPayloadCodecs(array $commands): array
    {
        foreach ($commands as $commandIndex => $command) {
            $items = $command['workflow_stream']['items'] ?? null;

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $itemIndex => $item) {
                if (! is_array($item) || ! array_key_exists('payload_codec', $item)) {
                    continue;
                }

                $commands[$commandIndex]['workflow_stream']['items'][$itemIndex]['payload_codec'] =
                    PayloadCodecContract::canonicalize($item['payload_codec']);
            }
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

            if (array_key_exists('payload_codec', $command)) {
                try {
                    $commands[$index]['payload_codec'] = PayloadCodecContract::canonicalize(
                        $command['payload_codec'],
                    );
                } catch (\InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        "commands.{$index}.payload_codec" => [$exception->getMessage()],
                    ]);
                }
            }

            foreach (['arguments', 'result', 'entries'] as $field) {
                if (! array_key_exists($field, $command) || ! is_array($command[$field])) {
                    continue;
                }

                $resolved = AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
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

                if (
                    in_array($field, ['arguments', 'result'], true)
                    && ($commands[$index]['payload_codec'] ?? null) === null
                ) {
                    $commands[$index]['payload_codec'] = $resolved['codec'];
                }
            }

            $streamItems = $command['workflow_stream']['items'] ?? null;
            if (is_array($streamItems)) {
                foreach ($streamItems as $item) {
                    $reference = is_array($item) ? ($item['payload_reference'] ?? null) : null;
                    if (is_string($reference) && $reference !== '') {
                        if ($driver === null) {
                            throw new RuntimeExternalPayloadException(
                                'external_payload_unavailable',
                                503,
                                true,
                                'External payload storage is unavailable for this namespace.',
                            );
                        }

                        $driver->get($reference);
                    }
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
        if ($exception instanceof RuntimeExternalPayloadException) {
            app(RuntimeExternalPayloadAudit::class)->record(request(), 'external_payload.rejected', [
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ]);

            return WorkerProtocol::json([
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
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
        if ($exception instanceof RuntimeExternalPayloadException) {
            app(RuntimeExternalPayloadAudit::class)->record(request(), 'external_payload.rejected', [
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
                'status' => $exception->status,
            ]);

            return WorkerProtocol::json([
                'schema' => 'durable-workflow.v2.runtime-external-payload-error.v1',
                'query_task_id' => $queryTaskId,
                'query_task_attempt' => $queryTaskAttempt,
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

        try {
            $status = $this->storageMutations->run(
                static fn (): array => $bridge->heartbeat($taskId),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workflowTaskHeartbeatResponse(
                $taskId,
                (int) $validated['workflow_task_attempt'],
                $validated['lease_owner'],
            );
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
            'failure.type' => ['nullable', 'string', 'max:'.self::WORKFLOW_TASK_FAILURE_TYPE_MAX_LENGTH],
            'failure.reason' => ['nullable', 'string', 'max:'.self::WORKFLOW_TASK_FAILURE_REASON_MAX_LENGTH],
            'failure.sequence' => ['nullable', 'integer', 'min:1'],
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

        if ($this->workflowTaskFailureWaitsForHistory($validated['failure'])) {
            try {
                $outcome = $this->storageMutations->run(
                    fn (): array => $this->acknowledgeWorkflowTaskWaitingForHistory(
                        $namespace,
                        $taskId,
                        $validated['failure'],
                    ),
                );
            } catch (\Throwable $exception) {
                if (! BackendLockPressure::is($exception)) {
                    throw $exception;
                }

                return BackendLockPressure::workerOperationResponse($request, false);
            }

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => (int) $validated['workflow_task_attempt'],
                'outcome' => 'waiting_for_history',
                'recorded' => $outcome['recorded'],
                'reason' => $outcome['reason'],
                'next_task_id' => $outcome['next_task_id'],
            ], $this->workflowOutcomeStatus($outcome['reason']));
        }

        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        try {
            $outcome = $this->storageMutations->run(function () use (
                $bridge,
                $namespace,
                $taskId,
                $validated,
            ): array {
                return DB::transaction(function () use (
                    $bridge,
                    $namespace,
                    $taskId,
                    $validated,
                ): array {
                    $outcome = $bridge->fail($taskId, $validated['failure']);
                    $nextTaskId = is_string($outcome['next_task_id'] ?? null)
                        ? $outcome['next_task_id']
                        : null;

                    if (
                        ($outcome['recorded'] ?? false) === true
                        && ($outcome['reason'] ?? null) === null
                        && $nextTaskId === null
                    ) {
                        $replayBlocked = $this->workflowTaskFailureBlocksReplay($validated['failure']);
                        $this->recordWorkflowTaskFailureIdentity(
                            $namespace,
                            $taskId,
                            $validated['failure'],
                            $replayBlocked,
                        );

                        if (! $replayBlocked) {
                            $nextTaskId = $this->createRetryWorkflowTask($namespace, $taskId);
                        }
                    }

                    $outcome['next_task_id'] = $nextTaskId;

                    return $outcome;
                });
            });
        } catch (ExternalPayloadStorageUnavailable $exception) {
            return $this->externalPayloadFailure($taskId, (int) $validated['workflow_task_attempt'], $exception, 503);
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }

        $nextTaskId = is_string($outcome['next_task_id'] ?? null)
            ? $outcome['next_task_id']
            : null;

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
        $reason = strtolower(trim((string) ($failure['reason'] ?? '')));
        if (in_array($reason, self::STRUCTURED_REPLAY_FAILURE_REASONS, true)) {
            return true;
        }

        $message = strtolower(substr((string) ($failure['message'] ?? ''), 0, 4096));
        $type = strtolower(substr((string) ($failure['type'] ?? ''), 0, 512));
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
            'unsupported_payload_codec',
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
    private function workflowTaskFailureWaitsForHistory(array $failure): bool
    {
        $type = trim((string) ($failure['type'] ?? ''));

        if (strcasecmp($type, 'WorkflowTaskWaitingForHistory') === 0) {
            return true;
        }

        $message = strtolower((string) ($failure['message'] ?? ''));

        return str_contains(strtolower($type).' '.$message, 'workflow task waiting for scheduled history');
    }

    /**
     * @param  array<string, mixed>  $failure
     * @return array{recorded: bool, reason: string|null, next_task_id: string|null}
     */
    private function acknowledgeWorkflowTaskWaitingForHistory(string $namespace, string $taskId, array $failure): array
    {
        /** @var WorkflowTaskBridge $bridge */
        $bridge = app(WorkflowTaskBridge::class);
        $bridgeCompleted = false;
        $workflowTaskQueue = null;
        $createdTaskIds = [];

        $outcome = DB::transaction(function () use (
            $namespace,
            $taskId,
            $failure,
            $bridge,
            &$bridgeCompleted,
            &$workflowTaskQueue,
            &$createdTaskIds,
        ): array {
            /** @var WorkflowTask|null $task */
            $task = WorkflowTask::query()
                ->lockForUpdate()
                ->whereKey($taskId)
                ->where('namespace', $namespace)
                ->first();

            if (! $task instanceof WorkflowTask) {
                return ['recorded' => false, 'reason' => 'task_not_found', 'next_task_id' => null];
            }

            if ($task->task_type !== TaskType::Workflow) {
                return ['recorded' => false, 'reason' => 'task_not_workflow', 'next_task_id' => null];
            }

            if ($task->status !== TaskStatus::Leased) {
                return ['recorded' => false, 'reason' => 'task_not_leased', 'next_task_id' => null];
            }

            $workflowTaskQueue = $task->queue;

            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->find($task->workflow_run_id);

            if (! $run instanceof WorkflowRun) {
                return ['recorded' => false, 'reason' => 'run_not_found', 'next_task_id' => null];
            }

            if ($run->status->isTerminal()) {
                $task->forceFill([
                    'status' => $run->status === RunStatus::Failed ? TaskStatus::Failed : TaskStatus::Completed,
                    'lease_expires_at' => null,
                ])->save();

                return ['recorded' => false, 'reason' => 'run_already_closed', 'next_task_id' => null];
            }

            if ($this->workflowTaskResumesSignal($task)) {
                $bridgeOutcome = $bridge->complete($taskId, []);

                if (($bridgeOutcome['completed'] ?? false) !== true) {
                    return [
                        'recorded' => false,
                        'reason' => is_string($bridgeOutcome['reason'] ?? null)
                            ? $bridgeOutcome['reason']
                            : 'workflow_bridge_completion_failed',
                        'next_task_id' => null,
                    ];
                }

                $bridgeCompleted = true;
                $createdTaskIds = array_values(array_filter(
                    $bridgeOutcome['created_task_ids'] ?? [],
                    static fn (mixed $createdTaskId): bool => is_string($createdTaskId)
                        && trim($createdTaskId) !== '',
                ));

                $task->refresh();
            }

            $payload = is_array($task->payload) ? $task->payload : [];
            $payload['waiting_for_history_acknowledged'] = true;
            $payload['waiting_for_history_message'] = (string) ($failure['message'] ?? '');

            if (is_string($failure['type'] ?? null) && trim($failure['type']) !== '') {
                $payload['waiting_for_history_failure_type'] = trim($failure['type']);
            }

            if ($bridgeCompleted) {
                $task->forceFill(['payload' => $payload])->save();
            } else {
                $task->forceFill([
                    'status' => TaskStatus::Completed,
                    'lease_expires_at' => null,
                    'payload' => $payload,
                ])->save();

                $run->forceFill([
                    'status' => RunStatus::Waiting,
                    'last_progress_at' => now(),
                ])->save();
            }

            $this->projectWorkflowRun($run->id);

            return [
                'recorded' => true,
                'reason' => null,
                'next_task_id' => $createdTaskIds[0] ?? null,
            ];
        });

        if ($bridgeCompleted) {
            app(ServiceModeTimerDispatcher::class)->dispatchCreatedTaskIds($createdTaskIds);
            $this->wakeQueryTaskPollersForWorkflowTaskQueue($namespace, $workflowTaskQueue);
        }

        return $outcome;
    }

    private function workflowTaskResumesSignal(WorkflowTask $task): bool
    {
        $payload = is_array($task->payload) ? $task->payload : [];
        $signalId = $payload['workflow_signal_id'] ?? $payload['resume_source_id'] ?? null;

        return ($payload['resume_source_kind'] ?? null) === 'workflow_signal'
            && is_string($signalId)
            && trim($signalId) !== ''
            && is_string($payload['signal_name'] ?? null)
            && trim($payload['signal_name']) !== '';
    }

    /**
     * @param  array<string, mixed>  $failure
     */
    private function recordWorkflowTaskFailureIdentity(
        string $namespace,
        string $taskId,
        array $failure,
        bool $replayBlocked,
    ): void {
        DB::transaction(function () use ($namespace, $taskId, $failure, $replayBlocked): void {
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

            if (is_string($failure['reason'] ?? null) && trim($failure['reason']) !== '') {
                $payload['failure_reason'] = trim($failure['reason']);
            }

            if (is_int($failure['sequence'] ?? null)) {
                $payload['failure_sequence'] = $failure['sequence'];
            }

            if (is_string($failure['type'] ?? null) && trim($failure['type']) !== '') {
                $payload['failure_type'] = trim($failure['type']);
            }

            if ($replayBlocked) {
                $payload['replay_blocked'] = true;
                $payload['replay_blocked_reason'] = 'worker_reported_replay_failure';

                if (isset($payload['failure_type'])) {
                    $payload['replay_blocked_failure_type'] = $payload['failure_type'];
                }
            }

            $task->forceFill(['payload' => $payload])->save();

            if ($replayBlocked) {
                $this->projectWorkflowRun((string) $task->workflow_run_id);
            }
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
            unset(
                $payload['failure_reason'],
                $payload['failure_sequence'],
                $payload['failure_type'],
                $payload['replay_blocked'],
                $payload['replay_blocked_reason'],
                $payload['replay_blocked_failure_type'],
            );
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

        if (config('app.debug')) {
            Log::debug('worker_query_poll_request_admitted', [
                'namespace' => $namespace,
                'worker_id' => $request->input('worker_id'),
                'task_queue' => $request->input('task_queue'),
                'poll_request_id' => $request->input('poll_request_id'),
            ]);
        }

        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'task_queue' => ['required', 'string'],
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
        );

        if ($worker instanceof JsonResponse) {
            return $worker;
        }

        if (! WorkerPollFence::isFresh($worker)) {
            return WorkerProtocol::json([
                'task' => null,
                'poll_status' => 'stale_worker_registration',
            ]);
        }

        try {
            $poll = $this->queryTasks->pollResult(
                $namespace,
                $worker,
                $validated['poll_request_id'] ?? null,
                $timeoutSeconds,
            );
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
            if ($exception instanceof LongPollCapacityExhaustedException) {
                return WorkerPollBackpressure::response(
                    'query_task',
                    $namespace,
                    $validated['task_queue'],
                    $exception,
                );
            }

            if (BackendLockPressure::is($exception)) {
                return BackendLockPressure::workerPollResponse(
                    'query_task',
                    $namespace,
                    $validated['task_queue'],
                );
            }

            throw $exception;
        }

        $pollContext = [
            'namespace' => $namespace,
            'worker_id' => $worker->worker_id,
            'task_queue' => $worker->task_queue,
            'poll_request_id' => $validated['poll_request_id'] ?? null,
            'poll_status' => $poll['poll_status'],
            'query_task_id' => $poll['task']['query_task_id'] ?? null,
        ];

        if ($poll['poll_status'] === 'empty') {
            if (config('app.debug')) {
                Log::debug('worker_query_poll_response_ready', $pollContext);
            }
        } else {
            Log::info('worker_query_poll_response_ready', $pollContext);
        }

        return WorkerProtocol::json([
            'task' => $poll['task'],
            'poll_status' => $poll['poll_status'],
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
                $resolved = AvroPayloadEnvelopeResolver::resolveCommandPayloadWithCodec(
                    $candidate,
                    'result_envelope',
                    $this->externalPayloadStorage->driverFor($namespace),
                );
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (ExternalPayloadIntegrityException $exception) {
                return $this->externalQueryPayloadFailure($queryTaskId, (int) $validated['query_task_attempt'], $exception, 422);
            } catch (\Throwable $exception) {
                return $this->externalQueryPayloadFailure($queryTaskId, (int) $validated['query_task_attempt'], $exception, 503);
            }

            if (isset($resolved)) {
                $resultEnvelope = [
                    'codec' => $resolved['codec'],
                    'blob' => $resolved['payload'],
                ];
            }
        }

        try {
            $outcome = $this->storageMutations->run(
                fn (): array => $this->queryTasks->complete(
                    $namespace,
                    $queryTaskId,
                    $validated['lease_owner'],
                    (int) $validated['query_task_attempt'],
                    $validated['result'] ?? null,
                    $resultEnvelope,
                ),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }

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

        try {
            $outcome = $this->storageMutations->run(
                fn (): array => $this->queryTasks->fail(
                    $namespace,
                    $queryTaskId,
                    $validated['lease_owner'],
                    (int) $validated['query_task_attempt'],
                    $validated['failure'],
                ),
            );
        } catch (\Throwable $exception) {
            if (! BackendLockPressure::is($exception)) {
                throw $exception;
            }

            return BackendLockPressure::workerOperationResponse($request, false);
        }

        return WorkerProtocol::json(
            array_filter($outcome, static fn (mixed $value): bool => $value !== null),
            (int) ($outcome['status'] ?? 200),
        );
    }

    public function pollUpdateValidationTasks(Request $request): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $namespace = $request->attributes->get('namespace');
        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'task_queue' => ['required', 'string'],
            'timeout_seconds' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
            ],
        ]);
        $worker = $this->resolveRegisteredWorker(
            $namespace,
            $validated['worker_id'],
            $validated['task_queue'],
        );

        if ($worker instanceof JsonResponse) {
            return $worker;
        }

        if (! in_array(
            WorkflowUpdateValidationTaskBroker::CAPABILITY,
            is_array($worker->capabilities) ? $worker->capabilities : [],
            true,
        )) {
            return WorkerProtocol::json([
                'task' => null,
                'poll_status' => 'unsupported',
                'reason' => 'update_validation_capability_not_advertised',
                'error' => 'Worker registration does not advertise synchronous update validation.',
            ], 409);
        }

        try {
            $task = $this->updateValidationTasks->poll(
                $namespace,
                $worker,
                isset($validated['timeout_seconds']) ? (int) $validated['timeout_seconds'] : null,
            );
        } catch (LongPollCapacityExhaustedException $exception) {
            return WorkerPollBackpressure::response(
                'update_validation_task',
                $namespace,
                $validated['task_queue'],
                $exception,
            );
        }

        return WorkerProtocol::json([
            'task' => $task,
            'poll_status' => $task === null ? 'empty' : 'leased',
        ]);
    }

    public function approveUpdateValidationTask(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'update_validation_attempt' => ['required', 'integer', 'min:1'],
        ]);
        $outcome = $this->updateValidationTasks->approve(
            (string) $request->attributes->get('namespace'),
            $taskId,
            $validated['lease_owner'],
            (int) $validated['update_validation_attempt'],
        );

        return WorkerProtocol::json(
            array_filter($outcome, static fn (mixed $value): bool => $value !== null),
            (int) ($outcome['status'] ?? 200),
        );
    }

    public function rejectUpdateValidationTask(Request $request, string $taskId): JsonResponse
    {
        if ($response = WorkerProtocol::rejectUnsupported($request)) {
            return $response;
        }

        $validated = $request->validate([
            'lease_owner' => ['required', 'string'],
            'update_validation_attempt' => ['required', 'integer', 'min:1'],
            'failure' => ['required', 'array'],
            'failure.message' => ['required', 'string'],
            'failure.reason' => ['nullable', 'string'],
            'failure.type' => ['nullable', 'string'],
            'failure.stack_trace' => ['nullable', 'string'],
            'failure.validation_errors' => ['nullable', 'array'],
            'failure.validation_errors.*' => ['array'],
            'failure.validation_errors.*.*' => ['string'],
        ]);
        $outcome = $this->updateValidationTasks->reject(
            (string) $request->attributes->get('namespace'),
            $taskId,
            $validated['lease_owner'],
            (int) $validated['update_validation_attempt'],
            $validated['failure'],
        );

        return WorkerProtocol::json(
            array_filter($outcome, static fn (mixed $value): bool => $value !== null),
            (int) ($outcome['status'] ?? 200),
        );
    }

    private function wakeQueryTaskPollersForWorkflowTaskQueue(mixed $namespace, mixed $taskQueue): void
    {
        if (! is_string($namespace) || trim($namespace) === '') {
            return;
        }

        $this->queryTasks->wakeTaskQueue($namespace, is_string($taskQueue) ? $taskQueue : null);
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
            $leaseWorker = WorkerRegistration::query()
                ->where('namespace', $namespace)
                ->where('worker_id', $leaseOwner)
                ->first();

            if ($leaseWorker instanceof WorkerRegistration && WorkerPollFence::isFresh($leaseWorker)) {
                return null;
            }

            return WorkerProtocol::json([
                'task_id' => $taskId,
                'workflow_task_attempt' => $workflowTaskAttempt,
                'error' => 'Workflow task lease owner is no longer an active worker.',
                'reason' => 'stale_worker_registration',
                'task_status' => 'leased',
                'lease_owner' => $leaseOwner,
                'lease_expires_at' => $result['status']['lease_expires_at'] ?? null,
            ], 409);
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

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
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
