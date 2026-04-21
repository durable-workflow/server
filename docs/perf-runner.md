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
remote-writes to the configured endpoint.

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

## Local Run

From the server repo:

```bash
DW_PERF_DURATION_SECONDS=120 \
DW_PERF_CONCURRENCY=8 \
scripts/perf/run-server-soak.sh
```

Artifacts land in `build/perf/` by default. The script removes the Compose project and volumes on exit.

## Safety Rules

- Do not run the self-hosted soak job for pull requests from forks.
- Keep the runner dedicated to trusted workflows.
- Keep Docker cleanup in the job even on failure.
- Do not commit remote-write credentials, runner registration tokens, or generated Prometheus configs.
