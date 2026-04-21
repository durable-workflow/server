<?php

/*
|--------------------------------------------------------------------------
| DW_* Environment Variable Contract
|--------------------------------------------------------------------------
|
| This file is the machine-checkable contract for operator-facing
| environment variables exposed by the Durable Workflow server image.
|
| Rules (see zorporation/durable-workflow#455):
|
|   1. Every operator-facing env var the server honors has the `DW_` prefix
|      and appears in the `vars` list below.
|
|   2. Entries are stable across minor versions. Additions are fine;
|      renames require a major bump with the old name alias-honored for
|      one major. Legacy names that still resolve live in `legacy`.
|
|   3. At boot, `php artisan env:audit` (invoked from the Docker
|      entrypoint) logs a warning for every `DW_` variable set in the
|      environment that is not in `vars`, and for every legacy name that
|      still resolves. This catches typos and silent-drop renames.
|
|   4. CI diffs this contract against `.env.example`, `docker-compose.yml`,
|      and `k8s/secret.yaml` via `tests/Unit/EnvContractTest.php` so the
|      three surfaces cannot drift.
|
| Unprefixed Laravel framework env vars (APP_KEY, DB_*, REDIS_*, etc.) are
| not operator-facing DW config — they are Laravel framework controls —
| and are listed in `framework` purely so the audit does not warn about
| them.
|
*/

