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
also requires `tracked_working_tree_clean=true`, so artifacts from uncommitted
source or policy edits are marked ineligible. The harness fails when it cannot
collect at least `DW_PERF_MIN_SAMPLE_COVERAGE` of the expected periodic samples,
which defaults to 80%. The final post-drain sample is included in the artifact
but does not count toward the periodic sample coverage gate.

Use `DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY` and
`DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY` to enforce per-cache-family
limits in addition to the aggregate `server:*` cache ceiling. Each value must
be a JSON object keyed by a `config/dw-bounded-growth.php` cache policy ID with
non-negative integer limits, for example:

```bash
DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY='{"workflow_task_poll_requests":2048}'
DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY='{"workflow_task_poll_requests":0}'
```

## Safety Rules

- Do not run the self-hosted soak job for pull requests from forks.
- Keep the runner dedicated to trusted workflows.
- Keep Docker cleanup in the job even on failure.
- Do not commit remote-write credentials, runner registration tokens, or generated Prometheus configs.
