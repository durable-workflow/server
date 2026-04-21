<?php

use App\Support\EnvAuditor;
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
| DW_* name requires a major bump with the old name aliased for one
| major — see zorporation/durable-workflow#455.
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
        'driver' => EnvAuditor::env('DW_AUTH_DRIVER', 'WORKFLOW_SERVER_AUTH_DRIVER', 'token'),
        'token' => EnvAuditor::env('DW_AUTH_TOKEN', 'WORKFLOW_SERVER_AUTH_TOKEN'),
        'signature_key' => EnvAuditor::env('DW_SIGNATURE_KEY', 'WORKFLOW_SERVER_SIGNATURE_KEY'),
        'role_tokens' => [
            'worker' => EnvAuditor::env('DW_WORKER_TOKEN', 'WORKFLOW_SERVER_WORKER_TOKEN'),
            'operator' => EnvAuditor::env('DW_OPERATOR_TOKEN', 'WORKFLOW_SERVER_OPERATOR_TOKEN'),
            'admin' => EnvAuditor::env('DW_ADMIN_TOKEN', 'WORKFLOW_SERVER_ADMIN_TOKEN'),
        ],
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
        'max_tasks_per_poll' => (int) EnvAuditor::env('DW_MAX_TASKS_PER_POLL', 'WORKFLOW_SERVER_MAX_TASKS_PER_POLL', 1),
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
    | Worker Protocol
    |--------------------------------------------------------------------------
    |
    | Versioned contract for external worker poll/complete/fail/heartbeat
    | requests. The server returns the version on every worker-plane response.
    |
    */

    'worker_protocol' => [
        'version' => EnvAuditor::env('DW_WORKER_PROTOCOL_VERSION', 'WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION', WorkerProtocolVersion::VERSION),
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
            EnvAuditor::env('DW_WORKER_POLL_TIMEOUT', 'WORKFLOW_SERVER_WORKER_POLL_TIMEOUT', WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT),
        ),
        'lease_timeout' => (int) EnvAuditor::env(
            'DW_QUERY_TASK_LEASE_TIMEOUT',
            'WORKFLOW_SERVER_QUERY_TASK_LEASE_TIMEOUT',
            EnvAuditor::env('DW_WORKFLOW_TASK_TIMEOUT', 'WORKFLOW_TASK_TIMEOUT', 60),
        ),
        'ttl_seconds' => (int) EnvAuditor::env('DW_QUERY_TASK_TTL_SECONDS', 'WORKFLOW_SERVER_QUERY_TASK_TTL_SECONDS', 180),
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
            max((int) EnvAuditor::env('DW_WORKER_POLL_TIMEOUT', 'WORKFLOW_SERVER_WORKER_POLL_TIMEOUT', 30) * 2, 60),
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
    ],

];
