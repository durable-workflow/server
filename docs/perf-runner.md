# Server Perf Runner

The server perf harness exercises the HTTP worker polling path for bounded
memory growth and polling-cache cleanup.

## Runner Shape

The workflow has two modes:

- a short smoke job on GitHub-hosted runners for pull requests and pushes
- a longer soak job on a trusted self-hosted runner for scheduled and manual runs

Register any self-hosted soak runner with these labels:

- `self-hosted`
- `linux`
- `x64`
- `perf-soak`
- `server-perf`

The long soak workflow targets all five labels. Do not attach these labels to general-purpose runners that may execute untrusted pull request code.

Install a current GitHub Actions runner package. The workflow uses current
Node-based actions and should run on a maintained runner version.

## GitHub Configuration

Required for the soak job:

- A self-hosted runner with the labels above.

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
cache-key drain path. The long soak runs on the labeled self-hosted runner and
enforces the memory slope budget after the run is long enough to make that
signal meaningful.

Both workflow modes pass explicit runner provenance into the artifact. Short
smokes set `RUNNER_ENVIRONMENT=github-hosted`; long soaks set
`RUNNER_ENVIRONMENT=self-hosted`, which is required before `summary.json` can be
classified as trusted long-soak evidence.

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
`Server Perf` workflow in `durable-workflow/server` on `refs/heads/main`. The
trusted profile is limited to scheduled and manual dispatch long-soak events,
and requires a checked-out source commit matching `GITHUB_SHA`, so artifacts
from uncommitted source, policy edits, feature branches, forks, unrelated
workflows, pull-request smokes, misconfigured checkouts, or ad hoc local runs
are marked ineligible for the trusted profile.
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
DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY='{"workflow_task_poll_requests":0,"long_poll_signals":0,"workflow_query_tasks":0,"task_queue_admission_locks":0,"task_queue_dispatch_counters":0,"workflow_task_expired_lease_recovery":0,"history_retention_inline":0,"readiness_probe":0}'
```

## Safety Rules

- Do not run the self-hosted soak job for pull requests from forks.
- Keep the runner dedicated to trusted workflows.
- Keep Docker cleanup in the job even on failure.
- Do not commit remote-write credentials, runner registration tokens, or generated Prometheus configs.
