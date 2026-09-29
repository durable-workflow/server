# Server #237 published long-history signal probe, 2026-09-28

This is a local qualification slice with synthetic workflows and credentials. It exercises signal admission, a cold SDK worker restart, replay, workflow-task completion, exact result, and paginated history. Later runs add activities, timers, continue-as-new, and recovery from an expired activity lease. Published-image MySQL and PostgreSQL mixed runs, plus one external-payload signal run, are below. A later [published-artifact MySQL side-effect slice](../server-237-20260929/README.md) completed at 7,648, 8,101 and 10,010 events. Retention cleanup, backend interruption and mixed-history qualification at those event counts remain open.

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

## Published Server 2.4.22 MySQL mixed run

A fresh isolated Compose stack used the unmodified published `durableworkflow/server:2.4.22@sha256:c0152bf71b163b047dea9ecba2ff79ef1f5f081815d79003474d34107453bb9e` multiarchitecture image. Its amd64 child was `sha256:dde901fec94ef9f1167ba16f32a0e07676e07fd2f07d8d8bc865abbf39e89c20`, with Workflow 2.2.16 embedded at dist commit `f91bd13856d9258dd04bafa90b9f6eb8d0b67cb7`. The published Python SDK was 2.3.5 in probe image `sha256:1a6bae0f436328d397064f2db45c64096d725afff30a748493311feeab2be623`. MySQL and Redis used the pinned digests above. The four-core i5-6500 host, 15 GiB RAM, one-CPU/one-GiB probe limit, 128 MiB Server PHP limit, eight concurrent signal requests, eight activity/timer boundaries, continuation, and 1,800-second worker window matched the prior mixed shape. Server service containers used the published Compose defaults without CPU or memory caps. The task-local project was `server-2422-history-20260928`, with HTTP bound to loopback port 18240. No candidate source was mounted.

Run the published Compose, probe and digest overlays shown above with `DW_SERVER_IMAGE` set to that exact index, `DW_SERVER_TAG=2.4.22`, a fresh project and synthetic credentials. Build `probe` with `PYTHON_SDK_VERSION=2.3.5`. The measured command was:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=pub-2422-mixed-20260928-a \
  -e PROBE_SIGNAL_CONCURRENCY=8 \
  -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=1800 \
  probe /probe/signal_history_probe.py 4000
```

The [raw verifier log](published-2422-mysql-mixed-4000.log) reports all 4,000 signals acknowledged in 363.19 seconds with signal API p50/p95/p99 of 0.417/0.757/0.824 seconds. The [durable event timestamps](published-2422-mysql-offer-span.tsv) span 13:51:17.404567–13:57:20.483958 UTC. The resumed SDK worker completed in 448.32 seconds and reported peak process RSS of 129,332 KiB. It verified exact result `{"count":4000,"total":7998000}`, 4,048 ordered events over six pages and two completed runs, eight activity starts/completions, eight timers, one continuation, and no activity retry. The [initial](published-2422-mysql-initial-snapshot.json) and [successor](published-2422-mysql-successor-snapshot.json) snapshots show matching 4,046/4,046 and 2/2 history/timeline rows, no open tasks or rejected signals, and an initial 5,476,237 history bytes with `continue_as_new_recommended`. Signal-record statuses remain 3,999 `received` and one `applied`; the exact history and result checks pass, while those record statuses still need semantic review. [Final backlog](published-2422-mysql-final-backlog.tsv) shows zero queued jobs, failed jobs, open tasks, and open runs.

| Completed task created after the last signal | Count | p50 / p95 / p99 lifetime |
| --- | ---: | ---: |
| Workflow | 17 | 8.96 / 20.12 / 20.12 s |
| Activity | 8 | 15.43 / 18.68 / 18.68 s |
| Timer | 8 | 9.07 / 9.61 / 9.61 s |

These nearest-rank values come from the [task rows](published-2422-mysql-post-offer-task-timing.tsv) and [summary](published-2422-mysql-post-offer-task-summary.tsv), using `updated_at - created_at` on completed tasks. They cover task lifetime, not isolated handler latency. Ten ordinary authenticated API calls during offers and ten during worker processing all returned HTTP 200, with p95 of [0.159](published-2422-mysql-api-during-offers.json) and [0.118](published-2422-mysql-api-during-worker.json) seconds. These small samples do not characterize an API latency distribution.

The [55 point samples per service](published-2422-mysql-container-stats.psv) run from 13:54:16 to 14:05:33 UTC. Their [summary](published-2422-mysql-container-stats-summary.json) gives observed maxima of 187.7 MiB for HTTP, 124.3 MiB for the queue worker, 88.15 MiB for scheduler, 661.0 MiB for MySQL, and 27.08 MiB for Redis. Sampling started after signal offers began and does not establish true peaks or steady-state memory. The [container outcome](published-2422-mysql-container-outcome.tsv) shows all five services healthy at completion, with zero Docker restarts or OOM flags. Swap-in/out counters did not move between the [mid-offer](published-2422-mysql-swap-mid-offer.txt) and [final](published-2422-mysql-swap-final.txt) samples. The [MySQL volume](published-2422-mysql-volume-final-bytes.txt) held 383,760,505 physical bytes at the end, including database files; without a before snapshot this is not a growth estimate. The isolated containers, volumes, and network were removed after evidence capture and verified absent.

This is one published-image repetition on MySQL. It supports the exact mixed-run correctness result. It does not yet give a replicated performance estimate or cover the remaining durability boundaries.

## Published Server 2.4.22 PostgreSQL mixed run

Another fresh isolated stack used the same unmodified Server 2.4.22 image index, embedded Workflow 2.2.16, published Python SDK 2.3.5, Redis digest, host, PHP 128 MiB limit, signal concurrency, activity/timer spacing, continuation and 1,800-second worker window. The published-SDK probe image ID was `sha256:c6b648ece6b55551724e865b7b5e5e9023e25b71f5caeb55840c19a9bc22ed2e`. The SQL backend was PostgreSQL 16.15 at the pinned `postgres@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea`. Only the probe had explicit one-CPU/one-GiB limits; other services used Compose defaults. No candidate source was mounted.

Use a fresh synthetic `stack.env` as above, with project `server-2422-pg-history-20260928` and an unused loopback port. Add `postgresql.compose.yml` after the three published Compose files to replace the database dependency. Build `probe` with `PYTHON_SDK_VERSION=2.3.5`, then run:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=pub-2422-pg-mixed-20260928-a \
  -e PROBE_SIGNAL_CONCURRENCY=8 \
  -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=1800 \
  probe /probe/signal_history_probe.py 4000
```

