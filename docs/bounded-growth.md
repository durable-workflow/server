# Server Bounded-Growth Policy

The server owns a few cache-backed coordination surfaces, SQL recovery scans,
JSON metric surfaces, conformance evidence fields, and the perf harness metrics
that can be remote-written during soaks.
Each surface must declare a bounded-growth policy in
`config/dw-bounded-growth.php` before it ships. The policy is intentionally
machine-readable so tests can fail when new cache prefixes or `dw_*` metrics are
added without a TTL, admission, scan, or cardinality contract.

## Review Rules

- Cache keys must have an owner, prefix, key dimensions, TTL, admission policy,
  bound, and eviction behavior.
- User-controlled dimensions such as `namespace`, `task_queue`,
  `workflow_type`, `worker_id`, or request IDs must either be capped or expire
  quickly enough that churn cannot grow without bound.
- Queue or list keys that retain user-controlled IDs need an admission limit or
  a pruning path that executes on normal reads/writes.
- SQL recovery scans on the worker-poll path must declare a configurable scan
  limit so an old backlog cannot create unbounded poll latency.
- Metrics must avoid unbounded label sets. Request-scoped values should stay in
  the request envelope, not become labels, unless a hard series limit and
  suppression counters are documented.
- New Prometheus or scrape-style surfaces must use the same policy file before
  exposing labels.
- Prometheus label names emitted by app code or the perf harness must exactly
  match the declared metric dimensions, so adding a label requires a reviewed
  cardinality policy in the same change.
- Runtime metric cardinality disclosures must fail closed: a metric cannot
  expose a label-set policy unless the metric and every disclosed dimension are
  declared in `config/dw-bounded-growth.php`.
- Every declared metric dimension must use one of the reviewed cardinality
  taxonomy classes (`bounded_*`, `finite_*`, `request_scope_*`, or `*_no_label`)
  so a future scrape or export surface cannot introduce a user-controlled label
  with an ad hoc cardinality string. The dimension name does not have to appear
  on the explicit user-controlled list to be gated.
- Remote-write scrape labels must stay deployment-scoped. Per-run values such
  as `GITHUB_RUN_ID` and `RUNNER_NAME` belong in `summary.json` provenance, not
  in Prometheus labels that create new series for every soak.

## Cache Inventory

