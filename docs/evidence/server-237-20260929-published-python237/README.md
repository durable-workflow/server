# Published Python 2.3.7 long-history qualification, 2026-09-29

The exact published Python SDK 2.3.7 package completed the mixed history that failed under published Python 2.3.6. This run used the **same published Server 2.4.26 image** and 128 MiB PHP request memory limit as the [failure and candidate recovery](../server-237-20260929-mixed/README.md). It started a new workflow and worker process with no candidate source mount.

## Frozen inputs

| Component | Selection |
| --- | --- |
| Server | `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`; local amd64 image `sha256:70dce06f20ee95f8f35613493100d2ffa927b177e6718b398d3feab66aee3f6a`; Workflow 2.2.18; PHP `memory_limit=128M` |
| Python SDK | Official PyPI `durable-workflow==2.3.7` wheel `sha256:9f35e692692e69858923f281ee3360eae9ea6f65e8b8795cbab2bfb7b7129617`, installed by [Dockerfile.pypi](Dockerfile.pypi) without a source overlay; task-built amd64 probe image `sha256:1da96ab92b2678c066443a78e594a7bcd67ca0c0387d60c79164f169b461766d` |
| Database/cache | Pinned MySQL `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` and Redis `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` from the [digest overlay](../server-237-20260928/digests.compose.yml) |
| Fixture | [`signal_history_probe.py`](../server-237-20260927/signal_history_probe.py) at Server PR #245 commit `4d89f3d267c9d95b664acb9edb8bcdd05a2b63de`; 4,000 numbered signals, 4,000 recorded side effects, eight activity and timer boundaries, then continue-as-new |
| Limits | Local four-core Intel i5-6500, 15 GiB RAM, isolated Compose project `dw237py2370427`, loopback port 18251, probe one CPU and 1 GiB, synthetic credentials |

## Procedure

Build the probe from this directory's Dockerfile, with the Server checkout as build context. The [build log](probe-build-official-pypi.log) shows the wheel downloaded from official PyPI and installed as 2.3.7. `RUN_ENV` held the exact Server image, project name, loopback port, and synthetic credentials. The Compose stack used the published file, the [probe overlay](../server-237-20260927/compose.yml), and the digest overlay above. No live service or paid host was involved.

```bash
docker build -f docs/evidence/server-237-20260929-published-python237/Dockerfile.pypi \
  -t dw237py2370427-probe .

docker compose --env-file "$RUN_ENV" -p dw237py2370427 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  run --rm --no-deps --name dw237py2370427-probe4000 \
  --entrypoint python \
  -e PROBE_RUN_ID=published237-mixed4000-side4000 \
  -e PROBE_SIGNAL_CONCURRENCY=8 -e PROBE_MIXED_INTERVAL=500 \
  -e PROBE_SIDE_EFFECTS=4000 -e PROBE_CONTINUE_AS_NEW=1 \
  -e PROBE_WORKER_WINDOW_SECONDS=3600 \
  probe /probe/signal_history_probe.py 4000
```

## Observed result

The [probe log](published237-mixed4000-side4000.log) records all 4,000 signals acknowledged in 362.993 seconds, signal API p50/p95/p99 of 0.415/0.760/0.841 seconds, and completion 595.313 seconds after the worker resumed. Its result is exactly `{count: 4000, total: 7998000}`. The Python process peak RSS was 119,624 KiB. [Container samples](container-stats.psv) include 75 probe samples and a highest sampled probe memory use of 100.1 MiB against its 1 GiB limit. These times describe this single bounded functional run.

The [durable state](durable-state.tsv) has two completed runs with contiguous sequences: 8,046 events in the continued first run and two in its completed successor. Across them are 4,000 `SignalReceived`, 4,000 `SideEffectRecorded`, eight each of `ActivityScheduled`, `ActivityStarted`, `ActivityCompleted`, `TimerScheduled` and `TimerFired`, one `WorkflowContinuedAsNew`, and one `WorkflowCompleted`. Activity retry starts were zero and no workflow task remained ready or leased. The MySQL schema directory occupied 144,166,912 bytes at completion; see [raw disk measurement](mysql-schema-bytes.tsv). This run did not include external signal payload bytes; the separate published-artifact payload probe is recorded in the earlier Server #237 evidence.

The [Server log](server.log.gz) has no HTTP 500 or PHP memory fatal for this run. The Server container had no OOM kill or restart; see [container identity](server-container-identity.txt). The older Python 2.3.6 run failed on a poll at 6,529 events. This published 2.3.7 worker crossed that boundary and completed on the unchanged Server image.

## Cleanup and remaining qualification

The [cleanup log](stack-cleanup.log) records removal of the task-owned Compose containers, network and database volumes. The task-built probe image was also removed and the project had no remaining containers, volumes or network. Server #237 still needs the broader PostgreSQL, interruption, retention and repeated-observation matrix. PHP and Rust SDK long-history worker qualifications remain separate from this Python result.
