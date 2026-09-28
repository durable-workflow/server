# Server #237 published long-history signal probe, 2026-09-28

This is a local qualification slice with synthetic workflows and credentials. It exercises signal admission, a cold SDK worker restart, replay, workflow-task completion, exact result, and paginated history. It does not yet cover mixed activities and timers, PostgreSQL, retention, or the 8,000/10,000-event thresholds.

## Frozen inputs

| Component | Selection |
| --- | --- |
| Server | Published `durableworkflow/server:2.4.20` multiarch index `sha256:9231de0d3c0c8fed79f1a2e6d62129f85ac844ed7c0afdc6c653a732b1e55bf4`, amd64 |
| Workflow inside Server | Published 2.2.13, dist commit `8eeec4895374ef94cfd9407e4c179435249e1446` |
| Python SDK | Published PyPI 2.3.5 in probe image ID `sha256:2be988b7c758ea36123cf617df79da4c9bbf36127ad6c857b106750bff759b81` |
| MySQL | `mysql@sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` |
| Redis | `redis@sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| Host | Four-core Intel i5-6500, 15 GiB RAM, x86-64, local Docker |
| Limits | Probe: 1 CPU and 1 GiB. Published Server PHP `memory_limit=128M`. Other service containers used Compose defaults without CPU or memory caps. |
| API | Synthetic scoped tokens, `DW_AUTH_BACKWARD_COMPATIBLE=false`, bound to `127.0.0.1:18238` |

The run uses the committed `signal_history_probe.py` fixture. A workflow waits for distinct integer signals; the first SDK worker stops before the signals are offered, then a fresh worker replays and completes. The expected result is `{"count": N, "total": N*(N-1)/2}`. Eight concurrent signal requests are offered at a time. The probe verifies all signal and terminal history event counts and increasing sequence numbers through paginated history.

## Reproduction

Create a task-local `stack.env` with the published Server index above as `DW_SERVER_IMAGE`, `DW_SERVER_TAG=2.4.20`, a unique `COMPOSE_PROJECT_NAME`, synthetic role-scoped credentials and `DW_SERVER_KEY`, `APP_ENV=production`, `DW_AUTH_BACKWARD_COMPATIBLE=false`, and an unused loopback `SERVER_PORT`. The file is intentionally not committed. From the Server checkout:

```bash
RUN_ENV=/path/to/task-local/stack.env
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  up -d --wait server worker scheduler
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  build --build-arg PYTHON_SDK_VERSION=2.3.5 probe
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=unique-1000 -e PROBE_SIGNAL_CONCURRENCY=8 \
  probe /probe/signal_history_probe.py 1000
```

Use a unique `PROBE_RUN_ID` per run. The same command was run with targets 100, 1,000, 3,000, and 3,300. The unmodified published image and 128 MiB PHP limit were used for all four. Check run-summary counters from MySQL and the Server logs when a run does not produce a final probe JSON record.

## Results under the published configuration

| Signals | Offer time | Signal API p50/p95/p99 | Cold worker replay to completion | Ordered events | History bytes | Worker peak RSS | Result |
| ---: | ---: | --- | ---: | ---: | ---: | ---: | --- |
| 100 | 9.28 s | 0.395 / 0.726 / 1.000 s | 1.47 s | 106 | 138,167 | 47,704 KiB | Complete, sum 4,950 |
| 1,000 | 97.68 s | 0.428 / 0.793 / 1.963 s | 9.24 s | 1,006 | 1,335,656 | 58,848 KiB | Complete, sum 499,500 |
| 3,000 | 309.95 s | 0.445 / 0.823 / 0.974 s | 22.06 s | 3,006 | 4,001,656 | 91,264 KiB | Complete, sum 4,498,500 |
| 3,300 | All acknowledged | Probe stopped after completion errors | No valid completion | 3,303 | 4,399,594 | Not measured at exit | Repeated HTTP 500 |

Raw probes: [100](signal-100.log), [1,000](signal-1000.log), [3,000](signal-3000.log), [3,300 partial](signal-3300-memory128-stopped.log), and the [durable stop snapshot](signal-3300-memory128-snapshot.json). The completed rows are individual diagnostic runs, not repetitions for a performance comparison. The 3,300-signal history crossed the documented 4 MiB warning but remained below the 5 MiB continue-as-new recommendation. PHP exhausted its 128 MiB request limit during workflow-task completion. All acknowledged signal events remained persisted and the run was still waiting at probe stop. Workflow #568 owns attribution and a bounded completion fix.

The stopped run's HTTP Server was recreated with a task-local `memory_limit=256M` override. Its pending completion request then returned HTTP 200. A separate resume helper imported the Python workflow class under a different module name, which changed its wait-predicate fingerprint and caused the workflow to fail. This was a test-harness error; it does not demonstrate successful recovery.

For a clean diagnostic, the [override](memory256.ini) and [request-peak instrumentation](qualification-peak.php) were mounted only into the HTTP Server. The [Compose overlay](memory256.compose.yml) is a portable copy of the task-local configuration. Apply it after the original 128 MiB probe while keeping the same MySQL and Redis volumes:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/memory256.compose.yml \
  up -d --no-deps --force-recreate --wait server
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/memory256.compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=unique-3300-m256 -e PROBE_SIGNAL_CONCURRENCY=8 \
  probe /probe/signal_history_probe.py 3300
```