| Policy ID | Prefix | Owner | Growth Bound |
| --- | --- | --- | --- |
| `polling_cache_availability_probe` | `server:polling-cache:` | `App\Support\ServerPollingCache` | One fixed, read-only key address probes shared polling-cache availability; the probe does not write or retain a value. |
| `long_poll_signals` | `server:long-poll-signal:` | `App\Support\LongPollSignalStore` | One expiring key per wake channel touched during the TTL window; no retained index. |
| `workflow_task_poll_requests` | `server:workflow-task-poll-request:` | `App\Support\WorkflowTaskPollRequestStore` | One pending key and one short replay-result key per idempotent worker poll request. |
| `activity_task_poll_requests` | `server:activity-task-poll-request:` | `App\Support\ActivityTaskPollRequestStore` | One pending key and one short replay-result key per idempotent activity worker poll request. |
| `query_task_poll_requests` | `server:query-task-poll-request:` | `App\Support\QueryTaskPollRequestStore` | One pending key and one short replay-result key per idempotent query worker poll request, plus one expiring current-marker key per worker/queue scope. |
| `long_poll_wait_slots` | `server:long-poll-wait-slot:` | `App\Support\LongPollWaitSlotStore` | One global held-wait slot per admitted empty worker poll plus one namespace slot when namespace isolation is configured; both are bounded by the per-node pool cap. |
| `sqlite_worker_poll_claim_gate` | `server:sqlite-worker-poll-claim:` | `App\Support\WorkerPollClaimGate` | One short-lived singleton lock key guards SQLite worker poll claim probes when the polling cache store supports locks. |
| `workflow_query_tasks` | `server:workflow-query-task:` | `App\Support\WorkflowQueryTaskBroker` | Pending query tasks are capped per `(namespace, task_queue)` by `server.query_tasks.max_pending_per_queue`, default 1024 and hard-clamped to 10000. Query-poller markers add at most one expiring key per `(namespace, task_queue, worker_id)` that has polled the query-task endpoint during the marker TTL window. Same-worker leased-task indexes add at most one expiring list key per `(namespace, task_queue, lease_owner)`; an active lease is replayed only to its original poll request. |
| `task_queue_admission_locks` | `server:task-queue-admission:` | `App\Support\TaskQueueAdmission` | One short-lived lock key per capped `(namespace, task_queue, task_kind)` under concurrent workflow/activity poll admission. |
| `runtime_external_payload_quota_rejections` | `server:external-payload-quota:` | `App\Support\RuntimeExternalPayloadQuota` | One two-minute counter and one log-suppression key per namespace and fixed quota rejection reason in an active minute bucket; SQL payload rows remain the quota authority. |
| `task_queue_dispatch_counters` | `server:task-queue-dispatch:` | `App\Support\TaskQueueAdmission` | One expiring counter per capped `(namespace, task_queue, task_kind, minute)` bucket that actually dispatches work. |
| `namespace_request_admission` | `server:namespace-request-admission:` | `App\Support\NamespaceRequestAdmission` | Per configured namespace, fixed-TTL rate/rejection buckets and no more concurrent slot keys than the effective request limit. |
| `shared_service_boundary_admission` | `server:service-boundary:` | `App\Support\SharedServiceBoundaryPolicy` | Configured service-call budgets use expiring counters per caller namespace and invoked boundary; all user-derived dimensions are hashed, no index is retained, and uncapped calls create no keys. |
| `namespace_durable_state_quota_rejections` | `server:namespace-durable-state:` | `App\Support\NamespaceDurableStateQuota` | One two-minute counter and one log-suppression key per namespace and fixed durable-state rejection reason; SQL rows remain the quota authority. |
| `workflow_task_expired_lease_recovery` | `server:workflow-task-expired-lease-recovery:` | `App\Support\WorkflowTaskPoller` | Expired-task recovery scans are capped by `server.polling.expired_workflow_task_recovery_scan_limit`, default 5, and duplicate recovery attempts are TTL-suppressed per task. |
| `history_retention_inline` | `server:history-retention-inline:` | `App\Support\HistoryRetentionEnforcer` | One short-lived throttle key per namespace receiving worker heartbeats; the elected heartbeat prunes at most one expired run. |
| `worker_compatibility_heartbeat` | `server:worker-compatibility-heartbeat:` | `App\Support\WorkerCompatibilityHeartbeatRecorder` | One expiring throttle key per recently heartbeating namespace/worker elects a single compatibility-fleet refresh across standalone HTTP processes during each TTL/3 write interval, then drains without an index. |
| `readiness_probe` | `server:readiness:` | `App\Support\ServerReadiness` | One temporary probe key per readiness check; deleted immediately and also protected by a 10-second TTL. |

`workflow_query_tasks` covers the query task, queue, lease, queue-lock,
query-poller marker, and same-worker leased-task index keys under
`server:workflow-query-task:`. Task and queue keys live for the configured
query-task TTL window, lease keys live for the effective query-task lease
timeout, leased-task index keys live for the query-task TTL window, and queue
locks live for 10 seconds. The query-poller marker key is scoped to
`(namespace, task_queue, worker_id)` and lives for the worker's requested query
poll timeout plus 5 seconds when `timeout_seconds` is supplied, otherwise for
`max(server.workers.stale_after_seconds, server.query_tasks.timeout + 5)`
seconds. The query timeout defaults to
`max(server.polling.timeout + 15, server.lease.workflow_task_timeout + 5, 40)`
and is runtime-clamped to 0 or greater. Markers are written only when a
registered worker polls the query-task endpoint, are refreshed by the same
worker's repeat polls, and are not retained in an index. Queue reads and writes
prune stale pending task IDs by checking the referenced task records. Query
completion, failure, and timeout paths remove IDs from the same-worker
leased-task index, repeat same-worker polls prune expired or non-leased IDs,
and the index key also has TTL eviction.

Workflow and activity poll request bindings reuse the existing durable task
payload row. A claim overwrites the single reserved binding value on that task,
so the binding adds no rows or keys beyond the task collection's existing
retention bound.

## Polling Scan Limits

| Policy ID | Config Key | Owner | Growth Bound |
| --- | --- | --- | --- |
| `due_timer_recovery` | `server.polling.due_timer_recovery_scan_limit` | `App\Support\WorkflowTaskPoller` | Service-mode due-timer recovery scans at most 5 ready timer tasks by default per worker poll pass, scoped to the polled namespace, task queue, and build-id compatibility cohort. |
| `expired_workflow_task_recovery` | `server.polling.expired_workflow_task_recovery_scan_limit` | `App\Support\WorkflowTaskPoller` | Expired workflow-task recovery scans at most 5 expired leases by default per recovery pass, with duplicate recovery attempts TTL-suppressed per task. |

