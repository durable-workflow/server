# Server Perf Runner

Issue `zorporation/durable-workflow#461` tracks bounded cache and metric-cardinality discipline. The server repo contributes the CI/perf harness for that work.

## Runner Shape

Use GitHub Actions as the control plane and one trusted self-hosted Vultr runner for long soaks.

Initial Vultr size:

- Product: High Performance Cloud Compute
- Shape: 4 vCPU / 8 GB RAM / NVMe
- Budget: about 48 USD/month monthly cap, or about 0.071 USD/hour
- Backups: off for the first pass

Vultr bills stopped instances, so destroy the server if the runner is no longer needed.

## GitHub Runner Labels

Register the runner with these labels:

- `self-hosted`
- `linux`
- `x64`
- `vultr-perf`
- `server-perf`

The long soak workflow targets all five labels. Do not attach these labels to general-purpose runners that may execute untrusted pull request code.

Install the current GitHub Actions runner package when provisioning the box. The workflow uses current Node 24-based actions, so the runner must be at least `2.327.1`.

## GitHub Configuration

Required for the soak job:

- A self-hosted runner with the labels above.

Optional for Grafana Cloud remote write:

- Repository variable `DW_PERF_GRAFANA_REMOTE_WRITE_URL`
- Repository variable `DW_PERF_GRAFANA_USERNAME`
- Repository secret `DW_PERF_GRAFANA_API_TOKEN`

When those values are absent, the harness still runs and uploads local JSON, log, and Prometheus exposition artifacts. When all are present, the wrapper starts a short-lived Prometheus sidecar that scrapes the harness endpoint and remote-writes to Grafana Cloud.

## Harness Behavior

The harness starts the production Docker Compose stack with isolated ports and a unique Compose project name, then drives the real worker polling route:

- creates perf namespaces,
- registers workers across multiple task queues,
- repeatedly calls `POST /api/worker/workflow-tasks/poll` with unique `poll_request_id` values,
- samples server, Redis, MySQL, and polling-cache counts,
- waits for the polling-result TTL window to drain,
- fails if cache keys, memory ceiling, request errors, or long-run memory slope exceed the configured budgets.

The short smoke job runs on GitHub-hosted runners and proves the harness plus cache-key drain path. The long soak runs on the Vultr self-hosted runner and enforces the memory slope budget after the run is long enough to make that signal meaningful.

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
- Do not commit Grafana tokens, runner registration tokens, or generated Prometheus configs.
