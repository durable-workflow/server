# Mixed long-history poll failure and recovery, 2026-09-29

This Server #237 diagnostic uses published Server 2.4.26 and published Python SDK 2.3.6 to combine 4,000 signals, 4,000 recorded side effects, eight activity/timer boundaries and a continuation in one workflow. The published Python worker hit an HTTP 500 while polling the next workflow task at 6,529 events. A fresh worker using the bounded-history candidate from [Python SDK #88](https://github.com/durable-workflow/sdk-python/pull/88) recovered the same durable run against the **unchanged published Server image** and completed it.

## Frozen inputs

| Component | Selection |
| --- | --- |
| Server | Published 2.4.26 multiarchitecture index `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, Workflow 2.2.18, PHP `memory_limit=128M` |
| Database/cache | Pinned MySQL `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` and Redis `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c`, from the existing [digest overlay](../server-237-20260928/digests.compose.yml) |
| First worker | PyPI `durable-workflow==2.3.6`, in task-built probe image `sha256:e8d09f83767943f0a4e2ceb2be4532fba6cf780ec62ef05bb8da61761202be95` |
| Recovery worker | Same probe image and fixture, with Python SDK candidate source at commit `658db758d7422cd28a626c97a49ca24be0e3e2ec` mounted read-only as `PYTHONPATH=/candidate/src`. The candidate is source, **not a published package**. |
| Fixture | [`signal_history_probe.py`](../server-237-20260927/signal_history_probe.py) at Server PR #245 commit `a0cc76f736aea38d9b75e6957f3d5f9185b99610`; 4,000 numbered signals, 4,000 deterministic side effects, one activity and zero-delay timer after each 500 side effects, continuation and exact sum check |
| Local limits | Four-core Intel i5-6500, 15 GiB RAM, local Docker, probe container one CPU and 1 GiB, isolated Compose project `dw237mixed0346`, loopback port `18250`, synthetic credentials |

## Procedure

The stack used the published Compose file, the [probe overlay](../server-237-20260927/compose.yml) and the pinned digest overlay above. `RUN_ENV` contained only unique synthetic credentials, the exact Server index, and the isolated project/port. The probe was built from the committed [Dockerfile](../server-237-20260927/Dockerfile.sdk) with Python SDK 2.3.6.

```bash
docker compose --env-file "$RUN_ENV" -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  run --rm --no-deps --entrypoint python \
  -e PROBE_RUN_ID=published236-mixed4000-side4000 \
  -e PROBE_SIGNAL_CONCURRENCY=8 -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_SIDE_EFFECTS=4000 -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=3600 \
  probe /probe/signal_history_probe.py 4000
```

After capturing the failure and stopping all services while preserving the disposable database volume, the same stack was restarted. The recovery command added `--volume "$SDK_CANDIDATE_SRC:/candidate/src:ro"`, `-e PYTHONPATH=/candidate/src`, and `-e PROBE_RESUME_INITIAL_RUN_ID=01m3nmg27rgcw2p5xffmy8hjc3` to the `docker compose run` command above. The resume flag prevented any signal from being offered again. The candidate source changed only worker polling and page-fetch failure behavior; the published Server image, workflow definition, probe image and database remained the same. Both runs used a fresh Python process.

## Results and diagnosis

The [published worker log](published-python236-failure.log) reports 4,000 acknowledged signals in 365.586 seconds, with signal API p50/p95/p99 0.415/0.764/0.831 seconds and no offer error. It later received HTTP 500 from `poll_workflow_task` and exited nonzero. The [failure state](failure-state.tsv) had a waiting run, one ready workflow task, 6,529 history events and 6,869,993 history-summary bytes. It had recorded 2,500 side effects, five completed activities and four fired timers. The [Server log](server.log.gz) records PHP fatal `Allowed memory size of 134217728 bytes exhausted` in `Illuminate/Http/JsonResponse.php:87` at 03:59:49 and 04:01:04–04:01:12 UTC. No Server, queue worker or scheduler container was OOM-killed or restarted. Host `pswpin` and `pswpout` counters were unchanged from worker start to failure.

The Python 2.3.6 worker omitted `history_page_size` in both worker poll paths. Server's poll contract returns the entire history when that field is omitted, and JSON response construction crossed the PHP limit for this mixed history. The Server already supports bounded poll pages and page tokens. Python SDK #88 requests 500-event pages and rejects an incomplete history if a later page fetch fails.

The [candidate recovery log](candidate-python87-recovery.log) reports completion in 274.982 seconds from restart, with an exact `{count: 4000, total: 7998000}` result and 115,576 KiB SDK process peak RSS. The [final durable state](candidate-final-state.tsv) has one continued first run and one completed successor, 8,049 ordered events across both runs, 4,000 `SignalReceived`, 4,000 `SideEffectRecorded`, eight each of `ActivityScheduled`, `ActivityStarted`, `ActivityCompleted`, `TimerScheduled` and `TimerFired`, one `WorkflowContinuedAsNew`, and one `WorkflowCompleted`. There were zero activity retry starts. The resumed Server log has no new PHP fatal after restart.

This is a single reproducible functional failure and recovery, not a throughput comparison. The local source recovery validates the candidate direction; the issue remains open until the SDK change passes review, is released, and the exact published package is retested. PHP and Rust first-party clients also omit the poll page size while supporting later pages, so their long-history behavior needs separate correction and published verification.

## Raw artifacts and cleanup

- [Published worker failure](published-python236-failure.log), [durable state at failure](failure-state.tsv), [Docker resource samples](container-stats-failed.psv)
- [Candidate recovery](candidate-python87-recovery.log), [final durable state](candidate-final-state.tsv), [Server log](server.log.gz)

The task-owned Compose containers, network, MySQL and Redis volumes, and probe image were removed after capture. No paid host or customer state was used.
