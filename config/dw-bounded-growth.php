<?php

use App\Support\LongPollSignalStore;
use App\Support\ServerReadiness;
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
| Tests diff this policy against app source so new cache prefixes and dw_*
| metric names cannot be added without an explicit growth policy.
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
    ],
];