The [SDK verifier](published-2422-pg-mixed-4000.log) acknowledged 4,000 signals in 347.23 seconds; signal API p50/p95/p99 was 0.415/0.743/0.900 seconds. The [durable event span](published-2422-pg-offer-span.tsv) was 14:29:21.748686–14:35:08.815459 UTC. The cold worker completed in 515.35 seconds and reported peak process RSS of 142,876 KiB. It verified exact result `{"count":4000,"total":7998000}`, 4,048 ordered events over six history pages and two runs, eight activity starts/completions, eight timers, one continuation and no retry. The [initial](published-2422-pg-initial-snapshot.json) and [successor](published-2422-pg-successor-snapshot.json) snapshots confirm completed runs with matching 4,046/4,046 and 2/2 history/timeline rows. The initial summary recorded 5,501,314 history bytes and recommended continue-as-new. Signal-record statuses remain 3,999 `received` and one `applied`, as on MySQL; that semantic question remains open. [Final backlog](published-2422-pg-final-backlog.tsv) has zero queued/failed jobs, open tasks and nonterminal runs.

| Completed task created after the last signal | Count | p50 / p95 / p99 lifetime |
| --- | ---: | ---: |
| Workflow | 17 | 10.64 / 24.61 / 24.61 s |
| Activity | 8 | 17.73 / 21.70 / 21.70 s |
| Timer | 8 | 8.62 / 9.12 / 9.12 s |

These nearest-rank values use `updated_at - created_at` on the completed [task rows](published-2422-pg-post-offer-task-timing.tsv); the [summary](published-2422-pg-post-offer-task-summary.tsv) is a calculation of those rows, not isolated handler latency. Ten ordinary authenticated API calls during offers and ten during worker processing all returned HTTP 200, with p95 [0.169](published-2422-pg-api-during-offers.json) and [0.147](published-2422-pg-api-during-worker.json) seconds. The small samples do not characterize the full latency distribution.

PostgreSQL `pg_database_size` rose from [12,639,255](published-2422-pg-database-before-bytes.txt) bytes before the workload to [40,811,543](published-2422-pg-database-after-offers-bytes.txt) bytes after signal offers and [41,573,399](published-2422-pg-database-final-bytes.txt) bytes after completion, a final increase of 28,934,144 bytes including indexes and database overhead. The [75 point samples per service](published-2422-pg-container-stats.psv), collected from 14:29:09 to 14:44:00 UTC, have observed memory maxima in the [summary](published-2422-pg-container-stats-summary.json) of 211.4 MiB HTTP, 109.4 MiB queue worker, 102.6 MiB scheduler, 86.14 MiB PostgreSQL and 21.65 MiB Redis. These samples are not true peaks or steady-state estimates. The [final container outcome](published-2422-pg-container-outcome.tsv) shows all five services healthy with zero Docker restarts or OOM flags. Host [swap counters](published-2422-pg-swap-before.txt) did not increase by the [final sample](published-2422-pg-swap-final.txt).

The rollback-only [full timeline diagnostic](published-2422-pg-timestamp-dirty-full.json) on the completed initial run visited all 4,046 entries and found **zero** `recorded_at` updates. The [initial snapshot after the diagnostic](published-2422-pg-initial-snapshot-after-diagnostic.json) was byte-identical to the one before it. This verifies that the published Workflow 2.2.16/Server 2.4.22 tuple avoids the 413 redundant PostgreSQL timestamp writes observed on the candidate-source pre-fix history above. The isolated containers, volumes, network and probe image were removed and verified absent. This is one published-image PostgreSQL repetition, not a cross-database performance comparison.

## Published PHP, Python and Rust replay conformance

The Server repository's `scripts/conformance/replay-published-artifacts.sh` ran in an ephemeral Python 3.12/Docker CLI container as UID/GID 1000:1000 with the host Docker socket and network. The exact pinned tuple was Server 2.4.22 at `durableworkflow/server@sha256:c0152bf71b163b047dea9ecba2ff79ef1f5f081815d79003474d34107453bb9e`, Workflow 2.2.16, PHP SDK 2.1.5, Python SDK 2.3.5, Rust SDK 2.1.1, CLI 2.1.2 and Waterline 2.0.7. Set `DW_SERVER_IMAGE`, `DW_SERVER_VERSION`, `DW_WORKFLOW_PHP_VERSION`, `DW_PHP_SDK_VERSION`, `DW_PYTHON_SDK_VERSION`, `DW_RUST_SDK_VERSION`, `DW_CLI_VERSION` and `DW_WATERLINE_VERSION` to those values, then run:

```bash
bash scripts/conformance/replay-published-artifacts.sh \
  --result-dir /path/to/isolated/replay-result
```

The [merged result](published-2422-replay-replay-conformance-result.json) reports `pass`: all 31 scenario results passed, all three runtime shards exited zero, and there were no findings. This includes 11 completed-history replay scenarios and 13 worker-restart replay scenarios. The [PHP](published-2422-replay-php-replay-shard.json), [Python](published-2422-replay-python-replay-shard.json) and [Rust](published-2422-replay-rust-replay-shard.json) shard reports retain their detailed observations. The [execution record](published-2422-replay-replay-conformance-record.json), [distribution identities](published-2422-replay-executed-distribution-identities.json) and [pins](published-2422-replay-pins.json) identify the exact published artifacts. The runner removed its isolated containers, volumes and network after completion. This replay matrix is a service conformance slice, not the full protocol catalog.

## Published Server 2.4.23 external-payload signal run

An isolated MySQL stack used the unmodified published `durableworkflow/server@sha256:33c59071952ab08d5b4be7c20862d53cc21e5aed56564ff8718a40b5f239f400` multiarchitecture image, with Workflow 2.2.17 embedded. The probe used published Python SDK 2.3.5 in image `sha256:d0af697717a60a7a106c9a26c9dc0d77cec23fc0d9281f605382a09d34e42ab5`. MySQL and Redis used the pinned digests in `digests.compose.yml`. The four-core i5-6500 host, 15 GiB RAM, Server PHP 128 MiB limit and one-CPU/one-GiB probe limit matched the earlier runs. Service containers used the published Compose defaults without CPU or memory caps. The only new service configuration was a disposable shared local payload directory mounted into HTTP, queue and scheduler containers. This is a single-node local-storage test, not a multi-node storage qualification.

The [Compose overlay](external-local.compose.yml) requires `PROBE_EXTERNAL_PAYLOAD_ROOT` in the task-local `stack.env`, alongside a synthetic `DW_ADMIN_TOKEN`. Create that root outside the repository evidence directory, grant the Server processes write access, and set a unique project and loopback port. Use the published Compose, probe and digest files above, add the external-local overlay last, and build the probe with `PYTHON_SDK_VERSION=2.3.5`. The probe sets the `default` namespace's local external storage threshold to 1 KiB using the admin token, then uses separate operator and worker tokens for the workload:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/external-local.compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=pub-2423-ext-mixed-20260928-a \
  -e PROBE_SIGNAL_CONCURRENCY=8 \
  -e PROBE_SIGNAL_PAYLOAD_BYTES=2048 \
  -e PROBE_MIXED_INTERVAL=250 \
  -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=900 \
  probe /probe/signal_history_probe.py 1000
