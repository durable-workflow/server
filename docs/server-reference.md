# Durable Workflow Server Reference

Detailed self-hosting, HTTP API, authentication, deployment, and configuration
reference for [Durable Workflow Server](../README.md).

## Quick Start

### Official Image + SQLite

Use this path when you want to validate the published image without cloning the
repository or starting MySQL/Redis. The image defaults to SQLite, database
queues, and file cache; mount `/app/database` so the bootstrap command and API
server share the same SQLite file.

The `2` image tag tracks the current stable Server release. Pin an immutable
version tag or digest for production change control.

```bash
export DW_SERVER_IMAGE="durableworkflow/server:2"
export DW_AUTH_TOKEN=dev-token
docker volume create durable-workflow-sqlite

# Bootstrap schema + default namespace once.
docker run --rm \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  "$DW_SERVER_IMAGE" server-bootstrap

# Start the API server.
docker run --rm --name durable-workflow-server \
  -p 8080:8080 \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  "$DW_SERVER_IMAGE"

# In a separate terminal, run the database queue worker that fires timers.
docker run --rm --name durable-workflow-queue-worker \
  -v durable-workflow-sqlite:/app/database \
  -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN="$DW_AUTH_TOKEN" \
  "$DW_SERVER_IMAGE" php artisan queue:work database --sleep=1 --tries=3
```

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

### Official Image + Compose

Use this path when you want a source-free multi-container stack backed by MySQL
and Redis. The same Compose file supports local development and single-node
production; the difference is the environment you provide and the operational
care around persistence, backups, and upgrades.

Image selection:

- The checked-in Compose file defaults to the exact Server image from its
  stable release metadata.
- `DW_SERVER_TAG=<server-version>` selects an exact Docker Hub image.
- `DW_SERVER_IMAGE=ghcr.io/durable-workflow/server:<server-version>`
  selects the same release line from GitHub Container Registry.
- `DW_SERVER_IMAGE=durableworkflow/server@sha256:...` pins an exact image
  digest for production change control.

#### Local Development Compose

This recipe is for one developer machine or internal non-production testing. It
uses the default MySQL/Redis volumes, exposes only the API port, and allows the
single `DW_AUTH_TOKEN` compatibility token for quick verification.

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
MySQL, Redis, and server storage in named volumes, exposes only the API port,
and expects role-scoped credentials plus an exact image tag or digest.

Create a production env file outside source control:

```env
DW_SERVER_IMAGE=durableworkflow/server:<server-version>
SERVER_PORT=8080
APP_ENV=production
APP_DEBUG=false

DB_DATABASE=durable_workflow
DB_USERNAME=workflow
DB_PASSWORD=replace-with-random-password
DB_ROOT_PASSWORD=replace-with-random-root-password

DW_AUTH_DRIVER=token
DW_AUTH_BACKWARD_COMPATIBLE=false
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
reverse proxy in front of the API container; do not expose the MySQL or Redis
services.

Persistence and backups:

- `mysql_data` is the durable workflow state. Back it up before every image
  upgrade and on a regular schedule.
- `redis_data` contains queue/cache state. Preserve it for graceful restarts;
  MySQL remains the source of truth for workflow history.
- Keep a copy of the exact env file and image reference with each backup so a
  restore uses the same auth, database, and image contract.

Backup and restore examples:

```bash
docker compose --env-file durable-workflow.prod.env \
  -f docker-compose.published.yml exec -T mysql \
  sh -lc 'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  > durable-workflow-$(date +%Y%m%d%H%M%S).sql

docker compose --env-file durable-workflow.prod.env \
  -f docker-compose.published.yml exec -T mysql \
  sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
  < durable-workflow-backup.sql
