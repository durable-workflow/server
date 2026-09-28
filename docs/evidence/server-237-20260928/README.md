# Server #237 published long-history signal probe, 2026-09-28

This is a local qualification slice with synthetic workflows and credentials. It exercises signal admission, a cold SDK worker restart, replay, workflow-task completion, exact result, and paginated history. A later candidate-source run adds activities, timers, continue-as-new, and recovery from an expired activity lease. PostgreSQL, retention, external payloads, and the 8,000/10,000-event thresholds remain open.

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

## Mixed 4,000-signal continuation and worker recovery

An isolated MySQL run used published Server 2.4.21 (`durableworkflow/server@sha256:3985988df14263102056cfc65cb4a8beb9e4ad2335ee91a38bcea74e214fe807`) with Workflow #568 candidate `141eeb1551b762d44f0d74bacb7260134eec18a9` mounted read-only. The published Python SDK was PyPI 2.3.5. MySQL and Redis used the digests in `digests.compose.yml`. The host, PHP 128 MiB limit, eight concurrent signal offers, and synthetic scoped tokens matched the earlier signal probe. The HTTP Server, scheduler, and queue worker ran together. The probe container had one CPU and 1 GiB; the service containers had no explicit CPU or container-memory cap.

The first Python worker stopped before offers. The probe then acknowledged 4,000 distinct signals over a 441.225-second first-to-last durable event span. A fresh worker scheduled eight activities and eight zero-delay timers, one pair per 500 signals, before continuing as new. That first worker's 900-second processing window expired during the fifth activity. Its five-minute lease expired, and a second published-SDK worker retried the activity and finished the workflow. The first run completed at 09:58:07 UTC, about 34 minutes 50 seconds after the last signal event at 09:23:17 UTC. This includes the worker timeout, lease wait, and scheduler/queue work; it is not replay CPU time.

The [final SDK verification](mixed-4000-final-verification.jsonl) retrieved six ordered history pages across both run IDs and the exact result `{"count":4000,"total":7998000}`. There were 4,000 `SignalReceived`, eight each of `ActivityScheduled`, `ActivityCompleted`, `TimerScheduled`, and `TimerFired`, nine `ActivityStarted` because the fifth activity retried once, one `WorkflowContinuedAsNew`, and one `WorkflowCompleted`. The [initial](mixed-4000-initial-snapshot.json) and [successor](mixed-4000-successor-snapshot.json) durable snapshots show 4,047/4,047 and 2/2 history/timeline rows, respectively, no open tasks, no rejected signals, and no failed jobs. The initial summary size was 5,429,147 bytes with `continue_as_new_recommended=true`. Its signal-record statuses were 3,999 `received` and one `applied`; the history and result checks establish execution, while those record statuses need separate semantic review.

| Completed task, created after offers | Count | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: |
| Workflow | 16 | 15.74 s | 160.00 s | 160.00 s |
| Activity | 8 | 29.61 s | 375.68 s | 375.68 s |
| Timer | 8 | 19.11 s | 26.08 s | 26.08 s |

These are nearest-rank percentiles of task creation-to-completed-status time from the [raw task rows](mixed-4000-task-timing.tsv), not isolated handler latency. The activity tail includes the five-minute lease expiry. The [offer timestamps](mixed-4000-offer-span.tsv) count durable signal events. Per-request signal latency samples were lost when the first probe worker timed out; the fixture now emits them before starting the worker. The [Server request-peak log](mixed-4000-server-php-peaks.log) has a highest PHP allocation of 96,468,992 bytes (92 MiB), below the image's 128 MiB PHP limit. This is PHP allocated memory, not container RSS. The Server and scheduler stayed healthy without Docker OOM or restart. The queue-worker container restarted eight times with no Docker OOM; its last observed exit code was zero and the image uses Laravel `queue:work --memory=128` by default. The exact recycling cause and active SDK-worker peak RSS were not captured.

