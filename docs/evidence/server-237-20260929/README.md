# Published-artifact history event-budget slice, 2026-09-29

This bounded local slice of [Server #237](https://github.com/durable-workflow/server/issues/237) exercises a single run below the default history warning, above the 8,000-event warning, and above the 10,000-event continue-as-new recommendation. It uses deterministic side effects and an activity every 500 side effects. It does not qualify mixed signals, timers, external payloads, worker or backend interruption, retention, or PostgreSQL at these event counts. Those remain in #237.

## Frozen inputs

| Component | Selection |
| --- | --- |
| HTTP, queue worker, scheduler | Published Server 2.4.26 multiarchitecture index `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, local amd64 image `sha256:70dce06f20ee95f8f35613493100d2ffa927b177e6718b398d3feab66aee3f6a`, embedded Workflow 2.2.18 |
| SDK | Published PyPI `durable-workflow==2.3.6`, probe image `sha256:fd9f660535c2825972ae7750e5e3f8d8837da05b888de49eee35089b485265a7`; [package check](probe-package.txt) confirms the 1,024-entry, 16 MiB verified-payload cache |
| MySQL / Redis | Digest-pinned images in [`digests.compose.yml`](../server-237-20260928/digests.compose.yml): MySQL `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6`, Redis `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c`; [reported versions](mysql-version.txt), [Redis version](redis-version.txt) |
| Host / limits | Four-core Intel i5-6500, 15 GiB RAM, x86-64 local Docker; probe limited to one CPU and 1 GiB; Server, queue worker, scheduler, MySQL and Redis use published Compose defaults without container CPU or memory caps; Server PHP [`memory_limit=128M`](php-memory-limit.txt) |
| Workload | The committed [`history_probe.py`](../server-237-20260927/history_probe.py), published SDK wheel, activity interval 500, 3,600-second worker bound, unique workflow IDs, synthetic scoped credentials, isolated loopback port and Compose project |

The default history contract warns at **8,000 events or 4 MiB** and recommends continue-as-new at **10,000 events or 5 MiB**. This fixture crosses the event and byte thresholds together. It therefore verifies the combined budget states and completion, not the independent contribution of either budget dimension. The workflow intentionally completes without issuing continue-as-new so that the run can be observed just beyond the recommendation.

## Reproduction

Create a task-local `RUN_ENV` containing a unique `COMPOSE_PROJECT_NAME`, the exact `DW_SERVER_IMAGE` index above, `DW_SERVER_TAG=2.4.26`, `APP_ENV=production`, `DW_AUTH_BACKWARD_COMPATIBLE=false`, a synthetic `DW_SERVER_KEY`, synthetic admin/operator/worker tokens, isolated MySQL passwords, and an unused `127.0.0.1` port. No production credentials or state were used. From the Server checkout:

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  up -d --wait server worker scheduler
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  build --build-arg PYTHON_SDK_VERSION=2.3.6 probe
```

For each row below, in the order **8,050, 9,950, 7,600**, run the same command with the indicated count and unique label. The unmodified Server and SDK images were used throughout. The local Compose project was `dw237threshold0307` and the loopback port was `18249`.

```bash
docker compose --env-file "$RUN_ENV" \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260927/compose.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  run --rm --no-deps \
  -e PROBE_RUN_ID=published236-event8050 \
  -e PROBE_ACTIVITY_INTERVAL=500 \
  -e PROBE_WORKER_TIMEOUT_SECONDS=3600 \
  probe 8050
```

For the other two levels, substitute `published236-event9950` / `9950` and `published236-event7600` / `7600`. Each process reports the exact arithmetic result, ordered event counts and types, history page count, elapsed worker time and peak process RSS. The [snapshot extractor](../server-237-20260928/run_snapshot.php) was run read-only as UID 1000 in the isolated Server container with `QUALIFICATION_RUN_ID` set to the probe's run ID. Task rows, backlog, logical payload lengths, Docker samples, access logs and image identities are retained below. The stack was removed with `docker compose ... down -v --remove-orphans` after capture.

The resource sampler ran while each named probe container existed, with this `docker stats` format for the probe, Server, worker, scheduler, MySQL and Redis. Each `docker stats --no-stream` call took several seconds, so sample intervals were not exactly ten seconds.

```bash
stamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)
docker stats --no-stream \
  --format "$stamp|{{.Name}}|{{.CPUPerc}}|{{.MemUsage}}|{{.NetIO}}|{{.BlockIO}}" \
  "$PROBE_CONTAINER" "$SERVER_CONTAINER" "$WORKER_CONTAINER" \
  "$SCHEDULER_CONTAINER" "$MYSQL_CONTAINER" "$REDIS_CONTAINER"
```

The durable rows came from `workflow_run_summaries`, `workflow_tasks`, `workflow_runs`, `workflow_history_events`, `workflow_run_timeline_entries` and `failed_jobs` on the disposable MySQL instance. Workflow and activity task duration is `TIMESTAMPDIFF(MICROSECOND, created_at, updated_at) / 1000000`. The storage query sums `OCTET_LENGTH(CAST(payload AS CHAR))` by run in the history and timeline tables, then reads `/var/lib/mysql/durable_workflow` with `du -sb`. Exact query output and the [cleanup log](stack-cleanup.log) are retained with the results.

## Results

| Side effects | Ordered events | History summary bytes | Budget state | Exact sum | Worker time | Probe process peak RSS | Workflow task created-to-completed p50/p95/p99 |
| ---: | ---: | ---: | --- | ---: | ---: | ---: | --- |
| 7,600 | 7,648 | 4,165,084 | `ok` | 28,876,200 | 286.70 s | 87,900 KiB | 10.963 / 16.654 / 16.654 s, 16 tasks |
| 8,050 | 8,101 | 4,411,985 | `approaching` | 32,397,225 | 310.99 s | 88,112 KiB | 11.455 / 16.932 / 16.932 s, 17 tasks |
| 9,950 | 10,010 | 5,450,346 | `continue_as_new_recommended` | 49,496,275 | 409.40 s | 114,176 KiB | 12.419 / 19.176 / 19.647 s, 20 tasks |

All three runs returned the exact expected result. Their history and timeline row counts match their ordered event counts, with 15, 16 and 19 completed activities respectively and no activity retries. The 10,010-event run crossed both the default 10,000-event and 5 MiB recommendations and still completed at the published PHP limit. Each task row reports one attempt. In the 9,950-side-effect run, one completed activity task and one completed workflow task each recorded one repair; the other task rows recorded zero. At the end of the three runs, [durable counts](final-backlog.tsv) report three completed runs, 25,759 history and timeline rows, 103 completed tasks, zero open runs, zero open tasks and zero failed jobs. The [Apache log](server.log.gz) contains 366 HTTP/1.1 200 responses, six HTTP/1.1 201 responses, six internal HTTP/1.0 200 responses, and no 4xx or 5xx. [Container inspection](containers-7600.txt) reports no OOM kills or restarts for Server, queue worker or scheduler.

The task percentiles use nearest-rank values from each run's SQL task rows and measure task creation to its final `updated_at`, including dispatch and service work. They are not isolated SDK replay CPU time. The active SDK process' `ru_maxrss` peak is distinct from the sampled Docker container-memory values below. The three diagnostic runs were sequential on a shared host, with prior run data retained in MySQL. Their elapsed times are observations of these exact runs, not a capacity or latency comparison.

| Side effects | Docker samples | Sampled HTTP container peak | Sampled SDK container peak | Sampled MySQL peak | Activity task created-to-completed p50/p95/p99 |
| ---: | ---: | ---: | ---: | ---: | --- |
| 7,600 | 23 | 159.4 MiB | 71.06 MiB | 614.8 MiB | 7.030 / 11.380 / 11.380 s, 15 tasks |
| 8,050 | 24 | 150.1 MiB | 71.59 MiB | 577.1 MiB | 7.044 / 11.045 / 11.045 s, 16 tasks |
| 9,950 | 33 | 188.9 MiB | 81.78 MiB | 611.8 MiB | 7.450 / 15.015 / 15.015 s, 19 tasks |

Docker memory values are sampled, so a transient peak can be missed. The HTTP container includes Apache and PHP processes and is not a PHP request's allocated-memory peak. The sampled MySQL peak is affected by retained data and cache from prior runs. No continuous swap measurement was taken. No external payloads were used in this slice.

### Storage

The [logical-storage query](logical-storage.tsv) measured the serialized JSON payload fields in the final database. For the 7,600 / 8,050 / 9,950 runs respectively, history event payloads summed to **4,258,734 / 4,511,179 / 5,572,776 bytes**, while timeline payloads summed to **16,096,409 / 17,050,028 / 21,066,446 bytes**. These are JSON field lengths, not complete row or index sizes, and differ from the history-budget byte counter. The final three-run MySQL schema directory was [245,518,336 bytes](mysql-schema-after-7600.txt), including indexes and allocated free pages. It grew by **62,980,096 bytes** during the last 7,600-side-effect run, from the [pre-run](mysql-schema-before-7600.txt) to post-run directory measurement. After all three runs, the history-event `.ibd` file was **37,748,736 bytes** and the timeline `.ibd` file was **197,132,288 bytes** in the [file snapshot](schema-files-final.txt). These filesystem sizes are allocation observations, not per-customer storage forecasts. The separate whole-MySQL-directory [before](mysql-dir-before-9950.txt) and [after](mysql-dir-after-9950.txt) measurements for the 9,950-side-effect run include InnoDB system files and redo logs, so they are retained raw without attributing their difference solely to the workflow.

### Raw artifacts

| Side effects | Probe result | Durable snapshot | Task rows and budget | Docker samples | Container outcome |
| ---: | --- | --- | --- | --- | --- |
| 7,600 | [log](probe-7600.log) | [JSON](snapshot-7600.json) | [TSV](durable-7600.tsv) | [PSV](container-stats-7600.psv) | [inspection](containers-7600.txt) |
| 8,050 | [log](probe-8050.log) | [JSON](snapshot-8050.json) | [TSV](durable-8050.tsv) | [PSV](container-stats-8050.psv) | [inspection](containers-8050.txt) |
| 9,950 | [log](probe-9950.log) | [JSON](snapshot-9950.json) | [TSV](durable-9950.tsv) | [PSV](container-stats-9950.psv) | [inspection](containers-9950.txt) |

Image identity files are [Server](server-image.txt) and [probe](probe-image.txt). The [final Compose state](compose-final.txt), [MySQL version](mysql-version.txt), [Redis version](redis-version.txt), [Server PHP limit](php-memory-limit.txt), [full HTTP log](server.log.gz), [final backlog](final-backlog.tsv) and [logical storage](logical-storage.tsv) complete this slice. Draft PR #245 remains open for the wider #237 qualification, including published PostgreSQL at these levels, mixed event sources, worker and backend interruption, repeat observations, retention, and a documented safe operating recommendation.
