# Temporal Compatibility Parity Tracker

This document tracks the durable-workflow stack against the capabilities Temporal
announced at Replay 2026 (May 6, 2026). The goal is to make the parity surface
visible in one place so a Temporal user evaluating durable-workflow as a swap-in
can read a single page and know what is shipped, what is in flight, and what is
deferred.

The capability names below mirror Temporal's own labels for searchability;
durable-workflow chooses its own implementation names. What matters is the
contract a Temporal user expects when they swap durable-workflow in for the
same workload.

This is a snapshot, not a release gate. The platform conformance suite in
`docs/contracts/platform-conformance.md` and the surface-stability contract
re-exported from `GET /api/cluster/info` remain the binding contracts for
release.

## How to read the status column

- **shipped** — implemented, on `main`, and reachable from a stable surface
  (route, contract, or documented operator workflow). Evidence is a file path
  or a public commit subject.
- **in flight** — code exists on a feature branch or behind a flag and has not
  yet landed on `main`.
- **gap** — durable-workflow does not currently implement this capability.
  Either tracked here for a future promotion or filed as a standalone work
  item in the appropriate repo.
- **building blocks present** — the underlying primitives ship today, but the
  capability described in the announcement is a higher-level pattern,
  reference sample, or helper that has not been promoted to a first-class
  surface.

## How to use this tracker

- Before doing new work for a row, search the code, docs, and tests for
  evidence the feature is already there. If it is, update the row to
  **shipped** and point to the existing implementation rather than building
  it again.
- "Priority" is durable-workflow's view of its own swap-in story, not
  Temporal's roadmap. **P0** breaks the swap-in story; **P1** is a strong
  expectation a new user will have; **P2** is ecosystem or nice-to-have.
- When a row is large enough that it needs its own GitHub issue with its own
  reviewers, file it in the relevant repo and link the row to it. Smaller
  rows can stay tracked here.

## Core contract / server primitives

