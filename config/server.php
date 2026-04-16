<?php

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

    'mode' => env('WORKFLOW_SERVER_MODE', 'service'),

    /*
    |--------------------------------------------------------------------------
    | Server Identity
    |--------------------------------------------------------------------------
    |
    | A unique identifier for this server instance, used in lease ownership,
    | worker registration, and cluster coordination.
    |
    */

    'server_id' => env('WORKFLOW_SERVER_ID', gethostname()),

    /*
    |--------------------------------------------------------------------------
    | Default Namespace
    |--------------------------------------------------------------------------
    |
    | The default namespace used when no namespace header is provided.
    | Namespaces isolate workflow instances, runs, and visibility.
    |
    */

    'default_namespace' => env('WORKFLOW_SERVER_DEFAULT_NAMESPACE', 'default'),

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
        'driver' => env('WORKFLOW_SERVER_AUTH_DRIVER', 'token'),
        'token' => env('WORKFLOW_SERVER_AUTH_TOKEN'),
        'signature_key' => env('WORKFLOW_SERVER_SIGNATURE_KEY'),
        'role_tokens' => [
            'worker' => env('WORKFLOW_SERVER_WORKER_TOKEN'),
            'operator' => env('WORKFLOW_SERVER_OPERATOR_TOKEN'),
            'admin' => env('WORKFLOW_SERVER_ADMIN_TOKEN'),
        ],
        'role_signature_keys' => [
            'worker' => env('WORKFLOW_SERVER_WORKER_SIGNATURE_KEY'),
            'operator' => env('WORKFLOW_SERVER_OPERATOR_SIGNATURE_KEY'),
            'admin' => env('WORKFLOW_SERVER_ADMIN_SIGNATURE_KEY'),
        ],
        'backward_compatible' => (bool) env('WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE', true),
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
        'trust_forwarded_headers' => (bool) env(
            'WORKFLOW_SERVER_TRUST_FORWARDED_ATTRIBUTION_HEADERS',
            false,
        ),
        'headers' => [
            'caller_type' => env('WORKFLOW_SERVER_CALLER_TYPE_HEADER', 'X-Workflow-Caller-Type'),
            'caller_label' => env('WORKFLOW_SERVER_CALLER_LABEL_HEADER', 'X-Workflow-Caller-Label'),
            'auth_status' => env('WORKFLOW_SERVER_AUTH_STATUS_HEADER', 'X-Workflow-Auth-Status'),
            'auth_method' => env('WORKFLOW_SERVER_AUTH_METHOD_HEADER', 'X-Workflow-Auth-Method'),
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
        'timeout' => (int) env('WORKFLOW_SERVER_WORKER_POLL_TIMEOUT', 30),
        'interval_ms' => (int) env('WORKFLOW_SERVER_WORKER_POLL_INTERVAL_MS', 1000),
        'signal_check_interval_ms' => (int) env('WORKFLOW_SERVER_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS', 100),
        'cache_path' => env(
            'WORKFLOW_SERVER_POLLING_CACHE_PATH',
            storage_path('framework/cache/server-polling/'.env('APP_ENV', 'production')),
        ),
        'wake_signal_ttl_seconds' => (int) env(
            'WORKFLOW_SERVER_WAKE_SIGNAL_TTL_SECONDS',
            max((int) env('WORKFLOW_SERVER_WORKER_POLL_TIMEOUT', 30) + 5, 60),
        ),
        'max_tasks_per_poll' => (int) env('WORKFLOW_SERVER_MAX_TASKS_PER_POLL', 1),
        'expired_workflow_task_recovery_scan_limit' => (int) env(
            'WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT',
            5,
        ),
        'expired_workflow_task_recovery_ttl_seconds' => (int) env(
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
        'version' => env('WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION', '1.0'),
        'history_page_size_default' => (int) env('WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT', 500),
        'history_page_size_max' => (int) env('WORKFLOW_SERVER_HISTORY_PAGE_SIZE_MAX', 1000),
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
        'workflow_task_timeout' => (int) env('WORKFLOW_TASK_TIMEOUT', 60),
        'activity_task_timeout' => (int) env('ACTIVITY_TASK_TIMEOUT', 300),
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
        'stale_after_seconds' => (int) env(
            'WORKFLOW_SERVER_WORKER_STALE_AFTER_SECONDS',
            max((int) env('WORKFLOW_SERVER_WORKER_POLL_TIMEOUT', 30) * 2, 60),
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
        'max_events_per_run' => (int) env('WORKFLOW_MAX_HISTORY_EVENTS', 50000),
        'retention_days' => (int) env('WORKFLOW_HISTORY_RETENTION_DAYS', 30),
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
        'enabled' => (bool) env('WORKFLOW_SERVER_COMPRESSION_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_payload_bytes' => (int) env('WORKFLOW_MAX_PAYLOAD_BYTES', 2 * 1024 * 1024),
        'max_memo_bytes' => (int) env('WORKFLOW_MAX_MEMO_BYTES', 256 * 1024),
        'max_search_attributes' => (int) env('WORKFLOW_MAX_SEARCH_ATTRIBUTES', 100),
        'max_pending_activities' => (int) env('WORKFLOW_MAX_PENDING_ACTIVITIES', 2000),
        'max_pending_children' => (int) env('WORKFLOW_MAX_PENDING_CHILDREN', 2000),
    ],

];