return [

    'prefix' => 'DW_',

    /*
    | ----------------------------------------------------------------------
    | DW_* operator-facing vars
    | ----------------------------------------------------------------------
    |
    | Each entry records:
    |   description: one-line human summary
    |   default:     textual representation of the default (or null when
    |                unset means "unset")
    |   since:       the server version the var was introduced in
    |   legacy:      optional legacy env var name that still resolves as
    |                a fallback — logged with a rename hint at boot.
    */

    'vars' => [

        // --- Server identity / mode ------------------------------------

        'DW_MODE' => [
            'description' => 'Server mode: "service" (default, external workers poll) or "embedded" (local queue).',
            'default' => 'service',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_MODE',
        ],
        'DW_SERVER_ID' => [
            'description' => 'Unique identifier for this server instance, used in lease ownership and worker registration.',
            'default' => 'gethostname()',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_ID',
        ],
        'DW_DEFAULT_NAMESPACE' => [
            'description' => 'Namespace used when a request does not carry the Durable-Workflow-Namespace header.',
            'default' => 'default',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_DEFAULT_NAMESPACE',
        ],
        'DW_TASK_DISPATCH_MODE' => [
            'description' => 'Override for workflows.v2.task_dispatch_mode. In service mode the server defaults to "poll"; set "queue" to dispatch locally anyway.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_TASK_DISPATCH_MODE',
        ],

        // --- Authentication --------------------------------------------

        'DW_AUTH_PROVIDER' => [
            'description' => 'Optional FQCN of a Laravel-resolvable class implementing App\\Contracts\\AuthProvider.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_AUTH_PROVIDER',
        ],
        'DW_AUTH_DRIVER' => [
            'description' => 'Auth driver: "none", "token", or "signature".',
            'default' => 'token',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_AUTH_DRIVER',
        ],
        'DW_AUTH_TOKEN' => [
            'description' => 'Single shared bearer token when no role-scoped token is configured (backward-compat credential).',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_AUTH_TOKEN',
        ],
        'DW_SIGNATURE_KEY' => [
            'description' => 'HMAC signature key used when DW_AUTH_DRIVER=signature and no role-scoped key is configured.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_SIGNATURE_KEY',
        ],
        'DW_WORKER_TOKEN' => [
            'description' => 'Bearer token for the worker role (poll, heartbeat, completion endpoints).',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_TOKEN',
        ],
        'DW_OPERATOR_TOKEN' => [
            'description' => 'Bearer token for the operator role (read-only control plane).',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_OPERATOR_TOKEN',
        ],
        'DW_ADMIN_TOKEN' => [
            'description' => 'Bearer token for the admin role (mutating control plane).',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_ADMIN_TOKEN',
        ],
        'DW_WORKER_SIGNATURE_KEY' => [
            'description' => 'HMAC signature key for the worker role when DW_AUTH_DRIVER=signature.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_SIGNATURE_KEY',
        ],
        'DW_OPERATOR_SIGNATURE_KEY' => [
            'description' => 'HMAC signature key for the operator role when DW_AUTH_DRIVER=signature.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_OPERATOR_SIGNATURE_KEY',
        ],
        'DW_ADMIN_SIGNATURE_KEY' => [
            'description' => 'HMAC signature key for the admin role when DW_AUTH_DRIVER=signature.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_ADMIN_SIGNATURE_KEY',
        ],
        'DW_AUTH_BACKWARD_COMPATIBLE' => [
            'description' => 'When true, honor the single DW_AUTH_TOKEN / DW_SIGNATURE_KEY as a fallback when a role-scoped credential is missing.',
            'default' => 'true',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE',
        ],

        // --- Command attribution ---------------------------------------

        'DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS' => [
            'description' => 'When true, record forwarded caller/auth headers from a trusted gateway into workflow command history.',
            'default' => 'false',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_TRUST_FORWARDED_ATTRIBUTION_HEADERS',
        ],
        'DW_CALLER_TYPE_HEADER' => [
            'description' => 'Request header that carries the forwarded caller type.',
            'default' => 'X-Workflow-Caller-Type',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_CALLER_TYPE_HEADER',
        ],
        'DW_CALLER_LABEL_HEADER' => [
            'description' => 'Request header that carries the forwarded caller label.',
            'default' => 'X-Workflow-Caller-Label',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_CALLER_LABEL_HEADER',
        ],
        'DW_AUTH_STATUS_HEADER' => [
            'description' => 'Request header that carries the forwarded auth status.',
            'default' => 'X-Workflow-Auth-Status',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_AUTH_STATUS_HEADER',
        ],
        'DW_AUTH_METHOD_HEADER' => [
            'description' => 'Request header that carries the forwarded auth method.',
            'default' => 'X-Workflow-Auth-Method',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_AUTH_METHOD_HEADER',
        ],

        // --- Worker polling --------------------------------------------

        'DW_WORKER_POLL_TIMEOUT' => [
            'description' => 'Seconds the server holds a poll open waiting for a task.',
            'default' => '30',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_POLL_TIMEOUT',
        ],
        'DW_WORKER_POLL_INTERVAL_MS' => [
            'description' => 'Milliseconds between internal scans while a poll is held open.',
            'default' => '1000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_POLL_INTERVAL_MS',
        ],
        'DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS' => [
            'description' => 'Milliseconds between wake-signal checks while a poll is held open.',
            'default' => '100',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS',
        ],
        'DW_POLLING_CACHE_PATH' => [
            'description' => 'Directory for worker-poll coordination state.',
            'default' => 'storage/framework/cache/server-polling/<APP_ENV>',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_POLLING_CACHE_PATH',
        ],
        'DW_WAKE_SIGNAL_TTL_SECONDS' => [
            'description' => 'TTL for per-queue wake signals that short-circuit a pending poll.',
            'default' => 'max(DW_WORKER_POLL_TIMEOUT + 5, 60)',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WAKE_SIGNAL_TTL_SECONDS',
        ],
        'DW_MAX_TASKS_PER_POLL' => [
            'description' => 'Maximum tasks returned per worker poll.',
            'default' => '1',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_MAX_TASKS_PER_POLL',
        ],
        'DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT' => [
            'description' => 'Maximum expired workflow tasks to recover per pass.',
            'default' => '5',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT',
        ],
        'DW_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS' => [
            'description' => 'Minimum seconds between expired-task recovery passes per queue.',
            'default' => '5',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS',
        ],

        // --- Worker protocol -------------------------------------------

        'DW_WORKER_PROTOCOL_VERSION' => [
            'description' => 'Override for the worker protocol version advertised on worker-plane responses.',
            'default' => 'WorkerProtocolVersion::VERSION',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION',
        ],
        'DW_HISTORY_PAGE_SIZE_DEFAULT' => [
            'description' => 'Default page size for worker history reads when the client does not request one.',
            'default' => 'WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT',
        ],
        'DW_HISTORY_PAGE_SIZE_MAX' => [
            'description' => 'Maximum page size honored on worker history reads.',
            'default' => 'WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_HISTORY_PAGE_SIZE_MAX',
        ],

        // --- Query task transport --------------------------------------

        'DW_QUERY_TASK_TIMEOUT' => [
            'description' => 'Seconds the control plane waits for a query task response from the worker.',
            'default' => 'DW_WORKER_POLL_TIMEOUT',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_QUERY_TASK_TIMEOUT',
        ],
        'DW_QUERY_TASK_LEASE_TIMEOUT' => [
            'description' => 'Lease timeout (seconds) for ephemeral query tasks handed to workers.',
            'default' => 'DW_WORKFLOW_TASK_TIMEOUT',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_QUERY_TASK_LEASE_TIMEOUT',
        ],
        'DW_QUERY_TASK_TTL_SECONDS' => [
            'description' => 'How long the server retains query-task result rows before reaping them.',
            'default' => '180',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_QUERY_TASK_TTL_SECONDS',
        ],
        'DW_QUERY_TASK_MAX_PENDING_PER_QUEUE' => [
            'description' => 'Maximum pending cache-backed query tasks retained per namespace/task queue before new control-plane queries are rejected.',
            'default' => '1024',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_QUERY_TASK_MAX_PENDING_PER_QUEUE',
        ],

        // --- Lease / timeout defaults ----------------------------------

        'DW_WORKFLOW_TASK_TIMEOUT' => [
            'description' => 'Default workflow-task lease timeout in seconds.',
            'default' => '60',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_TASK_TIMEOUT',
        ],
        'DW_ACTIVITY_TASK_TIMEOUT' => [
            'description' => 'Default activity-task lease timeout in seconds.',
            'default' => '300',
            'since' => '2.0.0',
            'legacy' => 'ACTIVITY_TASK_TIMEOUT',
        ],

        // --- Worker fleet ---------------------------------------------

        'DW_WORKER_STALE_AFTER_SECONDS' => [
            'description' => 'Seconds after a worker heartbeat before the worker registration is surfaced as stale.',
            'default' => 'max(DW_WORKER_POLL_TIMEOUT * 2, 60)',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_WORKER_STALE_AFTER_SECONDS',
        ],

        // --- Metrics --------------------------------------------------

        'DW_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT' => [
            'description' => 'Maximum workflow_type series reported by dw_workflow_task_consecutive_failures; excess types are summarized.',
            'default' => '20',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT',
        ],

        // --- History / retention / limits ------------------------------

        'DW_MAX_HISTORY_EVENTS' => [
            'description' => 'Maximum history events per workflow run before continue-as-new is enforced.',
            'default' => '50000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_HISTORY_EVENTS',
        ],
        'DW_HISTORY_RETENTION_DAYS' => [
            'description' => 'Default number of days closed-run history is retained when a namespace does not override it.',
            'default' => '30',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_HISTORY_RETENTION_DAYS',
        ],
        'DW_MAX_PAYLOAD_BYTES' => [
            'description' => 'Maximum serialized bytes for a single payload (input/output/signal/update).',
            'default' => '2097152',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_PAYLOAD_BYTES',
        ],
        'DW_MAX_MEMO_BYTES' => [
            'description' => 'Maximum serialized bytes for a workflow memo.',
            'default' => '262144',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_MEMO_BYTES',
        ],
        'DW_MAX_SEARCH_ATTRIBUTES' => [
            'description' => 'Maximum number of search attributes on a single workflow.',
            'default' => '100',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_SEARCH_ATTRIBUTES',
        ],
        'DW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH' => [
            'description' => 'Maximum length in bytes for a single search-attribute key at the request boundary.',
            'default' => '128',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH',
        ],
        'DW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES' => [
            'description' => 'Maximum size in bytes for a single search-attribute string value at the request boundary.',
            'default' => '2048',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES',
        ],
        'DW_MAX_OPERATION_NAME_LENGTH' => [
            'description' => 'Maximum length in bytes for a signal, update, or query name accepted at the request boundary.',
            'default' => '256',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_OPERATION_NAME_LENGTH',
        ],
        'DW_MAX_PENDING_ACTIVITIES' => [
            'description' => 'Maximum pending activities per workflow run before the server rejects a command batch.',
            'default' => '2000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_PENDING_ACTIVITIES',
        ],
        'DW_MAX_PENDING_CHILDREN' => [
            'description' => 'Maximum pending child workflows per run before the server rejects a command batch.',
            'default' => '2000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_MAX_PENDING_CHILDREN',
        ],

        // --- Response compression --------------------------------------

        'DW_COMPRESSION_ENABLED' => [
            'description' => 'Enable response compression for JSON payloads above the minimum size threshold.',
            'default' => 'true',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_COMPRESSION_ENABLED',
        ],

        // --- Provenance ------------------------------------------------

        'DW_EXPOSE_PACKAGE_PROVENANCE' => [
            'description' => 'Include package_provenance in /api/cluster/info (admin-only). Off by default.',
            'default' => 'false',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_EXPOSE_PACKAGE_PROVENANCE',
        ],
        'DW_PACKAGE_PROVENANCE_PATH' => [
            'description' => 'Absolute path to the provenance file written at Docker build time.',
            'default' => '<base_path>/.package-provenance',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_PACKAGE_PROVENANCE_PATH',
        ],

        // --- Docker bootstrap ------------------------------------------

        'DW_ENV_AUDIT_STRICT' => [
            'description' => 'When "1", the Docker entrypoint fails container boot if the env:audit finds unknown or legacy DW_* vars.',
            'default' => '0',
            'since' => '2.0.0',
        ],
        'DW_BOOTSTRAP_RETRIES' => [
            'description' => 'Number of bootstrap attempts before the entrypoint gives up (migrations + seed).',
            'default' => '30',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_BOOTSTRAP_RETRIES',
        ],
        'DW_BOOTSTRAP_DELAY_SECONDS' => [
            'description' => 'Seconds between bootstrap attempts.',
            'default' => '2',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERVER_BOOTSTRAP_DELAY_SECONDS',
        ],

        // --- Workflow package (vendor/durable-workflow/workflow) -------
        //
        // These control behavior of the durable-workflow/workflow package
        // bundled inside the server image. They are resolved inside the
        // package's config/workflows.php via Workflow\Support\Env::dw and
        // follow the same DW_*-primary / legacy-fallback pattern as the
        // server's own config/server.php (see
        // zorporation/durable-workflow#494).

        'DW_V2_NAMESPACE' => [
            'description' => 'Scopes workflow instances to a namespace. When unset, instances are visible to every consumer.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_NAMESPACE',
        ],
        'DW_V2_CURRENT_COMPATIBILITY' => [
            'description' => 'Worker-compatibility marker this worker advertises (e.g. "build-2026-04-17"). Null means no marker.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_CURRENT_COMPATIBILITY',
        ],
        'DW_V2_SUPPORTED_COMPATIBILITIES' => [
            'description' => 'Comma-separated list of worker-compatibility markers this worker accepts, or "*" to accept any.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_SUPPORTED_COMPATIBILITIES',
        ],
        'DW_V2_COMPATIBILITY_NAMESPACE' => [
            'description' => 'Compatibility namespace when multiple apps share one workflow database but maintain independent compatibility fleets.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_COMPATIBILITY_NAMESPACE',
        ],
        'DW_V2_COMPATIBILITY_HEARTBEAT_TTL' => [
            'description' => 'Seconds a worker-compatibility heartbeat remains valid.',
            'default' => '30',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_COMPATIBILITY_HEARTBEAT_TTL',
        ],
        'DW_V2_PIN_TO_RECORDED_FINGERPRINT' => [
            'description' => 'When true (default), in-flight runs resolve their workflow class from the fingerprint recorded at WorkflowStarted.',
            'default' => 'true',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_PIN_TO_RECORDED_FINGERPRINT',
        ],
        'DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD' => [
            'description' => 'History event count at which the package signals the workflow author to continue-as-new.',
            'default' => '10000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD',
        ],
        'DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD' => [
            'description' => 'Serialized-history size (bytes) at which the package signals continue-as-new.',
            'default' => '5242880',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD',
        ],
        'DW_V2_HISTORY_EXPORT_SIGNING_KEY' => [
            'description' => 'Optional HMAC key for authenticating history export archives. Unset emits unsigned exports.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY',
        ],
        'DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID' => [
            'description' => 'Optional key identifier recorded alongside signed history exports for rotation.',
            'default' => null,
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID',
        ],
        'DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS' => [
            'description' => 'Seconds the server waits for a workflow update to reach a terminal stage before returning.',
            'default' => '10',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS',
        ],
        'DW_V2_UPDATE_WAIT_POLL_INTERVAL_MS' => [
            'description' => 'Milliseconds between update-stage polls while waiting for a workflow update.',
            'default' => '50',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_UPDATE_WAIT_POLL_INTERVAL_MS',
        ],
        'DW_V2_GUARDRAILS_BOOT' => [
            'description' => 'Boot-time structural guardrail mode: "warn", "fail", or "silent".',
            'default' => 'warn',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_GUARDRAILS_BOOT',
        ],
        'DW_V2_LIMIT_PENDING_ACTIVITIES' => [
            'description' => 'Package-level pending-activity ceiling before a command batch is rejected.',
            'default' => '2000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_PENDING_ACTIVITIES',
        ],
        'DW_V2_LIMIT_PENDING_CHILDREN' => [
            'description' => 'Package-level pending-child-workflow ceiling before a command batch is rejected.',
            'default' => '1000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_PENDING_CHILDREN',
        ],
        'DW_V2_LIMIT_PENDING_TIMERS' => [
            'description' => 'Package-level pending-timer ceiling before a command batch is rejected.',
            'default' => '2000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_PENDING_TIMERS',
        ],
        'DW_V2_LIMIT_PENDING_SIGNALS' => [
            'description' => 'Package-level pending-signal ceiling before a command batch is rejected.',
            'default' => '5000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_PENDING_SIGNALS',
        ],
        'DW_V2_LIMIT_PENDING_UPDATES' => [
            'description' => 'Package-level pending-update ceiling before a command batch is rejected.',
            'default' => '500',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_PENDING_UPDATES',
        ],
        'DW_V2_LIMIT_COMMAND_BATCH_SIZE' => [
            'description' => 'Maximum commands accepted in a single workflow-task completion.',
            'default' => '1000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_COMMAND_BATCH_SIZE',
        ],
        'DW_V2_LIMIT_PAYLOAD_SIZE_BYTES' => [
            'description' => 'Package-level single-payload byte ceiling.',
            'default' => '2097152',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES',
        ],
        'DW_V2_LIMIT_MEMO_SIZE_BYTES' => [
            'description' => 'Package-level workflow-memo byte ceiling.',
            'default' => '262144',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES',
        ],
        'DW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES' => [
            'description' => 'Package-level search-attribute byte ceiling.',
            'default' => '40960',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES',
        ],
        'DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE' => [
            'description' => 'Package-level history-transaction event ceiling.',
            'default' => '5000',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_SIZE',
        ],
        'DW_V2_LIMIT_WARNING_THRESHOLD_PERCENT' => [
            'description' => 'Percent of a structural limit at which the package emits an approaching-limit warning.',
            'default' => '80',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_LIMIT_WARNING_THRESHOLD_PERCENT',
        ],
        'DW_V2_TASK_DISPATCH_MODE' => [
            'description' => 'Package-level workflow-task dispatch mode ("queue" or "poll"). Usually overridden by the server via DW_TASK_DISPATCH_MODE; included here for operators who bypass server.php.',
            'default' => 'queue',
            'since' => '2.0.0',
        ],
        'DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS' => [
            'description' => 'Seconds before an orphaned workflow task is redispatched by the repair loop.',
            'default' => '3',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
        ],
        'DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS' => [
            'description' => 'Minimum seconds between successive task-repair passes per queue.',
            'default' => '5',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
        ],
        'DW_V2_TASK_REPAIR_SCAN_LIMIT' => [
            'description' => 'Maximum tasks considered per task-repair pass.',
            'default' => '25',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_TASK_REPAIR_SCAN_LIMIT',
        ],
        'DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS' => [
            'description' => 'Ceiling on task-repair failure backoff in seconds.',
            'default' => '60',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
        ],
        'DW_V2_MULTI_NODE' => [
            'description' => 'Declare the deployment has multiple server nodes so cache backends are validated for cross-node coordination.',
            'default' => 'false',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_MULTI_NODE',
        ],
        'DW_V2_VALIDATE_CACHE_BACKEND' => [
            'description' => 'Whether to validate the long-poll cache backend at boot.',
            'default' => 'true',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_VALIDATE_CACHE_BACKEND',
        ],
        'DW_V2_CACHE_VALIDATION_MODE' => [
            'description' => 'How to handle cache-backend validation failures: "fail", "warn", or "silent".',
            'default' => 'warn',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_V2_CACHE_VALIDATION_MODE',
        ],
        'DW_SERIALIZER' => [
            'description' => 'Payload codec diagnostic input. Final v2 always resolves new-run payloads to "avro"; legacy values are surfaced by workflow:v2:doctor.',
            'default' => 'avro',
            'since' => '2.0.0',
            'legacy' => 'WORKFLOW_SERIALIZER',
        ],

    ],

    /*
    | ----------------------------------------------------------------------
    | Laravel framework env vars
    | ----------------------------------------------------------------------
    |
    | These are not DW_* operator controls but the audit recognizes them so
    | it does not warn about them. This list is intentionally conservative —
    | if you add a new non-DW var, add it here or prefer DW_ instead.
    */

    'framework' => [
        'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'APP_VERSION',
        'APP_LOCALE', 'APP_FALLBACK_LOCALE', 'APP_FAKER_LOCALE', 'APP_MAINTENANCE_DRIVER',
        'APP_MAINTENANCE_STORE', 'APP_PREVIOUS_KEYS', 'APP_CIPHER', 'APP_TIMEZONE',
        'LOG_CHANNEL', 'LOG_LEVEL', 'LOG_DEPRECATIONS_CHANNEL', 'LOG_STACK',
        'LOG_DAILY_DAYS', 'LOG_SLACK_WEBHOOK_URL', 'LOG_PAPERTRAIL_URL',
        'LOG_PAPERTRAIL_PORT',
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        'DB_PASSWORD', 'DB_SOCKET', 'DB_URL', 'DB_FOREIGN_KEYS', 'DB_CHARSET',
        'DB_COLLATION',
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_USERNAME',
        'REDIS_DB', 'REDIS_CACHE_DB', 'REDIS_CLIENT', 'REDIS_URL', 'REDIS_PREFIX',
        'REDIS_CLUSTER', 'REDIS_SCHEME',
        'QUEUE_CONNECTION', 'QUEUE_FAILED_DRIVER',
        'CACHE_STORE', 'CACHE_PREFIX',
        'SESSION_DRIVER', 'SESSION_LIFETIME', 'SESSION_DOMAIN', 'SESSION_ENCRYPT',
        'SESSION_PATH', 'SESSION_CONNECTION', 'SESSION_STORE', 'SESSION_COOKIE',
        'FILESYSTEM_DISK',
        'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
        'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
        'BROADCAST_CONNECTION', 'BROADCAST_DRIVER',
        'MEMCACHED_HOST', 'MEMCACHED_PORT', 'MEMCACHED_USERNAME', 'MEMCACHED_PASSWORD',
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION',
        'AWS_BUCKET', 'AWS_USE_PATH_STYLE_ENDPOINT',
        'PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'PUSHER_HOST',
        'PUSHER_PORT', 'PUSHER_SCHEME', 'PUSHER_APP_CLUSTER',
        'VITE_APP_NAME',
        'BCRYPT_ROUNDS',
        'PHP_CLI_SERVER_WORKERS',
    ],

];
