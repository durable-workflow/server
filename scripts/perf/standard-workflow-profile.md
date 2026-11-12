# Standard Workflow SQL/Redis Profile

This disposable fixture measures backend operation counters around the
[DW Standard Workflow v1](../../benchmarks/capacity/README.md) canary. It is
for finding hotspots, not for publishing workflows/second or qualifying an
alternative HTTP runtime. The PHP SDK installation comes from this profile's
independent `scripts/perf/sdk-php` lock; Server, MySQL and Redis must be exact published image
digests supplied by the caller.

From the Server repository root, set a unique Compose project and the three
image digests selected for the experiment:

```sh
export COMPOSE_PROJECT_NAME=dw-profile-unique-run-id
export COMPOSE_FILE=scripts/perf/standard-workflow-profile.compose.yml
export DW_PROFILE_SERVER_IMAGE='durableworkflow/server@sha256:<digest>'
export DW_PROFILE_MYSQL_IMAGE='mysql@sha256:<digest>'
export DW_PROFILE_REDIS_IMAGE='redis@sha256:<digest>'
docker compose up -d --build --wait server worker sdk-worker
docker compose run --rm --no-deps sdk-worker scripts/perf/standard_workflow_soak.php 20
```

The last command is a warmup and checks each completed result, status and
ordered semantic history. The profile supervises its long-running SDK worker.
Check that every intended SDK worker is running and that its container restart
count is unchanged before and after a measured window. A restart during the
window changes the load generator and invalidates that comparison.

For each measured window, read MySQL global `Questions`, `Com_select`,
`Com_insert`, `Com_update` and `Com_delete`, plus Redis
`total_commands_processed`, immediately before and after the window:

```sh
docker compose exec -T -e MYSQL_PWD=root mysql mysql -uroot -N -e \
  "SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Com_select','Com_insert','Com_update','Com_delete')"
docker compose exec -T redis redis-cli INFO stats | rg '^total_commands_processed:'
```

Use the same counter commands and elapsed-time measurement for idle controls
before and after workload. The canary is closed-loop at most one start per five
seconds; a 120-second run is:

```sh
docker compose run --rm --no-deps sdk-worker scripts/perf/standard_workflow_soak.php 120
```

MySQL global counters include Server polling and administrative traffic;
subtract the adjacent idle rates before estimating the incremental cost per
completed workflow. They are not exact per-request SQL-query counts. Keep
intrusive general-log tracing in a separate run, never mixed into the counter
window. Record the Server image digest, SDK lock version, database/cache
digests, runner commit, host identity, UTC times, raw counter deltas, completed
workflow count, errors and idle-subtraction method on the owning issue. A
capacity claim requires the separate capacity-suite workload and topology.

### Prepared-statement profiling limit

Check what MySQL Performance Schema actually records before ranking individual
queries. The Server uses prepared statements. In MySQL 8.4, global `Com_select`
can rise while `events_statements_summary_by_digest` contains mostly `COMMIT`,
`SAVEPOINT`, and connection setup rows. `events_statements_history_long` then
shows `statement/com/Prepare` and `statement/com/Execute` events with null
`SQL_TEXT` and `DIGEST_TEXT`. A top-query list from that digest table alone would
omit the application SELECTs and writes. Compare its covered statement count
with the global counters, and report the coverage gap explicitly.

For a separate diagnostic on a **disposable fixture only**, enable MySQL's
slow-query table at a zero threshold, reconnect the Server and queue worker so
their sessions inherit the setting, and run a small verified workflow sample.
Take an adjacent idle sample with the same logging configuration. Normalize
literal values before grouping the logged SQL, subtract the scaled idle rate,
and report both raw and adjusted totals. Use `MICROSECOND(query_time)` when
reading subsecond `TIME(6)` values; do not round them to zero through
`TIME_TO_SEC()` alone. The logger changes database load and can retain payload
values, so keep its raw rows out of the public repository and never use that
traced window for a throughput comparison. Disable the logger and remove the
isolated project and volumes after the diagnostic. Attribution to a particular
Server request still needs request-scoped instrumentation.

To exercise an alternative HTTP frontend with the same published application,
set `COMPOSE_FILE` to the colon-separated base file plus
`scripts/perf/fpm-experiment.compose.yml` for nginx/FPM. Append
`scripts/perf/apache-event-fpm-experiment.compose.yml` as a third file for
Apache event/FPM.
The latter overlay replaces nginx with a digest-pinned Apache event proxy while
reusing the same PHP-FPM pool. Run `docker compose config --quiet` before `up`
and verify `httpd -M` reports `mpm_event_module`. These overlays are API
experiments, not production images or capacity claims. In particular, FPM
workers remain occupied while synchronous Server long polls wait.

