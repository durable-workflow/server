# Published history-size boundaries and isolated replay time, 2026-09-29

This Server #237 slice tests the serialized-history budget independently of event count on both supported databases and measures replay of an exported near-10,000-event history. It uses the unchanged published Server 2.4.26 image and published Python SDK 2.3.7. The size matrix keeps each run at 1,030 events while moving its frozen side-effect payload across the default 4 MiB warning and 5 MiB continue-as-new recommendation. A separate PostgreSQL run reaches 10,010 events, exports that exact history and times three network-free SDK replays of it.

## Frozen inputs and method

| Input | Selection |
| --- | --- |
| Server | Published multiarchitecture index `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, local amd64 image `sha256:70dce06f20ee95f8f35613493100d2ffa927b177e6718b398d3feab66aee3f6a`, embedded Workflow 2.2.18, PHP request `memory_limit=128M` |
| SDK | Official PyPI `durable-workflow==2.3.7` installed by the committed [Dockerfile](Dockerfile.sdk). The task-built [PostgreSQL size](probe-image.txt), [PostgreSQL event](event-probe-image.txt) and [MySQL](mysql-probe-image.txt) image IDs are retained. The fixture gained an optional activity interval between the first three PostgreSQL runs and the later event run; its default size-run behavior stayed at 100 side effects per boundary. |
| Database/cache | PostgreSQL 16.15 at `postgres@sha256:721873c34ceb9f8d8fc265984940dc982404c105f19ad51be9fdc5970a6080ea`, MySQL 8.4.5 at `mysql@sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6`, Redis 7.2.16 at `redis@sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| Host / limits | Local four-core Intel i5-6500, 15 GiB RAM, x86-64 Docker. SDK probe limited to one CPU and 1 GiB. Server, internal worker, scheduler, database and Redis used published Compose defaults without explicit container limits. Isolated loopback ports 18256 and 18257, synthetic credentials, no customer data or paid host. |
| Workloads | [Fixture](size_replay_probe.py): 1,000 numbered side effects, a completed activity every 100 side effects, and 2,500 / 3,000 / 3,700 repeated padding bytes per recorded value for the size matrix. The event run used 9,950 side effects, no padding and an activity every 500 side effects. All runs had unique workflow IDs and queues. |