The fresh [3,300-signal result](signal-3300-memory256.log) completed with the original workflow module: 3,306 ordered events, 4,427,984 history bytes, exact sum 5,443,350, and no unfinished workflow task. The completion request returned HTTP 200 and `memory_get_peak_usage(true)` reported 146,800,640 bytes (140 MiB), above the published 128 MiB limit. The cold worker replay-to-completion time was 28.29 seconds and its process peak RSS was 91,324 KiB.

The [4,000-signal result](signal-4000-memory256.log) crossed the documented 5 MiB size recommendation: 4,006 ordered events, 5,366,687 history bytes, `continue_as_new_recommended=true`, exact sum 7,998,000, and no unfinished workflow task. The completion request returned HTTP 200 with a PHP allocated-memory peak of 176,160,768 bytes (168 MiB), recorded in the [Server log extract](completion-memory.log). Offering the signals took 437.23 seconds; observed per-request p50/p95/p99 latency was 0.456/0.865/1.107 seconds. The cold worker replay-to-completion time was 38.39 seconds, and its process peak RSS was 103,948 KiB. `continue_as_new_recommended` is advisory; this deliberately simple fixture completed rather than continuing.

## Candidate Workflow cross-check and bounded limits

A separate isolated stack mounted Workflow candidate commit `d57182bb` from [Workflow #568](https://github.com/durable-workflow/workflow/issues/568) read-only into the published Server 2.4.20 image. It kept the published MySQL, Redis and Python SDK tuple above and restored the HTTP Server to its 128 MiB PHP limit. The [portable Compose overlay](candidate-workflow.compose.yml), [PHP setting](memory128.ini) and request-peak instrumentation show the source overlay and measurements. This is candidate-source evidence, not a published Workflow result.

The candidate completed a fresh 4,000-signal workflow at 128 MiB with 4,006/4,006 history and timeline rows, exact result 7,998,000, and a 78 MiB PHP request allocation peak. The detailed candidate result and tests belong to Workflow #568. A subsequent 8,000-signal target stopped after 5,000 acknowledged signals because the default pending-signal cap is 5,000 while its SDK worker was stopped. The next eight offers received HTTP 409 `structural_limit_exceeded`. The [durable snapshot](pending-signal-5000-snapshot.json) shows 5,003 history and timeline rows, 6,775,727 history bytes, 5,000 received signals, zero applied signals, eight rejected signals, and one open workflow task. This probes admission at the cap; it does not qualify completion or recovery at 8,000 events. Future threshold probes must keep a worker consuming signals or use another bounded event source.

An activity-boundary fixture using the same candidate and the published Python SDK exposed a separate Server limit. Setting `PROBE_ACTIVITY_INTERVAL=500` for a 1,000-side-effect run made its first task return 500 side-effect commands. Three completion requests exceeded PHP's 30-second execution limit in Laravel's `ValidationRuleParser`. The [Server log extract](side-effect-500-validation-timeout-server.log) records the fatal location, and the [durable snapshot](side-effect-500-validation-timeout-snapshot.json) shows only the two start events with the run still pending. The recorded PHP allocation peak was 24,641,536 bytes. [Server #251](https://github.com/durable-workflow/server/issues/251) owns the validation cost and retry-safe fix. The interval setting is available in the [probe fixture](../server-237-20260927/history_probe.py), with the original 75-side-effect interval remaining its default.

To re-run the candidate probes, check out the exact Workflow candidate commit, set `WORKFLOW_CANDIDATE_ROOT` to its absolute path, use a fresh synthetic `RUN_ENV` and project, and add `candidate-workflow.compose.yml` after the three published Compose files shown above. Build `probe` after the fixture change. The [snapshot extractor](run_snapshot.php) accepts a run ID via `QUALIFICATION_RUN_ID` and reads only the disposable stack's durable state.

The [direct projection profiler](projector_peak.php) reprojected the already completed 3,300-signal run in separate PHP CLI processes under a database transaction. Its [raw JSON lines](projector-peak.jsonl) report 22 MiB for the history budget, 76 MiB for timeline mapping, and 116 MiB for either timeline projection or the full run-summary projection. These are PHP allocated-memory peaks, not container RSS. They identify projection as a major part of the request's memory use, while the complete HTTP request peaked higher at 140 MiB. The 256 MiB override is diagnostic only, not an operating recommendation or a fix for history-scaled projection memory.

To repeat the direct profile after the 3,300 run completes, use the run ID from its raw result and run one component per process in the isolated published Server container. The script updates projection rows, so use only disposable qualification state:

```bash
for component in budget timeline_map timeline_project summary; do
  docker exec -i -u 1000:1000 -w /app \
    -e QUALIFICATION_RUN_ID=RUN_ID_FROM_PROBE \
    -e QUALIFICATION_COMPONENT="$component" \
    -e QUALIFICATION_TRANSACTION=1 \
    SERVER_CONTAINER_ID php /dev/stdin \
    < docs/evidence/server-237-20260928/projector_peak.php
done
```

## Remaining qualification

After the completion path is bounded, repeat around the 4/5 MiB and 8,000/10,000-event guidance with mixed signals, timers, activities, continuations, external payloads, worker restarts, retention cleanup, and MySQL/PostgreSQL backends. Measure replay latency, task latency, worker and Server memory, database growth, result integrity, and acknowledged-work survival. Run published PHP/Python/Rust conformance before recommending limits.
