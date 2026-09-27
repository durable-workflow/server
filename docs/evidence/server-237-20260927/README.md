# Server #237 initial history probe, 2026-09-27

This is a bounded first measurement for [Server #237](https://github.com/durable-workflow/server/issues/237), not the full long-lived workflow qualification. Inputs and credentials were synthetic. The Compose project was isolated from Cloud and from the Server #137 remote benchmark host.

## Frozen setup

| Component | Exact selection |
| --- | --- |
| Server | Published 2.4.19 Docker Hub index `sha256:77a61a9f46ca9765ee86174b9a50dab1b9e810250caa9e3551b800457a81862a` on amd64 |
| MySQL | Published 8.4 image `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` |
| Redis | Published 7.2 image `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| SDK | Published Python SDK 2.3.4 dependencies with candidate `sdk-python` source commit `bb4dd8d` bind-mounted for paginated history and result retrieval, tracked by [SDK #81](https://github.com/durable-workflow/sdk-python/issues/81) |
| Host | Four-core Intel i5-6500, 15 GiB RAM, x86-64, local Docker |
| Limits | Probe container: 1 CPU and 1 GiB. Server, MySQL, Redis, worker, and scheduler used Compose defaults without explicit CPU/memory caps. |
| Auth | Synthetic role-scoped token credentials, `DW_AUTH_BACKWARD_COMPATIBLE=false`, local-only API port `127.0.0.1:18237` |

The workload records deterministic integer side effects. Every 75 side effects, an activity completes to create a new workflow task. It checks the ordered event sequences, all side effect and activity counts, and the arithmetic result. `history_probe.py` caps one run at 12,000 side effects and the worker at 900 seconds. The event count includes start and terminal events; continuation count is zero. The candidate SDK fetches history in pages of 1,000 and resolves the terminal event through the same paginated path.

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

Two smaller observations exposed limits in the measurement path:

- A fresh 100-side-effect run completed on Server 2.4.19 with stored output `4950`, but published Python SDK 2.3.4 returned `None` because its `get_result()` read only the first 100-event history page. The candidate source returned `4950` for a fresh 103-event run. [SDK PR #82](https://github.com/durable-workflow/sdk-python/pull/82) carries the fix.
- An unchunked 1,200-side-effect completion returned HTTP 500 after PHP's 30-second maximum execution time in Laravel's `ValidationRuleParser`. It retried and timed out again, so the trial was stopped. The chunked 1,050-side-effect run above completed. The batch-size failure needs a bounded Server finding and repeat; it is not evidence that a 1,200-event run is inherently invalid.

The remaining #237 work is to measure around the actual 8,000/10,000-event and 4/5 MiB guidance with mixed signals, timers, continuations, external payloads, worker restart, retention cleanup, and supported database backends. Record database growth and per-task replay latency before drawing operating guidance.
