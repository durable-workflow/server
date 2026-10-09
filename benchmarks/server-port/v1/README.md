# PHP reference measurements for the Rust Server port

Profile version `1.0.0` freezes the current published PHP artifact, PHP SDK,
database/cache digests and a bounded development resource envelope. The workload
uses [DW Standard Workflow v1](../../capacity/README.md): one external echo
activity and a 1,024-byte Avro value in each direction. Commands reuse the
existing capacity PHP adapter, offered-rate probe and mixed idle-poll probe.

This profile has one client and one SDK worker process. Its 120-second offered
windows are development comparisons, not the capacity suite's 300-second
measurement/concurrency contract. The declared host has Linux 5.15, runc 1.2.4
and SATA SSD storage; it cannot qualify the standard capacity topology's
Linux 6.8, runc 1.2.3 and NVMe requirements. Existing capacity inputs, schemas
and qualification rules remain unchanged. No maximum-capacity or Cloud claim
may be derived from this profile.

## Run the pinned PHP reference

Use a disposable clean checkout and a unique project. Install the committed
SDK lock in a runtime container as the host user; the worker mounts that exact
installation read-only over the adapter's dependency directory. This avoids
building another PHP SDK image or using the historical capacity SDK lock.

```sh
export DW_PORT_SOURCE_ROOT="$PWD"
port_project="dw-server-port-$(date -u +%Y%m%dT%H%M%S | tr '[:upper:]' '[:lower:]')"
port_compose=scripts/perf/server-port-baseline.compose.yml
port_image=$(jq -r .artifacts.server benchmarks/server-port/v1/php-baseline.json)
docker run --rm --user="$(id -u):$(id -g)" --entrypoint composer \
  -e COMPOSER_HOME=/tmp/composer -v "$PWD:/source" -w /source "$port_image" \
  install --working-dir=scripts/conformance/server-parity --no-interaction --no-progress
mkdir -p benchmarks/capacity/v1/bindings/php/vendor
docker compose -p "$port_project" -f "$port_compose" config --quiet
docker compose -p "$port_project" -f "$port_compose" up -d --wait server worker scheduler sdk-worker
docker compose -p "$port_project" -f "$port_compose" run --rm --no-deps probe \
  scripts/perf/standard-workflow-offered.php 0.25 60 120 250
docker compose -p "$port_project" -f "$port_compose" run --rm --no-deps probe \
  scripts/perf/standard-workflow-offered.php 0.25 120 120 250
```

Discard the warmup output for performance. Repeat each declared offered rate
three times, retaining every output including failures. Repeat 0.25 starts/s
before and after the higher rates to check drift. A missed offer, late start,
poll error, failed verification or pending workflow invalidates that run; do
not turn its accepted-start count into a completed-throughput result. The probe
reports measurement-window completions separately from its bounded drain.
Its post-window verification checks every completed output, ordered semantic
history and stage timestamps.

Record the source commit and input hashes, actual installed package versions,
resolved image identities, host facts, container limits, UTC boundaries,
restarts/OOM/swap, raw Docker CPU/memory samples and final database/task counts.
Sample all PHP roles and the database/cache, not only HTTP. Report the HTTP
and complete runtime memory totals separately from SDK/load-generator memory.
Use a consistent adjacent idle control and retain ordinary health/readiness
latencies under load. Unrelated host workload changes invalidate comparison;
wait for those jobs to finish without stopping another owner's resources.

## Mixed idle polls

After every admitted workflow has completed and task queues have drained,
stop only this project's SDK worker. The normal PHP worker and scheduler stay
running. A separate queue keeps the idle probe from claiming real work.

```sh
docker compose -p "$port_project" -f "$port_compose" stop sdk-worker
docker compose -p "$port_project" -f "$port_compose" run --rm --no-deps \
  -e DURABLE_WORKFLOW_TASK_QUEUE=server-port-v1-idle probe \
  scripts/perf/idle-poll-probe.php 6 10 mixed
```

Repeat the 6, 12 and 24 poll counts three times, with adjacent idle controls.
Workflow/activity waits share a six-slot admission pool; query waits have two
slots. Empty waits and explicit backpressure are separate outcomes. Query
timeouts may be clamped by the published server; retain each actual duration.
Probe health and readiness from another process while polls are held, and
record CPU and steady/peak memory for all runtime roles. A task claim, failed
registration, failed cleanup or unexpected error invalidates the idle run.

The versioned inputs describe planned measurements. Retained raw evidence and
the [port log](../../../docs/rust-server-port.md) distinguish setup, successful
measurements, invalid runs and remaining qualification work.

## Cleanup

Retain thin records and the owning issue summary, then remove this project's
containers, network and disposable volumes. Remove the task's SDK installation
when its source checkout is retired. Preserve shared images and other projects.

```sh
docker compose -p "$port_project" -f "$port_compose" down --volumes --remove-orphans
```
