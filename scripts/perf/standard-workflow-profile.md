# Standard Workflow SQL/Redis Profile

This disposable fixture measures backend operation counters around the
[DW Standard Workflow v1](../../benchmarks/capacity/README.md) canary. It is
for finding hotspots, not for publishing workflows/second or qualifying an
alternative HTTP runtime. The PHP SDK installation comes from the binding's
locked Composer manifest; Server, MySQL and Redis must be exact published image
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
ordered semantic history. For each measured window, read MySQL global
`Questions`, `Com_select`, `Com_insert`, `Com_update` and `Com_delete`, plus Redis
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
  scripts/perf/standard-workflow-offered.php 1 30 60
```

The client schedules starts at one-second intervals in this example and polls
in-flight executions round-robin instead of serially waiting for each result.
It validates output and semantic history after the observation window. Its
latency boundary is the client start-request beginning to the Server's
`closed_at` timestamp; the containers must share a clock. The reported
offer-window completion rate excludes the bounded drain, while the completed
count includes it. A nonzero exit means missed offer slots, late starts over
one slot, incomplete/failed workflows, a poll error, or verification failure.
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
together, and reports empty waits versus explicit 429 backpressure. Change the
count for a bounded concurrency step. Run it only when the queue has no work;
an actual claimed task makes the probe fail. This tests the configured poll
admission path, not maximum HTTP concurrency or task wake latency. Use the
same fresh project, image tuple, limits, and poll count for each frontend.

To measure HTTP headroom while polls wait, append
`scripts/perf/poll-wait-apache-experiment.compose.yml` for the published Apache
image, or `scripts/perf/poll-wait-fpm-experiment.compose.yml` after the FPM
overlays. Set `DW_PROFILE_POLL_WAIT_LIMIT` to the desired experimental cap
before `up`, and use the same file list for `run`. The probe's 20-second maximum
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
