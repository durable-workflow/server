# Published Rust 2.1.2 mixed long-history qualification, 2026-09-29

A fresh worker using the exact crates.io Rust SDK 2.1.2 package completed a new long mixed workflow on the unchanged published Server 2.4.26 image. The Server retained its 128 MiB PHP request memory limit. The first Rust worker opened a durable signal wait and exited cleanly before signals were offered. A new Rust worker then processed the accumulated history, continued as new, and completed the successor run.

## Frozen inputs

| Component | Selection |
| --- | --- |
| Server | `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`, Server 2.4.26, Workflow 2.2.18, PHP request `memory_limit=128M` |
| Rust worker | crates.io `durable-workflow=2.1.2`, checksum `63bee7fcbb528f993e30fbb40d66e3f84f7842516f10f6f5944f453a601b48c5` in [Cargo.lock](Cargo.lock), built with `rust:1.86@sha256:300ec56abce8cc9448ddea2172747d048ed902a3090e6b57babb2bf19f754081`; release binary SHA-256 `4d15b756b12fe52858d7964836d784854101f194bd8e457d6cc9c4f3e5aa9a75`, one CPU and 1 GiB memory per worker container |
| Signal client | Published PyPI `durable-workflow==2.3.7` in task-built image `sha256:c75fc2bc3882228f3381a6050229f6137d9537eff8e3df161d0fa763eae55838`, using [Dockerfile.offer](../server-237-20260929-published-php216/Dockerfile.offer) and [offer_signals.py](../server-237-20260929-published-php216/offer_signals.py) |
| Database/cache | Pinned MySQL `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` and Redis `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| Fixture and limits | [Rust probe](src/main.rs), 4,000 numbered signals, 4,000 deterministic side effects, eight activity and zero-delay timer boundaries, then continue-as-new; local four-core Intel i5-6500 and 15 GiB RAM; isolated local Compose project `dw237php2160509`, loopback port 18252, disposable credentials |

## Procedure

This run reused the isolated stack from the [PHP 2.1.6 qualification](../server-237-20260929-published-php216/README.md), including its completed PHP workflow. The exact [stack](stack.env.example) and [probe](probe.env.example) environment examples contain only disposable test credentials. `PROBE_ENV` refers to a copy of `probe.env.example`; `SCRATCH` is a unique task-owned directory. The Rust source and lockfile were copied into `$SCRATCH/project` before building so Cargo never wrote into the repository checkout. The stack was started with the same `docker-compose.published.yml` and [digest overlay](../server-237-20260928/digests.compose.yml) documented in the PHP record.

```bash
docker run --rm --user 1000:1000 -e CARGO_HOME=/app/.cargo \
  -e CARGO_TARGET_DIR=/app/target -v "$SCRATCH/project:/app" -w /app \
  rust@sha256:300ec56abce8cc9448ddea2172747d048ed902a3090e6b57babb2bf19f754081 \
  cargo build --release --locked

docker run -d --name dw237rust212-initial --network host --cpus=1 --memory=1g \
  --user 1000:1000 --env-file "$PROBE_ENV" -v "$SCRATCH/project:/app:ro" \
  -v "$SCRATCH:/probe" --entrypoint /app/target/release/server-237-rust-history-probe \
  rust:1.86 worker
docker run --rm --network host --cpus=1 --memory=1g --user 1000:1000 \
  --env-file "$PROBE_ENV" -v "$SCRATCH/project:/app:ro" \
  --entrypoint /app/target/release/server-237-rust-history-probe rust:1.86 start
docker run --rm --network host --cpus=1 --memory=1g --user 1000:1000 \
  --env-file "$PROBE_ENV" -v "$SCRATCH/project:/app:ro" \
  --entrypoint /app/target/release/server-237-rust-history-probe rust:1.86 wait
touch "$SCRATCH/initial.stop"
docker wait dw237rust212-initial
docker rm dw237rust212-initial

docker build -t dw237php216-offer \
  -f docs/evidence/server-237-20260929-published-php216/Dockerfile.offer .
docker run --rm --network host --cpus=1 --memory=1g --user 1000:1000 \
  --env-file "$PROBE_ENV" -e PROBE_SIGNAL_CONCURRENCY=8 dw237php216-offer

docker run -d --name dw237rust212-fresh --network host --cpus=1 --memory=1g \
  --user 1000:1000 --env-file "$PROBE_ENV" -e PROBE_STOP_FILE=/probe/fresh.stop \
  -v "$SCRATCH/project:/app:ro" -v "$SCRATCH:/probe" \
  --entrypoint /app/target/release/server-237-rust-history-probe rust:1.86 worker
docker run --rm --network host --cpus=1 --memory=1g --user 1000:1000 \
  --env-file "$PROBE_ENV" -v "$SCRATCH/project:/app:ro" \
  --entrypoint /app/target/release/server-237-rust-history-probe rust:1.86 result
touch "$SCRATCH/fresh.stop"
docker wait dw237rust212-fresh
docker rm dw237rust212-fresh

docker exec -i dw237php2160509-mysql-1 sh -c \
  'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -D durable_workflow -N' \
  < docs/evidence/server-237-20260929-published-rust212/collect-state.sql
```

The first worker started at 05:36:34 UTC and exited with code zero at 05:36:49. The fresh worker started at 05:42:52, the successor completed at 05:50:47, and the worker exited cleanly at 05:50:58 after the stop sentinel. The [worker states](initial-worker-state.json) and [fresh worker state](fresh-worker-state.json) report no OOM kill or restart. [Build output](build.log), [signal offers](offer4000.log), [result](result.log), [worker samples](fresh-worker-stats.txt), and compressed [Server](server.log.gz) and [scheduler](server-scheduler.log.gz) logs are retained.

## Observed result

The [offer log](offer4000.log) records 4,000 acknowledged signals in 344.375 seconds, with signal API p50/p95/p99 0.396/0.709/0.775 seconds. The [result](result.log) is exactly `{count: 4000, total: 7998000}`. The fresh worker needed about 475 seconds from start to successor completion. Its highest sampled container memory use was 63.75 MiB across 41 samples, below its 1 GiB limit.

The [durable state](durable-state.tsv) contains 8,046 contiguous first-run events and two completed successor events, 4,000 each of `SignalReceived` and `SideEffectRecorded`, eight each of `ActivityScheduled`, `ActivityStarted`, `ActivityCompleted`, `TimerScheduled` and `TimerFired`, one `WorkflowContinuedAsNew`, and one `WorkflowCompleted`. All eight activity and timer tasks and 19 workflow tasks completed with no attempt count above one. No task remained ready or leased. The database schema directory occupied [240,685,056 bytes](mysql-schema-bytes.tsv) after both the earlier PHP run and this Rust run, versus 144,166,912 bytes after PHP alone. This observed increase includes MySQL allocation overhead and cannot be attributed solely to Rust history bytes. The Server logs contain no HTTP 5xx or PHP memory fatal during this Rust run.

This is one functional recovery run, not a throughput comparison. It did not exercise PostgreSQL, external payloads or a backend interruption. The separate [published-artifact replay result](../server-237-20260929-published-python237/replay/replay-conformance-record.json) passed 31/31 scenarios for the exact Server, PHP, Python, Rust, Workflow, Waterline and CLI tuple. Server #237's broader database, recovery, retention and repeat-observation matrix remains open.
