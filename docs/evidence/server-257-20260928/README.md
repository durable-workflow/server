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

Server 2.4.23 was published from `f65363ad129080524d95f1bd584da07efcda0b3c`. Docker Hub and GHCR both resolve the exact multiarch image to `sha256:33c59071952ab08d5b4be7c20862d53cc21e5aed56564ff8718a40b5f239f400`. Its amd64 manifest is `sha256:fd363533708a1170f2a18a929d97622abeeb6396cb635f9a26531b49805c367f`, and arm64 is `sha256:a162e675c33c9eb7c30621ed6df4e10c56cca6ba000da75641260836cebdf14e`. The downloaded amd64 image embeds published Workflow 2.2.17 at `a2724ec07e043ae814dbe9a46f1890f8c4fd4bde`. The [Server image and chart release workflow](https://github.com/durable-workflow/server/actions/runs/36452138711) passed multiarch publishing, first-run readiness, source-free Compose bootstrap, OCI chart publication, and anonymous chart pull/install. The [independent chart release workflow](https://github.com/durable-workflow/server/actions/runs/36452087902) also passed lint, install, publication, and anonymous pull/install for chart 0.1.119.

The isolated published-image reproduction used the same Python SDK 2.3.5 probe, pinned database/Redis, hardware and resource limits as the baseline, with only the Server queue worker absent during completion. `DW_SERVER_IMAGE` was the exact index digest above. The [probe result](published-2.4.23-probe-result.json) records 1,000 side effects, one activity boundary, result `499500`, 1,006 ordered history events in two pages, and 18.46 seconds of SDK worker execution. The [first durable snapshot](published-2.4.23-snapshot-before-worker.json) records a completed run with 1,006 history and timeline rows, 1,006 summary events, one accepted command, no rejected commands, and no open task.

The [row comparison](published-2.4.23-timeline-diff-before.json) found zero differences between canonical history and all 1,006 stored timeline rows, including the terminal `WorkflowCompleted` command. The [projection drift check](published-2.4.23-projection-drift-before.json) found no stale timeline, and the [supported repair dry run](published-2.4.23-projection-dry-run-before.json) matched zero runs. After starting the Server queue worker, the [second durable snapshot](published-2.4.23-snapshot-after-worker.json), [row comparison](published-2.4.23-timeline-diff-after-worker.json), and [drift check](published-2.4.23-projection-drift-after-worker.json) remained clean. The [container state](published-2.4.23-container-state.txt) records no Server, scheduler, or queue-worker restart or OOM kill. The synthetic stack, network, and volumes were removed after collection.

The published [principal-attribution conformance summary](published-2.4.23-principal-conformance-summary.json) records 11 passing scenarios, no findings, and `runner_blocked=false` with a clean process exit and automatic scratch cleanup. Its [pins](published-2.4.23-principal-pins.json) record the exact published Server, Workflow, PHP SDK, Python SDK, CLI, and Waterline artifacts.

The published [replay conformance result](published-2.4.23-replay-conformance-result.json) records all 31 PHP, Python, and Rust replay scenarios passing with no findings and `runner_blocked=false`. Its [pins](published-2.4.23-replay-pins.json) add Rust SDK 2.1.1 to the same Server, Workflow, PHP SDK, Python SDK, CLI, and Waterline tuple. The result retains the executed distribution identities and scenario observations. The replay stack and scratch directory were removed on a clean runner exit.