```

The [SDK result](published-2423-mysql-external-mixed-1000.log) acknowledged all 1,000 signals in 113.91 seconds; signal API p50/p95/p99 was 0.596/0.936/1.069 seconds. The first SDK worker stopped before offers. A fresh worker finished in 76.58 seconds with reported process peak RSS 133,280 KiB. It verified exact result `{"count":1000,"total":499500}`, 1,028 ordered events across the [initial](published-2423-mysql-external-initial-snapshot.json) and [successor](published-2423-mysql-external-successor-snapshot.json) runs, four activities and timers, and one continuation. Both runs and their timeline projections completed, with [zero final backlog](published-2423-mysql-external-final-db.tsv). Sampled [container stats](published-2423-mysql-external-container-stats.tsv) and [outcome](published-2423-mysql-external-container-outcome.psv) are raw observations, not true memory peaks.

The run found a storage defect. The namespace stored [1,000 external objects](published-2423-mysql-external-object-count.txt), occupying [2,756,000 bytes](published-2423-mysql-external-payload-disk-bytes.tsv), and the registry marked all 1,000 retained. Yet all 1,000 signal records kept inline 2,756,000-byte encoded arguments, none contained an external-storage reference, and none of the 1,000 `SignalReceived` history rows contained an external-payload reference. The 1,026 initial-run history JSON rows totaled 6,913,406 bytes, and its summary reported 6,856,490 history bytes with `continue_as_new_recommended=true`. The [read-only SQL counts](published-2423-mysql-external-final-db.tsv) came from this disposable stack. The external upload succeeded, but the durable signal path copied the payload into the database. [Server #262](https://github.com/durable-workflow/server/issues/262) owns the fix and published-artifact repeat. These numbers are one diagnostic run, not a capacity or comparative-throughput estimate.

## Published Server 2.4.24 external-payload signal repeat

The [Server 2.4.24 release workflow](https://github.com/durable-workflow/server/actions/runs/36474971173) published and verified Docker Hub and GHCR images at multiarchitecture digest `sha256:da7f9d00e513d16c1f4ed3ee28f4d5a2039b535ecb0d486092c8181b1443dc5c`, with Workflow 2.2.18 embedded, and Helm chart 0.1.120. The [architecture manifests](published-2424-image-platforms.json) are `sha256:76461d6e6fb72f73671a43c0ccbebf7131c79f3cdef283bb94b48e6b1d14bfad` for amd64 and `sha256:cfdb97a6573943de474973e34b926baede41ed5a2382a737c07dc169a7e75547` for arm64. The release job also passed bare-image readiness, source-free Compose bootstrap, protocol-catalog convergence and clean chart install.

The isolated stack used the same four-core host, published Python SDK 2.3.5 probe image, pinned MySQL/Redis digests, 1 KiB local external-storage threshold and 1,000 distinct 2 KiB signal inputs as the 2.4.23 diagnostic above. The `stack.env` image was the exact digest. The command above was repeated with `PROBE_RUN_ID=pub-2424-ext-mixed-20260928-a`, and the same concurrency 8, four activity/timer boundaries, continuation and 900-second cold worker window. The [SDK result](published-2424-mysql-external-mixed-1000.log) verified exact result `{"count":1000,"total":499500}`, 1,028 ordered history events across the [initial](published-2424-mysql-external-initial-snapshot.json) and [successor](published-2424-mysql-external-successor-snapshot.json) runs, and zero activity retries. Both runs and timeline projections completed. The [read-only durable summary](published-2424-mysql-external-initial-summary.json) reports zero open tasks, queued jobs and failed jobs. The server access log recorded zero HTTP 4xx/5xx during this workload.

| Single-run observation | Server 2.4.23 / Workflow 2.2.17 | Server 2.4.24 / Workflow 2.2.18 |
| --- | ---: | ---: |
| Signal offer time | 113.91 s | 113.09 s |
| Signal API p50 / p95 / p99 | 0.596 / 0.936 / 1.069 s | 0.624 / 0.926 / 1.038 s |
| Cold worker to completion | 76.58 s | 772.24 s |
| SDK worker peak RSS | 133,280 KiB | 97,656 KiB |
| Initial-run summary history bytes | 6,856,490 | 1,930,613 |
| Initial-run raw history payload bytes | 6,913,406 | 2,009,561 |
| Retained object count / encoded bytes | 1,000 / 2,756,000 | 1,000 / 2,756,000 |

The fixed durable path kept all 1,000 signal arguments, accepted signal commands and `SignalReceived` history payloads as external references. It verified all 1,000 original values byte-for-byte after the cold restart. Stored signal argument and command payload columns each totaled 475,000 bytes. The 1,000 physical objects totaled 2,756,000 bytes. Initial-run history-summary size fell by 71.8% from the earlier diagnostic, below the continue-as-new size recommendation. These are one diagnostic repetition per version, so offer latency and memory differences are observations rather than capacity estimates.

The space saving exposed a replay cost in the published Python SDK. The compressed [raw payload GET lines](published-2424-mysql-external-payload-gets.log.gz) contain exactly 10,000 successful GETs for the 1,000 objects across repeated task replays; the [task completions](published-2424-mysql-external-task-completions.log) show progress through all boundaries. Python SDK 2.3.5's default verified payload cache holds 128 entries, so this 1,000-reference workload evicts each pass. The 772-second cold completion is a material operational tradeoff on this fixture. The [container samples](published-2424-mysql-external-container-stats.log), [final sample](published-2424-mysql-external-container-outcome.log) and [one-second swap spot check](published-2424-mysql-external-vmstat-outcome.log) are diagnostic samples, not continuous process-memory or swap measurements.

Published-artifact [replay conformance](published-2424-replay-conformance-result.json) passed all 31 scenarios with no findings across [PHP](published-2424-replay-php-shard.json), [Python](published-2424-replay-python-shard.json) and [Rust](published-2424-replay-rust-shard.json). Its [record](published-2424-replay-conformance-record.json), [distribution identities](published-2424-replay-executed-distribution-identities.json) and [pins](published-2424-replay-pins.json) identify the exact Server 2.4.24, Workflow 2.2.18, PHP SDK 2.1.5, Python SDK 2.3.5, Rust SDK 2.1.1, CLI 2.1.2 and Waterline 2.0.7 tuple. The focused published [PHP worker/CLI signal cell](published-2424-php-cli-signal-result.json) also passed, with its [record](published-2424-php-cli-signal-record.json). That focused cell does not claim the broad signals/queries property.

The first namespace-retention cleanup on the unmodified published image returned [HTTP 500](published-2424-namespace-delete-http500.log) after approximately 143 seconds when PHP exhausted its 30-second execution budget; all 1,000 objects and namespace rows remained. [Server PR #265](https://github.com/durable-workflow/server/pull/265) fixes the per-reference retained-row scan. A candidate rerun with only its committed source file mounted read-only into the otherwise published Server container deleted all 1,000 objects and related durable rows in [4.03 seconds](candidate-262-namespace-delete-success.log). This is source-candidate evidence, not a published-image verification. The namespace, files, registry and run rows all counted zero afterward. The disposable stack was removed after evidence capture.

## Published Server 2.4.25 namespace cleanup and concurrent-upload finding

The [Server 2.4.25 release workflow](https://github.com/durable-workflow/server/actions/runs/36481523533) published and verified Docker Hub and GHCR images at multiarchitecture index `sha256:4bd97a895175ceaed2dd39705bc0446acc5c330ba60830657d449f875b9f66e5`, with Workflow 2.2.18 embedded, and Helm chart 0.1.121. The [published platform manifests](published-2425-image-platforms.json) are `sha256:c09aab13c86c5eebac85e55c1a2f22e68b9d8594184baf04fe4027158b78488a` for amd64 and `sha256:d5c1389db41b377ebca343c4b0d2184549384c923356b95ffdea46679253c2eb` for arm64. The release job passed bare-image readiness, source-free Compose bootstrap, protocol-catalog convergence and chart publication.

An isolated published-image MySQL/Redis stack used the same pinned database/cache images, four-core host, published Python SDK 2.3.5, eight concurrent signal offers, 1 KiB local external-storage threshold and 1,000 distinct 2 KiB signal inputs. The fixture's `PROBE_OFFER_ONLY=1` mode started a workflow, stopped its first SDK worker, acknowledged the 1,000 signals and kept the run waiting for namespace deletion. This is a retention-cleanup check, not a second worker-completion measurement. The [offer log](published-2425-offer-only-1000.log) reports 113.00 seconds and signal API p50/p95/p99 of 0.623/0.929/1.008 seconds. The [read-only predelete summary](published-2425-predelete-summary.json) reports 1,000 external references each in signal rows, accepted commands and `SignalReceived` events, with all 1,000 original values verified byte-for-byte. The run had one open task, zero queued/failed jobs, [1,000 retained files](published-2425-predelete-physical-count.txt) and [2,756,000 encoded bytes](published-2425-predelete-physical-bytes.txt).

The unmodified published image returned [HTTP 200 in 5.44 seconds](published-2425-namespace-delete.log) when deleting the namespace, reporting 1,000 external objects deleted. The [postdelete database counts](published-2425-postdelete-rows.tsv) and [physical count](published-2425-postdelete-physical-count.txt) were zero for namespaces, runs, signal rows, registry rows and files. The [stack startup](published-2425-stack-start.log) and [cleanup](published-2425-stack-cleanup.log) logs show the disposable project and volumes were removed.

The exact 2.4.25 published-artifact [replay conformance result](published-2425-replay-conformance-result.json) passed all 31 scenarios with zero findings across [PHP](published-2425-replay-php-shard.json), [Python](published-2425-replay-python-shard.json) and [Rust](published-2425-replay-rust-shard.json). Its [record](published-2425-replay-conformance-record.json), [executed distribution identities](published-2425-replay-executed-distribution-identities.json) and [pins](published-2425-replay-pins.json) identify the exact Server 2.4.25, Workflow 2.2.18, PHP SDK 2.1.5, Python SDK 2.3.5, Rust SDK 2.1.1, CLI 2.1.2 and Waterline 2.0.7 tuple. The runner removed its isolated containers and volumes.

The [server log](published-2425-server-access.log.gz) also records one retried HTTP 503 during the offer phase. Laravel converted a `mkdir(): File exists` warning in `RuntimeLocalExternalPayloadStorage::commit()` into an exception when parallel uploads raced to create a hash-partition directory. The SDK retried and all 1,000 signals were acknowledged, but a caller without the same retry behavior would see a failed upload. [Server #267](https://github.com/durable-workflow/server/issues/267) owns the concurrent-directory fix and exact published-image repeat. Do not count this run as zero-error ingress evidence.

### Published Python SDK bounded-cache diagnostic

A separate fresh Server 2.4.25 stack repeated the completed 1,000-external-signal, four-activity/four-timer and continue-as-new shape on the same four-core host and published Python SDK 2.3.5. The only SDK setting changed was one verified-payload cache with `max_entries=1024` and the unchanged 16 MiB byte ceiling, shared by the operator client and resumed worker. The first worker was stopped before offers. The [SDK result](published-2425-cache1024-mixed-1000.log) verified exact result `{"count":1000,"total":499500}`, 1,028 ordered events, zero activity retries and worker completion in 408.76 seconds, with 103,448 KiB process peak RSS. Offers took 113.05 seconds and signal API p50/p95/p99 was 0.613/0.928/1.030 seconds. The [initial durable summary](published-2425-cache1024-initial-summary.json) verified all 1,000 original signal values and external references, zero open tasks, queued jobs and failed jobs.

The [request counts](published-2425-cache1024-request-counts.json) and [raw Server log](published-2425-cache1024-server-access.log.gz) show 1,000 successful external uploads, zero external GETs and zero HTTP 4xx/5xx. The earlier published 2.4.24 default-cache run needed 10,000 GETs and 772.24 seconds for worker completion, with 97,656 KiB peak RSS. These are single diagnostic repetitions on adjacent Server patch versions, not a capacity comparison. This cache was warmed by offers in the same SDK client process; a separate fresh worker process would start with an empty cache and must be measured before recommending a new default. The diagnostic shows that the 128-entry default can cause avoidable replay fetches when this process handles 1,000 distinct objects.

Namespace deletion on this completed run returned [HTTP 200 in 4.08 seconds](published-2425-cache1024-namespace-delete.log). The [postdelete rows](published-2425-cache1024-postdelete-rows.tsv) and [physical count](published-2425-cache1024-postdelete-physical-count.txt) were zero. The isolated project's [startup](published-2425-cache1024-stack-start.log) and [cleanup](published-2425-cache1024-stack-cleanup.log) logs show its containers and volumes were removed.

## Published Server 2.4.26 concurrent-upload repeat

The [Server 2.4.26 release workflow](https://github.com/durable-workflow/server/actions/runs/36486663713) published the fix from Server PR #268 to Docker Hub and GHCR at multiarchitecture index `sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, with Workflow 2.2.18 embedded and Helm chart 0.1.122. The [published platform manifests](published-2426-image-platforms.json) are `sha256:ae6afd3601bdafe35728de0807ab105991e71992f0c56f89612aeb70fb05cd80` for amd64 and `sha256:0072b9275391999a22c9ab5c681e796fab8c7a55ad599d1e8b0016a6560bb92b` for arm64. The release job passed full source CI, bare-image readiness, source-free Compose bootstrap, protocol-catalog convergence and clean chart install.

