# Server Bounded-Growth Policy

The server owns a few cache-backed coordination surfaces and one JSON metric
surface. Each surface must declare a bounded-growth policy in
`config/dw-bounded-growth.php` before it ships. The policy is intentionally
machine-readable so tests can fail when new cache prefixes or `dw_*` metrics are
added without a TTL, admission, or cardinality contract.

## Review Rules

- Cache keys must have an owner, prefix, key dimensions, TTL, admission policy,
  bound, and eviction behavior.
- User-controlled dimensions such as `namespace`, `task_queue`,
  `workflow_type`, `worker_id`, or request IDs must either be capped or expire
  quickly enough that churn cannot grow without bound.
- Queue or list keys that retain user-controlled IDs need an admission limit or
  a pruning path that executes on normal reads/writes.
- Metrics must avoid unbounded label sets. Request-scoped values should stay in
  the request envelope, not become labels, unless a hard series limit and
  suppression counters are documented.
- New Prometheus or scrape-style surfaces must use the same policy file before
  exposing labels.

## Cache Inventory

| Policy ID | Prefix | Owner | Growth Bound |
| --- | --- | --- | --- |
| `long_poll_signals` | `server:long-poll-signal:` | `App\Support\LongPollSignalStore` | One expiring key per wake channel touched during the TTL window; no retained index. |
| `workflow_task_poll_requests` | `server:workflow-task-poll-request:` | `App\Support\WorkflowTaskPollRequestStore` | One pending key and one short replay-result key per idempotent worker poll request. |
| `workflow_query_tasks` | `server:workflow-query-task:` | `App\Support\WorkflowQueryTaskBroker` | Pending query tasks are capped per `(namespace, task_queue)` by `server.query_tasks.max_pending_per_queue`, default 1024 and hard-clamped to 10000. |
| `task_queue_admission_locks` | `server:task-queue-admission:` | `App\Support\TaskQueueAdmission` | One short-lived lock key per capped `(namespace, task_queue, task_kind)` under concurrent workflow/activity poll admission. |
| `workflow_task_expired_lease_recovery` | `server:workflow-task-expired-lease-recovery:` | `App\Support\WorkflowTaskPoller` | Expired-task recovery scans are capped by `server.polling.expired_workflow_task_recovery_scan_limit`, default 5, and duplicate recovery attempts are TTL-suppressed per task. |
| `readiness_probe` | `server:readiness:` | `App\Support\ServerReadiness` | One temporary probe key per readiness check; deleted immediately and also protected by a 10-second TTL. |

## Metric Inventory

| Metric | Surface | Label Policy |
| --- | --- | --- |
| `dw_workflow_task_consecutive_failures` | `GET /api/system/metrics` | `namespace` is request-scoped rather than a label. `workflow_type` series are limited by `server.metrics.workflow_task_failure_type_limit`, default 20 and hard-clamped to 100; suppressed type/task counts are reported in the payload. |

## Enforcement

`tests/Unit/BoundedGrowthPolicyTest.php` checks the policy against source:

- every `server:*` cache key prefix literal in `app/` must be covered by a
  `cache_keys` entry;
- every `dw_*` metric name literal in `app/` must be covered by a `metrics`
  entry;
- each policy entry must include the required review fields;
- this document must mention every declared policy ID, cache prefix, and metric.

This is not a replacement for long-running soak evidence. It is the repository
gate that keeps future cache and metric additions reviewable before they can
become an operator memory or cardinality problem.
