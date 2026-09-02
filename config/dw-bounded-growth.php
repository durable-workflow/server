<?php

use App\Services\PrometheusMetricsSummary;
use App\Support\ActivityRuntimeResultGate;
use App\Support\ActivityTaskPollRequestStore;
use App\Support\HistoryRetentionEnforcer;
use App\Support\LongPollSignalStore;
use App\Support\LongPollWaitSlotStore;
use App\Support\NamespaceDurableStateQuota;
use App\Support\NamespaceRequestAdmission;
use App\Support\ProjectionDriftMetrics;
use App\Support\QueryTaskPollRequestStore;
use App\Support\RuntimeExternalPayloadQuota;
use App\Support\ServerPollingCache;
use App\Support\ServerReadiness;
use App\Support\SharedServiceBoundaryPolicy;
use App\Support\TaskQueueAdmission;
use App\Support\WorkerCompatibilityHeartbeatRecorder;
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
        'polling_cache_availability_probe' => [
            'owner' => ServerPollingCache::class,
            'prefix' => 'server:polling-cache:',
            'dimensions' => [
                'probe_kind',
            ],
            'ttl' => 'No value is written or retained; the fixed availability-probe key is read only.',
            'bound' => 'One fixed key address is probed for the configured shared polling cache.',
            'admission' => 'The probe key is a server-owned literal with no user-controlled dimensions.',
            'eviction' => 'Not applicable because the availability probe does not write a cache value.',
        ],

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
            'ttl' => 'Task-kind binding keys live at most 3600 seconds. Pending keys live max(server.polling.timeout + 5, 5) seconds. Empty result keys live at most 60 seconds; task result keys live through the active task lease, capped at 3600 seconds.',
            'bound' => 'At most one normalized task-kind binding, one pending key, and one short replay-result key per idempotent worker poll request in the TTL window.',
            'admission' => 'Cache add first binds the poll request id to its normalized task-kind set, then elects a single poll leader for that idempotent request. The claimed task payload also stores that request id, so a lost response can be rebuilt outside admission without replaying the lease into another poll slot.',
            'eviction' => 'Pending keys are removed when a leader publishes a result; all binding, pending, and result keys also expire by TTL.',
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
            'admission' => 'Cache add elects a single activity poll leader for each idempotent request. The claimed task payload also stores that request id, so a lost response can be rebuilt outside admission without replaying the activity attempt into another poll slot.',
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
            'admission' => 'Cache add elects a single query poll leader for each idempotent request. Leased query-task records retain the claiming request id; concurrent poll ids cannot replay that attempt and newer poll ids supersede older leaders before they can lease query work.',
            'eviction' => 'Pending keys are removed when a leader publishes a result; all pending, current-marker, and result keys also expire by TTL.',
        ],

        'long_poll_wait_slots' => [
            'owner' => LongPollWaitSlotStore::class,
            'prefix' => 'server:long-poll-wait-slot:',
            'dimensions' => [
                'server_id_hash',
                'pool',
                'optional_namespace_hash',
                'slot_index',
            ],
            'ttl' => 'server.polling.timeout + 5 seconds, with a runtime minimum of 1 second.',
            'bound' => 'At most server.polling.max_concurrent_waits workflow/activity wait keys per server node when set; otherwise PHP_CLI_SERVER_WORKERS minus the configured or derived HTTP and query-task reserves. Optional per-namespace worker and query-task caps add at most one namespace key for each held global slot, and each effective namespace cap is clamped to its global pool cap.',
            'admission' => 'Empty workflow, activity, and query-task worker long-poll waits must acquire a global slot before sleeping and, when configured, a namespace slot. Namespace caps prevent one namespace from occupying the whole pool and fail closed if shared cache authority is unavailable. Query-task polls use a separate pool, and pending tasks are claimed before a poll needs an idle wait slot.',
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
            'ttl' => 'Task, queue, and leased-task index keys live max(60, server.query_tasks.ttl_seconds, server.query_tasks.timeout + effective_query_task_lease_timeout + 60) seconds. effective_query_task_lease_timeout is server.query_tasks.lease_timeout when query timeout is 0; otherwise max(server.query_tasks.lease_timeout, server.query_tasks.timeout + 5). Lease keys live effective_query_task_lease_timeout seconds. Queue locks live 10 seconds. Query-poller markers live the worker poll timeout plus 5 seconds when the worker sends timeout_seconds, otherwise max(server.workers.stale_after_seconds, server.query_tasks.timeout + 5) seconds. Each held query-task poll wait is capped by server.query_tasks.poll_timeout before workers re-poll. The query timeout defaults to min(max(server.polling.timeout + 15, 40), 55) so failures remain inside the standard client transport deadline, and is runtime-clamped to 0 or greater.',
            'bound' => 'Pending query tasks are capped per namespace/task_queue by server.query_tasks.max_pending_per_queue, default 1024 and hard-clamped to 10000. Query-poller markers add at most one expiring marker per namespace/task_queue/worker_id that has polled the query-task endpoint during the TTL window. Same-worker leased-task indexes add at most one expiring list key per namespace/task_queue/lease_owner, and stale IDs are pruned by normal reads before the key TTL expires.',
            'admission' => 'Queue mutations require an atomic cache lock. Full queues return query_task_queue_full/HTTP 429; stores without locks or lock timeouts return query_task_queue_unavailable/HTTP 503. Query-poller markers are written only when a registered worker polls the query-task endpoint and are not retained in an index. Leased-task indexes are written only after an accepted worker poll leases an existing pending query task.',
            'eviction' => 'Poll and enqueue paths prune stale queue IDs by checking each referenced task. Query completion, failure, and timeout paths remove IDs from the leased-task index; repeat same-worker polls prune expired or non-leased IDs. Task, lease, queue, leased-index, and lock keys expire by TTL. Query-poller markers are overwritten by repeat polls from the same worker and otherwise expire by TTL.',
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

        'namespace_request_admission' => [
            'owner' => NamespaceRequestAdmission::class,
            'prefix' => 'server:namespace-request-admission:',
            'dimensions' => [
                'key_kind',
                'namespace_hash',
                'minute_bucket_or_slot_index',
                'rejection_reason',
            ],
            'ttl' => 'Rate and rejection counters live 2 minutes. Admission locks use server.namespace_admission.lock_ttl_seconds, default 5 seconds. Concurrent request slots use server.namespace_admission.request_lease_ttl_seconds, default 120 seconds and hard-clamped to 3600 seconds.',
            'bound' => 'Each configured namespace retains at most one rate counter and lock per active minute bucket, at most its effective concurrent-request limit in fixed slot keys, and one counter plus one log-suppression key per fixed rejection reason and active minute bucket.',
            'admission' => 'Keys are created only for namespaces with configured request limits or when recording one of the three fixed admission rejection reasons. Namespace identifiers are hashed and no namespace index is retained.',
            'eviction' => 'Request slots are released when requests finish; all locks, counters, slots, and log-suppression keys also expire by TTL after process loss.',
        ],

        'shared_service_boundary_admission' => [
            'owner' => SharedServiceBoundaryPolicy::class,
            'prefix' => 'server:service-boundary:',
            'dimensions' => [
                'key_kind',
                'caller_namespace_hash',
                'service_boundary_hash',
                'minute_bucket',
            ],
            'ttl' => 'Rate counters live 2 minutes. Admission locks live 5 seconds. In-flight counters live server.service_boundary.shared_admission.concurrency_lease_ttl_seconds seconds, default 86400 and hard-clamped to 604800 seconds.',
            'bound' => 'Configured caller-namespace budgets retain one namespace rate key per active minute and one namespace in-flight key. Configured operation budgets retain one rate key per invoked service boundary and active minute plus one in-flight key per invoked boundary. All user-derived dimensions are hashed and no index is retained.',
            'admission' => 'No keys are written when all service-boundary admission limits are unset. Configured budgets require shared cache with atomic locks and fail closed when that authority is unavailable. Hard ceilings cap operation policies and caller-namespace overrides.',
            'eviction' => 'Handler completion releases its object-scoped reservation idempotently. Process loss and unfinished asynchronous calls are bounded by the finite concurrency lease TTL; rate counters and locks expire automatically.',
        ],

        'namespace_durable_state_quota_rejections' => [
            'owner' => NamespaceDurableStateQuota::class,
            'prefix' => 'server:namespace-durable-state:',
            'dimensions' => [
                'key_kind',
                'namespace_hash',
                'minute_bucket',
                'rejection_reason',
            ],
            'ttl' => '2 minutes.',
            'bound' => 'At most one counter and one log-suppression key per namespace and fixed durable-state rejection reason in each active minute bucket.',
            'admission' => 'Counters are best-effort telemetry written only after database-authoritative durable row exhaustion or quota-availability rejection.',
            'eviction' => 'Cache TTL only. Durable database rows remain the quota source of truth.',
        ],

        'runtime_external_payload_quota_rejections' => [
            'owner' => RuntimeExternalPayloadQuota::class,
            'prefix' => 'server:external-payload-quota:',
            'dimensions' => [
                'key_kind',
                'namespace_hash',
                'minute_bucket',
                'rejection_reason',
            ],
            'ttl' => '2 minutes.',
            'bound' => 'At most one counter and one log-suppression key per namespace and fixed quota rejection reason in each active minute bucket.',
            'admission' => 'Counters are best-effort telemetry written only after database-authoritative namespace byte, object-count, or quota-availability rejection.',
            'eviction' => 'Cache TTL only. Durable payload rows remain the quota source of truth.',
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

        'worker_compatibility_heartbeat' => [
            'owner' => WorkerCompatibilityHeartbeatRecorder::class,
            'prefix' => 'server:worker-compatibility-heartbeat:',
            'dimensions' => [
                'namespace_hash',
                'worker_id_hash',
            ],
            'ttl' => 'One third of workflows.v2.compatibility.heartbeat_ttl_seconds, with a runtime minimum of 1 second.',
            'bound' => 'At most one expiring throttle key per namespace/worker that registered or heartbeated during the write interval.',
            'admission' => 'Registration seeds the key and heartbeat cache-add elects one request process to refresh compatibility fleet visibility during each write interval.',
            'eviction' => 'Cache TTL only. No index is retained, so all keys drain to zero after recently heartbeating workers become idle.',
            'ttl_config' => 'workflows.v2.compatibility.heartbeat_ttl_seconds',
            'ttl_divisor' => 3,
            'active_keys_per_scope' => 1,
            'drains_to_zero' => true,
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

        NamespaceRequestAdmission::METRIC_NAME => [
            'owner' => NamespaceRequestAdmission::class,
            'surface' => 'GET /api/system/metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'reason' => 'finite_three_reason_inventory',
            ],
            'cardinality' => 'The request namespace is the query scope rather than a label; rejection reasons are fixed to rate exhausted, concurrency exhausted, and admission unavailable.',
            'selection' => 'current minute for the requested namespace.',
            'suppression' => 'No suppression is needed because each response contains exactly three fixed reason counters.',
        ],

        NamespaceDurableStateQuota::METRIC_NAME => [
            'owner' => NamespaceDurableStateQuota::class,
            'surface' => 'GET /api/system/metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'reason' => 'finite_reason_inventory',
            ],
            'cardinality' => 'The request namespace is the query scope rather than a label; usage and rejection fields come from the fixed durable resource inventory plus quota unavailability.',
            'selection' => 'Current durable row usage and current-minute rejection counters for the requested namespace.',
            'suppression' => 'No suppression is needed because each response contains a fixed resource and reason inventory.',
        ],

        RuntimeExternalPayloadQuota::METRIC_NAME => [
            'owner' => RuntimeExternalPayloadQuota::class,
            'surface' => 'GET /api/system/metrics',
            'dimensions' => [
                'namespace' => 'request_scope_not_label',
                'reason' => 'finite_three_reason_inventory',
            ],
            'cardinality' => 'The request namespace is the query scope rather than a label; rejection reasons are fixed to byte exhaustion, object exhaustion, and quota unavailability.',
            'selection' => 'Current durable usage and current-minute rejection counters for the requested namespace.',
            'suppression' => 'No suppression is needed because each response contains exactly three fixed reason counters.',
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
