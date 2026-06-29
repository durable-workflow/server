<?php

use App\Services\PrometheusMetricsSummary;
use App\Support\ActivityRuntimeResultGate;
use App\Support\HistoryRetentionEnforcer;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\ProjectionDriftMetrics;
use App\Support\QueryTaskPollRequestStore;
use App\Support\ServerReadiness;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\TaskQueueAdmission;
use App\Support\WorkerPollClaimGate;
use App\Support\WorkflowQueryTaskBroker;
use App\Support\WorkflowTaskFailureMetrics;
use App\Support\WorkflowTaskPoller;
use App\Support\WorkflowTaskPollRequestStore;

/*
|--------------------------------------------------------------------------
| Durable Workflow Server Bounded-Growth Contract
|--------------------------------------------------------------------------
|
| Server-owned cache and metric surfaces must declare their key dimensions,
| TTL or admission policy, and operator-visible cardinality bounds here.
| Tests diff this policy against app and perf-harness source so new cache
| prefixes and dw_* metric names cannot be added without an explicit growth
| policy.
|
*/

return [

    'polling_scan_limits' => [
        'due_timer_recovery' => [
            'owner' => WorkflowTaskPoller::class,
            'config' => 'server.polling.due_timer_recovery_scan_limit',
            'default' => 5,
            'scope' => 'Per workflow-task worker poll pass for the polled namespace/task_queue/build-id compatibility cohort.',
            'bound' => 'Each poll pass examines at most the configured number of ready due timer tasks before returning to normal task leasing.',
        ],

        'expired_workflow_task_recovery' => [
            'owner' => WorkflowTaskPoller::class,
            'config' => 'server.polling.expired_workflow_task_recovery_scan_limit',
            'default' => 5,
            'scope' => 'Per workflow-task worker poll path for expired workflow-task leases.',
            'bound' => 'Each recovery pass examines at most the configured number of expired workflow task leases and duplicate recovery attempts are TTL-suppressed per task.',
        ],
    ],

    'cache_keys' => [
        'long_poll_signals' => [
            'owner' => LongPollSignalStore::class,
            'prefix' => 'server:long-poll-signal:',
            'dimensions' => [
                'plane',
                'namespace_scope',
                'namespace',
                'connection',
                'task_queue',
                'query_task_id',
                'workflow_run_id',
            ],
            'ttl' => 'server.polling.wake_signal_ttl_seconds when set; otherwise max(server.polling.timeout + 5, 60) seconds.',
            'bound' => 'One expiring key per active wake channel touched during the TTL window. Channels are hashed and never retained in an index.',
            'admission' => 'Writers emit a fixed set of wake channels per task/history/query event; no user-controlled list is stored.',
            'eviction' => 'Cache TTL only. Stale wake keys disappear without a sweeper.',
        ],

        'workflow_task_poll_requests' => [
            'owner' => WorkflowTaskPollRequestStore::class,
            'prefix' => 'server:workflow-task-poll-request:',
            'dimensions' => [
                'kind',
                'namespace',
                'task_queue',
                'build_id',
                'lease_owner',
                'poll_request_id',
            ],
            'ttl' => 'Pending keys live max(server.polling.timeout + 5, 5) seconds. Empty result keys live at most 60 seconds; task result keys live through the active task lease, capped at 3600 seconds.',
            'bound' => 'At most one pending key and one short replay-result key per idempotent worker poll request in the TTL window.',
            'admission' => 'Cache add elects a single poll leader for each idempotent request. Followers wait for the leader result and retry only while the pending marker exists.',
            'eviction' => 'Pending keys are removed when a leader publishes a result; all pending and result keys also expire by TTL.',
        ],

        'activity_task_poll_requests' => [
            'owner' => ActivityTaskPollRequestStore::class,
            'prefix' => 'server:activity-task-poll-request:',
            'dimensions' => [
                'kind',
                'namespace',
                'task_queue',
                'build_id',
                'lease_owner',
                'poll_request_id',
            ],
            'ttl' => 'Pending keys live max(server.polling.timeout + 5, 5) seconds. Empty result keys live at most 60 seconds; task result keys live through the active activity-attempt lease, capped at 3600 seconds.',
            'bound' => 'At most one pending key and one short replay-result key per idempotent activity worker poll request in the TTL window.',
            'admission' => 'Cache add elects a single activity poll leader for each idempotent request. Followers wait for the leader result and retry only while the pending marker exists.',
            'eviction' => 'Pending keys are removed when a leader publishes a result; all pending and result keys also expire by TTL.',
        ],

        'query_task_poll_requests' => [
            'owner' => QueryTaskPollRequestStore::class,
            'prefix' => 'server:query-task-poll-request:',
            'dimensions' => [
                'kind',
                'namespace',
                'task_queue',
                'build_id',
                'lease_owner',
                'poll_request_id',
            ],
            'ttl' => 'Pending and current-marker keys live max(server.polling.timeout + 5, 5) seconds. Empty result keys live at most 60 seconds; task result keys live through the active query-task lease, capped at 3600 seconds.',
            'bound' => 'At most one pending key and one short replay-result key per idempotent query worker poll request in the TTL window, plus one current-marker key per namespace/task_queue/build_id/worker.',
            'admission' => 'Cache add elects a single query poll leader for each idempotent request. Followers wait for the leader result and retry only while the pending marker exists. Newer poll ids supersede older leaders before they can lease query work.',
            'eviction' => 'Pending keys are removed when a leader publishes a result; all pending, current-marker, and result keys also expire by TTL.',
        ],

        'long_poll_wait_slots' => [
            'owner' => LongPollWaitSlotStore::class,
            'prefix' => 'server:long-poll-wait-slot:',
            'dimensions' => [
                'server_id_hash',
                'pool',
                'slot_index',
            ],
            'ttl' => 'server.polling.timeout + 5 seconds, with a runtime minimum of 1 second.',
            'bound' => 'At most server.polling.max_concurrent_waits workflow/activity wait keys per server node when set; otherwise PHP_CLI_SERVER_WORKERS minus server.polling.reserved_http_workers and the derived query-task poll wait budget in the standalone CLI server image. Query-task poll wait keys are capped by server.query_tasks.max_concurrent_poll_waits when set, otherwise up to two derived slots when PHP_CLI_SERVER_WORKERS leaves capacity.',
            'admission' => 'Empty workflow, activity, and query-task worker long-poll waits must acquire a slot before sleeping. Query-task polls use a separate pool so live workflow queries are not starved by idle workflow/activity waits. If all slots for a pool are occupied, guarded worker polls return their immediate probe result so health and control-plane routes keep request-worker capacity. Pending query tasks are claimed before a query-task poll needs an idle wait slot.',
            'eviction' => 'Slots are released when the poll returns; TTL clears stale holders after process death.',
        ],

        'sqlite_worker_poll_claim_gate' => [
            'owner' => WorkerPollClaimGate::class,
            'prefix' => 'server:sqlite-worker-poll-claim:',
            'dimensions' => [
                'singleton_lock',
            ],
            'ttl' => 'server.polling.sqlite_claim_lock_ttl_seconds seconds, default 10 and runtime-minimum 1.',
            'bound' => 'At most one short-lived lock key for the shared SQLite worker poll claim gate.',
            'admission' => 'Only created when the server uses SQLite and the configured polling cache store supports atomic locks; all workflow and activity claim probes share the same gate.',
            'eviction' => 'Cache lock TTL only. The lock key disappears once the holder releases it or the TTL expires.',
        ],

        'workflow_query_tasks' => [
            'owner' => WorkflowQueryTaskBroker::class,
            'prefix' => 'server:workflow-query-task:',
            'dimensions' => [
                'kind',
                'namespace',
                'task_queue',
                'query_task_id',
                'worker_id',
            ],
            'ttl' => 'Task and queue keys live max(60, server.query_tasks.ttl_seconds, server.query_tasks.timeout + effective_query_task_lease_timeout + 60) seconds. effective_query_task_lease_timeout is server.query_tasks.lease_timeout when query timeout is 0; otherwise max(server.query_tasks.lease_timeout, server.query_tasks.timeout + 5). Lease keys live effective_query_task_lease_timeout seconds. Queue locks live 10 seconds. Query-poller markers live the worker poll timeout plus 5 seconds when the worker sends timeout_seconds, otherwise max(server.workers.stale_after_seconds, server.query_tasks.timeout + 5) seconds. The query timeout defaults to max(server.polling.timeout + 15, server.lease.workflow_task_timeout + 5, 40) and is runtime-clamped to 0 or greater.',
            'bound' => 'Pending query tasks are capped per namespace/task_queue by server.query_tasks.max_pending_per_queue, default 1024 and hard-clamped to 10000. Query-poller markers add at most one expiring marker per namespace/task_queue/worker_id that has polled the query-task endpoint during the TTL window.',
            'admission' => 'Queue mutations require an atomic cache lock. Full queues return query_task_queue_full/HTTP 429; stores without locks or lock timeouts return query_task_queue_unavailable/HTTP 503. Query-poller markers are written only when a registered worker polls the query-task endpoint and are not retained in an index.',
            'eviction' => 'Poll and enqueue paths prune stale queue IDs by checking each referenced task. Task, lease, queue, and lock keys expire by TTL. Query-poller markers are overwritten by repeat polls from the same worker and otherwise expire by TTL.',
        ],

        'task_queue_admission_locks' => [
            'owner' => TaskQueueAdmission::class,
            'prefix' => 'server:task-queue-admission:',
            'dimensions' => [
                'namespace_hash',
                'task_queue_hash_or_namespace_or_budget_group_scope',
                'task_kind',
            ],
            'ttl' => 'server.admission.lock_ttl_seconds seconds, default 5.',
            'bound' => 'One short-lived lock key per namespace/task_queue/task_kind with queue-level caps, per namespace/task_kind when namespace-wide caps are configured, or per namespace/budget_group/task_kind when downstream group caps are configured and concurrent poll attempts exist.',
            'admission' => 'Locks are acquired only when workflow or activity active-lease caps or dispatch-per-minute budgets are configured; uncapped queues and namespaces do not create these keys.',
            'eviction' => 'Cache lock TTL only. The durable task rows remain the source of truth for active lease counts.',
        ],

        'task_queue_dispatch_counters' => [
            'owner' => TaskQueueAdmission::class,
            'prefix' => 'server:task-queue-dispatch:',
            'dimensions' => [
                'namespace_hash',
                'task_queue_hash_or_namespace_or_budget_group_scope',
                'task_kind',
                'minute_bucket',
            ],
            'ttl' => '2 minutes.',
            'bound' => 'One short-lived counter per capped namespace/task_queue/task_kind/minute bucket, plus one namespace/task_kind/minute counter for namespace-wide dispatch budgets or one namespace/budget_group/task_kind/minute counter for downstream group budgets, when at least one task is leased.',
            'admission' => 'Counters are created only when workflow or activity dispatch-per-minute budgets are configured and a task is actually leased.',
            'eviction' => 'Counters expire automatically after the two-minute rolling bucket window.',
        ],

        'workflow_task_expired_lease_recovery' => [
            'owner' => WorkflowTaskPoller::class,
            'prefix' => 'server:workflow-task-expired-lease-recovery:',
            'dimensions' => [
                'workflow_task_id',
            ],
            'ttl' => 'server.polling.expired_workflow_task_recovery_ttl_seconds seconds, with a runtime minimum of 1 second.',
            'bound' => 'Recovery scans examine at most server.polling.expired_workflow_task_recovery_scan_limit tasks per poll path, default 5.',
            'admission' => 'Cache add suppresses duplicate recovery attempts for the same expired workflow task during the TTL window.',
            'eviction' => 'Cache TTL only. The durable task row remains the source of truth.',
        ],

        'history_retention_inline' => [
            'owner' => HistoryRetentionEnforcer::class,
            'prefix' => 'server:history-retention-inline:',
            'dimensions' => [
                'namespace_hash',
            ],
            'ttl' => '60 seconds.',
            'bound' => 'One short-lived throttle key per namespace receiving worker heartbeats during the TTL window.',
            'admission' => 'Cache add elects at most one worker heartbeat per namespace per minute to run a one-run retention pass.',
            'eviction' => 'Cache TTL only. Expired run discovery stays in SQL and no cache index is retained.',
        ],

        'readiness_probe' => [
            'owner' => ServerReadiness::class,
            'prefix' => 'server:readiness:',
            'dimensions' => [
                'random_probe_id',
            ],
            'ttl' => '10 seconds.',
            'bound' => 'One temporary key per /api/ready cache check; keys use random probe IDs and are not indexed.',
            'admission' => 'Readiness writes only during a probe request.',
            'eviction' => 'Probe key is deleted immediately after the round-trip check and also has a 10-second TTL.',
        ],
    ],

    'metrics' => [
        'dw_workflow_task_consecutive_failures' => [
            'owner' => WorkflowTaskFailureMetrics::class,
            'surface' => 'GET /api/system/metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'workflow_type' => 'bounded_series',
            ],
            'cardinality' => 'workflow_type series are limited by server.metrics.workflow_task_failure_type_limit, default 20 and hard-clamped to 100.',
            'selection' => 'top_by_max_consecutive_failures_then_name',
            'suppression' => 'Suppressed workflow type and failed-task counts are returned with the metric payload.',
        ],

        'dw_projection_drift_total' => [
            'owner' => ProjectionDriftMetrics::class,
            'surface' => 'GET /api/system/metrics',
            'dimensions' => [
                'namespace' => 'server_scope_no_label',
                'table' => 'finite_projection_table_inventory',
            ],
            'cardinality' => 'table series are fixed to the server projection inventory: run_summaries, run_waits, run_timeline_entries, run_timer_entries, and run_lineage_entries.',
            'selection' => 'all projection tables in the fixed inventory.',
            'suppression' => 'No suppression path is needed because the table inventory is finite.',
        ],

        'dw_workflow_runs_total' => [
            'owner' => PrometheusMetricsSummary::class,
            'surface' => 'GET /api/system/prometheus-metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'task_queue' => 'bounded_series',
                'workflow_type' => 'bounded_series',
            ],
            'cardinality' => 'Workflow series keyed by task_queue/workflow_type are capped by server.metrics.prometheus_workflow_series_limit, default 100 and hard-clamped to 500; scrape-time discovery reads at most limit + 1 label sets.',
            'selection' => 'bounded_task_queue_and_workflow_type_ascending',
            'suppression' => 'The endpoint reports observed, reported, truncated, suppressed series, and suppressed started totals under cardinality.series_limits.workflows; counts are exact until the cap is exceeded, then disclosed as lower bounds.',
        ],

        'dw_workflow_run_latency_seconds' => [
            'owner' => PrometheusMetricsSummary::class,
            'surface' => 'GET /api/system/prometheus-metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'task_queue' => 'bounded_series',
                'workflow_type' => 'bounded_series',
            ],
            'cardinality' => 'Workflow latency series share the same bounded task_queue/workflow_type series cap as dw_workflow_runs_total.',
            'selection' => 'bounded_task_queue_and_workflow_type_ascending',
            'suppression' => 'Suppression is disclosed once for the shared workflow series set under cardinality.series_limits.workflows.',
        ],

        'dw_activity_executions_total' => [
            'owner' => PrometheusMetricsSummary::class,
            'surface' => 'GET /api/system/prometheus-metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'task_queue' => 'bounded_series',
                'workflow_type' => 'bounded_series',
                'activity_type' => 'bounded_series',
            ],
            'cardinality' => 'Activity series keyed by task_queue/workflow_type/activity_type are capped by server.metrics.prometheus_activity_series_limit, default 100 and hard-clamped to 500; scrape-time discovery reads at most limit + 1 label sets.',
            'selection' => 'bounded_task_queue_workflow_type_and_activity_type_ascending',
            'suppression' => 'The endpoint reports observed, reported, truncated, suppressed series, and suppressed started totals under cardinality.series_limits.activities; counts are exact until the cap is exceeded, then disclosed as lower bounds.',
        ],

        'dw_activity_execution_latency_seconds' => [
            'owner' => PrometheusMetricsSummary::class,
            'surface' => 'GET /api/system/prometheus-metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'task_queue' => 'bounded_series',
                'workflow_type' => 'bounded_series',
                'activity_type' => 'bounded_series',
            ],
            'cardinality' => 'Activity latency series share the same bounded task_queue/workflow_type/activity_type series cap as dw_activity_executions_total.',
            'selection' => 'bounded_task_queue_workflow_type_and_activity_type_ascending',
            'suppression' => 'Suppression is disclosed once for the shared activity series set under cardinality.series_limits.activities.',
        ],

        'dw_task_queue_runtime_state' => [
            'owner' => PrometheusMetricsSummary::class,
            'surface' => 'GET /api/system/prometheus-metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'task_queue' => 'bounded_series',
            ],
            'cardinality' => 'Task-queue runtime series keyed by task_queue are capped by server.metrics.prometheus_task_queue_series_limit, default 100 and hard-clamped to 500; scrape-time discovery reads at most limit + 1 queue label sets and aggregation scans only active or last-minute task rows for reported queues.',
            'selection' => 'task_queue_name_ascending',
            'suppression' => 'The endpoint reports observed, reported, truncated, and suppressed queue series under cardinality.series_limits.task_queues; counts are exact until the cap is exceeded, then disclosed as lower bounds.',
        ],

        'dw_server_image' => [
            'owner' => ActivityRuntimeResultGate::class,
            'surface' => 'Activities conformance published_artifact_worker_execution evidence.',
            'dimensions' => [],
            'cardinality' => 'single evidence field per published server artifact execution entry.',
            'selection' => 'pinned published server artifact execution source accepted by the activities conformance result gate.',
            'suppression' => 'No labels are exposed; the value remains scoped to the conformance evidence payload.',
        ],

        'dw_perf_requests_total' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [
                'status' => 'bounded_http_status_code',
            ],
            'cardinality' => 'status is produced from HTTP response codes and load-generator exception buckets; observed series are bounded to the finite status-code set.',
            'selection' => 'all observed status buckets for the current soak run.',
            'suppression' => 'No suppression path is needed because status-code cardinality is finite.',
        ],

        'dw_perf_errors_total' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single counter series per soak run.',
            'selection' => 'current run aggregate.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_latency_seconds_average' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'current run aggregate.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_server_memory_bytes' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'latest sampled server container memory.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_redis_memory_bytes' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'latest sampled Redis used_memory value.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_redis_polling_keys' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'latest sampled Redis keys matching the polling-cache pattern.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_redis_server_keys' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'latest sampled Redis keys matching the server-owned cache namespace pattern.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_redis_server_keys_by_policy' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [
                'policy' => 'finite_cache_policy_inventory',
            ],
            'cardinality' => 'policy series are fixed to the cache_keys inventory in this bounded-growth policy file.',
            'selection' => 'latest sampled Redis keys for each declared server-owned cache policy.',
            'suppression' => 'No suppression path is needed because the cache policy inventory is finite and reviewed.',
        ],

        'dw_perf_redis_db_keys' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'latest sampled Redis DBSIZE value.',
            'suppression' => 'No labels are exposed.',
        ],

        'dw_perf_assertion_failed' => [
            'owner' => 'scripts/perf/server_soak.py',
            'surface' => 'Perf harness /metrics scrape; optional remote_write.',
            'dimensions' => [],
            'cardinality' => 'single gauge series per soak run.',
            'selection' => 'current run assertion state.',
            'suppression' => 'No labels are exposed.',
        ],
    ],
];