| # | Capability | Status | Priority | Evidence / notes |
|---|---|---|---|---|
| 1 | **Worker Versioning** — pin in-flight workflows to the worker version that started them; progressive rollouts | shipped | P0 | `workflow_runs.compatibility` is stamped from the start-options `build_id` and surfaced on the workflow show / list APIs (`app/Http/Controllers/Api/WorkflowController.php`). Build-id rollouts table at `database/migrations/2026_04_22_000200_create_workflow_worker_build_id_rollouts_table.php` plus the deployment-lifecycle columns added on top. |
| 2 | **Worker Heartbeats / Status surface** — every SDK emits periodic heartbeat (slots, resource, config); server stores; CLI and UI list workers per task queue | shipped | P0 | `/api/worker/register` and `/api/worker/heartbeat` accept optional `task_slots` (`workflow_available`, `activity_available`, `session_available`) and `process_metrics` (`cpu_percent`, `memory_bytes`, `process_uptime_seconds`, `process_id`, `host`, `process_started_at`); both acknowledgements echo `heartbeat_interval_seconds` (default 10s, configurable via `DW_WORKER_HEARTBEAT_INTERVAL_SECONDS`) and the heartbeat ack also returns `stale_after_seconds` (`app/Http/Controllers/Api/WorkerController.php`, `config/server.php`, `database/migrations/2026_05_09_000100_add_heartbeat_state_to_worker_registrations.php`). Slot/metric state is surfaced through `GET /api/workers` and `GET /api/workers/{id}` (`app/Http/Controllers/Api/WorkerManagementController.php`) and is consumed by the CLI `dw worker:list` / `dw worker:describe` commands and the Waterline Worker Status view. The process identity tuple (`host`, `process_id`, `process_started_at`) lets the server release stale workflow-task leases when a restarted worker reuses the same worker id. The PHP, Python, and Rust SDKs ship heartbeat loops; workers that miss heartbeats fall out of the default active roster after the stale window, remain inspectable with `status=stale` or direct detail lookups, and are excluded from query-task dispatch and routing-gate admission (`app/Support/TaskQueueRoutingGate.php`, `app/Support/WorkflowQueryTaskBroker.php`). |
| 3 | **Task Queue Priority + Fairness** — priority levels on workflow / activity tasks; fair distribution across workload classes | gap | P1 | No priority field on the workflow / activity task contracts and no fairness scheduler in the matching path. Filed as a standalone surface for design review before implementation. |
| 4 | **Nexus** — durable workflow-to-workflow / service-to-service calls across namespaces | shipped | P1 | The cross-namespace service-call layer (`app/Support/ServiceCallBoundary.php`, `app/Http/Controllers/Api/ServiceCatalogController.php`, plus the workflow-runtime architecture in `docs/architecture/workflow-service-calls-architecture.md`) gives every cross-namespace invocation a durable record, an activity-style retry policy, and a server-side namespace-ACL gate. The parity-named view sits in `app/Support/NexusContract.php` and is published from `GET /api/cluster/info` at `nexus_contract`. The wire surface (`POST .../execute`, `GET/POST .../service-calls/{id}`) plus the new caller-side history surface `GET /api/workflows/{workflowId}/runs/{runId}/nexus-operations` give an operator the per-caller debug view of every outbound Nexus call. Contract is `docs/contracts/nexus.md`. |
| 5 | **Principal Attribution** — server-derived non-spoofable field naming who invoked each workflow event | shipped | P1 | `app/Support/WorkflowCommandContextFactory.php` resolves the request's authenticated `Principal` (`app/Http/Middleware/Authenticate.php`) and stamps a server-derived `principal` block (`type`, `id`, `label`) onto every command context via `CommandContext::withPrincipal()`. The block is recorded on each mutation command (start, signal, update, cancel, terminate) and copied into the corresponding workflow history events; forwarded `X-Workflow-Principal-*` headers cannot override it even when caller/auth metadata trust is enabled. The history API surfaces the attributed actor at the top of each event in `GET /api/workflows/{id}/runs/{runId}/history` (`app/Http/Controllers/Api/HistoryController.php`). Contract pinned by `tests/Feature/WorkflowHistoryPrincipalAttributionTest.php`. |
| 6 | **Standalone Activities** — Activities run on their own (job-style), not just inside a Workflow; same Activity reusable inside Workflows | shipped | P1 | `POST /api/activities` (`app/Http/Controllers/Api/ActivityController.php`) starts an activity as a top-level durable job and returns a handle (`activity_id`, `activity_execution_id`, `workflow_run_id`). The server records each standalone activity inside a server-managed host run (`Workflow\V2\StandaloneActivity\StandaloneActivityHostType`, workflow type `dw.standalone_activity`) so dispatch (`/api/worker/activity-tasks/poll`, `/complete`, `/fail`, `/heartbeat`), retry/timeout enforcement, cancellation, and history projection all flow through the existing activity infrastructure unchanged — the same Activity definition that workflows schedule with the `activity()` yield is reusable here without rewriting. The host run surfaces on run-summary listings (`workflow_run_summaries.workflow_type = 'dw.standalone_activity'`) so standalone activities show up as first-class top-level executions on Waterline; `GET /api/activities` and `GET /api/activities/{id}` give the same listing/detail surface scoped to standalone activities, with the activity result delivered on the show endpoint. The `StandaloneActivityStartService` in the workflow package (`workflow/src/V2/Support/StandaloneActivityStartService.php`) writes the host run + `ActivityExecution` + ready `WorkflowTask` in one transaction, and `ActivityOutcomeRecorder::closeStandaloneHostRun()` closes the host run with the activity's result on terminal outcome instead of scheduling a workflow-task resume row. Failure + retry semantics pinned by `tests/Feature/StandaloneActivityApiTest.php` and the workflow-package suite `tests/Feature/V2/V2StandaloneActivityHostTest.php`. |
| 7 | **Workflow Streams** — durable streaming via Signal / Update primitives for token batches, real-time UI | shipped | P1 | Per-run named streams with at-least-once durable delivery: producer appends ordered items via `POST /api/workflows/{id}/runs/{runId}/streams/{name}/items` (idempotency-key collapse on retry), subscribers read with `GET .../items?from=N&wait_seconds=S` and reconnect by offset after a worker restart. Lifecycle (open / closed / errored), pending-item backpressure cap (`429 stream_full`), retention, and last-delivered offset are observable via `GET .../streams` and `GET .../streams/{name}` (`app/Http/Controllers/Api/WorkflowStreamController.php`, `app/Support/WorkflowStreamService.php`). The wire surface is published from `GET /api/cluster/info.workflow_streams_contract` (schema `durable-workflow.v2.workflow-streams.contract`, `app/Support/WorkflowStreamsContract.php`); contract pinned by `tests/Feature/WorkflowStreamsTest.php`. Underlying durability is the existing signal / update / command pipeline plus a per-stream append-only items table (`database/migrations/2026_05_09_000300_create_workflow_durable_streams_tables.php`); contract document at `docs/contracts/workflow-streams.md`. |
| 8 | **External Payload Storage** — large payloads stored externally (object store + custom driver) for AI / large-data workflows | shipped | P1 | Per-namespace external payload storage with transparent worker/history round-trip for oversized workflow and activity input/output: `app/Support/ExternalPayloadEnvelopeService.php`, `app/Support/NamespaceExternalPayloadStorage.php`, `app/Support/FilesystemExternalPayloadStorage.php`, `app/Support/ExternalPayloadRetentionCleanup.php`, control plane in `app/Http/Controllers/Api/StorageController.php`, namespace migration at `database/migrations/2026_04_22_000100_add_external_payload_storage_to_workflow_namespaces.php`, and contract doc at `docs/contracts/external-payload-storage.md`. Test coverage in `tests/Feature/ExternalPayloadStorageTest.php` and `tests/Feature/PayloadEnvelopeIntegrationTest.php`. |

## SDK additions