A new isolated MySQL/Redis stack pinned that exact published index, the same database/cache digests and published Python SDK 2.3.5, on the same four-core host. The local external-storage threshold was 1 KiB. The fixture started a workflow, stopped its first worker, then offered 1,000 distinct 2 KiB external-payload signals at concurrency 16 with `PROBE_OFFER_ONLY=1`. The [offer log](published-2426-offer-only.log) reports all 1,000 acknowledged in 104.33 seconds and signal API p50/p95/p99 of 1.044/1.660/1.812 seconds. This run used twice the previous offer concurrency, so those latencies are not a same-load performance comparison.

The [predelete durable summary](published-2426-predelete-summary.json) verified all 1,000 original signal values and counted 1,000 external references each in signal rows, accepted commands and `SignalReceived` events. It found one open task and zero queued or failed jobs. There were [1,000 physical files](published-2426-predelete-physical-count.txt) totaling 2,756,000 encoded bytes. The [request counts](published-2426-request-counts.json) and [raw Server log](published-2426-server-access.log.gz) show 1,000 upload HTTP 201 responses, 1,000 signal HTTP 202 responses, zero external GETs and zero HTTP 4xx/5xx during the offer phase. This is the published-image repeat for the HTTP 503 found on 2.4.25; the fixture did not run a completion worker.