The default [history budget](https://github.com/durable-workflow/workflow/blob/main/docs/architecture/history-budget.md) warns at **4,194,304 bytes** and recommends continue-as-new at **5,242,880 bytes**, independently of its 8,000 / 10,000 event thresholds. The size matrix stayed far below those event thresholds. Activity boundaries capped each task-completion request below the Server's 2 MiB request limit. The final workflow in each run deliberately completed so that its frozen history could be exported and replayed.

The [PostgreSQL](stack.env.example) and [MySQL](mysql.env.example) environment examples contain the exact synthetic configuration. From this checkout, start either published stack with the matching Compose files and build `sizeprobe`:

```bash
RUN_ENV=docs/evidence/server-237-20260929-size-replay/stack.env.example
compose=(docker compose --env-file "$RUN_ENV" -p dw237sizereplay0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml \
  -f docs/evidence/server-237-20260929-size-replay/compose.yml)
"${compose[@]}" up -d --wait server worker scheduler
"${compose[@]}" build sizeprobe
"${compose[@]}" run -d --name dw237sizereplay0929-probe-3000 --no-deps \
  -e PROBE_RUN_ID=size3000-bounded \
  -e PROBE_HISTORY_EXPORT_PATH=/tmp/history-3000.json.gz \
  sizeprobe 1000 3000
# After exit 0, capture logs, SQL, snapshot and the history with docker cp.
# Repeat with unique names and labels for padding 2500 and 3700.
"${compose[@]}" run -d --name dw237sizereplay0929-probe-event9950 --no-deps \
  -e PROBE_RUN_ID=event9950-bounded -e PROBE_ACTIVITY_INTERVAL=500 \
  -e PROBE_HISTORY_EXPORT_PATH=/tmp/history-event9950.json.gz \
  sizeprobe 9950 0
"${compose[@]}" down -v --remove-orphans

RUN_ENV=docs/evidence/server-237-20260929-size-replay/mysql.env.example
compose=(docker compose --env-file "$RUN_ENV" -p dw237sizemysql0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260929-size-replay/compose.yml)
"${compose[@]}" up -d --wait server worker scheduler
"${compose[@]}" build sizeprobe
# Run sizeprobe 1000 2500, then 1000 3000, then 1000 3700 with unique
# PROBE_RUN_ID values and container names; collect each before removing it.
"${compose[@]}" down -v --remove-orphans
```

The exact run order was PostgreSQL 3,000 / 2,500 / 3,700 bytes, then PostgreSQL 9,950 events, then MySQL 2,500 / 3,000 / 3,700 bytes. The [PostgreSQL SQL collector](../server-237-20260929-pg-threshold/collect-pg-run.sql), [MySQL collector](../server-237-20260929-repeat/collect-mysql-run.sql) and [read-only Server snapshot extractor](../server-237-20260928/run_snapshot.php) verified budget state, event continuity, task attempts and backlog. `du -sb` measured the database data directory before and after each run. `docker stats --no-stream` sampled SDK, HTTP, worker, scheduler, database and Redis memory and CPU roughly every 10 to 15 seconds while each SDK process existed. Samples can miss transient peaks.

The probe measures **worker time** from starting the SDK worker through terminal completion. It then fetches the exact completed history through the published API in 500-event pages and measures that **history-fetch time**, including network and decoding. Finally, the same published SDK's public `Replayer` replays the already fetched event list three times in process, without HTTP or database calls. Each replay had to produce a `CompleteWorkflow` command with the exact result. These **offline replay times** measure Python reconstruction and deterministic workflow execution for this fixture; they are distinct from worker time and task-created-to-completed latency. The compressed full history exports and [SHA-256 checksums](histories.sha256) allow independent inspection.

## Serialized-size budget results

Every size run finished with **1,030 distinct contiguous events**, nine completed activities, the exact **499,500** result, one attempt per task, zero repairs, zero final open tasks and no failed job. Each exported history replayed three times with the same exact completion.

| Database | Padding bytes | Persisted history bytes | Pressure | Worker seconds | History-fetch seconds | Offline replay seconds, min / median / max | SDK process peak RSS |
| --- | ---: | ---: | --- | ---: | ---: | --- | ---: |
| PostgreSQL | 2,500 | 3,938,468 | `ok` | 38.68 | 0.320 | 0.0410 / 0.0412 / 0.0427 | 63,240 KiB |
| PostgreSQL | 3,000 | 4,602,724 | `approaching` | 39.99 | 0.351 | 0.0425 / 0.0430 / 0.0434 | 64,116 KiB |
| PostgreSQL | 3,700 | 5,538,468 | `continue_as_new_recommended` | 40.24 | 0.383 | 0.0460 / 0.0466 / 0.0471 | 68,892 KiB |
| MySQL | 2,500 | 3,936,354 | `ok` | 38.37 | 0.269 | 0.0405 / 0.0417 / 0.0417 | 63,364 KiB |
| MySQL | 3,000 | 4,600,610 | `approaching` | 37.36 | 0.295 | 0.0424 / 0.0429 / 0.0449 | 63,816 KiB |
| MySQL | 3,700 | 5,536,354 | `continue_as_new_recommended` | 38.43 | 0.319 | 0.0448 / 0.0472 / 0.0507 | 68,464 KiB |

PostgreSQL workflow-task created-to-completed p50/p95/p99 were 2.555/3.240/3.254, 2.487/3.463/3.490 and 2.665/3.411/3.503 seconds at the three padding levels. MySQL nearest-rank p50/p95/p99 were 2.557/3.192/3.192, 2.436/3.312/3.312 and 2.283/3.332/3.332 seconds. The two SQL collectors use different percentile estimators, so these figures are not a database speed ranking. Sampled SDK container-memory peaks were 43.88 / 47.08 / 51.62 MiB on PostgreSQL and 44.30 / 46.86 / 50.93 MiB on MySQL. The process RSS before offline replay is separate from the peak after replay, which each probe log also records.

PostgreSQL `pg_database_size` was 17,103,895 / 20,282,391 / 23,256,087 bytes after its 3,000 / 2,500 / 3,700-byte runs. Its data directory moved from [52,613,321](pg-before.tsv) to [74,274,013](pg-after-3000.tsv), [77,614,821](pg-after-2500.tsv) and [80,670,437](pg-after-3700.tsv) bytes. MySQL's schema directory moved from [11,063,296](mysql-before.tsv) to [39,833,600](mysql-after-2500.tsv), [52,449,280](mysql-after-3000.tsv) and [69,259,264](mysql-after-3700.tsv) bytes. Directory sizes include allocation, indexes and PostgreSQL WAL; they are not a per-workflow storage tariff.

## Near-10,000-event replay

The separate PostgreSQL run had **10,010 contiguous events**, 9,950 side effects, 19 completed activities, persisted history size **5,710,538 bytes**, `continue_as_new_recommended`, exact **49,496,275** result, one attempt per task, zero repairs and no final backlog. Its SDK worker ran for **439.95 seconds** with 80,120 KiB peak process RSS. Fetching the completed history in 21 pages took **2.049 seconds**. Three offline replays took **0.334 / 0.340 / 0.351 seconds** and reproduced the exact completion. The sampled SDK container peak was 65.60 MiB across 35 samples. Workflow-task created-to-completed p50/p95/p99 were **14.461 / 21.837 / 21.888 seconds** over 20 tasks. The database directory grew from [80,670,437](pg-before-event.tsv) to [142,466,789](pg-after-event.tsv) bytes while this run was added; that difference includes PostgreSQL allocation and WAL.

The offline replay measurement isolates the SDK's deterministic work after history is already in memory. It does not explain the entire 439.95-second worker run, which also includes task delivery, repeated HTTP exchanges, side-effect persistence, activities and waits. The [earlier near-threshold repetitions](../server-237-20260929-repeat/README.md) give separate MySQL and PostgreSQL task-latency and worker-time observations for the released SDK. The [mixed-history recovery record](../server-237-20260929-pg-recovery/README.md) verifies worker replacement and payload retention on a long-running run.

### Raw artifacts

| Database / run | Result log | Durable SQL | Server snapshot | Exported full history | Docker samples |
| --- | --- | --- | --- | --- | --- |
| PostgreSQL / 2,500 | [JSON log](size2500-probe.log) | [TSV](size2500-durable.tsv) | [JSON](size2500-snapshot.json) | [gzip](size2500-history.json.gz) | [PSV](size2500-stats.psv) |
| PostgreSQL / 3,000 | [JSON log](size3000-probe.log) | [TSV](size3000-durable.tsv) | [JSON](size3000-snapshot.json) | [gzip](size3000-history.json.gz) | [PSV](size3000-stats.psv) |
| PostgreSQL / 3,700 | [JSON log](size3700-probe.log) | [TSV](size3700-durable.tsv) | [JSON](size3700-snapshot.json) | [gzip](size3700-history.json.gz) | [PSV](size3700-stats.psv) |
| PostgreSQL / 9,950 events | [JSON log](event9950-probe.log) | [TSV](event9950-durable.tsv) | [JSON](event9950-snapshot.json) | [gzip](event9950-history.json.gz) | [PSV](event9950-stats.psv) |
| MySQL / 2,500 | [JSON log](mysql-size2500-probe.log) | [TSV](mysql-size2500-durable.tsv) | [JSON](mysql-size2500-snapshot.json) | [gzip](mysql-size2500-history.json.gz) | [PSV](mysql-size2500-stats.psv) |
| MySQL / 3,000 | [JSON log](mysql-size3000-probe.log) | [TSV](mysql-size3000-durable.tsv) | [JSON](mysql-size3000-snapshot.json) | [gzip](mysql-size3000-history.json.gz) | [PSV](mysql-size3000-stats.psv) |
| MySQL / 3,700 | [JSON log](mysql-size3700-probe.log) | [TSV](mysql-size3700-durable.tsv) | [JSON](mysql-size3700-snapshot.json) | [gzip](mysql-size3700-history.json.gz) | [PSV](mysql-size3700-stats.psv) |

The [PostgreSQL](pg-final-backlog.tsv) and [MySQL](mysql-final-backlog.tsv) final durable counts show four and three completed runs respectively, all tasks complete with one attempt and zero repairs, and no failed job. The [PostgreSQL](pg-server-final.log.gz) and [MySQL](mysql-server-final.log.gz) Server access logs contain no HTTP 4xx/5xx in these completed-run stacks. Probe/container outcome files and [PostgreSQL](pg-stack-cleanup.log) / [MySQL](mysql-stack-cleanup.log) cleanup logs are retained alongside the artifacts. No task-owned container, volume or probe image remains.

## Operating recommendation

Keep the default 8,000-event / 4 MiB warning and 10,000-event / 5 MiB continue-as-new recommendation. Plan a continuation when either warning dimension reports `approaching`, before task latency and dispatch recovery become harder to predict. Do not raise the defaults because one bounded workflow completed just past them. Monitor the run's event and byte counters, workflow-task p95, repair counts and backlog. Use SDK versions with bounded history polling: PHP 2.1.6, Python 2.3.7 and Rust 2.1.2 or later compatible releases. The earlier published [mixed-workload](../server-237-20260929-published-python237/README.md) and [recovery](../server-237-20260929-pg-recovery/README.md) slices establish the corresponding result, continuation, worker-replacement and external-payload outcomes for this exact Server image.

This qualification covers the tested published tuple, limits and workload. It establishes the size-only pressure transitions on both databases and replay time for the exported 10,010-event PostgreSQL history. Fan-out-only pressure and automatic age-based external-object deletion remain separate operating surfaces; neither was inferred from these runs.
