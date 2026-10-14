# Matched development profile

These are prospective inputs for the first fresh PHP/native comparison, not a
performance result. [matched.json](matched.json) advances the earlier development
profile to the normally installed corrected PHP SDK and an optimized, delivered
native source. The old [v1 profile and observations](../v1/README.md) remain
historical. The standard capacity suite's workload, artifacts and qualification
rules are unchanged.

Use the existing capacity PHP adapter, `standard-workflow-offered.php` and
`idle-poll-probe.php` unchanged. The workload has one external activity and a
1,024-byte Avro value in each direction. Each implementation gets a fresh
MySQL volume and a different Compose project. Run them sequentially. Never
enable PHP and native profiles in one project or give them one database.

Runtime limits total two CPUs and two GiB in either case. PHP distributes that
budget across HTTP, queue worker, scheduler and Redis. Native uses one process.
Identical MySQL, SDK worker and probe limits are additional. Include every
runtime component and MySQL when reporting cost; report SDK/client cost
separately. The PHP queue worker has no hourly exit, avoiding the documented
v1 measurement interruption. This is an explicit v2 profile choice.
CLI roles explicitly use SIGTERM because the PHP HTTP image's SIGWINCH stop
signal applies to Apache. Stop the SDK worker while its Server is still live
so deregistration can finish before auditing registration cleanup.

Before setup, record the runner/source revisions and verify the native subtree
against the profile. Build the specified source with `cargo build --release
--locked --bin durable-workflow-server` in the pinned Rust container, as the
checkout owner, with one build job and a bounded disposable target/cache.
Record the compiler identity and binary hash before any workload. Install the
committed SDK lock normally into a separate disposable directory, verify its
source identity and record its complete lock hash. No benchmark uses a debug
build, edited SDK or source-mounted replacement dependency.

Set absolute `DW_PORT_SOURCE_ROOT`, `DW_PORT_SDK_ROOT` and
`DW_PORT_NATIVE_BINARY`. Use distinct explicit project names with
`scripts/perf/server-port-matched.compose.yml`:

```sh
matched_compose=scripts/perf/server-port-matched.compose.yml
php_project="dw-port-php-$(date -u +%Y%m%dT%H%M%S)"
native_project="dw-port-native-$(date -u +%Y%m%dT%H%M%S)"
mkdir -p "$DW_PORT_SOURCE_ROOT/benchmarks/capacity/v1/bindings/php/vendor"
export DW_PORT_RUNTIME_URL=http://php-server:8080
docker compose -p "$php_project" -f "$matched_compose" --profile php \
  up -d --wait mysql redis php-bootstrap php-server php-worker php-scheduler
docker compose -p "$php_project" -f "$matched_compose" up -d sdk-worker
docker compose -p "$php_project" -f "$matched_compose" run --rm --no-deps probe \
  scripts/perf/standard-workflow-offered.php 0.25 5 120 250
```

That five-second check is setup qualification only. Stop `sdk-worker` while the
Server is live, then inspect exact results, ordered history, original durable
tasks and registration cleanup. Remove the complete PHP project with
`--profile php --profile clients down --volumes --remove-orphans`; including
the clients profile also removes stopped SDK containers. Then select
`DW_PORT_RUNTIME_URL=http://native-server:8080` and a different project, start
`--profile native up -d --wait mysql native-server`, and use the same SDK worker
and probe commands, using `--profile native --profile clients` for cleanup.
A setup failure stays unsuccessful and is diagnosed before
measured windows begin.

Actual measurements retain the earlier 60-second warmup, three 120-second
windows at each declared rate, 120-second drain bound and adjacent 0.25/s drift
checks. Repeat mixed idle counts 6/12/24 three times with ten-second waits after
work drains and the SDK worker stops. Measure ordinary health/readiness while
waits are held. Keep actual admission outcomes and durations rather than
requiring the two implementations to produce invented matching timings.

Record component CPU and memory from before admission through drain and
verification, ordinary API responsiveness, all failed observations, final
work/task/registration counts, actual limits, restarts, OOM and swap. When the
probe finishes its last planned workflow before the offer window ends, keep
the runtime and observers running through the declared end of that window.
Recheck
background host activity for each pair. Missing coverage, changed host workload,
late/skipped offers, unverified outputs or an expanding backlog prevent a gain
claim. Preserve small useful results and readable outcomes, then remove raw
databases, samples, provider objects, dependencies, compiler/cache and task
resources. No capacity, migration, release or Cloud claim follows from this
development comparison.

## Setup observations

Normally installed PHP SDK 2.3.9 completes two fresh standard workflows on each
implementation. Each workflow has its exact 1,024-byte output and ordered
history. SQL inspection finds two completed activities and six completed
first-attempt tasks, zero repairs and failed jobs, and no remaining registration
after each SDK worker exits 0. Native also exits 0 on SIGTERM. The first PHP
setup completed its work but failed cleanup: inherited SIGWINCH produced CLI
exit 137 and one registration. The corrected SIGTERM recipe uses a separate
fresh database and originals; the first observation remains unsuccessful.

The cold optimized build takes 7 minutes 23 seconds with Rust 1.99.0,
LLVM 23.1.1, one build job, one CPU and a 1.5 GiB memory/no-extra-swap ceiling.
Its binary is 26,172,808 bytes; temporary Cargo state uses 176,128,000 bytes
and targets 561,360,896 bytes after the build. These are one build's elapsed
time and on-disk observations, not an application speed or peak-memory result.
All setup containers, databases, dependencies and compiler state are removed
after inspection. Full measured windows, complete resource sampling, repeats,
idle/API observations and drift remain unqualified.
