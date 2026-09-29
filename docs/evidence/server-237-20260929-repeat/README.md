# Published near-threshold repetitions on MySQL and PostgreSQL, 2026-09-29

This Server #237 slice repeats the 9,950-side-effect, 10,010-event published-artifact run after the [MySQL](../server-237-20260929/README.md) and [PostgreSQL](../server-237-20260929-pg-threshold/README.md) threshold measurements. It records two new MySQL runs and one new PostgreSQL run with the currently published Python SDK 2.3.7. Every run used the unchanged published Server 2.4.26 image and finished past the default 10,000-event / 5 MiB continue-as-new recommendation. These are functional repetitions on a shared local host, not a throughput comparison.

## Frozen inputs and method

| Input | Selection |
| --- | --- |
| Server | `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, local amd64 image `sha256:70dce06f20ee95f8f35613493100d2ffa927b177e6718b398d3feab66aee3f6a`, embedded Workflow 2.2.18, PHP request `memory_limit=128M` |
| SDK | Official PyPI `durable-workflow==2.3.7`, confirmed by [installed package version](probe-package.txt). The task-built MySQL and PostgreSQL probe image IDs were [701f6596](probe-image.txt) and [530679ad](pg-probe-image.txt); both used the committed [Dockerfile](../server-237-20260927/Dockerfile.sdk) and [fixture](../server-237-20260927/history_probe.py). |
| Database/cache | Digest-pinned MySQL 8.4.5, PostgreSQL [16.15](pg-version.txt) and Redis 7.2.16 in the existing [Compose overrides](../server-237-20260928/digests.compose.yml) and [PostgreSQL override](../server-237-20260928/postgresql.compose.yml). |
| Host and limits | Local four-core Intel i5-6500, 15 GiB RAM, x86-64 Docker. Each SDK probe had one CPU and 1 GiB; Server, internal worker, scheduler, database and Redis used published Compose defaults without explicit container limits. No customer data or paid host. |
| Workload | One deterministic side effect per step, one activity each 500 steps, 9,950 side effects, 19 activities, 10,010 ordered history events, a unique workflow and queue per run. Runs were serial; the second MySQL run retained the first run's database state. |

The task-local Compose projects were `dw237mysqlrepeat0929` on loopback port 18254 and `dw237pgrepeat0929` on port 18255. Their exact synthetic [MySQL](mysql.env.example) and [PostgreSQL](pg.env.example) configurations are included. From this Server checkout, the commands were:

```bash
RUN_ENV=docs/evidence/server-237-20260929-repeat/mysql.env.example
compose=(docker compose --env-file "$RUN_ENV" -p dw237mysqlrepeat0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml)
"${compose[@]}" up -d --wait server worker scheduler
"${compose[@]}" build --build-arg PYTHON_SDK_VERSION=2.3.7 probe
"${compose[@]}" run -d --name dw237mysqlrepeat0929-probe-1 --no-deps \
  -e PROBE_RUN_ID=published237-mysql-repeat1 \
  -e PROBE_ACTIVITY_INTERVAL=500 -e PROBE_WORKER_TIMEOUT_SECONDS=3600 \
  probe 9950
# Wait for exit 0, collect logs and read-only state, then remove the probe container.
# Repeat with probe-2 and PROBE_RUN_ID=published237-mysql-repeat2.
"${compose[@]}" down -v --remove-orphans

RUN_ENV=docs/evidence/server-237-20260929-repeat/pg.env.example
compose=(docker compose --env-file "$RUN_ENV" -p dw237pgrepeat0929 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  -f docs/evidence/server-237-20260928/postgresql.compose.yml)
"${compose[@]}" up -d --wait server worker scheduler
"${compose[@]}" build --build-arg PYTHON_SDK_VERSION=2.3.7 probe
"${compose[@]}" run -d --name dw237pgrepeat0929-probe-1 --no-deps \
  -e PROBE_RUN_ID=published237-pg-repeat1 \
  -e PROBE_ACTIVITY_INTERVAL=500 -e PROBE_WORKER_TIMEOUT_SECONDS=3600 \
  probe 9950
