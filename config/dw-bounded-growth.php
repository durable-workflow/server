<?php

use App\Support\LongPollSignalStore;
use App\Support\ProjectionDriftMetrics;
use App\Support\ServerReadiness;
use App\Support\TaskQueueAdmission;
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

        'workflow_query_tasks' => [
            'owner' => WorkflowQueryTaskBroker::class,
            'prefix' => 'server:workflow-query-task:',
            'dimensions' => [
                'kind',
                'namespace',
                'task_queue',
                'query_task_id',
            ],
            'ttl' => 'Task and queue keys live max(60, server.query_tasks.timeout + server.query_tasks.lease_timeout + 60) seconds. Lease keys live server.query_tasks.lease_timeout seconds. Queue locks live 10 seconds.',
            'bound' => 'Pending query tasks are capped per namespace/task_queue by server.query_tasks.max_pending_per_queue, default 1024 and hard-clamped to 10000.',
            'admission' => 'Queue mutations require an atomic cache lock. Full queues return query_task_queue_full/HTTP 429; stores without locks or lock timeouts return query_task_queue_unavailable/HTTP 503.',
            'eviction' => 'Poll and enqueue paths prune stale queue IDs by checking each referenced task. Task, lease, queue, and lock keys expire by TTL.',
        ],

        'task_queue_admission_locks' => [
            'owner' => TaskQueueAdmission::class,
            'prefix' => 'server:task-queue-admission:',
            'dimensions' => [
                'namespace_hash',
                'task_queue_hash',
                'task_kind',
            ],
            'ttl' => 'server.admission.lock_ttl_seconds seconds, default 5.',
            'bound' => 'One short-lived lock key per namespace/task_queue/task_kind that has an active server-side lease cap or dispatch-per-minute budget and concurrent poll attempts.',
            'admission' => 'Locks are acquired only when workflow or activity active-lease caps or dispatch-per-minute budgets are configured; uncapped queues do not create these keys.',
            'eviction' => 'Cache lock TTL only. The durable task rows remain the source of truth for active lease counts.',
        ],

        'task_queue_dispatch_counters' => [
            'owner' => TaskQueueAdmission::class,
            'prefix' => 'server:task-queue-dispatch:',
            'dimensions' => [
                'namespace_hash',
                'task_queue_hash',
                'task_kind',
                'minute_bucket',
            ],
            'ttl' => '2 minutes.',
            'bound' => 'One short-lived counter per capped namespace/task_queue/task_kind/minute bucket that has dispatched at least one task.',
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