| # | Capability | Status | Priority | Evidence / notes |
|---|---|---|---|---|
| 9 | **Rust SDK** — first-party Rust support for Workflows + Activities | public preview | P2 | The independently versioned [Rust SDK](https://github.com/durable-workflow/sdk-rust) provides client start/signal APIs, worker registration using `runtime=rust`, workflow/activity task polling and completion, worker/activity heartbeats, and the shared fixed typed Avro Value protocol used by the PHP and Python SDKs. See the [Rust API documentation](https://rust.durable-workflow.com/) for installation and usage. |
| 10 | **AI integrations: Google ADK** — LLM calls + tool execution as Activities | gap | P2 | No first-party ADK helper. Workflows can already wrap ADK calls inside Activities today; the question is whether a packaged helper / sample is in scope. |
| 11 | **AI integrations: OpenAI Agents SDK + sandbox support** — agent SDK + isolated execution | gap | P2 | Similar to ADK: no first-party helper. Sandbox lifecycle is the harder half and is tracked separately under "Sandbox Orchestration Harness" below. |
| 12 | **AI Partner Ecosystem program** — partner-facing integration framework | strategic; out of scope as code | P2 | Not a code deliverable. Listed for completeness against the announcement. |

## Agentic / sample patterns

| # | Capability | Status | Priority | Evidence / notes |
|---|---|---|---|---|
| 21 | **Sandbox Orchestration Harness** — reference samples + thin SDK helpers wrapping Activities to manage agent sandbox lifecycle (provision, drive, persist, recover, clean up); not a new server primitive | gap | P2 | Tracked in the sample-app repo. Activities and the worker-session contract already cover the durable parts; the missing piece is the canonical sandbox-lifecycle pattern. |

## Compute / deployment

| # | Capability | Status | Priority | Evidence / notes |
|---|---|---|---|---|
| 13 | **Serverless Workers (AWS Lambda)** — Cloud auto-invokes / scales / shuts-down workers based on workload | gap | P2 | The worker protocol does not require a long-lived poller (workers may register, claim, and exit), but Cloud-side auto-invocation against task-queue depth is not built. Cloud-repo tracker. |

## Cloud / operational

These rows live in the `cloud` repo when promoted, not in `server`. They are
listed here so the parity surface can be read end-to-end.

| # | Capability | Status | Priority | Evidence / notes |
|---|---|---|---|---|
| 14 | **Prometheus / OpenMetrics endpoint** — Cloud-side metrics endpoint with task-queue / workflow / activity granularity | shipped | P1 | Server publishes the bounded runtime summary at `GET /api/system/prometheus-metrics` with task-queue, workflow, and activity series (`app/Services/PrometheusMetricsSummary.php`, `tests/Feature/SystemPrometheusMetricsTest.php`, and the cardinality policy in `docs/bounded-growth.md`). The Cloud repo exposes the namespace OpenMetrics scrape at `GET /api/v1/projects/{project}/environments/{environment}/namespaces/{namespace}/metrics` (`app/Http/Controllers/Api/OpenMetricsController.php`, `app/Services/OpenMetricsExporter.php`, `docs/openmetrics-endpoint.md`) and pins the required labels and series in `tests/Feature/OpenMetricsEndpointTest.php`. |
| 15 | **Multi-region + Multi-cloud Replication** — namespace replication with 20-minute RTO automatic failover | building blocks present | P1 | The server publishes only the self-hosted active/passive, operator-driven runbook boundary in `docs/multi-region-validation.md`. Cloud-side namespace replication, automatic failover/failback, observable replication lag, last successful replication, and current-primary state are not shipped on a stable surface yet; do not treat this row as promoted until the Cloud control plane owns that surface. Multi-cloud replication remains deferred. |
| 16 | **Billing API + Billable Action Metrics** — programmatic spend / usage with namespace + action-type labels | gap | P2 | Cloud-side. |
| 17 | **SCIM** — automated user provisioning / group management | gap | P2 | Cloud-side. |
| 18 | **AWS PrivateLink / GCP PSC self-serve** — private connectivity from customer network to Cloud namespace | gap | P2 | Cloud-side. |
| 19 | **Capacity Modes** — predictable per-namespace capacity sizing (spikes, batch, load-test) | gap | P2 | Cloud-side. |

## Recognition (not parity work)

| # | Item |
|---|---|
| 20 | AWS AI Competency in Agentic AI — recognition; not a parity gap. |

## Cross-references

- Platform conformance suite (binding release contract):
  `docs/contracts/platform-conformance.md`.
- Replay verification contract (binding promotion contract):
  `docs/contracts/replay-verification.md`.
- Bounded-growth policy (caches, metrics, label cardinality):
  `docs/bounded-growth.md`.
- Surface stability authority: `surface_stability_contract` field of
  `GET /api/cluster/info`.
- Public docs site: <https://durable-workflow.github.io/>.

Source for the capability list: Temporal blog,
"Announcing new Temporal capabilities from Replay 2026" (May 6, 2026).
