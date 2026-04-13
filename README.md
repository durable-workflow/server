# Durable Workflow Server

A standalone, language-neutral workflow orchestration server. Write durable workflows in any language. Built on the same engine as [Durable Workflow](https://github.com/durable-workflow/durable-workflow).

## Quick Start

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
durable-workflow workflow start --type=my-workflow --input='{"name":"world"}'

# List workflows
durable-workflow workflow list

# Check server health
durable-workflow server health
```

## Getting Started: End-to-End Workflow

This walkthrough shows the full lifecycle using `curl` — start the server,
create a workflow, poll for tasks, and complete them. Any HTTP client in any
language follows the same steps.

Set a token for convenience (or set `WORKFLOW_SERVER_AUTH_DRIVER=none` in
`.env` to skip auth during development):

```bash
export TOKEN="your-token-here"
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
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"my-app","description":"My application namespace","retention_days":30}'
```

### 3. Start a Workflow

```bash
curl -X POST $SERVER/api/workflows \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2" \
  -d '{
    "workflow_id": "order-42",
    "workflow_type": "orders.process",
    "task_queue": "order-workers",
    "input": ["order-42", {"rush": true}]
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
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1" \
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
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1" \
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
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1" \
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
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1" \
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
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1" \
  -d '{"worker_id": "worker-1", "task_queue": "order-workers"}'

# Complete (use task_id and activity_attempt_id from the poll response)
curl -X POST $SERVER/api/worker/activity-tasks/TASK_ID/complete \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1" \
  -d '{
    "activity_attempt_id": "ATTEMPT_ID",
    "lease_owner": "worker-1",
    "result": "confirmation-sent"
  }'
```

### 8. Check Workflow Status

```bash
curl $SERVER/api/workflows/order-42 \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Control-Plane-Version: 2"
```

### 9. View Event History

```bash
curl "$SERVER/api/workflows/order-42/runs/abc123/history" \
  -H "Authorization: Bearer $TOKEN" \
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
- `GET /api/cluster/info` — Server capabilities and version

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

Workflow and history control-plane requests must send
`X-Durable-Workflow-Control-Plane-Version: 2`. Requests without that header or
with legacy `wait_policy` fields are rejected. Workflow and history responses
always return the same header. The v2 canonical workflow command fields are
`workflow_id`, `command_status`, `outcome`, plus `signal_name`, `query_name`,
or `update_name` where applicable and, for updates, `wait_for`,
`wait_timed_out`, and `wait_timeout_seconds`.

Workflow control-plane responses also publish a nested, independently versioned
`control_plane.contract` boundary with:
- `schema: durable-workflow.v2.control-plane-response.contract`
- `version: 1`
- `legacy_field_policy: reject_non_canonical`
- `legacy_fields`, `required_fields`, and `success_fields`

Clients can validate that nested contract separately from the outer
`control_plane` envelope.

The server also publishes the current request contract in
`GET /api/cluster/info` under `control_plane.request_contract` with:
- `schema: durable-workflow.v2.control-plane-request.contract`
- `version: 1`
- `operations`

Treat that versioned manifest as the source of truth for canonical request
values, rejected aliases, and removed fields such as start
`duplicate_policy` and update `wait_for`. Clients should reject missing or
unknown request-contract schema or version instead of silently guessing.

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

Worker-plane requests must send `X-Durable-Workflow-Protocol-Version: 1`, and
worker-plane responses always echo the same header plus `protocol_version: "1"`.
Worker registration, poll, heartbeat, complete, and fail responses all include
`server_capabilities.supported_workflow_task_commands` so SDK workers can
negotiate whether the server only supports terminal workflow-task commands or
the expanded non-terminal command set.

Long-poll wake-ups use short-lived cache-backed signal keys plus periodic
reprobes. Multi-node deployments therefore need a shared cache backend for
prompt wake behavior; without one, correctness still comes from the periodic
database recheck, but wake latency will regress toward the forced recheck
interval.

Within worker protocol version `1`, `worker_protocol.version`,
`server_capabilities.long_poll_timeout`, and
`server_capabilities.supported_workflow_task_commands` are stable contract
fields. Adding new workflow-task commands is additive; removing or renaming a
command requires a protocol version bump.

Workflow task polling returns a leased task plus `workflow_task_attempt`. Clients
must echo both `workflow_task_attempt` and `lease_owner` on workflow-task
`heartbeat`, `complete`, and `fail` calls. Workflow-task completion supports
non-terminal commands such as `schedule_activity`, `start_timer`, and
`start_child_workflow`, plus terminal `complete_workflow`, `fail_workflow`,
and `continue_as_new` commands.

Activity task polling returns a leased attempt identity. Clients must echo both
`activity_attempt_id` and `lease_owner` on activity `complete`, `fail`, and
`heartbeat` calls. Heartbeats accept `message`, `current`, `total`, `unit`, and
`details` fields; the server normalizes them to the package heartbeat-progress
contract before recording the heartbeat.

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

### Token Authentication
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" http://localhost:8080/api/workflows
```

### Signature Authentication
```bash
# HMAC-SHA256 of the request body
curl -H "X-Signature: COMPUTED_SIGNATURE" http://localhost:8080/api/workflows
```

Set `WORKFLOW_SERVER_AUTH_DRIVER=none` to disable authentication (development only).

## Deployment

### Docker

```bash
docker build -t durable-workflow-server .

# Bootstrap schema + default namespace once
docker run --rm --env-file .env durable-workflow-server server-bootstrap

# Start the API server
docker run --rm -p 8080:8080 --env-file .env durable-workflow-server
```

The Dockerfile clones the `durable-workflow/workflow` `v2` branch into the
build and satisfies the app's Composer path repository from that source. Use
`--build-arg WORKFLOW_PACKAGE_SOURCE=...` and
`--build-arg WORKFLOW_PACKAGE_REF=...` to point the image build at another
remote or ref when needed.

Across Compose, plain Docker, and Kubernetes, the supported bootstrap contract
is the same: run the image's `server-bootstrap` command once before starting the
server and worker processes.

### Kubernetes

```bash
# Create namespace and secrets
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/secret.yaml  # Edit secrets first!

# Run bootstrap job
kubectl apply -f k8s/migration-job.yaml

# Deploy server and workers
kubectl apply -f k8s/server-deployment.yaml
kubectl apply -f k8s/worker-deployment.yaml
```

### Configuration

All configuration is via environment variables. See [.env.example](.env.example) for the full list.

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

$client = new Client('http://localhost:8080', token: 'YOUR_TOKEN');

$worker = new Worker($client, taskQueue: 'default');
$worker->registerWorkflow(MyWorkflow::class);
$worker->registerActivity(MyActivity::class);
$worker->run();
```

### Python (future SDK)
```python
from durable_workflow import Client, Worker

client = Client("http://localhost:8080", token="YOUR_TOKEN")

worker = Worker(client, task_queue="default")
worker.register_workflow(MyWorkflow)
worker.register_activity(my_activity)
worker.run()
```

## License

MIT
