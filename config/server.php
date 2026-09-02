<?php

use App\Support\EnvAuditor;
use App\Support\WorkerProtocol;
use Workflow\V2\Support\WorkerProtocolVersion;

/*
|--------------------------------------------------------------------------
| DW_* environment variable resolution
|--------------------------------------------------------------------------
|
| Every operator-facing config key reads its value through
| EnvAuditor::env($dw, $legacy, $default). The DW_* name is the
| documented public contract (see config/dw-contract.php); the legacy
| WORKFLOW_* / ACTIVITY_* name is honored for backward compatibility and
| logged as deprecated by the `env:audit` artisan command. Renaming a
| DW_* name requires a major bump with the old name aliased for one major.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Server Mode
    |--------------------------------------------------------------------------
    |
    | In "service" mode (default), the server acts as a task broker: it creates
    | workflow and activity task rows in the database but does NOT dispatch them
    | to the Laravel queue for local execution. External workers poll the HTTP
    | API to claim and execute tasks. Timer tasks are still dispatched locally.
    |
    | In "embedded" mode, the server dispatches all tasks to the Laravel queue
    | for local execution, requiring workflow and activity classes to be
    | registered in the same process.
    |
    */

    'mode' => EnvAuditor::env('DW_MODE', 'WORKFLOW_SERVER_MODE', 'service'),

    'migrations' => [
        'workflow_memo_recovery' => EnvAuditor::env(
            'DW_WORKFLOW_MEMO_MIGRATION_RECOVERY',
            'WORKFLOW_SERVER_WORKFLOW_MEMO_MIGRATION_RECOVERY',
            null,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Dispatch Mode Override
    |--------------------------------------------------------------------------
    |
    | Captures an explicit operator choice for workflows.v2.task_dispatch_mode
    | at config load time. In service mode the server defaults this key to
    | "poll" so external workers claim tasks over HTTP; this override records
    | whether the operator asked for something else (typically "queue") so the
    | default is only applied when no explicit choice exists.
    |
    | Reading env() directly from the AppServiceProvider is unsafe after
    | `php artisan config:cache`: dotenv is no longer loaded at runtime, so a
    | DW_TASK_DISPATCH_MODE value that lived in .env would be interpreted as
    | "not set" and silently rewritten to "poll". Capturing the env here makes
    | the override part of the cached config.
    |
    */

    'task_dispatch_mode_override' => EnvAuditor::env('DW_TASK_DISPATCH_MODE', 'WORKFLOW_V2_TASK_DISPATCH_MODE', null),

    /*
    |--------------------------------------------------------------------------
    | Workflow Fleet Validation Mode
    |--------------------------------------------------------------------------
    |
    | Mirrors the documented fleet-compatibility validation contract into the
    | installed workflow package config at boot time so config:cache preserves
    | the operator choice.
    |
    */

    'fleet_validation_mode' => EnvAuditor::env('DW_V2_FLEET_VALIDATION_MODE', 'WORKFLOW_V2_FLEET_VALIDATION_MODE', 'warn'),

    'update_validation' => [
        'timeout' => EnvAuditor::env('DW_UPDATE_VALIDATION_TIMEOUT', 'WORKFLOW_SERVER_UPDATE_VALIDATION_TIMEOUT', 10),
        'lease_timeout' => EnvAuditor::env('DW_UPDATE_VALIDATION_LEASE_TIMEOUT', 'WORKFLOW_SERVER_UPDATE_VALIDATION_LEASE_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | External Executor Config
    |--------------------------------------------------------------------------
    |
    | Optional steady-state config for carrier-neutral external executor
    | handler mappings. The server validates and advertises this config through
    | cluster discovery; carriers still own actual handler invocation.
    |
    */

    'external_executor' => [
        'config_path' => EnvAuditor::env('DW_EXTERNAL_EXECUTOR_CONFIG_PATH', 'WORKFLOW_SERVER_EXTERNAL_EXECUTOR_CONFIG_PATH', null),
        'overlay' => EnvAuditor::env('DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY', 'WORKFLOW_SERVER_EXTERNAL_EXECUTOR_CONFIG_OVERLAY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    |
    | A unique identifier for this server instance, used in lease ownership,
    | worker registration, and cluster coordination.
    |
    */

    'server_id' => EnvAuditor::env('DW_SERVER_ID', 'WORKFLOW_SERVER_ID', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Topology Identity
    |--------------------------------------------------------------------------
    |
    | Cluster discovery advertises the current node's deployment shape and
    | process class so split-role fleets can distinguish HTTP/control,
    | scheduler, matching, and execution nodes without reading compose files.
    | Invalid values fail back to the documented standalone HTTP defaults.
    |
    */

    'topology' => [
        'shape' => EnvAuditor::env('DW_SERVER_TOPOLOGY_SHAPE', 'WORKFLOW_SERVER_TOPOLOGY_SHAPE', 'standalone_server'),
        'process_class' => EnvAuditor::env('DW_SERVER_PROCESS_CLASS', 'WORKFLOW_SERVER_PROCESS_CLASS', 'server_http_node'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Namespace
    |--------------------------------------------------------------------------
    |
    | The default namespace used when no namespace header is provided.
    | Namespaces isolate workflow instances, runs, and visibility.
    |
    */

    'default_namespace' => EnvAuditor::env('DW_DEFAULT_NAMESPACE', 'WORKFLOW_SERVER_DEFAULT_NAMESPACE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Controls how the server authenticates incoming API requests.
    |
    | Supported drivers: "none", "token", "signature". Role-scoped tokens
    | and signature keys separate worker, operator, and admin access while
    | preserving the legacy single credential when role credentials are absent.
    |
    */

    'auth' => [
        'provider' => EnvAuditor::env('DW_AUTH_PROVIDER', 'WORKFLOW_SERVER_AUTH_PROVIDER'),
        'driver' => EnvAuditor::env('DW_AUTH_DRIVER', 'WORKFLOW_SERVER_AUTH_DRIVER', 'token'),
        'token' => EnvAuditor::env('DW_AUTH_TOKEN', 'WORKFLOW_SERVER_AUTH_TOKEN'),
        'signature_key' => EnvAuditor::env('DW_SIGNATURE_KEY', 'WORKFLOW_SERVER_SIGNATURE_KEY'),
        'role_tokens' => [
            'worker' => EnvAuditor::env('DW_WORKER_TOKEN', 'WORKFLOW_SERVER_WORKER_TOKEN'),
            'operator' => EnvAuditor::env('DW_OPERATOR_TOKEN', 'WORKFLOW_SERVER_OPERATOR_TOKEN'),
            'admin' => EnvAuditor::env('DW_ADMIN_TOKEN', 'WORKFLOW_SERVER_ADMIN_TOKEN'),
        ],
        'principal_tokens' => EnvAuditor::env('DW_PRINCIPAL_TOKENS', 'WORKFLOW_SERVER_PRINCIPAL_TOKENS'),
        'role_signature_keys' => [
            'worker' => EnvAuditor::env('DW_WORKER_SIGNATURE_KEY', 'WORKFLOW_SERVER_WORKER_SIGNATURE_KEY'),
            'operator' => EnvAuditor::env('DW_OPERATOR_SIGNATURE_KEY', 'WORKFLOW_SERVER_OPERATOR_SIGNATURE_KEY'),
            'admin' => EnvAuditor::env('DW_ADMIN_SIGNATURE_KEY', 'WORKFLOW_SERVER_ADMIN_SIGNATURE_KEY'),
        ],
        'backward_compatible' => filter_var(
            EnvAuditor::env('DW_AUTH_BACKWARD_COMPATIBLE', 'WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Attribution
    |--------------------------------------------------------------------------
    |
    | Control-plane commands record caller and authentication metadata in the
    | durable command context. By default the server records its own platform
    | identity. When the server sits behind a trusted gateway, forwarded caller
    | and auth headers can be opted in explicitly to preserve request-level
    | attribution in workflow history.
    |
    */

    'command_attribution' => [
        'trust_forwarded_headers' => filter_var(
            EnvAuditor::env(
                'DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS',
                'WORKFLOW_SERVER_TRUST_FORWARDED_ATTRIBUTION_HEADERS',
                false,
            ),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? false,
        'headers' => [
            'caller_type' => EnvAuditor::env('DW_CALLER_TYPE_HEADER', 'WORKFLOW_SERVER_CALLER_TYPE_HEADER', 'X-Workflow-Caller-Type'),
            'caller_label' => EnvAuditor::env('DW_CALLER_LABEL_HEADER', 'WORKFLOW_SERVER_CALLER_LABEL_HEADER', 'X-Workflow-Caller-Label'),
            'auth_status' => EnvAuditor::env('DW_AUTH_STATUS_HEADER', 'WORKFLOW_SERVER_AUTH_STATUS_HEADER', 'X-Workflow-Auth-Status'),
            'auth_method' => EnvAuditor::env('DW_AUTH_METHOD_HEADER', 'WORKFLOW_SERVER_AUTH_METHOD_HEADER', 'X-Workflow-Auth-Method'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Polling
    |--------------------------------------------------------------------------
    |
    | Configuration for long-poll task leasing. Workers poll the server
    | for available tasks; the server holds the connection open until
    | a task is available or the timeout expires.
    |
    */

    'polling' => [
        'timeout' => (int) EnvAuditor::env(
            'DW_WORKER_POLL_TIMEOUT',
            'WORKFLOW_SERVER_WORKER_POLL_TIMEOUT',
            WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
        ),
        'interval_ms' => (int) EnvAuditor::env('DW_WORKER_POLL_INTERVAL_MS', 'WORKFLOW_SERVER_WORKER_POLL_INTERVAL_MS', 1000),
        'signal_check_interval_ms' => (int) EnvAuditor::env('DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS', 'WORKFLOW_SERVER_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS', 100),
        'cache_retry_interval_ms' => 1000,
        'cache_path' => EnvAuditor::env(
            'DW_POLLING_CACHE_PATH',
            'WORKFLOW_SERVER_POLLING_CACHE_PATH',
            storage_path('framework/cache/server-polling/'.env('APP_ENV', 'production')),
        ),
        'wake_signal_ttl_seconds' => (int) EnvAuditor::env(
            'DW_WAKE_SIGNAL_TTL_SECONDS',
            'WORKFLOW_SERVER_WAKE_SIGNAL_TTL_SECONDS',
            max((int) EnvAuditor::env('DW_WORKER_POLL_TIMEOUT', 'WORKFLOW_SERVER_WORKER_POLL_TIMEOUT', 30) + 5, 60),
        ),
        'max_concurrent_waits' => EnvAuditor::env(
            'DW_WORKER_LONG_POLL_MAX_CONCURRENT',
            'WORKFLOW_SERVER_WORKER_LONG_POLL_MAX_CONCURRENT',
            null,
        ),
        'max_concurrent_waits_per_namespace' => EnvAuditor::env(
            'DW_WORKER_LONG_POLL_MAX_CONCURRENT_PER_NAMESPACE',
            'WORKFLOW_SERVER_WORKER_LONG_POLL_MAX_CONCURRENT_PER_NAMESPACE',
            null,
        ),
        'reserved_http_workers' => EnvAuditor::env(
            'DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS',
            'WORKFLOW_SERVER_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS',
            null,
        ),
        'max_tasks_per_poll' => (int) EnvAuditor::env('DW_MAX_TASKS_PER_POLL', 'WORKFLOW_SERVER_MAX_TASKS_PER_POLL', 1),
        'sqlite_claim_lock_ttl_seconds' => (int) EnvAuditor::env(
            'DW_SQLITE_CLAIM_LOCK_TTL_SECONDS',
            'WORKFLOW_SERVER_SQLITE_CLAIM_LOCK_TTL_SECONDS',
            10,
        ),
        'sqlite_claim_lock_wait_seconds' => (int) EnvAuditor::env(
            'DW_SQLITE_CLAIM_LOCK_WAIT_SECONDS',
            'WORKFLOW_SERVER_SQLITE_CLAIM_LOCK_WAIT_SECONDS',
            5,
        ),
        'due_timer_recovery_scan_limit' => (int) EnvAuditor::env(
            'DW_DUE_TIMER_RECOVERY_SCAN_LIMIT',
            'WORKFLOW_SERVER_DUE_TIMER_RECOVERY_SCAN_LIMIT',
            5,
        ),
        'expired_workflow_task_recovery_scan_limit' => (int) EnvAuditor::env(
            'DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT',
            'WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT',
            5,
        ),
        'expired_workflow_task_recovery_ttl_seconds' => (int) EnvAuditor::env(
            'DW_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS',
            'WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS',
            5,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Queue Admission
    |--------------------------------------------------------------------------
    |
    | Optional server-side active-lease and dispatch budgets for workflow and
    | activity task queues. Worker registrations still advertise local capacity;
    | these limits let operators cap queue, namespace, and downstream budget
    | group dispatch before a noisy tenant or dependency consumes the fleet.
    |
    */

    'admission' => [
        'workflow_tasks' => [
            'max_active_leases_per_queue' => EnvAuditor::env(
                'DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE',
                'WORKFLOW_SERVER_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE',
                null,
            ),
            'max_active_leases_per_namespace' => EnvAuditor::env(
                'DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE',
                'WORKFLOW_SERVER_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE',
                null,
            ),
            'max_dispatches_per_minute' => EnvAuditor::env(
                'DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE',
                'WORKFLOW_SERVER_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE',
                null,
            ),
            'max_dispatches_per_minute_per_namespace' => EnvAuditor::env(
                'DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE',
                'WORKFLOW_SERVER_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE',
                null,
            ),
        ],
        'activity_tasks' => [
            'max_active_leases_per_queue' => EnvAuditor::env(
                'DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_QUEUE',
                'WORKFLOW_SERVER_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_QUEUE',
                null,
            ),
            'max_active_leases_per_namespace' => EnvAuditor::env(
                'DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE',
                'WORKFLOW_SERVER_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE',
                null,
            ),
            'max_dispatches_per_minute' => EnvAuditor::env(
                'DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE',
                'WORKFLOW_SERVER_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE',
                null,
            ),
            'max_dispatches_per_minute_per_namespace' => EnvAuditor::env(
                'DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE',
                'WORKFLOW_SERVER_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE',
                null,
            ),
        ],
        'queue_overrides' => json_decode(
            (string) EnvAuditor::env(
                'DW_TASK_QUEUE_ADMISSION_OVERRIDES',
                'WORKFLOW_SERVER_TASK_QUEUE_ADMISSION_OVERRIDES',
                '{}',
            ),
            true,
        ) ?: [],
        'lock_ttl_seconds' => 5,
        'lock_wait_seconds' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Namespace Request Admission
    |--------------------------------------------------------------------------
    |
    | Control-plane requests can be bounded independently per namespace. Null
    | defaults are explicitly unlimited. Namespace overrides may lower or
    | raise defaults, but configured hard limits and implementation ceilings
    | remain non-bypassable.
    |
    */

    'namespace_admission' => [
        'max_requests_per_minute' => EnvAuditor::env(
            'DW_NAMESPACE_MAX_REQUESTS_PER_MINUTE',
            'WORKFLOW_SERVER_NAMESPACE_MAX_REQUESTS_PER_MINUTE',
            null,
        ),
        'max_concurrent_requests' => EnvAuditor::env(
            'DW_NAMESPACE_MAX_CONCURRENT_REQUESTS',
            'WORKFLOW_SERVER_NAMESPACE_MAX_CONCURRENT_REQUESTS',
            null,
        ),
        'hard_max_requests_per_minute' => EnvAuditor::env(
            'DW_NAMESPACE_HARD_MAX_REQUESTS_PER_MINUTE',
            'WORKFLOW_SERVER_NAMESPACE_HARD_MAX_REQUESTS_PER_MINUTE',
            null,
        ),
        'hard_max_concurrent_requests' => EnvAuditor::env(
            'DW_NAMESPACE_HARD_MAX_CONCURRENT_REQUESTS',
            'WORKFLOW_SERVER_NAMESPACE_HARD_MAX_CONCURRENT_REQUESTS',
            null,
        ),
        'overrides' => json_decode(
            (string) EnvAuditor::env(
                'DW_NAMESPACE_ADMISSION_OVERRIDES',
                'WORKFLOW_SERVER_NAMESPACE_ADMISSION_OVERRIDES',
                '{}',
            ),
            true,
        ) ?: [],
        'lock_ttl_seconds' => 5,
        'lock_wait_seconds' => 1,
        'request_lease_ttl_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Namespace Durable State
    |--------------------------------------------------------------------------
    |
    | Durable row counts can be bounded independently per namespace. Pending
    | task limits count queued ready rows; active leases are bounded separately
    | by task-queue admission. Empty objects are explicitly unlimited for
    | backwards compatibility. Namespace overrides may raise or lower defaults,
    | but hard limits remain non-bypassable. A configured limit is enforced
    | against database state while holding the namespace row lock.
    |
    */

    'namespace_durable_state' => [
        'limits' => json_decode(
            (string) EnvAuditor::env(
                'DW_NAMESPACE_DURABLE_LIMITS',
                'WORKFLOW_SERVER_NAMESPACE_DURABLE_LIMITS',
                '{}',
            ),
            true,
        ),
        'hard_limits' => json_decode(
            (string) EnvAuditor::env(
                'DW_NAMESPACE_DURABLE_HARD_LIMITS',
                'WORKFLOW_SERVER_NAMESPACE_DURABLE_HARD_LIMITS',
                '{}',
            ),
            true,
        ),
        'overrides' => json_decode(
            (string) EnvAuditor::env(
                'DW_NAMESPACE_DURABLE_OVERRIDES',
                'WORKFLOW_SERVER_NAMESPACE_DURABLE_OVERRIDES',
                '{}',
            ),
            true,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Protocol
    |--------------------------------------------------------------------------
    |
    | Versioned contract for external worker poll/complete/fail/heartbeat
    | requests. The server returns the version on every worker-plane response.
    |
    */

    'worker_protocol' => [
        'version' => EnvAuditor::env('DW_WORKER_PROTOCOL_VERSION', 'WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION', WorkerProtocol::VERSION),
        'history_page_size_default' => (int) EnvAuditor::env(
            'DW_HISTORY_PAGE_SIZE_DEFAULT',
            'WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT',
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
        ),
        'history_page_size_max' => (int) EnvAuditor::env(
            'DW_HISTORY_PAGE_SIZE_MAX',
            'WORKFLOW_SERVER_HISTORY_PAGE_SIZE_MAX',
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime External Payload Transport
    |--------------------------------------------------------------------------
    |
    | Encoded payloads above the inline threshold move through the authenticated
    | namespace runtime. The request body is deliberately bounded in memory;
    | backing-provider credentials and locations never cross this boundary.
    |
    */

    'external_payload_transport' => [
        'max_payload_bytes' => (int) EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_MAX_BYTES',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_MAX_BYTES',
            64 * 1024 * 1024,
        ),
        'request_timeout_seconds' => (int) EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_REQUEST_TIMEOUT',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_REQUEST_TIMEOUT',
            30,
        ),
        'abandoned_upload_expiry_seconds' => (int) EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_UPLOAD_EXPIRY',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_UPLOAD_EXPIRY',
            3600,
        ),
        'max_bytes_per_namespace' => EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_MAX_BYTES_PER_NAMESPACE',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_MAX_BYTES_PER_NAMESPACE',
            null,
        ),
        'max_objects_per_namespace' => EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_MAX_OBJECTS_PER_NAMESPACE',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_MAX_OBJECTS_PER_NAMESPACE',
            null,
        ),
        'hard_max_bytes_per_namespace' => EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_HARD_MAX_BYTES_PER_NAMESPACE',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_HARD_MAX_BYTES_PER_NAMESPACE',
            null,
        ),
        'hard_max_objects_per_namespace' => EnvAuditor::env(
            'DW_EXTERNAL_PAYLOAD_HARD_MAX_OBJECTS_PER_NAMESPACE',
            'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_HARD_MAX_OBJECTS_PER_NAMESPACE',
            null,
        ),
        'namespace_overrides' => json_decode(
            (string) EnvAuditor::env(
                'DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES',
                'WORKFLOW_SERVER_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES',
                '{}',
            ),
            true,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Task Transport
    |--------------------------------------------------------------------------
    |
    | Python and other external runtimes cannot be replayed in-process by the
    | PHP server. Control-plane queries for those workflows are forwarded as
    | ephemeral worker-plane query tasks and wait for the worker response.
    |
    */

    'query_tasks' => [
        'timeout' => (int) EnvAuditor::env(
            'DW_QUERY_TASK_TIMEOUT',
            'WORKFLOW_SERVER_QUERY_TASK_TIMEOUT',
            min(
                max(
                    (int) EnvAuditor::env(
                        'DW_WORKER_POLL_TIMEOUT',
                        'WORKFLOW_SERVER_WORKER_POLL_TIMEOUT',
                        WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT,
                    ) + 15,
                    40,
                ),
                55,
            ),
        ),
        'lease_timeout' => (int) EnvAuditor::env(
            'DW_QUERY_TASK_LEASE_TIMEOUT',
            'WORKFLOW_SERVER_QUERY_TASK_LEASE_TIMEOUT',
            EnvAuditor::env('DW_WORKFLOW_TASK_TIMEOUT', 'WORKFLOW_TASK_TIMEOUT', 60),
        ),
        'poll_timeout' => (int) EnvAuditor::env(
            'DW_QUERY_TASK_POLL_TIMEOUT',
            'WORKFLOW_SERVER_QUERY_TASK_POLL_TIMEOUT',
            5,
        ),
        'ttl_seconds' => (int) EnvAuditor::env('DW_QUERY_TASK_TTL_SECONDS', 'WORKFLOW_SERVER_QUERY_TASK_TTL_SECONDS', 180),
        'max_pending_per_queue' => (int) EnvAuditor::env(
            'DW_QUERY_TASK_MAX_PENDING_PER_QUEUE',
            'WORKFLOW_SERVER_QUERY_TASK_MAX_PENDING_PER_QUEUE',
            1024,
        ),
        'max_concurrent_poll_waits' => EnvAuditor::env(
            'DW_QUERY_TASK_POLL_MAX_CONCURRENT',
            'WORKFLOW_SERVER_QUERY_TASK_POLL_MAX_CONCURRENT',
            null,
        ),
        'max_concurrent_poll_waits_per_namespace' => EnvAuditor::env(
            'DW_QUERY_TASK_POLL_MAX_CONCURRENT_PER_NAMESPACE',
            'WORKFLOW_SERVER_QUERY_TASK_POLL_MAX_CONCURRENT_PER_NAMESPACE',
            null,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Task Lease
    |--------------------------------------------------------------------------
    |
    | How long a worker can hold a task before the lease expires and
    | the task becomes available for another worker to claim.
    |
    */

    'lease' => [
        'workflow_task_timeout' => (int) EnvAuditor::env('DW_WORKFLOW_TASK_TIMEOUT', 'WORKFLOW_TASK_TIMEOUT', 60),
        'activity_task_timeout' => (int) EnvAuditor::env('DW_ACTIVITY_TASK_TIMEOUT', 'ACTIVITY_TASK_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Fleet
    |--------------------------------------------------------------------------
    |
    | Worker registrations are marked stale when their heartbeat falls behind
    | this timeout. Task-queue visibility surfaces the derived stale status
    | without requiring a background sweeper to mutate registration rows.
    |
    */

    'workers' => [
        'stale_after_seconds' => (int) EnvAuditor::env(
            'DW_WORKER_STALE_AFTER_SECONDS',
            'WORKFLOW_SERVER_WORKER_STALE_AFTER_SECONDS',
            max(
                (int) EnvAuditor::env(
                    'DW_WORKER_HEARTBEAT_INTERVAL_SECONDS',
                    'WORKFLOW_SERVER_WORKER_HEARTBEAT_INTERVAL_SECONDS',
                    10,
                ) * 3,
                30,
            ),
        ),
        // Cadence advertised to SDKs in the register/heartbeat acknowledgement
        // so every official SDK ticks at the same beat by default. SDKs read
        // this value and feed it back into their periodic heartbeat loop;
        // operators can pin the cadence cluster-wide without changing the
        // worker fleet's configuration. Bounded to [1, 3600] seconds.
        'heartbeat_interval_seconds' => (int) EnvAuditor::env(
            'DW_WORKER_HEARTBEAT_INTERVAL_SECONDS',
            'WORKFLOW_SERVER_WORKER_HEARTBEAT_INTERVAL_SECONDS',
            10,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Server-owned metrics keep user-controlled dimensions capped before any
    | JSON or Prometheus-style surface exposes them to long-running operators.
    |
    */

    'metrics' => [
        'workflow_task_failure_type_limit' => (int) EnvAuditor::env(
            'DW_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT',
            'WORKFLOW_SERVER_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT',
            20,
        ),
        'prometheus_workflow_series_limit' => (int) EnvAuditor::env(
            'DW_METRICS_PROMETHEUS_WORKFLOW_SERIES_LIMIT',
            'WORKFLOW_SERVER_METRICS_PROMETHEUS_WORKFLOW_SERIES_LIMIT',
            100,
        ),
        'prometheus_activity_series_limit' => (int) EnvAuditor::env(
            'DW_METRICS_PROMETHEUS_ACTIVITY_SERIES_LIMIT',
            'WORKFLOW_SERVER_METRICS_PROMETHEUS_ACTIVITY_SERIES_LIMIT',
            100,
        ),
        'prometheus_task_queue_series_limit' => (int) EnvAuditor::env(
            'DW_METRICS_PROMETHEUS_TASK_QUEUE_SERIES_LIMIT',
            'WORKFLOW_SERVER_METRICS_PROMETHEUS_TASK_QUEUE_SERIES_LIMIT',
            100,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Controls history retention, export, and budget limits.
    |
    */

    'history' => [
        'max_events_per_run' => (int) EnvAuditor::env('DW_MAX_HISTORY_EVENTS', 'WORKFLOW_MAX_HISTORY_EVENTS', 50000),
        'retention_days' => (int) EnvAuditor::env('DW_HISTORY_RETENTION_DAYS', 'WORKFLOW_HISTORY_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Compression
    |--------------------------------------------------------------------------
    |
    | When enabled, JSON responses above the minimum size threshold are
    | compressed using the encoding requested by the client's Accept-Encoding
    | header (gzip or deflate). Disable when a reverse proxy already handles
    | compression.
    |
    */

    'compression' => [
        'enabled' => filter_var(
            EnvAuditor::env('DW_COMPRESSION_ENABLED', 'WORKFLOW_SERVER_COMPRESSION_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Package Provenance Exposure
    |--------------------------------------------------------------------------
    |
    | When enabled, /api/cluster/info includes a `package_provenance` object
    | describing the PHP workflow package's source repository, ref, and commit
    | hash. This leaks PHP implementation identity to polyglot clients and is
    | OFF by default. Enable only for admin diagnostics; the field is still
    | restricted to authenticated admin callers when exposure is on.
    |
    */

    'expose_package_provenance' => filter_var(
        EnvAuditor::env('DW_EXPOSE_PACKAGE_PROVENANCE', 'WORKFLOW_SERVER_EXPOSE_PACKAGE_PROVENANCE', false),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE,
    ) ?? false,

    /*
    |--------------------------------------------------------------------------
    | Package Provenance Path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the provenance file that records the workflow package
    | source, ref, and resolved commit. Docker builds write this file to
    | `/app/.package-provenance` (see Dockerfile); a Laravel-native install
    | does not produce one. Tests override this key to isolate fixtures from
    | any real provenance file at the repo root.
    |
    */

    'package_provenance_path' => EnvAuditor::env('DW_PACKAGE_PROVENANCE_PATH', 'WORKFLOW_SERVER_PACKAGE_PROVENANCE_PATH', base_path('.package-provenance')),

    /*
    |--------------------------------------------------------------------------
    | Payload Limits
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Service-Call Boundary
    |--------------------------------------------------------------------------
    |
    | Cross-namespace service calls run through a single boundary policy
    | before handler dispatch. The rules below merge on top of the
    | workflow package defaults (workflows.v2.service_boundary.rules), so
    | operators can keep the package defaults and only override the
    | knobs that matter for the current cluster.
    |
    | See Workflow\V2\Support\DefaultServiceBoundaryPolicy for the full
    | rule schema. The default posture is "allow" so introducing the
    | boundary does not silently disable existing service calls; tighten
    | this in environments that should fail closed.
    |
    */

    'service_boundary' => [
        'rules' => [
            'namespaces' => [
                'cross_namespace_default' => EnvAuditor::env(
                    'DW_SERVICE_BOUNDARY_CROSS_NAMESPACE_DEFAULT',
                    'WORKFLOW_SERVER_SERVICE_BOUNDARY_CROSS_NAMESPACE_DEFAULT',
                    'allow',
                ),
            ],
            'rate_limit' => [
                'requests_per_minute' => EnvAuditor::env(
                    'DW_SERVICE_BOUNDARY_RATE_LIMIT_PER_MINUTE',
                    'WORKFLOW_SERVER_SERVICE_BOUNDARY_RATE_LIMIT_PER_MINUTE',
                    null,
                ),
            ],
            'concurrency' => [
                'max_in_flight' => EnvAuditor::env(
                    'DW_SERVICE_BOUNDARY_MAX_IN_FLIGHT',
                    'WORKFLOW_SERVER_SERVICE_BOUNDARY_MAX_IN_FLIGHT',
                    null,
                ),
            ],
        ],
        'shared_admission' => [
            'max_requests_per_minute_per_namespace' => EnvAuditor::env(
                'DW_SERVICE_BOUNDARY_NAMESPACE_RATE_LIMIT_PER_MINUTE',
                'WORKFLOW_SERVER_SERVICE_BOUNDARY_NAMESPACE_RATE_LIMIT_PER_MINUTE',
                null,
            ),
            'max_in_flight_per_namespace' => EnvAuditor::env(
                'DW_SERVICE_BOUNDARY_NAMESPACE_MAX_IN_FLIGHT',
                'WORKFLOW_SERVER_SERVICE_BOUNDARY_NAMESPACE_MAX_IN_FLIGHT',
                null,
            ),
            'hard_max_requests_per_minute' => EnvAuditor::env(
                'DW_SERVICE_BOUNDARY_HARD_RATE_LIMIT_PER_MINUTE',
                'WORKFLOW_SERVER_SERVICE_BOUNDARY_HARD_RATE_LIMIT_PER_MINUTE',
                null,
            ),
            'hard_max_in_flight' => EnvAuditor::env(
                'DW_SERVICE_BOUNDARY_HARD_MAX_IN_FLIGHT',
                'WORKFLOW_SERVER_SERVICE_BOUNDARY_HARD_MAX_IN_FLIGHT',
                null,
            ),
            'namespace_overrides' => json_decode(
                (string) EnvAuditor::env(
                    'DW_SERVICE_BOUNDARY_NAMESPACE_OVERRIDES',
                    'WORKFLOW_SERVER_SERVICE_BOUNDARY_NAMESPACE_OVERRIDES',
                    '{}',
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'concurrency_lease_ttl_seconds' => 86400,
            'retry_after_seconds' => 1,
        ],
    ],

    'limits' => [
        'max_payload_bytes' => (int) EnvAuditor::env('DW_MAX_PAYLOAD_BYTES', 'WORKFLOW_MAX_PAYLOAD_BYTES', 2 * 1024 * 1024),
        'max_memo_bytes' => (int) EnvAuditor::env('DW_MAX_MEMO_BYTES', 'WORKFLOW_MAX_MEMO_BYTES', 256 * 1024),
        'max_search_attributes' => (int) EnvAuditor::env('DW_MAX_SEARCH_ATTRIBUTES', 'WORKFLOW_MAX_SEARCH_ATTRIBUTES', 100),
        'max_search_attribute_key_length' => (int) EnvAuditor::env(
            'DW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH',
            'WORKFLOW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH',
            128,
        ),
        'max_search_attribute_value_bytes' => (int) EnvAuditor::env(
            'DW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES',
            'WORKFLOW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES',
            2048,
        ),
        'max_operation_name_length' => (int) EnvAuditor::env(
            'DW_MAX_OPERATION_NAME_LENGTH',
            'WORKFLOW_MAX_OPERATION_NAME_LENGTH',
            256,
        ),
        'max_pending_activities' => (int) EnvAuditor::env('DW_MAX_PENDING_ACTIVITIES', 'WORKFLOW_MAX_PENDING_ACTIVITIES', 2000),
        'max_pending_children' => (int) EnvAuditor::env('DW_MAX_PENDING_CHILDREN', 'WORKFLOW_MAX_PENDING_CHILDREN', 2000),

        // Caps the per-caller list size returned from the Nexus operations
        // history surface. A workflow with more outbound Nexus calls than
        // this limit must paginate via the per-call describe surface or use
        // the cross-namespace audit query.
        'max_nexus_operations_per_caller' => (int) EnvAuditor::env(
            'DW_MAX_NEXUS_OPERATIONS_PER_CALLER',
            'WORKFLOW_MAX_NEXUS_OPERATIONS_PER_CALLER',
            200,
        ),
    ],

];
