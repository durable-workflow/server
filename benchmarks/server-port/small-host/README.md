# Small-host capacity on plan-sized targets

[profile.json](profile.json) measures what one small plan can sustain. The
target host runs only the server and its database, with no container limits,
so the plan itself is the limit. A separate driver host in the same private
network runs the SDK workers, load probes and samplers. The question it
answers is how much one-activity work native rc.2 sustains on a 1 vCPU / 1 GB
or 1 vCPU / 2 GB plan, and whether published PHP 2.11.8 fits there at all.

This is a development comparison on disposable hosts, not a publishable
capacity claim. The 4 vCPU comparison host instead runs the matched profile
([matched-4c.json](matched-4c.json)), described at the end.

## What a run does

- **Target modes**, one at a time and never concurrently, each with a fresh
  Compose project and volumes:
  - `native-mysql`: native rc.2 and MySQL 8.4 (the default).
  - `native-sqlite`: native rc.2 on its default SQLite file, no database
    server.
  - `php-mysql`: published PHP 2.11.8 HTTP, queue worker and scheduler with
    MySQL 8.4 and Redis.
- **Capacity ladder.** After a 30-second warmup, the driver offers each rate
  in `ladder.rates_per_second` (0.5 to 32 workflows/s) for 60 seconds. It runs
  one probe process per 4 workflows/s and two SDK workers per probe, so the
  driver is not the limit. A rate passes when every probe exits cleanly (no
  errors, late or skipped offers, pending work or poll errors), at least 90%
  of the planned workflows close inside the window, and the slowest probe's
  p95 is at most 2 s. A failed rate is repeated once; two failures end the
  ladder. The sustained capacity is the highest passing rate. The lowest rate
  runs again afterwards as a drift check.
- **Idle long polls.** With the SDK workers stopped, the driver holds 6, 12
  and 24 mixed long polls, twice each.
- **Evidence.**
  - Target: one-second host samples of CPU (busy, iowait, steal), memory
    used and available, swap use and traffic, OOM kills and pressure, plus
    per-service CPU and memory from the containers' cgroup files. Also the
    database audit, container restarts and OOM kills, and server logs.
  - Driver: every probe result, the window marks, health and ready answers
    during work and idle, and driver host CPU per window, which shows the
    driver was not the limit.
  - Both: machine facts and NTP state at the start and the end.

## Hosts

- Targets: `vhp-1c-1gb-amd`, `vc2-1c-1gb`, `vhp-1c-2gb-amd`, in one region,
  each fresh, with nothing else running.
- Driver: one host in the same VPC with at least 4 vCPU (for example
  `vhp-4c-8gb-amd`), reused across targets.
- Network: each target publishes 8080 only on its private address. Allow
  8080 from the driver's private address only.
- Clock: latency compares the driver's start time with the server's
  `closed_at`, so both hosts must report `NTPSynchronized=yes`. Each run
  records the NTP state; check it before trusting a run.
- Software on both hosts: Docker Engine with the Compose plugin, `git`,
  `jq` and `curl`, plus `python3` on whichever host summarizes. Check out the
  same Server commit on both hosts, the one that merges this profile.
- Do not add swap. A run records whatever swap the plan has.

## Driver setup (once)

```sh
git clone https://github.com/durable-workflow/server.git && cd server
git checkout <commit>
export DW_PORT_SOURCE_ROOT=$PWD DW_PORT_SDK_ROOT=$PWD/../sdk-php-2.3.9
mkdir -p "$DW_PORT_SDK_ROOT"
cp scripts/conformance/server-parity/corrected-markers/php/composer.json \
  scripts/conformance/server-parity/corrected-markers/php/composer.lock "$DW_PORT_SDK_ROOT/"
bash scripts/ci/run-composer.sh --rm -v "$DW_PORT_SDK_ROOT:/app" -w /app -- \
  install --no-interaction --no-dev
sha256sum "$DW_PORT_SDK_ROOT/composer.lock"   # must equal artifacts.sdk_php_lock_sha256
docker pull "$(jq -r .artifacts.client_runtime_image benchmarks/server-port/small-host/profile.json)"
```

## Target setup (once per target host)