```

Upgrade order:

1. Back up MySQL and record the current image reference.
2. Change only `DW_SERVER_IMAGE` or `DW_SERVER_TAG` in the env file.
3. Run `docker compose --env-file durable-workflow.prod.env -f docker-compose.published.yml pull`.
4. Run `docker compose --env-file durable-workflow.prod.env -f docker-compose.published.yml up -d --wait`.
5. Confirm `/api/ready`, `/api/cluster/info`, and worker registration before
   shifting external traffic.

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

## API Overview

### System
- `GET /api/health` — Health check plus a machine-readable topology summary for the current node
- `GET /api/ready` — Bounded readiness check for migrations, configured queue storage, default namespace, cache, auth config, fail-closed backend/fleet admission, and the current node topology summary
- `GET /api/cluster/info` — Bounded server compatibility and capability discovery used by SDK and CLI preflight
- `GET /api/cluster/info?include=diagnostics` — Compatibility discovery plus explicit coordination health, fleet detail, task-repair diagnostics, and operator metrics
- `GET /api/system/health` — Full rollout-safety health snapshot for the requested namespace, including check status, categories, routing-drain state, operator metrics, and structural limits
- `GET /api/system/metrics` — Server metrics including bounded stuck workflow-task diagnostics
- `GET /api/system/operator-metrics` — Full operator metrics snapshot (runs, tasks, backlog, repair, workers/fleet, backend, structural limits) for namespace-scoped rollout-safety coordination health
- `GET /api/system/repair` — Task repair diagnostics
- `POST /api/system/repair/pass` — Run task repair sweep
- `GET /api/system/activity-timeouts` — Expired activity execution diagnostics
- `POST /api/system/activity-timeouts/pass` — Enforce activity timeouts
- `GET /api/system/external-payload-cleanup` — External-payload cleanup backlog, outcomes, and storage-failure diagnostics
- `POST /api/system/external-payload-cleanup/pass` — Retry one bounded namespace cleanup pass

`POST /api/system/repair/pass` accepts optional `connection`, `queue`,
`run_ids`, and `instance_id` filters. Set `respect_throttle=true` when a
dedicated matching-role loop should skip a pass rather than duplicate work
already covered by another matching-role process holding the repair throttle.

### Namespaces
- `GET /api/namespaces` — List namespaces
- `POST /api/namespaces` — Create namespace
- `GET /api/namespaces/{name}` — Get namespace
- `PUT /api/namespaces/{name}` — Update namespace
- `DELETE /api/namespaces/{name}` — Delete namespace and clean up its runtime state
- `PUT /api/namespaces/{name}/external-storage` — Configure external payload storage policy

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

### History
- `GET /api/workflows/{id}/runs/{runId}/history` — Get event history
- `GET /api/workflows/{id}/runs/{runId}/history/export` — Export replay bundle

### External Payload Storage
- `POST /api/storage/test` — Round-trip diagnostic for the selected namespace storage policy

The storage diagnostic writes, reads, verifies, and deletes small and large test
payloads through the namespace's configured policy. It supports `local` and
configured-disk `s3`, `gcs`, and `azure` policies; it returns
`storage_driver_unavailable` when the namespace only stores provider metadata
and the current server runtime has no filesystem disk configured for that
provider.

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

History export responses are the exception inside the workflow route group:
`GET /api/workflows/{id}/runs/{runId}/history/export` returns the replay bundle
as-is so its integrity checksum and optional signature cover the exact artifact
the client receives.

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
- `DELETE /api/worker/registrations/{workerId}` — Orderly worker self-deregistration
- `POST /api/worker/heartbeat` — Worker fleet heartbeat (free task slots, basic process metrics)
- `POST /api/worker/workflow-tasks/poll` — Long-poll for workflow tasks
- `POST /api/worker/workflow-tasks/{id}/heartbeat` — Workflow task heartbeat
- `POST /api/worker/workflow-tasks/{id}/complete` — Complete workflow task
- `POST /api/worker/workflow-tasks/{id}/fail` — Fail workflow task
- `POST /api/worker/activity-tasks/poll` — Long-poll for activity tasks
- `POST /api/worker/activity-tasks/{id}/complete` — Complete activity task
- `POST /api/worker/activity-tasks/{id}/fail` — Fail activity task
- `POST /api/worker/activity-tasks/{id}/heartbeat` — Activity heartbeat

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

The current server advertises worker protocol `1.17` by default. Worker-plane
requests should send the highest `X-Durable-Workflow-Protocol-Version` their SDK
implements, and worker-plane responses always echo the server's advertised
version in the same header plus the `protocol_version` body field.
Worker-protocol compatibility is same-major with a worker minor less than or
equal to the server minor: a server advertising `1.17` accepts worker requests
for `1.0` through `1.17`, including older `1.16` and `1.15` workers. Requests
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
retry policy is transport-only: it may repeat transient HTTP delivery before a
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
the poll response includes a `deadlines` object with ISO-8601 timestamps for
`schedule_to_start`, `start_to_close`, `schedule_to_close`, and/or `heartbeat`.
Workers should use these deadlines to self-cancel before the server enforces the
timeout. The server runs `activity:timeout-enforce` periodically to expire
activities that exceed their deadlines. Heartbeats accept `message`, `current`,
`total`, `unit`, and `details` fields; the server normalizes them to the package
heartbeat-progress contract before recording the heartbeat.
When a run-level cancel or terminate command stops a leased activity task,
heartbeat, complete, and fail responses include `run_closed_reason` and
`run_closed_at` alongside `cancel_requested: true`.

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

Set `DW_AUTH_DRIVER=none` to disable authentication (development only).

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

```bash
docker build -t durable-workflow-server .
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
SQLite uses WAL journal mode and a 5000 ms busy timeout by default. The server
also serializes SQLite worker poll claim probes through the polling cache so
concurrent PHP/Python workers in the single-container quickstart do not race the
same file-backed writer lock. If worker poll endpoints still return
`reason: backend_lock_pressure`, workers should retry with backoff; sustained
multi-worker deployments should use MySQL/PostgreSQL with Redis.