```

The [MySQL collector](collect-mysql-run.sql) ran with `@workflow_id` set by `mysql --init-command` and reported each task's attempt and repair count, nearest-rank task latency percentiles, event continuity, history budget and backlog. The [PostgreSQL collector](../server-237-20260929-pg-threshold/collect-pg-run.sql) reported the same durable state and continuous task-latency percentiles. The [read-only snapshot extractor](../server-237-20260928/run_snapshot.php) independently read each run by ID inside the published Server container. A `docker stats --no-stream` sampler recorded SDK, HTTP, worker, scheduler, database and Redis CPU and memory roughly every 10 to 15 seconds while each SDK process existed. Database data-directory bytes were measured with `du -sb` before and after runs. Sampling can miss transient peaks.

## Results

| Database / observation | SDK worker time | Process peak RSS | Sampled SDK container peak | Workflow-task created-to-completed p50 / p95 / p99 | Repair counts |
| --- | ---: | ---: | ---: | --- | --- |
| MySQL original, Python 2.3.6 | 409.40 s | 114,176 KiB | 81.78 MiB | 12.419 / 19.176 / 19.647 s | Two completed tasks, one activity and one workflow, recorded one repair each. |
| MySQL repeat 1, Python 2.3.7 | 411.53 s | 78,336 KiB | 65.47 MiB | 12.890 / 19.395 / 19.636 s | One workflow task recorded one repair. |
| MySQL repeat 2, Python 2.3.7 | 409.23 s | 78,872 KiB | 64.38 MiB | 12.771 / 19.666 / 20.284 s | One workflow task recorded one repair. |
| PostgreSQL original, Python 2.3.7 | 440.55 s | 78,268 KiB | 63.47 MiB | 14.984 / 21.078 / 21.185 s | Zero. |
| PostgreSQL repeat 1, Python 2.3.7 | 425.11 s | 77,844 KiB | 62.75 MiB | 14.668 / 20.295 / 20.706 s | Zero. |

The two new MySQL worker times differ by 2.30 seconds, 0.56% of their 410.38-second mean. The two PostgreSQL times differ by 15.44 seconds, 3.51% of the original. These comparisons include SDK execution, activity boundaries, HTTP and database work; they do not isolate replay CPU time. MySQL's original run used the earlier published SDK, so its memory figure is an observation of that earlier tuple rather than a controlled SDK comparison. MySQL nearest-rank and PostgreSQL continuous latency percentiles use different estimators and should not be read as a database speed ranking. Each database's runs were serial, with differing retained state and host conditions.

Every new run returned **49,496,275**, recorded exactly 9,950 side effects and 19 completed activities, and had 10,010 distinct contiguous history events with a matching timeline and `continue_as_new_recommended` summary. Every task had one attempt; all three runs had zero final open tasks and failed jobs. The [MySQL final backlog](mysql-final-backlog.tsv) shows two completed runs and the two repaired workflow tasks. The [PostgreSQL final backlog](pg-final-backlog.tsv) shows one completed run and zero repairs. The inspected SDK, HTTP, queue worker and scheduler containers had no OOM kill or restart; the retained [MySQL](mysql-repeat2-outcome.psv) and [PostgreSQL](pg-repeat1-outcome.psv) inspections show their final states. The Server access logs contain no HTTP 4xx/5xx during these repetitions.

The MySQL schema directory grew from [11,063,296](mysql-before-first.tsv) to [115,363,840](mysql-after-first.tsv) and [191,991,808](mysql-after-second.tsv) bytes after the two runs. The PostgreSQL data directory grew from [52,804,309](pg-before.tsv) to [133,902,053](pg-after.tsv) bytes. These measurements include allocated pages, indexes and, for PostgreSQL, WAL; they are not per-workflow billing estimates. The new PostgreSQL database's final `pg_database_size` was 42,826,775 bytes in its SQL output.

### Raw artifacts

| Run | SDK result | Durable SQL | Server snapshot | Docker samples | Container state |
| --- | --- | --- | --- | --- | --- |
| MySQL repeat 1 | [log](mysql-repeat1-probe.log) | [TSV](mysql-repeat1-durable.tsv) | [JSON](mysql-repeat1-snapshot.json) | [PSV](mysql-repeat1-stats.psv) | [inspection](mysql-repeat1-outcome.psv) |
| MySQL repeat 2 | [log](mysql-repeat2-probe.log) | [TSV](mysql-repeat2-durable.tsv) | [JSON](mysql-repeat2-snapshot.json) | [PSV](mysql-repeat2-stats.psv) | [inspection](mysql-repeat2-outcome.psv) |
| PostgreSQL repeat 1 | [log](pg-repeat1-probe.log) | [TSV](pg-repeat1-durable.tsv) | [JSON](pg-repeat1-snapshot.json) | [PSV](pg-repeat1-stats.psv) | [inspection](pg-repeat1-outcome.psv) |

The [MySQL](mysql-server-final.log.gz) and [PostgreSQL](pg-server-final.log.gz) Server logs, earlier [MySQL Server log](mysql-server-after-first.log.gz), image identities and database measurements are retained in this directory. The published-artifact [backend interruption and payload-retention record](../server-237-20260929-pg-recovery/README.md) separately verifies worker replacement, eventual exact completion and preservation of shared external payloads after a targeted retention pass.

## Operating implication and remaining qualification

The completed runs show that this exact published tuple can finish beyond the default recommendation on both databases with the tested limits. They do not justify raising those thresholds. The repeated MySQL repair counts and workflow-task p95 near 20 seconds make the 8,000-event / 4 MiB `approaching` state a practical point to plan continue-as-new, before the 10,000-event / 5 MiB recommendation. Monitor task latency, repair counts, backlog and the history budget together. A run's completion at 10,010 events is not a promise that larger histories or different workloads will behave the same way.

Server #237 remains open for an isolated replay-time measurement and final supported guidance. This fixture crosses event and serialized-size thresholds together, so it does not independently establish the size-only boundary. Draft PR #245 must remain unmerged until the remaining qualification is complete.
