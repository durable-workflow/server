# Published PHP 2.1.6 mixed long-history qualification, 2026-09-29

A fresh worker using the exact Packagist PHP SDK 2.1.6 package completed a new long mixed workflow on the unchanged published Server 2.4.26 image. The Server retained its 128 MiB PHP request memory limit. A first worker opened the durable signal wait and stopped before any signals were offered; the fresh worker processed the accumulated history.

## Frozen inputs

| Component | Selection |
| --- | --- |
| Server | `durableworkflow/server@sha256:d9d13157f91d5418ba74759bc1b32f1ba0fc649e165f6206faab55e0f4737a1e`; Workflow 2.2.18; PHP request `memory_limit=128M` |
| PHP worker | Packagist `durable-workflow/sdk:2.1.6`, source/dist reference `1a00fd92bc63e83b749765cb2d8fdbbe4b0218f9` in [composer.lock](composer.lock). Worker container base `composer@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7`, one CPU, 1 GiB memory |
| Signal client | Published PyPI `durable-workflow==2.3.7` in task-built image `sha256:c75fc2bc3882228f3381a6050229f6137d9537eff8e3df161d0fa763eae55838`, using [Dockerfile.offer](Dockerfile.offer) and [offer_signals.py](offer_signals.py) |
| Database/cache | Pinned MySQL `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6` and Redis `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c` |
| Fixture and limits | [php_mixed_history_probe.php](php_mixed_history_probe.php); 4,000 numbered signals, 4,000 deterministic side effects, eight activity and zero-delay timer boundaries, then continue-as-new; local four-core Intel i5-6500 and 15 GiB RAM; isolated project `dw237php2160509`, loopback port 18252 and synthetic credentials |

## Procedure

The stack used `docker-compose.published.yml` and the [MySQL/Redis digest overlay](../server-237-20260928/digests.compose.yml). `RUN_ENV` selected its exact image, project, loopback port and synthetic credentials. `PROBE_ENV` supplied the same synthetic role credentials, workflow ID, task queue and bounded counts to the published-package clients. Neither env file was committed. The PHP worker fixture was mounted read-only into an otherwise clean Composer project installed from Packagist; its generated [composer.json](composer.json) and lockfile are retained.

```bash
docker compose --env-file "$RUN_ENV" -p dw237php2160509 \
  -f docker-compose.published.yml \
  -f docs/evidence/server-237-20260928/digests.compose.yml \
  up -d server worker scheduler

docker run --rm --user 1000:1000 -v "$PHP_PROJECT:/app" -w /app \
  composer:2 composer require --no-interaction --no-progress durable-workflow/sdk:2.1.6

# With the PHP project at /app and the committed probe mounted read-only at /probe.php:
docker run -d --name dw237php216-initial --network host --cpus=1 --memory=1g \
  --user 1000:1000 --env-file "$PROBE_ENV" -v "$PHP_PROJECT:/app" \
  -v "$PHP_PROBE:/probe.php:ro" --entrypoint php composer:2 /probe.php worker
docker run --rm --network host --user 1000:1000 --env-file "$PROBE_ENV" \
  -v "$PHP_PROJECT:/app" -v "$PHP_PROBE:/probe.php:ro" \
  --entrypoint php composer:2 /probe.php start
docker run --rm --network host --user 1000:1000 --env-file "$PROBE_ENV" \
  -v "$PHP_PROJECT:/app" -v "$PHP_PROBE:/probe.php:ro" \
  --entrypoint php composer:2 /probe.php wait
# Stop and remove the initial worker after the durable wait, then offer 4,000 signals.
docker run --rm --network host --cpus=1 --memory=1g --user 1000:1000 \
  --env-file "$PROBE_ENV" -e PROBE_SIGNAL_CONCURRENCY=8 dw237php216-offer
# Start a new PHP worker container and run /probe.php result in a separate client container.
```

## Observed result

The [offer log](offer4000.log) records 4,000 acknowledged signals in 365.156 seconds, with signal API p50/p95/p99 0.419/0.760/0.830 seconds. The [start](start.log), [initial wait](wait.log), and [result](result.log) logs identify the two worker phases and exact `{count: 4000, total: 7998000}` result. The fresh worker started at 05:21:06 UTC and the successor run closed at 05:29:30 UTC, about 504 seconds later; see [run timestamps](run-timestamps.tsv) and [worker identity](php-worker-final-identity.txt). This is one functional run, not a throughput comparison.

The [durable state](durable-state.tsv) has 8,049 contiguous first-run events and two completed successor events, 4,000 each of `SignalReceived` and `SideEffectRecorded`, eight each of `ActivityScheduled`, `ActivityStarted`, `ActivityCompleted`, `TimerScheduled` and `TimerFired`, one `WorkflowContinuedAsNew`, and one `WorkflowCompleted`. All eight activity and timer tasks and 20 workflow tasks completed with no attempt count above one; no task remained ready or leased. The schema directory occupied [144,166,912 bytes](mysql-schema-bytes.tsv). This run did not use external signal payloads; the separate published-artifact payload probe is in earlier Server #237 evidence.

The highest sampled PHP worker container memory use was 144.2 MiB across 43 samples in [container-stats.psv](container-stats.psv), below its 1 GiB limit. The [Server log](server.log.gz) has no HTTP 5xx or PHP memory fatal. The worker had no OOM kill or restart; after the verified result, `docker stop` terminated its idle process, producing exit 137 in the retained worker identity. The published PHP worker crossed the 6,529-event request point that failed with an unbounded poll under Python SDK 2.3.6.

This PHP result, the [published Python 2.3.7 result](../server-237-20260929-published-python237/README.md), and the exact published 31/31 replay conformance establish the bounded-poll fix for these two first-party workers. Rust's separate long-history run and Server #237's broader database, recovery, retention and repeat-observation matrix remain open.
