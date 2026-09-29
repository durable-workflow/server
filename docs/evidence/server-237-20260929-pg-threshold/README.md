# Published PostgreSQL history-budget slice, 2026-09-29

This bounded Server #237 slice repeats the earlier MySQL side-effect thresholds on PostgreSQL using an unchanged published Server image and published Python SDK 2.3.7. It checks one run below the default 8,000-event warning, one above that warning, and one beyond the 10,000-event continue-as-new recommendation. Each run verifies its exact result, ordered history, activity count, task state and persisted history-budget pressure.

## Frozen inputs

| Component | Selection |
| --- | --- |
| Server | Published Server 2.4.26 index `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, local amd64 image `sha256:70dce06f20ee95f8f35613493100d2ffa927b177e6718b398d3feab66aee3f6a`, embedded Workflow 2.2.18, PHP request `memory_limit=128M` |
| SDK | Published PyPI `durable-workflow==2.3.7` in task-built probe image `sha256:b59f56c1da03f26643ff0b69be0c51b1dc9fd70d555cf73bbab3c17aa5d77c82`; [package version](probe-package.txt), [build log](probe-build.log.gz), and [Dockerfile](../server-237-20260927/Dockerfile.sdk) |
| Database/cache | PostgreSQL 16.15 at `postgres@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea`, Redis `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c`; [version](pg-version.txt) and [Redis version](redis-version.txt) |
| Host / limits | Local four-core Intel i5-6500, 15 GiB RAM, x86-64 Docker; probe limited to one CPU and 1 GiB; Server, queue worker, scheduler, PostgreSQL and Redis used published Compose defaults without explicit container CPU or memory caps |
| Fixture | Published-SDK [history probe](../server-237-20260927/history_probe.py), one deterministic side effect per count, an activity every 500 side effects, unique workflow IDs, synthetic credentials, isolated Compose project `dw237pgthreshold0929` and loopback port 18253 |

The default [history budget](https://github.com/durable-workflow/workflow/blob/main/docs/architecture/history-budget.md) warns at 8,000 events or 4 MiB and recommends continue-as-new at 10,000 events or 5 MiB. This fixture crosses the event and byte thresholds together, so it verifies combined budget states. The final workflow deliberately completes past the recommendation without continuing.

## Reproduction

From the Server checkout, copy [stack.env.example](stack.env.example) into a unique task-owned directory as `RUN_ENV`, change its Compose project and loopback port if needed, then use the pinned published Compose files. The environment contains disposable credentials only.

```bash
docker compose --env-file "$RUN_ENV" -p dw237pgthreshold0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml \
  up -d --wait server worker scheduler
docker compose --env-file "$RUN_ENV" -p dw237pgthreshold0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml \
  build --build-arg PYTHON_SDK_VERSION=2.3.7 probe

# Run serially with unique labels. The actual order was 7600, 8050, 9950.
docker compose --env-file "$RUN_ENV" -p dw237pgthreshold0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml \
  run --rm --no-deps -e PROBE_RUN_ID=published237-pg-event7600 \
  -e PROBE_ACTIVITY_INTERVAL=500 -e PROBE_WORKER_TIMEOUT_SECONDS=3600 probe 7600
# Repeat with count 8050 / 9950 and the matching PROBE_RUN_ID suffix.

docker exec -i dw237pgthreshold0929-pgsql-1 \
  psql -U workflow -d durable_workflow \
  -v workflow_id=history-qualification-side-effects-7600-published237-pg-event7600 \
  -At -f - < docs/evidence/server-237-20260929-pg-threshold/collect-pg-run.sql
docker exec -i --user 1000:1000 \
  -e QUALIFICATION_RUN_ID=01m3nvydvm4vq6ryy7ycvkffj5 \
  dw237pgthreshold0929-server-1 php /dev/stdin \
  < docs/evidence/server-237-20260928/run_snapshot.php