## Metric Inventory

| Metric | Surface | Label Policy |
| --- | --- | --- |
| `dw_workflow_task_consecutive_failures` | `GET /api/system/metrics` | `namespace` is request-scoped rather than a label. `workflow_type` series are limited by `server.metrics.workflow_task_failure_type_limit`, default 20 and hard-clamped to 100; suppressed type/task counts are reported in the payload. |
| `dw_projection_drift_total` | `GET /api/system/metrics` | `namespace` is server-scoped rather than a label. `table` is fixed to the finite projection inventory: `run_summaries`, `run_waits`, `run_timeline_entries`, `run_timer_entries`, and `run_lineage_entries`. Alert on non-zero `needs_rebuild` per table. |
| `dw_namespace_request_admission_rejections` | `GET /api/system/metrics` | `namespace` is request-scoped rather than a label. Rejection counters use a fixed three-reason inventory for the current minute. |
| `dw_namespace_durable_state_usage` | `GET /api/system/metrics` | `namespace` is request-scoped rather than a label. Usage and rejection counters use the fixed durable resource inventory plus quota unavailability. |
| `dw_runtime_external_payload_namespace_usage` | `GET /api/system/metrics` | `namespace` is request-scoped rather than a label. Durable byte/object usage has no dynamic labels; rejection counters use a fixed three-reason inventory for the current minute. |
| `dw_workflow_runs_total` | `GET /api/system/prometheus-metrics` | `namespace` is request-scoped rather than a label. `task_queue`/`workflow_type` series are limited by `server.metrics.prometheus_workflow_series_limit`, default 100 and hard-clamped to 500. Scrape-time discovery reads at most `limit + 1` label sets; `cardinality.series_limits.workflows` reports exact counts until the cap is exceeded, then lower bounds. |
| `dw_workflow_run_latency_seconds` | `GET /api/system/prometheus-metrics` | Shares the bounded `task_queue`/`workflow_type` series set used by `dw_workflow_runs_total`; latency buckets are emitted only for reported workflow series. |
| `dw_activity_executions_total` | `GET /api/system/prometheus-metrics` | `namespace` is request-scoped rather than a label. `task_queue`/`workflow_type`/`activity_type` series are limited by `server.metrics.prometheus_activity_series_limit`, default 100 and hard-clamped to 500. Scrape-time discovery reads at most `limit + 1` label sets; `cardinality.series_limits.activities` reports exact counts until the cap is exceeded, then lower bounds. |
| `dw_activity_execution_latency_seconds` | `GET /api/system/prometheus-metrics` | Shares the bounded `task_queue`/`workflow_type`/`activity_type` series set used by `dw_activity_executions_total`; latency buckets are emitted only for reported activity series. |
| `dw_task_queue_runtime_state` | `GET /api/system/prometheus-metrics` | `namespace` is request-scoped rather than a label. `task_queue` series are limited by `server.metrics.prometheus_task_queue_series_limit`, default 100 and hard-clamped to 500. Scrape-time discovery reads at most `limit + 1` queue label sets, aggregation scans only active or last-minute task rows for reported queues, and `cardinality.series_limits.task_queues` reports exact counts until the cap is exceeded, then lower bounds. |
| `dw_server_image` | Activities conformance `published_artifact_worker_execution` evidence | No labels; single evidence field per published server artifact execution entry. The value stays scoped to the conformance evidence payload and is matched against the pinned server image source. |
| `dw_perf_requests_total` | Perf harness `/metrics`; optional remote_write | The only label is `status`, produced from HTTP response codes and load-generator exception buckets, so the series set is finite. |
| `dw_perf_errors_total` | Perf harness `/metrics`; optional remote_write | No labels; single counter series per soak run. |
| `dw_perf_latency_seconds_average` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_server_memory_bytes` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_redis_memory_bytes` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_redis_polling_keys` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_redis_server_keys` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. Counts all Redis keys in the server-owned `server:*` cache namespace, not only the workflow-task polling subset. |
| `dw_perf_redis_server_keys_by_policy` | Perf harness `/metrics`; optional remote_write | The only label is `policy`, fixed to the reviewed `cache_keys` inventory in `config/dw-bounded-growth.php`. |
| `dw_perf_redis_db_keys` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |
| `dw_perf_assertion_failed` | Perf harness `/metrics`; optional remote_write | No labels; single gauge series per soak run. |