The standalone server image also reserves Apache request-worker capacity for
health and control-plane routes. Empty workflow and activity worker long-polls
acquire a short-lived wait slot before sleeping; once the node-local slot cap
is reached, additional idle polls receive `Retry-After: 1` instead of entering
a tight empty-poll loop or holding another PHP server worker for the full poll
timeout. Published Python, Rust, PHP, and other supported workers receive a
protocol-compatible HTTP 200 empty response with the
`long_poll_capacity_exhausted` reason and retry header. The server holds that
compatibility response for the advertised one-second cooldown because existing
worker poll APIs do not all expose retry headers to their loops. This keeps
workers alive and bounds immediate empty repolling without admitting another
full long-poll wait. Idle query-task
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

By default every queue worker also runs the in-worker matching-role wake on
every Looping event, which keeps the broad-poll repair sweep close to the
workers that consume tasks. This is the in-worker shape of the matching role
described in `vendor/durable-workflow/workflow/docs/architecture/task-matching.md`
and is the right default for small deployments.

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
override on top of `docker-compose.published.yml` to enable the same shape:

```bash
docker compose \
  -f docker-compose.published.yml \
  -f docker-compose.dedicated-matching.yml \
  up
```

The override sets `DW_V2_MATCHING_ROLE_QUEUE_WAKE=false` on the `server`,
`worker`, `scheduler`, and `matching` services so every long-running process
reports the dedicated repair pass as the broad-poll wake owner. It adds a
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

All operator-facing configuration is via `DW_*` environment variables.
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

#### Environment variable reference

The full table below is generated from `config/dw-contract.php` and lists
every operator-facing variable the server honors.

