<?php

namespace App\Support;

use App\Models\SearchAttributeDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\WorkerHistoryPayloadContract;
use Workflow\V2\Support\WorkerProtocolVersion;

class WorkerProtocol
{
    /**
     * Worker-plane protocol version implemented by this server release.
     *
     * Keep this server-owned so a stale workflow package install cannot
     * silently lower the advertised protocol below the endpoints implemented
     * here. WorkflowPackageApiFloor asserts the installed package still
     * provides the companion protocol helpers for this version.
     */
    public const VERSION = '1.19';

    public const PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION = '1.18';

    /** @var list<string> */
    public const PORTABLE_WORKER_AFFINITY_CAPABILITIES = [
        'local_activities',
        'worker_sessions',
        'sticky_execution',
    ];

    /** @var array<string, string> */
    private const WORKFLOW_TASK_COMMAND_MINIMUM_PROTOCOL_VERSIONS = [
        'record_local_activity' => self::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
        'cancel_selection_operation' => '1.19',
    ];

    public const TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION =
        WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION;

    public const CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION =
        WorkflowMetadataCapabilityPolicy::CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION;

    public const SYNCHRONOUS_UPDATE_VALIDATION_MINIMUM_PROTOCOL_VERSION = '1.13';

    public const HEADER = 'X-Durable-Workflow-Protocol-Version';

