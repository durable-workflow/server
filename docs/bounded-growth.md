# Server Bounded-Growth Policy

The server owns a few cache-backed coordination surfaces, one JSON metric
surface, and the perf harness metrics that can be remote-written during soaks.
Each surface must declare a bounded-growth policy in
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
- Prometheus label names emitted by the perf harness must exactly match the
  declared metric dimensions, so adding a label requires a reviewed cardinality
  policy in the same change.
- Remote-write scrape labels must stay deployment-scoped. Per-run values such
  as `GITHUB_RUN_ID` and `RUNNER_NAME` belong in `summary.json` provenance, not
  in Prometheus labels that create new series for every soak.

## Cache Inventory

| Policy ID | Prefix | Owner | Growth Bound |
| --- | --- | --- | --- |
| `long_poll_signals` | `server:long-poll-signal:` | `App\Support\LongPollSignalStore` | One expiring key per wake channel touched during the TTL window; no retained index. |
| `workflow_task_poll_requests` | `server:workflow-task-poll-request:` | `App\Support\WorkflowTaskPollRequestStore` | One pending key and one short replay-result key per idempotent worker poll request. |
| `workflow_query_tasks` | `server:workflow-query-task:` | `App\Support\WorkflowQueryTaskBroker` | Pending query tasks are capped per `(namespace, task_queue)` by `server.query_tasks.max_pending_per_queue`, default 1024 and hard-clamped to 10000. |
| `task_queue_admission_locks` | `server:task-queue-admission:` | `App\Support\TaskQueueAdmission` | One short-lived lock key per capped `(namespace, task_queue, task_kind)` under concurrent workflow/activity poll admission. |
| `task_queue_dispatch_counters` | `server:task-queue-dispatch:` | `App\Support\TaskQueueAdmission` | One expiring counter per capped `(namespace, task_queue, task_kind, minute)` bucket that actually dispatches work. |
| `workflow_task_expired_lease_recovery` | `server:workflow-task-expired-lease-recovery:` | `App\Support\WorkflowTaskPoller` | Expired-task recovery scans are capped by `server.polling.expired_workflow_task_recovery_scan_limit`, default 5, and duplicate recovery attempts are TTL-suppressed per task. |
| `readiness_probe` | `server:readiness:` | `App\Support\ServerReadiness` | One temporary probe key per readiness check; deleted immediately and also protected by a 10-second TTL. |

## Metric Inventory

| Metric | Surface | Label Policy |
| --- | --- | --- |
| `dw_workflow_task_consecutive_failures` | `GET /api/system/metrics` | `namespace` is request-scoped rather than a label. `workflow_type` series are limited by `server.metrics.workflow_task_failure_type_limit`, default 20 and hard-clamped to 100; suppressed type/task counts are reported in the payload. |
| `dw_projection_drift_total` | `GET /api/system/metrics` | `namespace` is server-scoped rather than a label. `table` is fixed to the finite projection inventory: `run_summaries`, `run_waits`, `run_timeline_entries`, `run_timer_entries`, and `run_lineage_entries`. Alert on non-zero `needs_rebuild` per table. |
| `dw_perf_requests_total` | Perf harness `/metrics`; optional remote_write | The only label is `status`, produced from HTTP response codes and load-generator exception buckets, so the series set is finite. |
| `dw_perf_errors_total` | Perf harness `/metrics`; optional remote_write | No labels; single counter series per soak run. |
| `dw_perf_latency_seconds_average` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_server_memory_bytes` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_redis_memory_bytes` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_redis_polling_keys` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_redis_server_keys` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. Counts all Redis keys in the server-owned `server:*` cache namespace, not only the workflow-task polling subset. |
| `dw_perf_redis_db_keys` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_assertion_failed` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |

## Enforcement

`tests/Unit/BoundedGrowthPolicyTest.php` checks the policy against source:

- every `server:*` cache key prefix literal in `app/` must be covered by a
  `cache_keys` entry;
- every `dw_*` metric name literal in `app/` and `scripts/perf/` must be
  covered by a `metrics` entry;
- perf-harness Prometheus labels must exactly match the corresponding metric
  dimensions declared in the policy;
- perf-harness remote-write target labels must not include per-run or
  per-runner dimensions;
- each policy entry must include the required review fields;
- policy owners must resolve to an autoloadable class or repo-relative file;
- this document must mention every declared policy ID, cache prefix, and metric.

This is not a replacement for long-running soak evidence. It is the repository
gate that keeps future cache and metric additions reviewable before they can
become an operator memory or cardinality problem.

## Soak Evidence

The perf harness writes `summary.json`, `samples.jsonl`, `metrics.prom`, and
service logs under `build/perf/`. A trusted bounded-growth run must include:

- enough periodic samples to cover at least `DW_PERF_MIN_SAMPLE_COVERAGE`
  (default 80%) of the configured duration/sample-interval window;
- the maximum server memory, Redis key counts, server-owned `server:*` cache
  key counts, per-policy server cache key counts, final drain counts, and, for
  runs of at least 10 minutes, the post-warmup memory slope when a slope limit
  is configured;
- GitHub/runner provenance in `summary.json` (`GITHUB_SHA`, `GITHUB_RUN_ID`,
  runner name/OS/arch, Compose project, and the tested base URL when present);
- the SHA-256 digest of `config/dw-bounded-growth.php` so the artifact can be
  tied back to the policy that was active for the run.

If sample coverage falls below the trusted minimum, the harness marks the run
failed instead of uploading an incomplete artifact as passing evidence.

`summary.json` includes both aggregate server cache keys and
`max_server_cache_keys_by_policy` / `final_server_cache_keys_by_policy`. Those
per-policy maps mirror the `cache_keys` inventory so a long soak can show which
bounded cache family produced growth instead of only reporting a total
`server:*` count.
