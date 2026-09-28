# Server #257 published terminal timeline attribution

This directory records an isolated, synthetic reproduction of the selected-run timeline drift. It uses a published Server image and published Python SDK, with the Server queue worker absent during task completion. The Python SDK worker handles the workflow and activity. The persistent Server queue worker was started only after the first snapshot to check whether ordinary background work repaired the row.

## Published baseline, 2026-09-28

| Input | Exact selection |
| --- | --- |
| Server | `durableworkflow/server:2.4.22`, multiarch index `sha256:c0152bf71b163b047dea9ecba2ff79ef1f5f081815d79003474d34107453bb9e`, amd64 |
| Embedded Workflow | Published 2.2.16, source `f91bd13856d9258dd04bafa90b9f6eb8d0b67cb7` |
| Python SDK | Published PyPI 2.3.5 in a disposable `python:3.12-slim` probe |
| MySQL and Redis | Pinned digests in [compose.yml](compose.yml) |
| Host and limits | Four-core Intel i5-6500, 15 GiB RAM, x86-64 local Docker. Probe limited to 1 CPU and 1 GiB. Service containers used Compose defaults. |
| Server mode | Production, polling dispatch, synthetic scoped tokens, loopback HTTP binding, scheduler present, Server queue worker absent until after first snapshot. |

The [probe result](probe-result.json) shows 1,000 side effects with an activity boundary after 500, an exact result of `499500`, 1,006 ordered history events across two pages, and an 18.56-second SDK worker run. The [durable snapshot](snapshot-before-worker.json) shows a completed run with 1,006 history events, 1,006 timeline rows, 1,006 summary events, no open task, and no rejected commands. This confirms that the previous row-count truncation is fixed in the published image.

The [row comparison](timeline-diff-before.json) found one mismatch among those 1,006 entries. The terminal `WorkflowCompleted` history event has the authenticated `complete_workflow` command, while its projected timeline row has `command=null` and `command_type=null`. The [drift check](projection-drift-before.json) selected only this run's timeline. The [supported repair dry run](projection-dry-run-before.json) matched the run. Starting the Server queue worker did not change the [row comparison](timeline-diff-after-worker.json) or [drift result](projection-drift-after-worker.json). A [manual supported rebuild](manual-rebuild.json) cleared the [timeline drift](projection-drift-after-manual-rebuild.json) and [row difference](timeline-diff-after-manual-rebuild.json).

The cause is the order in `WorkerTerminalEventAttribution::record()`: the Workflow bridge projects the terminal row, then Server saves the authenticated command into the history event without refreshing that row. The fix projects that single enriched event inside the same completion transaction.

## Reproduction commands

From a Server checkout containing this directory, create a fresh ignored environment file with **synthetic** values for `COMPOSE_PROJECT_NAME`, `DW_SERVER_IMAGE`, `DW_SERVER_KEY`, `DW_OPERATOR_TOKEN`, `DW_WORKER_TOKEN`, `DW_AUTH_BACKWARD_COMPATIBLE=false`, `DW_TASK_DISPATCH_MODE=poll`, `APP_ENV=production`, and a free loopback `SERVER_PORT`. Set `DW_SERVER_IMAGE` to the exact index above. The disposable project used `dw572pub153647` and port `127.0.0.1:18272`. The command structure was:

```bash
docker compose --env-file "$RUN_ENV" -f docker-compose.published.yml \
  -f docs/evidence/server-257-20260928/compose.yml \
  up -d mysql redis bootstrap server scheduler
docker compose --env-file "$RUN_ENV" -f docker-compose.published.yml \
  -f docs/evidence/server-257-20260928/compose.yml build probe
docker compose --env-file "$RUN_ENV" -f docker-compose.published.yml \
  -f docs/evidence/server-257-20260928/compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=published-2422-noqueue \
  -e PROBE_ACTIVITY_INTERVAL=500 \
  -e PROBE_WORKER_TIMEOUT_SECONDS=600 \
  probe /probe/history_probe.py 1000
```

Take the run ID from the probe result. With the running Server container ID in `SERVER_CONTAINER`, the read-only diagnostic commands were:

```bash
docker exec -i -u 1000:1000 -w /app -e QUALIFICATION_RUN_ID="$RUN_ID" \
  "$SERVER_CONTAINER" php /dev/stdin \
  < docs/evidence/server-257-20260928/run_snapshot.php
docker exec -i -u 1000:1000 -w /app -e QUALIFICATION_RUN_ID="$RUN_ID" \
  "$SERVER_CONTAINER" php /dev/stdin \
  < docs/evidence/server-257-20260928/timeline-diff.php
docker exec -i -u 1000:1000 -w /app -e QUALIFICATION_RUN_ID="$RUN_ID" \
  "$SERVER_CONTAINER" php /dev/stdin \
  < docs/evidence/server-257-20260928/projection-drift.php
```

Start the queue worker using `docker compose ... up -d worker`, then repeat the snapshot and drift checks. The baseline repair was `php artisan workflow:v2:rebuild-projections --run-id="$RUN_ID" --needs-rebuild --json` in the disposable Server container. After collecting evidence, `docker compose ... down --volumes --remove-orphans` removed the entire synthetic stack. The actual local stack, network, volumes, containers and disposable probe image were removed.

## Fixed published image

The published-image verification for the Server release will be added here with the exact multiarch digest, the same no-queue-worker workload, timeline command and principal, drift status, and post-worker check. Source tests alone do not complete this issue.
