<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Workflow\V2\Support\WorkerProtocolVersion;

class WorkerProtocol
{
    public const VERSION = WorkerProtocolVersion::VERSION;

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

        return version_compare($configured, self::workerSessionMinimumProtocolVersion(), '>=');
    }

    public static function rejectWorkerSessionsUnavailable(Request $request): ?JsonResponse
    {
        if (self::workerSessionsSupported()) {
            return null;
        }

        $configured = (string) config('server.worker_protocol.version', self::VERSION);
        $minimum = self::workerSessionMinimumProtocolVersion();

        return self::json([
            'error' => sprintf(
                'Worker sessions require worker protocol %s or newer.',
                $minimum,
            ),
            'reason' => 'worker_sessions_unavailable',
            'supported_version' => $configured,
            'requested_version' => self::requestVersion($request),
            'minimum_protocol_version' => $minimum,
            'remediation' => sprintf(
                'Route worker-session clients only to server nodes advertising worker protocol %s or newer.',
                $minimum,
            ),
        ], 409);
    }

    /**
     * @return list<string>
     */
    public static function supportedWorkflowTaskCommands(): array
    {
        return array_values(array_merge(
            WorkerProtocolVersion::terminalCommandTypes(),
            WorkerProtocolVersion::nonTerminalCommandTypes(),
        ));
    }

    /**
     * @return array{
     *     long_poll_timeout: int,
     *     supported_workflow_task_commands: list<string>,
     *     workflow_task_poll_request_idempotency: bool,
     *     poll_status: bool,
     *     history_page_size_default: int,
     *     history_page_size_max: int,
     *     query_tasks: bool,
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
     *     external_task_input: array<string, mixed>,
     *     external_task_result: array<string, mixed>,
     * }
     */
    public static function serverCapabilities(): array
    {
        $workerSessionSupported = self::workerSessionsSupported();

        return [
            'long_poll_timeout' => (int) config(
                'server.polling.timeout',
                WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
            ),
            'supported_workflow_task_commands' => self::supportedWorkflowTaskCommands(),
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
            'query_tasks' => true,
            'activity_retry_policy' => true,
            'activity_timeouts' => true,
            'local_activities' => self::localActivitySemantics(),
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
                    60,
                ))),
                'stale_after_seconds' => max(1, (int) config(
                    'server.workers.stale_after_seconds',
                    300,
                )),
                'fields' => [
                    'task_slots' => ['workflow_available', 'activity_available', 'session_available'],
                    'process_metrics' => ['cpu_percent', 'memory_bytes', 'process_uptime_seconds', 'process_id', 'host'],
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
        ];
    }

    /**
     * @return list<string>
     */
    private static function workerSessionVerbs(): array
    {
        return method_exists(WorkerProtocolVersion::class, 'workerSessionVerbs')
            ? WorkerProtocolVersion::workerSessionVerbs()
            : ['create', 'heartbeat', 'close'];
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
        return method_exists(WorkerProtocolVersion::class, 'workerSessionSemantics')
            ? WorkerProtocolVersion::workerSessionSemantics()
            : [
                'command_field' => 'worker_session',
                'activity_options_field' => 'worker_session',
                'lifecycle' => 'lazy_create_on_first_admitted_activity',
                'ownership' => 'single_worker_lease_owner',
                'verbs' => ['create', 'heartbeat', 'close'],
            ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function localActivitySemantics(): array
    {
        if (method_exists(WorkerProtocolVersion::class, 'localActivitySemantics')) {
            return WorkerProtocolVersion::localActivitySemantics();
        }

        return [
            'schema' => 'durable-workflow.v2.local-activity.contract',
            'version' => 1,
            'supported' => false,
            'api' => [
                'functions' => ['Workflow\\V2\\localActivity'],
                'workflow_facade' => [
                    'Workflow\\V2\\Workflow::localActivity',
                    'Workflow\\V2\\Workflow::executeLocalActivity',
                ],
                'options' => 'Workflow\\V2\\Support\\LocalActivityOptions',
            ],
            'execution' => [
                'mode' => 'local',
                'same_process' => true,
                'ordinary_activity_task_created' => false,
                'history_marker' => [
                    'execution_mode' => 'local',
                    'local_activity' => true,
                ],
            ],
            'routing' => [
                'admission' => 'activity_class_must_resolve_in_the_workflow_worker_process',
                'queue_bypassed' => true,
                'rejected_options' => ['connection', 'queue', 'worker_session', 'schedule_to_start_timeout'],
            ],
            'retry' => [
                'cold_replay_reason' => 'cold_replay',
            ],
            'visibility' => [
                'activity_execution_marker' => 'activity_options.execution_mode',
                'history_marker' => 'payload.execution_mode',
                'metrics_marker' => 'activities.local_*',
            ],
        ];
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
