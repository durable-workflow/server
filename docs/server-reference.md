# Durable Workflow Server Reference

Detailed self-hosting, HTTP API, authentication, deployment, and configuration
reference for [Durable Workflow Server](../README.md).

## Quick Start

### Official Image + SQLite

Use this path when you want to validate the published image without cloning the
repository or starting MySQL or Redis. The native image defaults to SQLite at
`/app/database/database.sqlite` and creates its schema on first start, in a
single container. Mount `/app/database` to keep the database across
restarts.

Pin an immutable version tag or digest for production change control.

```bash
export DW_SERVER_IMAGE="durableworkflow/server:3.28.0"
export DW_AUTH_TOKEN=dev-token
docker volume create durable-workflow-sqlite

docker run --rm --name durable-workflow-server \
  -p 127.0.0.1:8080:8080 \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  "$DW_SERVER_IMAGE"
```

The `schema-bootstrap` command is for MySQL, MariaDB and PostgreSQL; SQLite
needs no separate bootstrap or queue worker.

In another terminal:

```bash
curl http://localhost:8080/api/health
curl http://localhost:8080/api/ready
curl -H "Authorization: Bearer $DW_AUTH_TOKEN" \
  http://localhost:8080/api/cluster/info

curl -X POST http://localhost:8080/api/worker/register \
  -H "Authorization: Bearer $DW_AUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{"worker_id":"quickstart-worker","task_queue":"quickstart","runtime":"python"}'
```

Use Redis or another shared cache backend for multi-node deployments. The file
cache default is intentionally scoped to the one-container SQLite quickstart.

#### SQLite mount identity

Mount the SQLite database's parent directory so the HTTP process can write both
the database and its journal files. For a custom `DB_DATABASE`, the nearest
existing parent must also allow that identity to create any missing directories.

Root startup delegates database creation and permission checks to
`APACHE_RUN_USER` and `APACHE_RUN_GROUP` (both default to `www-data`). Names and
Apache's numeric `#UID` / `#GID` form are supported. Nonroot startup uses its
current UID/GID; keep bootstrap, HTTP and other SQLite roles aligned. The image
resolves identities at runtime rather than assuming a host UID.

New files honor the caller's umask. Existing database bytes, ownership and modes
are preserved. An incompatible file or parent fails startup with the required
UID/GID and a mount-permission explanation, before configuration caching or
migrations. Correct the mount's intended permissions before retrying; startup
does not repair an existing operator-owned file's permissions.