For a fixed local comparison, append
`scripts/perf/standard-workflow-fixed-envelope.compose.yml` immediately after
the base profile. It caps Apache HTTP at 1 CPU / 1 GiB, with separate declared
caps for the queue worker, MySQL, Redis and SDK worker. For either FPM frontend,
append `scripts/perf/fpm-fixed-envelope.compose.yml` last; it splits the same
HTTP allowance into 0.2 CPU / 128 MiB for the proxy and 0.8 CPU / 896 MiB for
FPM. This chosen split is not tuned. Use a fresh Compose project and durable
volumes for each candidate, identical published artifacts, and equal warmup
and measurement windows. The closed-loop canary's five-second start interval
does not measure saturation or maximum capacity.

For an experimental fixed offered rate, run the PHP SDK client with an offer
rate, offer window, and bounded drain (seconds):

```sh
docker compose run --rm --no-deps sdk-worker \
  scripts/perf/standard-workflow-offered.php 0.25 30 60
```

The client schedules starts every four seconds in this example and polls
in-flight executions round-robin instead of serially waiting for each result.
It validates output and semantic history after the observation window. Its
latency boundary is the client start-request beginning to the Server's
`closed_at` timestamp; the containers must share a clock. The reported
offer-window completion rate excludes the bounded drain, while the completed
count includes it. A nonzero exit means missed offer slots, late starts over
one slot, incomplete/failed workflows, a poll error, or verification failure.
The same verified history reports p50/p95/p99 intervals between the client
start and `WorkflowStarted`, then each adjacent standard workflow event through
`WorkflowCompleted`. These intervals include queueing, polling, handler work,
and persistence; they are not isolated task-wakeup latency. A missing or
out-of-order event timestamp fails verification rather than producing a partial
stage summary.
The optional fourth argument is a minimum interval in milliseconds between
client `describe()` calls (0-1000, default 0 for the original unpaced probe).
For example, append `250` to test whether unpaced observation traffic affects
the same HTTP server being measured. The output records the interval and number
of describe calls. The loop checks due starts before its pacing wait, but any
synchronous HTTP call can still make a start late. Pacing may delay when the
client notices completion; latency still uses the Server's `closed_at`.
Do not use a single short run as a capacity claim. Repeat at the same offered
rate, recheck the baseline, record host and container limits, and inspect
errors and final backlog before comparing frontends. The collector itself
adds describe requests, so verify it is not the bottleneck at higher rates.

For an idle workflow-task poll check, start the selected fresh stack without
`sdk-worker`, then run `docker compose` with the same file list and project:

```sh
docker compose run --rm --no-deps sdk-worker \
  scripts/perf/idle-poll-probe.php 4 10
```

The probe registers four synthetic workers, releases their ten-second polls
together, and reports empty waits versus explicit 429 backpressure. The optional
third argument selects `workflow`, `activity`, `query`, or `mixed` (round-robin);
the default is `workflow`. Workflow and activity waits share one admission pool,
while query waits use a separate pool. The reported timeout is the client request;
Server can clamp it (query polls default to five seconds). Change the count for a
bounded concurrency step. Run it only when the queue has no work;
an actual claimed task makes the probe fail. This tests the configured poll
admission path, not maximum HTTP concurrency or task wake latency. Use the
same fresh project, image tuple, limits, and poll count for each frontend.

To measure HTTP headroom while polls wait, append
`scripts/perf/poll-wait-apache-experiment.compose.yml` for the published Apache
image, or `scripts/perf/poll-wait-fpm-experiment.compose.yml` after the FPM
overlays. Set `DW_PROFILE_POLL_WAIT_WORKER_LIMIT` and
`DW_PROFILE_POLL_WAIT_QUERY_LIMIT` to the desired experimental caps before `up`,
and use the same file list for `run`. Workflow and activity polls share the
worker cap; query polls have their own cap. Add both caps when reserving request
workers or FPM children for ordinary API traffic. The probe's 20-second maximum
keeps its one-shot registrations live without a separate heartbeat process.
Sample `/api/ready` from another container on the same Compose network while
the polls are held; record its timeouts and response times alongside the probe
outcome and `docker stats`. The cap is not a throughput target: a held poll
occupies one Apache request worker or FPM child, so reserve capacity for
ordinary API calls and never deploy a cap based on this idle-only experiment.

Remove this project and its synthetic durable state after recording evidence:

```sh
docker compose down -v --remove-orphans
```

The Octane (FrankenPHP, Swoole, OpenSwoole) experiments and their overlays and
probes were removed in server#677: their PECL and extension downloads bypassed
the checked CA stores, and the PHP Server they measured is retired. Their
earlier evidence stays in the issues that recorded it.
