# Server Perf Runner

The server perf harness exercises the HTTP worker polling path for bounded
memory growth and polling-cache cleanup.

## Runner Shape

The public workflows have two modes:

- a short smoke job on GitHub-hosted runners for pull requests and pushes
- a longer soak job executed over SSH on a newly provisioned Vultr instance for scheduled and manual runs

The GitHub-hosted controller creates one `vhp-2c-4gb-amd` instance, waits for
cloud-init, checks out the exact Actions SHA, runs the existing Compose-backed
harness, retrieves its artifacts, and deletes the instance. The host is not
registered as a GitHub runner and is not reused between jobs.

## GitHub Configuration

Required for the soak job:

- GitHub Environment `perf-soak`
- Environment secret `VULTR_PERF_API_KEY`, belonging to a dedicated Vultr
  service user with only `compute.instance.Create`,
  `compute.instance.Read`, and `compute.instance.Delete`
- Environment variable `VULTR_PERF_REGION`
- Repository variable `DW_PERF_SOAK_ENABLED=true` to enable the daily schedule

The broad account credential must not be used. The controller credential stays
on the GitHub-hosted job and is not copied to the instance. Each run generates
a new SSH key, permits SSH only from the controller's current public IPv4
address, and binds the benchmark Server port to loopback.
GitHub-hosted runner egress addresses are dynamic, so the Vultr service user's
IPv4 API allowlist permits GitHub-hosted sources; its narrow IAM policy and the
default-branch GitHub Environment restriction are the authorization boundary.

Optional for Prometheus `remote_write` export:

- Repository variable `DW_PERF_REMOTE_WRITE_URL`
- Repository variable `DW_PERF_REMOTE_WRITE_USERNAME`
- Repository secret `DW_PERF_REMOTE_WRITE_PASSWORD`

When those values are absent, the harness still runs and uploads local JSON,
log, and Prometheus exposition artifacts. When all are present, the wrapper
starts a short-lived Prometheus sidecar that scrapes the harness endpoint and
remote-writes to the configured endpoint. Remote-write labels are intentionally
limited to deployment-scoped values such as repository and workflow. Per-run
identity, runner name, runner OS/arch, and the tested URL stay in
`summary.json` provenance so the evidence can be traced without creating a new
Prometheus series for every run.

## Harness Behavior

The harness starts the production Docker Compose stack with isolated ports and a unique Compose project name, then drives the real worker polling route:

- creates perf namespaces,
- registers workers across multiple task queues,
- repeatedly calls `POST /api/worker/workflow-tasks/poll` with unique `poll_request_id` values,
- samples server, Redis, MySQL, and polling-cache counts,
- waits for the polling-result TTL window to drain,
- fails if cache keys, memory ceiling, request errors, or long-run memory slope exceed the configured budgets.

The short smoke job runs on GitHub-hosted runners and proves the harness plus
cache-key drain path. The long soak runs on the disposable host and enforces
the memory slope budget after the run is long enough to make that signal
meaningful.

Workflow-growth coverage is rate-independent. `DW_PERF_WORKFLOW_RUNS` sets the
nominal cardinality and `DW_PERF_MIN_WORKFLOW_COMPLETION_RATIO` sets the minimum
successful fraction, defaulting to 98%. This lets a healthy shared runner finish
slightly below the nominal target without weakening the bounded cache, memory,
resource-sampling, or drain assertions. Request errors, non-100% endpoint
availability, completion below the configured floor, and the wrapper's bounded
load timeout still fail the run. `summary.json` records the target, minimum
successful starts, attempted and successful starts, observed completion ratio,
and final workflow-row count under `workflow_growth`.

Both workflow modes pass explicit execution provenance into the artifact. Short
smokes set `RUNNER_ENVIRONMENT=github-hosted`; disposable-host long soaks set
`RUNNER_ENVIRONMENT=self-hosted`, which is required before `summary.json` can be
classified as trusted long-soak evidence. The self-hosted job also sets
`DW_PERF_REQUIRE_TRUSTED_EVIDENCE=true`, so it fails if the run completes but the
artifact is ineligible for `trusted_long_soak_v1`.

## Local Run

From the server repo:

```bash
DW_PERF_DURATION_SECONDS=120 \
DW_PERF_CONCURRENCY=8 \
scripts/perf/run-server-soak.sh
```

Artifacts land in `build/perf/` by default. The script removes the Compose project and volumes on exit.

`summary.json` is the evidence index for a run. It includes the configured
duration, elapsed time, request/error totals, memory and Redis key ceilings,
final drain counts, sample coverage, GitHub runner provenance, and the
SHA-256 digest of `config/dw-bounded-growth.php`. Trusted long-soak evidence
also requires `tracked_working_tree_clean=true` and GitHub Actions provenance
(`GITHUB_REPOSITORY`, `GITHUB_REF`, `GITHUB_SHA`, `GITHUB_WORKFLOW`,
`GITHUB_EVENT_NAME`, `GITHUB_RUN_ID`, and `GITHUB_RUN_ATTEMPT`) from the
`Server Perf Soak` workflow in `durable-workflow/server` on `refs/heads/main`. The
trusted profile is limited to scheduled and manual dispatch long-soak events,
and requires a checked-out source commit matching `GITHUB_SHA`, so artifacts
from uncommitted source, policy edits, feature branches, forks, unrelated
workflows, pull-request smokes, misconfigured checkouts, or ad hoc local runs
are marked ineligible for the trusted profile.
When `DW_PERF_REQUIRE_TRUSTED_EVIDENCE=true`, the harness turns that ineligible
profile into a failed run and records the profile reasons in `summary.json`.
The harness fails when it cannot collect at least `DW_PERF_MIN_SAMPLE_COVERAGE`
of the expected periodic samples, which defaults to 80%. The final post-drain
sample is included in the artifact but does not count toward the periodic sample
coverage gate.

Use `DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY` and
`DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY` to enforce per-cache-family
limits in addition to the aggregate `server:*` cache ceiling. Each value must
be a JSON object keyed by a `config/dw-bounded-growth.php` cache policy ID with
non-negative integer limits. The map must include every declared cache policy;
unknown policy IDs, missing policy IDs, and non-integer limits fail before load
starts so a typo or partial map cannot silently weaken the evidence. A trusted
long-soak artifact is marked ineligible if either per-policy threshold map is
omitted or incomplete. The workflow file contains the canonical smoke and
long-soak threshold maps, for example:

```bash
DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY='{"workflow_task_poll_requests":0,"activity_task_poll_requests":0,"query_task_poll_requests":0,"sqlite_worker_poll_claim_gate":0,"long_poll_signals":0,"long_poll_wait_slots":0,"workflow_query_tasks":0,"task_queue_admission_locks":0,"task_queue_dispatch_counters":0,"workflow_task_expired_lease_recovery":0,"history_retention_inline":0,"readiness_probe":0}'
```

## Safety Rules

- Do not expose the Vultr secret to pull-request workflows.
- Keep the GitHub Environment restricted to the default branch.
- Keep instance deletion in the controller's exit trap.
- Do not commit remote-write credentials, runner registration tokens, or generated Prometheus configs.
