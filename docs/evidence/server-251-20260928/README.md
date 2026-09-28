# Server #251 bounded workflow-task completion validation, 2026-09-28

This is an isolated, synthetic qualification of the candidate Server controller. It supports the change in Server #251 and the long-history work in Server #237. The Server image with this controller has not been published yet.

## Attribution

The published controller passes one request containing all commands and more than 100 `commands.*` rules to Laravel validation. The framework expands each wildcard rule over the full command data. In the published HTTP image, 500 side-effect commands repeatedly reached PHP's 30-second execution limit at `ValidationRuleParser.php:167` before any command was committed. The candidate keeps the same rules, validates top-level fields once, then validates commands in batches of 25 while retaining their original indexes. It collects errors across batches before any task ownership or completion action. The [regression test](../../../tests/Feature/WorkerProtocolContractTest.php) checks errors in separate batches, a forbidden principal field, a nested required field, a top-level error, and a valid 500-command request.

## Frozen benchmark inputs

| Item | Value |
| --- | --- |
| Published image used as PHP runtime | `durableworkflow/server@sha256:9231de0d3c0c8fed79f1a2e6d62129f85ac844ed7c0afdc6c653a732b1e55bf4`, local amd64 image `sha256:fc70a6dab505f678ab414eaa7713601347097fb111248ff0309218020f0a3e29`, PHP 8.3.35 |
| Baseline controller | Server `main` commit `c373388e4870eec4b2925f830f9cf5265d1bc4a8`, file SHA-256 `0434e249878e6fb1c57ac29a47c528c50626f996b7358fd2547060cde294b45e` |
| Candidate controller | This PR's controller, file SHA-256 `a5a3467c775e07b708794edf93eaaee2958e9c5443271b69177c0e44ae02746f` |
| Mounted test dependencies | Checkout `composer.lock` SHA-256 `febe585737b618cc8e56eb9cc0980caf210c1363e4071ac30eb0376dfc2668fd`, `vendor/composer/installed.json` SHA-256 `b51e58e7b85e74cf868cf5b39840dd27780980c99da3ec27d905fea47b5be021`, Workflow 2.2.13, PHPUnit 11.5.55 |
| Host | Four-core Intel i5-6500, 15 GiB RAM, x86-64 local Docker |
| Test data | 50, 250, or 500 `record_side_effect` commands. Nested shape adds a valid `workflow_stream` append on every tenth command. SQLite in memory. No remote services. |

The [benchmark fixture](ValidationBenchmark.php) submits each batch to a missing workflow task. Both versions return the same HTTP 404 `task_not_found` after validation and preflight. The timed region covers the local request, including validation and ownership lookup, but excludes PHP process startup and database migration. Peak allocation is for the PHP test process. These are single diagnostic runs, not throughput or capacity estimates. The [raw observations](validation-benchmark.jsonl) are:

| Commands | Shape | Baseline request | Candidate request | Baseline peak | Candidate peak |
| ---: | --- | ---: | ---: | ---: | ---: |
| 50 | Flat | 0.496 s | 0.377 s | 18 MiB | 18 MiB |
| 250 | Flat | 6.961 s | 1.343 s | 28 MiB | 18 MiB |
| 500 | Flat | 62.668 s | 2.564 s | 39 MiB | 20 MiB |
| 250 | Nested | 7.183 s | 1.379 s | 28 MiB | 20 MiB |
| 500 | Nested | Not measured | 2.655 s | Not measured | 20 MiB |

The baseline 500-command request was also measured once with file-backed SQLite at 62.433 seconds; the final in-memory recheck above was 62.668 seconds. The production 30-second PHP request limit explains the observed HTTP 500. The candidate's 500-command path stays below that limit in this local fixture, with less allocated memory. The measurement does not include actual workflow history writes.

To repeat the benchmark from the Server checkout, create a task-local baseline controller copy and run the same fixture once with that file mounted over the candidate. The runtime image provides PHP, while the checkout supplies the test source and installed vendor tree. Run the container as UID 1000 and keep `DB_DATABASE=:memory:` so tests do not modify `database/database.sqlite`:

```bash
git show c373388e4870eec4b2925f830f9cf5265d1bc4a8:app/Http/Controllers/Api/WorkerController.php > /path/to/task-local/baseline-WorkerController.php
docker run --rm --user 1000:1000 --network none \
  -e DB_DATABASE=:memory: -e QUALIFICATION_COMMAND_COUNT=500 \
  -e QUALIFICATION_COMMAND_SHAPE=flat \
  -v "$PWD:/app" \
  -v /path/to/task-local/baseline-WorkerController.php:/app/app/Http/Controllers/Api/WorkerController.php:ro \
  -w /app --entrypoint php \
  durableworkflow/server@sha256:9231de0d3c0c8fed79f1a2e6d62129f85ac844ed7c0afdc6c653a732b1e55bf4 \
  vendor/bin/phpunit docs/evidence/server-251-20260928/ValidationBenchmark.php --no-progress
```

Omit the baseline controller mount to measure the candidate. Change count and shape as needed. The benchmark prints one JSON record to stderr.

## Published SDK completion probe

A fresh isolated Compose project ran the **unmodified published** Server 2.4.20 image, its bundled Workflow 2.2.13, published Python SDK 2.3.5, MySQL digest `sha256:679e7e924f38a3cbb62a3d7df32924b83f7321a602d3f9f967c01b3df18495d6`, and Redis digest `sha256:0637954999d01b7c9ce9167db2da50656e2590d3b884f1c600c5f63bb6e6773c`. Only the candidate `WorkerController.php`, the 128 MiB PHP setting, and request-peak instrumentation were mounted into the HTTP Server. The source-mounted Workflow candidate from Server #237 was absent in this fresh run. All credentials and payloads were synthetic; the probe container had 1 CPU and 1 GiB RAM.

The [Python fixture at immutable Server evidence commit `48794526`](https://github.com/durable-workflow/server/blob/48794526/docs/evidence/server-237-20260927/history_probe.py) ran with `PROBE_ACTIVITY_INTERVAL=500`, `PROBE_WORKER_TIMEOUT_SECONDS=600`, and count `1000`. The [raw SDK result](published-sdk-1000-interval500.json) records the exact sum `499500`, 1,000 side effects, one complete activity boundary, 1,006 ordered history events across two pages, 34.69 seconds of worker execution, and 46,224 KiB peak probe-process RSS. The [durable snapshot](completed-run-snapshot.json) confirms 1,006 history rows, 1,006 timeline rows, no open tasks, and a completed run. Both task completions returned [HTTP 200](completion-http.log). PHP completion request allocation peaks were [16 and 28 MiB](completion-memory.log), both below the published 128 MiB limit.

After completion, a 500-command retry against the first completed task returned [HTTP 409 `run_closed`](duplicate-completion.json). The [retry probe](duplicate_probe.py) used the same synthetic worker credential. A second durable snapshot was byte-for-byte identical to the committed completed-run snapshot, so the retry added no history or duplicate terminal event. The isolated containers and volumes were removed after evidence capture.

## Checks and release boundary

The large-batch regression and affected protocol, workflow, stream, and ownership feature tests passed together: 181 tests and 2,220 assertions in a PHP 8.3 container. The exact candidate still needs the Server CI result, published PHP/Python/Rust conformance, review, and a new image release. Repeat the 500-command published SDK probe against the exact released digest before closing Server #251.