    public static function requestVersion(Request $request): ?string
    {
        $version = $request->header(self::HEADER);

        if (! is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version === '' ? null : $version;
    }

    public static function isWorkerPlaneRequest(Request $request): bool
    {
        return $request->is('api/worker') || $request->is('api/worker/*');
    }

    public static function rejectUnsupported(Request $request): ?JsonResponse
    {
        $version = self::requestVersion($request);
        $supported = (string) config('server.worker_protocol.version', self::VERSION);

        if ($version !== null && self::isCompatibleProtocolVersion($version, $supported)) {
            return null;
        }

        if ($version === null) {
            return self::json([
                'error' => 'Missing worker protocol version header.',
                'reason' => 'missing_protocol_version',
                'supported_version' => $supported,
                'requested_version' => null,
                'remediation' => sprintf(
                    'Send the %s: %s header on worker protocol requests.',
                    self::HEADER,
                    $supported,
                ),
            ], 400);
        }

        return self::json([
            'error' => 'Unsupported worker protocol version.',
            'reason' => 'unsupported_protocol_version',
            'supported_version' => $supported,
            'requested_version' => $version,
            'remediation' => sprintf(
                'Worker requested protocol version %s; this server supports %s. Workers may target any %s.x version with x ≤ %s. Upgrade the worker to a release that targets a compatible version, or connect to a server that matches.',
                $version,
                $supported,
                self::splitProtocolVersion($supported)[0] ?? '0',
                self::splitProtocolVersion($supported)[1] ?? '0',
            ),
        ], 400);
    }

    public static function requestUsesCompatibleProtocolVersion(Request $request): bool
    {
        $requested = self::requestVersion($request);

        return $requested !== null
            && self::isCompatibleProtocolVersion(
                $requested,
                (string) config('server.worker_protocol.version', self::VERSION),
            );
    }

    /**
     * A worker's announced protocol version is compatible with the server when
     * they share a MAJOR and the worker's MINOR is ≤ the server's MINOR. Per
     * workflow:v2's WorkerProtocolVersion contract, MINOR bumps are additive
     * (new optional fields, new non-terminal command types) so older workers
     * can talk to newer servers — they simply don't exercise the new optional
     * shapes. MAJOR bumps are breaking and remain strict-rejected.
     *
     * Workers ahead of the server (higher MINOR than the server announces)
     * are also rejected, because they may rely on additive features the
     * server doesn't yet implement.
     *
     * @internal exposed for tests; see App\Support\WorkerProtocol::rejectUnsupported.
     */
    public static function isCompatibleProtocolVersion(string $worker, string $server): bool
    {
        $w = self::splitProtocolVersion($worker);
        $s = self::splitProtocolVersion($server);

        if ($w === null || $s === null) {
            // Malformed / unparseable input — fall back to strict equality
            // so a typo or hostile header can't bypass the check.
            return $worker === $server;
        }

        return $w[0] === $s[0] && $w[1] <= $s[1];
    }

    public static function versionMeetsMinimum(?string $candidate, string $minimum): bool
    {
        if ($candidate === null) {
            return false;
        }

        $candidateParts = self::splitProtocolVersion($candidate);
        $minimumParts = self::splitProtocolVersion($minimum);

        return $candidateParts !== null
            && $minimumParts !== null
            && $candidateParts[0] === $minimumParts[0]
            && $candidateParts[1] >= $minimumParts[1];
    }

    /**
     * @return array{
     *     advertised_version_path: string,
     *     default_advertised_version: string,
     *     request_header_rule: string,
     *     accepted_request_versions_by_default: list<string>,
     *     response_version: string,
     *     fail_closed_on: list<string>
     * }
     */
    public static function negotiation(): array
    {
        $advertised = (string) config('server.worker_protocol.version', self::VERSION);
        $parts = self::splitProtocolVersion($advertised);
        $accepted = [];

        if ($parts !== null) {
            for ($minor = 0; $minor <= $parts[1]; $minor++) {
                $accepted[] = sprintf('%d.%d', $parts[0], $minor);
            }
        }

        return [
            'advertised_version_path' => 'worker_protocol.version',
            'default_advertised_version' => $advertised,
            'request_header_rule' => 'same_major_and_minor_less_than_or_equal_to_advertised',
            'accepted_request_versions_by_default' => $accepted,
            'response_version' => 'advertised_version',
            'fail_closed_on' => [
                'missing_header',
                'malformed_version',
                'different_major',
                'minor_greater_than_advertised',
            ],
        ];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function splitProtocolVersion(string $value): ?array
    {
        if (! preg_match('/^\d+\.\d+$/', $value)) {
            return null;
        }
        [$major, $minor] = explode('.', $value, 2);

        return [(int) $major, (int) $minor];
    }

    public static function workerSessionMinimumProtocolVersion(): string
    {
        $semantics = self::baseWorkerSessionSemantics();
        $minimum = $semantics['minimum_protocol_version']
            ?? $semantics['min_worker_protocol_version']
            ?? ($semantics['rollout_safety']['minimum_protocol_version'] ?? null)
            ?? self::VERSION;

        return is_string($minimum) && trim($minimum) !== ''
            ? trim($minimum)
            : self::VERSION;
    }

    public static function workerSessionsSupported(): bool
    {
        $configured = (string) config('server.worker_protocol.version', self::VERSION);

        return self::protocolVersionSupportsWorkerSessions($configured);
    }

    public static function portableWorkerAffinitySupported(?string $version = null): bool
    {
        $version ??= (string) config('server.worker_protocol.version', self::VERSION);

        return self::versionMeetsMinimum(
            $version,
            self::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION,
        );
    }

    public static function workerSessionsAvailableForRequest(Request $request): bool
    {
        $version = self::requestVersion($request);

        return self::workerSessionsSupported()
            && $version !== null
            && self::protocolVersionSupportsWorkerSessions($version);
    }

    public static function messageStreamsSupported(?string $version = null): bool
    {
        $version ??= (string) config('server.worker_protocol.version', self::VERSION);
        $candidate = self::splitProtocolVersion($version);
        $minimum = self::splitProtocolVersion(MessageStreamsContract::MINIMUM_WORKER_PROTOCOL_VERSION);

        return $candidate !== null
            && $minimum !== null
            && $candidate[0] === $minimum[0]
            && $candidate[1] >= $minimum[1];
    }

    public static function messageStreamsAvailableForRequest(Request $request): bool
    {
        $version = self::requestVersion($request);

        return self::messageStreamsSupported()
            && $version !== null
            && self::messageStreamsSupported($version);
    }

    public static function typedSearchAttributesSupported(?string $version = null): bool
    {
        $version ??= (string) config('server.worker_protocol.version', self::VERSION);
        $candidate = self::splitProtocolVersion($version);
        $minimum = self::splitProtocolVersion(self::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION);

        return $candidate !== null
            && $minimum !== null
            && $candidate[0] === $minimum[0]
            && $candidate[1] >= $minimum[1];
    }

    public static function typedSearchAttributesAvailableForRequest(Request $request): bool
    {
        $version = self::requestVersion($request);

        return self::typedSearchAttributesSupported()
            && $version !== null
            && self::typedSearchAttributesSupported($version);
    }

    public static function rejectWorkerSessionsUnavailable(Request $request): ?JsonResponse
    {
        if (self::workerSessionsAvailableForRequest($request)) {
            return null;
        }

        $configured = (string) config('server.worker_protocol.version', self::VERSION);
        $minimum = self::workerSessionMinimumProtocolVersion();
        $requested = self::requestVersion($request);
        $serverSupports = self::protocolVersionSupportsWorkerSessions($configured);

        $remediation = $serverSupports
            ? sprintf(
                'Send the %s header with worker protocol %s or newer and use a worker SDK '
                    .'that implements worker-session semantics.',
                self::HEADER,
                $minimum,
            )
            : sprintf(
                'Route worker-session clients only to server nodes advertising worker protocol %s or newer.',
                $minimum,
            );

        return self::json([
            'error' => sprintf(
                'Worker sessions require worker protocol %s or newer.',
                $minimum,
            ),
            'reason' => 'worker_sessions_unavailable',
            'supported_version' => $configured,
            'requested_version' => $requested,
            'minimum_protocol_version' => $minimum,
            'remediation' => $remediation,
        ], 409);
    }

    private static function protocolVersionSupportsWorkerSessions(string $version): bool
    {
        $minimum = self::workerSessionMinimumProtocolVersion();

        if (self::splitProtocolVersion($version) === null || self::splitProtocolVersion($minimum) === null) {
            return false;
        }

        return version_compare($version, $minimum, '>=');
    }

    /**
     * @return list<string>
     */
    public static function supportedWorkflowTaskCommands(): array
    {
        $advertisedVersion = (string) config('server.worker_protocol.version', self::VERSION);
        $commands = array_values(array_merge(
            WorkerProtocolVersion::terminalCommandTypes(),
            WorkerProtocolVersion::nonTerminalCommandTypes(),
        ));

        $commands = array_values(array_filter(
            $commands,
            static function (string $command) use ($advertisedVersion): bool {
                $minimum = self::WORKFLOW_TASK_COMMAND_MINIMUM_PROTOCOL_VERSIONS[$command] ?? null;

                return $minimum === null
                    || (self::versionMeetsMinimum(self::VERSION, $minimum)
                        && self::versionMeetsMinimum($advertisedVersion, $minimum));
            },
        ));

        if (! self::workflowMemoUpdatesSupported()) {
            $commands = array_values(array_filter(
                $commands,
                static fn (string $command): bool => $command !== 'upsert_memo',
            ));
        }

        return $commands;
    }

    public static function workflowMemoUpdatesSupported(?string $version = null): bool
    {
        $version ??= (string) config('server.worker_protocol.version', self::VERSION);
        $minimum = self::workflowMemoUpdateSemantics()['minimum_protocol_version'] ?? self::VERSION;
        $candidate = self::splitProtocolVersion($version);
        $minimum = is_string($minimum) ? self::splitProtocolVersion($minimum) : null;

        return $candidate !== null
            && $minimum !== null
            && $candidate[0] === $minimum[0]
            && $candidate[1] >= $minimum[1];
    }

    /**
     * @return array<string, mixed>
     */
    public static function workflowMemoUpdateSemantics(): array
    {
        return WorkerProtocolVersion::upsertMemoCommandShape();
    }

    /**
     * @return array{
     *     long_poll_timeout: int,
     *     supported_workflow_task_commands: list<string>,
     *     workflow_memo_updates: array<string, mixed>,
     *     workflow_task_poll_request_idempotency: bool,
     *     poll_status: bool,
     *     history_page_size_default: int,
     *     history_page_size_max: int,
     *     workflow_history_budget: array<string, mixed>,
     *     message_streams: array<string, mixed>,
     *     query_tasks: bool,
     *     update_validation_tasks: bool,
     *     synchronous_update_validation: array<string, mixed>,
     *     query_task_poll_request_idempotency: bool,
     *     query_task_timeouts: array{control_plane_timeout_seconds: int, lease_timeout_seconds: int, lease_grace_seconds: int},
     *     activity_retry_policy: bool,
     *     activity_timeouts: bool,
     *     local_activities: array<string, mixed>,
     *     worker_session_verbs: list<string>,
     *     worker_sessions: array<string, mixed>,
     *     child_workflow_retry_policy: bool,
     *     child_workflow_timeouts: bool,
     *     parent_close_policy: bool,
     *     non_retryable_failures: bool,
     *     response_compression: list<string>,
     *     history_compression: array{supported_encodings: list<string>, compression_threshold: int},
     *     external_execution_surface: array<string, mixed>,
     *     external_executor_config: array<string, mixed>,
     *     invocable_carrier: array<string, mixed>,
     *     worker_status: array{
     *         supported: bool,
     *         heartbeat_interval_seconds: int,
     *         stale_after_seconds: int,
     *         fields: array{task_slots: list<string>, process_metrics: list<string>},
     *     },
     *     external_task_input: array<string, mixed>,
     *     external_task_result: array<string, mixed>,
     *     task_queue_priority_fairness: array<string, mixed>,
     *     runtime_external_payload_transport: array<string, mixed>,
     * }
     */
    public static function serverCapabilities(): array
    {
        $portableWorkerAffinitySupported = self::portableWorkerAffinitySupported();
        $workerSessionSupported = self::workerSessionsSupported();

        return [
            'long_poll_timeout' => (int) config(
                'server.polling.timeout',
                WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
            ),
            'supported_workflow_task_commands' => self::supportedWorkflowTaskCommands(),
            'workflow_memo_updates' => [
                ...self::workflowMemoUpdateSemantics(),
                'supported' => self::workflowMemoUpdatesSupported(),
                'worker_capability' => WorkflowMetadataCapabilityPolicy::MEMO_UPSERTS,
            ],
            'workflow_task_poll_request_idempotency' => true,
            'poll_status' => true,
            'history_page_size_default' => (int) config(
                'server.worker_protocol.history_page_size_default',
                WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            ),
            'history_page_size_max' => (int) config(
                'server.worker_protocol.history_page_size_max',
                WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            ),
            'workflow_history_budget' => WorkerHistoryPayloadContract::manifest(),
            'message_streams' => [
                ...MessageStreamsContract::manifest(),
                'supported' => self::messageStreamsSupported(),
            ],
            'typed_search_attributes' => [
                'supported' => self::typedSearchAttributesSupported(),
                'minimum_worker_protocol_version' => self::TYPED_SEARCH_ATTRIBUTES_MINIMUM_PROTOCOL_VERSION,
                'worker_capability' => WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES,
                'canonical_types' => SearchAttributeDefinition::CANONICAL_TYPES,
                'command_field' => 'attribute_types',
                'history_event' => 'SearchAttributesUpserted',
                'history_field' => 'attribute_types',
                'legacy_history_rule' => 'absent_metadata_is_unknown_type_identity',
            ],
            'condition_wait_occurrence_identity' => [
                'supported' => true,
                'minimum_worker_protocol_version' => self::CONDITION_WAIT_OCCURRENCE_MINIMUM_PROTOCOL_VERSION,
                'command_type' => 'open_condition_wait',
                'command_field' => 'condition_wait_occurrence_id',
                'history_field' => 'condition_wait_occurrence_id',
                'history_events' => [
                    'ConditionWaitOpened',
                    'ConditionWaitSatisfied',
                    'ConditionWaitTimedOut',
                    'TimerScheduled',
                    'TimerFired',
                    'TimerCancelled',
                ],
                'history_routing' => 'requires_minimum_worker_protocol_version',
            ],
            'query_tasks' => true,
            'query_task_poll_request_idempotency' => true,
            'query_task_timeouts' => self::queryTaskTimeouts(),
            'update_validation_tasks' => true,
            'synchronous_update_validation' => [
                'supported' => true,
                'minimum_protocol_version' => self::SYNCHRONOUS_UPDATE_VALIDATION_MINIMUM_PROTOCOL_VERSION,
                'acceptance_boundary' => 'validator_approved',
                'worker_capability' => WorkflowUpdateValidationTaskBroker::CAPABILITY,
                'workflow_contract_field' => WorkflowUpdateValidationTaskBroker::CONTRACT_FIELD,
                'validator_execution' => 'replay_without_handler_or_state_commit',
                'request_id_required' => true,
                'duplicate_completion' => 'typed_rejection',
                'stale_completion' => 'typed_rejection',
                'missing_contract' => 'typed_rejection',
                'unsupported_worker' => 'typed_rejection',
                'task_poll' => [
                    'strategy' => 'multiplexed',
                    'endpoint' => '/worker/workflow-tasks/poll',
                    'request_field' => 'task_kinds',
                    'task_kinds' => ['workflow', 'update_validation'],
                    'default_task_kinds' => ['workflow'],
                    'response_discriminator' => 'task.task_kind',
                    'poll_request_id_binding' => 'normalized_task_kind_set',
                    'poll_request_id_conflict_reason' => 'poll_request_task_kinds_conflict',
                ],
                'completion' => [
                    'approve_endpoint' => '/worker/update-validation-tasks/{taskId}/approve',
                    'reject_endpoint' => '/worker/update-validation-tasks/{taskId}/reject',
                    'fence_fields' => ['lease_owner', 'update_validation_attempt'],
                    'typed_failure_reasons' => [
                        'update_validation_task_not_found',
                        'duplicate_update_validation_completion',
                        'update_validation_task_not_leased',
                        'update_validation_lease_owner_mismatch',
                        'stale_update_validation_completion',
                        'update_validation_lease_expired',
                        'update_validator_worker_lost',
                    ],
                ],
                'timeout_seconds' => max(0, (int) config('server.update_validation.timeout', 10)),
                'lease_timeout_seconds' => max(1, (int) config('server.update_validation.lease_timeout', 5)),
                'sdk_surfaces' => [
                    'sdk-python' => 'validator_capable',
                    'sdk-php' => 'validators_not_exposed',
                    'sdk-rust' => 'validators_not_exposed',
                ],
            ],
            'activity_retry_policy' => true,
            'activity_timeouts' => true,
            'local_activities' => [
                ...WorkerProtocolVersion::localActivitySemantics(),
                'supported' => $portableWorkerAffinitySupported,
            ],
            'sticky_execution' => [
                ...WorkerProtocolVersion::describe()['sticky_execution'],
                'supported' => $portableWorkerAffinitySupported,
            ],
            'worker_session_verbs' => $workerSessionSupported ? self::workerSessionVerbs() : [],
            'worker_sessions' => self::workerSessionSemantics($workerSessionSupported),
            'child_workflow_retry_policy' => true,
            'child_workflow_timeouts' => true,
            'parent_close_policy' => true,
            'non_retryable_failures' => true,
            'response_compression' => (bool) config('server.compression.enabled', true)
                ? ['gzip', 'deflate']
                : [],
            'history_compression' => [
                'supported_encodings' => WorkerProtocolVersion::supportedHistoryEncodings(),
                'compression_threshold' => WorkerProtocolVersion::COMPRESSION_THRESHOLD,
            ],
            'external_execution_surface' => [
                'schema' => ExternalExecutionSurfaceContract::SCHEMA,
                'version' => ExternalExecutionSurfaceContract::VERSION,
                'name' => 'activity_grade_external_execution',
            ],
            'external_executor_config' => [
                'schema' => ExternalExecutorConfigContract::CONTRACT_SCHEMA,
                'version' => ExternalExecutorConfigContract::CONTRACT_VERSION,
                'config_schema' => ExternalExecutorConfigContract::CONFIG_SCHEMA,
                'config_schema_version' => ExternalExecutorConfigContract::CONFIG_VERSION,
            ],
            'invocable_carrier' => [
                'schema' => InvocableCarrierContract::SCHEMA,
                'version' => InvocableCarrierContract::VERSION,
                'carrier_type' => InvocableCarrierContract::CARRIER_TYPE,
            ],
            'worker_status' => [
                'supported' => true,
                'heartbeat_interval_seconds' => max(1, min(3600, (int) config(
                    'server.workers.heartbeat_interval_seconds',
                    10,
                ))),
                'stale_after_seconds' => max(1, (int) config(
                    'server.workers.stale_after_seconds',
                    30,
                )),
                'fields' => [
                    'task_slots' => ['workflow_available', 'activity_available', 'session_available'],
                    'process_metrics' => [
                        'cpu_percent',
                        'memory_bytes',
                        'process_uptime_seconds',
                        'process_id',
                        'host',
                        'process_started_at',
                    ],
                ],
            ],
            'external_task_input' => [
                'schema' => ExternalTaskInputContract::SCHEMA,
                'version' => ExternalTaskInputContract::VERSION,
            ],
            'external_task_result' => [
                'schema' => ExternalTaskResultContract::SCHEMA,
                'version' => ExternalTaskResultContract::VERSION,
            ],
            'task_queue_priority_fairness' => self::taskQueuePriorityFairnessSemantics(),
            'runtime_external_payload_transport' => RuntimeExternalPayloadReference::transportManifest(),
        ];
    }

    /**
     * @return array{control_plane_timeout_seconds: int, lease_timeout_seconds: int, lease_grace_seconds: int}
     */
    private static function queryTaskTimeouts(): array
    {
        $controlPlaneTimeout = max(0, (int) config(
            'server.query_tasks.timeout',
            config('server.polling.timeout', WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT),
        ));
        $configuredLease = max(1, (int) config(
            'server.query_tasks.lease_timeout',
            config('server.lease.workflow_task_timeout', 60),
        ));
        $grace = 5;

        return [
            'control_plane_timeout_seconds' => $controlPlaneTimeout,
            'lease_timeout_seconds' => $controlPlaneTimeout === 0
                ? $configuredLease
                : max($configuredLease, $controlPlaneTimeout + $grace),
            'lease_grace_seconds' => $grace,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function taskQueuePriorityFairnessSemantics(): array
    {
        if (method_exists(WorkerProtocolVersion::class, 'taskQueuePriorityFairnessSemantics')) {
            return WorkerProtocolVersion::taskQueuePriorityFairnessSemantics();
        }

        return [
            'schema' => 'durable-workflow.v2.task-queue-priority-fairness.contract',
            'version' => 1,
            'feature' => 'task_queue_priority_fairness',
            'fields' => [
                'priority' => [
                    'type' => 'integer',
                    'min' => 0,
                    'min_user' => 1,
                    'max' => 9,
                    'default' => 5,
                    'lower_is_more_urgent' => true,
                ],
                'fairness_key' => [
                    'type' => 'string',
                    'nullable' => true,
                    'max_length' => 64,
                    'default' => null,
                    'default_class_label' => '__default__',
                ],
                'fairness_weight' => [
                    'type' => 'integer',
                    'min' => 1,
                    'max' => 1000,
                    'default' => 1,
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function workerSessionVerbs(): array
    {
        return WorkerProtocolVersion::workerSessionVerbs();
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerSessionSemantics(bool $supported): array
    {
        $minimum = self::workerSessionMinimumProtocolVersion();

        return [
            ...self::baseWorkerSessionSemantics(),
            'supported' => $supported,
            'minimum_protocol_version' => $minimum,
            ...($supported ? [] : [
                'unavailable_reason' => 'worker_protocol_version_below_worker_session_minimum',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseWorkerSessionSemantics(): array
    {
        return WorkerProtocolVersion::workerSessionSemantics();
    }

    /**
     * @return array{
     *     version: string,
     *     server_capabilities: array{
     *         long_poll_timeout: int,
     *         supported_workflow_task_commands: list<string>,
     *         workflow_task_poll_request_idempotency: bool,
     *     },
     * }
     */
    public static function info(): array
    {
        return [
            'version' => (string) config('server.worker_protocol.version', self::VERSION),
            'server_capabilities' => self::serverCapabilities(),
            'external_execution_surface_contract' => ExternalExecutionSurfaceContract::manifest(),
            'external_executor_config_contract' => [
                ...ExternalExecutorConfigContract::manifest(),
                'runtime' => ExternalExecutorConfigContract::runtime(),
            ],
            'invocable_carrier_contract' => InvocableCarrierContract::manifest(),
            'external_task_input_contract' => ExternalTaskInputContract::manifest(),
            'external_task_result_contract' => ExternalTaskResultContract::manifest(),
        ];
    }

    public static function json(array $payload, int $status = 200): JsonResponse
    {
        $version = (string) config('server.worker_protocol.version', self::VERSION);

        $payload['protocol_version'] ??= $version;
        $payload['server_capabilities'] ??= self::serverCapabilities();

        return response()
            ->json($payload, $status)
            ->header(self::HEADER, $version);
    }
}