This run also exposed expensive overlapping projection work. A live MySQL snapshot showed a scheduler transaction with 3,308 rows modified and 4,601 locked while a Server completion transaction waited for a task lock. `TaskWatchdog::recoverExistingTask()` projects full history while holding task/run locks, and `TaskDispatcher::refreshRunSummary()` projects it again after redispatch. [Workflow #573](https://github.com/durable-workflow/workflow/issues/573) owns the bounded repair path. This is a candidate-source qualification result, not a published Workflow-package result or a safe long-history latency limit.

Raw first-worker [timeout](mixed-4000-first-worker.log) and [resumed-worker verifier failure](mixed-4000-resumed-worker.log) logs are retained alongside the final successful verifier. The latter used an older assertion requiring exactly eight activity starts; the durable retry correctly produced nine. The current `signal_history_probe.py` accepts `PROBE_MIXED_INTERVAL=500`, `PROBE_CONTINUE_AS_NEW=1`, `PROBE_WORKER_WINDOW_SECONDS=900` for the first bounded worker, and `PROBE_RESUME_INITIAL_RUN_ID` for a later worker. Set the same `PROBE_RUN_ID` and target on resume; no signals are reoffered. Use the four-file Compose command above plus `candidate-workflow.compose.yml` and a fresh `WORKFLOW_CANDIDATE_ROOT` at the exact Workflow commit. Run each experiment in its own project and remove its volumes afterward.

The unchanged default mode also passed a fresh [10-signal smoke run](mixed-probe-default-smoke.jsonl): exact result 45, 16 ordered events, and no mixed events or continuation.

## Combined bounded-completion and watchdog comparison

A fresh isolated MySQL stack repeated the same 4,000-signal mixed shape with Workflow source commit `29af0519c4fe2b3c2e548bccb1e1fe536fc8f4b3` mounted read-only. That local commit combines Workflow #568 PR #569 commit `141eeb1551b762d44f0d74bacb7260134eec18a9` and Workflow #573 PR #574 commit `573ad8bfd349083fa05309403e3b99582f2f731c`. The Server image remained the published 2.4.21 index `sha256:3985988df14263102056cfc65cb4a8beb9e4ad2335ee91a38bcea74e214fe807`, with published Python SDK 2.3.5, the pinned MySQL 8.0 and Redis 7 digests, PHP 128 MiB, eight signal offers at a time, and the same four-core host. The first worker stopped before offers. The resumed worker had a 1,800-second window so the full mixed sequence could finish without the baseline's 900-second fixture timeout. This is source-overlay evidence, not a published Workflow package result.

The probe [acknowledged all 4,000 signals](mixed-4000-combined.log) in 364.66 seconds. Signal API p50/p95/p99 was 0.418/0.761/0.834 seconds; the [durable event span](mixed-4000-combined-offer-span.tsv) was 364.55 seconds. Worker resume to completion took 1,627.17 seconds. The SDK verified exact result `{"count":4000,"total":7998000}` and 4,048 ordered events over six history pages across the initial and continued runs: 4,000 signals, eight each of activity schedule/start/completion and timer schedule/fire, one continuation, and one terminal completion. There was no activity retry. The [initial](mixed-4000-combined-initial-snapshot.json) and [successor](mixed-4000-combined-successor-snapshot.json) snapshots have 4,046/4,046 and 2/2 history/timeline rows, no open tasks, and no rejected signals. The [final backlog check](mixed-4000-combined-final-backlog.tsv) has zero queued jobs, failed jobs, open tasks, or open runs. The initial summary had 5,500,558 history bytes and recommended continue-as-new. Its signal-record statuses were again 3,999 `received` and one `applied`; exact history/result checks passed, while those statuses need separate semantic review.

| Completed task, created after offers | Count | p50 | p95 | p99 |
| --- | ---: | ---: | ---: | ---: |
| Workflow | 16 | 14.47 s | 169.59 s | 169.59 s |
| Activity | 8 | 28.96 s | 33.82 s | 33.82 s |
| Timer | 8 | 18.41 s | 26.44 s | 26.44 s |

These nearest-rank percentiles come from the [raw task rows](mixed-4000-combined-task-timing.tsv). Workflow p95 remained in the same minutes-long range as the baseline's 160.00 seconds, so this single run does not establish an end-to-end latency gain from the watchdog change. The activity tail differs because the baseline's first SDK worker stopped during the fifth activity and that lease had to expire; this run finished within its longer worker window. The probe's active SDK process reported peak RSS 140,692 KiB. The [HTTP completion log](mixed-4000-combined-server-timing.log) has a highest PHP allocation of 94,371,840 bytes (90 MiB), below PHP's 128 MiB limit. The [sampled container statistics](mixed-4000-combined-container-stats.tsv) are point observations, not peak or steady-memory estimates. The [container outcome](mixed-4000-combined-container-outcome.txt) shows seven queue-worker restarts and zero Docker OOM flags; the Server and scheduler did not restart. No Server fatal or HTTP 5xx matched the filtered log check.

The delayed path is now more specific than the watchdog repair alone. Docker log-write timestamps in the [activity-completion access extract](mixed-4000-combined-server-timing.log) are roughly 100–159 seconds after Apache's request-start timestamps for individual activity completions. A [live processlist sample](mixed-4000-combined-projection-processlist.tsv) caught both Server HTTP and scheduler connections updating old `workflow_run_timeline_entries` rows. `ActivityOutcomeRecorder` calls full history projection inside its completion transaction after creating the resume task. A rollback-only [100-entry diagnostic](timeline_dirty_probe.php) against the completed run found [100 updates](mixed-4000-combined-timeline-dirty-result.json), all dirty only in `payload`, while all 100 before/after decoded JSON values were semantically equal. Workflow #575 and PR #576 own that distinct redundant-write path. The diagnostic changed no durable rows.

For reproduction, use a fresh stack with the same five Compose files described above, a read-only `WORKFLOW_CANDIDATE_ROOT` at the combined source commit, and a unique project and `PROBE_RUN_ID`. The exact probe flags were `PROBE_SIGNAL_CONCURRENCY=8`, `PROBE_MIXED_INTERVAL=500`, `PROBE_CONTINUE_AS_NEW=1`, and `PROBE_WORKER_WINDOW_SECONDS=1800`, followed by `probe /probe/signal_history_probe.py 4000`. The raw probe prints signal latencies before worker processing and performs the final SDK result and history checks. Run the diagnostic only on disposable state with `QUALIFICATION_RUN_ID` set to the initial run ID; it rolls back its projection pass.

## Equivalent-JSON timeline-write fix, same mixed shape

A third fresh isolated stack used the same published Server 2.4.21 index, Python SDK 2.3.5, pinned MySQL/Redis images, host, PHP limit, signal fanout, eight activity/timer boundaries, continuation, and 1,800-second worker window. Its read-only Workflow source was local commit `b095759b`, combining the earlier `29af0519` source with Workflow #575 PR #576's equivalent-JSON comparison. The #575 change in this combined source matches final PR head `896a788d` apart from the repository's array-formatting rule; the combined source also contains PRs #569 and #574. The full target-branch matrix ran at exact PR head `896a788d`. Both probe images contained the same SHA-256 `ee6ce27a73dad29be5d2e953d68441714bf8a5e934d1461db9b77062bdf85248` for `signal_history_probe.py` and the same published Python SDK 2.3.5. These remain candidate-source results.

The [fixed-run verifier](mixed-4000-jsonfix.log) acknowledged 4,000 signals in 362.57 seconds versus 364.66 seconds in the preceding run; per-signal API p50/p95/p99 was 0.415/0.758/0.834 seconds versus 0.418/0.761/0.834 seconds. The [durable offer span](mixed-4000-jsonfix-offer-span.tsv) was 362.47 seconds. The SDK verified exact result `{"count":4000,"total":7998000}`, 4,048 ordered events across six pages and two runs, eight activity starts/completions, eight timers, one continuation, and no retry. The [initial](mixed-4000-jsonfix-initial-snapshot.json) and [successor](mixed-4000-jsonfix-successor-snapshot.json) snapshots show matching 4,046/4,046 and 2/2 history/timeline rows. The [final backlog](mixed-4000-jsonfix-final-backlog.tsv) has zero queued jobs, failed jobs, open tasks and open runs. The initial summary had 5,492,487 history bytes and recommended continue-as-new. Signal-record statuses were still 3,999 `received` and one `applied`, as in the previous run.

| Completed task, created after offers | Count | Before p50/p95/p99 | Fixed p50/p95/p99 |
| --- | ---: | ---: | ---: |
| Workflow | 16 | 14.47 / 169.59 / 169.59 s | 8.91 / 20.15 / 20.15 s |
| Activity | 8 | 28.96 / 33.82 / 33.82 s | 15.56 / 16.78 / 16.78 s |
| Timer | 8 | 18.41 / 26.44 / 26.44 s | 9.04 / 9.78 / 9.78 s |

Nearest-rank task times come from the [fixed raw rows](mixed-4000-jsonfix-task-timing.tsv) and the preceding run's task rows. Worker resume to terminal was 445.05 seconds versus 1,627.17 seconds, about 3.66 times faster in this pair. The [Server access extract](mixed-4000-jsonfix-server-timing.log) puts individual activity-completion responses about 8–9 seconds after Apache request start, versus roughly 100–159 seconds before. A rollback-only repeat of the [100-entry timeline diagnostic](mixed-4000-jsonfix-timeline-dirty-result.json) found zero updates after the fix, versus 100 semantically unchanged `payload` updates before. This direct write result explains the large latency difference, but these are single-run observations rather than repeated capacity measurements.

The fixed probe reported SDK worker peak RSS 142,104 KiB versus 140,692 KiB before. Highest logged PHP workflow-completion allocation was 94,371,840 bytes (90 MiB) in both runs. [Container samples](mixed-4000-jsonfix-container-stats.tsv) do not establish peak or steady memory. The [container outcome](mixed-4000-jsonfix-container-outcome.txt) had zero OOM flags and zero queue-worker restarts, versus seven worker restarts before; the cause of that difference is not established. Host [swap-in/out counters](mixed-4000-jsonfix-swap-counters.txt) did not increase across the sampled 11:18–11:25 UTC worker interval. Ten authenticated ordinary `/api/cluster/info` calls during processing all returned HTTP 200 with p95 0.166 seconds; the [raw sample](mixed-4000-jsonfix-api-latency.json) and [small probe](api_latency_probe.py) are retained. There is no matched pre-fix ordinary-API sample.

The probe command was identical to the previous mixed run apart from a unique project, port, run ID, and `WORKFLOW_CANDIDATE_ROOT` at `b095759b`; the full flag set is in the preceding section. PostgreSQL, external payloads, retention cleanup, 8,000/10,000-event guidance, and published-package qualification remain open.

After this candidate-source experiment, Workflow PR #576 merged and Workflow 2.2.15 was [published and verified](https://github.com/durable-workflow/workflow/releases/tag/2.2.15). The published Server 2.4.21 image still embeds Workflow 2.2.13. These raw results do not test the 2.2.15 package or a Server image carrying it.

## PostgreSQL mixed run and timestamp normalization finding

A fresh isolated stack used the same published Server 2.4.21 image index, published Python SDK 2.3.5, Redis digest, four-core host, PHP 128 MiB setting, signal concurrency eight, mixed interval 500, continuation and 1,800-second worker window. The read-only Workflow source was commit `1a95bbbe74bd88f4739e79047eb8fbaaac7b8941`, combining the bounded completion, watchdog repair and equivalent-JSON fixes. Its SQL backend was PostgreSQL 16.15, pinned as `postgres@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea`. HTTP, queue worker, scheduler and bootstrap all used `DB_CONNECTION=pgsql`, `DB_HOST=pgsql` and port 5432. The [Compose overlay](postgresql.compose.yml) replaces MySQL dependencies and leaves that service inactive. The probe image ID was `sha256:770b3540fb638c598015cb81c1c6ad255dc509d88300a348f3f27e0db9810aa0`; its `signal_history_probe.py` SHA-256 remained `ee6ce27a73dad29be5d2e953d68441714bf8a5e934d1461db9b77062bdf85248`. As before, only the probe had explicit limits of one CPU and 1 GiB; service containers used Compose defaults. This is candidate-source evidence, not a published Server result.

The [SDK verifier](mixed-4000-pg.log) acknowledged all 4,000 signals in 345.18 seconds, then verified exact result `{"count":4000,"total":7998000}`, 4,048 ordered events over six pages and two runs, eight activity starts/completions, eight timers, one continuation and no activity retry. Worker resume to terminal was 563.81 seconds. Signal API p50/p95/p99 was 0.411/0.741/0.871 seconds, and the [durable event span](mixed-4000-pg-offer-span.tsv) was 345.03 seconds. These are one-run observations on a different database, not a throughput comparison with MySQL.

The [initial](mixed-4000-pg-initial-snapshot.json) and [successor](mixed-4000-pg-successor-snapshot.json) snapshots have matching 4,046/4,046 and 2/2 history/timeline rows. Both runs are completed. The initial summary recorded 5,420,604 history bytes and `continue_as_new_recommended=true`; its signal records again show 3,999 `received` and one `applied`, which requires separate semantic review. The [final backlog](mixed-4000-pg-final-backlog.tsv) has zero queued/failed jobs, open tasks and open runs. The final PostgreSQL database occupied [48,487,447 bytes](mixed-4000-pg-database-size-bytes.txt), including its schema; no before-size was recorded, so this is not a growth delta. Completed post-offer task p50/p95/p99 was 10.23/29.96/29.96 seconds for 17 workflow tasks, 18.55/22.44/22.44 for eight activities, and 9.17/10.54/10.54 for eight timers, from [raw rows](mixed-4000-pg-task-timing.tsv) and [summary](mixed-4000-pg-task-summary.tsv).

The highest logged [PHP completion allocation](mixed-4000-pg-server-timing.log) was 94,371,840 bytes (90 MiB); the SDK verifier reported peak RSS 131,548 KiB. [Forty-eight container point samples](mixed-4000-pg-container-stats.log) do not establish peak or steady container memory. The [container outcome](mixed-4000-pg-container-outcome.txt) shows no OOM flag or restart in the Server, queue worker, scheduler, PostgreSQL or Redis. Host [swap counters](mixed-4000-pg-swap-counters.txt) did not change between 12:06:59 and 12:20:43 UTC. Ten authenticated ordinary API calls during signal offers and ten during worker processing all returned HTTP 200; their p95 was [0.179 seconds](mixed-4000-pg-api-during-offers.json) and [0.147 seconds](mixed-4000-pg-api-during-worker.json). Neither ten-call sample establishes an API latency distribution.

A rollback-only [100-entry diagnostic](mixed-4000-pg-timeline-dirty-100.json) found 11 old timeline rows would still receive an update, all dirty only in `recorded_at`. The [full 4,046-entry diagnostic](mixed-4000-pg-timestamp-dirty-full.json) counted 413 such writes. PostgreSQL returns a stored microsecond timestamp such as `12:05:02.53999`, while the UTC timestamp cast supplies the equivalent six-digit value `12:05:02.539990`; Eloquent marks the raw strings unequal. The [diagnostic source](timestamp_dirty_probe.php) runs inside a transaction and rolls back. This is distinct from the JSON payload normalization fixed in Workflow 2.2.15. Workflow #575 owns the follow-up. The completed result and final backlog remain correct.

Workflow [PR #578](https://github.com/durable-workflow/workflow/pull/578) compares the stored and projected UTC microsecond timestamp before filling an existing row. On the same disposable PostgreSQL history and same published Server image, the Server container alone was recreated with read-only Workflow source at `fd1792fee73e00ead316acdfc263ba23a15ce0aa`. Its rollback-only [full diagnostic](mixed-4000-pg-timestamp-after-578.json) then found zero `recorded_at` updates across the same 4,046 entries. The worker and scheduler remained idle on the prior source; neither participated in this diagnostic. The Server was healthy with no restart. The isolated project, volumes and task-owned probe image were removed afterward. This checks the specific timestamp rewrite on candidate source, not published-package behavior or comparative throughput.

To reproduce, use `docker-compose.published.yml`, `../server-237-20260927/compose.yml`, `digests.compose.yml`, `candidate-workflow.compose.yml`, and finally `postgresql.compose.yml` in that order. Set a fresh project, port and source worktree at `1a95bbbe`, build the published-SDK probe, then run `probe /probe/signal_history_probe.py 4000` with `PROBE_RUN_ID=pg-4000-20260928-a`, `PROBE_SIGNAL_CONCURRENCY=8`, `PROBE_MIXED_INTERVAL=500`, `PROBE_CONTINUE_AS_NEW=1` and `PROBE_WORKER_WINDOW_SECONDS=1800`. The timestamp diagnostic accepts `QUALIFICATION_RUN_ID` and `QUALIFICATION_ENTRY_LIMIT=0` for the complete run and must be used only on disposable state.

## Remaining qualification

Repeat the PostgreSQL timestamp check on a published package and image. Qualify external payloads, retention cleanup, backend interruption, and around/beyond the 8,000/10,000-event guidance. Record active SDK-worker steady memory and repeat latency observations under the same load. Run the published PHP/Python/Rust conformance tuple and repeat final limit checks on published packages and images before recommending guidance.