Namespace deletion returned [HTTP 200 in 4.02 seconds](published-2426-namespace-delete.log) and reported all 1,000 external objects deleted. [Postdelete database counts](published-2426-postdelete-rows.tsv) and the [physical count](published-2426-postdelete-physical-count.txt) were zero for namespaces, runs, signal rows, registry rows and files. The isolated project's [startup](published-2426-stack-start.log) and [cleanup](published-2426-stack-cleanup.log) logs show its containers and volumes were removed.

The exact 2.4.26 published-artifact [replay conformance result](published-2426-replay-conformance-result.json) passed all 31 scenarios with zero findings across [PHP](published-2426-php-replay-shard.json), [Python](published-2426-python-replay-shard.json) and [Rust](published-2426-rust-replay-shard.json). Its [record](published-2426-replay-conformance-record.json), [executed distribution identities](published-2426-executed-distribution-identities.json) and [pins](published-2426-pins.json) identify the exact Server 2.4.26, Workflow 2.2.18, PHP SDK 2.1.5, Python SDK 2.3.5, Rust SDK 2.1.1, CLI 2.1.2 and Waterline 2.0.7 tuple. The runner used published distributions, no local product source checkouts, and removed its isolated containers and volumes.

## Published Server 2.4.26 fresh-worker payload-cache comparison

Three new isolated MySQL/Redis stacks ran on the same four-core, 15 GiB x86_64 host against the exact published Server 2.4.26 index `sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e` and published Python SDK 2.3.5. The probe image was `sha256:746054265cdaa0084d276551cfdaa0ad16283010144c4d54f2d5827cb76fe051`, built from the digest-pinned Python 3.12 base and `durable-workflow==2.3.5`. MySQL and Redis used the exact digests in [the existing overlay](digests.compose.yml). The probe had 1 CPU and 1 GiB RAM. Server, queue worker, MySQL, and Redis used the published Compose defaults without CPU or memory caps. The local external-payload threshold was 1 KiB. These were synthetic single-node local-storage runs.

Each offer process started the same workflow shape, stopped its first SDK worker before offers, and acknowledged 1,000 distinct 2 KiB external-payload signals at concurrency eight. It then exited. A **separate new Python process** resumed the pending run with an empty verified-payload cache. The sole replay setting varied was `PROBE_EXTERNAL_CACHE_ENTRIES`: 1,024, SDK default 128, then 1,024 again. All three retained the cache's 16 MiB byte ceiling. The workflow ran an activity and timer after every 250 signals and continued as new once. Run labels were `pub-2426-coldcache-1024-20260929-a`, `pub-2426-coldcache-default-20260929-b`, and `pub-2426-coldcache-1024-20260929-c`; initial run IDs are in the offer logs.

The command sequence for each fresh project was the published Compose, [probe overlay](../server-237-20260927/compose.yml), [digest overlay](digests.compose.yml), and [local-storage overlay](external-local.compose.yml), with a unique project, loopback port, writable isolated payload root, published Server image digest, and synthetic role tokens in `$RUN_ENV`:

```bash
# The probe image was built once with:
docker compose --env-file "$RUN_ENV" -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/external-local.compose.yml \
  build --build-arg PYTHON_SDK_VERSION=2.3.5 probe
docker compose --env-file "$RUN_ENV" -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/external-local.compose.yml \
  up -d --wait server worker scheduler
docker compose --env-file "$RUN_ENV" run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID="$RUN_LABEL" -e PROBE_SIGNAL_CONCURRENCY=8 \
  -e PROBE_SIGNAL_PAYLOAD_BYTES=2048 -e PROBE_MIXED_INTERVAL=250 \
  -e PROBE_CONTINUE_AS_NEW=1 -e PROBE_WORKER_WINDOW_SECONDS=900 \
  -e PROBE_OFFER_ONLY=1 probe /probe/signal_history_probe.py 1000
docker compose --env-file "$RUN_ENV" run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID="$RUN_LABEL" -e PROBE_RESUME_INITIAL_RUN_ID="$INITIAL_RUN_ID" \
  -e PROBE_EXTERNAL_CACHE_ENTRIES="$CACHE_ENTRIES" \
  -e PROBE_SIGNAL_CONCURRENCY=8 -e PROBE_SIGNAL_PAYLOAD_BYTES=2048 \
  -e PROBE_MIXED_INTERVAL=250 -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=900 probe /probe/signal_history_probe.py 1000
docker compose --env-file "$RUN_ENV" down -v --remove-orphans
```

