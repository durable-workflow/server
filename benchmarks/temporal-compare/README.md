# Temporal comparison race

This harness races the native Server against Temporal's server on the kind of
small VPS that users buy (Server #1081). It runs the same workflow and
activity code on both sides and reports the slowest native backend against
Temporal.

Stage 1 is a dispatch-only workflow. Nightly runs, calibration of the noise
band, regression bisects, the release gate and the public page come later.
No result from this harness is a publishable claim until calibration has
set the noise band.

## What a run does

- **Races.** `five-steps` runs 200 workflows of five sequential activities,
  each of which sleeps 20 ms. `five-local-steps` runs the same workflow with
  local activities. Workflow and activity type names are the same on both
  sides (`race.five_steps` and `race.step`, `race.five_local_steps` and `race.local_step`).
- **Order.** For each race, one system at a time on the target: Temporal,
  native SQLite, native MySQL 8.0, native PostgreSQL 16, then Temporal again.
  Each system gets a fresh Compose project and fresh database volumes. Each
  system runs a 20-workflow warmup and then 5 reps.
- **Workers.** Six Durable Workflow PHP SDK workers, or six RoadRunner
  workers running the Temporal PHP SDK, on the driver. One RoadRunner worker
  runs one workflow PHP process and one activity PHP process, RoadRunner's
  minimum. The RoadRunner image has the protobuf extension that Temporal
  recommends for PHP workers.
- **Timing.** The load generator runs on the driver and takes every time on
  the driver's clock. Race time runs from the first start request to the last
  observed close. Workflow time runs from a workflow's start request to its
  observed close. Both sides start workflows through the same pool of 16
  concurrent starts. Temporal closes are observed through the client's
  close-event long poll. The native Server has no such long poll, so the load
  generator lists running race workflows every 50 ms. That can only delay a
  native close.
- **Headline.** For each race, the ratio of the slowest native backend's
  median race time to Temporal's (the mean of its two runs). All three
  backends are always reported, and SQLite is never the headline on its own.
  If the two Temporal runs differ by more than the drift threshold
  (provisionally 10% until calibration), the run is marked unusable.

## Fairness rules the harness enforces

`scripts/perf/temporal_compare.py` refuses to run when one of these fails:

- Every image is pinned by digest in [images.lock.json](images.lock.json):
  Temporal, RoadRunner, PHP, Composer, MySQL 8.0, PostgreSQL 16, the Rust
  builder and runtime of `Dockerfile.native`, and the uv image. Every harness
  Dockerfile base and every Compose service image must be listed there or be
  an image the harness just built. A digest bump is a reviewed pull request.
- Both SDKs install from lock files: `workers/sdk-php/composer.lock` and
  `workers/roadrunner/composer.lock`. The load generator installs
  `scripts/perf/uv.lock`.
- Default durability. Commands, entrypoints and environments may not relax
  commit durability (`synchronous=OFF`, `innodb_flush_log_at_trx_commit=2`,
  `sync_binlog=0`, `fsync=off`, `synchronous_commit=off`, any
  `--sqlite-pragma`, in-memory or tmpfs databases). At runtime the harness
  reads MySQL's and PostgreSQL's settings back. MySQL refuses connections
  without TLS, and the native Server connects with `DB_SSLMODE=require`.
  Temporal runs `start-dev` on a SQLite file with SQLite's defaults, and the
  native Server uses its own (WAL, `synchronous=FULL`).
- Services talk over TCP. Databases sit behind the target's Compose network,
  and the driver reaches the target over the private network.
- Every system starts on fresh volumes. The harness refuses a project that
  already has volumes and checks that teardown removed them.
- An idle preflight runs before each system on both hosts. It refuses a host
  that used more than 10% CPU over 5 seconds, or that runs other containers.

## Outputs

`summary.json` records the configuration, every rep, each system's median,
spread, p50 and p95 workflow time, the Temporal drift, and provenance: the
harness and native Server commits, the GitHub run, every image digest and
built image ID, the SDK versions, Temporal's and RoadRunner's versions, the
plan and region, and both hosts' CPU model, memory, OS, kernel and Docker
version, plus every idle check. `summary.md` is the job summary table. Rep
files and container logs sit next to them.

## Run it on Vultr

The `Temporal Compare` workflow
([temporal-compare.yml](../../.github/workflows/temporal-compare.yml)) runs
only on `workflow_dispatch` from `main`, in the `perf-soak` environment with
`VULTR_PERF_API_KEY`. `scripts/perf/run-vultr-temporal-compare.sh` creates a
VPC, a target VM (`vhp-2c-4gb-amd` by default, or `vhp-1c-2gb-amd`) and a
`vhp-4c-8gb-amd` driver. The target admits SSH and the race ports only from
the driver's private address. The driver builds the native Server from the
requested commit and the worker images, then runs the race. The controller's
exit trap deletes both VMs and the VPC and verifies they are gone, by a 404
for each and by listing instances under the run's unique label. A failed
check fails the job.

The controller refuses to start when the worst-case cost could exceed
`max_spend_usd`. The worst case is the VM lifetime cap (150 minutes, billed
as 3 started hours) at the plans' published hourly prices. The race must end
10 minutes before the cap, and the job's `timeout-minutes` is longer than the
cap, so the trap still runs. If a runner dies before its trap runs, the next
run deletes race VMs older than the cap, and the workflow's `cleanup_only`
input (or `scripts/perf/run-vultr-temporal-compare.sh cleanup`) does the same
on demand.

`DW_RACE_DRY_RUN=1 scripts/perf/run-vultr-temporal-compare.sh` prints the
cost computation and every Vultr call it would make, including the deletes
and checks, without making any. Set `GITHUB_RUN_ID`, `GITHUB_RUN_ATTEMPT` and
`GITHUB_SHA` first.

## Run it locally

A local run is a functional check, never a measurement. With Docker Compose
on one host, the target and driver are separate Compose projects:

```bash
python3 scripts/perf/temporal_compare.py run --output build/race-local \
  --workflows 20 --reps 1 --warmup-workflows 5 \
  --local-stand-in --allow-busy-hosts \
  --target-bind 172.17.0.1 --target-address 172.17.0.1 \
  --temporal-port 17233 --native-port 18080 --native-source .
```

`--local-stand-in` applies the container limits in
`local-target.compose.yml` and `local-driver.compose.yml`. Such a run, or one
with the idle check off, is never marked publishable.
`python3 scripts/perf/temporal_compare.py check` verifies the pins without
starting anything.

Tests: `uv run --project scripts/perf python -m unittest tests/Unit/Support/temporal_compare_test.py`.