## Enforcement

`tests/Unit/BoundedGrowthPolicyTest.php` checks the policy against source:

- every `server:*` cache key prefix literal in `app/` must be covered by a
  `cache_keys` entry;
- every `dw_*` metric name literal in `app/` and `scripts/perf/` must be
  covered by a `metrics` entry;
- Prometheus labels emitted by app or perf-harness source must exactly match
  the corresponding metric dimensions declared in the policy;
- runtime metric disclosures reject unknown metrics or undeclared dimensions
  before they can appear in `/api/system/metrics`;
- every declared metric dimension must use a `bounded_*`, `finite_*`,
  `request_scope_*`, or `*_no_label` cardinality class so unbounded label sets
  cannot ship even when a new dimension name is not on the user-controlled list;
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
  (default 80%) of the configured duration/sample-interval window; the final
  post-drain sample is reported separately and does not count toward coverage;
- the maximum server memory, Redis key counts, server-owned `server:*` cache
  key counts, per-policy server cache key counts, final drain counts, and, for
  runs of at least 10 minutes, the post-warmup memory slope when a slope limit
  is configured;
- `sampling_health` showing every compose-backed Docker, Redis, and MySQL
  sample was collected successfully; missing resource samples fail the run
  instead of being recorded as zero-count evidence;
- GitHub/runner provenance in `summary.json` (`GITHUB_SHA`, `GITHUB_RUN_ID`,
  `GITHUB_EVENT_NAME`, runner name/OS/arch/environment, Compose project, and
  the tested base URL when present);
- a checked-out git SHA that matches `GITHUB_SHA`, so a trusted artifact cannot
  claim evidence for one commit while running another checkout;
- the SHA-256 digest of `config/dw-bounded-growth.php` so the artifact can be
  tied back to the policy that was active for the run;
- a clean tracked working tree, recorded as `tracked_working_tree_clean`, so a
  trusted artifact cannot come from uncommitted source or policy edits that were
  never reviewed in git.

If `periodic_sample_count` falls below `minimum_trusted_samples`, the harness
marks the run failed instead of uploading an incomplete artifact as passing
evidence.

`summary.json` includes both aggregate server cache keys and
`max_server_cache_keys_by_policy` / `final_server_cache_keys_by_policy`. Those
per-policy maps mirror the `cache_keys` inventory so a long soak can show which
bounded cache family produced growth instead of only reporting a total
`server:*` count. The same finite per-policy inventory is exposed as
`dw_perf_redis_server_keys_by_policy{policy="..."}` for optional remote-write
alerting.

`summary.json` also includes `evidence.trust` with the
`trusted_long_soak_v1` profile. Short CI smokes remain ineligible for trusted
evidence. The scheduled and manually dispatched long soak qualifies only when
it runs for at least one hour, uses compose-backed resource sampling, executes
on the disposable long-soak host with
`RUNNER_ENVIRONMENT=self-hosted` provenance, include GitHub Actions provenance
(`GITHUB_REPOSITORY`, `GITHUB_REF`, `GITHUB_SHA`, `GITHUB_WORKFLOW`,
`GITHUB_EVENT_NAME`, `GITHUB_RUN_ID`, and `GITHUB_RUN_ATTEMPT`), come from the
`Server Perf Soak` workflow in `durable-workflow/server` on `refs/heads/main`, use a
scheduled or manual dispatch event, have a clean tracked working tree, have
`GITHUB_SHA` match the checked-out source commit, meet sample coverage, include
complete per-policy maximum and final cache threshold maps for every declared
cache policy, and have no bounded-growth assertion failures. A local run,
pull-request smoke, unrelated workflow, or feature-branch workflow can still
produce useful artifacts, but it cannot satisfy the
trusted long-soak evidence profile just by setting runner environment metadata.
The CI smoke sets `RUNNER_ENVIRONMENT=github-hosted`; the long-soak controller
sets `RUNNER_ENVIRONMENT=self-hosted` only for execution on its disposable host.

Per-policy limits can be enforced with JSON maps keyed by policy ID:
`DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY` for maximum observed keys and
`DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY` for post-drain keys. Unknown
policy IDs, non-integer values, and negative limits fail before the soak starts
so evidence cannot silently drift away from the inventory in
`config/dw-bounded-growth.php`. Trusted long-soak evidence is also marked
ineligible when either per-policy threshold map is omitted or incomplete, even
if the aggregate cache-key ceilings pass.