Images affected by [#706](https://github.com/durable-workflow/server/issues/706)
can instead create a fresh custom file as root and fail on the first HTTP write.
Use an image containing that fix or align all roles to the actual HTTP identity.

#### Read-only root filesystem

For a native Server with a read-only container root, keep the local payload
volume writable at `/app/storage`. With SQLite, also mount a writable `/tmp`
and keep the database's parent directory writable as described above. The
native schema-upgrade command only needs writable access to the database it
upgrades.

The PHP 2.x image with MySQL or PostgreSQL, Redis-backed cache and queue, and
stderr logging needs writable `/tmp` for each role. Set
`APP_CONFIG_CACHE=/tmp/durable-workflow-config.php` to put the generated Laravel
config cache there. The HTTP role also needs writable `/var/run/apache2` for
the PID file. Grant those mounts write access to the container identity.
If you select file logging, file cache or local payload storage, mount the
corresponding paths under `/app/storage` too. Use durable shared storage for
payloads that outlive the pod or need to be read by other instances.

The [Helm chart](../k8s/helm/durable-workflow/README.md#read-only-root-filesystems)
configures the tested mounts for its standard deployments.

### Official Image + Compose

Use this path when you want a source-free stack: the native server backed by
MySQL. The same Compose file supports local development and single-node
production; the difference is the environment you provide and the operational
care around persistence, backups, and upgrades.

Image selection:

- The checked-in Compose file defaults to the current native release, pinned
  by its `x-server-image` default.
- `DW_SERVER_IMAGE=ghcr.io/durable-workflow/server:<server-version>`
  selects the same release line from GitHub Container Registry.
- `DW_SERVER_IMAGE=durableworkflow/server@sha256:...` pins an exact image
  digest for production change control.

#### Local Development Compose

This recipe is for one developer machine or internal non-production testing. It
runs the native server with MySQL in named volumes, publishes only the API port
and only on `127.0.0.1`, and uses the shared `DW_AUTH_TOKEN` for quick
verification. Compose refuses to start until `DW_AUTH_TOKEN` is set. The PHP 2.x stack
(server, queue worker, scheduler, bootstrap job and Redis) is
`docker-compose.php.yml`; the PHP-specific procedures below that run `artisan`
commands apply to it.

```bash
curl -fsSLO https://raw.githubusercontent.com/durable-workflow/server/main/docker-compose.published.yml

export DW_AUTH_TOKEN=dev-token

docker compose -f docker-compose.published.yml up -d --wait
```

Verify health, readiness, cluster discovery, and worker registration:

```bash
curl http://localhost:8080/api/health
curl http://localhost:8080/api/ready
curl -H "Authorization: Bearer $DW_AUTH_TOKEN" \
  http://localhost:8080/api/cluster/info

curl -X POST http://localhost:8080/api/worker/register \
  -H "Authorization: Bearer $DW_AUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{"worker_id":"compose-worker","task_queue":"compose","runtime":"python"}'
```

#### Single-Node Production Compose

This recipe is for a small self-hosted deployment on one Docker host. It keeps
MySQL in a named volume, persists the native server's local payload storage in
`server_storage`, exposes only the API port, and expects a strong shared token,
role-scoped credentials and an exact image tag or digest.

Create a production env file outside source control:

```env
DW_SERVER_IMAGE=durableworkflow/server:<server-version>
SERVER_PORT=8080
# Loopback by default. Use a protected private interface for the reverse proxy.
SERVER_BIND_ADDRESS=127.0.0.1

DB_DATABASE=durable_workflow
DB_USERNAME=workflow
DB_PASSWORD=replace-with-random-password
DB_ROOT_PASSWORD=replace-with-random-root-password

DW_AUTH_DRIVER=token
# Native requires a shared token and accepts it for every role; keep it secret.
DW_AUTH_TOKEN=replace-with-random-shared-token
DW_WORKER_TOKEN=replace-with-worker-token
DW_OPERATOR_TOKEN=replace-with-operator-token
DW_ADMIN_TOKEN=replace-with-admin-token
```

Start the stack and run the same readiness checks:

```bash
docker compose --env-file durable-workflow.prod.env \
  -f docker-compose.published.yml up -d --wait

curl http://localhost:8080/api/health
curl http://localhost:8080/api/ready
curl -H "Authorization: Bearer $(grep '^DW_ADMIN_TOKEN=' durable-workflow.prod.env | cut -d= -f2-)" \
  http://localhost:8080/api/cluster/info
```

Register SDK workers with `DW_WORKER_TOKEN` and send operator traffic with
`DW_OPERATOR_TOKEN`. Operator and admin credentials may also call
`/api/worker/register` for diagnostic worker registration, while heartbeats,
task polling, task completion, and orderly self-deregistration remain
worker-token endpoints. Put TLS, request logging, and public routing in a
reverse proxy in front of the API container; do not expose the MySQL
service.

Persistence and backups:

- `mysql_data` is the durable workflow state. Back it up before every image
  upgrade and on a regular schedule.
- `server_storage` holds the native server's local external payload objects.
  Back it up with `mysql_data`; a recovery point needs both.
- Keep a copy of the exact env file and image reference with each backup so a
  restore uses the same auth, database, and image contract.
- External payload bytes stored outside the server (S3) are outside these volumes. Follow the
  [online backup coordination procedure](contracts/external-payload-storage.md#coordinating-online-backups)
  and verify a copy of the referenced objects before accepting a recovery point.

Backup and restore examples:

The dump options take a consistent snapshot of Server's InnoDB tables without
locking them for the full backup and avoid requiring `PROCESS` privilege for
tablespace metadata. Do not run migrations or other schema changes during the
dump. Keep the external-payload backup hold active until the database dump and
all referenced objects have been copied and verified.

```bash
docker compose --env-file durable-workflow.prod.env \
  -f docker-compose.published.yml exec -T mysql \
  sh -lc 'mysqldump --single-transaction --quick --no-tablespaces -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  > durable-workflow-$(date +%Y%m%d%H%M%S).sql

docker compose --env-file durable-workflow.prod.env \
  -f docker-compose.published.yml exec -T mysql \
  sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  < durable-workflow-backup.sql
```

Review dump errors even if the command exits successfully, and verify each
recovery point with a restore. Import only into an isolated replacement stack
with Server, worker, and scheduler stopped. Restore and verify external payload
objects before starting those services and shifting traffic.
Follow the [complete self-hosted backup and restore procedure](self-hosted-backup-and-restore.md)
for the backup hold, object manifest, recovery-point boundary and verification.

Upgrade order:

1. Back up MySQL and record the current image reference.
2. Change only `DW_SERVER_IMAGE` in the env file.
3. Run `docker compose --env-file durable-workflow.prod.env -f docker-compose.published.yml pull`.
4. Run `docker compose --env-file durable-workflow.prod.env -f docker-compose.published.yml up -d --wait`.
5. Confirm `/api/ready`, `/api/cluster/info`, and worker registration before
   shifting external traffic.

For the PHP 2.x stack (`docker-compose.php.yml`): the release that introduces
`SignalRejected` requires a stop-first upgrade.
Older Core readers cannot decode this persisted history event. After pulling
the new image, pause admission, drain external workers and stop the existing
`server`, `worker` and `scheduler` services before starting their replacements.
Stop any additional matching processes and embedded or direct-database readers,
including Waterline when it shares the database. Upgrade those readers before
resuming writes. Keep database, Redis and external-payload storage intact.
Do not roll an old reader back onto a database containing the new event; repair
forward or restore the complete pre-upgrade recovery point. See the
[chart reader-upgrade procedure](../k8s/helm/durable-workflow/docs/UPGRADING.md#01182)
for the equivalent Kubernetes ordering.

The image generates an internal runtime key automatically. Set `DW_SERVER_KEY`
only if your deployment needs that key to remain stable across container
replacement.

The published Compose smoke workflow runs this file in both `local` and
`production` profiles for amd64 and arm64. The `local` profile validates the
single-token development recipe; the `production` profile validates role-scoped
worker/admin tokens with backward-compatible auth disabled.

### Small Cluster Status

Small clustered deployments without Kubernetes are validated as a narrow public
support boundary, not as a general HA promise. The current supported shape uses
external MySQL or PostgreSQL plus 2 or 3 API nodes behind a stateless load
balancer, shared Redis, at least one server queue worker, and independently
scaled external workers. The first contract requires exactly one scheduler or
maintenance runner. SQLite, Redis-less multi-node mode, duplicate schedulers,
rolling upgrades, active/active multi-region, Helm-based Kubernetes deployments,
and provider-specific failover semantics are not part of that first contract.

The CI harness in `docker-compose.small-cluster.yml` runs the MySQL and
PostgreSQL variants with two API nodes, one bootstrap job, one Redis queue
worker, one scheduler, shared Redis, load-balanced health/readiness/cluster-info
checks, external worker registration, and a workflow-task poll on one API node
followed by completion on the other. The support boundary and harness details
live in
[`small-cluster-validation.md`](small-cluster-validation.md).

### Multi-Region Status

Active/passive multi-region with operator-driven regional failover is a
self-serve contract. One region runs the validated single-region or
small-cluster shape, and one standby region holds an asynchronously
replicated standby database, optional standby Redis, and idle API/worker
containers. The singleton scheduler/maintenance runner runs in the active
region only; failover starts it in the promoted region after the database
is promoted. Active/active multi-region, automatic regional failover, and
synchronous cross-region replication remain support-led. The contract,
operator runbook, and rehearsal expectations live in
[`multi-region-validation.md`](multi-region-validation.md).

### Docker Compose

```bash
# Clone the repository
git clone https://github.com/durable-workflow/server.git
cd server

# Copy environment config
cp .env.example .env

# Start the server with all dependencies
docker compose up -d

# Verify
curl http://localhost:8080/api/health
curl http://localhost:8080/api/ready
```

Compose runs a one-shot `bootstrap` service before the API and worker
containers start. That service calls the image's `server-bootstrap` command,
which runs migrations and seeds the default namespace.
The long-running `server`, `worker`, and `scheduler` services each pin
`DW_SERVER_TOPOLOGY_SHAPE` and `DW_SERVER_PROCESS_CLASS` so
`GET /api/cluster/info` reports the role class you actually launched during
local split-role testing.
The local compose files leave the Workflow build arguments empty, so the
Dockerfile derives the exact package source, version, and commit from
`composer.json` and `composer.lock`. Supplying `WORKFLOW_PACKAGE_SOURCE`,
`WORKFLOW_PACKAGE_REF`, or `WORKFLOW_PACKAGE_COMMIT` turns that value into a
consistency guard; a value that disagrees with Composer metadata fails the
build.

### Using the CLI

```bash
# Install the current stable CLI
curl -fsSL https://durable-workflow.com/install.sh | sh
export PATH="$HOME/.local/bin:$PATH"
```

```bash
# Start a workflow
dw workflow start --type=my-workflow --input='{"name":"world"}'

# List workflows
dw workflow list

# Check server health
dw server health
```

## Getting Started: End-to-End Workflow

This walkthrough shows the full lifecycle using `curl` — start the server,
create a workflow, poll for tasks, and complete them. Any HTTP client in any
language follows the same steps.

Set role tokens for convenience (or set `DW_AUTH_DRIVER=none` in
`.env` to skip auth during development). If you only configure the legacy
`DW_AUTH_TOKEN`, use the same value for each variable below.

```bash
export ADMIN_TOKEN="your-admin-token"
export OPERATOR_TOKEN="your-operator-token"
export WORKER_TOKEN="your-worker-token"
export SERVER="http://localhost:8080"
```

### 1. Check Server Health

```bash
curl $SERVER/api/health
```

```json
{"status":"serving","timestamp":"2026-04-13T12:00:00Z"}
```

### 2. Create a Namespace (or Use the Default)

The bootstrap seeds a `default` namespace. To create a dedicated one:

```bash
curl -X POST $SERVER/api/namespaces \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{"name":"my-app","description":"My application namespace","retention_days":30}'
```

Namespaces use an explicit history-retention mode. `bounded` is the default and
requires `retention_days` between 1 and 365. Protected namespaces can retain
terminal history until an authorized delete or archive request:

```bash
curl -X POST $SERVER/api/namespaces \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{"name":"protected","retention_mode":"forever"}'
```

### 3. Start a Workflow

```bash
curl -X POST $SERVER/api/workflows \
  -H "Authorization: Bearer $OPERATOR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{
    "workflow_id": "order-42",
    "workflow_type": "orders.process",
    "task_queue": "order-workers",
    "input": ["order-42", {"rush": true}],
    "execution_timeout_seconds": 3600,
    "run_timeout_seconds": 600
  }'
```

```json
{
  "workflow_id": "order-42",
  "run_id": "abc123",
  "workflow_type": "orders.process",
  "status": "pending",
  "outcome": "started_new"
}
```

### 4. Register a Worker

Before polling, register the worker with the server:

```bash
curl -X POST $SERVER/api/worker/register \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{
    "worker_id": "worker-1",
    "task_queue": "order-workers",
    "runtime": "python",
    "supported_workflow_types": ["orders.process"],
    "workflow_definition_fingerprints": {
      "orders.process": "sha256:..."
    }
  }'
```

When a worker re-registers the same active `worker_id`, any advertised
workflow type must keep the same `workflow_definition_fingerprints` value. A
changed fingerprint is rejected with `workflow_definition_changed`; restart
the process with a new worker id before serving a changed workflow class.
Workers that omit fingerprints during re-registration cannot clear previously
stored fingerprints for workflow types they still advertise; the server keeps
the stored value until a new worker id is used.

Existing runs retain their original definition fingerprint, build pin, history
and deadlines. By default, the Server prefers a fresh eligible worker with the
recorded definition. When that definition is no longer available in the run's
namespace, queue, workflow type and build, a replacement definition can claim
the original task only when it advertises `workflow_entry_arguments_validation`,
and must pass the SDK's strict replay checks. This worker capability declares
pre-entry refusal without failing the business run; older worker implementations
do not gain it from their runtime or protocol version. `entry_validation_unavailable`
in `definition_routing` identifies a replacement that needs an SDK upgrade.
Use versioned
branches such as `get_version` for changes to durable commands. A different
source fingerprint alone is not proof of incompatible replay.

`DW_V2_PIN_TO_RECORDED_FINGERPRINT=false` removes the matching-source preference.
It does not remove build pins, namespace or queue isolation, workflow-type and
capability checks, immutable registration identity, lease fences or strict replay.
Run detail, run debug, repair responses and queue detail expose
`definition_routing`. `replay_required` is an allowed fallback, while
`blocked=true` means a due ready workflow task has no eligible definition route.
Repair can fix transport state; it cannot manufacture a compatible worker or
rewrite the original definition. Queue observations contain at most ten run
examples and explicitly report whether more ready runs exist.

### 5. Poll for Workflow Tasks

The server holds the connection open (long-poll) until a task is ready or
the timeout expires:

```bash
curl -X POST $SERVER/api/worker/workflow-tasks/poll \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{
    "worker_id": "worker-1",
    "task_queue": "order-workers"
  }'
```

The response includes the task, its history events, and lease metadata:

```json
{
  "protocol_version": "1.13",
  "task": {
    "task_id": "task-xyz",
    "workflow_id": "order-42",
    "run_id": "abc123",
    "workflow_type": "orders.process",
    "workflow_task_attempt": 1,
    "lease_owner": "worker-1",
    "task_queue": "order-workers",
    "history_events": [
      {"sequence": 1, "event_type": "StartAccepted", "...": "..."},
      {"sequence": 2, "event_type": "WorkflowStarted", "...": "..."}
    ]
  }
}
```

### 6. Complete a Workflow Task

Replay history, execute logic, and return commands. To schedule an activity:

```bash
curl -X POST $SERVER/api/worker/workflow-tasks/task-xyz/complete \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{
    "lease_owner": "worker-1",
    "workflow_task_attempt": 1,
    "commands": [
      {
        "type": "schedule_activity",
        "activity_type": "orders.send-confirmation",
        "task_queue": "order-workers",
        "input": ["order-42"]
      }
    ]
  }'
```

To complete the workflow (terminal command):

```bash
curl -X POST $SERVER/api/worker/workflow-tasks/task-xyz/complete \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{
    "lease_owner": "worker-1",
    "workflow_task_attempt": 1,
    "commands": [
      {
        "type": "complete_workflow",
        "result": {"status": "shipped", "tracking": "TRK-123"}
      }
    ]
  }'
```

### 7. Poll and Complete Activity Tasks

If the workflow scheduled activities, poll for them on the same (or different) queue:

```bash
# Poll
curl -X POST $SERVER/api/worker/activity-tasks/poll \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{"worker_id": "worker-1", "task_queue": "order-workers"}'

# Complete (use task_id and activity_attempt_id from the poll response)
curl -X POST $SERVER/api/worker/activity-tasks/TASK_ID/complete \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.13" \
  -d '{
    "activity_attempt_id": "ATTEMPT_ID",
    "lease_owner": "worker-1",
    "result": "confirmation-sent"
  }'
```

### 8. Check Workflow Status

```bash
curl $SERVER/api/workflows/order-42 \
  -H "Authorization: Bearer $OPERATOR_TOKEN" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2"
```

### 9. View Event History

```bash
curl "$SERVER/api/workflows/order-42/runs/abc123/history" \
  -H "Authorization: Bearer $OPERATOR_TOKEN" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2"
```

### Supported Workflow Task Commands

| Command | Terminal | Description |
|---------|----------|-------------|
| `complete_workflow` | Yes | Complete workflow with a result |
| `fail_workflow` | Yes | Fail workflow with an error |
| `continue_as_new` | Yes | Continue as a new run |
| `schedule_activity` | No | Schedule an activity for execution |
| `start_timer` | No | Start a durable timer |
| `start_child_workflow` | No | Start a child workflow |
| `record_side_effect` | No | Record a non-deterministic value |
| `record_version_marker` | No | Record a version marker |
| `upsert_search_attributes` | No | Update search attributes |
| `signal_external_workflow` | No | Signal another workflow in the namespace (native) |

Retry and timeout fields are scoped to the command layer they control.
`schedule_activity` accepts activity `retry_policy`,
`start_to_close_timeout`, `schedule_to_start_timeout`,
`schedule_to_close_timeout`, and `heartbeat_timeout`; heartbeat, start, and
schedule-to-start budgets cannot exceed schedule-to-close or start-to-close
where those outer budgets are present. `start_child_workflow` accepts child
workflow `retry_policy`, `execution_timeout_seconds`, and
`run_timeout_seconds`; the run timeout cannot exceed the execution timeout.
`non_retryable` is only a failure outcome flag on `fail_workflow` and
`fail_update`. HTTP transport retry policy is configured by clients outside the
workflow-task command payload.

`signal_external_workflow` (native, advertised as
`capabilities.signal_external_workflow`) signals another workflow in the
namespace by `workflow_id`, with an optional `run_id`, a `signal_name` and an
Avro `arguments` envelope. The completion delivers the signal on the target as
an HTTP signal is admitted, records `ExternalSignalRequested` and then
`ExternalSignalDelivered` or `ExternalSignalFailed` (`target_not_found`,
`target_closed` or `rejected`) at the command's sequence, and gives the sender
one workflow task to read the outcome. Replay reads the recorded outcome and
never sends the signal again. A worker holding a workflow task can therefore
signal any workflow in that task's namespace without the operator or admin
role an HTTP signal needs; the power is namespace-scoped, as in Temporal. The
`workflow-signal:` `request_id` prefix is reserved for these deliveries, and
HTTP signals that use it are refused with `422 request_id_reserved`. See the
[signal-external contract](contracts/signal-external-workflow.md).

## API Overview

### System
- `GET /api/health` — Health check plus a machine-readable topology summary for the current node
- `GET /api/ready` — Bounded readiness check for migrations, configured queue storage, default namespace, cache, auth config, fail-closed backend/fleet admission, and the current node topology summary
- `GET /api/cluster/info` — Bounded server compatibility and capability discovery used by SDK and CLI preflight
- `GET /api/cluster/info?include=diagnostics` — Compatibility discovery plus explicit coordination health, fleet detail, task-repair diagnostics, and operator metrics
- `GET /api/system/health` — Full rollout-safety health snapshot for the requested namespace, including check status, categories, routing-drain state, operator metrics, and structural limits
- `GET /api/system/metrics` — Server metrics including bounded stuck workflow-task diagnostics
- `GET /api/system/operator-metrics` — Full operator metrics snapshot (runs, tasks, backlog, repair, workers/fleet, backend, structural limits) for namespace-scoped rollout-safety coordination health
- `GET /api/system/operator-dashboard/bounded` returns dashboard aggregates without decoding fleet-wide histories. History-audit counts are `null` and `operator_metrics.history_audit_evaluation` is `not_requested`. Use `/api/system/operator-dashboard` or `/api/system/operator-metrics` when a full history audit is needed. Both dashboard routes require operator authentication, the control-plane version header and a namespace.
- `GET /api/system/operator-dashboard/bounded/workflow-types?workflow_types=<JSON array>` restricts workflow counts, trends, failures, waits and workflow alerts to the specified exact workflow types in the authenticated namespace. URL-encode the JSON array. An empty array selects no workflow types. Worker, queue and storage metrics retain their namespace scope. The response identifies both scopes and the aggregation windows in `workflow_scope`, `operator_metrics_scope` and `time_windows`.
- `GET /api/system/repair` — Task repair diagnostics
- `POST /api/system/repair/pass` — Run task repair sweep
- `GET /api/system/activity-timeouts` — Expired activity execution diagnostics
- `POST /api/system/activity-timeouts/pass` — Enforce activity timeouts
- `GET /api/system/external-payload-cleanup` — External-payload cleanup backlog, outcomes, and storage-failure diagnostics
- `POST /api/system/external-payload-cleanup/pass` — Retry one bounded namespace cleanup pass

- `GET /metrics` — Native only (Server #534). Prometheus text exposition of the requested namespace's task queue backlog, admission, retry pressure, schedule state and run and activity series, for Prometheus, KEDA and HPA adapters. See [Prometheus scrape and autoscaling](#prometheus-scrape-and-autoscaling).

`POST /api/system/repair/pass` accepts optional `connection`, `queue`,
`run_ids`, and `instance_id` filters. Set `respect_throttle=true` when a
dedicated matching-role loop should skip a pass rather than duplicate work
already covered by another matching-role process holding the repair throttle.

The native completion transport correction for [#1136](https://github.com/durable-workflow/server/issues/1136)
adds `limits.max_workflow_task_completion_bytes` to cluster discovery. It is the
actual cap on the complete encoded JSON HTTP request body for workflow-task
completion and activity continuation completion, including envelopes, metadata
and commands. The default is 33,554,432 bytes. `limits.max_payload_bytes` remains
the per-value and ordinary API request limit; a decoded argument sum does not
measure completion wire size. SDKs use the explicit optional field for those
two completion routes and preserve their conservative fallback when it is absent.
A positive integer smaller than the ordinary cap is still authoritative for
completion. Explicit malformed, null, zero, negative or non-integer values
refuse completion planning before upload or submission; ordinary routes ignore
this unused field.

A completion over that cap returns HTTP 413 with `reason: payload_too_large`,
`limit_kind: workflow_task_completion_bytes` and the actual byte `limit`, before
any workflow state changes. The server discards at most 64 MiB of remaining
upload data for at most two seconds before closing the HTTP/1 connection. This
lets ordinary within-budget uploads finish sending and receive the typed refusal;
clients that exceed either discard bound can still encounter a transport failure.
The [shared size vectors](../resources/platform-protocol-specs/workflow-task-completion-limits-v1-vectors.json)
cover exact and over-cap bodies on both completion routes. Published SDK worker
qualification remains required before claiming this correction delivered.

Native never writes an admitted completion to one database row
([#1234](https://github.com/durable-workflow/server/issues/1234)). The
completion receipt that lets an identical retry replay the original answer keeps
a SHA-256 digest of the request and the created task IDs, not the request body;
each command's arguments are stored in their own rows and are bounded by
`limits.max_payload_bytes`. A completion up to the 32 MiB default cap therefore
records on MariaDB with its 16 MiB default `max_allowed_packet`, as well as on
MySQL, PostgreSQL and SQLite, without raising any database setting. Receipts
written by an earlier native release keep the full request and still replay.

On MySQL and MariaDB, native reads `max_allowed_packet` at startup and logs
`database_packet_limit`, or `database_packet_limit_below_payload_headroom` when
the packet is below four times `DW_MAX_PAYLOAD_BYTES` (8 MiB with the default
2 MiB payload limit). Raise `max_allowed_packet` or lower `DW_MAX_PAYLOAD_BYTES`
when that warning appears: below that headroom a single large argument can
exceed the packet. When the database reports such a refusal (MySQL error 1153
or 1301) for a completion, nothing is recorded and native answers HTTP 413
with `reason: payload_too_large`, `limit_kind: database_max_allowed_packet` and
the packet size as `limit`, which is not retryable. A database that instead
drops the connection mid-statement still surfaces as
`503 storage_unavailable`, so keep the recommended headroom.

### Namespaces
- `GET /api/namespaces` — List namespaces
- `POST /api/namespaces` — Create namespace
- `GET /api/namespaces/{name}` — Get namespace
- `PUT /api/namespaces/{name}` — Update namespace
- `DELETE /api/namespaces/{name}` — Delete namespace and clean up its runtime state
- `PUT /api/namespaces/{name}/external-storage` — Configure external payload storage policy
- `POST /api/namespaces/{name}/retention-plan` — Preview a proposed retention policy without applying it (native Server)
- `POST /api/namespaces/{name}/retention-plan/apply` — Apply a planned retention policy only while the plan still holds (native Server)

The native Server uses `DW_HISTORY_RETENTION_DAYS` (1–365 days, default 30)
to seed the initial `default` namespace and when you create a bounded
namespace without a retention period. The stored policy governs each
namespace. Inspect it with `GET /api/namespaces/{name}`. To correct a policy
seeded before this behavior or change it after creation, preview and apply a
retention plan as described below.

#### Retention plan

`PUT /api/namespaces/{name}` activates a new retention policy at once, and the
next retention pass applies it to existing closed runs. To see the impact
first, an admin can send the proposed `retention_mode` and `retention_days` to
`POST /api/namespaces/{name}/retention-plan`. The fields follow the namespace
update's rules, and a field left out keeps its current value. Optional
`scan_limit` (default 1000, at most 10000) bounds each count, and
`hold_scan_limit` (default 100, at most 1000) bounds how many runs are checked
for holds.

```bash
curl -X POST $SERVER/api/namespaces/my-app/retention-plan \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{"retention_days":7}'
```

The answer has schema `durable-workflow.v2.retention-plan.v1`. It reports:

- `current_policy` and `proposed_policy`, each with its cutoff.
- `policy_revision`, a SHA-256 over the namespace, its stored retention mode
  and days, the days in effect and the namespace's `updated_at`. Any change
  to the retention mode or days produces a new revision. Other namespace
  updates move `updated_at` too, so they usually produce a new revision, but
  only at the stored precision. PostgreSQL and MySQL round `updated_at` to
  the nearest whole second and MariaDB truncates it to the second, so an
  update that stores the same second as the previous namespace write leaves
  the revision unchanged. The server writes SQLite times with milliseconds.
- `plan_revision`, a SHA-256 over `policy_revision` and the proposed mode and
  days. It binds the plan to both the current policy and this exact proposal.
- `retroactive`: `true` when the proposal makes closed runs eligible that the
  current policy keeps (fewer days, or a bounded policy replacing `forever`).
- `changes`: closed, unarchived runs that become eligible for pruning
  (`newly_eligible`), stop being eligible (`no_longer_eligible`), stay
  eligible (`unchanged_eligible`) or stay retained (`unchanged_retained`).
  Each lists up to five example runs, oldest first.
- `exclusions`: archived runs past the proposed cutoff (`archived`), closed
  runs a `forever` proposal keeps (`forever`), open runs (`not_terminal`), and
  eligible runs a prune would currently skip for an open abandoned activity
  (`detached_activity_hold`), a payload reference native cannot settle
  (`external_payload_cleanup_unavailable`) or an active online backup hold
  (`backup_hold`).

Each count carries `count`, `lower_bound` and `complete`. A count that reaches
its bound has `count: null` and `complete: false`, and `incomplete` names it.
Treat such a count as a minimum, not an exact figure.

The plan makes no writes. It changes no policy, marks no row, fetches no
payload object and prunes nothing. It reads one snapshot, so it describes that
moment only. Runs can close, age past the cutoff, be archived or gain and lose
holds, and the policy can change before the next retention pass. It is not a
fixed list of what a later prune will delete.

#### Guarded apply

To apply a planned policy, send the same proposal with the plan's
`policy_revision` and `plan_revision` to
`POST /api/namespaces/{name}/retention-plan/apply`. A retroactive proposal
also needs `"confirm_retroactive_pruning": true`, because pruning is
irreversible. Like the plan, the apply needs an admin.

```bash
curl -X POST $SERVER/api/namespaces/my-app/retention-plan/apply \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{"retention_days":7,"policy_revision":"sha256:…","plan_revision":"sha256:…","confirm_retroactive_pruning":true}'
```

The checks and the update run in one write transaction, which every namespace
update also takes, so no policy change can land in between. The apply refuses
and changes nothing when:

| Status and reason | Cause |
| --- | --- |
| `409 retention_policy_revision_stale` | The namespace changed after the plan. The answer carries the current `policy_revision`. Request a new plan. |
| `409 retention_plan_revision_mismatch` | The policy sent is not the policy that was planned. Plan this policy first. |
| `422 retention_pruning_confirmation_required` | The proposal is retroactive and `confirm_retroactive_pruning` is not `true`. |
| `422` validation errors, `422 invalid_namespace_definition` | A revision is missing, or the proposal breaks the namespace update's rules. |

An accepted apply answers with schema `durable-workflow.v2.retention-apply.v1`:
`applied_at`, `retroactive`, `confirmed_retroactive_pruning`, the
`previous_policy` with its `previous_policy_revision`, and the applied
`policy` with its new `policy_revision`. A plan that changes the retention
mode or days cannot be applied twice, because applying it changes the
revision. A plan that proposes the current mode and days leaves the policy
unchanged but still moves `updated_at`, which can make other outstanding
plans for the namespace stale. Applying it again is accepted only if the
stored `updated_at` did not change (on PostgreSQL, MySQL and MariaDB, the
same stored second), and then rewrites the same policy. Otherwise it is
refused as stale.

The apply changes only the namespace's policy. It marks, archives and prunes
no run. The policy is active from `applied_at`. Each later retention pass
prunes the closed, unarchived runs past the cutoff as it stands at that pass.
That can include runs that closed or aged after the plan, and it skips runs
archived or held at that time. The plan's counts were a point-in-time scan,
not the set that will be deleted.

`PUT /api/namespaces/{name}` is unchanged. It still activates a policy at once
with no plan or confirmation, so existing clients keep working.

When a namespace enables external payload storage, remote clients upload and
fetch encoded bytes through `/api/external-payloads/v1` using their normal
namespace runtime credential. Workflow, activity, signal, query, update,
schedule, stream, result, and history surfaces carry only codec-tagged opaque
runtime references; backing provider URIs and credentials never cross the HTTP
boundary. The server verifies declared size and SHA-256 before a reference can
be committed to workflow state and verifies backing bytes again before fetch.
History retention and namespace cleanup remain authoritative for backing-object
deletion. See [the runtime external payload transport contract](contracts/external-payload-storage.md)
for the versioned reference shape, discovery fields, limits, expiry, cache, and
typed failure outcomes.

### Workflows
- `GET /api/workflows` — List workflows (with filters)
- `POST /api/workflows` — Start a workflow
- `GET /api/workflows/{id}` — Describe a workflow
- `GET /api/workflows/{id}/runs` — List runs (continue-as-new chain)
- `GET /api/workflows/{id}/runs/{runId}` — Describe a specific run
- `GET /api/workflows/{id}/debug` — Bounded support diagnostic for the current run
- `GET /api/workflows/{id}/runs/{runId}/debug` — Bounded support diagnostic for a specific run
- `POST /api/workflows/{id}/signal/{name}` — Send a signal
- `POST /api/workflows/{id}/signal-with-start/{name}` — Start or reuse the run and send a signal atomically (native only)
- `POST /api/workflows/{id}/query/{name}` — Execute a query
- `POST /api/workflows/{id}/update/{name}` — Execute an update
- `POST /api/workflows/{id}/cancel` — Request cancellation
- `POST /api/workflows/{id}/terminate` — Terminate immediately
- `POST /api/workflows/{id}/repair` — Request repair for retryable stuck state
- `POST /api/workflows/{id}/archive` — Archive a closed workflow run

Instance-targeted signal, query, update, cancel, terminate, repair, and archive
routes operate on the current run for the workflow id. Run-targeted command
variants under `/api/workflows/{id}/runs/{runId}/...` are available for
signal, query, update, cancel, terminate, repair, and archive; they only forward
when `{runId}` is the current run and reject historical-run commands with `409`
and `reason: "historical_run_command_rejected"`.

#### Signal-with-start

`POST /api/workflows/{id}/signal-with-start/{name}` starts the workflow or
reuses its open run and admits one signal on that run in one transaction
(native Server, advertised as `capabilities.signal_with_start`; published PHP
Server 2.x has no such route). Use it for an entity workflow that must
receive every event even if its run closes between a separate start and
signal. It is modeled on embedded `WorkflowStub::signalWithStart` but
deliberately differs from Workflow 2.33.0 in three ways (see below).

The body is a start request (`workflow_type`, `task_queue`, `input`,
timeouts, `memo`, `search_attributes`, `business_key`, priority and fairness)
plus three intake fields:

| Field | Meaning |
| --- | --- |
| `signal_input` | The signal's arguments, in the same forms as a signal's `input`. |
| `request_id` | Optional. The signal's durable command key (see below). |
| `closed_run_policy` | `reject` (default) or `start_new_run`. What a closed current run means. |

`workflow_id` comes from the path; a different body `workflow_id` is a 422.
`duplicate_policy` defaults to `use-existing`.

| Current state of the instance | Result |
| --- | --- |
| No instance | A new run starts under the request's options and the signal joins it. `201`, `start_outcome: "started_new"`. |
| Open run (pending, running or waiting; after continue-as-new, the newest run) | An accepted start command with `start_outcome: "returned_existing_active"` and the signal on that run. `202`. The run's input, history, deadlines and earlier receipts are unchanged. With `duplicate_policy: "fail"` it is refused with `409 instance_already_started`. |
| Closed run, `closed_run_policy: "reject"` | `409 run_not_active` with `run_id` and `run_status`. The closed run is never reopened. |
| Closed run, `closed_run_policy: "start_new_run"` | A fresh run numbered after the closed one starts under this request's input and timeouts, and the signal joins it. `201`, with `closed_run_id`. The closed run keeps its status, history and outcome. |

The response is the signal's receipt (`run_id`, `command_id`,
`command_sequence`, `outcome: "signal_received"`) plus the start's
(`start_command_id`, `start_command_sequence`, `start_outcome`,
`start_command_status`) and `intake_group_id`, which both commands also record
in their command context with `intake.mode: "signal_with_start"`.

The instance's namespace and durable workflow type must match the request,
and so must an explicit `task_queue` when an open run is reused. Every
refusal writes nothing: no run starts, no signal is recorded and no key is
reserved. That covers `workflow_id_reserved_in_namespace`,
`workflow_type_conflict`, `task_queue_conflict`, `instance_already_started`,
`run_not_active`, `task_queue_draining`, and the signal's own `unknown_signal`
(404), `invalid_signal_arguments` (422) and `structural_limit_exceeded`
refusals, which on this route do not leave a rejected signal record. A blank
or whitespace-only signal name is refused with `422 invalid_signal_name`, as on
the signal route.

`request_id` is the signal's command key on the instance. A key binds only
three things: the signal name, the typed signal arguments and the workflow
type (from the retained start command); the intent digest also names the
operation, so a plain signal's key is never a signal-with-start's. A retry
that matches all three returns the original start and signal receipts, with
`retained: true` and the original status code, before any run gate: after
the run closed or continued as new, and even with `start_new_run`, it starts
nothing. The start half is not part of the key: a retry with a different
start `input`, `task_queue`, timeouts, `duplicate_policy` or
`closed_run_policy` also returns the original receipts, unchanged. A
different signal name, signal arguments or workflow type, or a key a plain
signal already used, is `409 request_id_conflict` with `conflict` naming the
part (`name`, `arguments`, `workflow_type` or `operation`). Concurrent
retries resolve to one admission, and a key is scoped to its instance: the
same key on another workflow id is an independent intake. Without a key,
each request is its own intake. One admitted intake is one admitted start and
signal; it is not a once-only external effect, so activities the signal
triggers still need their own idempotency.

Deliberate differences from embedded `WorkflowStub::signalWithStart`
(Workflow 2.33.0), registered as native divergence `signal-with-start-intake`:

- **Closed runs.** Embedded always starts a new run when the current run is
  closed. Native refuses with `409 run_not_active` unless the request sets
  `closed_run_policy: "start_new_run"`, so a closed execution is never
  reopened silently.
- **Duplicate policy.** Embedded throws unless the start uses
  return-existing-active. Native also accepts `duplicate_policy: "fail"`,
  which refuses an open run with `409 instance_already_started` and starts
  only a new one.
- **Legacy keys.** Embedded returns the stored receipt of a key that has no
  recorded intent as unverified. Native refuses a key without a recorded
  signal-with-start intent, including a legacy key, with
  `409 request_id_conflict`.

#### Workflow queries

A query is answered by a query-capable worker that replays the run's history.
It never changes the run: no history event, status change or workflow task is
written. The native Server queries any retained run, whatever its status:
pending, running, waiting, completed, failed, cancelled or terminated. That is
the embedded-mode rule (`WorkflowStub::queryWithArguments` in Workflow 2.29
replays any run), and a failed run is where a query helps most with debugging.
The query binds the run it was admitted for, through the same route, lease and
deadline fences as an open run, so the worker receives that run's id, status
and complete history up to its terminal event. Published PHP Server 2.x
refuses failed, cancelled and terminated runs with `409 run_not_active` and
queries completed runs only. Describe reports `actions.can_query: true` on the
current run when its history is retained and a compatible query worker is
registered.

A run whose history is no longer available cannot be replayed, so it is
refused instead of being queried against an empty history. That covers runs
whose details retention pruned (`history_state: "pruned"`) and runs whose
`WorkflowStarted` event is missing (`history_state: "missing"`).

Every query failure has the query response shape (`workflow_id`, `run_id`,
`target_scope`, `query_name`, `result: null`, `reason`, `message`, and the
`control_plane` envelope). The reasons:

| Status | `reason` | Meaning for the caller |
| --- | --- | --- |
| 404 | `instance_not_found` | The workflow does not exist in this namespace. |
| 409 | `historical_run_command_rejected` | A run-targeted query named a run that is not the current run. Query the current run. |
| 409 | `query_history_unavailable` | The run's history was pruned or is missing; `history_state`, `run_status` and `is_terminal` say which run. Retrying cannot succeed. Use a retained export or archive instead. |
| 422 | `validation_failed` | `input` is not an array. |
| 404 | `rejected_unknown_query` | The run's recorded contract, or the worker, does not declare this query name. |
| 422 | `invalid_query_arguments` | The arguments do not match the declared query contract; `validation_errors` lists each problem. |
| 409 | `query_worker_unavailable` | No active worker on the task queue accepts query tasks. The request first waits up to `DW_QUERY_TASK_TIMEOUT` for one to register. Start or register a query-capable worker. |
| 409 | `query_worker_incompatible` | Query-capable workers exist, but none advertise the workflow type, the run's build compatibility or its recorded definition fingerprint. Refused at once. Deploy a compatible worker. |
| 429 | `query_task_queue_full` | The task queue already holds `DW_QUERY_TASK_MAX_PENDING_PER_QUEUE` pending queries. Retry later. |
| 413 | `query_snapshot_exceeds_budget` | The run's history is larger than one query snapshot. |
| 409 | worker-supplied, default `query_rejected` | The worker failed the query; `message` carries its explanation. |
| 504 | `query_task_not_claimed` | No compatible worker claimed the query before its deadline, for example because every worker was busy. If the route disappeared meanwhile, the route's 409 reason is returned instead. Safe to retry. |
| 504 | `query_worker_execution_timeout` | A worker claimed the query but did not answer before the deadline, for example during a long replay. The message names the worker. Retry, or raise `DW_QUERY_TASK_TIMEOUT` for runs with long histories. |
| 504 | `query_worker_timeout` | The query ended in another state without an answer before the deadline; the message names the last task status. Safe to retry. |
| 409 | `query_task_timed_out` | The query task's record expired before its answer was read. Submit the query again. |

The query deadline is `DW_QUERY_TASK_TIMEOUT`: by default
`min(max(DW_WORKER_POLL_TIMEOUT + 15, 40), 55)` seconds, so 45 seconds with the
default 30-second worker poll. A worker's lease on a query task is
`DW_QUERY_TASK_LEASE_TIMEOUT` and never extends that deadline. A timed-out
query is not resumed; submit it again as a new query.

A signal or update `request_id` is a durable command key on the workflow
instance (native Server 3.9.0 and later, advertised as
`capabilities.command_request_keys`). The first accepted signal or update under
a key is the only command it admits:

- A retry with the same command intent returns the original receipt, including
  its `command_id`, `run_id` and, for an update, `update_id`. It writes nothing.
  The intent is Core's CommandIntent v1 digest over the command type, name,
  typed arguments and explicit run target, so `1`, `1.0` and `"1"` differ and
  map key order does not matter. The receipt is found before any run gate, so a
  retry after the run closed or continued as new still gets it. A retained
  receipt carries `retained: true` and `intent_verified: true`.
- The same key with a different name, arguments or run target is refused with
  `409`, `reason: "request_id_conflict"` and `outcome:
  "rejected_request_id_conflict"`. The response names the part that differs in
  `conflict` and the original command in `retained_command_id`. Nothing is
  written, and the key keeps its original receipt. Clients and SDKs must not
  retry this 409 unchanged: it means the key was reused for different input.
  Send the original input to recover the receipt, or use a new key.
- A refused signal (for example `run_not_active` or `unknown_signal`) does not
  reserve its key; retrying it is evaluated again.
- The key is stored hashed and the intent as its digest, never the arguments.
  Both live as long as the command does. When retention prunes a run's details,
  its commands' keys and digests go with them, and a later request under that
  key is a new command.
- A receipt written before intent digests were introduced has no recorded
  intent. An exact raw-key retry reads back the original command only when
  the name matches and any explicit run target is the original run. It carries
  `retained: true` and `intent_verified: false`. Arguments are not compared;
  the readback does not establish that the retry's input equals the original.
- An ambiguous raw-key or hashed-key match is refused with
  `409 request_id_ambiguous`, `intent_verified: false` and `retryable: false`,
  without selecting a receipt or writing anything. This includes a legacy
  raw key that looks like another key's hash token and database collation
  collisions. Inspect the original command before using a new key; do not
  retry an ambiguous key automatically.

Before 3.9.0, a repeated signal `request_id` recorded a second signal, and an
update retried with changed arguments returned the original receipt.

For this Server API, the body `request_id` is the caller key. `X-Request-Id`
and `X-Correlation-Id` are audit correlation only. Omitting the body key admits
an independent operation, even if a proxy reuses its header. Caller keys are
opaque and case-sensitive, including the literal string `0`, up to the existing
255-character validation limit. The command context and `requestId()` accessor
retain that logical key; the indexed database column uses an internal bounded
lookup token. Historical body keys remain readable without rewriting old rows.

Drain and restart all direct database writers together when upgrading to the
new token format. After new updates are admitted, an older writer is not a safe
rollback target. Remote SDK clients need no change for this key correction.

Keep one key for retries of the same logical operation and use a new key for a
new operation. `wait_for: "accepted"` confirms admission, while `wait_for:
"completed"` requests the retained completion result. The PHP Server 2.x line
does not compare arguments for a reused update key and does not deduplicate
signals.

A completion wait holds the request open, so the server bounds it. An update's
`wait_timeout_seconds` must be an integer from 1 to 60, on the update route and
on bridge `update_workflow` targets. A larger value is refused with HTTP 422
(`validation_failed`) before the update is admitted, and nothing is recorded.
When the field is absent, the wait lasts
`DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS`, which is also capped at 60. If the update is still
incomplete when the wait ends, the response reports `wait_timed_out: true`;
the update itself continues, and its status can be read later. The control-plane
request contract publishes the bound as
`operations.update.fields.wait_timeout_seconds.maximum`
([#775](https://github.com/durable-workflow/server/issues/775)).

Workflow starts fail closed with `409` / `reason: "task_queue_draining"`
when the requested task queue has been explicitly drained and no active worker
cohort remains to claim new work. The response includes
`routing_status`, worker counts, and `draining_build_ids` so operators can
distinguish "wait for the active cohort to return" from "resume or replace the
drained build cohort first."

Workflow debug responses are capped support snapshots, not full run exports:
the server fetches at most 25 pending workflow tasks, 25 pending activities
with only each activity's current/latest attempt, and 10 recent failures. The
last history event includes only sequence, type, timestamp, and bounded payload
metadata by default; add `include_last_event_payload=true` to include at most a
4 KiB JSON preview. Use the history endpoints when a full replay/debug archive
is needed.

Each recent failure includes a `supporting_event` reference. A retained reference
contains the failure event's sequence, type, timestamp and an opaque
`next_page_token`. Pass that token unchanged to the selected run's history
endpoint to load a page starting at the event. A missing reference reports
`pruned` when run details were reclaimed, or `unavailable` otherwise.
`recent_failures_truncated` indicates that more than ten failure records exist.
These references load event metadata without decoding the complete run history.

For a terminal run whose projected history-size counter disagrees with its
retained events, use the [selected-run summary repair procedure](terminal-summary-recovery.md).

For a ready workflow task, debug findings also explain delivery eligibility.
`workflow_build_draining` identifies the required drained build and queue.
Resume that build and start or restart a compatible workflow worker. Resume
permits delivery but does not restart an exited process.
`no_eligible_workflow_worker` means the task is ready but no active worker with
a live matching compatibility heartbeat advertises its build and workflow type
on that queue. Start a matching worker and let it register and poll. Keep the
existing run, build pin and recorded history. Each finding includes
`routing_status`, `required_build_id`, `task_queue`, `next_event` and
`expected_resolution`. Protocol compatibility fields continue to describe code
support. Work not yet due, an ordinary wait without a ready task and terminal
runs do not produce these routing findings.

The candidate cooperative-cancellation runtime adds `cancellation_cascade_supported`
and `cancellation_cascade` to both debug responses. A supported runtime returns
`null` when the selected run has no cooperative request. An older installed
runtime reports support as `false`. This read-only view does not require worker
protocol 1.20 to inspect already recorded evidence.

The `durable-workflow.cancellation-cascade/v1` view joins the original root
request, its immutable cleanup deadline, each visible run's local request and
lifecycle, child propagation, activity fencing and matching stop receipts,
cleanup lease recovery, and the recorded terminal cleanup outcome. Selecting a
historical run keeps that run selected. Every related run is checked against
the namespace before its identity or history is returned. Activity fencing
alone does not prove that its callback stopped. Recovery records identify the
old and replacement grants without guessing why a worker lost its lease.

The view inspects at most 20 runs, 100 relationship edges, 128 recent relevant
history events per run and 512 recent history events in total. Original request
events are loaded separately so clipping recent history does not change the
root budget. Each request text field is capped at 8192 bytes. Missing,
inaccessible, inconsistent or clipped evidence is explained in `findings` and
sets `inspection_complete` to `false`. `inspection_complete` describes the
evidence inventory, not whether cleanup succeeded. Use the history endpoints
for evidence outside these bounds. This candidate contract is not a claim
about the currently published Server image.

### Standalone Activities

A standalone activity is an activity a client starts directly, outside any
workflow. Workers poll and complete it like any other activity task; the
server records it in its own host run of workflow type
`dw.standalone_activity`, which has no workflow task and closes with the
activity's outcome.

- `POST /api/activities` — Start a standalone activity
- `GET /api/activities` — List standalone activities (`status` = `running`,
  `completed` or `failed`; `page_size` 1–200, default 50; `next_page_token`)
- `GET /api/activities/{activity_id}` — Describe the current run of an activity

```bash
curl -X POST $SERVER/api/activities \
  -H "Authorization: Bearer $OPERATOR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{
    "activity_id": "invoice-42",
    "activity_type": "billing.send_invoice",
    "task_queue": "billing",
    "input": ["invoice-42"],
    "duplicate_policy": "use-existing",
    "retry_policy": {"max_attempts": 3, "backoff_seconds": [5, 30]},
    "start_to_close_timeout_seconds": 60
  }'
```

**Start fields.** `activity_type` is required (at most 255 characters).
`activity_id` is optional: up to 128 letters, digits, `.`, `_`, `:` or `-`;
the server generates one when it is omitted. `task_queue` defaults to
`default`. `activity_class` is a free-form label for describe and list.
`input` is a positional argument list or a payload envelope. `business_key`,
`retry_policy` (`max_attempts`, `backoff_seconds`,
`non_retryable_error_types`) and the timeouts
`start_to_close_timeout_seconds`, `schedule_to_start_timeout_seconds`,
`schedule_to_close_timeout_seconds` and `heartbeat_timeout_seconds` have
their in-workflow meaning. Without a retry policy an activity makes one
attempt.

A new run answers `201` with `activity_id`, `activity_execution_id`, the host
`workflow_id` and `workflow_run_id`, `status: "running"`, the deadlines,
`outcome: "started_new"` and `command_status: "accepted"`.

**Duplicate starts and id reuse.** `duplicate_policy` has workflow start's
values (native Server; published PHP has no policy and answers a running id
with a `422` validation error on `activity_id`):

| Policy | The id's current run is still running | The id's current run is closed |
| --- | --- | --- |
| `fail` (default) | `409`, `reason: "activity_already_started"`, `outcome: "rejected_duplicate"`, naming the running `activity_id` and `workflow_run_id`. | A new run starts (`201`). |
| `use-existing` | `200` with the running activity in the start response's shape and `outcome: "returned_existing_active"`. | A new run starts (`201`). |

Neither duplicate answer writes anything, and `use-existing` does not compare
the request with the original start: it returns the activity already running
under that id, whatever its type or input. A client that retries a start
after a lost response should send `use-existing` with the same
`activity_id`. Both answers come before the queue's drain check, so a retry
still finds its activity on a queue that has since begun draining. A closed
id always starts a new run, numbered after the previous ones; use a new id
when a repeat must not run again. An id already used by a workflow cannot be
used for a standalone activity (`422` on `activity_id`), and an id reserved
in another namespace is `409 activity_id_reserved_in_namespace`.

**Waiting, describing and cancelling.** `GET /api/activities/{activity_id}`
describes the id's current run: `status` (the host run's status),
`activity_status`, `closed_reason`, the deadlines, `attempt_count`,
`current_attempt` and every attempt, and `result` once the activity
completed. To wait for an outcome, poll it until `status` is no longer
`running`; a terminal failure or timeout fails the host run with the
activity's failure. The host run is an ordinary run under the same id, so
`POST /api/workflows/{activity_id}/cancel` or `/terminate` stops it and
`GET /api/workflows/{activity_id}/runs/{workflow_run_id}/history` shows its
history. An unknown id, or an id that names a workflow, is
`404 activity_not_found`.

**Limits, storage and retention.** The request body is bounded by
`DW_MAX_PAYLOAD_BYTES` (2 MiB by default; `413 payload_too_large`). Large
input follows the namespace's [external payload storage](#external-payload-storage)
policy: a reference envelope is stored as given, and external storage
refusals use the typed external payload outcomes (for example
`external_payload_unavailable`, 503). [Storage admission](storage-admission.md)
refuses starts unless storage is in its normal state, and a task queue whose workers
are all draining refuses new starts with `409 task_queue_draining`. Closed
host runs are kept and pruned by the namespace's history retention like
workflow runs.

### History
- `GET /api/workflows/{id}/runs/{runId}/history` — Get event history
- `GET /api/workflows/{id}/runs/{runId}/history/export` — Export replay bundle

### External Payload Storage
- `POST /api/storage/test` — Round-trip diagnostic for the selected namespace storage policy

The storage diagnostic writes, reads, verifies, and deletes small and large test
payloads through the namespace's configured policy. It supports `local` and
the built-in S3-compatible disk as well as configured-disk `gcs`, `azure`, and
`custom` policies. It returns `storage_driver_unavailable` with a non-secret
`configuration_error` when the selected disk is incomplete or unavailable.
Configure every Server process with the same `DW_EXTERNAL_PAYLOAD_S3_*`
environment and follow the
[external-payload backing-storage setup](contracts/external-payload-storage.md#self-hosted-backing-storage)
before enabling an S3 namespace policy.

On the native Rust server (local driver), storage failures are classified
([#589](https://github.com/durable-workflow/server/issues/589)). Each reason
below is returned on the payload transport upload and fetch, on workflow
start, on worker task completion, and by the storage diagnostic. None of them
includes the storage location or the storage's own error text.

| Reason | Status | Retryable | Meaning and fix |
| --- | --- | --- | --- |
| `external_payload_storage_access_denied` | 424 | no | The storage refused the server's identity: the local directory or object is not readable or writable by the server's user (EACCES/EPERM). Give the server's user read and write access to the namespace's storage root and everything under it, then retry. Retrying earlier will not succeed. |
| `external_payload_unavailable` | 503 | yes | Any other storage failure: a missing or unmounted root, a path that is not a directory, a full disk, an I/O error. Restore the storage, then retry. |
| `external_payload_not_found` | 404 | no | The referenced object is gone from the storage. |

A failed native storage diagnostic answers with the same status and also
names the step: `operation` is `put`, `get` or `delete`, and `payload` is
`small_payload` or `large_payload`. When the read fails after a successful
write, the diagnostic still deletes the object it wrote, once, and reports
that in `cleanup` (`{"operation":"delete","outcome":"deleted"}` or
`"failed"`) without replacing the read failure. `cleanup` is null otherwise.

Every non-health, non-discovery control-plane endpoint must send
`X-Durable-Workflow-Control-Plane-Version: 2` on the request. That
covers namespace, schedule, search-attribute, task-queue, worker-management,
system, workflow, and history endpoints. Requests without that header or
with legacy `wait_policy` fields are rejected. Mutating requests with bodies
must use `Content-Type: application/json` or another `application/*+json` media
type; XML, form, and other body formats return a versioned 415 response before
controller work. Workflow and history responses always return the same header.
The v2 canonical workflow command fields are
`workflow_id`, `command_status`, `outcome`, plus `signal_name`, `query_name`,
or `update_name` where applicable and, for updates, `wait_for`,
`wait_timed_out`, and `wait_timeout_seconds`.
Validation failures return HTTP 422 with `reason: validation_failed` plus both
`errors` and `validation_errors`; workflow operation routes also project that
reason and validation detail into the nested `control_plane` metadata. Current
run-targeted command routes project the URL `run_id` in the response and
`control_plane.run_id` so clients can distinguish instance-level commands from
explicit selected-run commands.

Only `GET /api/health`, `GET /api/ready`, and `GET /api/cluster/info` are
exempt. They are intentionally version-free so probes can check liveness and
readiness, and clients can discover the supported control-plane version before
adopting it.

Workflow control-plane responses, including run-history listing responses, also
publish a nested, independently versioned `control_plane.contract` boundary
with:
- `schema: durable-workflow.v2.control-plane-response.contract`
- `version: 1`
- `legacy_field_policy: reject_non_canonical`
- `legacy_fields`, `required_fields`, and `success_fields`

Clients can validate that nested contract separately from the outer
`control_plane` envelope.

On both the PHP and native servers, `wait_new_event=1` on the run history
endpoint waits for a committed event after `next_page_token`. If the page
already has events, the server returns it immediately. If the configured
`DW_WORKER_POLL_TIMEOUT` expires first, the server returns an empty page.
Use `wait_new_event=0` or omit the flag for an immediate read.

A run whose details history retention pruned has no events left. Its history
endpoint answers `200` with `events: []` and `next_page_token: null` for the
first page and for any later token, including one issued before the prune.
`wait_new_event=1` returns that page at once. The native server also reports
`history_complete: false` and `details_state: "pruned"` on that page, as the
run's history export does. Published PHP Server answers the same empty, final
page without those two fields, after waiting out `wait_new_event=1`. A retained
run's page has neither field. Use a history archive taken before the prune to
read the pruned events. The `413 history_event_exceeds_page_budget` refusal is
reserved for a retained event larger than one history page.

History export responses are the exception inside the workflow route group:
`GET /api/workflows/{id}/runs/{runId}/history/export` returns the replay bundle
as-is so its integrity checksum and optional signature cover the exact artifact
the client receives.
The bundle keeps each external payload as a reference envelope and copies no
bytes. For an archive that outlives retention cleanup and the payload store,
copy the referenced objects under the backup hold into a
[self-contained verified history archive](history-archives.md).

The server also publishes the current request contract in
`GET /api/cluster/info` under `control_plane.request_contract` with:
- `schema: durable-workflow.v2.control-plane-request.contract`
- `version: 1`
- `operations`

Treat that versioned manifest as the source of truth for canonical request
values, rejected aliases, and removed fields such as start
`duplicate_policy` and update `wait_for`. Clients should reject missing or
unknown request-contract schema or version instead of silently guessing.

`GET /api/cluster/info` also includes `client_compatibility`, whose
`authority` is `protocol_manifests`. The top-level server `version` is build
identity only; CLI and SDK compatibility must be decided from
`control_plane.version`, `control_plane.request_contract`, and, for workers,
`worker_protocol.version`. Unknown, missing, or undiscoverable protocol
manifests should fail closed.

The same cluster-info response publishes `skew_refusal_matrix_contract`, a
machine-readable matrix for published-artifact compatibility checks. It names
the required CLI, Python SDK, PHP worker, and Waterline pairings, the compatible
and intentionally skewed version classes, the allowed worker and Waterline
classifications, and the wire evidence a conformance run must capture before a
skew result can pass. Smoke-only compatibility evidence is explicitly
non-passing under that contract. The manifest also publishes the host-runner
handoff for the full matrix, including focused coverage-gap findings for any
unexecuted cell.

### Worker Protocol
- `POST /api/worker/register` — Register a worker
- `DELETE /api/worker/registrations/{workerId}` — Legacy worker self-deregistration
- `POST /api/worker/registrations/{workerId}/deregister` — Fenced worker self-deregistration when advertised by the server. See [the retry and authority contract](worker-deregistration-contract.md).
- `POST /api/worker/heartbeat` — Worker fleet heartbeat (free task slots, basic process metrics)
- `POST /api/worker/workflow-tasks/poll` — Long-poll for workflow tasks
- `POST /api/worker/workflow-tasks/{id}/heartbeat` — Workflow task heartbeat
- `POST /api/worker/workflow-tasks/{id}/complete` — Complete workflow task
- `POST /api/worker/workflow-tasks/{id}/fail` — Fail workflow task
- `POST /api/worker/activity-tasks/poll` — Long-poll for activity tasks
- `POST /api/worker/activity-tasks/{id}/complete` — Complete activity task
- `POST /api/worker/activity-tasks/{id}/fail` — Fail activity task
- `POST /api/worker/activity-tasks/{id}/heartbeat` — Activity heartbeat

Native Server accepts condition waits as members of an `all` group. Each
condition resolves when its predicate succeeds or its timeout expires. A
signal can reevaluate an open condition and reopen it at the same group
position if its predicate is still false. The barrier releases after all
members have resolved.

Native Server returns `grouped_condition_waits` version 1 at registration to
workers that declare `grouped_condition_wait_replay`. Upgrade every worker
that can receive tasks for these workflows before authoring them. The
registration capability does not restrict task delivery to those workers.

Worker-fleet heartbeats accept optional `task_slots` (`workflow_available`,
`activity_available`, `session_available`) and `process_metrics`
(`cpu_percent`, `memory_bytes`, `process_uptime_seconds`, `process_id`,
`host`, `process_started_at`) so operators can answer "what workers are
polling task queue X right now, what's their slot capacity, when did each last
check in" via `GET /api/workers`, the CLI `dw worker:list` /
`dw worker:describe`, and the Waterline Worker Status view.
`process_started_at` is a process identity value, alongside `host` and
`process_id`, that lets the server distinguish a restarted process that reused
the same worker id and OS pid. The register and heartbeat acknowledgements
advertise the recommended cadence in `heartbeat_interval_seconds` (default
10s, configurable via `DW_WORKER_HEARTBEAT_INTERVAL_SECONDS`); workers that
miss enough heartbeats fall out of the default `GET /api/workers` and
`dw worker:list` active roster after `DW_WORKER_STALE_AFTER_SECONDS`; operators
can still ask for the expired diagnostic set with `status=stale`, and stale
workers stop being considered for query-task dispatch and routing-gate
admission.

On the native Rust server, `GET /api/workers` returns one page of at most 100
registrations in worker id order. `page_size` (1 to 100, default 100) sets the
page length, and the response's `next_page_token` (null on the last page) is
passed back as `next_page_token` to read the next page. The status filter is
applied before the page is cut. An out-of-range `page_size` is refused with
`422 invalid_page_size`, and a token the server did not issue with
`400 invalid_page_token`. The response also reports
`registration_retention_seconds`.

The native server deletes a registration that has had no heartbeat for
`DW_WORKER_REGISTRATION_RETENTION_SECONDS` (default 86400, 24 hours; never
less than the stale window). A worker that crashed, was OOM-killed or
SIGKILLed, or stopped without deregistering is listed as `stale` until then,
and is removed afterwards by the maintenance loop or by a later worker
registration in its namespace. Active and draining workers heartbeat and are
never removed, nor is a silent registration that still holds an unexpired
task lease. Removal retires the registration like deregistration; the
worker's runs, pinned build ids, rollouts and pending tasks stay, so
`GET /api/task-queues/{name}/build-ids` still reports a pinned build with
pending tasks as `no_compatible_worker` after its last worker is removed. To
remove a worker by hand at any time, call `DELETE /api/workers/{workerId}`
with an admin token, or let the worker deregister itself on shutdown.

The current server advertises worker protocol `1.20` by default. Worker-plane
requests should send the highest `X-Durable-Workflow-Protocol-Version` their SDK
implements, and worker-plane responses always echo the server's advertised
version in the same header plus the `protocol_version` body field.
Worker-protocol compatibility is same-major with a worker minor less than or
equal to the server minor: a server advertising `1.20` accepts worker requests
for `1.0` through `1.20`, including older `1.19` and `1.17` workers. Requests
with a missing, malformed, different-major, or higher-minor protocol version are
rejected with `missing_protocol_version` or `unsupported_protocol_version` and a
`supported_version` value that tells the worker what the server advertises.
Worker-session routes and `worker_session` workflow commands additionally
require request protocol `1.8` or newer; older same-major workers remain
accepted for non-session work and receive `worker_sessions_unavailable` if they
try to use session features.
Worker requests with bodies follow the same JSON media-type requirement as the
control plane and return a worker-protocol 415 response for XML, form, or other
non-JSON body formats.
Worker registration, poll, heartbeat, complete, and fail responses all include
`server_capabilities.supported_workflow_task_commands` so SDK workers can
negotiate whether the server only supports terminal workflow-task commands or
the expanded non-terminal command set. The same `server_capabilities` object
also advertises command-option support for activity retry policies, activity
timeouts, child workflow retry policies, child workflow timeouts, parent-close
policy, and non-retryable failures. SDK workers can therefore negotiate worker
behavior from either `GET /api/cluster/info` or any worker-plane response.
Memo upserts and explicit typed search-attribute updates additionally require
the matching registered worker capability. Durable condition-wait occurrence
identity requires protocol `1.17`; older workers remain eligible for workflows
whose replay history does not contain that identity.
Native additionally advertises `condition_wait_idempotent_reopen` when opening
an already-unresolved occurrence preserves its existing wait and deadline.
See [idempotent condition-wait opens](contracts/condition-wait-occurrences.md).
Native advertises `activity_workflow_continuation` for activity completions
that also complete the run's resumed workflow task with worker-computed
commands. Its top-level fields describe version 1 and keep `version: 1` for
released SDKs; `supported_versions` lists `[1, 2]` and the `v2` member names
the version-2 endpoint, commands, required worker capabilities
(`sticky_execution` and `sticky_history_suffix`) and the history tail budgets
(`tail_max_events`, `tail_max_bytes`). Continuations are opt-in per request and
default-off in the SDKs. See
[activity workflow continuation v2](contracts/activity-workflow-continuation-v2.md).

Long-poll wake-ups use short-lived cache-backed signal keys plus periodic
reprobes. Multi-node deployments therefore need a shared cache backend for
prompt wake behavior; without one, correctness still comes from the periodic
database recheck, but wake latency will regress toward the forced recheck
interval.

Server-owned cache keys and metric label sets are governed by the bounded-growth
policy in `config/dw-bounded-growth.php`; the human-readable inventory lives in
`docs/bounded-growth.md`.

Default cluster discovery publishes the full bounded `topology` manifest. It
freezes the server's role vocabulary (`api_ingress`, `control_plane`, `matching`,
`history_projection`, `scheduler`, `execution_plane`), the product's supported
deployment shapes (`embedded`, `standalone_server`,
`split_control_execution`), the roles currently hosted by this node's
configured process class, and the current execution mode. `execution_mode` is
`remote_worker_protocol` in the default service-mode deployment and switches to
`local_queue_worker` when `DW_MODE=embedded` routes workflow and activity task
execution through local Laravel queue workers. Set
`DW_SERVER_TOPOLOGY_SHAPE` and `DW_SERVER_PROCESS_CLASS` when a deployment
splits control-plane, scheduler, matching, or execution work away from the
default `server_http_node` so discovery reports the live node identity instead
of a generic HTTP shape. The published Compose artifacts set these per service
for the supported `server`, `worker`, and `scheduler` nodes, so `GET /api/cluster/info`
and local diagnostics report the same node class the operator actually
deployed. `topology.matching_role` adds the live matching-role
deployment knobs for that node: `queue_wake_enabled`, the matching-role
`shape` (`in_worker` or `dedicated`), who owns the broad-poll wake
(`worker_loop` or `dedicated_repair_pass`), the active `task_dispatch_mode`
(`poll` or `queue`), the frozen `partition_primitives`
(`connection`, `queue`, `compatibility`, `namespace`), and the durable
`backpressure_model` (`lease_ownership`). The same manifest now also publishes
`role_catalog` for the current node, the supported process-class assignments
for each topology, the durable-write authority boundary for every role, the
surface-by-surface authority map for durable tables, the expected degraded
behavior for each role failure domain, the scaling axis for each role, and the
incremental migration steps from today's standalone shape to the split
control/execution topology.

Authenticated API routes now also fail closed against that advertised process
class. Nodes that do not host the server's current HTTP control surface return
`503` with `reason: "topology_role_unavailable"` on role-gated routes instead
of pretending to be interchangeable HTTP peers. `GET /api/cluster/info`,
`/api/health`, and `/api/ready` stay available for discovery and liveness even
on scheduler-only, execution-only, or matching-only nodes. The unauthenticated
health and readiness probes publish the current node's topology summary
(`schema`, `version`, `current_shape`, `current_process_class`,
`current_roles`, `execution_mode`, and `matching_role`) so operators can
identify split-role nodes without authenticating into the broader
`/api/cluster/info` manifest.

Those runtime-serving write and poll routes also fail closed on bootstrap
blockers. If database connectivity or workflow-table migrations are not ready,
the workflow index, workflow start/mutation routes, bridge-adapter traffic, and
worker-protocol traffic return `503` with `reason: "workflow_v2_blocked"` plus
`blocked_by` and `remediation` instead of accepting traffic that depends on an
incomplete rollout state. Run-scoped debug and history diagnostics stay
available so operators can inspect the broken node.

The explicit `GET /api/cluster/info?include=diagnostics` response includes a versioned
`coordination_health` manifest for rollout-safety coordination risk. It
summarizes the current server-wide workflow v2 health status, warning and error
check names, category counts, the normalized check list that already powers the
bounded readiness gate, and a `routing_drains` summary that lists namespaces and task
queues with draining build-id cohorts. The manifest is intentionally
`all_namespaces` scoped so it describes the server's fleet-wide coordination
posture; use `GET /api/system/health` for the namespace-scoped
`routing_drains` view.

Explicit diagnostic discovery also publishes the full operator metrics snapshot at
`operator_metrics`, scoped to the requested namespace (or
`server.default_namespace` when no `X-Namespace` header is supplied). The
snapshot mirrors the shape served by `GET /api/system/operator-metrics` —
`runs`, `tasks`, `backlog`, `repair`, `workers` (including the live `fleet`
detail), `backend`, `structural_limits`, and `repair_policy` — so the
standalone server's diagnostic discovery surface carries namespace-specific backlog,
worker, and repair detail alongside the fleet-wide `coordination_health`
manifest. Use the dedicated `GET /api/system/operator-metrics` endpoint when
operators need an admin-gated, control-plane-versioned read of the same
snapshot.

Default cluster discovery, liveness, readiness, and runtime bootstrap admission
never build this full snapshot. Their database and CPU work stays fixed as
workflow history and ready-task cardinality grow. Default discovery retains the
compatibility, capability, control-plane, worker-protocol, auth-composition,
limits, static contract manifests, and full topology fields required by public
SDK and CLI preflight. Use
`GET /api/system/health`, `GET /api/system/operator-metrics`, or
`GET /api/cluster/info?include=diagnostics` when detailed backlog, projection,
history, command-contract, and fleet diagnostics are required.

The activity-grade external execution surface is published from
`GET /api/cluster/info` at
`worker_protocol.external_execution_surface_contract`. That manifest is the
carrier-neutral umbrella for durable, bounded, external work: operator,
platform, and integration automation first, with script or agent handlers as
secondary consumers. It keeps workflow replay, ContinueAsNew, signal/update/query
ordering, and event-history interpretation inside real runtimes. A
human-readable summary lives in `docs/contracts/external-execution-surface.md`.
Handler mappings are config-first: set `DW_EXTERNAL_EXECUTOR_CONFIG_PATH` to a
`durable-workflow.external-executor.config` JSON file and, when needed, set
`DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY` to apply an environment overlay before
server validation. Cluster discovery publishes the config contract and redacted
runtime diagnostics at `worker_protocol.external_executor_config_contract`.
When a leased activity task matches a valid configured activity mapping by task
queue and activity type, the activity poll response includes a redacted
`task.external_executor` mapping block with the handler, carrier target, auth
reference, rollout metadata, and config schema version.

The first concrete invocable carrier contract is published at
`worker_protocol.invocable_carrier_contract` with carrier type
`invocable_http`. It is activity-task only: the target endpoint receives the
external task input envelope over `POST` and must return the external task
result envelope. The server validates `invocable_http` carrier config
fail-closed, including absolute HTTPS `url` targets, HTTP only for loopback
development targets, no embedded URL credentials, `POST` method, bounded
`timeout_seconds`, optional bounded `retry_policy`, and activity-only
capabilities, before mapping it onto pollable activity tasks. The carrier
must also resolve an `auth_ref` for non-loopback targets; only loopback HTTP
development targets may omit auth. Effective auth remains redacted in cluster
diagnostics and activity poll responses.
The Server never sends the HTTP request itself. A process you run must poll
the activity queue, read `task.external_executor`, call the endpoint and
report the result through the normal complete/fail paths. If nothing polls
the queue, the activity task waits. A Server-side dispatcher will be built
when users need it (#527).
The carrier retry policy is transport-only: it may repeat transient HTTP delivery before a
result is reported, while durable activity retry remains owned by the
server/runtime after complete/fail reporting.
For leased invocable mappings, `task.external_executor.dispatch` also exposes
the attempt-level diagnostics needed to reason about one handler call: content
types, configured transport timeout, task deadline fields, idempotency key
source, normalized transport retry policy, durable retry authority, failure
mapping, and the complete/fail result reporting paths.

The carrier-neutral external task input envelope is published from
`GET /api/cluster/info` at `worker_protocol.external_task_input_contract`.
That manifest explicitly splits its scope: activity tasks are the
activity-grade external-execution handler input, while workflow tasks are
published for worker-protocol runtime compatibility and drift testing rather
than as generic external handler work. Both shapes freeze task identity,
attempt, queue, handler, workflow/run context, lease metadata, deadlines where
relevant, payload metadata, idempotency keys, and versioning rules.
Shared JSON fixtures are embedded in the manifest as artifact objects with
stable artifact names, media types, SHA-256 digests, and examples. A
human-readable summary lives in `docs/contracts/external-task-input.md`.

The carrier-neutral external task result envelope is published from
`GET /api/cluster/info` at `worker_protocol.external_task_result_contract`.
That manifest freezes success, structured failure, malformed output,
cancellation, handler crash, decode failure, and unsupported payload outcomes.
Shared result fixtures use the same embedded artifact shape so CLI, SDK, and
future carriers can validate parser behavior without repository-local fixture
paths. A human-readable summary lives in
`docs/contracts/external-task-result.md`.

Within worker protocol version `1.17`, `worker_protocol.version`,
`server_capabilities.long_poll_timeout`, and
`server_capabilities.supported_workflow_task_commands` are stable contract
fields. The command-option booleans under `server_capabilities` are additive
worker capability fields. Adding new workflow-task commands or optional
capability booleans is additive; removing or renaming a command or capability
requires a protocol version bump.

Native Server: a worker can send
`X-Durable-Workflow-Server-Capabilities-Digest: <digest>|none` on
worker-plane requests to stop receiving the same `server_capabilities` object
in every answer. When the header names the current digest, each copy of the
current capabilities (top level, or inside a carried poll's `answer`) is
replaced by the digest string. Otherwise each copy is sent in full with an added
`digest` member. Every opted-in answer also carries the current digest in the
same response header. A digest string always names the digest that request
sent, so a worker resolves it against the capabilities it captured when sending
that request. A worker that cannot resolve a digest never repeats a request that
may have changed state (a completion's answer can carry the next leased task):
it keeps that answer and reads the capabilities from `GET /api/cluster/info`;
only a task poll may be sent again without the header. Requests without the
header are unchanged. The full contract is in
`docs/contracts/server-capabilities-digest.md`.

Workflow task polling returns a leased task plus `workflow_task_attempt`. Clients
must echo both `workflow_task_attempt` and `lease_owner` on workflow-task
`heartbeat`, `complete`, and `fail` calls. Workflow-task completion supports
non-terminal commands such as `schedule_activity`, `start_timer`,
`start_child_workflow`, `complete_update`, and `fail_update`, plus terminal
`complete_workflow`, `fail_workflow`, and `continue_as_new` commands. Workers
use `complete_update` with `update_id` and an optional encoded `result`
after applying an accepted update, or `fail_update` with `update_id`,
`message`, and optional exception metadata when the update handler fails. Poll
responses also expose stable resume
context fields from the durable task payload: `workflow_wait_kind`,
`open_wait_id`, `resume_source_kind`, `resume_source_id`,
`workflow_update_id`, `workflow_signal_id`, `workflow_command_id`,
`signal_name`, `signal_wait_id`, `activity_execution_id`,
`activity_attempt_id`, `activity_type`,
`child_call_id`, `child_workflow_run_id`, `workflow_sequence`,
`workflow_event_type`, `timer_id`, `condition_wait_id`, `condition_key`, and
`condition_definition_fingerprint`. Signal-backed tasks also expose
`signal_arguments` as the same codec-tagged payload envelope used by workflow
start and activity inputs. Fields that do not apply to the leased task are
`null`; pure timer resumes set
`workflow_wait_kind: "timer"`, `open_wait_id: "timer:{timer_id}"`, and
`timer_id` so SDK workers can apply timer-fired history directly. Update-backed
tasks set
`workflow_wait_kind: "update"` and `workflow_update_id` so SDK workers can tie
the task to the accepted update they are applying. Signal-backed tasks set
`workflow_wait_kind: "signal"`, `workflow_signal_id`, `signal_name`, and
`signal_wait_id` so SDK workers can tie the task to the accepted signal or
timer-backed signal wait they are applying, while activity-backed resume tasks
set `workflow_wait_kind: "activity"` and `activity_execution_id` so workers can
apply completed or failed activity history without scanning the full event
stream. Timer-backed condition resumes set `workflow_wait_kind: "condition"`,
`condition_wait_id`, `condition_key`, and
`condition_definition_fingerprint` when the original wait recorded them. If a
cancel or terminate command closes the run while a workflow task
is leased, the next workflow-task
`history`, `heartbeat`, `complete`, or `fail` response returns the worker
envelope with `reason: "run_closed"`, `can_continue: false`,
`cancel_requested: true`, and a concrete `stop_reason` such as `run_cancelled`
or `run_terminated`. The response also includes `run_closed_reason` and
`run_closed_at` from the durable run so external workers can log the exact
closure state that stopped their leased task.

Start-boundary command ordering is part of the worker replay contract. When a
signal or update is accepted after the run is persisted but before the first
workflow task is polled, the server still records and returns `WorkflowStarted`
before `SignalReceived` or `UpdateAccepted`. SDK workers can initialize workflow
state before applying command handlers during replay; commands sent before a
workflow ID is bound remain rejected as `instance_not_found`.

Activity task polling returns a leased attempt identity. Clients must echo both
`activity_attempt_id` and `lease_owner` on activity `complete`, `fail`, and
`heartbeat` calls. When the activity execution has timeout deadlines configured,
the poll response includes a `deadlines` object with UTC timestamps and six
fractional digits for `schedule_to_start`, `start_to_close`, `schedule_to_close`,
and/or `heartbeat`. Configured workflow deadlines are included as `run` and
`execution`, copied from the original durable run. Missing deadlines are omitted;
polling, observation and runtime renewal do not invent or restart them.
Workers should use these deadlines to self-cancel before the server enforces the
timeout. The server runs `activity:timeout-enforce` periodically to expire
activities that exceed their deadlines. Heartbeats accept `message`, `current`,
`total`, `unit`, and `details` fields; the server normalizes them to the package
heartbeat-progress contract before recording the heartbeat.

The optional `activity_lease_renewal` capability is available only with an
installed bridge implementing runtime renewal. When discovery explicitly
returns `true`, a worker can send its original `activity_attempt_id` and
`lease_owner` to `POST /api/worker/activity-tasks/{id}/renew-lease` using the
same namespace and worker credentials. A successful response contains
`lease_renewed: true`, `can_continue: true`, the original task/run/execution/
attempt/owner identity, `lease_expires_at`, `server_time`, and
`heartbeat_recorded: false`. Validate that identity and charge the request's
elapsed time against the returned grant before executing or publishing work.
An HTTP success alone is not execution authority. A lost required worker
session, expired lease or elapsed business deadline refuses renewal; this
operation cannot revive a claim. Missing capability retains the existing
lease/heartbeat behavior and requires no new protocol floor.

Runtime renewal records no application progress or durable history. Only an
actual acknowledged application heartbeat may advance its originally recorded
heartbeat interval. Workers must preserve the original run, execution and
activity deadlines, keep renewal active during argument loading and result
publication, and recheck authority before every terminal HTTP retry. A late
outcome is refused before resolving an external result. The package also needs
to validate authority under its mutation locks; a preflight response is not a
reservation. The automatic-worker and published long-activity acceptance for
this optional candidate remain tracked in Workflow #878.

When a run-level cancel or terminate command stops a leased activity task,
heartbeat, complete, and fail responses include `run_closed_reason` and
`run_closed_at` alongside `cancel_requested: true`.

When a run's own code closes it with `complete_workflow`, `fail_workflow` or
`continue_as_new` (native Server 3.9.0 and later, Core workflow#1117), the
close settles the activities and timers the run still has open, in the same
transaction and before the terminal event. An open activity, such as a
`select()` loser the workflow never awaited, gets `ActivityCancelled` with
`cancellation_reason: "run_closed"`, and its attempt and task are cancelled.
Each pending timer, including a wait's timeout timer, gets `TimerCancelled`.
Nothing is appended after the terminal event. A late heartbeat or lease
renewal for such an activity answers `reason: "run_closed"` with
`can_continue: false` and `cancel_requested: true`. A late `complete` or `fail`
is refused with `409`, `outcome: "ignored"`, `recorded: false`,
`reason: "run_closed"` and `activity_status: "cancelled"`. Workers must treat
it as final and not retry. Replay must ignore an `ActivityCancelled` whose
`cancellation_reason` is `run_closed`: the workflow never observed it, so it
never resolves a selection, a parallel group member or an activity outcome.
This close also cancels an activity scheduled with the `abandon` cancellation
policy. Native keeps such an activity running only past a cooperative
cancellation's close, and never writes the `abandoned_activities` list that
Core writes for one. Children are not
settled: they follow their parent-close policy. Published PHP 2.x left this
work open, so a workflow that needs a loser's effect must await it before
returning.

When a run continues as new while children it started are still open (native
Server 3.14.0; Server #1184, Core workflow#868 in Workflow 2.26.0), the
continued run takes over their supervision in the same transaction. The child
link, child call, child identity, deadlines and `parent_close_policy` stay as
recorded, and the child's `WorkflowStarted` still names the run that started
it. The continued run gets no handle on the child or its result, and its
description and links do not list it. When the chain's final run completes,
fails, times out, is cancelled or is terminated, it applies each handed-over
child's `request_cancel`, `terminate` or `request_cancellation` policy to the
child's current run, following the child across its own continue-as-new, and
records `ParentClosePolicyApplied` or `ParentClosePolicyFailed` (and
`ParentCloseCancellationRequested` for `request_cancellation`) in its own
history. `abandon` stays `abandon`. A run that continues again passes on every
child it still supervises. A handed-over child that closes appends nothing to
the closed predecessor and creates no task for it. One that fails while a run
of the chain still supervises it is retried under the retry policy the
starting run recorded, with the same call and attempt count; the retry's
`WorkflowStarted` names the original parent and sequence, the predecessor's
history is not changed, and supervision follows the retry run. Once the chain
has closed, a child's failure is final. A run that continued before this
change recorded no supervision, so its children are left running as before.
Native keeps each transfer, with the columns of Core's
`workflow_child_supervisions`, in its own `dw_child_supervisions` table; no
link listing, description or export shows it.
Published PHP 2.x applies no policy to a child whose parent continued as new.

### Schedules
- `GET /api/schedules` — List schedules with visibility filters and cursor paging
- `POST /api/schedules` — Create schedule
- `GET /api/schedules/{id}` — Describe schedule
- `PUT /api/schedules/{id}` — Update schedule
- `DELETE /api/schedules/{id}` — Delete schedule
- `POST /api/schedules/{id}/pause` — Pause schedule
- `POST /api/schedules/{id}/resume` — Resume schedule
- `POST /api/schedules/{id}/trigger` — Trigger immediately
- `POST /api/schedules/{id}/backfill` — Backfill missed runs

A scheduled, backfilled or drained buffered occurrence starts its run under a
deterministic workflow ID, the same one embedded mode has used since Workflow 2.15.15,
so an application can derive the run for an occurrence
([#599](https://github.com/durable-workflow/server/issues/599)):

```text
schedule.ns:{namespace digest}:{schedule ID byte length}:{schedule ID}:backfill:{occurrence Unix seconds}
```

The namespace digest is the first 32 hex digits of the SHA-256 of the
schedule's namespace name, so equal schedule IDs in two namespaces name
different runs. A schedule ID that would make the workflow ID longer than 191
characters, or that uses a character outside `A-Z a-z 0-9 . _ : -`, is replaced
by `h:{SHA-256 of the schedule ID, 64 hex}`. For example, schedule `nightly` in
namespace `default` at `2026-11-18T09:00:00Z` starts
`schedule.ns:37a8eec1ce19687d132fe29051dca629:7:nightly:backfill:1794992400`.
The suffix reads `backfill` for every occurrence, as in embedded mode. If the
run for an occurrence already exists, a repeated fire of it records the skip
`already_fired_occurrence` and starts nothing. A manual trigger has no
occurrence, so its run gets a generated ULID. Runs started before this change
keep their generated IDs.

The native development [scalar summary draft](schedule-list-scalar-summary.md) adds
counters, latest-instance identity, a nullable current-run summary and the stored
`last_skip_reason` / `last_skipped_at` pair. The reason is nullable complete text
with at most 64 Unicode scalars / 256 UTF-8 bytes. The nullable UTC timestamp
preserves six fractional digits, for example `2026-11-05T12:00:01.123456Z`, and
matches describe's `info.last_skipped_at`. Invalid or oversized stored values
return HTTP 503 `storage_unavailable`. A later successful fire preserves an
earlier skip, so this pair is not the latest overall dispatch outcome. The
dispatch summary has its own authority below. An empty list cannot prove
support for either contract.

Native list rows and describe responses also carry a top-level
`dispatch_summary` v1. Older producers omit it. The complete object contains
`version`, `state`, `last_outcome` and `buffered_actions_count`:

| State | Last outcome | Current buffer count |
| --- | --- | --- |
| `known` | Complete recorded dispatch | Integer from 0 through 9007199254740991 |
| `never` | `null`, established only by new creation | `0` |
| `unavailable` | `null`, including legacy or invalid stored authority | `null` |

A known outcome has exactly `id`, `outcome`, `recorded_at`, `reason`,
`workflow_instance_id` and `workflow_run_id`. Outcomes are `started`, `buffered`,
`skipped` or `failed`. Started outcomes name the new workflow instance (a
generated ULID, or an occurrence's deterministic ID above) and its run's
uppercase ULID, and have a null reason. Other outcomes have null workflow identities. Skipped and failed
outcomes keep the complete reason, including empty strings and whitespace;
buffered outcomes have a null reason. Times are UTC with six fractional digits.
The complete summary is bounded to 4096 serialized UTF-8 bytes. An outcome that
cannot fit the complete contract replaces earlier authority with unavailable
without changing the underlying scheduling operation or truncating its reason.

A valid unavailable summary overrides older legacy outcome evidence. Absent,
null, partial or unsupported summaries provide no v1 authority. A later describe
owns the whole summary, including absence or null; do not merge it with a list
snapshot. Count-only buffer changes retain the outcome ID and time, so a buffered
outcome can coexist with a current count of zero. Neither timestamp ranking nor
the latest-workflow pointer determines the latest dispatch. Current workflow
execution status remains separate. Native schema upgrades leave existing rows
unknown and do not reconstruct summaries from legacy arrays.

Schedule listing is namespace-scoped and never returns deleted schedules. The
optional `status` (`active` or `paused`) and `workflow_type` filters are exact
matches. `query` accepts equality predicates joined by `AND` for
`ScheduleId`, `Status`, `WorkflowType`, `TaskQueue`, `Note`, and registered
search-attribute names. String literals use quotes; number and boolean literals
retain their JSON types. Other operators and fields are rejected instead of
being ignored. All supplied filters combine with AND semantics.

`page_size` defaults to 50 and accepts 1 through 200. Results use the stable
order `created_at DESC, schedule_id ASC`. A non-null `next_page_token` is an
opaque, signed keyset cursor; pass it back unchanged with the same namespace,
status, workflow type, and visibility query. `page_size` may change between
pages. A null token terminates traversal. Malformed, cross-namespace,
filter-mismatched, and stale cursors return typed errors with `reason`, `field`,
`errors`, and `last_safe_cursor` evidence. A cursor becomes stale when its
anchor schedule is deleted or no longer matches the original filtered set;
restart the traversal without a token in that case.

### Task Queues
- `GET /api/task-queues` — List task queues
- `GET /api/task-queues/{name}` — Task queue details, pollers, and recent add/dispatch flow

Native Server reports `stats.type_coverage` on both task queue responses and
`operator_metrics.backlog.type_coverage` on `GET /api/system/operator-metrics`.
It counts due workflow and activity tasks for open runs. The report separates
tasks on queues without active pollers (`no_active_poller_count`) from tasks
whose exact, case-sensitive type is absent from the active registrations
(`unsupported_type_count`). Tasks without enough stored identity to classify
are counted in `unclassified_count`. The `by_type` list gives counts by
queue, task kind, type, and reason. It contains up to `type_limit` entries.
`types_truncated` indicates additional groups, which are included in the
totals. Type coverage only describes advertised types. Worker capacity, build
compatibility, and dispatch pauses have their own diagnostics.

On `GET /api/workflows`, an open run can include `unsupported_ready_task`
with the `task_queue`, `task_kind`, and exact `type` of one affected ready
task. Each workflow page checks only its own runs, and a finding requires an
active registration on the task's queue.
- `GET /api/task-queues/{name}/activity-dispatch` — Whether activity dispatch on the queue is paused (native only)
- `POST /api/task-queues/{name}/activity-dispatch/pause` — Pause activity dispatch, with an optional `reason` (native only)
- `POST /api/task-queues/{name}/activity-dispatch/resume` — Resume activity dispatch (native only)

A native Server that advertises `task_queue_activity_dispatch_pause` can hold
new activity dispatch on one queue of one namespace. Ready activity tasks stay
ready with no attempt used, activity deadlines keep running, activities
already leased finish normally, and workflow, signal, query and update
delivery is unchanged. The pause is durable and records who paused and
resumed the queue and when. It is not a concurrency cap: a cap setting of `0`
never pauses a queue. See
[the contract](contracts/task-queue-activity-dispatch-pause.md).

Task queue responses include an `admission` object so operators can separate
worker-local capacity from server-side queue and query-task admission limits. Workflow
and activity entries report active worker count, configured slots from worker
registrations, leased and ready counts, available slots, optional server-side
queue and namespace active lease caps, optional queue and namespace per-minute
dispatch caps, optional downstream budget-group dispatch caps, and a status such as
`accepting`, `throttled`, `saturated`, `no_slots`, or `no_active_workers`. Set
`DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE` and
`DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_QUEUE` to cap active leases per
namespace/task queue. Set `DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE`
and `DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE` to cap active leases
across all task queues in a namespace. Set `DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE` and
`DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE` to smooth downstream dispatch per
namespace/task queue. Set `DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE`
and `DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE` to smooth
tenant-wide dispatch across all queues in a namespace, or use
`DW_TASK_QUEUE_ADMISSION_OVERRIDES` for exact queue and namespace overrides
keyed by `namespace:task_queue`, `namespace:*`, `task_queue`, or `*`. Override
entries may set `workflow_tasks.max_active_leases`,
`workflow_tasks.max_active_leases_per_namespace`,
`workflow_tasks.max_dispatches_per_minute`,
`workflow_tasks.max_dispatches_per_minute_per_namespace`,
`workflow_tasks.dispatch_budget_group`,
`workflow_tasks.max_dispatches_per_minute_per_budget_group`,
`activity_tasks.max_active_leases`,
`activity_tasks.max_active_leases_per_namespace`,
`activity_tasks.max_dispatches_per_minute`, or
`activity_tasks.max_dispatches_per_minute_per_namespace`,
`activity_tasks.dispatch_budget_group`, or
`activity_tasks.max_dispatches_per_minute_per_budget_group`. Give several
queues the same `dispatch_budget_group` when they share a rate-limited
downstream dependency and should consume one namespace-scoped per-minute
budget without throttling every queue in the namespace. Query-task
entries report `server.query_tasks.max_pending_per_queue`, approximate pending
count, remaining capacity, cache-lock support, and whether the queue is
`accepting`, `full`, or `unavailable`.

### Search Attributes
- `GET /api/search-attributes` — List search attributes
- `POST /api/search-attributes` — Register custom attribute
- `DELETE /api/search-attributes/{name}` — Remove custom attribute

### Long-Lived History Operations

Run detail exposes `history_event_count`, `history_size_bytes`,
`history_fan_out`, `history_budget_pressure`, and the dimensions that caused
pressure. The default warning is 8,000 events, 4 MiB of serialized history,
or fan-out of 160. Continue-as-new is recommended at 10,000 events, 5 MiB,
or fan-out of 200. Reaching any one dimension changes the pressure state.
The Server does not cap history per run. `DW_MAX_HISTORY_EVENTS` has no
effect: no Server release has read it, so setting it does not limit anything.
Continue-as-new from workflow code is what bounds a long-lived history.

Plan a continuation when a run reaches `approaching`. Carry the state needed
by the next run explicitly through the SDK's continue-as-new command, and
check the resulting run chain. Watch workflow-task latency, repairs and
backlog alongside the budget. A growing history can increase task latency
even while every result remains correct. If a backend outage interrupts a
worker, restore database readiness and ensure a compatible worker is running
on the recorded queue before considering manual intervention. Do not delete the
run or referenced external payloads to clear a backlog. Retention applies
to closed-run history and must preserve payloads still referenced by active
runs.

The [published-artifact qualification](evidence/server-237-20260929-size-replay/README.md)
measured both event and serialized-size thresholds on MySQL and PostgreSQL,
plus replay of an exported 10,010-event history. Its [recovery record](evidence/server-237-20260929-pg-recovery/README.md)
covers a worker replacement after PostgreSQL interruption and a targeted
retention pass. Those observations support the default guidance for the
tested artifacts and limits; they do not set a capacity promise for other
workflow shapes or hardware.

### Prometheus scrape and autoscaling

The native Server serves `GET /metrics` in Prometheus text format 0.0.4
(`Content-Type: text/plain; version=0.0.4`). Workers poll for tasks, so a
worker Deployment scaled to zero emits no metrics of its own. These series are
read from the Server's task rows, so due work is visible while no worker runs,
and an autoscaler can start the first worker. Published PHP 2.x has no such
route; its `GET /api/system/prometheus-metrics` stays the admin-only JSON
summary.

**Authentication.** Scrape with the scrape-only `metrics` role:

- `DW_METRICS_TOKEN`, a server-wide bearer token that can read `GET /metrics`
  for any namespace and reaches no other route. Startup refuses a value equal
  to `DW_AUTH_TOKEN` or any role token.
- or a database runtime credential with `"roles":["metrics"]` and a `tenant`,
  which reads only its own namespace's scrape
  (`DW_RUNTIME_CREDENTIALS_ENABLED=true`).

An admin token can also scrape. The control-plane version header is optional
on this route; a version other than `2` is still refused.

**Namespace.** Each scrape reads one namespace: the `X-Namespace` header, else
the `namespace` query parameter, else `default`. Storage-backed samples carry a
`namespace` label, so one Prometheus job per namespace merges cleanly. In a
Prometheus scrape config, pass `params: {namespace: [orders]}`.

**Availability.** A failed storage read fails the whole scrape with HTTP 503
and no samples, so Prometheus records the target as down (`up == 0`) and the
series go stale. Missing telemetry is never rendered as an empty queue or as
unlimited capacity.

**Cardinality.** Labels are namespace, task queue, task kind (`workflow` or
`activity`), build id, workflow type, activity type and fixed state names.
Workflow, run and task identifiers are never labels. Each family reports at
most 100 task queues (by name), and at most 10 build ids per queue and kind;
further build ids are summed under `build_id="__other__"`. The workflow and
activity families report at most 100 label sets each. `dw_metrics_series_limit`,
`dw_metrics_series_observed` and `dw_metrics_series_truncated` (by `family`:
`task_queues`, `activity_retries`, `workflows`, `activities`) disclose
truncation.

#### Request timing

Scrape each Server replica's default namespace with the instance-wide
`DW_METRICS_TOKEN` or an instance-wide admin token. The process-local
`dw_http_request_duration_seconds` histogram counts completed HTTP responses
by route template, numeric response status, and kind. It starts at the router
and ends when the response is ready. That includes admission, handler work and
response preparation. Network transit and response streaming occur outside that
interval. Tenant-bound credentials receive their namespace metrics only.

`kind="worker_poll"` covers the POST endpoints for workflow, activity, query,
and update-validation polls, including time spent waiting for a task.
`kind="request"` covers other requests. A histogram with
`route="__overflow__",status="other"` identifies observations merged after the
512-combination budget is reached. Counts reset when the Server restarts.

Compare equivalent route and response-status groups over the same interval.
For example, this query shows the Server-side p95 for successful workflow detail
requests.

```promql
histogram_quantile(0.95,
  sum by (le) (rate(dw_http_request_duration_seconds_bucket{
    route="/api/workflows/{workflow_id}",status="200",kind="request"
  }[5m])))
```

In a Prometheus export of the Python SDK histogram, compare this with
`durable_workflow_client_request_duration_seconds_bucket` for
`route="/workflows/{workflow_id}",status_code="200"`. The client includes
the HTTP transport and intermediary time. Plot the two distributions
side by side. Percentiles summarize different samples and cannot be
subtracted to calculate network latency. For worker polling, compare the
corresponding poll route with `kind="worker_poll"`. [Python SDK metrics reference](https://github.com/durable-workflow/sdk-python/blob/main/docs/reference/metrics.md)

Set `DW_HTTP_ACCESS_LOG=true` to emit one JSON record per completed response
to stderr. It contains `event="http_access"`, the route template, method,
status, kind and `duration_seconds`. Unmatched routes share
`__unmatched__`. The log records route templates and response metadata only.
Use the deployment's log retention settings when enabling access logs.

#### Task queue series

Labels: `namespace`, `task_queue`, `task_kind`, and `build_id` where shown.
A workflow task's `build_id` is its own compatibility marker, else its run's,
as the build-id rollout view groups pending workflow tasks. Activity tasks are
not routed by build id and carry `build_id=""`. Unversioned work also carries
`build_id=""`.

| Series | Labels | Meaning |
| --- | --- | --- |
| `dw_task_queue_ready_tasks` | queue, kind, build | Ready tasks, due now or later. |
| `dw_task_queue_due_tasks` | queue, kind, build | Ready tasks whose availability time has passed: work waiting for a worker now. |
| `dw_task_queue_delayed_tasks` | queue, kind, build | Ready tasks scheduled for later (retry backoff, delayed work). Not a reason to scale up. |
| `dw_task_queue_leased_tasks` | queue, kind, build | Tasks leased to a worker and not yet completed. |
| `dw_task_queue_expired_leases` | queue, kind, build | Leased tasks whose lease expired and awaits repair. |
| `dw_task_queue_oldest_due_task_age_seconds` | queue, kind, build | Seconds the oldest due task has been ready (its ready origin, or its availability time when later); `0` with no due task. Absent when due tasks have no recorded readiness time. |
| `dw_task_queue_dispatchable_tasks` | queue, kind, build | Due tasks that admission lets a worker lease now. This is the scale-up signal. |
| `dw_task_queue_admission_state` | queue, kind, `state` | `1` for the current state, `0` for the others: `accepting` (a budget applies and has capacity), `throttled` (a budget is exhausted or dispatch is paused) or `unlimited`. |
| `dw_task_queue_admission_remaining_capacity` | queue, kind | Tasks the admission budget still lets workers lease. Absent when unlimited. |
| `dw_task_queue_activity_dispatch_paused` | queue | `1` while an operator pause holds activity dispatch on the queue. |
| `dw_task_queue_active_pollers` | queue, build | Workers registered on the queue with a fresh heartbeat. `0` (with `build_id=""`) when none runs. |
| `dw_task_queue_worker_slots` | queue, kind | Concurrent task slots the fresh workers declare. |
| `dw_task_queue_available_worker_slots` | queue, kind | Free slots the fresh workers last reported. |

A queue is reported while it has ready or leased tasks, a fresh worker or an
activity dispatch pause. When a queue drains and its workers stop, its backlog
series disappear; sum them in queries (`sum(...)` of nothing is no data, so
add `or vector(0)`).

Native enforces no server-side lease or dispatch budgets, so its admission
state is `unlimited` except while an operator pause
([`POST /api/task-queues/{task_queue}/activity-dispatch/pause`](contracts/task-queue-activity-dispatch-pause.md))
holds a queue's activity dispatch: the activity kind is then `throttled` with
remaining capacity `0`, and its dispatchable tasks are `0` however large the
due backlog grows.

#### Retry and schedule series

| Series | Labels | Meaning |
| --- | --- | --- |
| `dw_activity_retrying` | queue | Activities waiting for a retry attempt after a failed attempt. |
| `dw_activity_oldest_retrying_age_seconds` | queue | Seconds since the oldest retrying activity first started; `0` with none. |
| `dw_activity_failed_attempts` | queue | Failed activity attempts of retained runs. Retention pruning lowers it. |
| `dw_schedules` | `state` (`active`, `paused`) | Schedules by state. |
| `dw_schedules_missed` | — | Active schedules whose next fire time passed without firing. |
| `dw_schedule_oldest_missed_age_seconds` | — | Seconds since the most overdue next fire time; `0` with none. |

Schedule fire, failure and skip totals are not exported. The stored totals
cover only existing schedules, so they fall when a schedule is deleted; they
are not counters.

#### Run and activity series

These are the families of the JSON `GET /api/system/prometheus-metrics`
summary, labeled by `task_queue` and `workflow_type` (plus `activity_type` for
activities). They count rows retained in visibility, so retention pruning
lowers them; they are gauges, not counters.

| Series | Meaning |
| --- | --- |
| `dw_workflow_runs_retained` | Retained runs of the queue and type. |
| `dw_workflow_runs{status}` | Retained runs by `status`: `running`, `completed`, `failed`, `cancelled`, `terminated`. |
| `dw_workflow_run_duration_seconds` | Histogram of closed run durations (buckets 0.1 to 900 seconds). |
| `dw_activity_executions_retained` | Retained activity executions. |
| `dw_activity_executions{status}` | Executions by `status`: `running` (pending or running), `completed`, `failed`, `cancelled`. |
| `dw_activity_execution_duration_seconds` | Histogram of closed execution durations. |

Because pruning lowers the histogram counts, which Prometheus reads as a
counter reset, use them for ratios and quantiles over a window rather than
`rate()` across retention passes.

#### Scale workers from zero

Scale a worker Deployment on work it could take now plus work it holds.
This example uses one scrape target for the `orders` namespace.

```promql
(
  sum(dw_task_queue_dispatchable_tasks{namespace="orders",task_queue="orders"})
  + sum(dw_task_queue_leased_tasks{namespace="orders",task_queue="orders"})
)
or (vector(0) and on() up{job="durable-workflow-server"} == 1)
```

Use the scrape job for that namespace in the `up` selector. If a job scrapes
several namespaces, add a target label to identify the matching scrape. When
the queue has no series, a healthy scrape yields zero and an unavailable
scrape leaves the result empty.

With a target of the worker's concurrent slots per replica, the first started
workflow makes this `1` and wakes one worker; when every task is completed it
returns to `0` and the autoscaler can scale back to zero after its cooldown.
Delayed tasks (retry backoff) never add replicas, and a throttled queue's
growing due backlog contributes nothing until its admission reopens, so the
autoscaler does not add workers that cannot take work. Both series come from
the same rows, so they are present or absent together. To scale one build-id cohort, add its `build_id` label; follow the
[build-id rollout guidance](https://durable-workflow.github.io/docs/2.0/polyglot/worker-build-id-rollout#pending-workflow-tasks-by-cohort)
before scaling a cohort to zero, because pending work pinned to an older
build needs a compatible worker even when the queue total looks idle.

KEDA's Prometheus scaler, a Prometheus `ServiceMonitor` and the values for the
Helm chart are in [`k8s/helm/examples/autoscaling`](../k8s/helm/examples/autoscaling).

## Authentication

Set the `X-Namespace` header to target a specific namespace (defaults to `default`).
Requests that name a namespace which is not registered receive a `404` with
`reason: "namespace_not_found"`; register the namespace via
`POST /api/namespaces` before directing traffic to it. The namespace
administration endpoints (`/api/namespaces/**`), cluster discovery
(`/api/cluster/info`), and the unauthenticated `/api/health` and `/api/ready`
probes are exempt from this check.

### Token Authentication

For production, prefer role-scoped tokens:

```env
DW_AUTH_DRIVER=token
DW_WORKER_TOKEN=worker-secret
DW_OPERATOR_TOKEN=operator-secret
DW_ADMIN_TOKEN=admin-secret
```

`worker` tokens can call `/api/worker/*` and `/api/cluster/info`. `operator`
tokens can call workflow, history, schedule, search-attribute, task-queue,
worker-read, namespace-read, and diagnostic `/api/worker/register` endpoints.
`admin` tokens can call admin operations such as `/api/system/*`, namespace
create/update/delete, worker deletion, diagnostic `/api/worker/register`, and
can also use operator endpoints.

The native Server also accepts a scrape-only `metrics` role
(`DW_METRICS_TOKEN`, or a runtime credential with `"roles":["metrics"]`). It
reads `GET /metrics` and nothing else; see
[Prometheus scrape and autoscaling](#prometheus-scrape-and-autoscaling).

```bash
curl -H "Authorization: Bearer operator-secret" \
     -H "X-Durable-Workflow-Control-Plane-Version: 2" \
     http://localhost:8080/api/workflows
```

Existing deployments can keep `DW_AUTH_TOKEN`. When no role tokens
are configured, that legacy token keeps full API access. Once any role token is
configured, the legacy token is treated as an admin token and no longer grants
worker-plane access. Set `DW_AUTH_BACKWARD_COMPATIBLE=false` to
require role-scoped credentials only.

For audit trails that need stable actor names rather than role labels, set
`DW_PRINCIPAL_TOKENS` to a JSON object or array. Each entry maps one bearer
token to a server-derived principal subject and role set; clients cannot
override these values with request payloads or headers.

```env
DW_AUTH_DRIVER=token
DW_AUTH_BACKWARD_COMPATIBLE=false
DW_PRINCIPAL_TOKENS='[{"token":"alice-v1","subject":"alice","roles":["operator"],"label":"Alice"},{"token":"alice-v2","subject":"alice","roles":["operator"],"label":"Alice"},{"token":"bob-token","subject":"bob","roles":["operator"],"label":"Bob"},{"token":"worker-token","subject":"worker:principal-conformance","roles":["worker"]}]'
```

The same subject may appear on more than one token, which lets operators
rotate credentials without changing the recorded principal identity.

When a principal token defines `tenant`, the built-in provider binds that
principal to the matching namespace. Requests for another namespace are
denied before namespace lookup, and a tenant-bound principal cannot use the
namespace-list endpoint.

Hosts that need to provision and revoke namespace credentials without a
Server restart can enable the database-backed credential store:

```env
DW_RUNTIME_CREDENTIALS_ENABLED=true
```

Use an existing static admin token with the versioned
`/api/runtime-credentials` endpoints. `PUT /api/runtime-credentials/{id}`
accepts a caller-generated bearer token, subject, `operator` or `worker` role
(native also accepts the scrape-only `metrics` role), and required tenant
namespace. Repeating an identical `PUT` is idempotent;
different attributes return `409`. The Server stores only the SHA-256 token
hash and a non-secret prefix. Rotation and revocation take effect without a
restart:

```bash
curl -X PUT http://localhost:8080/api/runtime-credentials/acme-client \
  -H "Authorization: Bearer admin-secret" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -H "Content-Type: application/json" \
  -d '{"token":"replace-with-a-long-random-token","subject":"acme:client","roles":["operator"],"tenant":"acme"}'
```

The admin surface also supports list, show,
`POST /api/runtime-credentials/{id}/rotate`, and idempotent revoke through
`DELETE /api/runtime-credentials/{id}`. Keep a static admin credential for
recovery; database credentials cannot grant the `admin` role.

### Signature Authentication

Signature auth supports the same role split with role-scoped HMAC keys:

```env
DW_AUTH_DRIVER=signature
DW_WORKER_SIGNATURE_KEY=worker-hmac-key
DW_OPERATOR_SIGNATURE_KEY=operator-hmac-key
DW_ADMIN_SIGNATURE_KEY=admin-hmac-key
```

```bash
# HMAC-SHA256 of the request body
curl -H "X-Signature: COMPUTED_SIGNATURE" \
     -H "X-Durable-Workflow-Control-Plane-Version: 2" \
     http://localhost:8080/api/workflows
```

The legacy `DW_SIGNATURE_KEY` follows the same compatibility rule
as the legacy bearer token.

Set `DW_AUTH_DRIVER=none` to disable authentication (development only). It
admits every request as an administrator. The native 3.x release image
refuses `none`, under `DW_AUTH_DRIVER` or `WORKFLOW_SERVER_AUTH_DRIVER`,
before it opens storage or binds its port; only a native development build
accepts it.

### Custom Auth Providers

Set `DW_AUTH_PROVIDER` to the fully-qualified class name of a Laravel
container-resolvable implementation of `App\Contracts\AuthProvider` to replace
the built-in token/signature provider without editing server middleware. The
provider returns an `App\Auth\Principal` from `authenticate(Request $request)`
and receives each route authorization decision as
`authorize(Principal $principal, string $action, array $resource): bool`.

The route resource includes `allowed_roles`, HTTP method/path, route name/URI,
normalized `requested_namespace`, `default_namespace`, route parameters,
`operation_family`, `operation_name`, and stable identifier fields such as
`workflow_id`, `run_id`, `signal_name`, `query_name`, `update_name`, `task_id`,
`query_task_id`, `task_queue`, `worker_id`, `schedule_id`, and
`search_attribute_name` when those identifiers are present on the route or in
the worker request body. This resource is built before namespace existence is
validated, so tenant-aware providers can deny access by namespace or workflow
resource without reparsing raw paths and without revealing whether a namespace
exists. The authenticated principal is also recorded in workflow command
attribution so signal/update/query history can show the subject, roles, tenant,
and non-secret claims supplied by the provider. When `DW_AUTH_PROVIDER` is set,
`/api/ready` verifies that the class resolves and implements `AuthProvider`;
built-in token or signature credentials are not required for readiness.

## Deployment

### Docker

The PHP image uses a source allowlist for BuildKit. It excludes local environment
files, credentials, Git metadata, cached configuration, databases and storage data.
The root `.env.example` template and directory scaffolding remain available;
the build creates a fresh empty SQLite file. Supply deployment credentials
through runtime environment variables or secrets.

```bash
DOCKER_BUILDKIT=1 docker build -t durable-workflow-server .
export DW_AUTH_TOKEN=dev-token
docker volume create durable-workflow-sqlite

# Bootstrap schema + default namespace once
docker run --rm \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  durable-workflow-server server-bootstrap

# Start the API server
docker run --rm -p 8080:8080 \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  durable-workflow-server

# In a separate terminal, run the database queue worker that fires timers
docker run --rm \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  durable-workflow-server php artisan queue:work database --sleep=1 --tries=3
```

The Dockerfile clones the exact `durable-workflow/workflow` commit locked by
Composer and refreshes the package metadata from that verified source before
installing production dependencies. Optional `WORKFLOW_PACKAGE_SOURCE`,
`WORKFLOW_PACKAGE_REF`, and `WORKFLOW_PACKAGE_COMMIT` build arguments must match
the Composer authority. Source-admission jobs may additionally set
`WORKFLOW_PACKAGE_QUALIFICATION_REF` to the same landed commit when its release
tag is not available yet; any mismatch fails before the image is assembled.

The production image defaults to `DB_CONNECTION=sqlite`,
`DB_DATABASE=/app/database/database.sqlite`, `QUEUE_CONNECTION=database`, and
`CACHE_STORE=file` so the plain Docker quickstart works without external
services. The entrypoint creates the SQLite file when a fresh volume is mounted.
`server-bootstrap` creates the database queue schema, and the queue-worker
process shown above consumes the persisted timer jobs.
For custom paths and existing operator databases, follow the
[SQLite mount identity contract](#sqlite-mount-identity).
SQLite uses WAL journal mode and a 5000 ms busy timeout by default. The server
also serializes SQLite worker poll claim probes through the polling cache so
concurrent PHP/Python workers in the single-container quickstart do not race the
same file-backed writer lock. If worker poll endpoints still return
`reason: backend_lock_pressure`, workers should retry with backoff; sustained
multi-worker deployments should use MySQL/PostgreSQL with Redis.

The standalone server image also reserves Apache request-worker capacity for
health and control-plane routes. Empty workflow and activity worker long-polls
acquire a process-owned wait slot before sleeping; once the node-local slot cap
is reached, additional idle polls receive `Retry-After: 1` instead of entering
a tight empty-poll loop or holding another PHP server worker for the full poll
timeout. Published Python, Rust, PHP, and other supported workers receive a
typed HTTP 429 response with the `long_poll_capacity_exhausted` reason,
`retryable: true`, and an advertised one-second retry delay. Supported workers
treat the refusal as transient and back off before polling again. Returning the
refusal immediately keeps workers alive, bounds empty repolling, and releases
the Apache request worker instead of sleeping through the retry window. Idle query-task
polls use a separate wait-slot budget, set to one slot on the default
standalone image, so workflow/activity polls cannot starve live workflow queries
across the PHP and Python worker queues, and query-task polls cannot consume the
request workers needed by the waiting query request and the worker's completion
callback. A
poll that arrives after a query task is pending still claims it immediately
before any wait slot or backpressure response is required. Size
the Apache request pool and the two explicit long-poll caps for expected
concurrent workflow, activity, and query workers when building a larger
standalone deployment.

Wait-slot limits are per API node. Every API node must keep its own unique
`DW_SERVER_ID` and a writable `DW_POLLING_CACHE_PATH`, including deployments
using Redis or database polling caches. One local admission lock enforces the
global and optional namespace caps together; a held file descriptor owns each
wait on a local filesystem supporting advisory locks. All request processes
on that node must share this directory. Normal request cleanup or process death
releases capacity without
waiting for a cache TTL, and one node cannot release another node's live waits.
Unavailable local lock storage refuses an idle wait rather than exceeding its
configured limits. Immediate task claims still happen before wait admission;
a wait slot does not grant a task lease.

#### Native long-poll capacity

The native Rust server reads the same settings
([#982](https://github.com/durable-workflow/server/issues/982)). Its waits
are not processes: a held poll is a parked task on one async runtime, so the
caps bound how many idle polls hold connections and probe the database, not
how many request workers are busy.

- `DW_WORKER_LONG_POLL_MAX_CONCURRENT` caps held workflow and activity waits
  (including workflow polls that also take update-validation tasks), and
  `DW_QUERY_TASK_POLL_MAX_CONCURRENT` caps held query-task and
  update-validation waits. Each cap covers the whole node, across namespaces.
  A value is read as PHP reads it: a numeric value, floored at zero, is the
  cap; zero refuses every wait; unset or not numeric leaves the budget
  unbounded, which is native's default.
- A poll probes for work first. Only a poll that found nothing and would wait
  takes a slot, and it keeps the slot until it answers. A poll that cannot
  take one answers at once with the `429 long_poll_capacity_exhausted`
  response described above (`Retry-After: 1`, `retryable: true`,
  `retry_after_seconds: 1`, `wait_pool` `worker` or `query-task`). A poll
  with `timeout_seconds: 0` never needs a slot. A slot is released when the
  poll answers, fails or its client disconnects.
- `DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS` reserves database connections.
  A native node opens four database connections on MySQL, PostgreSQL and file
  SQLite: a request pool of three, which health, readiness, admin and every
  other request share, and one connection of its own for the maintenance loop
  (timers, schedules, repair, retention and the task-offer key window), so
  maintenance never borrows a request connection
  ([#1355](https://github.com/durable-workflow/server/issues/1355)). With a
  positive value, every poll probe takes one of `request pool size - reserved`
  probe permits (at least one) and returns it before waiting, so polls never
  occupy the reserved connections. Unset or zero, probes take no permit.
- Whatever the setting, a node-wide budget covers this held-poll work, so it
  never occupies the request pool's last connection, which producers and
  registrations keep. Its reads take at most the request pool size minus two
  connections (at least one); the one transaction holding the transition
  queue takes one more, so it never waits behind those reads:
  - a held workflow or activity poll's admission and replay reads, its
    probes' lock-free reads (one connection per probe, returned before it
    claims or waits), its claim transactions and, for a workflow poll, its
    abandoned-lease recovery reads;
  - a held query-task poll's admission reads, readiness reads (registration
    proof, superseded request, ready query keys and the leased-hold snapshot)
    and fence reads;
  - a workflow poll that also takes update-validation tasks: its admission
    reads, fence reads and each probe's transaction;
  - every held poll's cross-kind wake reads.

  A budgeted transaction takes its turn on the transition queue before its
  budget connection. A held poll's claim stops waiting for its turn two
  seconds after the poll's own deadline, so no lease is committed for an
  answer nobody reads, but never before the five-second acquisition
  deadline. After its first probe, a held poll waits for a read slot until
  its own deadline, however long that is: it never fails for a slot wait
  longer than the five-second acquisition deadline while it has time left
  ([#1372](https://github.com/durable-workflow/server/issues/1372)). If no
  slot is free by its deadline it answers as at its deadline (`empty`)
  instead of waiting for other polls' reads past it.
  Other work stays outside the budget and competes for
  the last connection: ordinary requests, query-task claims and the shared
  answers of duplicate query polls, recording an empty or stale poll answer,
  workflow lease recovery transactions, and polls carried by a task
  completion
  ([#1204](https://github.com/durable-workflow/server/issues/1204),
  [#1355](https://github.com/durable-workflow/server/issues/1355)).
- A transition waits its turn on the transition queue for up to 30 seconds,
  longer than the pool's five-second acquisition deadline, so a transition
  ahead that is waiting out a database stall (an HA failover) is waited for.
  Running out of that wait answers `503 storage_unavailable`, logged as
  `transition_queue_timeout`; a pool with no free connection once it is the
  transition's turn is still `pool_timeout`. A producer or registration can
  therefore take about 35 seconds before it answers during a stall: give
  clients an HTTP timeout above that.
- A connection idle for a second or more is pinged before checkout for at
  most one second. One the database has not answered by then is handed out,
  so the request waits on its own statements instead of the stall counting
  against the acquisition deadline. Such a connection must then receive a
  reply within 45 seconds; otherwise its statement fails
  (`503 storage_unavailable`, `database_io`) and the connection is discarded,
  so a database that went silent without resetting its connections (common
  in an HA failover) holds a request for at most about 46 seconds
  ([#1355](https://github.com/durable-workflow/server/issues/1355)).
- Every statement on a MySQL, MariaDB or PostgreSQL runtime connection (the
  request pool and the maintenance connection) must receive each reply
  within 45 seconds, and a connection returning to the pool must receive
  each reply of its session reset and release ping within 5 seconds (the
  acquisition deadline; MySQL's session reset already has the same
  deadline). Otherwise the statement fails with `503 storage_unavailable`
  (`database_io`), or the release ends, and the connection is discarded, so
  a silent database is noticed on each reply instead of when the operating
  system gives up on the peer
  ([#1368](https://github.com/durable-workflow/server/issues/1368)). A
  release that holds a held poll's budget slot frees it within about 5
  seconds of reset plus 5 seconds of release ping, well within the 30-second
  transition queue deadline. Socket flushes for statement writes and release
  commands share the same bound (45 seconds for statements, 5 seconds for
  release), so a large send to a peer that has stopped reading also fails
  within the bound and discards the connection
  ([#1385](https://github.com/durable-workflow/server/issues/1385)).
  A healthy server always replies sooner: PostgreSQL ends every statement
  on these connections after 30 seconds (`statement_timeout`, first-start
  migrations included), and MySQL and MariaDB refuse lock waits after 5
  seconds (`lock_wait_timeout`, `innodb_lock_wait_timeout`). On MySQL and
  MariaDB, which have no statement time limit, a namespace delete and the
  per-run retention prune (statements with no row bound) run without the
  reply bound; on PostgreSQL `statement_timeout` still bounds them. Other
  long statements on MySQL, such as operator aggregates over very large
  namespaces, now fail after 45 seconds of silence, as PostgreSQL already
  fails them after 30. Schema bootstrap and upgrade connections opened
  outside the pools keep no reply bound.
- The per-namespace variants, `DW_WORKER_LONG_POLL_MAX_CONCURRENT_PER_NAMESPACE`
  and `DW_QUERY_TASK_POLL_MAX_CONCURRENT_PER_NAMESPACE`, are still ignored by
  native.

There is no shared lock directory: each native process enforces its own
caps, so `DW_POLLING_CACHE_PATH` is not needed.

Across Compose, plain Docker, and Kubernetes, the supported bootstrap contract
is the same: run the image's `server-bootstrap` command once before starting the
server and worker processes. `/api/health` is a liveness check; `/api/ready`
is the readiness check to gate workers and load balancers. After bootstrap it
also evaluates the bounded workflow v2 backend and fleet admission checks, so
`DW_V2_FLEET_VALIDATION_MODE=fail` incompatibility and error-severity backend
issues keep the server unready until corrected. Full rollout-safety and
operator-metrics snapshots remain explicit diagnostic requests and are not
executed by recurring liveness/readiness probes.

### Dedicated Matching-Role Daemon

By default every queue worker runs the in-worker matching-role wake on each
Looping event for its Laravel queue. The published Compose scheduler also runs
an unscoped repair pass every maintenance cycle. That pass covers remote SDK
task queues, which need not match the Laravel queue name, and recovers their
expired leases. This is the small-deployment shape of the matching role
described in `vendor/durable-workflow/workflow/docs/architecture/task-matching.md`.

Larger deployments can opt execution-only nodes out of the in-worker wake and
run the broad sweep as a dedicated process. Set
`DW_V2_MATCHING_ROLE_QUEUE_WAKE=false` on the queue-worker pods or services so
they stop broad-polling the durable task table, and run a single dedicated
matching-role daemon alongside the cluster:

```bash
docker run --rm --name durable-workflow-matching \
  --env-file .env \
  durable-workflow-server \
  php artisan workflow:v2:repair-pass --loop
```

For Compose deployments, layer the
[`docker-compose.dedicated-matching.yml`](../docker-compose.dedicated-matching.yml)
override on top of the PHP 2.x `docker-compose.php.yml` to enable the same shape:

```bash
docker compose \
  -f docker-compose.php.yml \
  -f docker-compose.dedicated-matching.yml \
  up
```

The override sets `DW_V2_MATCHING_ROLE_QUEUE_WAKE=false` on the `server`,
`worker`, `scheduler`, and `matching` services. This also skips the published
Compose scheduler's unscoped pass, so the dedicated daemon is the sole broad
sweep owner. It adds a
`matching` service running `php artisan workflow:v2:repair-pass --loop` so the
broad sweep runs in a dedicated process operators can scale and supervise
independently of API ingress and execution workers. It also pins
`DW_SERVER_TOPOLOGY_SHAPE=split_control_execution` on the `server`, `worker`,
`scheduler`, and `matching` services, with `DW_SERVER_PROCESS_CLASS`
respectively set to `control_plane_node`, `execution_node`,
`scheduler_node`, and `matching_node`. That lets the public HTTP service
advertise the split control-plane shape while execution, scheduler, and
matching nodes each report their own independent role class and a consistent
`wake_owner=dedicated_repair_pass` contract.

The daemon respects the watchdog loop throttle on every iteration so multiple
cooperating matching-role processes coexist without duplicating broad-poll
work, sleeps for `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS` between iterations
(override with `--sleep-seconds=N`), and traps `SIGTERM`/`SIGINT` for graceful
shutdown so process supervisors (systemd, supervisord, Docker, Kubernetes)
can drain it cleanly between deployments.

Operators can confirm which shape each node is running through the
operator-metrics snapshot: the `matching_role` block on
`GET /api/system/metrics` reports `queue_wake_enabled`, `shape` (`in_worker`
or `dedicated`), the configured `task_dispatch_mode`, the frozen
`partition_primitives`, and the durable `backpressure_model` per process. The
cluster-topology manifest reuses the same matching-role contract and adds
`wake_owner` so operators can see which process class owns the broad-poll wake.

### Publishing Container Images

The `Release` workflow publishes multi-arch images to
Docker Hub (`durableworkflow/server`) and GitHub Container Registry
(`ghcr.io/durable-workflow/server`) when a server semver tag is pushed. The
workflow builds the server image with the exact public
`durable-workflow/workflow` package declared by the release source's
Composer manifest and lock, and verifies its retained source commit before the
image can be published.

The Server image embeds an already-published Workflow package. When a later
Server image needs a newer Workflow fix, publish that package first and update
both machine-owned Workflow package pins before tagging Server.
Declare the Server release in
`resources/release/source-release.json`. The Helm chart version in the same
record is selected independently and is never derived from the Server version.
Advance it explicitly in the same edit when the new `appVersion` should produce
a chart package. Render and check the bounded release metadata before tagging:

```bash
# In the server repo, render all exact current-version consumers.
node scripts/ci/sync-source-release.mjs --write
node scripts/ci/sync-source-release.mjs --check
SERVER_VERSION="$(node scripts/ci/sync-source-release.mjs --print server-version)"
git tag "$SERVER_VERSION" origin/main
git push origin "refs/tags/$SERVER_VERSION"
```

The server tag push publishes the exact version plus the semver aliases
generated by the release workflow, including `latest`, to both registries. After
the workflow finishes, verify the image provenance and runtime config before
announcing the release:

```bash
docker pull "durableworkflow/server:$SERVER_VERSION"
docker run --rm --entrypoint sh "durableworkflow/server:$SERVER_VERSION" -lc \
  'cat /app/.package-provenance && grep -n "serializer" /app/vendor/durable-workflow/workflow/src/config/workflows.php'

docker pull "ghcr.io/durable-workflow/server:$SERVER_VERSION"
docker run --rm --entrypoint sh "ghcr.io/durable-workflow/server:$SERVER_VERSION" -lc \
  'cat /app/.package-provenance && grep -n "serializer" /app/vendor/durable-workflow/workflow/src/config/workflows.php'
```

### Native image SBOMs

Each native Server release publishes an SPDX 2.3 software bill of materials
for each platform, `linux/amd64` and `linux/arm64`. The release workflow
generates it with Syft from the platform digest that passed the release smoke
test. It then attests both documents to the multi-arch image index in Docker
Hub and GHCR, alongside the build provenance. The GitHub Release verifies
those attestations before it is created and attaches the verified documents
as `server-linux-amd64.spdx.json` and `server-linux-arm64.spdx.json`.
Releases built before this, including 3.28.0 and 3.29.0, have no SBOM.

Verify the attestations and extract a platform's document with the GitHub CLI:

```bash
SERVER_VERSION=3.30.0
IMAGE=ghcr.io/durable-workflow/server   # or durableworkflow/server
gh attestation verify "oci://$IMAGE:$SERVER_VERSION" \
  --repo durable-workflow/server \
  --signer-workflow github.com/durable-workflow/server/.github/workflows/native-release.yml \
  --source-ref "refs/tags/$SERVER_VERSION" \
  --predicate-type https://spdx.dev/Document/v2.3 \
  --format json \
  --jq '.[].verificationResult.statement.predicate
        | select(.name == "durable-workflow-server-linux-amd64")' > server-linux-amd64.spdx.json
```

The command fails unless an attestation signed by the release workflow from
that tag verifies. The attestations are also stored in each registry as
referrers of the index, so `--bundle-from-oci` reads them from the registry
instead of from GitHub. Download the same documents from the Release with
`gh release download "$SERVER_VERSION" --repo durable-workflow/server --pattern '*.spdx.json'`.

The SBOM lists the operating-system packages in the image. The Server binary's
Rust dependencies are not listed yet, because the binary does not embed its
dependency metadata.

### Kubernetes

The published Helm chart in [`k8s/helm/durable-workflow/`](../k8s/helm/durable-workflow/)
is the recommended self-serve path for Kubernetes deployments. The raw
manifests remain the inspectable low-level alternative for teams that
intentionally do not want Helm in the rollout.

Both paths share the same external-persistence, singleton-scheduler, and
`/api/ready` readiness contracts. Use Helm values, Kustomize overlays, or
direct patches for environment-specific names, images, registry secrets, and
scaling policy.

The public manifests default to the released Docker Hub image recorded in
their machine-owned deployment metadata. For production, patch every workload
to the exact Docker Hub or GHCR tag or digest you intend to run before applying it. See
[`k8s/README.md`](../k8s/README.md) for the raw-manifest support boundary,
[`helm-validation.md`](helm-validation.md) for the Helm contract and
validation harness, and [`k8s/helm/durable-workflow/docs/UPGRADING.md`](../k8s/helm/durable-workflow/docs/UPGRADING.md)
for chart upgrade steps.

The chart and the raw manifests do not configure the external database.
`externalDatabase.connection: mariadb` needs no `max_allowed_packet` change for
native Servers that include
[#1234](https://github.com/durable-workflow/server/issues/1234): MariaDB's
16 MiB default holds every admitted workflow-task completion (see the
`limits.max_workflow_task_completion_bytes` notes under [System](#system)). Native Server 3.13.0 and
earlier, packaged by Chart 3.1.0 and earlier, store the whole completion in one
row; on MariaDB, raise `max_allowed_packet` to at least 64 MiB for those
releases or completions over about 16 MiB fail with a retried
`503 storage_unavailable`. On any MySQL-family database, keep
`max_allowed_packet` at least four times `DW_MAX_PAYLOAD_BYTES`.

The supported apply order is configuration first, migration second, and
long-running workloads last. The helper script enforces that order, deletes any
previous completed migration job so a new deploy runs bootstrap again, waits for
completion, and only then applies the server, worker, scheduler, and disruption
budget manifests:

```bash
scripts/deploy-k8s.sh
```

Before running it, create the externally managed credentials referenced by the
pod templates. Keep DB/Redis credentials out of `k8s/secret.yaml`; manage them
with your secret manager, External Secrets operator, or `kubectl`:

```bash
# Required by every pod template.
kubectl apply -f k8s/namespace.yaml
kubectl create secret generic durable-workflow-database \
  --namespace durable-workflow \
  --from-literal=DB_USERNAME=workflow \
  --from-literal=DB_PASSWORD='CHANGE_ME'

# Optional; only create this when Redis requires auth.
kubectl create secret generic durable-workflow-redis \
  --namespace durable-workflow \
  --from-literal=REDIS_USERNAME='<username>' \
  --from-literal=REDIS_PASSWORD='<password>'

# App config and app-level secrets only.
kubectl apply -f k8s/secret.yaml

# Manual equivalent of scripts/deploy-k8s.sh.
kubectl apply -f k8s/migration-job.yaml
kubectl -n durable-workflow wait --for=condition=complete --timeout=300s job/durable-workflow-migrate

kubectl apply -f k8s/server-pdb.yaml
kubectl apply -f k8s/server-deployment.yaml
kubectl apply -f k8s/worker-deployment.yaml
kubectl apply -f k8s/scheduler-cronjob.yaml
```

The Deployment manifests omit `spec.replicas` so HorizontalPodAutoscalers and
operator overlays own replica count. For static installs, set replicas in your
overlay or with `kubectl scale`.

### Configuration

#### Database TLS

Set `DB_SSLMODE` on every PHP role: API server, queue worker, bootstrap/migration
and scheduler. `DB_SSL_MODE` remains an accepted alias. PostgreSQL defaults to
`prefer`; MySQL/MariaDB retain their existing connection defaults when no mode
is set. These defaults do not guarantee an authenticated encrypted connection.

| Mode | PostgreSQL | MySQL / MariaDB |
| --- | --- | --- |
| `disable` | No TLS. | No TLS; conflicting TLS options are refused. |
| `allow`, `prefer` | Opportunistic TLS, with no encryption/identity guarantee. | Refused; select an explicit supported mode. |
| `require` | Encryption required. | Encryption required with mysqlnd and OpenSSL. |
| `verify-ca` | Encryption and a trusted certificate chain required. | Refused because PDO mysqlnd cannot provide chain-only verification independently of hostname verification. |
| `verify-full` | Encryption, trusted chain and matching hostname required. | Encryption, trusted chain and matching hostname required. |

For certificate verification, set `DB_SSLROOTCERT` to a readable CA bundle file
inside each container. `DB_SSL_ROOT_CERT` is an alias. The existing
`MYSQL_ATTR_SSL_CA` can supply the CA for an explicit MySQL/MariaDB
`verify-full` connection. Conflicting canonical/alias/URL settings refuse
startup rather than silently change the requested mode or CA. Required TLS
uses an explicit TCP hostname; Unix socket connections are refused. PHP
MySQL/MariaDB explicit TLS modes require PDO MySQL with mysqlnd and OpenSSL.

```bash
DB_CONNECTION=pgsql
DB_HOST=database.example.com
DB_SSLMODE=verify-full
DB_SSLROOTCERT=/run/database-ca/ca.pem
```

Use the certificate's DNS hostname, not a substituted IP or unrelated alias.
With Helm, set `externalDatabase.sslMode: verify-full` and
`externalDatabase.tls.existingSecret` / `caKey`; the chart mounts the CA into
all four roles. Create that Secret before installing or upgrading because the
bootstrap hook runs before chart-managed ordinary resources.

`/api/ready` reports `checks.database.tls` only after its connection succeeds.
`encrypted`, `ca_verified` and `hostname_verified` describe the guarantee
enforced by the selected driver mode. Opportunistic or unconfigured TLS is
reported as unknown, not verified. `require` guarantees encryption, not server
identity. Invalid configuration reports `database_tls_configuration_invalid`;
a failed required connection reports `database_tls_connection_failed` and
returns 503. The report covers PHP to its configured endpoint. If that endpoint
is a pooler, qualify and configure its separate database connection too; a
backend `pg_stat_ssl` result does not prove the client-to-pooler leg.

#### PostgreSQL connection pooling

The PostgreSQL connection uses native bound parameters without session-scoped
prepared-statement names. This lets PgBouncer transaction pooling change the
physical database session between transactions. Direct and session-pooled
connections use the same binding path. Statement pooling cannot support the
Server's multi-statement transactions.

The PHP feature job runs the same alternating-client and rollback controls
against direct PostgreSQL, session pooling, and transaction pooling with
`max_prepared_statements=0` and `200`. The transaction controls require actual
backend PID changes. To run against an isolated existing endpoint:

```bash
DW_POOLER_TEST_HOST=pooler DW_POOLER_TEST_PORT=6432 \
DW_POOLER_TEST_DATABASE=isolated_test DW_POOLER_TEST_USER=test_user \
vendor/bin/phpunit tests/Feature/PostgresPoolerTest.php --fail-on-skipped
```

These controls do not validate custom session settings, temporary tables,
advisory-lock policies, or arbitrary proxy configurations. Qualify the exact
published image, pooler version, authentication, schema and TLS settings used
by your deployment before routing production traffic.

`/api/ready` executes a bound read on the primary database connection and
reports `checks.database.check_scope=bound_read_query`. A failed query reports
`database_query_failed`, or `backend_unavailable` when the backend is known to
be unavailable. This remains a read probe: `write_capacity_verified=false`.
Use an actual workflow canary to verify writes and the complete worker path.
The runtime's lightweight bootstrap guard remains connection-only.

Workflow runtime tuning uses `DW_*` environment variables. Database settings
such as the `DB_*` TLS inputs above retain Laravel's configuration boundary.
`config/dw-contract.php` is the authoritative machine-checkable contract;
CI (`tests/Unit/EnvContractTest.php`) diffs it against `.env.example`,
`docker-compose.yml`, and `k8s/secret.yaml` so the three surfaces cannot
drift. The Docker entrypoint runs `php artisan env:audit` at boot and
logs a warning for any unknown `DW_*` variable and any legacy
`WORKFLOW_*` / `ACTIVITY_*` name that still resolves.

Rules — every `DW_*` name is stable across minor versions. Additions are
fine; renames require a major bump with the old name alias-honored for
one major. Set `DW_ENV_AUDIT_STRICT=1` to fail container boot when the
audit finds drift.

Admission policy values must be JSON objects. The queue admission, namespace
request, namespace durable-state and external-payload namespace maps reject
malformed JSON, arrays, scalars and explicit `null` during configuration loading
and `config:cache`, regardless of `DW_ENV_AUDIT_STRICT`. An omitted setting or
`{}` retains its empty-map default. A supplied `DW_*` value takes precedence
over its legacy alias even when it is invalid; an invalid primary does not
silently select the legacy policy. The error names the selected variable
without printing its value. Correct that variable, rebuild cached configuration
and restart every role that uses it.

#### Environment variable reference

The full table below is generated from `config/dw-contract.php` and lists
every operator-facing variable the server honors.

| `DW_*` name | Default | Description |
| --- | --- | --- |
| `DW_MODE` | `service` | Server mode: "service" (external workers poll) or "embedded" (local queue). |
| `DW_OBSERVABILITY_CONTEXT_ENABLED` | `false` | Enable optional trace context persistence, poll delivery and capability discovery when the application-owned API and nullable schema are installed. See [trace context preparation](observability-context.md). |
| `DW_OBSERVABILITY_BAGGAGE_ALLOWLIST` | `[]` | JSON list of baggage keys permitted on run origins. Dispatch and secondary links never carry baggage. |
| `DW_EXTERNAL_PAYLOAD_COMPLETION_MAX_BYTES` | (unset) | Distinct bytes permitted across immutable upload slots in one leased completion; defaults to `DW_EXTERNAL_PAYLOAD_MAX_BYTES`. |
| `DW_SERVER_ID` | `gethostname()` | Unique identifier for this server instance. |
| `DW_SERVER_KEY` | generated at container boot | Optional server-internal runtime key. |
| `DW_DEFAULT_NAMESPACE` | `default` | Namespace used when a request omits the namespace header. |
| `DW_TASK_DISPATCH_MODE` | (unset) | Override for `workflows.v2.task_dispatch_mode`. Set to `queue` to dispatch locally in service mode. |
| `DW_EXTERNAL_EXECUTOR_CONFIG_PATH` | (unset) | Optional path to an external executor handler-mapping JSON config. |
| `DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY` | (unset) | Optional named overlay to apply before validating the external executor config. |
| `DW_AUTH_PROVIDER` | (unset) | Optional FQCN implementing `App\Contracts\AuthProvider`; unset uses the built-in driver. |
| `DW_AUTH_DRIVER` | `token` | `none`, `token`, or `signature`. |
| `DW_AUTH_TOKEN` | (unset) | Single shared bearer token (backward-compat credential). |
| `DW_SIGNATURE_KEY` | (unset) | HMAC key used when `DW_AUTH_DRIVER=signature` and no role-scoped key is configured. |
| `DW_WORKER_TOKEN` | (unset) | Bearer token for worker registration, deregistration, polling, heartbeat, and completion. |
| `DW_OPERATOR_TOKEN` | (unset) | Bearer token for the operator control plane and diagnostic worker registration; polling remains worker-only. |
| `DW_ADMIN_TOKEN` | (unset) | Bearer token for the admin control plane and diagnostic worker registration; polling remains worker-only. |
| `DW_METRICS_TOKEN` | (unset) | Native only. Scrape-only bearer token for `GET /metrics` in any namespace; reaches no other route and must differ from every other token. |
| `DW_HTTP_ACCESS_LOG` | `false` | Native only. Set to `true` or `1` to write request timing JSON to stderr. |
| `DW_PRINCIPAL_TOKENS` | (unset) | JSON token map for named bearer-token principals used by audit attribution. |
| `DW_RUNTIME_CREDENTIALS_ENABLED` | `false` | Enable durable, namespace-bound operator and worker credentials managed through the admin API. |
| `DW_WORKER_SIGNATURE_KEY` | (unset) | Role-scoped HMAC key for worker registration, deregistration, polling, heartbeat, and completion. |
| `DW_OPERATOR_SIGNATURE_KEY` | (unset) | Role-scoped HMAC key for the operator control plane and diagnostic worker registration; polling remains worker-only. |
| `DW_ADMIN_SIGNATURE_KEY` | (unset) | Role-scoped HMAC key for the admin control plane and diagnostic worker registration; polling remains worker-only. |
| `DW_AUTH_BACKWARD_COMPATIBLE` | `true` | Honor `DW_AUTH_TOKEN` / `DW_SIGNATURE_KEY` as a fallback when role credentials are missing. |
| `DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS` | `false` | Accept forwarded caller/auth headers from a trusted gateway. |
| `DW_CALLER_TYPE_HEADER` | `X-Workflow-Caller-Type` | Request header carrying the forwarded caller type. |
| `DW_CALLER_LABEL_HEADER` | `X-Workflow-Caller-Label` | Request header carrying the forwarded caller label. |
| `DW_AUTH_STATUS_HEADER` | `X-Workflow-Auth-Status` | Request header carrying the forwarded auth status. |
| `DW_AUTH_METHOD_HEADER` | `X-Workflow-Auth-Method` | Request header carrying the forwarded auth method. |
| `DW_WORKER_POLL_TIMEOUT` | `30` | Seconds the server holds a poll open. |
| `DW_WORKER_POLL_INTERVAL_MS` | `1000` | Internal scan interval during an open poll. |
| `DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS` | `100` | Wake-signal check interval during an open poll. |
| `DW_POLLING_CACHE_PATH` | `storage/.../server-polling/<APP_ENV>` | Writable local coordination and wait-lock directory, also required with Redis or database polling caches. |
| `DW_WAKE_SIGNAL_TTL_SECONDS` | `max(DW_WORKER_POLL_TIMEOUT + 5, 60)` | TTL for per-queue wake signals. |
| `DW_WORKER_LONG_POLL_MAX_CONCURRENT` | (unset; derived for PHP CLI server) | Optional cap for concurrent held workflow/activity worker long-poll waits on this server node. Query-task polls use a separate wait budget so live workflow queries are not starved by idle workflow/activity waits. Native honors it as a node-wide cap with the same `429 long_poll_capacity_exhausted` refusal; unset, native waits are unbounded (see [Native long-poll capacity](#native-long-poll-capacity)). |
| `DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS` | `2` | PHP CLI server workers reserved for health and control-plane requests when deriving the workflow/activity long-poll wait cap. Native has no request-worker pool; it reads this as the number of its request-pool database connections that long-poll probes leave free. Unset, native reserves none for it; the held-poll work listed under native long-poll capacity still leaves the request pool's last connection free. |
| `DW_MAX_TASKS_PER_POLL` | `1` | Maximum tasks returned per poll. |
| `DW_SQLITE_CLAIM_LOCK_TTL_SECONDS` | `10` | Seconds the SQLite quickstart backend holds the cache-backed worker poll claim gate before the lock expires. |
| `DW_SQLITE_CLAIM_LOCK_WAIT_SECONDS` | `5` | Seconds SQLite worker poll claims wait for the cache-backed claim gate before returning backend lock pressure. |
| `DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE` | (unset) | Optional server-side cap for active workflow-task leases per namespace/task queue. |
| `DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE` | (unset) | Optional server-side cap for active workflow-task leases across all task queues in a namespace. |
| `DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE` | (unset) | Optional server-side cap for workflow-task dispatches per minute per namespace/task queue. |
| `DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE` | (unset) | Optional server-side cap for workflow-task dispatches per minute across all task queues in a namespace. |
| `DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_QUEUE` | (unset) | Optional server-side cap for active activity-task leases per namespace/task queue. |
| `DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE` | (unset) | Optional server-side cap for active activity-task leases across all task queues in a namespace. |
| `DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE` | (unset) | Optional server-side cap for activity-task dispatches per minute per namespace/task queue. |
| `DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE` | (unset) | Optional server-side cap for activity-task dispatches per minute across all task queues in a namespace. |
| `DW_TASK_QUEUE_ADMISSION_OVERRIDES` | `{}` | JSON overrides keyed by `namespace:task_queue`, `namespace:*`, `task_queue`, or `*` for workflow/activity active lease, dispatch-per-minute, namespace, and downstream budget-group caps. |
| `DW_DUE_TIMER_RECOVERY_SCAN_LIMIT` | `5` | Max due service-mode timer tasks recovered per worker poll pass. |
| `DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT` | `5` | Max expired workflow tasks recovered per pass. |
| `DW_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS` | `5` | Min seconds between expired-task recovery passes. |
| `DW_WORKER_PROTOCOL_VERSION` | `WorkerProtocol::VERSION` | Override for the advertised worker protocol version. |
| `DW_HISTORY_PAGE_SIZE_DEFAULT` | `DEFAULT_HISTORY_PAGE_SIZE` | Default page size for worker history reads. |
| `DW_HISTORY_PAGE_SIZE_MAX` | `MAX_HISTORY_PAGE_SIZE` | Maximum page size honored for worker history reads. |
| `DW_QUERY_TASK_TIMEOUT` | `min(max(DW_WORKER_POLL_TIMEOUT + 15, 40), 55)` | Seconds the control plane waits for a worker query response. The default covers one worker long-poll cycle plus dispatch grace, while the 55-second ceiling preserves time to return a structured failure before the standard client transport deadline. |
| `DW_QUERY_TASK_LEASE_TIMEOUT` | `DW_WORKFLOW_TASK_TIMEOUT` | Configured lease timeout for ephemeral query tasks, with a minimum of 1 second. Each claim expires at the earlier of claim time plus this timeout and the original query deadline. A short lease can expire before the caller deadline. |
| `DW_QUERY_TASK_TTL_SECONDS` | `180` | Retention floor for query-task result rows. Effective retention is `max(60, DW_QUERY_TASK_TTL_SECONDS, DW_QUERY_TASK_TIMEOUT + max(1, DW_QUERY_TASK_LEASE_TIMEOUT) + 60)` seconds. Retention does not extend query execution authority. |
| `DW_QUERY_TASK_MAX_PENDING_PER_QUEUE` | `1024` | Max pending cache-backed query tasks per namespace/task queue before new queries are rejected. |
| `DW_QUERY_TASK_POLL_MAX_CONCURRENT` | (unset; derived for PHP CLI server) | Optional cap for concurrent held idle query-task worker long-poll waits. Pending query tasks are still claimed immediately before an idle poll waits; the default standalone image derives one query-task wait slot. Native honors it, also for update-validation polls as PHP does; unset, native waits are unbounded. |
| `DW_WORKFLOW_TASK_TIMEOUT` | `60` | Default workflow-task lease timeout (seconds). |
| `DW_ACTIVITY_TASK_TIMEOUT` | `300` | Default activity-task lease timeout (seconds). |
| `DW_WORKER_STALE_AFTER_SECONDS` | `max(DW_WORKER_HEARTBEAT_INTERVAL_SECONDS * 3, 30)` | Seconds before a worker heartbeat is considered stale. |
| `DW_WORKER_REGISTRATION_RETENTION_SECONDS` | `86400` | Native server only: seconds without a heartbeat after which a worker registration is deleted. Never less than `DW_WORKER_STALE_AFTER_SECONDS`. |
| `DW_WORKER_HEARTBEAT_INTERVAL_SECONDS` | `10` | Cadence in seconds advertised to SDK workers for fleet heartbeats. |
| `DW_MAX_HISTORY_EVENTS` | `50000` | Has no effect. No Server release reads it and history per run is not capped. Bound long histories with continue-as-new. |
| `DW_HISTORY_RETENTION_DAYS` | `30` | Default retention for closed-run history (namespaces can override). |
| `DW_MAX_PAYLOAD_BYTES` | `2097152` | Max serialized bytes for a single payload. |
| `DW_EXTERNAL_PAYLOAD_MAX_BYTES` | `67108864` | Max encoded bytes accepted by the authenticated runtime external-payload transport. |
| `DW_EXTERNAL_PAYLOAD_REQUEST_TIMEOUT` | `30` | Advertised upload/fetch request-timeout budget in seconds. |
| `DW_EXTERNAL_PAYLOAD_UPLOAD_EXPIRY` | `3600` | Seconds an unclaimed uploaded reference remains valid. |
| `DW_EXTERNAL_PAYLOAD_S3_ACCESS_KEY_ID` | (unset) | Optional access-key ID for the S3-compatible external-payload disk; omit with its secret when workload identity supplies credentials. |
| `DW_EXTERNAL_PAYLOAD_S3_SECRET_ACCESS_KEY` | (unset) | Optional secret access key for the S3-compatible external-payload disk; set it together with the access-key ID. |
| `DW_EXTERNAL_PAYLOAD_S3_SESSION_TOKEN` | (unset) | Optional session token used with temporary S3-compatible credentials. |
| `DW_EXTERNAL_PAYLOAD_S3_REGION` | `us-east-1` | Region used by the S3-compatible external-payload disk. |
| `DW_EXTERNAL_PAYLOAD_S3_BUCKET` | (unset) | Bucket used by the S3-compatible external-payload disk. |
| `DW_EXTERNAL_PAYLOAD_S3_ENDPOINT` | (unset) | Optional HTTPS endpoint for an S3-compatible external-payload service. |
| `DW_EXTERNAL_PAYLOAD_S3_USE_PATH_STYLE_ENDPOINT` | `false` | Use path-style bucket addressing for the S3-compatible external-payload service. |
| `DW_MAX_MEMO_BYTES` | `262144` | Max serialized bytes for a workflow memo. |
| `DW_MAX_SEARCH_ATTRIBUTES` | `100` | Max search attributes per workflow. |
| `DW_MAX_PENDING_ACTIVITIES` | `2000` | Max pending activities per run. |
| `DW_MAX_PENDING_CHILDREN` | `2000` | Max pending child workflows per run. |
| `DW_COMPRESSION_ENABLED` | `true` | Enable gzip/deflate on JSON responses over the size threshold. |
| `DW_EXPOSE_PACKAGE_PROVENANCE` | `false` | Include `package_provenance` in `/api/cluster/info` (admin-only). |
| `DW_PACKAGE_PROVENANCE_PATH` | `<base_path>/.package-provenance` | Path to the package provenance file written at Docker build time. |
| `DW_ENV_AUDIT_STRICT` | `0` | When `1`, the entrypoint fails container boot on unknown/legacy DW vars. |
| `DW_BOOTSTRAP_RETRIES` | `30` | Bootstrap attempts before the entrypoint gives up. |
| `DW_BOOTSTRAP_DELAY_SECONDS` | `2` | Seconds between bootstrap attempts. |

The bundled `durable-workflow/workflow` package reads the same
`DW_V2_*` prefix for operator controls; every entry below is resolved
inside the package's `config/workflows.php` via
`Workflow\Support\Env::dw` and falls back to its legacy
`WORKFLOW_V2_*` counterpart the same way the server's own vars do.

| `DW_*` name | Default | Description |
| --- | --- | --- |
| `DW_V2_NAMESPACE` | (unset) | Scope workflow instances to a namespace. Unset means the default, visible-to-every-consumer namespace. |
| `DW_V2_CURRENT_COMPATIBILITY` | (unset) | Worker-compatibility marker this worker advertises (e.g. `build-2026-04-17`). |
| `DW_V2_SUPPORTED_COMPATIBILITIES` | (unset) | Comma-separated marker list the worker accepts, or `*` for any. |
| `DW_V2_COMPATIBILITY_NAMESPACE` | (unset) | Compatibility namespace for independent fleets sharing one database. |
| `DW_V2_COMPATIBILITY_HEARTBEAT_TTL` | `30` | Seconds a worker-compatibility heartbeat remains valid. |
| `DW_V2_PIN_TO_RECORDED_FINGERPRINT` | `true` | Prefer an eligible recorded-definition worker; otherwise require strict replay by current code. False removes this preference, not build pins or replay checks. |
| `DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD` | `10000` | History event count at which the package signals continue-as-new. |
| `DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD` | `5242880` | Serialized-history byte count at which the package signals continue-as-new. |
| `DW_V2_HISTORY_EVENT_WARNING_THRESHOLD` | `8000` | Event count at which history pressure becomes `approaching`. |
| `DW_V2_HISTORY_SIZE_BYTES_WARNING_THRESHOLD` | `4194304` | Serialized-history byte count at which history pressure becomes `approaching`. |
| `DW_V2_CONTINUE_AS_NEW_FAN_OUT_THRESHOLD` | `200` | Largest recorded parallel group size at which continue-as-new is recommended. |
| `DW_V2_HISTORY_FAN_OUT_WARNING_THRESHOLD` | `160` | Largest recorded parallel group size at which history pressure becomes `approaching`. |
| `DW_V2_HISTORY_EXPORT_SIGNING_KEY` | (unset) | Optional HMAC key authenticating history export archives. |
| `DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID` | (unset) | Optional key identifier recorded alongside signed exports. |
| `DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS` | `10` | Seconds the server waits for an update to reach a terminal stage when the request omits `wait_timeout_seconds`. Values above 60 are treated as 60; a request may ask for 1 to 60 seconds. |
| `DW_V2_UPDATE_WAIT_POLL_INTERVAL_MS` | `50` | Milliseconds between update-stage polls. |
| `DW_V2_GUARDRAILS_BOOT` | `warn` | Boot-time structural guardrail mode: `warn`, `fail`, or `silent`. |
| `DW_V2_LIMIT_PENDING_ACTIVITIES` | `2000` | Package-level pending-activity ceiling per run. |
| `DW_V2_LIMIT_PENDING_CHILDREN` | `1000` | Package-level pending-child ceiling per run. |
| `DW_V2_LIMIT_PENDING_TIMERS` | `2000` | Package-level pending-timer ceiling per run. |
| `DW_V2_LIMIT_PENDING_SIGNALS` | `5000` | Package-level pending-signal ceiling per run. |
| `DW_V2_LIMIT_PENDING_UPDATES` | `500` | Package-level pending-update ceiling per run. |
| `DW_V2_LIMIT_COMMAND_BATCH_SIZE` | `1000` | Maximum commands accepted per workflow-task completion. |
| `DW_V2_LIMIT_PAYLOAD_SIZE_BYTES` | `2097152` | Package-level single-payload byte ceiling. |
| `DW_V2_LIMIT_MEMO_SIZE_BYTES` | `262144` | Package-level memo byte ceiling. |
| `DW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES` | `40960` | Package-level search-attribute byte ceiling. |
| `DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE` | `5000` | Package-level history-transaction event ceiling. |
| `DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES` | `16777216` (native: four workflow-task completion caps, `134217728` by default) | Package-level history-transaction byte ceiling, also applied to Waterline v1 projection imports. Zero disables this dimension. Unset, native uses four times `limits.max_workflow_task_completion_bytes` (never below 16 MiB), so a completion the wire cap admits, whose scheduled activities repeat their arguments in history, can be recorded. A completion over any history-transaction limit is refused with `409` and names `limit_kind`, `current_value` and `configured_limit`. |
| `DW_V2_LIMIT_WARNING_THRESHOLD_PERCENT` | `80` | Percent of a structural limit at which the package warns. |
| `DW_V2_TASK_DISPATCH_MODE` | `queue` | Package-level workflow-task dispatch mode. Usually overridden by the server via `DW_TASK_DISPATCH_MODE`. |
| `DW_V2_MATCHING_ROLE_QUEUE_WAKE` | `true` | Whether queue workers run the in-worker matching-role wake on every Looping event. In the supplied Compose stacks, `false` also skips the scheduler's unscoped repair pass. Set it to `false` only when a dedicated `php artisan workflow:v2:repair-pass --loop` daemon owns the sweep. |
| `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS` | `3` | Seconds before an orphaned workflow task is redispatched. |
| `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS` | `5` | Minimum seconds between successive task-repair passes. |
| `DW_V2_TASK_REPAIR_SCAN_LIMIT` | `25` | Maximum tasks considered per task-repair pass. |
| `DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS` | `60` | Ceiling on task-repair failure backoff in seconds. |
| `DW_V2_MULTI_NODE` | `false` | Declare multi-node deployment so cache backends are validated for cross-node coordination. |
| `DW_V2_VALIDATE_CACHE_BACKEND` | `true` | Validate the long-poll cache backend at boot. |
| `DW_V2_CACHE_VALIDATION_MODE` | `warn` | Cache-backend validation failure handling: `fail`, `warn`, or `silent`. |
| `DW_V2_FLEET_VALIDATION_MODE` | `warn` | Fleet-compatibility validation handling: `warn` logs, `fail` blocks dispatch and fails closed when no compatible worker is available. |
| `DW_SERIALIZER` | `avro` | Payload codec diagnostic input. Legacy values are surfaced by `workflow:v2:doctor`; new-run v2 payloads always resolve to Avro. |

Legacy `WORKFLOW_*` / `WORKFLOW_V2_*` / `ACTIVITY_*` names remain
honored as fallbacks during the deprecation window so existing
deployments keep working — `env:audit` logs a rename hint at boot for
each one it sees.

### HTTP concurrency (Apache)

The image's default command runs Apache with mod_php, two prefork request
processes ready at startup, up to four idle spare processes, and a maximum of
24 concurrent request processes. Keeping the eager and idle pools small leaves
memory for Laravel requests while retaining burst capacity.
HTTP keep-alive is disabled in this prefork configuration. Each completed
response releases its connection instead of reserving a PHP process between
requests. Clients reconnect for their next request; an active long poll still
holds its connection until its normal response or deadline.
A workflow query may hold one process while it waits for a worker response;
worker heartbeats, query-task polls, completions, and control-plane requests
continue to enter Laravel through other processes.

The published image explicitly caps held workflow/activity long polls at two
and held query-task long polls at one. This leaves request capacity for query
result waits, completions, recurring liveness probes, workflow starts, listing,
readiness, and compatibility discovery. Larger deployments can build a tuned
Apache prefork configuration and set `DW_WORKER_LONG_POLL_MAX_CONCURRENT` and
`DW_QUERY_TASK_POLL_MAX_CONCURRENT` to matching bounded values. An alternative
container command remains supported, but it must provide production-grade
concurrent request admission; the PHP built-in server is not a supported
production HTTP runtime.

## Writing Workers

Workers in any language connect to the server via HTTP. The protocol is simple:

1. **Register** the worker with supported types
2. **Poll** for tasks (long-poll, server holds connection)
3. **Execute** the task locally
4. **Complete** or **fail** the task back to the server
5. **Heartbeat** for long-running activities

### PHP (using the SDK)
```php
use DurableWorkflow\Client;
use DurableWorkflow\Worker;

$client = new Client('http://localhost:8080', token: 'WORKER_TOKEN');

$worker = new Worker($client, taskQueue: 'default');
$worker->registerWorkflow(MyWorkflow::class);
$worker->registerActivity(MyActivity::class);
$worker->run();
```

### Python
```python
from durable_workflow import Client, Worker, workflow, activity

client = Client("http://localhost:8080", token="WORKER_TOKEN", namespace="default")

worker = Worker(
    client,
    task_queue="default",
    workflows=[MyWorkflow],
    activities=[my_activity],
)
await worker.run()
```

### Rust

The Rust SDK is developed and released independently in the
[durable-workflow/sdk-rust](https://github.com/durable-workflow/sdk-rust)
repository. Installation and API guidance are available from the
[Rust SDK documentation](https://rust.durable-workflow.com/).

MIT