| `DW_*` name | Default | Description |
| --- | --- | --- |
| `DW_MODE` | `service` | Server mode: "service" (external workers poll) or "embedded" (local queue). |
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
| `DW_PRINCIPAL_TOKENS` | (unset) | JSON token map for named bearer-token principals used by audit attribution. |
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
| `DW_POLLING_CACHE_PATH` | `storage/.../server-polling/<APP_ENV>` | Directory for worker-poll coordination state. |
| `DW_WAKE_SIGNAL_TTL_SECONDS` | `max(DW_WORKER_POLL_TIMEOUT + 5, 60)` | TTL for per-queue wake signals. |
| `DW_WORKER_LONG_POLL_MAX_CONCURRENT` | (unset; derived for PHP CLI server) | Optional cap for concurrent held workflow/activity worker long-poll waits on this server node. Query-task polls use a separate wait budget so live workflow queries are not starved by idle workflow/activity waits. |
| `DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS` | `2` | PHP CLI server workers reserved for health and control-plane requests when deriving the workflow/activity long-poll wait cap. |
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
| `DW_QUERY_TASK_LEASE_TIMEOUT` | `DW_WORKFLOW_TASK_TIMEOUT` | Configured lease timeout for ephemeral query tasks; when `DW_QUERY_TASK_TIMEOUT` is nonzero, effective leases are at least `DW_QUERY_TASK_TIMEOUT + 5` seconds. |
| `DW_QUERY_TASK_TTL_SECONDS` | `180` | Configured retention floor for query-task result rows; effective retention is at least query timeout + effective lease + 60 seconds. |
| `DW_QUERY_TASK_MAX_PENDING_PER_QUEUE` | `1024` | Max pending cache-backed query tasks per namespace/task queue before new queries are rejected. |
| `DW_QUERY_TASK_POLL_MAX_CONCURRENT` | (unset; derived for PHP CLI server) | Optional cap for concurrent held idle query-task worker long-poll waits. Pending query tasks are still claimed immediately before an idle poll waits; the default standalone image derives one query-task wait slot. |
| `DW_WORKFLOW_TASK_TIMEOUT` | `60` | Default workflow-task lease timeout (seconds). |
| `DW_ACTIVITY_TASK_TIMEOUT` | `300` | Default activity-task lease timeout (seconds). |
| `DW_WORKER_STALE_AFTER_SECONDS` | `max(DW_WORKER_HEARTBEAT_INTERVAL_SECONDS * 3, 30)` | Seconds before a worker heartbeat is considered stale. |
| `DW_WORKER_HEARTBEAT_INTERVAL_SECONDS` | `10` | Cadence in seconds advertised to SDK workers for fleet heartbeats. |
| `DW_MAX_HISTORY_EVENTS` | `50000` | Max history events per run before continue-as-new is enforced. |
| `DW_HISTORY_RETENTION_DAYS` | `30` | Default retention for closed-run history (namespaces can override). |
| `DW_MAX_PAYLOAD_BYTES` | `2097152` | Max serialized bytes for a single payload. |
| `DW_EXTERNAL_PAYLOAD_MAX_BYTES` | `67108864` | Max encoded bytes accepted by the authenticated runtime external-payload transport. |
| `DW_EXTERNAL_PAYLOAD_REQUEST_TIMEOUT` | `30` | Advertised upload/fetch request-timeout budget in seconds. |
| `DW_EXTERNAL_PAYLOAD_UPLOAD_EXPIRY` | `3600` | Seconds an unclaimed uploaded reference remains valid. |
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
| `DW_V2_PIN_TO_RECORDED_FINGERPRINT` | `true` | Resolve in-flight runs from the fingerprint recorded at WorkflowStarted. |
| `DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD` | `10000` | History event count at which the package signals continue-as-new. |
| `DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD` | `5242880` | Serialized-history byte count at which the package signals continue-as-new. |
| `DW_V2_HISTORY_EXPORT_SIGNING_KEY` | (unset) | Optional HMAC key authenticating history export archives. |
| `DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID` | (unset) | Optional key identifier recorded alongside signed exports. |
| `DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS` | `10` | Seconds the server waits for an update to reach a terminal stage. |
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
| `DW_V2_LIMIT_WARNING_THRESHOLD_PERCENT` | `80` | Percent of a structural limit at which the package warns. |
| `DW_V2_TASK_DISPATCH_MODE` | `queue` | Package-level workflow-task dispatch mode. Usually overridden by the server via `DW_TASK_DISPATCH_MODE`. |
| `DW_V2_MATCHING_ROLE_QUEUE_WAKE` | `true` | Whether queue workers run the in-worker matching-role wake on every Looping event. Set to `false` to opt execution-only nodes out of the broad-poll wake when a dedicated `php artisan workflow:v2:repair-pass --loop` daemon owns the sweep. |
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
