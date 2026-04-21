# Durable Workflow Server

A standalone, language-neutral workflow orchestration server. Write durable workflows in any language. Built on the same engine as [Durable Workflow](https://github.com/durable-workflow/workflow).

## Quick Start

### Official Image + SQLite

Use this path when you want to validate the published image without cloning the
repository or starting MySQL/Redis. The image defaults to SQLite, database
queues, and file cache; mount `/app/database` so the bootstrap command and API
server share the same SQLite file.

```bash
export DW_SERVER_IMAGE=durableworkflow/server:0.2
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
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -d '{"worker_id":"quickstart-worker","task_queue":"quickstart","runtime":"python"}'
```

Use Redis or another shared cache backend for multi-node deployments. The file
cache default is intentionally scoped to the one-container SQLite quickstart.

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
The image build fetches the `durable-workflow/workflow` `v2` package source by
default so `docker compose up --build` works from a clean checkout. Override
`WORKFLOW_PACKAGE_SOURCE` or `WORKFLOW_PACKAGE_REF` if you need a different
package remote or ref during image builds.

### Using the CLI

```bash
# Install the CLI
composer global require durable-workflow/cli

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
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -d '{
    "worker_id": "worker-1",
    "task_queue": "order-workers",
    "runtime": "python"
  }'
```

### 5. Poll for Workflow Tasks

The server holds the connection open (long-poll) until a task is ready or
the timeout expires:

```bash
curl -X POST $SERVER/api/worker/workflow-tasks/poll \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -d '{
    "worker_id": "worker-1",
    "task_queue": "order-workers"
  }'
```

The response includes the task, its history events, and lease metadata:

```json
{
  "protocol_version": "1.0",
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
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
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
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
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
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -d '{"worker_id": "worker-1", "task_queue": "order-workers"}'

# Complete (use task_id and activity_attempt_id from the poll response)
curl -X POST $SERVER/api/worker/activity-tasks/TASK_ID/complete \
  -H "Authorization: Bearer $WORKER_TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
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

## API Overview

### System
- `GET /api/health` — Health check
- `GET /api/ready` — Readiness check for migrations, default namespace, cache, and auth config
- `GET /api/cluster/info` — Server capabilities and version
- `GET /api/system/metrics` — Server metrics including bounded stuck workflow-task diagnostics
- `GET /api/system/repair` — Task repair diagnostics
- `POST /api/system/repair/pass` — Run task repair sweep
- `GET /api/system/activity-timeouts` — Expired activity execution diagnostics
- `POST /api/system/activity-timeouts/pass` — Enforce activity timeouts

### Namespaces
- `GET /api/namespaces` — List namespaces
- `POST /api/namespaces` — Create namespace
- `GET /api/namespaces/{name}` — Get namespace
- `PUT /api/namespaces/{name}` — Update namespace

### Workflows
- `GET /api/workflows` — List workflows (with filters)
- `POST /api/workflows` — Start a workflow
- `GET /api/workflows/{id}` — Describe a workflow
- `GET /api/workflows/{id}/runs` — List runs (continue-as-new chain)
- `GET /api/workflows/{id}/runs/{runId}` — Describe a specific run
- `POST /api/workflows/{id}/signal/{name}` — Send a signal
- `POST /api/workflows/{id}/query/{name}` — Execute a query
- `POST /api/workflows/{id}/update/{name}` — Execute an update
- `POST /api/workflows/{id}/cancel` — Request cancellation
- `POST /api/workflows/{id}/terminate` — Terminate immediately

### History
- `GET /api/workflows/{id}/runs/{runId}/history` — Get event history
- `GET /api/workflows/{id}/runs/{runId}/history/export` — Export replay bundle

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

### Worker Protocol
- `POST /api/worker/register` — Register a worker
- `POST /api/worker/heartbeat` — Worker heartbeat
- `POST /api/worker/workflow-tasks/poll` — Long-poll for workflow tasks
- `POST /api/worker/workflow-tasks/{id}/heartbeat` — Workflow task heartbeat
- `POST /api/worker/workflow-tasks/{id}/complete` — Complete workflow task
- `POST /api/worker/workflow-tasks/{id}/fail` — Fail workflow task
- `POST /api/worker/activity-tasks/poll` — Long-poll for activity tasks
- `POST /api/worker/activity-tasks/{id}/complete` — Complete activity task
- `POST /api/worker/activity-tasks/{id}/fail` — Fail activity task
- `POST /api/worker/activity-tasks/{id}/heartbeat` — Activity heartbeat

Worker-plane requests must send `X-Durable-Workflow-Protocol-Version: 1.0`, and
worker-plane responses always echo the same header plus `protocol_version: "1.0"`.
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

Long-poll wake-ups use short-lived cache-backed signal keys plus periodic
reprobes. Multi-node deployments therefore need a shared cache backend for
prompt wake behavior; without one, correctness still comes from the periodic
database recheck, but wake latency will regress toward the forced recheck
interval.

Within worker protocol version `1.0`, `worker_protocol.version`,
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
use `complete_update` with `update_id` and an optional codec-tagged `result`
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
`condition_definition_fingerprint`. Fields that do not apply to the leased task
are `null`; pure timer resumes set
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
- `GET /api/schedules` — List schedules
- `POST /api/schedules` — Create schedule
- `GET /api/schedules/{id}` — Describe schedule
- `PUT /api/schedules/{id}` — Update schedule
- `DELETE /api/schedules/{id}` — Delete schedule
- `POST /api/schedules/{id}/pause` — Pause schedule
- `POST /api/schedules/{id}/resume` — Resume schedule
- `POST /api/schedules/{id}/trigger` — Trigger immediately
- `POST /api/schedules/{id}/backfill` — Backfill missed runs

### Task Queues
- `GET /api/task-queues` — List task queues
- `GET /api/task-queues/{name}` — Task queue details and pollers

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
worker-read, and namespace-read endpoints. `admin` tokens can call admin
operations such as `/api/system/*`, namespace creation/update, and worker
deletion, and can also use operator endpoints.

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

The route resource includes `allowed_roles`, HTTP method/path, route name, and
the resolved namespace when available. The authenticated principal is also
recorded in workflow command attribution so signal/update/query history can
show the subject, roles, tenant, and non-secret claims supplied by the provider.

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
```

The Dockerfile clones the `durable-workflow/workflow` `v2` branch into the
build and satisfies the app's Composer path repository from that source. Use
`--build-arg WORKFLOW_PACKAGE_SOURCE=...` and
`--build-arg WORKFLOW_PACKAGE_REF=...` to point the image build at another
remote or ref when needed.

The production image defaults to `DB_CONNECTION=sqlite`,
`DB_DATABASE=/app/database/database.sqlite`, `QUEUE_CONNECTION=database`, and
`CACHE_STORE=file` so the plain Docker quickstart works without external
services. The entrypoint creates the SQLite file when a fresh volume is mounted.
Override those framework variables for MySQL/PostgreSQL/Redis deployments.

Across Compose, plain Docker, and Kubernetes, the supported bootstrap contract
is the same: run the image's `server-bootstrap` command once before starting the
server and worker processes. `/api/health` is a liveness check; `/api/ready`
is the readiness check to gate workers and load balancers.

### Publishing Container Images

The `Release` workflow publishes multi-arch images to
Docker Hub (`durableworkflow/server`) and GitHub Container Registry
(`ghcr.io/durable-workflow/server`) when a server semver tag is pushed. The
workflow builds the server image with the latest `durable-workflow/workflow`
prerelease tag that matches `2.0.0-alpha.*` or `2.0.0-beta.*`, falling back to
the `v2` branch only when no prerelease tags exist.

When the server image needs a workflow package fix that has only landed on the
workflow `v2` branch, tag workflow first, then tag server:

```bash
# In the workflow repo, publish the package ref the server image must consume.
git tag 2.0.0-alpha.3 origin/v2
git push origin refs/tags/2.0.0-alpha.3

# In the server repo, publish the Docker image tags.
git tag 0.2.0 origin/main
git push origin refs/tags/0.2.0
```

The server tag push publishes the exact version plus the semver aliases
generated by the release workflow, including `latest`, to both registries. After
the workflow finishes, verify the image provenance and runtime config before
announcing the release:

```bash
docker pull durableworkflow/server:0.2.0
docker run --rm --entrypoint sh durableworkflow/server:0.2.0 -lc \
  'cat /app/.package-provenance && grep -n "serializer" /app/vendor/durable-workflow/workflow/src/config/workflows.php'

docker pull ghcr.io/durable-workflow/server:0.2.0
docker run --rm --entrypoint sh ghcr.io/durable-workflow/server:0.2.0 -lc \
  'cat /app/.package-provenance && grep -n "serializer" /app/vendor/durable-workflow/workflow/src/config/workflows.php'
```

### Kubernetes

The raw manifests intentionally stay Kubernetes-native instead of shipping a
Helm chart. Use Kustomize overlays or direct patches for environment-specific
names, images, registry secrets, and scaling policy; revisit Helm only when an
operator needs chart versioning and a chart/image compatibility matrix.

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

# Required when pulling private images; delete imagePullSecrets from overlays
# if every referenced image is public in your cluster.
kubectl create secret docker-registry durable-workflow-registry \
  --namespace durable-workflow \
  --docker-server=ghcr.io \
  --docker-username='<username>' \
  --docker-password='<token>'

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
| `DW_DEFAULT_NAMESPACE` | `default` | Namespace used when a request omits the namespace header. |
| `DW_TASK_DISPATCH_MODE` | (unset) | Override for `workflows.v2.task_dispatch_mode`. Set to `queue` to dispatch locally in service mode. |
| `DW_AUTH_PROVIDER` | (unset) | Optional FQCN implementing `App\Contracts\AuthProvider`; unset uses the built-in driver. |
| `DW_AUTH_DRIVER` | `token` | `none`, `token`, or `signature`. |
| `DW_AUTH_TOKEN` | (unset) | Single shared bearer token (backward-compat credential). |
| `DW_SIGNATURE_KEY` | (unset) | HMAC key used when `DW_AUTH_DRIVER=signature` and no role-scoped key is configured. |
| `DW_WORKER_TOKEN` | (unset) | Bearer token for the worker role. |
| `DW_OPERATOR_TOKEN` | (unset) | Bearer token for the operator role. |
| `DW_ADMIN_TOKEN` | (unset) | Bearer token for the admin role. |
| `DW_WORKER_SIGNATURE_KEY` | (unset) | Role-scoped HMAC key for workers. |
| `DW_OPERATOR_SIGNATURE_KEY` | (unset) | Role-scoped HMAC key for operators. |
| `DW_ADMIN_SIGNATURE_KEY` | (unset) | Role-scoped HMAC key for admins. |
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
| `DW_MAX_TASKS_PER_POLL` | `1` | Maximum tasks returned per poll. |
| `DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT` | `5` | Max expired workflow tasks recovered per pass. |
| `DW_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS` | `5` | Min seconds between expired-task recovery passes. |
| `DW_WORKER_PROTOCOL_VERSION` | `WorkerProtocolVersion::VERSION` | Override for the advertised worker protocol version. |
| `DW_HISTORY_PAGE_SIZE_DEFAULT` | `DEFAULT_HISTORY_PAGE_SIZE` | Default page size for worker history reads. |
| `DW_HISTORY_PAGE_SIZE_MAX` | `MAX_HISTORY_PAGE_SIZE` | Maximum page size honored for worker history reads. |
| `DW_QUERY_TASK_TIMEOUT` | `DW_WORKER_POLL_TIMEOUT` | Seconds the control plane waits for a worker query response. |
| `DW_QUERY_TASK_LEASE_TIMEOUT` | `DW_WORKFLOW_TASK_TIMEOUT` | Lease timeout for ephemeral query tasks. |
| `DW_QUERY_TASK_TTL_SECONDS` | `180` | Retention for query-task result rows. |
| `DW_QUERY_TASK_MAX_PENDING_PER_QUEUE` | `1024` | Max pending cache-backed query tasks per namespace/task queue before new queries are rejected. |
| `DW_WORKFLOW_TASK_TIMEOUT` | `60` | Default workflow-task lease timeout (seconds). |
| `DW_ACTIVITY_TASK_TIMEOUT` | `300` | Default activity-task lease timeout (seconds). |
| `DW_WORKER_STALE_AFTER_SECONDS` | `max(DW_WORKER_POLL_TIMEOUT * 2, 60)` | Seconds before a worker heartbeat is considered stale. |
| `DW_MAX_HISTORY_EVENTS` | `50000` | Max history events per run before continue-as-new is enforced. |
| `DW_HISTORY_RETENTION_DAYS` | `30` | Default retention for closed-run history (namespaces can override). |
| `DW_MAX_PAYLOAD_BYTES` | `2097152` | Max serialized bytes for a single payload. |
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
| `DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS` | `3` | Seconds before an orphaned workflow task is redispatched. |
| `DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS` | `5` | Minimum seconds between successive task-repair passes. |
| `DW_V2_TASK_REPAIR_SCAN_LIMIT` | `25` | Maximum tasks considered per task-repair pass. |
| `DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS` | `60` | Ceiling on task-repair failure backoff in seconds. |
| `DW_V2_MULTI_NODE` | `false` | Declare multi-node deployment so cache backends are validated for cross-node coordination. |
| `DW_V2_VALIDATE_CACHE_BACKEND` | `true` | Validate the long-poll cache backend at boot. |
| `DW_V2_CACHE_VALIDATION_MODE` | `warn` | Cache-backend validation failure handling: `fail`, `warn`, or `silent`. |
| `DW_SERIALIZER` | `avro` | Payload codec diagnostic input. Legacy values are surfaced by `workflow:v2:doctor`; new-run v2 payloads always resolve to Avro. |

Legacy `WORKFLOW_*` / `WORKFLOW_V2_*` / `ACTIVITY_*` names remain
honored as fallbacks during the deprecation window so existing
deployments keep working — `env:audit` logs a rename hint at boot for
each one it sees.

### HTTP concurrency (PHP_CLI_SERVER_WORKERS)

The image's default CMD runs `php artisan serve --no-reload` with
`PHP_CLI_SERVER_WORKERS=4`. The `--no-reload` flag is required for
Laravel's built-in server to honour the worker count — without it the
server logs `Unable to respect the PHP_CLI_SERVER_WORKERS environment
variable without the --no-reload flag` and falls back to a single
thread, which will block every other request while one worker holds a
long-poll connection open.

Raise the worker count for polyglot or multi-worker deployments:

```bash
docker run --rm -p 8080:8080 -e PHP_CLI_SERVER_WORKERS=16 \
  --env-file .env durable-workflow-server
```

For production workloads the `php artisan serve` built-in server is a
reasonable default but not the ceiling — FrankenPHP, RoadRunner, or an
nginx/php-fpm pair are all valid replacements and only require
overriding the container's `CMD`.

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

MIT
