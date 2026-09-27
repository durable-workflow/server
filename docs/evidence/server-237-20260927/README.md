# Server #237 initial history probe, 2026-09-27

This is a bounded first measurement for [Server #237](https://github.com/durable-workflow/server/issues/237), not the full long-lived workflow qualification. Inputs and credentials were synthetic. The Compose project was isolated from Cloud and from the Server #137 remote benchmark host.

## Frozen setup

| Component | Exact selection |
| --- | --- |
| Server | Published 2.4.19 Docker Hub index `sha256:77a61a9f46ca9765ee86174b9a50dab1b9e810250caa9e3551b800457a81862a` on amd64 |
| MySQL | Published 8.4 image `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` |
| Redis | Published 7.2 image `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| SDK | Published Python SDK 2.3.4 dependencies with candidate `sdk-python` source commit `bb4dd8d` bind-mounted for paginated history and result retrieval, tracked by [SDK #81](https://github.com/durable-workflow/sdk-python/issues/81) |
| Published recheck SDK | Python SDK 2.3.5 from PyPI, wheel SHA-256 `1db40895ed81d6df44a6e62db3305fa6ec01fce41296acf2a0beafb17e6e685f`, released from source commit `53e7ddd57c1a31aa4ec6ce4395e50d3018c409e9` |
| Host | Four-core Intel i5-6500, 15 GiB RAM, x86-64, local Docker |
| Limits | Probe container: 1 CPU and 1 GiB. Server, MySQL, Redis, worker, and scheduler used Compose defaults without explicit CPU/memory caps. |
| Auth | Synthetic role-scoped token credentials, `DW_AUTH_BACKWARD_COMPATIBLE=false`, local-only API port `127.0.0.1:18237` |

The workload records deterministic integer side effects. Every 75 side effects, an activity completes to create a new workflow task. It checks the ordered event sequences, all side effect and activity counts, and the arithmetic result. `history_probe.py` caps one run at 12,000 side effects and the worker at 900 seconds. The event count includes start and terminal events; continuation count is zero. The candidate SDK fetches history in pages of 1,000 and resolves the terminal event through the same paginated path.

The signal probe starts a workflow that waits for distinct integer signals, stops its first SDK worker, sends bounded batches of signals, then starts a new worker to check replay and the exact result. The published Server 2.4.19 image bundles Workflow 2.2.11. Its source was not changed for these observations.

## Commands

The task-local `stack.env` set the image references above, `COMPOSE_PROJECT_NAME=dw237h2250`, the synthetic role tokens, `APP_ENV=production`, a synthetic `DW_SERVER_KEY`, and `SERVER_PORT=127.0.0.1:18237`. It was not committed. Set `RUN_ENV` to that synthetic environment file and `SDK_SOURCE` to the candidate SDK's `src` directory. From the Server checkout:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  up -d --wait server worker scheduler
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  build probe
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  run --rm --no-deps \
  -v "$SDK_SOURCE:/candidate:ro" \
  -e PYTHONPATH=/candidate -e PROBE_RUN_ID=chunked1050 probe 1050
```

## Observed result

The [raw JSON](history-probe-1050.json) reports 1,092 ordered history events in two API pages: 1,050 `SideEffectRecorded`, 13 each of `ActivityScheduled`, `ActivityStarted`, and `ActivityCompleted`, one accepted start, one workflow start, and one workflow completion. The result was `550725`, the expected sum of integers 0 through 1,049. Worker execution took 261.24 seconds and the probe process peaked at 54,392 KiB RSS by the time its worker stopped. This is one local diagnostic run, without repetition or a baseline. It does not establish throughput, a recommended history limit, or a Server memory ceiling.

After Python SDK 2.3.5 published, a fresh stack used the same Server/MySQL/Redis digests and resource limits. Build and run the exact published package without a source mount:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  up -d --wait server worker scheduler
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  build --build-arg PYTHON_SDK_VERSION=2.3.5 probe
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  run --rm --no-deps -e PROBE_RUN_ID=published235 probe 1050
```

The [published-package raw result](history-probe-1050-published-235.json) again records 1,092 ordered events in two pages and the exact result `550725`, with 13 completed activities. The worker took 273.90 seconds and the probe process peaked at 52,760 KiB RSS. These two runs are not controlled repetitions because the SDK source changed. They verify that the released package can retrieve a terminal event beyond the first history page.

Published PHP SDK 2.1.5 (Packagist source `d664c865c8b9b352eb79abd27ee3141a87a1924f`) read the same completed run and returned `550725` from both the description and `workflowResult()`. Its `workflowHistory()` returned the first 100 events with a next-page token; the [raw output](php-result-215.json) records that distinction. This scalar-result check found no PHP result defect. It does not cover large external result payloads or a failure event on a later page.

## Signal ingestion and worker restart

A separate isolated Compose project, `dw237s2334`, used the same published Server, MySQL, Redis and Python SDK 2.3.5 digests and the same limits. `signal_history_probe.py` sent eight concurrent signal requests at a time. The [completed 100-signal raw result](signal-probe-100-published-235.json) records 100 acknowledged signals, 106 ordered history events, the exact sum `4950`, and a completed result after stopping and restarting the SDK worker. Offering the signals took 21.96 seconds; observed per-request p50/p95/p99 latency was 0.885/1.931/2.222 seconds. This is one smoke run, not a throughput claim.

An 8,100-signal target used the same eight-request pattern, but admission slowed as the run grew. The attempt was stopped deliberately after 584 acknowledged signal events in about six minutes and 54 seconds. The [stop snapshot](signal-probe-8100-stopped.json) records a `waiting` run with 587 history events, 779,995 serialized history bytes, and no budget pressure yet. No final result or 8,100-event claim is made. The probe had not yet emitted its first 1,000-signal progress line, so the stop snapshot comes from the published stack's run summary and history tables. Source inspection found that each accepted signal loads all run commands and history events under locks in bundled Workflow 2.2.11. [Workflow #565](https://github.com/durable-workflow/workflow/issues/565) owns profiling and a bounded signal-admission fix before this history-boundary probe is repeated.

To reproduce the signal smoke case with the published SDK, use the same Compose stack and probe build commands above without a candidate source mount, then run:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml -f docs/evidence/server-237-20260927/compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=smoke100f8 -e PROBE_SIGNAL_CONCURRENCY=8 \
  probe /probe/signal_history_probe.py 100
```

Two smaller observations exposed limits in the measurement path:

- A fresh 100-side-effect run completed on Server 2.4.19 with stored output `4950`, but published Python SDK 2.3.4 returned `None` because its `get_result()` read only the first 100-event history page. The candidate source returned `4950` for a fresh 103-event run. [SDK PR #82](https://github.com/durable-workflow/sdk-python/pull/82) carries the fix.
- An unchunked 1,200-side-effect completion returned HTTP 500 after PHP's 30-second maximum execution time in Laravel's `ValidationRuleParser`. It retried and timed out again, so the trial was stopped. The chunked 1,050-side-effect run above completed. The batch-size failure needs a bounded Server finding and repeat; it is not evidence that a 1,200-event run is inherently invalid.

The remaining #237 work is to measure around the actual 8,000/10,000-event and 4/5 MiB guidance with mixed signals, timers, continuations, external payloads, worker restart, retention cleanup, and supported database backends. Record database growth and per-task replay latency before drawing operating guidance.