```

The same read-only queries used each run's workflow ID and run ID. PostgreSQL data-directory bytes were sampled before and after every run with `docker exec dw237pgthreshold0929-pgsql-1 du -sb /var/lib/postgresql/data`. A sampler used `docker stats --no-stream` for the probe, Server, worker, scheduler, PostgreSQL and Redis while each probe container existed, recording timestamp, CPU, memory, network and block I/O in the raw PSV files. A Docker sample is an observation, not a continuous peak. The [SQL query](collect-pg-run.sql) records individual task durations and PostgreSQL's exact p50/p95/p99 percentiles.

## Results

| Side effects | Ordered events | History budget bytes | Pressure | Exact sum | Worker time | SDK process peak RSS | Workflow-task created-to-completed p50/p95/p99 |
| ---: | ---: | ---: | --- | ---: | ---: | ---: | --- |
| 7,600 | 7,648 | 4,188,163 | `ok` | 28,876,200 | 295.17 s | 72,192 KiB | 12.591 / 17.118 / 17.589 s, 16 tasks |
| 8,050 | 8,101 | 4,436,432 | `approaching` | 32,397,225 | 325.03 s | 73,776 KiB | 13.140 / 17.497 / 18.733 s, 17 tasks |
| 9,950 | 10,010 | 5,480,547 | `continue_as_new_recommended` | 49,496,275 | 440.55 s | 78,268 KiB | 14.984 / 21.078 / 21.185 s, 20 tasks |

All three published-SDK probes verified their expected arithmetic results and complete ordered history across 8, 9 and 11 pages. The [read-only snapshots](snapshot-7600.json) and SQL outputs show history and timeline row counts equal to the ordered-event counts, with 15, 16 and 19 completed activities respectively. Each task row has one attempt and zero repairs. The [final backlog](final-backlog.tsv) has three completed runs, 103 completed tasks, 25,759 history and timeline rows, zero failed jobs and no open task. The [Server log](server.log.gz) has no HTTP 4xx/5xx or PHP memory fatal. The [container outcome](server-container-outcome.txt) has healthy services with no OOM kill or Docker restart.

| Side effects | Docker samples | Sampled SDK container peak | Sampled HTTP container peak | Sampled PostgreSQL peak | Activity-task created-to-completed p50/p95/p99 |
| ---: | ---: | ---: | ---: | ---: | --- |
| 7,600 | 23 | 57.82 MiB | 132.5 MiB | 74.4 MiB | 5.916 / 9.680 / 9.761 s, 15 tasks |
| 8,050 | 26 | 60.33 MiB | 177.4 MiB | 110.2 MiB | 6.307 / 10.184 / 10.518 s, 16 tasks |
| 9,950 | 35 | 63.47 MiB | 175.4 MiB | 132.3 MiB | 7.375 / 12.551 / 13.586 s, 19 tasks |

Task latency is from creation to final update and includes dispatch and service work. The worker time includes all workflow and activity execution; it is not isolated replay CPU time. The process RSS and sampled container memory are different measurements. These are single functional runs on a shared local host, with earlier run data retained in PostgreSQL, so the times are observations for this tuple rather than a replicated capacity comparison.

### Database growth

PostgreSQL `pg_database_size` was 36,043,799 / 58,833,943 / 86,957,079 bytes after the 7,600 / 8,050 / 9,950 runs. The latter two increments were 22,790,144 and 28,123,136 bytes; they include all data and indexes added between observations, not just the history table. The PostgreSQL data directory grew from 53,032,157 to 110,325,477 bytes during the first run, then to 166,878,949 and 195,337,957 bytes after the next two. Its per-run differences were 57,293,320 / 56,553,472 / 28,459,008 bytes. Data-directory size includes WAL and allocated free pages, so these differences are not per-workflow storage bills. The [SQL results](durable-9950.tsv) retain cumulative relation sizes after the final run.

### Raw artifacts

| Side effects | Probe result | Durable query | Snapshot | Docker samples | Directory before / after |
| ---: | --- | --- | --- | --- | --- |
| 7,600 | [log](probe-7600.log) | [TSV](durable-7600.tsv) | [JSON](snapshot-7600.json) | [PSV](container-stats-7600.psv) | [before](pg-dir-before-7600.tsv) / [after](pg-dir-after-7600.tsv) |
| 8,050 | [log](probe-8050.log) | [TSV](durable-8050.tsv) | [JSON](snapshot-8050.json) | [PSV](container-stats-8050.psv) | [before](pg-dir-before-8050.tsv) / [after](pg-dir-after-8050.tsv) |
| 9,950 | [log](probe-9950.log) | [TSV](durable-9950.tsv) | [JSON](snapshot-9950.json) | [PSV](container-stats-9950.psv) | [before](pg-dir-before-9950.tsv) / [after](pg-dir-after-9950.tsv) |

This PostgreSQL threshold slice complements the [MySQL threshold slice](../server-237-20260929/README.md) on published artifacts. It does not exercise a worker restart during these specific runs, backend interruption, external payload references during retention, or repeat observations. Those remain in Server #237 before a safe operating recommendation.