`CACHE_ENTRIES=0` selects the published 128-entry default; the other runs used `1024`. The actual local `COMPOSE_FILE` environment contained those four files, so the `run` commands used the same overlays as `up`. Each run's offer and worker logs retain the exact run IDs and inputs.

| Fresh-worker run | Cache entries | Offer seconds | Resume to completion seconds | External GET 200 | Peak worker RSS KiB |
| --- | ---: | ---: | ---: | ---: | ---: |
| First larger-cache run | 1,024 | 113.53 | 448.78 | 1,000 | 93,792 |
| Published default | 128 | 112.76 | 771.46 | 10,000 | 90,916 |
| Larger-cache recheck | 1,024 | 114.49 | 448.10 | 1,000 | 97,828 |

Every run verified `{"count":1000,"total":499500}`, 1,028 ordered events across three history pages and two completed runs, four completed activities and timers, one continuation, and zero activity retries. Durable snapshots show 19 completed tasks, zero queued or failed jobs, 1,000 retained payload records and 2,756,000 encoded payload bytes in each fresh stack. The initial history summaries were about 1.94 MiB, below the documented 5 MiB continuation advice. Each access log contains exactly 1,000 successful external uploads and 1,000 acknowledged signals, with zero HTTP 4xx/5xx. Worker swap spot checks were zero. The two 1,024-entry completion times differ by 0.68 seconds; the intervening default run took 323 seconds longer than their mean. These times are diagnostic observations on a shared host, not a capacity or service-latency guarantee. The tenfold GET difference directly shows the replay-fetch amplification for this workload.

Raw artifacts for the [first larger-cache run](published-2426-coldcache1024-offer.log): [worker result](published-2426-coldcache1024-worker.log), [request counts](published-2426-coldcache1024-request-counts.json), [access log](published-2426-coldcache1024-server-access.log.gz), [durable state](published-2426-coldcache1024-final-db.tsv), [cleanup](published-2426-coldcache1024-stack-cleanup.log). For the [default run](published-2426-defaultcache128-offer.log): [worker result](published-2426-defaultcache128-worker.log), [request counts](published-2426-defaultcache128-request-counts.json), [access log](published-2426-defaultcache128-server-access.log.gz), [durable state](published-2426-defaultcache128-final-db.tsv), [cleanup](published-2426-defaultcache128-stack-cleanup.log). For the [larger-cache recheck](published-2426-coldcache1024-recheck-offer.log): [worker result](published-2426-coldcache1024-recheck-worker.log), [request counts](published-2426-coldcache1024-recheck-request-counts.json), [access log](published-2426-coldcache1024-recheck-server-access.log.gz), [durable state](published-2426-coldcache1024-recheck-final-db.tsv), [cleanup](published-2426-coldcache1024-recheck-stack-cleanup.log). The three task-local payload directories were removed after the evidence copy.

This supports a focused Python SDK cache-default follow-up. The 16 MiB verified-byte bound must remain in force; broader histories and SDK conformance still need qualification before releasing a change.

## Python SDK #84 candidate default against published Server 2.4.26