```sh
git clone https://github.com/durable-workflow/server.git && cd server
git checkout <commit>
profile=benchmarks/server-port/small-host/profile.json
for image in native_image php_server mysql redis; do docker pull "$(jq -r .artifacts.$image "$profile")"; done
export DW_SMALL_TARGET_BIND=<this target's private address>
export DW_SMALL_PROVIDER_PLAN=vhp-1c-1gb-amd   # the plan, recorded in identity.json
```

Pulling the images first keeps downloads out of the measured run.

## One run (per target and mode)

Start the target, run the driver, then tear the target down. Run the modes
in order `native-mysql`, `native-sqlite`, `php-mysql` on every target. If
PHP does not fit a plan, the run shows it as OOM kills, swap or failed
windows, and that is the answer for that plan.

On the target:

```sh
bash scripts/perf/run-server-port-small-host-target.sh up "$profile" results/target-native-mysql native-mysql
```

On the driver, once the target prints `ready`:

```sh
target=http://<target private address>:8080
profile=benchmarks/server-port/small-host/profile.json
DW_SMALL_PROVIDER_PLAN=vhp-4c-8gb-amd \
  bash scripts/perf/run-server-port-small-host.sh "$profile" results/vhp-1c-1gb-native-mysql-smoke "$target" --smoke
bash scripts/perf/run-server-port-small-host.sh "$profile" results/vhp-1c-1gb-native-mysql "$target"
```

The `--smoke` pass (two short steps and one idle window) only checks the
setup. Use the same target project for the smoke and the measured run only
if the smoke passed; otherwise tear down and start again.

On the target, after the driver finishes:

```sh
bash scripts/perf/run-server-port-small-host-target.sh down results/target-native-mysql
cat results/target-native-mysql/teardown.txt   # must be: containers=0 volumes=0
```

Then summarize on either host, after copying the other side's output
directory over:

```sh
python3 -I scripts/perf/summarize-server-port-small-host.py \
  results/vhp-1c-1gb-native-mysql results/target-native-mysql > summary-vhp-1c-1gb-native-mysql.json
```

## Reading a summary

- `capacity.sustained_rate_per_second` is the plan's sustained one-activity
  capacity for that mode.
- For each step: `steps[].target` (host CPU, memory, swap, OOM kills and
  pressure), `steps[].target_services` (the server's and the database's
  share), `steps[].driver_cpu_busy_fraction` (must stay well below 1) and
  `steps[].health`.
- `idle[]` gives the target's cost with held long polls and no work.
- `target_whole_run.oom_kills`, `target_container_states`,
  `target_database_audit` and `clock` must be clean for the run to count.

## Teardown checks

After the last run on a host: `docker ps -a` and `docker volume ls` list
nothing from these projects (`dw-small-*`), each `teardown.txt` reads
`containers=0 volumes=0`, and then the hosts are deleted and their absence
verified. Commit the summary JSON files and a short observations note under
`benchmarks/server-port/small-host/`. Delete the raw result directories once
the observations are reviewed.

## The 4 vCPU comparison host

[matched-4c.json](matched-4c.json) is the [rc1 matched profile](../rc1/README.md)
with `artifacts.native_image` set to rc.2 and `artifacts.php_server` set to
published PHP 2.11.8. `run-server-port-matched.sh` runs the PHP roles from
`artifacts.php_server` (published 2.6.2 when a profile names none, so the
rc1 profile is unchanged). The SDK client runtime and its lock stay as in
rc1. Follow the rc1 README's commands with this profile and
`DW_PORT_NATIVE_IMAGE` set to the rc.2 digest, alternating PHP and
native-image runs on one `vhp-4c-8gb-amd` host.

## Local stand-in

Two optional variables exist only for a local stand-in check, never for a
measured run:
- `DW_SMALL_TARGET_COMPOSE_OVERRIDE` and `DW_SMALL_DRIVER_COMPOSE_OVERRIDE`
  add a Compose file, for example to cap the target at 1 CPU / 1 GiB on a
  shared host;
- `DW_SMALL_PROJECT_PREFIX` changes the project prefix.

The profile's first stand-in check is recorded in [smoke.md](smoke.md).
