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

Remove this project and its synthetic durable state after recording evidence:

```sh
docker compose down -v --remove-orphans
```