Python SDK [PR #85](https://github.com/durable-workflow/sdk-python/pull/85) at `540521e` raises the default verified-payload cache from 128 to 1,024 entries while retaining its 16 MiB byte limit. The code change is commit `fbd99e8`. One isolated fresh MySQL/Redis stack repeated the 1,000-external-signal shape above on the same four-core host and the exact published Server 2.4.26 index. The probe image installed published Python SDK 2.3.5, then mounted candidate `src` read-only at `/candidate/src` and set `PYTHONPATH=/candidate/src` for both the offer and fresh-worker processes. This is candidate-source evidence, not a published SDK-package result.

The [offer process](sdk84-candidate-offer.log) acknowledged all 1,000 signals in 112.76 seconds and exited. A separate worker process started with an empty cache, using the candidate default and the same 1 CPU/1 GiB probe limit. It [completed](sdk84-candidate-worker.log) in 449.66 seconds, verified the exact result `{"count":1000,"total":499500}`, 1,028 ordered events over three pages, four activities and timers, one continuation, and zero retries. Its peak process RSS was 99,804 KiB. The [Apache access log](sdk84-candidate-server-access.log.gz) has 1,000 successful external uploads, 1,000 acknowledged signals, exactly 1,000 external GETs, 11 workflow-task completions, four activity-task completions, and zero HTTP 4xx/5xx. The [durable-state query](sdk84-candidate-final-db.tsv) shows two completed runs, 19 completed tasks, no queued or failed jobs and 1,000 ready payload records. [Container state](sdk84-candidate-container-outcome.txt) shows no OOM or restart.

The probe's original `worker_cache` log line hard-coded 128 for the default case. A separate [candidate module check](sdk84-candidate-cache-default.txt) reports the actual 1,024-entry/16 MiB constructor defaults under the same read-only source mount. The fixture now logs the worker's effective cache limits instead of a hard-coded fallback; the published-package recheck will use this corrected fixture. The candidate's GET count and completion time agree with the two explicit 1,024-entry published-SDK runs above. This one source-overlay run does not establish a released-package result.

To reproduce, use the four-file Compose sequence above with a fresh project and synthetic credentials. Bind the SDK PR's `src` directory read-only into both `probe` runs, set `PYTHONPATH=/candidate/src`, and use `PROBE_EXTERNAL_CACHE_ENTRIES=0` so the SDK constructs its default cache. Set `PROBE_RUN_ID=sdk84-candidate-cold-default-20260929-a`, `PROBE_SIGNAL_CONCURRENCY=8`, `PROBE_SIGNAL_PAYLOAD_BYTES=2048`, `PROBE_MIXED_INTERVAL=250`, `PROBE_CONTINUE_AS_NEW=1`, and `PROBE_WORKER_WINDOW_SECONDS=900`. The first process uses `PROBE_OFFER_ONLY=1`; the second uses `PROBE_RESUME_INITIAL_RUN_ID` from the first log. The Docker build argument for the probe image is `PYTHON_SDK_VERSION=2.3.5`.

## Published Python SDK 2.3.6 fresh-worker verification

The [Python SDK 2.3.6 release Action](https://github.com/durable-workflow/sdk-python/actions/runs/36513302156) passed at exact source commit `2bc2730b334c5dd9599ffa83e13b5880ac2a0d7e`. PyPI published wheel SHA-256 `fe85926a28f41f45842ada2eef84e8411b4f82a40ac06c99a42d5184f7c59541` and source archive SHA-256 `f473d12b9d1690040e04f016ee7739ea698c5a9e84cf7f65cbc9d0539a8f7ca0`. A fresh probe image `sha256:7e775ef7b2ada67dc1b8e4e0a908bfa52200255b5f8f172f4a571335c15f4a37` installed `durable-workflow==2.3.6` from PyPI with no SDK source mount; the [package check](sdk84-published-236-package-check.txt) reports 2.3.6 and its 1,024-entry/16 MiB defaults. The unmodified published Server 2.4.26 index, pinned MySQL/Redis digests, four-core host, 1 CPU/1 GiB probe limit and mixed workload matched the previous runs. This is a single fresh published-package repetition, with separate offer and worker processes.

The [offer](sdk84-published-236-offer.log) acknowledged 1,000 distinct external signals in **113.24 s** and exited. The separate fresh worker [completed](sdk84-published-236-worker.log) in **448.79 s**, with peak process RSS **100,940 KiB**, and verified exact result `{"count":1000,"total":499500}`, **1,028 ordered events over three history pages**, four completed activities and timers, one continuation and zero retries. The corrected fixture logs the effective **1,024-entry** worker cache. The [Apache access log](sdk84-published-236-server-access.log.gz) has 1,000 successful external uploads, 1,000 acknowledged signals, **1,000 external GETs**, 11 successful workflow-task completions, four successful activity-task completions and zero HTTP 4xx/5xx. [Durable state](sdk84-published-236-final-db.tsv) has two completed runs, 19 completed tasks, zero queued or failed jobs and 1,000 ready payload records. [Container state](sdk84-published-236-container-outcome.txt) has no OOM or restart. The isolated stack and volumes were removed after this evidence copy.

The released default avoided the prior published 2.3.5 default's 10,000 GETs under this fixture. Its observed 448.79 s completion matches the two earlier explicit 1,024-entry runs (448.78 and 448.10 s), while the 2.3.5 128-entry run took 771.46 s. These are local single-host diagnostics, not a capacity gain or a general latency guarantee. Python SDK #84 owns the released cache change; the broader history-limit, interruption and cross-database work stays open here.

The Server repository's `scripts/conformance/replay-published-artifacts.sh` ran from `main` commit `63123ba44a918a12ed54ce514bb38a58c2ef3874` in an ephemeral Python 3.12/Docker CLI container as UID/GID 1000:1000. It used the exact published Server 2.4.26 index above, Workflow 2.2.18, PHP SDK 2.1.5, **Python SDK 2.3.6**, Rust SDK 2.1.1, CLI 2.1.2 and Waterline 2.0.7. The [merged conformance result](sdk236-replay-conformance-result.json) reports **31/31 scenarios passed**, zero findings and no blocked cells from 02:54:15 to 02:59:50 UTC. The [Python shard](sdk236-python-replay-shard.json) passed all 16 of its scenarios; [PHP](sdk236-php-replay-shard.json) and [Rust](sdk236-rust-replay-shard.json) shards also passed. [Pins](sdk236-pins.json), [distribution identities](sdk236-executed-distribution-identities.json) and the [execution record](sdk236-replay-conformance-record.json) identify the installed published tuple. The runner used no local product source checkout for its runtime cells. Its [Compose cleanup](sdk236-docker-compose-cleanup.log) removed the isolated Server, MySQL, Redis, network and volumes.

## Remaining qualification

Qualify backend interruption, retention, and mixed histories around and beyond the 8,000/10,000-event guidance on MySQL and PostgreSQL. The [side-effect-only MySQL slice](../server-237-20260929/README.md) verifies three budget states and exact completion on published artifacts. Record active SDK-worker steady memory and repeat latency samples for the broader workloads, then run affected non-replay service conformance. The Python SDK cache-default work has its own owning issue.
