# Rust Server port

[Server #325](https://github.com/durable-workflow/server/issues/325) owns the
rewrite and its acceptance evidence. PHP remains the default published runtime.
The unpublished crate in [`rust/`](../rust/README.md) is an opt-in development
runtime (`DW_RUST_EXPERIMENTAL=1`) with official Avro encoding and SQLite,
PostgreSQL and MySQL/MariaDB storage. Full runtime parity, the backup-first
in-place upgrade, performance qualification and cutover remain open.

Read this log top to bottom: current state and next action, the surface
inventory, design decisions, then the detailed checkpoint history. Each
checkpoint stays exact to the source it names. A pass at one tree does not
qualify a later tree.

## Current state

Updated October 27, 2026.

- **Merged October 26-27, through the merge queue.**
  - [#790](https://github.com/durable-workflow/server/pull/790): prepared
    local-activity refusals.
  - [#793](https://github.com/durable-workflow/server/pull/793): Nexus catalog
    administration, service-call execute, show and cancel, shared admission
    limits, and catalog call reads.
  - [#795](https://github.com/durable-workflow/server/pull/795): takeover of
    published 2.11.1 databases (fresh and upgraded from 2.9.x), keeping search
    attribute definitions, worker sessions and service calls, plus the repair
    pass's service-call deadline sweep.
  - [#797](https://github.com/durable-workflow/server/pull/797): native's own
    maintenance repair loop (opt-in, `DW_RUST_MAINTENANCE_LOOP=1`); takeover
    keeps runtime credentials and update validation tasks; takeover of
    published 2.11.3, fresh and upgraded from 2.9.x.
  - [#799](https://github.com/durable-workflow/server/pull/799): the fixture
    adapters' Cargo cache, saved only by pushes to main.
  - [#801](https://github.com/durable-workflow/server/pull/801):
    message-stream retention (PHP's `MessageStreamRetentionCleanup`), and
    takeover that keeps message and workflow streams.
- **In the queue or next.**
  - [#803](https://github.com/durable-workflow/server/pull/803): the native
    release-candidate image (`Dockerfile.native`), its release workflow and
    the takeover runbook's image commands.
  - The parity reference moved to published 2.11.4 (#805,
    [checkpoint](rust-server-port-checkpoints/2026-10-27-reference-2114.md)),
    and moved to published 2.11.5 (#815); the SQLite maintenance pause stays
    for `service-calls` only
    ([checkpoint](rust-server-port-checkpoints/2026-10-27-reference-2115.md)).
  - The parity reference moves to published 2.11.7 (#863), with native's
    v1 projection history bytes in Core's canonical unit (#770)
    ([checkpoint](rust-server-port-checkpoints/2026-10-29-reference-2117.md)).
  - [#814](https://github.com/durable-workflow/server/pull/814): the shared
    fixtures accept published PHP's typed `429 long_poll_capacity_exhausted`
    poll refusal, and retry a locked Composer install after a transient
    download failure (the two #813 merge-group failures).
  - `bridge-adapters` compares exactly: published 2.11.7 accepts and
    deduplicates the bridge update (#747) and refuses a foreign instance as
    unavailable (#777), so the published-defect answers of 2.11.1 to 2.11.5
    are no longer accepted.
- **Still refused at takeover:** workflow-to-workflow messages
  (`workflow_messages`, written only by embedded PHP workflows) and external
  payload references. PHP keeps references in about 30 table and column
  locations in their URI form, so taking them over is its own project; the
  recommendation is that the release candidate keeps refusing them.
- **Shared Server fixtures.** Native, embedded and published PHP 2.11.7 are
  compared on SQLite, MySQL 8.0, MariaDB 10.11 and PostgreSQL 17. Where the
  published reference itself misbehaves, an explicit boundary separates it
  from native, and the PHP owner has an issue for each case. Recent ones:
  - #794, workflow-bound Nexus handlers.

  Answered as intended by 2.11.7, so `bridge-adapters` compares exactly:
  #747 (a bridge update to an SDK-worker workflow) and #777 (a bridge start
  for an instance another namespace holds).

  Closed by 2.11.5: Workflow #931 (`service-calls` repeats a key from
  another caller again, now refused 403), and the SQLite maintenance pause
  for `update-validation` (#723, #726). `service-calls` still records apart
  with the loop stopped: under full-corpus load 2.11.5 can exhaust its SQLite
  contention retries on a service-call insert (#796, open;
  [checkpoint](rust-server-port-checkpoints/2026-10-27-sqlite-service-calls-pause.md)).

  No expectation is edited to make native pass.
- **Parity surface.** Native answers all 175 API route methods in
  published 2.11.1's route table, which 2.11.4 and 2.11.5 leave unchanged.
  The last, the cancellation scope prefix checkpoint, is served since
  October 27
  ([checkpoint](rust-server-port-checkpoints/2026-10-27-scope-checkpoints.md)). Eight of those are the prepared
  local-activity routes, which it answers with PHP's refusal for a bridge
  without the prepared role. Nexus workflow-bound handler kinds answer 501
  until #794 settles their contract. An execution test sends each route
  through the native router, and the ones still answering
  `501 rust_capability_not_implemented` must match
  [`unserved-php-routes.v2.11.1.txt`](../rust/tests/fixtures/unserved-php-routes.v2.11.1.txt).
- **Remaining for the release candidate:**
  - (Takeover of the latest published PHP release, 2.11.8, is covered,
    with its pending external payload deletions carried over;
    [checkpoint](rust-server-port-checkpoints/2026-11-01-php-2118-takeover.md).)
  - the scope question with the operator: prepared local activities,
    workflow-bound Nexus handlers and external payload takeover;
  - publishing a final native release to Docker Hub. From November 1, native
    releases publish to the PHP image's own repositories,
    `durableworkflow/server` and `ghcr.io/durable-workflow/server`, as major
    version 3 (operator decision). Candidates 1 and 2 are on GHCR as
    `ghcr.io/durable-workflow/server-native:3.0.0-rc.1` and `3.0.0-rc.2`.
    Before the first final native tag reaches Docker Hub, the
    published-artifact conformance scripts that default to the newest
    `durableworkflow/server` tag (namespaces, principal attribution, Python,
    replay, sagas and migration) must either run native or pin PHP;
  - differential qualification of the published image. (Matched
    performance against the recorded PHP baseline is met on one host for
    release candidate 1: throughput, memory and idle long polls, with about a
    twelfth of PHP's runtime CPU per workflow;
    [observations](../benchmarks/server-port/rc1/observations-2026-10-30.md).)

## API surface still missing

These published PHP routes on this tree have no native handler yet, by family.
A request for one of them answers `501 rust_capability_not_implemented`;
any other unknown path or method answers PHP's own 404 or 405
([checkpoint](rust-server-port-checkpoints/2026-10-23-route-fallback.md)).

- **Used by the CLI or Waterline.** None remain. Served from this family
  since: task queue priority and fairness
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-priority-fairness.md)); standalone activities (start, list and
  describe), compared live with published 2.11.1
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-standalone-activities.md));
  run archive, instance and run-targeted
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-cli-sweep-and-archive.md));
  run redrive of a failed run at its failed activity
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-redrive.md));
  build pins at start and in polls, where unpinned activities follow
  [Workflow #908](https://github.com/durable-workflow/workflow/issues/908)
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-build-pins.md));
  build-id rollouts and deployments: promote, drain, resume and rollback,
  draining polls and starts
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-deployments.md));
  the service catalog read contract in cluster info, which Waterline 2.12.3's
  Services pages require; with it, every Waterline route answered native as it
  answered PHP
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-waterline-service-mode.md));
  namespace delete, with the
  `namespace-delete` fixture, and its external payloads, with the
  `namespace-delete-payloads` fixture
  ([checkpoint](rust-server-port-checkpoints/2026-11-01-committed-payload-deletion.md)). The JSON
  `GET|POST /api/system/metrics` is served with the `system-metrics`
  fixture: namespace failure, durable-state and payload counts are exact;
  native enforces no admission, durable-state or payload quota, so limits
  are null and rejections zero, as on an unconfigured PHP server.
  Projection drift is server-wide, and native's only drift source is a run
  without its summary. The Prometheus summary
  `GET /api/system/prometheus-metrics` is served with the
  `prometheus-metrics` fixture, with PHP's default limit of 100 series per
  family. External payload cleanup and its pass are served with the
  `payload-cleanup` fixture. The task repair pass is served with the
  `repair-pass` fixture; a candidate whose recovery fails is reported as
  a failure, with 207.
- **Worker protocol features.**
  - Prepared local activities (prepare, checkpoint, group checkpoint,
    recover, heartbeat, control, outcome and cancellation acknowledgement)
    are answered as a PHP server whose bridge lacks the prepared role answers
    them. Native advertises `prepared_local_activities: false`, no groups and
    no policies, so SDKs record local activities with `record_local_activity`.
    A worker that calls one anyway gets PHP's 409
    `prepared_local_activity[_groups]_not_supported`, listing
    `request_protocol` below 1.20 and `installed_runtime_prepared_local_activity`
    (plus `…_groups` for the group checkpoint), before any task is read.
    No recorded fixture: published 2.11.1 has the role, so its answers can't
    be recorded. `prepared_local_routes_are_refused_without_the_prepared_role`
    pins the answers to `PreparedLocalActivityController::refused`. Serving
    them is planned in
    [#325 comment 6423707058](https://github.com/durable-workflow/server/issues/325#issuecomment-6423707058).
  - (Cancellation scopes are served: open, prepare, deliver and the prefix
    checkpoint, with the `cancellation-scopes` fixture. The published PHP
    SDK checkpoints a turn's earlier commands before it opens or delivers a
    scope, and fails the task on any refusal
    ([checkpoint](rust-server-port-checkpoints/2026-10-27-scope-checkpoints.md)).)
  - (Worker sessions are served: create, heartbeat, close, list and detail,
    with the `worker-sessions` and `worker-session-activities` fixtures.)
  - (Activity cancellation acknowledgement is served:
    `POST /api/worker/activity-tasks/{task}/acknowledge-cancellation`, with the
    `activity-cancellation-acknowledgement` fixture. So is lease renewal, from
    Server 2.11: `POST /api/worker/activity-tasks/{task}/renew-lease`, with the
    `activity_lease_renewal` capability and the `activity-lease-renewal` fixture.)
  - (Synchronous update validation is served: the validation task poll,
    approve and reject routes, the multiplexed workflow poll with
    `task_kinds`, and validator-gated update admission, with the
    `update-validation` fixture. Native does not replay a validation task by
    `poll_request_id` or bind a request id to its task kinds; see
    `docs/rust-server-port-checkpoints/2026-10-23-update-validation.md`.)
- **Other families.**
  - Message streams are served
    ([checkpoint](rust-server-port-checkpoints/2026-10-23-message-streams.md)),
    except external payload references as input, stream retention cleanup
    and takeover of PHP data with streams.
  - Workflow-bound service handlers (start, signal, update and query
    workflow) and the workflow-task `start_service_operation` command. Each
    still answers 501 or refuses; published PHP's handlers are unusable for
    SDK-worker workflows (Server #794), so native waits for that contract. When the
    workflow-bound handlers come, an operation bound to a workflow update must
    refuse a `wait_timeout_seconds` above the update ceiling of 60 before it
    runs, as PHP does (Server #775).
    - Service catalog administration is served, and takeover keeps a PHP
      database's endpoints, services and operations. The 15 admin routes under
      `/api/service-endpoints` create, read, update and delete a namespace's
      endpoints, services and operations as `ServiceCatalogController` does,
      with the `service-endpoints` fixture's 72 cases. The `/api/service-catalog`
      reads render those rows by page and identity; the `service-catalog`
      fixture checks the empty namespace's 37 cases.
    - Service-call execution, show and cancel are served for the handler kinds
      that stay accepted (`activity_execution`, `invocable_http`), with the
      `service-calls` fixture's 91 cases. That covers admission and its audit
      row, the boundary's authorization and circuit refusals, the 503 for an
      invalid limit, the shared rate and concurrency limits, handler failures
      retried in the request, deadlines, idempotent replays, and cancellation.
      A workflow-bound kind answers 501 before anything is written.
      The `/api/service-catalog/calls` list and a call read by identity show
      the recorded calls as `ServiceCatalogReadController` does: the scopes
      (by default calls the namespace made or that target it), status and
      outcome filters and buckets, newest accepted first, and the detail with
      caller and link references; payload references and the Nexus result
      show only to the namespace that owns the call. The fixture's last nine
      cases read them.
    - Differences from published 2.11.1:
      - Native JSON documents don't keep object key order. An update that
        only reorders a document's keys writes nothing, where PHP's strict
        comparison writes it and moves `updated_at`.
      - PHP checks a name and then inserts without a lock, so concurrent
        creates of one name can end in a unique-index 500, and a delete can
        race a child's create. Native writes under its serialized transaction,
        so the second create gets 409.
      - Laravel also reads a write's fields from the query string. Native reads
        a field from it only when the body lacks that field, and only under its
        exact name, not PHP's `metadata[a]=1` array syntax.
      - A call's deadline lives in the native-owned `dw_service_call_deadlines`
        (schema version 7), not in 2.11.1's
        `workflow_service_calls.call_deadline_at`. Native keeps the frozen PHP
        tables plus its own relations, so takeover layouts stay recognisable;
        accepting a source with service calls will copy their deadlines.
      - Native commits a call's admission and dispatch in one serialized
        transaction, where PHP commits each step on its own. The rows end the
        same.
      - The shared admission counters live in the native-owned
        `dw_service_admission_counters` (schema version 7), not in PHP's
        cache. Native counts under its transition lock instead of a cache
        lock, so an admission never answers 503 because the lock was busy, and
        a call whose audit row fails to write takes back its rate count too.
        The namespace-wide limits and the hard ceilings run only at their
        default of none, and the concurrency lease lives the default day.
  - (Bridge adapter webhooks are served: `POST /api/bridge-adapters/webhook/{adapter}`,
    with the `bridge-adapters` fixture. Native admits bridge updates as the
    regular update route does; published 2.11.1 skips that admission, Server #747.
    Four further differences from published 2.11.1, all tracked in Server #777:
    - A start for an id that another namespace holds is refused as unavailable,
      naming neither the type nor the namespace. The draining gate and the
      instance reservation are decided under the transition lock, before
      anything is written.
    - The adapter name is at most 64 bytes.
    - Signals and updates are deduplicated inside their own transaction under
      the transition lock. Each one is recorded with a fixed-length
      `dw-bridge:v1:` identity as its request id, and the commands PHP
      recorded before takeover are matched by their metadata. A command
      under an identity, accepted or rejected, that records another tuple is
      refused `409 bridge_identity_conflict`.
    - The regular signal and update routes refuse caller request ids that
      start with `dw-bridge:`.)
  - (Caller-indexed Nexus operation reads are served, with the
    `nexus-operations` fixture. The fixture's lists are empty, because an HTTP
    execute records no caller run; an execution test seeds rows for the row
    shape and newest-first order. Native now records service calls, and
    takeover keeps PHP's.)

## Known gaps found in review

These are confirmed differences from the published PHP reference and open
contract decisions found while qualifying native slices. Each one needs a
contract fixture before its correction.

- **MySQL InnoDB Cluster behind MySQL Router** (November 1, qualified on one
  host; [checkpoint](rust-server-port-checkpoints/2026-11-01-mysql-innodb-cluster-router.md)).
  Native runs on a single-primary InnoDB Cluster through Router's
  read-write port. Schema creation, the workflow, timer and
  activity lifecycle, and primary crash, clean shutdown and hang all pass
  with exact history and no leftover leases; native answers 503 while no
  primary is reachable and recovers without a restart, 1 to 5 s after Router
  switches. Two defects found and fixed: the activity poll answer lacked
  PHP's `server_time` (since 2.11.0), so Python SDK 2.12.2 refused every
  activity native delivered on any database; and MySQL failover errors
  (1053, 1290, 1927, 2055, 3100, 3101, 4031) are now classified transient.
  Multi-primary, read/write split and several Routers are not covered.
- **History export waits and timeline** (November 1, closed,
  [checkpoint](rust-server-port-checkpoints/2026-11-01-history-export-waits.md)).
  Native's export now serves the run's stored waits with each kind's
  `RunWaitView` fields, and a timeline mapped from history as
  `HistoryTimeline` maps it, under the source name PHP gives a rebuilt
  timeline. The `history-export-waits` fixture records seven run shapes
  against published 2.11.7. A timeline command still carries no caller
  context, and only PHP's built-in exception classes resolve.
- **Cluster info, remaining fields** (November 1, narrowed). Native now
  answers `version` (the release version, built in from the `native-` tag),
  `capabilities`, `structural_limits` and `discovery`
  ([checkpoint](rust-server-port-checkpoints/2026-11-01-cluster-capabilities.md)).
  Capabilities are true only where native serves the feature.
  `workflow_activity_result_inspection` is now advertised: the
  `cluster-capabilities` fixture qualifies the activity result read against
  published 2.11.7. Native advertises no manifest it does not publish and no
  response compression. `native_unqualified` names each member it still
  leaves out: `supported_sdk_versions`, `client_compatibility`, `topology`,
  the diagnostics view, the external payload transaction limit and the
  conformance manifests.
- **Fairness within a priority tier** (October 31, closed). Published PHP
  tries each batch of a poll's candidates (10 at the default
  `max_tasks_per_poll`) in `TaskFairnessScheduler` order: fairness classes
  take turns by weight. Its fairness state is `InMemoryTaskFairnessState`,
  which starts empty on every request of its Apache server, so the order
  never moves a poll's first candidate. It decides which candidate a poll
  tries next when an earlier one cannot be claimed. Native now tries its
  candidates in the same batches and order
  ([checkpoint](rust-server-port-checkpoints/2026-10-31-task-fairness.md)).
  Two differences remain:
  - PHP's activity poll stops after one batch, while native keeps looking.
  - Published PHP's resume workflow tasks and timer tasks carry the default
    priority and no fairness key, against its own inheritance contract
    ([workflow#971](https://github.com/durable-workflow/workflow/issues/971)).
    Native gives every task its run's priority and fairness.
- **When an external payload reference is claimed.** Published PHP retains
  a referenced object once it fetches the bytes, before the command is
  recorded, so a refused command still retains it. Native claims it in the
  command's transaction, so the upload of a refused command expires
  unclaimed. Signal, update and query input otherwise resolve references as
  PHP does
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-external-command-input.md)).
- **Which worker receives an accepted update.** Published PHP gives the
  workflow task that carries an update to any worker of the type. During a
  rolling deploy that adds the handler, an older worker can take the update
  and fail it (`UnknownUpdate`).
  Native offers that task only to a worker that advertises
  `workflow_updates` and declares the update for the run's type. The PHP,
  Python and Rust SDKs do both exactly when they register the handler. This
  is a contract decision, recorded in
  [Server #690](https://github.com/durable-workflow/server/issues/690)
  (`tests/execution/update_routing.rs`).
- **Repair of a run without a recoverable task** (October 30 and
  November 1, closed). Published PHP's repair command creates a run's
  missing task and settles an expired activity-task lease through the
  activity's retry policy. Native's repair command and repair pass now do
  too:
  - a missing workflow task is recreated;
  - a missing activity task is recreated for the pending execution, with
    its latest retry's fields;
  - a missing timer task is recreated, available at the fire time;
  - an expired activity lease is settled by the activity's retry policy;
  - a received signal, an accepted update or a resolved child gets the
    workflow task that resumes the run from it;
  - a condition or signal wait's pending timeout gets its timer task
    again, available at the deadline, and a fired timeout gets the
    workflow task that applies it.

  Native's scheduler usually settles such a lease first, as PHP's repair
  loop does
  ([checkpoint](rust-server-port-checkpoints/2026-10-30-repair-missing-tasks.md),
  [wait tasks](rust-server-port-checkpoints/2026-11-01-repair-missing-wait-tasks.md)).
  A run with a received signal and no open task is now projected as PHP
  projects it: waiting to apply the signal, and needing repair. Native no
  longer refuses a repair with `development_workflow_task_repair_unqualified`.
- **Structural limits.** For external workers, published PHP enforces four
  of the Workflow package's `DW_V2_LIMIT_*` limits: the memo size, the
  command batch size as a bound on parallel group metadata, the pending
  signal and update counts with their payload size at intake, and the
  payload size of an activity's result. Native enforces all four; the
  command batch size is fixed at its default of 1,000, since native refuses
  to start with another value. A completion itself has no command-count
  limit in either (October 29: native's 100-command and 100-member group
  caps are removed; the 64-update lifetime cap went on October 23). #612
  tracks the rest
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-structural-limits.md)).
- **Completion budget, ahead of published PHP.** Native applies Phase A of
  [Workflow #930](https://github.com/durable-workflow/workflow/issues/930)
  ([Workflow #934](https://github.com/durable-workflow/workflow/pull/934)),
  which published 2.11.1 does not have yet:
  - `record_local_activity` caps `duration_ms` and heartbeat `elapsed_ms` at
    7 days and `backoff_seconds` at 1 day, each with PHP's field error. The walk
    back from completion is overflow-checked, and it is refused with
    `local_activity_timestamp_out_of_range` before it reaches
    1970-01-01T00:00:01Z.
  - After a run's deadline close, which is never metered, a completion whose
    local activities' exact events and rows exceed
    `DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE` or `_ROWS` (other commands count one
    event) is refused with `completion_budget_exceeded`.
  - The rest of the completion runs under a meter. Each history event charges
    one event, its bytes as `HistoryBudget` measures them (against
    `_BYTES`) and two rows, and each local activity row is charged before it
    is written. A refusal answers `history_transaction_limit_exceeded`, writes
    nothing and leaves the task's lease as it was. A tripped meter also refuses
    the commit.
  - Native never stores an external payload object while applying a
    completion: workers upload objects first, and a completion only claims
    their references. Native also dispatches no lifecycle events. So #934's
    staging and event deferral have no native counterpart.
  - Operator metrics, the health snapshot and the backend capabilities
    publish the active `history_transaction_size`, `history_transaction_bytes`
    and `history_transaction_rows` (0 when disabled), as Workflow #934's
    `StructuralLimits::snapshot()` does.
- **Closing a run with over 1,000 open activities or timers, ahead of
  published PHP** (October 28). Workflow's `attemptTerminalCommand` cancels
  every open activity and timer, and a run may hold up to 2,000 of each by
  default (`StructuralLimits`). Native used to refuse such a close: HTTP
  cancel and terminate answered `503 cancellation_inventory_exceeds_budget`,
  a parent-close policy recorded `ParentClosePolicyFailed`, and a
  cooperative cancellation could not complete. Native now closes it in one
  transaction, as the run timeout already did.

  Published 2.11.5 cannot close such a run in practice. On SQLite, its
  terminate time grows faster than the inventory: 4.0 s for 198 open timers,
  10.3 s for 396 and 28.9 s for 792. At 1,089 open timers or activities it
  exceeds PHP's 30-second execution limit, answers 500, and leaves the run
  open. No published reference can record a passing close, so native
  follows Workflow's source here
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-close-inventory.md)).
- **Waterline v1 projections, ahead of published PHP.**
  - Native refuses a message stream append to a projection with
    `409 v1_projection_read_only`, at the route before the body is read and
    again under the store's lock. Published 2.11.1 accepts it and creates a
    signal and a workflow task
    ([#769](https://github.com/durable-workflow/server/issues/769)).
  - Native refuses a projection that would write more history events or
    bytes than one transaction may (`DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE`,
    `_BYTES`) with `409 v1_projection_too_large`, before reading or writing
    any row. The count is checked from the source's row counts before any
    event is built; then each event is built and measured in turn, and the
    first event past the byte limit stops the build. Published 2.11.1 has no limit
    ([#770](https://github.com/durable-workflow/server/issues/770)).
- **Environment settings.** Of PHP's 164 contract settings, native honors
  49 and ignores 51 that change nothing it serves. It also honors
  `DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES` and `_ROWS`, which the Workflow
  package reads and PHP's contract does not list yet. It refuses to start on a
  non-default value of the other 64
  ([checkpoint](rust-server-port-checkpoints/2026-10-22-environment-contract.md)).
  Several of those are cheap to honor: repair timing and the multi-node
  declaration.
- **Signal and update names PHP cannot store.** Native validates signal,
  update and query names as PHP does (1 to 256 bytes, no control
  characters). PHP's signal and update name columns hold 255 bytes, so PHP
  stores a 256-byte name on SQLite and fails with a database error on MySQL
  and PostgreSQL. Native refuses such a name, and a name of only spaces,
  with `invalid_signal_name` or `invalid_update_name` on every database
  ([checkpoint](rust-server-port-checkpoints/2026-10-22-poll-waits.md)).
- **Key order inside plain JSON input** (November 1, closed). For input
  that is not an envelope, published PHP takes an object input's values in
  document order (`array_values`) and encodes each nested object as an Avro
  map in document order (`Avro::toDatum`). A nested object whose integer
  keys are not 0 to n-1 in document order, such as `{"1": "a", "0": "b"}`,
  is not a list, and its encoder fails with `invalid_map_key`. Native
  encoded nested maps with sorted keys and read such an object as the list
  `["b", "a"]`. It now encodes plain input in document order through the
  ordered Avro writer, so its blobs are PHP's byte for byte, and refuses an
  integer-keyed object that is not a list: on a start with PHP's 422 under
  `workflow_type`. On a signal, update, query, message or bridge start it
  keeps native's `422 invalid_workflow_arguments`, where PHP's signal answers
  `500 control_plane_internal_error`. The `key-order` fixture records starts
  and a signal against published 2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-11-01-key-order.md)).
- **Child completion while the parent has an open workflow task**
  (November 1; ready task closed, leased task decided;
  [checkpoint](rust-server-port-checkpoints/2026-11-01-child-open-parent-task.md)).
  Published PHP 2.11.7 records the child resolution and, while the parent
  has a ready or leased workflow task, creates none and leaves that task's
  payload as it is (`dispatchParentResumeTasks`).
  - **Ready task.** Native now does the same: the ready task replays the
    parent with the resolution in its history. An update task does not
    replay the workflow, so beside one the resolution still gets its own
    resume task. The `child-open-parent-task` fixture records it against
    published 2.11.7.
  - **Leased task.** On 2.11.7 a lease whose worker read history before the
    resolution completes without it, and the parent is left with no task;
    the repair pass does not recover it
    ([workflow#962](https://github.com/durable-workflow/workflow/issues/962)).
    Core 2.15.10, not yet in a published Server release, creates one child
    resume task when such a lease completes. Native gives the resolution
    its own resume task at once, as activity and timer resolutions do on
    both servers. Compare again when the parity reference moves to a Server
    release with Core 2.15.10.
  - A signal creates no resume while the parent waits only on a child, as
    on PHP: `PendingMessageTask::signalCanAdvanceRun` advances only signal
    and condition waits.
- **Update delivery into a ready task** (November 1, decided: native
  keeps its behavior). Published PHP merges an accepted update into the
  run's ready workflow task (`ExternalWorkflowUpdateAdmission::wakeWorkflowTask`).
  An update accepted before the first poll therefore gives the empty start
  task a `workflow_update_id`, and the published Rust, Python and PHP SDKs
  run such a task as update-only, so the workflow body waits for repair. A
  later update also overwrites an earlier one on the same ready task. Native
  delivers each update in its own task once the run has no open workflow
  task, oldest first, as PHP does behind a leased task. PHP's merge is
  reported as
  [server#902](https://github.com/durable-workflow/server/issues/902).
- **Timer firing under the CPU deadline profile on slower hosts.** In local
  MySQL 8.0 cells, where eight dense readers saturate the one-CPU database and
  the one-CPU native server, one of six runs fired the three-second timer
  1.177 seconds after its deadline, past the one-second canonical bound. Hosted
  runners passed all six database cells. The bound stays as declared. Next,
  measure how CPU-bound codec work on the single server CPU delays native timer
  dispatch, and keep timer, lease and cleanup deadlines inside the bound.
- **Renewal observer window.** The CPU profile's activity heartbeat timeout
  equals its 2-second request timeout. After this harness change, the post-claim
  run read and status read can still consume the first window on a slow host,
  and native then correctly fences the attempt. A heartbeat timeout comfortably
  above the request timeout, still inside the 9-second load wave, needs its own
  reviewed profile change.
- **A valid workflow task claim held by a stale worker** (October 28,
  [checkpoint](rust-server-port-checkpoints/2026-10-28-stale-workflow-owner.md)).
  Native's workflow task guard now ends with the owner's registration, as
  published PHP's does: heartbeat, history, fail, complete and the
  cancellation-scope checkpoint refuse a claim whose owner is missing or no
  longer fresh with `409 stale_worker_registration`. A workflow probe that
  claims nothing recovers such a task on its queue (up to
  `DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT`), repairing it as PHP does,
  and fences the owner as superseded, whose heartbeat is then refused.
  Published behavior kept: a draining build's worker is not fresh either. The `worker-poll-fence`
  fixture records the refusals and the recovery against published 2.11.5. The local activity
  checkpoints need no change: native refuses them before reading the task,
  as PHP without the prepared role does. Cooperative cancellation delivery and
  the cancellation-scope routes use the same freshness check with
  `DW_WORKER_STALE_AFTER_SECONDS`; delivery used a fixed 60 seconds until
  October 28 (`cooperative_delivery_uses_the_configured_stale_window`).
- **Retention of runs with external payloads** (November 1,
  [checkpoint](rust-server-port-checkpoints/2026-11-01-retention-external-payloads.md)).
  A pruned run's external payloads now go with it, as published PHP's
  `ExternalPayloadRetentionCleanup::deleteForRun` releases them: a payload no
  retained run holds loses its registration, and its object too when no
  other namespace registers it, counted in `external_payloads_deleted`.
  Without enabled local storage such a run is skipped with
  `external_payload_storage_driver_unavailable`. Since November 1 the object
  is deleted only after the prune commits, as published 2.11.8 deletes it
  (Server #789, #911;
  [checkpoint](rust-server-port-checkpoints/2026-11-01-committed-payload-deletion.md)):
  the prune records the deletion in `dw_external_payload_deletions` (schema
  version 8), so a prune that fails later keeps the run, its registrations
  and every object. A provider failure after the commit leaves the deletion
  pending, retried by the next pass. While one is pending the retention pass
  reports `external_payload_cleanup`, the payload cleanup routes report
  `retention_cleanup` (the pass answering 207), and namespace delete reports
  `external_payload_cleanup` with 207; 2.11.8 adds those counts to clean
  answers too, which native will do once CI's parity reference moves from
  2.11.7, whose clean answers lack them. An online-backup hold still fails the prune before it commits. The
  `retention-external-payloads` fixture records it against published 2.11.7
  and passes unchanged against 2.11.8.
  A run holding a reference native cannot settle (PHP's stored format from a
  workflow import, an envelope, or one on an inbound or durable stream) is
  still skipped with `external_payload_cleanup_unavailable`. Since November 1,
  a skip or failure of the payload cleanup marks the instance's inbound
  streams blocked with its reason (`cleanup_blocked_at`, `_reason`,
  `_run_id`), as PHP's `MessageStreamRetentionCleanup::markBlocked` does; an
  open abandoned activity's hold marks nothing, and a later completed prune
  of the run clears the mark. PHP reads the mark only in its capacity
  evidence, which native does not serve, so native tests cover the stored
  columns rather than a fixture.
  A run whose instance has inbound message streams is pruned as PHP's
  `MessageStreamRetentionCleanup` prunes it (October 27): the messages it
  consumed lose their payloads once a retained later run's cursor checkpoint
  covers them, and the instance's last retained run, once terminal, takes the
  instance's streams with it.
- **Native schema upgrades** (November 1, closed). Native once had no
  migration between its own schema versions, so the move to version 8 left
  release candidate databases (version 7) unable to open. The explicit
  `schema-upgrade` command now upgrades a stopped, backed-up native database
  on all four backends, as `upgrade-takeover` runs: it recognises the earlier
  version exactly, runs each later step file and records the new cumulative
  migration, verified as a fresh bootstrap is. Startup refuses an earlier
  version (`native_schema_upgrade_required`) or a later one
  (`native_schema_newer_than_binary`) instead of upgrading. MySQL and MariaDB
  fence the marker as `rust-upgrading` and resume an interrupted upgrade
  ([schema upgrades](../rust/README.md#native-schema-upgrades)).
- **Deeply nested JSON bodies** (October 30, closed). Published PHP's
  `json_decode` reads bodies nested up to 511 levels (its default depth of
  512) and refuses deeper ones with `400 malformed_json`. Native's handlers
  parsed with serde_json's 128-level limit, so a body nested 129 to 511
  levels was refused with a plain-text 400; and its pre-route check admitted
  one level too many. Request bodies are now read to 511 levels, bounded by
  that check. The `deep-json-bodies` fixture records it against published
  2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-10-30-deep-json-bodies.md)).
- **Python worker shutdown after a late synchronous query handler (not yet
  diagnosed).** In the SQLite query-authority cohort on #515's run
  39431491496, the published Python SDK 2.5.15 client in `late-handler-sync`
  mode failed against native. Its 6-second handler returned and was fenced
  with `query_execution_budget_expired` after one claim. The SDK's query thread
  then did not stop within the 5-second shutdown budget. Forty local
  repetitions of that case alone passed. Find what holds the query thread
  after the fence before changing either side.
- **Named-namespace routes native does not serve.** Native now serves every
  workflow command and operation it implements in any namespace, as PHP
  does ([checkpoint](rust-server-port-checkpoints/2026-10-23-named-namespace-commands.md)).
  A route native does not implement answers `501` in the default namespace,
  but `422 development_namespace_operation_unqualified` in a named one.
- **Child cancellation cascade, remaining parts.** Native propagates a
  parent's cooperative cancellation to its child under `try_cancel` and
  `wait_cancellation_completed`, as PHP does. The rest is open:
  - **Cascade view evidence native never writes.** Run debug carries
    PHP's `cancellation_cascade` view. Native writes no scoped
    (`cancellation-context/v2`) contexts and no activity stop receipts, so
    after a takeover a PHP-written scoped request reads as
    `request_context_invalid`, and a PHP-written stop receipt as
    `callback_state: unknown`.
  - **Children native cannot close yet.** When a policy would cancel or
    terminate a child 32 levels below the closing parent, native records
    `ParentClosePolicyFailed` with the refusal it would give an HTTP cancel
    or terminate and leaves the child open. PHP closes it.
- **Several open waits at once** (October 28, closed). Published PHP lets a
  run hold any number of open activities, timers, children and waits, and
  native now does too: a task may open a wait while others are open, an
  accepted signal reserves the latest open wait of its name (or is buffered
  behind an earlier pending signal of that name), and the resumed task
  applies it at its own wait's sequence and satisfies the latest open
  condition wait whose timeout has not fired. A declared buffered signal
  rebinds to the open wait of its name, and a later wait takes a pending
  signal's identity. The `concurrent-waits` fixture records it against
  published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-concurrent-waits.md)).
- **Continue-as-new transfers** (October 28, closed). Native continues a
  run over open activities, timers, children and waits, and from a child
  run, as PHP does
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-command-batches.md)).
  A new workflow type or queue on the command applies, as in PHP. Native
  also continues a run over streams, service calls and live queries, as
  published PHP does: Core's `applyContinueAsNew` moves message stream
  cursors and touches neither service calls nor query tasks. A call keeps
  the predecessor as its caller and stays open. A query admitted against
  the predecessor is still leased and answered from its history. The
  `continue-transfers` fixture records both against published 2.11.5, and
  `continue_as_new_transfer_unqualified` is gone. HTTP cancel and terminate
  and parent-close policies now share one rule, which reads no links and no
  queries. A continued, redriven or imported-linked run, a run with a live
  query, and a child that continued after its parent closed now close as in
  PHP. Native used to refuse (`..._propagation_not_available`,
  `child_call_mismatch`), or record `ParentClosePolicyFailed`
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-continue-transfers.md)).
- **Parent close, `request_cancellation` without a cooperative request**
  (October 28, closed). Published PHP applies the policy whenever a parent
  completes, fails, is cancelled, is terminated or times out. A parent
  without its own cooperative request first records a policy-owned root,
  `ParentCloseCancellationRequested`, after its terminal event
  (`ParentCloseCancellation::ensureOrigin`). Each open child then takes a
  descendant request under it. Native now does the same. It used to refuse
  the parent's completion, cancellation and termination while such a child
  was open, and to leave the child open when the parent failed or timed out.
  The `parent-close-cancellation` fixture records the completed, failed and
  terminated cases against published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-parent-close-origin.md)).
- **Root conflicts** (October 28, closed). When a child refuses a parent's
  request because it already holds one under another root, or because it is
  no longer active, PHP's `rejectCommand` records a rejected
  `request_cancellation` command on the child, at its next command sequence,
  with the requester's context. The conflict's diagnostics are its payload,
  and the parent's `ParentClosePolicyFailed` names it. Native now records it
  too, in both the policy and the cascade path. The
  `parent-close-cancellation` fixture's `conflict` case records it against
  published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-root-conflict-rows.md)).
- **Search attributes on start** (October 28, closed). Published PHP
  validates a start's `search_attributes` against the namespace's registry,
  stores them as the run's first typed rows and records them on
  `StartAccepted` and `WorkflowStarted`. Native now does the same. It used
  to answer `501 start_search_attributes_unavailable`. The
  `start-search-attributes` fixture records typed, inferred, null, empty
  and refused starts against published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-start-search-attributes.md)).
- **Query arguments the server cannot decode** (October 28, closed).
  Published PHP decodes a query's arguments only to check them against a
  query contract the run's start recorded
  (`validateContractedQueryArguments`). A run that declares no queries
  routes the query with its arguments as given, so its worker receives even
  a blob that does not decode. A declared query refuses such arguments
  before routing, with PHP's message and `validation_errors`. Native used to
  refuse any undecodable blob at admission with a bare
  `invalid_query_arguments`. The `query-arguments` fixture records both
  against published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-query-arguments.md)).
- **Start build-id pin** (closed; entry corrected October 28). Native pins
  a new run to its start cohort's build id as published PHP does: a promoted
  build-id rollout, else the single build id among the live workers for the
  type, else among all the queue's live workers (`start_in`,
  `deployments.rs`, `build_pins.rs`). The open-gap entry predated #696.
- **Named and validated query arguments** (October 28, closed). For a
  declared query, published PHP matches the arguments to the query's durable
  parameter contract (`WorkflowQueryContract::validatedArgumentsForRun`):
  - a string-keyed map is taken as named arguments;
  - positional arguments are matched in order;
  - defaults are filled in and variadics collected;
  - unknown names, missing required arguments, nulls, wrong types and extra
    arguments are each reported. Declared types may be nullable, unions,
    intersections, scalars, `array`, `object` or class names.

  A violation answers `422 invalid_query_arguments`, "argument validation
  failed", with per-argument messages in `validation_errors`. The worker
  receives the normalized positional list, encoded again. A declared query
  without a parameter contract takes a list as given and refuses named
  arguments. Native now does the same (`query_contract.rs`). It used to
  check positional arguments only, refuse a map, report a violation as a
  bare `invalid_query_arguments`, and refuse some contracts as unsupported.
  `Avro::fromDatum` returns an empty or integer-keyed map as an object, not
  an array, and a named variadic map spreads in wire order. A registered
  JSON default takes PHP's array shape. The `query-contract-arguments`
  fixture records twenty-two cases against
  published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-28-query-contract-arguments.md)).
- **Completions with more than 100 commands** (October 29, closed).
  Published PHP accepts any non-empty workflow task completion from an
  external worker; each command meets the run's structural limits (pending
  activities and timers) instead. Native refused more than 100 commands
  with `invalid_commands`. Its timer, activity and external-wait summaries
  also stopped at 100 open waits, so a run with more than 100 was described
  without its wait. Native also capped a parallel group at 100 members,
  where PHP bounds it by the command batch size (1,000). It refused an
  invalid group as `development_parallel_group_unqualified`, where PHP's
  preflight answers `validation_failed` with an error on each command. All
  of these are removed or ported. The `large-command-batches` fixture records
  150 timers, 150 activities, a 150-member group and a 1,001-member group
  against published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-29-large-command-batches.md)).
- **Descriptions after more than 64 updates** (October 29, closed).
  Published PHP bounds only pending updates, not how many a run takes over
  its life. The October 23 change removed native's 64-update admission cap,
  but its update summary still gave up past 64 updates. A run that had
  taken 65 was described with no wait. The bound is removed. The
  `many-updates` fixture records a run waiting for a signal through 65
  completed updates, then a 66th left accepted, against published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-29-many-updates.md)).
- **Descriptions with several open children** (October 29, closed).
  Published PHP describes a parent by its open waits however many children
  it holds: the first open child, or an open timer ahead of open children,
  under any parent-close policy. Native's child summary allowed one waiting
  child at a time, only the `abandon` parent-close policy, at most 100
  children over a run's life, and no timer beside a child. Such a parent
  was described with no wait. The `child-descriptions` fixture records five
  cases against published 2.11.5
  ([checkpoint](rust-server-port-checkpoints/2026-10-29-child-descriptions.md)).
  An open activity is likewise described ahead of open children
  (October 29). A child with a cancellation policy other than `abandon`
  under a worker that declares cooperative cancellation is still described
  without its wait, pending its own evidence.
- **Child retry and timeout options** (October 29, closed). Published PHP
  accepts `retry_policy`, `execution_timeout_seconds` and
  `run_timeout_seconds` on `start_child_workflow`, records them on the call
  and the child's runs, and retries a failed or timed-out child as a new run
  of the same child instance while attempts remain and the failure is
  retryable (`startChildRetryIfAvailable`). Native refused the three fields
  with `unsupported_request_field`. It now records and retries the same
  way, and describes a parent waiting on a retried child as waiting for it.
  The `child-retries` fixture records seven cases against published 2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-10-29-child-retries.md)).
  A child timeout or backoff too far away for a timestamp is refused
  (`invalid_child_timeout`, `invalid_child_retry_policy`), as a timer delay
  is. A parent's cooperative cancellation reaches a retried child (#873):
  the request is admitted, and the delivery follows the call to its current
  run and link, so under `try_cancel` the retry run, not the failed first
  run, is asked to cancel. The `retried-child-cancellation` fixture records
  both policies against published 2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-10-29-retried-child-cancellation.md)).
- **Trace context (Core 2.10, published in Server 2.11.1).** Core adds
  nullable `observability_context` columns on runs, commands and tasks.
  Native has no carrier: it accepts and ignores a top-level carrier on
  business operations, as a PHP server with the feature disabled does.
  Takeover recognizes both published 2.11.1 layouts, fresh and migrated from
  2.9.x. It counts the carried values (`observability_contexts_removed`) and
  drops the three columns; the verified backup keeps them
  ([#325 decision](https://github.com/durable-workflow/server/issues/325#issuecomment-6306545520),
  [checkpoint](rust-server-port-checkpoints/2026-10-26-php-2111-takeover.md)).
- **Server 2.11.3 (Core 2.14.2) takeover.** Takeover recognizes both
  published 2.11.3 layouts, fresh and created by 2.9.x. It copies each active
  session's recorded holder, drops full failure messages (counted; native
  reads the labelled preview, as PHP's older readers do), the service-call
  idempotency digest, completed dispatches and the stream reclaim deadline,
  and refuses while a dispatch is pending
  ([checkpoint](rust-server-port-checkpoints/2026-10-26-php-2113-takeover.md)).
  Published 2.11.4, 2.11.5 and 2.11.7 add no migration and have the same
  layout, so takeover recognizes their databases as `php-2.11.3`; a
  `php2117` cohort backs up, takes over and resumes a published 2.11.7
  source on SQLite, PostgreSQL, MySQL and MariaDB
  ([checkpoint](rust-server-port-checkpoints/2026-10-30-php-2117-takeover.md)).
  The parity reference was still 2.11.1 then. Core 2.14's new behavior (full
  failure-message storage, service-call scheduling and idempotency scope)
  is native work for the move to 2.11.3.
- **Stop signal in the published image.** The published Server image declares
  `STOPSIGNAL SIGWINCH`, Apache's graceful stop. Native stops gracefully on
  `SIGTERM` or `SIGINT` and ignores `SIGWINCH`, so `docker stop` on the
  published image with the native binary as its entrypoint waits out its
  timeout and kills the process. A native image must declare
  `STOPSIGNAL SIGTERM`.
- **Visibility query evaluation** (November 1, narrowed). Published PHP
  filters a structured list `query` in SQL. Native now adds a narrowing to
  its list statement that every matching run satisfies:
  - status and status bucket;
  - run type, workflow ID, run ID and queue equality or `IN`;
  - a present attribute row for each queried key;
  - keyword equality or `IN`;
  - integer comparisons and `IN`.

  Its matcher then decides each candidate as before, with only that page's
  attribute rows. So a selective query in a large namespace reads about as
  many runs as it selects. Each run is still decided by the matcher, so the
  answers are unchanged. A group of comparisons that cannot narrow, for
  example on start or close time alone, still reads candidates in list
  order, 500 at a time
  ([checkpoint](rust-server-port-checkpoints/2026-11-01-visibility-narrowing.md)).
- **Visibility query text comparisons** (October 30, closed). PHP compares
  text in a structured list query with the database collation: exactly on
  SQLite and PostgreSQL, and under the published MySQL schema's
  `utf8mb4_unicode_ci`, which ignores case, accents and trailing spaces, for
  attributes and run columns alike. Text fields accept only `=`, `!=` and
  `IN`. Native compared exactly on every backend; on MySQL and MariaDB it
  now compares each text by its collation key (`WEIGHT_STRING` of the value
  without trailing spaces). The `visibility-collation` fixture records both
  collations against published 2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-10-30-visibility-collation.md)).
- **Search attribute validation order** (November 1, closed). Published
  PHP reports several invalid search attributes in request order, on a
  start and on one `upsert_search_attributes` command, as
  `SearchAttributeValueValidator` loops over them. Native reported them in
  sorted key order. Since `serde_json`'s `preserve_order` (#906) its
  validator visits the request's keys in document order, and the `key-order`
  fixture records both orders against published 2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-11-01-key-order.md)).
- **Inherited memos and search attributes on child runs** (October 29,
  closed). Published PHP copies the parent's memos and search attributes to
  a child run, and the failed run's to a retried child
  (`inheritTypedVisibilityMetadata`). Native copied both only on
  continue-as-new; the same copy now runs for a child's first run and a
  retry. The `child-visibility` fixture records it against published
  2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-10-29-child-visibility.md)).
- **Search attribute datetime forms** (November 1, narrowed by decision;
  [checkpoint](rust-server-port-checkpoints/2026-11-01-search-attribute-datetimes.md)).
  `Carbon::parse` reads a registered datetime on a start, an upsert and a
  visibility query literal. Native now reads every deterministic form it
  accepts: an ISO 8601 date, or date and time with `T`, `t` or a space,
  minutes with optional seconds and fraction, and an offset written `Z`,
  `+HH:MM`, `+HHMM` or `+HH` or none (UTC); and an RFC 2822 date. Fractional
  seconds are truncated to microseconds, as PHP stores them, and an empty
  value stores nothing. The `search-attribute-datetimes` fixture records
  each form on a start and an upsert, and a filter by one instant, against
  published 2.11.7. Decided: native refuses, as an unparsable value, the
  forms that depend on the clock, a timezone database or PHP's date overflow.
  Published 2.11.7 stores `tomorrow` as the next midnight at write time,
  `@1700000000` as that Unix time, `2026-02-30` as March 2, `24:00` as the
  next day and `Europe/Paris` by its zone rules. No published SDK sends them:
  the Rust SDK validates RFC 3339 before sending, the Python SDK writes
  `isoformat()`, and the PHP SDK sends the string it is given.
- **Search attribute datetimes with an offset** (November 1, closed).
  Published Server 2.11.5 (Core 2.15.1) fixed
  [workflow#898](https://github.com/durable-workflow/workflow/issues/898):
  2.11.7 stores `2026-10-08T12:34:56+02:00` as the instant it names
  (`10:34:56Z`), as native does. The `search-attribute-datetimes` fixture
  records positive, negative and microsecond offsets, and a visibility filter
  by the stored instant.
- **Workflow task command fields.** Native checks a completion with PHP's
  transport rules, then its payload and normalizer rules
  ([checkpoint](rust-server-port-checkpoints/2026-10-23-completion-rules.md)).
  Two differences remain:
  - A field for a feature native cannot run, such as `worker_session`, is
    refused with `unsupported_request_field`. Fields PHP ignores are dropped
    as PHP drops them.
  - A timer delay, retry backoff, child timeout or wait timeout too large
    for a timestamp is refused on native. PHP accepts it, and wraps a
    timer's fire time into the past, so the timer fires at once.
- **Search attribute limits** (November 1, closed). Over 100 attributes or
  65,536 value bytes per run, published 2.11.7's `validateAttributeSet`
  exception reaches the worker completion's catch, which rejects the
  completion `422 invalid_workflow_stream_command` with the message as
  `error` and records nothing, or `workflow_memo_validation_failed` when the
  completion also upserts a memo. Native answers the same; it answered
  `search_attribute_limit_exceeded` before. The `search-attribute-datetimes`
  fixture records upserts at exactly 100 attributes and exactly 65,536 bytes
  as accepted; a start over a limit is refused `validation_failed` (the
  `start-search-attributes` fixture). How the limits are documented and
  configured is
  [workflow#853](https://github.com/durable-workflow/workflow/issues/853).
- **Cooperative cancellation boundaries.** Published PHP delivers a run's
  cancellation at an activity, local activity, timer, condition, signal,
  child, parallel or selection-handle boundary. Native delivers it at an
  activity, a timer, a condition or signal wait, a child (retried or not), a
  parallel group of activity and timer members, and a selection handle over
  such members (October 30,
  [checkpoint](rust-server-port-checkpoints/2026-10-30-parallel-cancellation.md)).
  Native authors no prepared local activity, and no parallel group of
  children or waits, so it refuses those boundaries with
  `422 cancellation_delivery_boundary_not_available`.
- **Activity results after a deadline** (October 30, closed; the entry
  was stale). Published 2.11.7 refuses an activity completion or failure
  that arrives after the attempt's start-to-close, schedule-to-close or
  heartbeat deadline, and records nothing, as native does. Before the
  timeout sweep closes the attempt, it answers `409` with the deadline's
  timeout reason. After the sweep it answers `409 stale_attempt`. With
  its maintenance loop stopped, it still refuses with the timeout reason.
  The `activity-late-outcomes` fixture accepts either refusal, because
  which one a run sees races the sweep on both servers. It checks that
  nothing is recorded and that exactly one `ActivityTimedOut` of the
  deadline's kind follows
  ([checkpoint](rust-server-port-checkpoints/2026-10-30-activity-late-outcomes.md)).
- **A repeated identical activity result** (October 30, closed). Published
  PHP answers every repeat of the result that finished an activity attempt
  with its stale-attempt receipt (`409 stale_attempt`, recorded false),
  identical or not, on either route, before and after the workflow consumed
  it. Native returned its earlier idempotent receipt (`200`,
  `already_completed`) for an identical repeat. It now answers as PHP does.
  The published PHP 2.2.6 and Rust 3.4.1 SDKs read a repeated completion's
  receipt as their own committed completion; Python 2.5.0 logs it and moves
  on. The `activity-result-repeats` fixture records both routes against
  published 2.11.7
  ([checkpoint](rust-server-port-checkpoints/2026-10-30-activity-result-repeats.md)).
- **Authentication beyond role tokens and runtime credentials.** Native
  refuses principal tokens (`DW_PRINCIPAL_TOKENS`), custom providers and the
  signature driver at startup. It still requires `DW_AUTH_TOKEN` with the
  `none` driver or database runtime credentials, which published PHP does
  not. Without a tenant-bound administrator, native does not read a namespace
  route's target from a JSON body `name`.
- **Command route names.** Published PHP records each request's route name in
  its command context, and its unnamed workflow routes get a name generated
  per deployment. Native records names only for the named schedule routes.
- **Registered default key order** (November 1, closed). Published PHP
  keeps a registered query default's keys in the order its database stores
  them, so a default map reaches the worker in that order, and
  `{"1": "a", "0": "b"}` is not a list and fails PHP's encoder with a 500.
  Native's JSON objects now keep document order (`serde_json` with
  `preserve_order`), and a default is a list only when its keys are 0 to n-1
  in stored order. SQLite, a Postgres `json` column and MariaDB keep the
  registered order; a MySQL `json` column orders keys by length, then bytes,
  for both servers. The `query-contract-arguments` fixture records three more cases
  against published 2.11.7, with a contract that holds on every backend
  ([checkpoint](rust-server-port-checkpoints/2026-11-01-registered-default-order.md)).

## Contract and verification inventory

The status column summarizes bounded native source and its recorded component
evidence. The current checkpoint above identifies the admitted head and any
separately prepared source. A listed implementation or earlier passing cohort
does not establish full parity, current-head qualification or release readiness.

| Surface | Current authority and reusable checks | Port status |
| --- | --- | --- |
| Control plane, namespaces, authorization, errors, visibility | `resources/platform-protocol-specs/control-plane-api.openapi.yaml`, `docs/contracts/auth-composition.md`, `routes/api.php`, Feature control-plane/auth/namespace tests; namespace and principal-attribution published runners | Bounded listing/CLI metadata, named workflow/activity/timer routes, role-token admission and namespace fences exist. PR478 adds [selected/current run observation](contracts/workflow-run-observation.md) and transactional summaries. Broader visibility, namespace and principal-authorization parity remains open. |
| Worker registration, sessions, leases, fencing, retries, timeouts, heartbeats, routing, affinity, backpressure, versioning | Worker OpenAPI and stream AsyncAPI in `resources/platform-protocol-specs/`; Feature worker/activity/prepared-local/cancellation tests; activity, heartbeat and worker-versioning published runners | Registration incarnations, exact-attempt leases, recovery, retry/heartbeat/deadline controls and fenced shutdown have source and scoped consumer evidence. Full fleet, session, routing, affinity, backpressure and policy compatibility remains open. |
| Avro values, external task inputs/results, payload storage and reclamation | `docs/contracts/external-task-{input,result}.md`, `external-payload-storage.md`, external payload OpenAPI; `regression-corpus-policy.json`, `tests/Fixtures/CodecRegression/`, payload Feature tests | Official Avro encoding/decoding, opaque task envelopes, versioned external transport, retained inputs/results and completion uploads exist. Codec, authority, external-value and targeted activity-result controls have separate evidence. Complete ingress, provider, reclamation and installed-consumer parity remains open. |
| Workflow lifecycle, duplicate commands, typed history, continuation, child workflows, cancellation, cleanup replay | Workflow lifecycle, migration, child-workflow and replay runners; Feature cooperative cancellation, history, migration and repeated-signal tests | Bounded completion, failure, sequential child outcomes, duplicate receipts, direct cancellation and cooperative root cleanup exist. PR478 includes [terminal update closure](contracts/terminal-update-closure.md), [cooperative child abandonment](contracts/cooperative-child-abandon.md), actual resume associations and [independent child root cancellation](contracts/child-independent-cancellation.md). Child retry/parallel/other close-policy families, wider cancellation propagation, cleanup replay and continuation transfer remain open. |
| Timers, schedules, signals, queries, updates, search attributes, memo, sagas | Corresponding runners in `scripts/conformance/`, manifests in `static/platform-conformance/`; Sample App polyglot experiments | Source covers durable timers, bounded schedules, typed signals/queries/updates, memo limits and bounded mixed selections. PR478 adds timed signal/condition waits, named signal waits and flat/nested activity/timer all barriers. Wider grouped leaves and selection shapes, queued control routing, policies, search and saga families remain open. |
| Local activities, cancellation scopes, worker sessions, streams, service catalog/Nexus, bridge adapters, standalone activities, debugging and repair | `routes/api.php`, `docs/contracts/`, worker/control-plane specifications, corresponding Feature tests and Nexus runner | Scoped native repair and diagnostics exist. Local-activity execution, cancellation scopes, sessions, streams, Nexus/catalog, bridge and standalone-activity compatibility remain required work. No unpublished package telemetry or stored-job contract is adopted by these slices. |
| Actual PHP/Python/Rust directions and existing CLI/Waterline/Sample App | Organization [conformance runbook](https://github.com/durable-workflow/.github/blob/main/conformance/README.md) and its SDK coverage inventory; Sample App activity, child, timer, saga, update, namespace and search experiments | Published cohorts cover workflow/activity (#401), parent/child (#408), timer authors (#411), signals (#415), queries (#422), successful/failed updates (#424/#427), named namespaces (#429), selected-run signals (#434) and subsequent corrected marker/range and authority slices. Each retains its exact source/tuple and failures. CLI reads have bounded coverage. Remaining SDK families, broader CLI, native Waterline/Sample App and browser acceptance remain open; published PHP consumers do not qualify native. |
| SQL schema and stored representations | `database/migrations/`, Workflow package `src/migrations/`, model casts, `Workflow\\Serializers\\Serializer`, queue job payloads, exported history and credential digests | [Representation checkpoints](rust-server-storage-audit.md) recorded; complete mapping and executable migration tests pending |
| Images, architecture, bootstrap, configuration, readiness, metrics and graceful shutdown | `Dockerfile`, `docker/`, `config/`, `docker-compose*.yml`, `k8s/helm/`, `docs/server-reference.md`, small-cluster/multi-region validation docs | From November 1 (operator decision) the published Compose file, `docker-compose.published.yml`, runs the native server with MySQL only: native creates its schema, runs its maintenance loop and needs no bootstrap job, queue worker, scheduler or Redis. Its default image is the current native release candidate by digest until 3.0.0 replaces it. The PHP 2.x stack moved to `docker-compose.php.yml`, which PHP releases still synchronize and the PHP published-artifact runners use. Native refuses `DW_AUTH_BACKWARD_COMPATIBLE=false` and an empty `DW_AUTH_TOKEN`, so role-only authentication is not available: the shared token is required and accepted for every role. The Helm chart switch, full configuration and metrics compatibility, and operational cutover remain open. |
| Backup and in-place upgrade | `docs/self-hosted-backup-and-restore.md`, [PHP ownership/refusal controls](php-database-ownership.md), external payload backup holds; last PHP image writes, all PHP roles stop, Rust takes over same DB sequentially | Ownership/refusal controls and isolated migration preparation exist. Backup-first native takeover with pending work, interrupted migration, external payload preservation and PHP refusal still need complete qualification on SQLite, MariaDB/MySQL and PostgreSQL. No mixed fleet or PHP rollback is permitted. |
| Throughput, memory, CPU, latency, backlog/drain, idle mixed long polls | `benchmarks/capacity/v1/` and `scripts/benchmark/`; repeated runs and drift checks using frozen artifacts | The [diagnostic PHP reference](../benchmarks/server-port/v1/php-observations-2026-10-09.md) and scoped comparison observations are recorded. Complete matched throughput/memory/idle-poll proof, repeated measurements, drift checks and CPU-load deadline health remain release gates. Source checks and functional comparisons make no performance claim. |

Ordinary PHP, Rust and embedded runs use independent databases. Never connect
different engines to one database concurrently. SQLite multi-node qualification
must state its shared-file topology and locking limits; existing single-node
support is not evidence of multi-node safety.

## Decisions

- **October 22: the parity reference moves from published Server 2.5.14 to 2.11.1.**
  Published PHP has moved on, and the Sample App and current consumers resolve
  2.10 or later.
  - A local run measured the change. It ran the shared fixtures against the
    2.11.1 image (`sha256:bf8bed13…`) with the consumer tuple unchanged.
  - Three reviewed expectations followed 2.5.14 and differ under 2.11.1:
    - **`signals-before-worker`:** signals a run closed before applying are
      recorded as `SignalRejected` after its terminal event.
    - **`worker-listing`:** every registration's stored process metrics carry
      its `_worker_protocol_version`, including one that reported no metrics.
    - **`schedule-policies`:** under SQLite lock pressure PHP refuses with the
      structured, retryable `503 backend_lock_pressure` rather than "database
      is locked".
  - Native already behaved as 2.11.1 does in the first two. The first two
    expectations move to 2.11.1. The probe now retries the third refusal under
    its existing rule: a write is sent again only when its schedule reads
    unchanged.
  - The frozen 2.5.14 tuple remains in `tests/Fixtures/ServerParityBaselines`
    for the takeover cohorts that pin it.
  - It also remains the physical layout native creates, following the
    conversion-recipe decision below. The SQLite, MySQL and PostgreSQL catalog
    comparisons bootstrap with the 2.5.14 image. The MySQL and PostgreSQL
    reference databases are then migrated forward to 2.11.1 before the
    behavior comparison, the same upgrade PHP users run.
  - The consumer tuple (PHP SDK 2.2.6, Python 2.5.0, Rust 3.4.1, CLI 2.2.0,
    Waterline 2.3.1) is unchanged. The embedded target now runs Workflow 2.10.2
    from the 2.11.1 image.
- Shared fixtures are expectations reviewed against public contracts, not PHP
  recordings promoted to expected behavior. The recorder keeps raw observations;
  the comparator checks the declared fixture projection. Coverage is explicit.
- The HTTP adapter uses the published PHP SDK and its official Avro codec. The
  embedded adapter executes real Workflow jobs and persisted history. Neither
  counts an accepted start as completion or substitutes mocked execution.
- Generated run/activity/attempt identifiers may differ between independent
  databases. Compare their relationships with bijective aliases; retain actual
  IDs in raw evidence. Preserve workflow IDs, event order, command sequences,
  attempt counts and decoded values. Wall-clock observations remain raw.
- The first Rust foundation uses Apache's official Avro single-object
  reader/writer with the immutable schema. The first HTTP/storage slice uses
  Axum/Tokio and SQLx with selected features and native transactions. Avoid
  unnecessary dependencies and code generation. Record
  clean and incremental compile time plus target-directory size for the first
  runtime slice. Use one task-owned Cargo target directory, low debug information
  for development, bounded build concurrency and cleanup after qualification.
- Evaluate [Kache](https://github.com/kunobi-ninja/kache) in a task-local runtime
  before choosing the Rust build setup. Use an explicit cache budget and local
  storage; measure physical disk blocks across cache and targets together. Keep
  cache and build outputs under one container mount so cross-mount copying does
  not erase sharing. Use a per-run compiler wrapper and scoped cleanup.
  The [first real crate measurements](../rust/build-observations-2026-10-09.md)
  observed native clean builds around 22 seconds and 182 MiB targets. Kache
  restored a fresh target in 3.5 seconds but added cold-build time and retained
  disk; native no-op/comment-edit builds were faster in-place. Keep it optional.
  Full-server build cost and any disk-saving claim remain pending.
- Tokio tasks are [cooperatively scheduled](https://docs.rs/tokio/latest/tokio/task/coop/index.html).
  Potentially expensive replay, decoding and synchronous database operations
  need bounded work away from the async request executor. `spawn_blocking` needs
  an explicit CPU concurrency limit, and a started blocking job cannot be
  aborted. Preserve durable fencing and cancellation checks around that work;
  qualify timer, lease and cancellation deadlines under CPU-heavy load.

## Checkpoint history

Newest checkpoints first. Each entry is exact to the source, tree and runs it
names, including unsuccessful observations. From October 21, each new
checkpoint is its own file in
[`rust-server-port-checkpoints/`](rust-server-port-checkpoints/), named
`YYYY-MM-DD-topic.md`; that directory is their index. Changes that add a
checkpoint do not edit this file, so they do not conflict here.

October 21, operator dashboard:

- **Found by.** Waterline 2.12.0's landing page in front of native: its
  `/waterline/api/stats` request reached native's
  `/api/system/operator-dashboard/bounded`, which answered 501.
- **Source.** Native serves admin-only `GET /api/system/operator-dashboard`
  and `/bounded`, in the default or a named namespace, by the rules of
  Workflow 2.5.5's `OperatorDashboardSummary` over the run summaries:
  - the flow counts and rate, current running and failed runs, the hour's
    completed and failed runs, the longest open wait and the longest
    duration as summary rows, with the model's casts and appends;
  - the week's ten busiest workflow types with pass rate, error count,
    status breakdown and the upper-middle duration;
  - PHP's attention alerts over workers and run summaries;
  - hourly completed and failed counts for the last 169 hours;
  - the operator metrics snapshot. The bounded variant marks the history
    audits as not requested and reports capacity evidence unavailable.
- **By workflow type.** `/bounded/workflow-types` applies PHP's
  `workflow_types` validation and answers the bounded dashboard with every
  run figure narrowed to the listed types, as published Server 2.11.1 does.
  `workflow_scope` echoes the list, and the operator metrics keep the whole
  namespace. The list is bound as one JSON value, and each database compares
  through the column, as PHP's `whereIn` does: MySQL and MariaDB collations
  ignore case, SQLite and PostgreSQL do not. Waterline turns on its workflow
  type filter whenever the bounded dashboard has a `workflow_scope`, so it
  asked native for this and got 501 until
  [the checkpoint](rust-server-port-checkpoints/2026-10-23-dashboard-by-workflow-type.md).
- **Verification.** `operator_dashboard` integration tests pass on SQLite,
  PostgreSQL and MySQL, and the full native suite passes on SQLite. With
  identical seeds, Waterline's stats from native and from published PHP
  2.5.14 differ only in identifiers, timings and durations, summary
  `history_size_bytes` (above) and sort-key precision. Before the scheduled
  run summary correction, native also counted one run fewer.

October 21, operator metrics and health:

- **Source.** Native serves admin-only `GET /api/system/operator-metrics` and
  `GET /api/system/health`, in the default or a named namespace, from the
  rules of Workflow 2.5.5's `OperatorMetrics` and `HealthCheck`:
  - the run, task, activity, backlog, start, history, schedule, worker and
    command-contract counts, with PHP's predicates over the shared tables;
  - the twelve health checks, their categories and messages, and 503 while a
    check reports an error.

  Like Laravel, task comparisons with "now" bind whole seconds, while activity
  deadlines and schedule times compare with microseconds. The policy
  sections describe native: no repair loop, sticky routing or worker
  sessions, in-process long-poll wake, and native's structural limits and
  workflow task lease. `capacity_evidence` reports itself unavailable.
- **Worker deregistration.** `DELETE /api/workers/{id}` now works in a named
  namespace and removes only that namespace's registration, as in PHP.
- **Decision: selected-run projections.** Native stores run waits in the
  transaction that records history, so they cannot drift. It reads
  timelines, timers and lineage from history, so those tables are reported as
  not maintained rather than aligned. The health check reports native's wait
  projections. PHP's same check also warns while its asynchronous timeline
  projection catches up.
- **Task rows.** Native now stamps a new task's `created_at`, `updated_at`,
  `last_dispatched_at` and `last_dispatch_attempt_at`, as PHP does in poll
  mode. Run summaries classify a task's transport as PHP's
  `RunSummaryProjector::taskLiveness` does: lease expired, dispatch failed,
  claim failed (`<type>_task_claim_failed`), dispatch overdue, then leased or
  ready, with PHP's wait reasons and task-problem flag. Before this, native
  declined to project a run whose tasks carried these columns.
- **Fixture.** `operator-metrics` drives a fresh namespace through seven
  stages, from empty to a completed run with one activity retry. The stages
  cover a waiting run, an overdue unclaimed task, and an activity scheduled,
  running and retrying. Published PHP 2.5.14, embedded PHP (which declares it
  inapplicable) and native pass the contract. In a local copy of the SQLite
  parity step, 32 fixtures (the schedule and visibility fixtures need CI's
  scheduler and CLI artifact) passed on all three targets, and the
  comparison passed.
- **Known differences.**
  - Summary `history_size_bytes` differs from PHP's (8 events: PHP 10,351
    bytes, native 7,961).
  - PHP stamps a dispatch on some new tasks and not on an activity scheduled
    from a workflow task completion, so `tasks_dispatched_last_minute`
    differs.
  - PHP's maintenance loop redispatches an overdue ready task, after which
    PHP reports it repaired, not overdue. Native runs no repair loop, so the
    task stays overdue until a worker claims it. The fixture accepts either
    PHP state. Since October 23 it also accepts PHP's reads that straddle
    the redispatch, provided they never go backwards; see
    [the redispatch-states checkpoint](rust-server-port-checkpoints/2026-10-23-operator-metrics-redispatch-states.md).
  - The operator dashboards, capacity evidence, and the repair, retention,
    activity-timeout and payload-cleanup pass endpoints remain.

October 21, query responses and routing:

- **Found by.** The Waterline comparison and direct probes of published PHP
  2.5.14. Native answered every query on a run with an open workflow task
  with `query_snapshot_busy`. It refused queries a run did not declare even
  when the run declared none, and it refused unknown request fields and a
  null `input`. Its responses lacked PHP's `control_plane` envelope and
  messages.
- **Admission order.** As in PHP: a workflow outside the namespace is 404
  `Workflow not found.`. A selected run that is not current is 409
  `historical_run_command_rejected`. A non-array `input` fails Laravel's
  rule with 422. A failed, cancelled or terminated run is 409
  `run_not_active` with `run_status` and `is_terminal`. Other request fields
  are ignored, and a null `input` means no arguments.
- **Contracts.** A run that declares no queries leaves the name and arguments
  to the worker. A declared query without a parameter contract takes its
  arguments as given. Worker claims match the workflow type, not the query
  name, so a worker answers queries it does not declare, as in PHP.
- **Routing.** Before admitting a query, native checks PHP's query route. A
  missing route is refused with PHP's reason and message. While no worker is
  available at all, the request waits up to the query timeout for one to
  register, as `awaitRegisteredQueryRoute` does.
- **Timeouts.** A query nobody claimed re-checks the route. It is refused if
  the route is gone, otherwise it is 504 `query_task_not_claimed`. A claimed
  query that times out is 504 `query_worker_execution_timeout`, naming the
  worker. Both carry PHP's messages.
- **Responses.** Answers and refusals carry `target_scope`, `query_name`,
  `result` and PHP's `query` envelope, with its rejection fields and reasons.
- **Still different.** Native still refuses a query while a workflow task is
  open (see the known gaps), now with PHP's body shape and an explanatory
  message.
- **Evidence.** Probes of the route refusals, the terminal run, the missing
  workflow, the historical run, invalid input and an unclaimed query match
  PHP's status, reason, message and envelope. The open-task case is the only
  difference. `state-queries`, `state-updates`, `signals-before-worker` and
  `echo` pass on published PHP, embedded and native. New `query_responses`
  tests and the existing query tests pass on SQLite, PostgreSQL and MySQL.

October 21, history export:

- **Found by.** On native, published dw 2.3.0's `workflow:history-export`
  and Waterline's export download both answered 501.
- **Source.** Native serves
  `GET /api/workflows/{id}/runs/{run}/history/export` as Workflow 2.5.5's
  `HistoryExport::forRun` builds the bundle (`schema_version` 2):
  - the workflow, payloads, summary, history events, commands, signals,
    updates, tasks, activities with their attempts, timers, failures and
    links;
  - the Avro codec schema and the payload manifest of every Avro frame;
  - the `json-recursive-ksort-v1` SHA-256 checksum, and HMAC-SHA-256 when
    `DW_V2_HISTORY_EXPORT_SIGNING_KEY` (with `_ID`) is set.

  Timers and links are rebuilt from history and link rows under PHP's
  `*_rebuilt` source names. An unknown run answers 404 `run_not_found`.
- **Verification.**
  - On identical seeds against published PHP 2.5.14, every section's values
    match apart from identifiers, timestamps and the projection sources.
    That covers workflow, payloads, summary, signals, activities, payload
    manifest, links, redaction and codec schemas.
  - The remaining differences are in data native records differently:
    commands carry no caller context, event payloads hold envelopes where
    PHP stores bare blobs, and task payloads differ.
  - Published Python SDK 2.7.0's bundle verifier recomputes native's
    checksum and finds it valid. Its only finding, on PHP's bundle and on
    native's, is that it accepts `schema_version` 1 only
    (durable-workflow/sdk-python#190).
  - dw 2.3.0's `workflow:history-export` and Waterline 2.12.0's export
    download succeed against native.
  - New `history_export` integration tests and unit tests pass, and the
    full native suite passes on SQLite, PostgreSQL and MySQL.
- **Remaining.** None for waits and timeline since November 1; see known gaps.

October 21, signal and update requests:

- **Found by.** Published dw 2.3.0 run against both servers on the same seed.
  - On native, `workflow:signal` and `workflow:update` with `--input '[1]'`
    were refused with `unsupported_payload_codec`.
  - `workflow:repair` was refused with `invalid_request`, because the CLI
    sends `[]` for a command without fields.
- **Source.**
  - **Empty bodies.** A short JSON `[]` body is read as `{}`, as Laravel
    reads both.
  - **Validation.** Signal and update apply `WorkflowController`'s rules with
    Laravel's messages and ignore fields outside them. Signal checks
    `input`, `request_id` and `dry_run`; update checks `input`,
    `request_id`, `wait_for` and `wait_timeout_seconds`, and refuses the old
    `wait_policy` field.
  - **Input.** Both take input as start does: absent, null or empty is no
    arguments, and plain JSON is encoded as its values in request order.
  - **Update default.** An update without `wait_for` returns once it is
    accepted, as in PHP. Native previously waited for completion.
- **Remaining differences.**
  - A signal `dry_run` is refused with
    `501 signal_dry_run_unavailable`; PHP returns a preview.
  - Native keeps its 30-second cap on an update's completion wait; PHP
    waits as long as the request asks.
  - Native validates a signal or update body before it looks up the
    workflow, so an invalid body for a missing workflow is 422, where PHP
    answers 404.
- **Verification.** New `command_requests` integration tests and validator
  unit tests. The full native suite passes on SQLite, PostgreSQL and MySQL.

October 21, workflow start requests:

- **Found by.** Waterline-harness probes against published PHP 2.5.14 and the
  CLI's `workflow:start`. Native refused `business_key`, `priority`,
  `fairness_key`, `fairness_weight`, `payload_codec` and every unknown field,
  required `workflow_id`, `task_queue` and an envelope `input`, and answered
  without the control-plane contract the CLI requires. As a result,
  `dw workflow:start` failed against native even when the run started.
- **Validation.** Native applies `WorkflowController::start`'s rules with
  Laravel's messages and order, after TrimStrings and
  ConvertEmptyStringsToNull on every string in the body. The `after` hook
  names the six legacy fields, and `payload_codec` accepts only `avro`, with
  PHP's message. Unknown fields are ignored. One native-only bound remains:
  a timeout above 3,153,600,000 seconds (100 years) is refused, so every
  deadline stays representable.
- **Defaults.** As `WorkflowStartService` passes them on: a generated
  instance ID, the `default` queue, empty arguments for absent, null or
  empty input, and plain JSON input encoded as its values in request order.
  Only JSON integers set a timeout, priority or weight, so `"4"` keeps the
  default, as in PHP.
- **Stored fields.** `business_key` on the instance and run; priority
  (default 5), the lowercased fairness key and the weight (default 1) on the
  run and on every task it creates. Workers claim lower priority numbers
  first.
- **History and responses.** StartAccepted keeps PHP's `business_key`,
  `visibility_labels` and `search_attributes` fields; WorkflowStarted drops
  null fields. A start for an existing instance records its command and
  event on the current run: `use-existing` on an active run answers 200
  `returned_existing_active` with StartAccepted, and anything else answers
  409 `rejected_duplicate` / `instance_already_started` with StartRejected.
  Every start response carries the control-plane contract; its block matches
  PHP's except for identifiers.
- **Verification.**
  - The full native suite passes on SQLite, PostgreSQL and MySQL, including
    the new `start_request` integration tests and the validator unit tests.
  - Against published PHP 2.5.14, 17 start probes now match in status,
    errors, defaults, stored input and history events. Two differences
    remain: describe's `business_key`, which native reports once #578
    merges, and the nested-map key order above.
  - The checkout CLI's `workflow:start` with `--input`, `--business-key`,
    `--priority` and the fairness options succeeds against native.
- **Remaining.** Search attributes on start, fairness rebalancing and the key
  order are listed under known gaps.

October 21, describe actions and run list:

- **Found by.** The Waterline comparison. Waterline 2.12.0 disables cancel,
  terminate, signal and repair for a run when the server's describe response
  lacks PHP's `actions` block, so on native every run looked read-only.
- **Actions.** Describe now carries PHP's `actions`. Signal, cancel,
  terminate and repair need the current open run. Queries need a
  query-capable worker route. Updates are never offered, because PHP offers
  them only for workflow classes it can load in process. Archive needs a
  closed, unarchived run.
- **Query route.** A port of PHP's `registeredQueryRoute` decides `can_query`:
  a fresh active worker on the run's queue, query-task capability, the
  workflow type, the run's compatibility (an unversioned run with declared
  queries needs an unversioned worker) and the recorded definition
  fingerprint, checked in PHP's order. Native counts only workers that
  registered the `query_tasks` capability, because only they can claim native
  query tasks. PHP also counts a worker that recently polled for query tasks.
- **Other describe fields.** `business_key`, `resume_step_sequence`,
  `recovery_kind`, `compatibility` with its fleet status and reason,
  `payload_previews`, `last_progress_at`, `wait_kind`, `wait_reason`, typed
  `search_attributes`, `updates_scope` and `updates`. An empty memo or search
  attribute set is `[]`, as PHP renders it. Start and close times use
  `toJSON()`; deadlines and last progress use `toIso8601String()`, which has
  whole seconds.
- **Run list.** `GET /api/workflows/{id}/runs` answers with PHP's
  `run_count` and `formatRunListEntry` entries, including status bucket,
  terminal flag, task queue, compatibility, fleet status and memo. The current
  run is the highest numbered one, as in PHP.
- **Envelopes.** The debug view's `control_plane` names the
  `debug_workflow` operation with PHP's success fields. The run list and its
  missing-workflow refusal carry PHP's `list_runs` envelope.
- **Still different.** Native records no command context, so describe's
  commands lack PHP's caller, principal, auth and request fields, and
  Waterline's timeline shows no principal. Native query responses are not yet
  PHP's, and native does not refuse a query when no worker can serve it.

October 21, explicit run deadlines:

- **Found by.** Comparing describe responses showed native's default
  deadlines (#574). Reading published PHP's handling of explicit ones showed
  a second difference.
- **Before.** Native refused every claim, signal, activity result,
  heartbeat and timer on a run past its execution or run deadline with
  `run_timed_out`. It never closed the run, so the run stayed open with
  work it could not accept.
- **Published PHP.** `DefaultWorkflowTaskBridge::complete` calls
  `WorkflowExecutor::timeoutIfDeadlineExpired`. PHP applies a deadline when a
  workflow task completes after it, and nowhere else. `timeoutRun` cancels
  the run's other open tasks, its open activities and its timers. It records
  a `timeout` failure and fails the run with `closed_reason` `timed_out`. It
  records `WorkflowTimedOut` with the timeout kind, message, exception class
  and deadlines, closes pending updates, completes the task and resolves a
  parent's child call as failed. The worker gets 409 with `recorded: false`
  and `reason: run_timed_out`.
- **Source.** Native does the same. Deadlines no longer refuse other work,
  so a run past its deadline still takes signals, activity results and timer
  fires until its next workflow task completes.
- **Evidence.** A probe of published PHP and native, with a 2-second run
  timeout and a workflow task completed after it, gives the same response,
  run state, history and `WorkflowTimedOut` payload. The only difference is
  native's narrower server capability list, the same as on every worker
  response. New `run_deadlines` tests cover the timeout with an open
  activity and timer, and an in-time completion, on SQLite, PostgreSQL and
  MySQL.
- **Still different.** Describe omits PHP's terminal failure fields
  (`error`, `failure`, `exception`, `failures`) for every failed run, not
  only timed-out ones.

October 21, scheduled run summaries:

- **Found by.** Comparing the operator dashboard with published PHP. A run
  started by a schedule trigger had no run summary while it was open, so
  dashboards and summary-backed views missed it.
- **Cause.** The summary projection treats an unrecognized history event as
  unsupported. The run's own `ScheduleTriggered` marker was not recognized.
- **Source.** The marker opens no wait and is now accepted. The schedule
  trigger test asserts that every run has its summary; without the change it
  fails.

October 21, Waterline reads:

- **Found by.** Waterline 2.12.0 service mode in front of published PHP
  2.5.14 and in front of native, with identical seeds. On native, the run
  debug view failed, the cancelled and terminated filters were refused, the
  task queue pages were empty and the run detail page reported durable
  streams as unavailable.
- **Run debug view.** `GET /api/workflows/{id}[/runs/{run}]/debug` follows
  PHP's `WorkflowRunDiagnostics::forRun`: summary, worker fleet, tasks with
  their problem badges, pending activities, queue, last event, recent
  failures and routing findings. The last event's payload appears only when
  `include_last_event_payload` asks for it, re-encoded as PHP's
  `json_encode` writes it.
- **Workflow list.** The list is scoped to the request's namespace in named
  namespaces too, and accepts `cancelled` and `terminated` filters by exact
  status.
- **Task queues.** `GET /api/task-queues` and `GET /api/task-queues/{name}`
  follow PHP's `TaskQueueController`: pollers, stats, current leases, repair
  candidates, recent task flow, activity attempt states and admission.
- **Durable streams.** The run's stream list, stream description, item
  reads, appends and closes answer as PHP does, with the
  `workflow-stream-writes` fixture recorded from published 2.11.1: offsets
  from 0, idempotent retries, bounded waits, final closes, whole-second times
  and every refusal body. The shared contract fix of Server #774 is native
  too:
  - `max_pending_items` is at most 10000 and `retention_seconds` at most
    2592000, both refused with 422;
  - a batch whose fresh items would pass the cap is refused whole, so
    nothing of it is written (published 2.11.1 committed the items before the
    cap; the fixture bounds that answer as a published defect);
  - a closed stream past its retention reads as not found, append
    included, and keeps its row until its run is pruned, so the name is
    never opened again;
  - each deadline is a row in the native-owned `dw_stream_reclaims`
    (schema 6), indexed `(namespace, reclaim_at, stream_id)`. A bounded pass
    reads due streams from that index, from the worker-heartbeat retention
    pass and from a general `POST /api/system/retention/pass`. It deletes
    items in batches under the transition lock and stops blocked when
    items hold external references, which native does not delete yet;
  - run retention deletes a pruned run's streams unless they hold external
    references, and an append to a run whose details are pruned answers
    404 `stream_not_found`.
  A continued run keeps its streams, as in PHP. Native differs in three
  places, documented in `rust/src/runtime/workflow_streams.rs`:
  - a batch whose items hold no item fields gets 400, not PHP's 500;
  - a read at the head never clears the count of a later append;
  - a runtime external payload reference object in `payload_reference`
    gets 501.
  Still missing: the workflow-side `workflow_stream` command, so cluster
  info does not advertise `workflow_streams_contract`, and takeover still
  refuses a PHP database that holds streams.
- **Last progress.** Runs record `last_progress_at` when they start, as PHP
  does at start and child start.
- **Deliberate differences.**
  - Native bounds pending queries at 16 per queue, so task queue admission
    reports that bound where PHP reports 1,024.
  - Native tasks carry no queue-driver dispatch metadata. For tasks native
    created, the recent added and dispatched counters are zero, and the debug
    view's dispatch-overdue and transport states differ.
- **Evidence.** Debug views of seven run states match PHP except for the
  differences above, history payload content and key order, and identifier
  case. Task queue views differ only in the bound and counters above. Sixteen
  stream requests, including validation edge cases, match PHP after native
  adopted PHP's `integer` rule, and Waterline's stream panel is identical.
  Locally, 37 parity fixtures pass on published PHP, embedded and native.
  `visibility-current-runs` needs CI's published CLI artifact and was not run.
- **Next.** The describe response lacks PHP's `actions` block, so Waterline
  disables cancel, terminate, signal and repair on native. History export and
  the bounded dashboard summary remain.

October 21, starts without timeouts:

- **Found by.** Comparing describe responses in the Waterline comparison.
  For a start that names no timeouts, published PHP reports null timeouts
  and deadlines. Native reported a 3,600-second execution timeout and a
  600-second run timeout.
- **Impact.** Native refuses every command on a run after its deadline. A
  workflow started by an SDK without explicit timeouts, which is the default,
  stopped accepting work after ten minutes.
- **Source.** As in PHP's `DefaultWorkflowControlPlane::start`, an absent or
  null timeout now means none, and that run has no deadline. Each deadline
  follows its own timeout from the start time; the run deadline is no longer
  clipped to the execution deadline. Schedule-started runs use the same path.
- **Evidence.** New tests cover absent and null timeouts, independent
  explicit deadlines and invalid values on SQLite, PostgreSQL and MySQL.
- **Still different.** PHP closes a run whose explicit deadline has passed as
  failed with `timed_out` and records `WorkflowTimedOut`. Native still only
  refuses further commands on such a run.

October 20, workflow task lease:

- **Source.** Native leased a claimed workflow task for a fixed 30 seconds.
  Published PHP leases it for `DW_WORKFLOW_TASK_TIMEOUT`, 60 seconds by
  default, on both claim and heartbeat; 2.5.14 and 2.9.8 measured 59.9 and
  60.0 seconds. Native now reads the same setting with the same default. The
  activity lease stays at PHP's fixed five minutes.
- **Checks.** The `worker-replacement-activity-lease` contract now requires
  a claimed workflow task's lease to last about 60 seconds. A native test
  checks claim and heartbeat on each database. Locally, published PHP and
  native both pass the contract.

October 21, schedule listing, updates and backfill:

- **Listing.** `schedule_list.rs` serves `GET /api/schedules` as PHP's
  `ScheduleController::index` does:
  - the `status`, `workflow_type`, `query`, `page_size` and
    `next_page_token` filters, with Laravel's rules and messages and PHP's
    refusal bodies, including `last_safe_cursor`;
  - the visibility query from `ScheduleVisibilityQuery`, which accepts only
    equality predicates joined by `AND`. It works on ScheduleId, Status,
    WorkflowType, TaskQueue, Note and registered search attributes, with
    PHP's literal rules. A search attribute predicate matches no schedule,
    because native schedules hold no search attribute values.
  - continuation tokens signed as PHP signs them, with `DW_SERVER_KEY`,
    then `APP_KEY`. When neither is set, PHP generates a key for each
    container; native uses its auth token instead, so tokens survive
    restarts and work on every node. Each token is bound to its namespace,
    filters and a cursor that must still be present.
  - newest-first order, with PHP's list items.

  The SQL is assembled from fixed fragments with bound values for each
  database family.
- **Updates.** `PUT /api/schedules/{id}` follows `ScheduleManager::update`:
  the action is merged with the stored one, the quota is recomputed, the
  next fire time is recalculated, and `ScheduleUpdated` carries PHP's
  changed fields.
- **Backfill.** `POST /api/schedules/{id}/backfill` enumerates every
  occurrence from the start time up to, but not including, the end time,
  and triggers each one as `triggerForBackfill` does:
  - no stale check;
  - buffering policies start every occurrence;
  - a fired occurrence is skipped;
  - the run quota ends the backfill.

  Fire times carry the offset PHP formats: the schedule zone's for a cron
  occurrence, UTC for an interval. The Laravel `date` rule reads ISO-8601
  dates and date-times exactly as PHP does, including hour 24, second 60,
  fractions and offsets.
- **Deliberate differences.**
  - Backfill times use ISO-8601 only. PHP's other `strtotime` forms, such as
    `2026-04-01 +1 day` or `@1700000000`, get the `date` validation message.
  - A backfill range with more than 10,000 occurrences is refused with 422
    `backfill_range_too_large`. PHP has no limit.
  - An update to a specification that cannot be evaluated is a 422. PHP
    leaves that `LogicException` unhandled and returns 500.
- **Corrected.** Laravel sizes a non-numeric value under a numeric rule by
  its length, so `page_size=x` reports only the integer rule. The shared
  rules engine now does the same.
- **Evidence.**
  - `cron-oracle/backfill-dates.php` records PHP's `date` rule and instant
    for 700 seeded strings. Native accepts nothing PHP refuses, reads every
    accepted string to the same microsecond, and reads every ISO-8601 shape
    PHP accepts, except offsets of a day or more.
  - The next-fire corpus now also records PHP's offset, and native matches
    all 600 cases.
  - The new `schedule-catalog` fixture sends 72 requests. Published PHP and
    native agreed on every normalized response locally, and a reviewed copy
    is in `tests/Fixtures/ServerParityExpected`.
  - A PHP-on-PostgreSQL harness matched native on 81 responses, except the
    two deliberate differences above.
  - Schedule tests pass on SQLite, PostgreSQL, MySQL 8.4 and MariaDB 10.11.
- **Next.** Narrowing the takeover schedule refusal (#568) to what native
  still cannot run.

October 21, PHP schedule specifications and policies:

- **Cron.** `schedule_cron.rs` ports `dragonmantank/cron-expression` 3.6.0 as
  published PHP 2.5.14 runs it: validation, aliases, names, ranges, steps,
  lists, `L`, `W`, `#` and `?`, the separate day-of-month and day-of-week
  searches, the 1,000-step limit and each field's quirks. PHP's `DateTime`
  rules around daylight-saving changes are kept: a skipped wall-clock time
  takes the offset from before the change, a repeated one keeps the current
  daylight flag, and hour and minute steps are applied in UTC. Zone rules come
  from `jiff`'s bundled IANA data (2026e). Ireland and Morocco use negative
  daylight saving in the main IANA data PHP bundles, so PHP's flag is
  inverted there.
- **Zones.** `schedule_zones.rs` reads names as `new DateTimeZone()` does:
  numeric offsets (`+5`, `-0330`, `GMT+1`), PHP's 144 abbreviations first
  (`CET` is fixed +01:00) and then IANA identifiers in any case, with matched
  parentheses. Offsets of 26 hours or more, which PHP accepts up to 100, are
  refused.
- **Specifications.** `schedule_spec.rs` is `computeNextFireAt`: the earliest
  occurrence across cron expressions in the zone and intervals counted from
  the epoch plus an offset, with PHP's `DateInterval` grammar and its
  365-day year and 30-day month.
- **Corpora.** `scripts/conformance/cron-oracle` regenerates, from the frozen
  image, 1,200 cron cases, every PHP zone spelling (1,068 lines) and 600 whole
  specifications. Native matches all of them, and 6,000 and 2,000 more seeded
  cases checked locally. Six zones whose rules changed after PHP's 2026.1
  database are left out (Casablanca, El Aaiun, Edmonton, Inuvik, Vancouver,
  Winnipeg); native uses the newer rules.
- **Schedules.** Creation follows PHP's Laravel rules and messages (a small
  `laravel_rules.rs` engine: trimming, empty strings as null, `*` expansion,
  attribute names and order). It accepts any schedule id, cron and interval
  specifications in any zone, all six overlap policies, jitter, any run
  quota, memo, note and creation paused. Descriptions, history snapshots and
  history pages have PHP's shapes and timestamps. Pause accepts an
  already paused schedule and records its note; resume records its note
  after the resumed audit. Triggers follow `triggerDetailed`: the stale and
  status checks, quota exhaustion, buffering with a full `buffer_one`,
  closing the latest run for `cancel_other` and `terminate_other`, the
  ten-entry recent actions, failure accounting and the next fire time after
  each fire. The tick drains buffered actions of finished runs before due
  schedules. Schedules are namespace-scoped and start runs in their own
  namespace.
- **Deliberate differences.** PHP's unhandled 500 for resuming an active
  schedule is a 409 `schedule_not_paused`. A tick that finds another native
  node already fired the occurrence skips it silently instead of recording
  `stale_occurrence`. Plain JSON array input and search attributes are
  refused, as native start refuses them; Avro input that does not decode is
  refused at creation.
- **Evidence.** The new `schedule-policies` fixture sends 60 requests to a fresh
  namespace and compares every response, without generated identities and
  times, with a reviewed copy of published PHP's
  (`tests/Fixtures/ServerParityExpected`). Published PHP, embedded and native
  passed it locally, and native from main failed it. Published PHP on SQLite
  can fail a trigger with `database is locked` against its own maintenance
  roles, and the transaction rolls back, so the probe retries exactly that.
  The two existing schedule fixtures still pass. A local harness running PHP
  on PostgreSQL matched native on all 72 compared responses except the two
  refusals above.
- **Takeover.** The takeover schedule check from #568 now refuses only what
  native still cannot run: search attributes, visibility labels, Laravel
  queue routing, an unknown status, a definition native's own creation
  refuses (such as input that is not a payload envelope), and a
  specification it cannot evaluate. Cron schedules, schedules in any
  namespace, notes, memo, buffered actions, jitter, quotas and every
  overlap policy are taken over.
- **Next.** Update, listing with visibility queries and page tokens, and
  backfill.

October 21, signals before any worker registers:

- **Found by.** Waterline 2.12.0 service mode was compared against
  published PHP 2.5.14 and against native with identical seeds. A signal
  sent to a run that no worker had picked up was accepted by PHP and
  rejected by native with 404 `unknown_signal`, so the signal was lost.
- **Cause.** Native records a run's command contract at start only when a
  worker for the type has registered. It then treated a missing contract as
  one that declares nothing, and its recorded contracts lacked PHP's
  `declared_entry_*` fields.
- **Source.** Admission now follows PHP's `signalAdmissionForRun`:
  - A signal is unknown only under a complete durable contract.
  - A run without one admits every signal (`external_contract_unavailable`).
  - A declared signal without a parameter contract takes its arguments as
    given.
  - The runtime message-stream signal name is refused with 422
    `runtime_reserved_signal` before admission, whatever the run's state,
    and no command is recorded.
  - A signal without `input`, or with null input, has no arguments.
- **Contracts.** Recorded contracts now carry PHP's entry defaults (`handle`,
  `canonical`, `external:<type>`), so they are complete.
- **Responses.** Signal responses carry `workflow_type`. The `control_plane`
  envelope projects exactly PHP's `PROJECTED_FIELDS`, and cancel and
  terminate responses now carry it.
- **Evidence.** The new `signals-before-worker` fixture sends five signals,
  the reserved name before and after termination, and a missing workflow to
  a run on an unserved queue. Its responses match a reviewed copy of
  published PHP's, and its history holds the same events. Published PHP,
  embedded and native passed it locally; native from main failed it.
- **Next.** Durable rejected updates and PHP's query routing refusals, which
  the same comparison found.

October 21, takeover refuses schedules native cannot run:

- **Found by.** A review of takeover against native's schedule support.
  Native's scheduler fires only default-namespace schedules with one UTC
  `P1D` or `PT2S` interval, the skip policy, no jitter and at most one run.
  Once due, a PHP cron schedule taken over would have failed every
  scheduler tick (native refuses its next occurrence), holding back the
  schedules due after it. A named-namespace one would have silently stopped
  firing.
- **Source.** Every upgrade inspection now reports `schedule_inspection`: the
  live schedules and, by reason, how many are outside native's subset. The
  reasons are native's own schedule-creation refusals plus namespace, status,
  memo/search attributes/labels, note, buffered actions and queue routing,
  which native would not describe. Any such schedule makes the inspection
  `unqualified`, and takeover refuses with
  `upgrade_source_schedules_unsupported` before the fingerprint check or any
  write. Deleted schedules do not count.
- **Tests.** SQLite: a cron schedule and a named-namespace schedule each refuse
  takeover and leave the file byte-identical; a runnable schedule and a
  deleted cron schedule convert; inspection counts reasons without schedule
  names or memo values. PostgreSQL 17, MySQL 8.0 and MariaDB 10.11 takeover
  qualification each refuse the cron schedule with the source unchanged.
- **Next.** Native schedule parity (cron, time zones, overlap policies,
  jitter, quotas, metadata, namespaces) narrows this refusal.

October 20, concurrent MySQL and MariaDB bootstrap:

- **Found by.** A multi-node smoke run started three nodes together on an
  empty MySQL database. One node exited with `409 unsupported_rust_schema`.
- **Cause.** MySQL commits DDL on its own. Between one node creating
  `dw_server_schema` and recording its `rust-initializing` marker, another
  node's read-only preflight, which runs before the bootstrap lock, saw an
  empty marker table and refused. A node killed in the same window left a
  database that no node could start on. PostgreSQL bootstraps inside one
  locked transaction and SQLite in one file transaction, so neither has the
  window.
- **Source.** Preflight accepts an empty marker table when it is the only
  table, and the node then waits for the bootstrap lock. Under the lock, a
  missing marker is recorded before the migration runs.
- **Checks.** The kill qualification has a `marker-table` phase. It kills a
  bootstrapping child right after it creates the marker table and holds the
  bootstrap lock. A concurrent start must still be waiting after one second,
  then complete once the child is killed. Without the preflight change, it
  refused at once. All 15 ignored MySQL qualification tests passed on MySQL
  8.0 and MariaDB 10.11 locally.

October 21, optional caller trace context:

- **Why.** PHP Server's optional tracing ([#558](https://github.com/durable-workflow/server/pull/558),
  Workflow #773) admits a top-level `observability_context` on start, signal,
  update, cancel and request-cancellation requests when it is enabled. PHP
  with tracing disabled, and published PHP 2.5.14, ignore the field. Native
  refused it with 422 `unsupported_request_field`, so a caller that sent it
  without checking discovery would have its business request refused.
- **Source.** Those native handlers now drop exactly that top-level field
  before reading the request. It is not validated, stored, logged or
  compared, so a retried start with another trace context is the same
  request. Native never advertises
  `worker_protocol.server_capabilities.observability_context`. Terminate,
  every other route, the same name anywhere else in a body, and any other
  unknown field keep their existing handling.
- **Test.** `trace_carriers` sends each of the nine affected routes with and
  without a carrier to two identical workflows. It checks equal outcomes,
  equal history event types, no stored trace identifier, the refused
  terminate carrier and a refused unknown field beside the carrier. Removing
  the change from any one of the nine handlers fails the test.
- **Takeover.** Decided on [#325](https://github.com/durable-workflow/server/issues/325#issuecomment-6306545520):
  a PHP 2.10 database with Core 2.9's nullable `observability_context`
  columns is refused until its published layout is recorded. Its recipe will
  count and then drop the three columns, like 2.9.7's `queue_delivery_reference`.

October 20, external payload storage diagnostic:

- **Source.** Native serves `POST /api/storage/test` for operators, in the
  default or a named namespace, as PHP's `StorageController::test` does:
  - Laravel's validation messages and error counts for `driver` and the two
    payload sizes (shared with the search attribute registry through
    `laravel_validation`);
  - `external_storage_not_configured`, `external_storage_disabled` and
    `storage_driver_unavailable` refusals with PHP's bodies;
  - a small and large generated payload written to, read back from and
    removed from the local driver, with each one's size, SHA-256 and the
    digest of its object URI.
- **Shared objects.** Objects are content-addressed, so a diagnostic payload
  can have the same bytes as a registered external payload. Native holds the
  payload object lock across the round trip and removes the object only when
  no registered payload refers to it. PHP removes it unconditionally.
- **Not covered.** S3, GCS, Azure and custom drivers are refused as
  unavailable in this runtime, as published PHP does without their SDKs.
- **Fixture.** `storage-test` drives a fresh namespace through no policy, a
  disabled policy, another driver, three invalid bodies and two passing round
  trips. Published PHP 2.5.14 and native returned identical bodies, including
  the reference identities, and embedded PHP declares it inapplicable.

October 20, database TLS settings (PHP Server 2.9.10, #545):

- **Source.** Native resolves PostgreSQL, MySQL and MariaDB TLS with
  `database_tls`, the rules of PHP's `DatabaseTlsConfiguration`:
  - `DB_SSLMODE`/`DB_SSL_MODE` and `DB_SSLROOTCERT`/`DB_SSL_ROOT_CERT`, plus a
    `DB_URL`'s `sslmode`/`sslrootcert`;
  - MySQL's mode names (`required`, `verify_identity`);
  - MySQL's legacy `MYSQL_ATTR_SSL_CA`.

  Refusals use PHP's messages, with reason `database_tls_configuration_invalid`:
  - conflicting aliases;
  - a URL that contradicts `DB_SSLMODE` or `DB_SSLROOTCERT`;
  - unknown modes, and MySQL modes other than disable, require and
    verify-full;
  - verification without a readable CA bundle;
  - a CA without a mode;
  - required TLS over a Unix socket.
- **Behavior change.** Native used to ignore `DB_SSLMODE` and
  `DB_SSLROOTCERT` when `DB_URL` was set. TLS now always comes from the
  resolved settings, and TLS spellings that only SQLx reads are removed from
  the URL first.
- **Startup.** Native refuses to start on an invalid configuration. When a
  connection that required TLS fails, it refuses with
  `database_tls_connection_failed`. PHP keeps serving and reports both as an
  unavailable readiness check. Readiness carries PHP's `tls` snapshot when the
  database is reachable, scoped `rust_to_configured_database_endpoint`.
- **Known difference.** With no configured mode, native MySQL uses TLS when
  the server offers it. PHP connects without TLS options. Both report the mode
  as unset.
- **Qualification.** `scripts/conformance/native-database-tls.sh` runs PHP's
  TLS cases against the same fixtures, with the database side confirming the
  transport.

October 20, lost workflow task claims:

- **Found by.** The [multi-node runs](../benchmarks/server-port/multinode/results-2026-10-20.md).
  A paused SDK worker resumed with workflow tasks the server had already
  given to the other worker. Native refused its late renewals and completion
  with only a reason (`task_not_leased`, `conflicting_completion`), and the
  worker exited 6 times. Published PHP's ownership guard also returns the task
  status and, after the run closes, `can_continue: false`. A current worker
  reads those fields to drop a lost claim and keep running.
- **Source.** Workflow task completion, failure, lease renewal and task history
  now share PHP's guard (`WorkflowTaskOwnership::guard`), in PHP's order.
  A task that is no longer leased is refused with `run_closed` when its run
  has closed, and otherwise with `task_not_leased` and its status. An open
  lease is then checked for expiry, owner and attempt. Each refusal has PHP's
  body: `error`, the reason's fields, `protocol_version` and
  `server_capabilities`. A completion that differs from the recorded one now
  gets this refusal instead of `conflicting_completion`. An identical replay
  still returns the recorded `already_completed` receipt. A completed workflow
  task now clears its lease expiry and keeps its owner, as PHP's does.
- **Fixture.** `late-workflow-completion` replaces a worker that holds a
  claim and sends the replaced claim's completion, renewal and failure while
  the new claim holds the lease, after it completes, and after the run
  closes. Published PHP 2.5.14, embedded and native passed in a local copy of
  the SQLite parity step. Before the change, native failed the same fixture.

October 20, search attribute registry:

- **Source.** Native serves `GET` and `POST /api/search-attributes` and
  `DELETE /api/search-attributes/{name}` for operators, in the default or a
  named namespace, as PHP's `SearchAttributeController` does. Its rules are:
  - request strings are trimmed;
  - Laravel's validation messages and error counts;
  - system names and their aliases are reserved;
  - a duplicate keeps its type;
  - `DW_MAX_SEARCH_ATTRIBUTES` (legacy `WORKFLOW_MAX_SEARCH_ATTRIBUTES`,
    default 100) limits definitions per namespace;
  - system attributes cannot be removed.
- **Concurrent registrations.** When two registrations of one name race, the
  unique key decides the winner. PHP surfaces that as a server error; native
  refuses the loser as a duplicate.
- **Not covered.** Native start and upsert still refuse search attribute
  values, so a definition is registry state only. PHP's enforcement of
  definitions on start, upsert and visibility filters needs its own fixtures.
- **Fixture.** `search-attributes` drives two fresh namespaces through the
  published SDK and raw requests. Published PHP 2.5.14, embedded PHP (which
  declares it inapplicable) and native passed the contract in a local copy of
  the SQLite parity step, and the comparison passed.

October 20, worker listing:

- **Source.** Native serves `GET /api/workers` and `GET /api/workers/{id}`,
  as published PHP's WorkerManagementController does:
  - the summary fields;
  - the `task_queue` and `status` filters, with stale workers hidden unless
    asked for;
  - the stale window;
  - `404 worker_not_found`.

  Operators may read these; only `DELETE` needs an admin. Named namespaces may
  read them.
- **Registration facts.** Registration and heartbeat now keep what PHP keeps:
  - capacities, defaulting to 100, 100 and 10;
  - task slots, bounded by those capacities;
  - normalized process metrics;
  - the heartbeat cadence;
  - workflow definition fingerprints.

  Re-registering an active worker with a changed fingerprint is refused with
  `409 workflow_definition_changed`. Omitting an advertised one is refused with
  `409 workflow_definition_fingerprint_missing`, and neither refusal changes
  the registration. Omitting the fingerprints keeps the advertised one. A
  heartbeat changes only what it reports. Its acknowledgement carries the
  configured cadence and stale window.
- **A replaced worker's activity leases.** A new process registering a
  worker_id that holds activity leases now releases them at once, as PHP
  does. Its running attempts close as `expired`, and the tasks return to
  ready, so the next claim is attempt 2 of the same task. This holds even under
  a one-attempt retry policy, since PHP checks no policy at claim time. Before
  this, native left the task leased for the five-minute activity lease. The
  expired attempt's completion now gets PHP's whole stale receipt:
  `409 stale_attempt`, `recorded: false`, and the activity, attempt and task
  statuses.
- **Fixtures.**
  - `worker-listing` registers two workers through the unchanged published
    SDK. It then lists, filters, describes and refuses them, and
    re-registers and deregisters them.
  - `worker-replacement-activity-lease` replaces a worker holding an activity
    lease, then completes the original and the replacement attempts.

  The contracts check each HTTP target against the reviewed facts. Embedded
  mode is explicitly inapplicable.
- **Local evidence.** A local copy of the SQLite parity step recorded
  published PHP 2.5.14, embedded PHP and native for both fixtures and
  `namespace-isolation`. All three targets passed and the comparison passed.
  Before the release, native failed the replacement fixture: its second claim
  found no task. Native integration tests pass on SQLite, PostgreSQL, MySQL
  and MariaDB.

October 20, immediate termination:

- **Source.** Native serves `POST /api/workflows/{id}/terminate` and its
  selected-run form. Published Workflow runs cancel and terminate through one
  `attemptTerminalCommand`, so native now does the same. Terminate records
  `TerminateRequested` and `WorkflowTerminated`, the `terminated` status,
  closed reason, failure category and propagation kind, and
  `WorkflowTerminatedException`. It cancels open tasks, activities and timers
  exactly as cancel does. A late activity outcome is refused with
  `run_terminated`. The response carries the `terminate` control-plane
  contract, which matches cancel's.
- **Refused.** Terminating a child run returns
  `422 termination_propagation_not_available` and changes nothing (see known
  gaps). The other propagation refusals are cancel's.
- **Fixtures.** `terminate-before-claim`, `terminate-pending-timer` and
  `terminate-leased-activity` reuse the cancellation workflows with
  `immediate_cancellation.command = "terminate"`. The shared contract and its
  runner unit tests check the termination identity in place of cancellation's.
- **Local evidence.** A local copy of the SQLite parity step recorded published
  PHP 2.5.14 over HTTP, embedded PHP and native for the three cancel and three
  terminate fixtures. All six passed on each target, and the comparison passed.
  Native integration tests for both commands pass on SQLite, PostgreSQL and
  MySQL.

October 20, namespace update:

- **Source.** Native serves `PUT /api/namespaces/{name}`, as published PHP's
  NamespaceController::update does. A description replaces the stored one
  only when given. Retention follows PHP's rules: a forever namespace becoming
  bounded needs `retention_days`; forever refuses days; bounded refuses null
  days; days run 1–365. A missing namespace is `404 namespace_not_found`.
  Only an admin may update a namespace. Native still has no retention pass,
  so the stored retention is not enforced yet.
- **Checks.** The `namespace-isolation` probe now updates its first
  namespace at the end through the published SDK, and updates a missing one.
  The contract checks both. Locally, published PHP 2.5.14, embedded PHP and
  native passed, and the comparison passed. A native test covers the
  retention rules on SQLite.

October 19, before #478, #476, #494 and #460 merged:

- **Open native PRs.** [#478](https://github.com/durable-workflow/server/pull/478)
  (run observation and cooperative cancellation), [#476](https://github.com/durable-workflow/server/pull/476)
  (run activity metadata listing) and [#460](https://github.com/durable-workflow/server/pull/460)
  (read-only inspection of an existing PHP database). All three are drafts.
  The latest merged native slice is [#470](https://github.com/durable-workflow/server/pull/470).
- **#478 source.** The branch now combines the October 18 head `543ed0485`, the
  separately prepared [independent child cancellation](contracts/child-independent-cancellation.md)
  component `15e14fb50`, current main `1682e1de` and a harness correction.
  Main brings PHP Server 2.9.4 and Workflow 2.6.2. It changes no native,
  shared harness or fixture path, and the shared matrix keeps its frozen
  published PHP 2.5.14, embedded and SDK actors.
- **#476 source.** The activity metadata listing now reads history, executions
  and attempts from one consistent read-only snapshot on every database family,
  takes the codec permit only after its reads, and gives a synthetic attempt no
  stop reason when its execution row is pending, as released `RunActivityView`
  does. Controls cover SQLite, PostgreSQL 17, MySQL 8.0 and MariaDB 10.11, and
  the projection corpus has a released-projector case for the pending row.
  Remaining listing gaps are the 413 read budget for long-heartbeat or wide
  runs, six-digit activity timestamps and NULL-row tolerance.
- **#460 source.** Read-only inspection of an existing PHP database now refuses
  PostgreSQL triggers and rules like other unknown catalog objects, measures
  every SQLite queue text value before transfer and refuses values over 4 KiB,
  bounds MySQL and MariaDB lock waits and statement time on the server, and
  reports the published payload tables it does not scan yet. It still grants no
  takeover, backup or quiescence claim.
- **Diagnosed failure.** Shared run [39092116973](https://github.com/durable-workflow/server/actions/runs/39092116973)
  failed `execution-and-codec` step 9 with exit code 255. The same step
  reproduced locally at `543ed0485` with the exact published image and failed the
  same way. `restart.php prepare` against the published PHP reference received
  the documented worker-poll refusal (HTTP 503, `backend_lock_pressure`,
  `task: null`, retry after one second) while that reference shared its SQLite
  file with the maintenance loop. The harness treated the refusal as fatal.
  Native preparation had passed. The harness now honors only that documented
  refusal, with bounded retries reported in the restart output.
- **Not yet diagnosed.** Earlier failed originals on this branch include six
  `Compare published query deadlines and installed Worker authority` jobs
  across MariaDB, MySQL, PostgreSQL and SQLite, three
  `Compare corrected markers, ranges, clocks and activity expiry` jobs and one
  MySQL three-runtime recovery job. The MySQL recovery job also runs
  `restart.php` against PHP, so it may share this cause. The others are
  timing-sensitive cohorts and need local reproduction before any change.
- **Combined checks so far.** 80 library tests (18 existing ignores) and 272
  SQLite execution tests pass. The new lock-pressure decision test passes.
  The full local step 9 run and the hosted six-database matrix for the
  combined head are recorded on #478.
- **Next action.** Qualify and merge #478. Then bring #476 forward with its
  review findings fixed (codec permit taken before reads, PostgreSQL
  statement snapshots, read budget, timestamp precision), followed by #460.
  Reproduce the timing-sensitive cohort failures locally or on disposable
  hosts until each has a diagnosed cause.

Current checkpoint, October 18:

- Separately prepared [independent child cancellation](contracts/child-independent-cancellation.md)
  is tested at `54bb8c61d`, runtime `024620b570757ae3d2142b01cbad74c674ea2911`
  and tests `37403b5d13c3fe9fa70c70670fd4e2b07e4551df`. An existing sequential
  child with both `abandon` policies can receive its own operator root request
  in the default namespace. Its root, lineage and original deadline are separate
  from its parent's request. Child completion, failure and maintenance expiry
  resolve the original parent atomically with the child's canonical cancellation.
  Actual ready/leased parent root tasks and their claims remain unchanged. Closed
  parents are not resumed, missing waits stay missing, and a forced parent-write
  failure rolls back both histories, the child failure and its original claim.
  Parent and child duplicate receipts retain their own identities and deadlines;
  duplicate child completions append no history and expired attempts stay fenced.
  Eleven focused controls, 80 library tests, all 272 SQLite execution tests and
  ten applicable controls on each of PostgreSQL 17.11 and MySQL 8.0.46 pass.
  The library retains 18 existing ignores; the isolated SQLite profile entry runs
  through its passing parent. The network selections exclude the separate
  SQLite-only forced parent-write control. The final SQLite, PostgreSQL and
  MySQL durations are 263.37, 16.95 and 31.77 seconds, respectively, without a
  performance claim. One identical test binary serves all three selections.
  Published PHP 8.3.35 / Workflow 2.5.32 matches 36 final captured wait states
  using 36 real projector updates: 20 SQLite, eight PostgreSQL and eight MySQL.
  The projector/model hashes match `78075b6f`; earlier focused captures overlap.
  First tests `b7213de21` have six harness compile errors, corrected before the
  runtime counterfactual. Tests-first `9bec16f64` then retains ten actual failures
  at the old incoming-link refusal and the passing unsupported-policy control.
  Initial implementation `c26b87d40` passes eleven focused and 272 SQLite tests.
  The assertion-only follow-up adds explicit parent receipts and duplicate child
  completions, then passes the final checks above with identical runtime bytes.
  Both network databases have zero test databases before clean SIGTERM/exit0
  without OOM. Formatting and diff checks pass. This source is not yet PR478's
  admitted head and does not explain its failed predecessors. No PHP/Composer,
  schema, shared profile, budget, frozen actor, preview or catalog adoption occurs.
  Combined hosted/analyzer, installed consumer, wider propagation and release
  gates remain separate; all stopped scopes stay stopped.
- PR478 now combines the accepted-update cancellation closure, sequential child
  abandonment and existing child-resume association components through `8dd8e899b`.
  Runtime `bfb7361d37e2027a978a398d9a59189b21dff6c0` and tests
  `c52c26e70169ccdf741ff328828a02326824abc2` are byte-identical to tested
  implementation `8e8a9b8fb`. The final local selection passes 80 library,
  261 SQLite execution and 23 applicable cooperative controls on each of
  PostgreSQL 17.11 and MySQL 8.0.46, with the exclusions below. This integration
  checkpoint changes only this log. Admitted head `543ed0485` has exact
  [PHP/source39092117015](https://github.com/durable-workflow/server/actions/runs/39092117015),
  [Sonar39092117005](https://github.com/durable-workflow/server/actions/runs/39092117005)
  and [boundary39092116998](https://github.com/durable-workflow/server/actions/runs/39092116998)
  PASS. Its Sonar app reports 1.2% new duplication under the unchanged 3% limit,
  with zero OPEN/CONFIRMED findings. The
  [shared39092116973](https://github.com/durable-workflow/server/actions/runs/39092116973)
  original remains pending at 03:26 UTC, with seven jobs SUCCESS and six live.
  Component checks do not establish its result, installed acceptance,
  independent review or promotion.
  Qualified stable main `964f9e3d5` and Workflow 2.5.32 remain the PHP source.
  General's preview #486 is separate and unmerged. No RC references, PHP or
  Composer changes, frozen tuples, profiles, budgets or expectations are adopted.
- Preceding head `3d68a5fe3` retains its terminal failed
  [shared run 39086884181](https://github.com/durable-workflow/server/actions/runs/39086884181):
  twelve jobs succeed and `execution-and-codec` job117557316718 fails at
  `Compare published query deadlines and installed Worker authority`. All
  thirteen originals are terminal. The assertion, target implementation/client
  and cause remain unknown. Its exact PHP/source, Sonar and boundary checks
  pass only at that head. The
  [terminal receipt](https://github.com/durable-workflow/server/pull/478#issuecomment-6244885792)
  preserves the failure. The later cancellation components neither explain it
  nor establish a fix. Remote native logs and artifacts remain unretrieved;
  the stopped inspector and installed observation recipe remain stopped.
- The [child cancellation resume association](contracts/child-cancellation-resume-link.md)
  is tested at `8e8a9b8fb`, runtime `bfb7361d37e2027a978a398d9a59189b21dff6c0`
  and tests `c52c26e70169ccdf741ff328828a02326824abc2`. A completed, failed or
  directly cancelled child retains its original wait and associates it with
  the actual existing ready or leased root cancellation resume. The task ID,
  payload, owner, attempt and expiry remain unchanged. A cold claim updates the
  associated status; missing waits stay missing. An update-only claim remains
  unavailable as a root resume, with the original deadline and accepted-update
  closure preserved. Ordinary parent conflicts and update admission are unchanged.
  Nine focused controls, 80 library tests, 261 SQLite execution tests and
  23 applicable cooperative controls on each of PostgreSQL 17.11 and MySQL
  8.0.46 pass. The library retains 18 existing ignores, and the isolated SQLite
  profile entry runs through its passing parent. Network selections exclude
  three separate SQLite-only write faults. The new forced association-write
  failure rolls back both histories, child call/outcome/failure and the original
  parent claim. Published PHP 8.3.35 / Workflow 2.5.32 independently matches
  31 final wait snapshots using 31 actual projector updates in a separate
  migrated in-memory database. Projector and wait-model hashes match `78075b6f`;
  the reference script is committed. Earlier focused captures overlap these
  final states and are not additional distinct qualification.
  Tests-first `dd8b8bda6` retains seven actual failures and the passing missing-row
  control. Candidate `d502287fa` retains six passes and three fixture failures:
  a new cancel command on a closed child correctly refuses `run_not_active`,
  and an update requested while a child is open correctly refuses
  `update_snapshot_busy`. The corrected control accepts its update at the
  supported signal wait before starting the child from that wait's real timer
  resume. Runtime bytes are unchanged by those fixture corrections. Both final
  network databases have zero test databases before clean SIGTERM/exit0/noOOM.
  This is scoped source/model validation, separate from failed admitted `3d68`
  and the new combined hosted qualification.
  No PHP/Composer/schema, shared runner/profile, frozen actor, budget, catalog,
  stopped recipe or held-major adoption occurs. General's separate PHP Nexus
  preview window awaits its own exact qualified handoff before combination.
- The [cooperative child abandonment](contracts/cooperative-child-abandon.md) component
  is tested at `3f87a8524`, runtime `9cd7e90357caf9ea30d0a7cb0756c6935e85d732`
  and tests `694ba3b8d5f0163802ca97ef6dc52dfa96f71d80`. The existing
  default-namespace sequential child stays independent when its parent finishes
  cooperative cancellation. Its original worker, attempt, lease expiry, claim
  payload and typed result survive parent completion, failure and deadline
  cleanup. The canonical `child` delivery rejects a resolution recorded before
  the original root request, permits a later resolution, and reuses the existing
  parent cleanup task. Incoming child cancellation and other policies remain
  refused. Ordinary completion still guards pending durable operations.
  Tests-first `71eee90e6` retains six actual failures at the old link refusal.
  Implementation `3f87a8524` passes seven focused controls, 80 library tests with
  18 existing ignores, 252 SQLite execution tests with the isolated profile entry exercised
  by its passing parent, and fifteen applicable cooperative controls each on
  PostgreSQL 17.11 and MySQL 8.0.46. The network selections exclude two separate
  SQLite-only write-failure controls. Published PHP 8.3.35 / Workflow 2.5.32
  independently matches all 26 final canonical delivery/policy captures with
  zero database queries, using the committed history-only model reference.
  Its validator, child policy and models match published source `78075b6f`.
  Review follow-up `da16ffbc9`, tests `1e9426a379743e07d331c7548e1d6282e5ef47d4`,
  replaces two absent-field comparisons with seven named summary fields and
  explicit presence checks. All seven focused controls pass again. Production
  runtime and published-PHP model bytes remain identical to `3f87a8524`.
  The first network selections each retain eleven passes and four failures in
  the new snapshot fixture's integer decoder. Explicit test-query casts correct
  that fixture; runtime logic is unchanged. The first PHP invocation stopped
  before comparison because public history omits event IDs; the reference now
  reads captured stored IDs. Review `304d2e099` retains six passes and one failed
  assumption that a present summary timestamp could not be null. The follow-up
  preserves nullable values while checking actual field presence. These unsuccessful selections remain separate from
  the final results. Both final network databases have no test databases before
  clean SIGTERM, exit0 and no OOM. Formatting and diff checks pass.
  This component result is separate from admitted `3d68a5fe3`; combined hosted/analyzer,
  installed SDK, wider propagation, review and release gates remain open. No
  package, schema, frozen tuple, runner/profile, budget or observation recipe
  changes.
- Cooperative-update implementation `cff0fd253`, runtime tree
  `e5c31381ba0eefa83bb8bbc90102fdbbea48ecc4` and tests
  `8b17933aadff7893d92ba0e9139f9fb39de6d1c6`, closes accepted updates when an
  otherwise supported cooperative root cancellation finishes. Its existing
  closer runs after WorkflowCancelled in that same transaction. Completion and
  failure retain root cancellation precedence and the actual terminal task
  snapshot. Maintenance uses the original deadline without inventing a task.
  That update-only component leaves children, active activities, open-wait
  request guards and update admission unchanged. The tests-first `a5860b284`
  retains four actual failures at
  the preceding accepted-update refusal. Final source passes all four focused
  controls, 80 library tests with 18 existing ignores, and 245 SQLite execution
  tests with the isolated profile entry exercised through its passing parent.
  PostgreSQL 17.11 and fresh MySQL 8.0.46 each pass sixteen actual controls,
  excluding both separate SQLite-only write-failure controls. Root identity and
  deadline, original update/command/message identities, cold receipts, stale
  attempts, terminal order and forced-write rollback of both outcomes and summary
  are checked. Published PHP 8.3.35 / Workflow 2.5.32 independently matches 26
  captured closer outcomes, including focused/full selection overlap, using
  390 reference queries. The closer, executor and history model match exact
  published source `78075b6f`. Formatting and diff checks pass.
  An earlier candidate retained incorrect expectations that delivery fields
  stayed null and that the first post-reopen read waited for maintenance. Those
  fixture corrections leave the runtime unchanged. A separate incomplete build
  lacked its sibling fixture bind. Neither is a passing original. The first
  512 MiB MySQL database passed nine cooperative controls, then Docker recorded
  OOM/exit137 and the terminal group failed with two passes and five connection
  errors. The fresh sixteen-case result uses a 768 MiB database with unchanged
  binary, deadlines and assertions; it does not rescue that failed original or
  explain the unrelated hosted MariaDB failure. The two final databases contain
  no test databases before clean SIGTERM and exit0 without OOM. This remains
  a component result rather than later whole-head qualification.
  Combined hosted/analyzer, installed SDK, wider propagation and release gates
  stay open. No schema, package, frozen tuple, shared profile or budget changes.
- The preceding `3d68a5fe3` integrated named-signal source `d449fca29`, retaining
  runtime tree `33a92133f1926b0f0e11163af082e5f7c0a6c23c` and tests
  `c3cdccc984a1447956e1d5346596b00786a82bec`. The separately recorded local
  component controls below cover that code. Named ordinary/timed signal waits
  and the lifetime correction remain in the new candidate. The terminal combined
  result above retains its failure under unchanged profiles and budgets.
- The preceding `b025c8c30` original
  [shared run 39082779792](https://github.com/durable-workflow/server/actions/runs/39082779792)
  is terminal FAILURE: twelve jobs pass and one MariaDB job fails. Metadata
  identifies its failed step as `Compare published query deadlines and installed
  Worker authority`. The failing assertion, implementation/client and cause are
  unknown. Its TLS verification is skipped, not passed. Native bootstrap/source,
  the ordinary three-runtime/recovery comparison and the other listed comparison
  steps pass in that job's metadata. Those narrower results do not override the
  failed qualification. PHP/source, Sonar and boundary checks pass only at b025.
  The [failure receipt](https://github.com/durable-workflow/server/pull/478#issuecomment-6244205805)
  and original remain retained. Neither a source fix nor recovery of that failure
  is claimed by the later namespace component.
- Preceding observation head `0c8eafc9` retains its original
  [shared run 39078215426](https://github.com/durable-workflow/server/actions/runs/39078215426),
  now terminal with all thirteen jobs passing. Its exact PHP/source, Sonar
  app/workflow and public boundary also pass. This is a job/check metadata
  readback, without remote log or artifact retrieval.
- The b025 candidate integrated separately prepared failure and terminal-update
  source from `1540bd0c4`, runtime tree
  `2ec0d0e9c98dfb2e0e22fdc1b15b9d849f9c4397`. Its component results below
  remain scoped to that source; the failed combined qualification above remains
  separate from local controls and the earlier 0c8 pass.
- The prepared named-signal component admits a single ordinary
  `open_signal_wait` command with an absent or null timeout in named namespaces.
  Non-null signal timeouts and completions combining a named signal wait with
  another command retain their existing refusal.
  Tests-first `63f55fd50` fails both new positive controls with the original 422
  admission refusal while preserving the batch-refusal control. Final tested
  `9dc90d50e`, runtime tree `4179c82b858f00f2a249b42a7d110cacba7b5313`,
  passes 80 library tests with 18 existing ignores and all 236 SQLite execution
  tests. The isolated profile child retains its direct-entry ignore and runs
  through its passing parent. PostgreSQL 17.11 and MySQL 8.0.46 each pass seven
  applicable controls, excluding the separate SQLite-only projection trigger.
  Buffered and later signal delivery preserve their original wait, signal,
  command and history identities across cold pools. Typed int64, Unicode, null
  and false survive application. Another namespace cannot signal, complete,
  observe or poll the original work. Duplicate completions add no history.
  Published PHP 2.8.7 / Workflow 2.5.32 independently matches eleven counter
  snapshots, six liveness classifications and four open-wait views per database,
  with zero reference queries. Formatting and diff checks pass. The component
  also removes the valid redundant `PHP_CONNECTION` trait lifetime reported on
  `b025c8c30`; fresh analyzer disposition remains pending. Combined hosted
  qualification, installed native/browser acceptance and release gates stay
  separate. No package, schema, catalog or frozen fixture changes are included.
- The next prepared namespace component extends the same single signal wait to
  its existing zero/positive timeout contract. A named signal wait must remain
  the sole command in its completion. Timer maintenance already uses the run's
  stored namespace; only the
  admission guard changes. Tests-first `ce1ffe993` retains five actual failures
  at the old named-command refusal. Tested `56f19d543`, runtime tree
  `33a92133f1926b0f0e11163af082e5f7c0a6c23c`, passes 80 library tests with
  18 existing ignores and all 241 SQLite execution tests, with the isolated
  profile entry exercised through its passing parent. Fourteen actual network
  controls pass on each of PostgreSQL 17.11 and MySQL 8.0.46; both separate
  SQLite-only forced-write controls are excluded from those totals.
  Immediate/durable firing retains the original timer, wait, resume identity and
  planned deadline across cold pools. Buffered input creates no timeout, while
  later signal application cancels the original timer and preserves typed values.
  Foreign namespaces cannot claim or complete the resume. Invalid timeouts and
  failed projection writes leave the original attempt usable. Duplicate
  completions add no history. Independent published PHP 2.8.7 / Workflow 2.5.32
  agrees on counters and wait projections across 60 captured snapshots, including
  37 active classifications and 20 open-wait views, with zero reference queries.
  Twelve of those active cases also pass the separate external-wait classifier.
  Formatting and diff checks pass. This source includes the earlier untimed and
  lifetime corrections without carrying their earlier qualification forward.
  Combined hosted, installed native/browser, broader named signal-wait batches
  and release gates remain separate; no package, schema, catalog or frozen tuple
  is adopted.
- The preceding observation head `a15b87977` has all 13 jobs passing in
  [shared run 39072054431](https://github.com/durable-workflow/server/actions/runs/39072054431),
  plus its exact PHP/source, Sonar and public-boundary checks. Newer prepared
  source includes separately qualified PHP main `964f9e3d5`, timed waits and
  activity/timer barriers. Its local component results below do not carry the
  a15 result forward. Fresh combined hosted qualification is required.
- Query authority is merged at `0934c46d`, whole tree `98775e68`. Its separate
  [merged-main qualification](https://github.com/durable-workflow/server/actions/runs/38738455163)
  passes. That result applies to the query tree.
- Targeted activity-result inspection is merged through #470. Candidate
  `512ba635c`, whole tree `9249997b`, matches the tested synthetic merge tree.
  [All 13 shared jobs](https://github.com/durable-workflow/server/actions/runs/38762453794)
  pass. The recorded six-artifact audit confirms 29 passing comparisons,
  20 successful profile statuses and 129 native execution tests per database
  configuration. SQLite also records 77 library passes with the existing
  18 explicit ignores. The separate main run above remains its own gate.
- [Metadata-only activity listing #476](https://github.com/durable-workflow/server/pull/476)
  is still a draft. Its earlier [13-job qualification](https://github.com/durable-workflow/server/actions/runs/38773360007)
  passes at tested merge `146e27a6`, tree `34a0caeb`. Independent readback of
  all six retained summaries confirms 29 passing comparisons, 20 zero-exit
  profile statuses and 133 native execution tests per database configuration.
  SQLite also records 78 library passes with 18 explicit ignores. The installed
  SDK consumer then completed 24 originals on independent published PHP and
  native databases across all six configurations and two namespaces, with
  96 matching metadata snapshots. These use public SDK task primitives, not
  workflow callbacks. The integrated `d30a7687` candidate has a separate
  [qualification run](https://github.com/durable-workflow/server/actions/runs/38787311689)
  with all 13 jobs passing. Its independent retained-summary review remains
  open. Earlier unsuccessful originals remain unsuccessful.
- [Read-only upgrade inspection #460](https://github.com/durable-workflow/server/pull/460)
  remains a draft at `7066a50e`. Qualification and review are incomplete, so
  the candidate is not being promoted. No backup, migration or takeover
  authority follows from inspection.
- [Waterline consumer #477](https://github.com/durable-workflow/server/pull/477)
  provides the separate real-backend browser recipe. Forty published-PHP
  selections pass across five values, two namespaces, two widths and both
  themes. Its [profile](../scripts/conformance/activity-result-browser/profile.json)
  pins the published Server, Waterline, SDK and browser identities. The
  observation includes forty selected result requests, eighty wrong-selector
  refusals, exact typed dialogs, restored focus and unchanged histories. The
  viewer stopped with exit 137 and no OOM; graceful viewer shutdown is not
  established. Native bounded run observation, discovery and positive native
  browser acceptance remain open.
- [Bounded run observation #478](https://github.com/durable-workflow/server/pull/478)
  now implements current and selected reads under the
  [existing PHP contract](contracts/workflow-run-observation.md). Local SQLite
  validation passes 78 library and 134 execution tests, with the existing
  18 library ignores; five focused HTTP controls also pass on PostgreSQL 17
  and MySQL 8.0. Missing summaries/waits remain explicit, and constructed
  retained projections are separate from genuine retry/failure execution.
  Its first [hosted MariaDB job](https://github.com/durable-workflow/server/actions/runs/38783984695/job/116596418338)
  fails during bootstrap with exit 101; the assertion log remains unavailable.
  Independent reproduction on that MariaDB image found all five observation
  controls returning 503: `COALESCE(MAX(sequence), 0)` promotes the unsigned
  ceiling to DECIMAL. The reader now keeps nullable integer `MAX(sequence)`
  and supplies zero in Rust for an empty history. Empty/pruned history coverage
  also asserts the zero ceiling and absence of a continuation token. The corrected
  local source passes all 134 MariaDB execution tests and five focused SQLite
  observation tests. This does not supply the missing original hosted assertion.
  The corrected `ce8c8fd6` [hosted run](https://github.com/durable-workflow/server/actions/runs/38790367885)
  failed at query-authority and existing-PHP-MySQL comparison steps; their
  assertion logs remain unavailable. The later `a510043c`
  [run](https://github.com/durable-workflow/server/actions/runs/38793158995)
  passes all 13 jobs, without explaining or replacing those earlier failures.
  The separate [installed observation recipe](../scripts/conformance/server-parity/run-observation-consumer.md)
  now exercises nine real task lifecycle stages, current/selected reads,
  retry waits and fixed-window continuation with the pinned published SDK.
  Published-PHP originals exposed a terminal summary undercount
  ([#479](https://github.com/durable-workflow/server/issues/479)). The PHP
  attribution correction is merged through
  [#480](https://github.com/durable-workflow/server/pull/480). Release preparation
  [#482](https://github.com/durable-workflow/server/pull/482) is merged at
  `f2775db7`, matching the fully qualified candidate tree `493f4331`.
  Immutable tag `2.8.6` points there. The normal image/chart publication and all
  `f2775db7` fresh-main checks pass. Four completed/failed × default/named originals
  against the exact published amd64 image pass 72 observation responses and
  eight consumed failure-cursor pages with installed PHP SDK 2.4.2. Independent
  canonical budget recomputation agrees with all four summaries. The
  [delivery receipt](https://github.com/durable-workflow/server/issues/479#issuecomment-6202298825)
  records the identities and limits; #479 is closed. The README recovery link
  is merged through [#483](https://github.com/durable-workflow/server/pull/483).
  The separate recipe extension at `83bb5c35` verifies selected-run repair of
  completed and failed originals on published PHP, retaining the original
  failures and deadline. Four fresh completed/failed × default/named controls
  pass with the corrected helper overlaid. Those earlier source and repair
  results remain separate from the published-image result and native parity.
  The exact native artifact download timed out before any installed native
  original began. Independent retained-artifact review, full differential reads,
  projection production and native browser acceptance remain open. PHP-issued cursor
  compatibility remains an explicit migration gate.
- The next observation slice produces activity wait rows in the authored
  scheduling transaction. Typed claim, retry, completion, failure, timeout and
  cancellation events update the same wait identity; retry follows its new
  task. Reads and later events do not recreate a missing wait. The initial
  HTTP regression failed with zero waits after a real schedule. The correction
  passes 136 SQLite execution tests, including projection-write rollback, and
  then eight focused SQLite controls after adding the absent-projection case.
  Seven focused controls also pass on PostgreSQL 17 and MySQL 8.0; the separate
  forced-write rollback control is SQLite-specific. These checks cover actual
  retry/heartbeat/terminal execution, duplicate requests, cold reads, namespace
  refusal and unchanged history around observation. This source is not yet
  qualified through shared fixtures or an installed native artifact. Summary
  production, other wait families and complete observation parity remain open.
- Ordinary authored timers now create their canonical wait alongside the timer
  task, with the recorded schedule time and planned fire time. Typed fire and
  cancellation events close the existing row in their own transaction. Missing
  rows remain unavailable instead of being rebuilt by events or reads. The
  initial real scheduling regression observed zero waits where one was required.
  Before the fixture budget correction below, all 141 SQLite execution tests
  pass. PostgreSQL 17 passes nine observation
  controls, both existing timer tests and the cancellation recovery control.
  These include cold two-node firing in both namespaces, unchanged history
  around reads and missing-row behavior. Atomic scheduling/cancellation after
  a failed projection write has separate SQLite controls. Initial MySQL timer
  controls failed because initial schema creation consumed the execution budget,
  with the failure recorded before the scheduled fire time. The fixtures now
  start their unchanged 15/35-second budgets after initial database bootstrap;
  admission, cold recovery and both namespaces retain the original deadline.
  The final four new and two existing timer controls pass on SQLite. PostgreSQL
  17 and MySQL 8 each pass the two applicable new controls, two existing timer
  controls and cancellation recovery. The earlier full SQLite result is not a
  repeat against the corrected fixture source. This does not
  provide summary production, other wait families or installed native acceptance.
- Ordinary signal waits now follow their typed opening, receipt, application
  and terminal events in the same transaction. Receipt resolves an existing
  wait before worker application; a buffered receipt that predates the wait
  resolves it at application. Missing rows stay missing. The initial scheduling
  regression failed with zero projected waits. The first implementation batch
  passed three controls and exposed the existing named-namespace admission
  refusal in the fourth. That gate is retained and tested explicitly, so the
  positive component scope is the default namespace. All 146 SQLite execution
  tests pass, including projection-write rollback and stored cancellation.
  PostgreSQL 17 and MySQL 8 each pass six signal controls, including cold
  recovery, selected-run guards and waiting-history behavior.
  Named signal-wait admission, signal timeouts, other wait families and complete
  native observation remain open.
- Ordinary condition waits now follow the existing opening and satisfaction
  events in the same transaction. Receipt alone leaves the condition open;
  application resolves its authored identity. A later occurrence retains its
  own identity, and missing projections are not recreated. Both already admitted
  namespaces are covered; timeout admission is unchanged. The initial regression
  failed with a retained opening event and zero observed waits. The first full
  SQLite batch passed 149 of 150 execution tests, and PostgreSQL passed seven
  of eight component controls. Both failures came from the new fixture reusing
  a globally reserved workflow ID across namespaces. Giving the originals
  distinct IDs preserves that refusal and all runtime code. Final source passes
  all four new SQLite controls and eight controls each on PostgreSQL 17 and
  MySQL 8.0.46. No final full 150-test SQLite repeat is claimed.
  The controls cover repeated occurrences across cold runtime pools, receipt versus
  satisfaction, duplicate completion, missing rows, persisted cancellation and
  unchanged history. Projection-write rollback is a separate SQLite control.
  Condition timeouts and scope cancellation remain outside this component.
- Terminal completion, continuation and cancellation now store closed summaries
  in their owning transaction. A failed summary write rolls back completion.
  History counters use contiguous retained events in bounded batches, with
  PHP-compatible JSON accounting that preserves database key order. Eighteen
  fixed encoding cases pass against the actual published PHP 2.8.6 image and
  its installed Workflow 2.5.22 counter. The first native byte-count baseline
  and both initial terminal regressions failed before implementation.
  An initial continuation fixture combined commands outside the existing
  admitted contract; replacing those commands with supported memo upserts
  preserves that refusal. Final native source passes all 153 SQLite execution
  tests and four focused controls each on PostgreSQL 17 and MySQL 8.0.46.
  These include completion/cancellation in both namespaces, cold connections,
  duplicate completion and refused repeat cancellation, continuation across
  history pages, activity failures and fan-out. Published PHP independently
  agrees with event, byte and fan-out counters for nine actual native originals
  per database, 27 total. Reference verification issues no database queries.
  Earlier library checks passed 79 with 18 ignored; their source is unchanged.
  Active summaries, imported/pruned-history production, configurable budget
  policy, shared differential and installed native/browser acceptance stay open.

- The next summary component commits workflow-task liveness after all mutations
  in the owning transaction. Claims and lease renewal update summaries without
  adding history; failure and explicit repair preserve the original task/run
  identities. A failed summary write rolls back the lease and attempt counter.
  Both initial regressions failed before implementation. The first implementation
  passes 79 library tests with 18 explicit ignores and all 155 SQLite execution
  tests. The first MySQL control rejected a test-only year-2099 timestamp;
  replacing it with the admitted run deadline preserves production behavior and
  timing budgets. The active classifier covers task-only native histories.
  Wait families, compatibility/fleet and transport metadata remain unavailable
  until their classifiers are qualified; the earlier ready summary is removed
  atomically rather than retained as current liveness. Reads never repair it.
  Final component `4d3a2468` passes 27 observation controls plus the original-task
  repair control on SQLite, and four focused controls each on PostgreSQL 17.11
  and MySQL 8.0.46. Published PHP independently matches 28 stored counter
  snapshots and 12 actual ready/leased task classifications across those three
  databases, with zero reference queries and the original observation clock.
  This is not a repeated full 156-test execution run. The earlier full 155-test
  result remains attached to `17657d04`.
  Full published-PHP observation, installed native/browser and shared differential
  qualification remain separate from this component.

- Ordinary condition and direct-signal summaries are implemented on the next
  component. Condition receipt and task claim retain waiting liveness until
  application. Direct signal receipt produces ready task liveness, with buffered
  receipt and cold-node application covered separately. Summary refresh compares
  every open projection with complete typed history. Missing or inconsistent
  projections stay unavailable without read repair. Summary-write failures roll
  back both wait creation and signal receipt with their task/history mutations.
  The three initial summary assertions failed on the preceding implementation.
  At `2f8607398`, 79 library tests pass with 18 ignored and all 158 SQLite
  execution tests pass. Five focused controls pass on each of PostgreSQL 17.11
  and MySQL 8.0.46. Published Server 2.8.6 / Workflow 2.5.22 independently agrees
  on 34 history-counter snapshots and 21 external-wait liveness snapshots across
  the three databases, with zero reference queries. An initial reference-harness
  timestamp omission was corrected using the original stored event timestamp.
  These are counter/classifier comparisons; the full published observation API
  and installed native/browser remain separate gates. Activity, timer, child,
  update, timeout, compatibility and transport summary classifiers remain open.

- The following component adds ordinary timer summaries. Scheduling stores
  timer liveness and counters in the same transaction as the timer, task and
  retained wait. Actual firing across cold pools changes liveness to ready
  workflow work, and claiming that work changes it to leased workflow work.
  Default and named namespace controls preserve their original execution
  deadline. A summary-write failure rolls back scheduling. Missing wait rows
  or changed projection deadlines make a later summary refresh unavailable;
  repeated observations do not repair the projection or add history.
  The test-first source passed one existing timer control and failed four
  summary assertions. The initial implementation passed five controls. An added
  consistency fixture first failed compilation because it used formatted SQL
  with the literal-SQL test helper. Its correction changes only fixture SQL.
  At `b11a6daa3`, all 79 library tests pass with 18 existing ignores, and all
  160 SQLite execution tests pass. Three focused timer controls pass on each
  of PostgreSQL 17.11 and MySQL 8.0.46. Installed Server 2.8.6 / Workflow 2.5.22
  independently matches 38 history-counter snapshots and 18 actual timer
  classifications across the three databases, with zero reference queries.
  Timeout, selection and parallel timer summaries remain separate work, as do
  full published observation API, installed native/browser and shared
  differential qualification. The native controls do not claim an externally
  leased timer transition.

- Ordinary activity summaries are the next component. Complete typed history
  identifies the activity, attempt and retry task, and every open retained wait
  must match those identities and the mutable source state. Scheduling, claim,
  heartbeat, retry and terminal activity transitions refresh the stored summary
  in their owning transaction. A failed summary write rolls back the original
  claim and attempt creation. Missing or inconsistent retained waits remain
  unavailable after refresh, without read repair. Positive controls cover both
  namespaces and real completion and failure after a retry, with cold readback.
  Both added summary assertions fail on preceding source `5cd374587`; the
  initial implementation `c0d3a9910` passes all nine observation controls.
  Final source `988d36f38` includes the inconsistent-metadata assertion and
  bounded attempt arithmetic. Its exact native tree
  `83bc3ff4b0362724860a35831affbda99ecd570e` passes 79 library checks with
  18 existing ignores and all 161 SQLite execution checks. Three activity
  controls pass on each of PostgreSQL 17.11 and MySQL 8.0.46. Installed
  Server 2.8.6 / Workflow 2.5.22 agrees with 54 counter snapshots and 24
  activity classifications across the three databases, with zero reference
  queries (SQLite 34/8; PostgreSQL and MySQL 10/8 each).
  Local, parallel, timeout and concurrent open-family activity summaries remain
  unavailable pending their own controls. Published observation API, installed
  native/browser, hosted combined-source and performance gates stay separate.

- Source follow-through for the Sonar findings consolidates the four ordinary
  wait projectors under one database implementation, sharing projection identity
  and external-wait closure handling. History counting, task classification and
  wait timing have separate helpers. Seven redundant constant lifetimes and the
  single-namespace test loop are simplified. The analyzer's 4.2% duplication
  failure on `0551089c3` remains its actual result; the profile and 3% threshold
  are unchanged. At `ce2be116d`, all 79 library checks pass with 18 existing
  ignores and all 161 SQLite execution checks pass. The 32-test observation
  selection passes with each of PostgreSQL 17.11 and MySQL 8.0.46: each selection
  contains 21 tests using that network database and 11 explicit SQLite fixtures.
  Installed Server 2.8.6 / Workflow 2.5.22 agrees on 100 history-counter
  comparisons (34 SQLite, 33 per network database) and 93 classifier comparisons
  (30 task, 21 external wait, 18 timer and 24 activity), all with zero reference
  queries. Formatting and diff checks pass. The integrated `fbeb03408` analysis
  passes at 2.2% new duplication under the unchanged 3% threshold, and the eleven
  previous findings are closed as fixed. One activity-summary complexity finding
  remains at that head. Its transition-validation helper is extracted in the
  following child component; a fresh analyzer result remains required.

- Ordinary sequential child waits now retain the original call, child instance,
  run and link identities in the scheduling transaction. Completion and direct
  cancellation resolve that same row and associate it with the original parent
  task through ready and leased states. Summary refresh validates complete typed
  history and retained identities; missing or mismatched rows become unavailable
  without changing business resumption or repairing projections on reads.
  Published child-result precedence and the stored task-problem flag are retained,
  including a cancelled earlier child followed by a new child. Existing child
  admission remains default-only, sequential and without retry/timeout options.
  The classifier bounds retained child snapshots to 100; broader child families
  and concurrent wait-family precedence remain unqualified.
  Four initial controls fail against the preceding runtime. The expanded installed
  PHP check separately reproduces a task-problem flag mismatch at `0a7aa238a`;
  `2e7195c12` corrects it without changing task liveness priority. Two observation
  fixtures incorrectly compared the read timestamp across separate reads; their
  failures remain recorded and the corrected assertions preserve durable wait
  fields and the stored summary timestamp. The optional reference collector also
  needed serialized writes after ten of 46 records interleaved during concurrent
  tests. This was an evidence-collection failure, not a native execution pass for
  the reference comparison.
  Final tested source `dc8a1467e`, native tree
  `8e3617c1cc185b43856b033130b6b455300bcc32`, passes 79 library checks with
  18 existing ignores and all 167 SQLite execution checks. PostgreSQL 17.11 passes
  38 observation selections: 25 use PostgreSQL and 13 are explicit SQLite
  fixtures. MySQL 8.0.46 passes the six changed child controls: four use MySQL
  and two are explicit SQLite fixtures. The preceding `93cd9e30c` MySQL selection
  separately passes all 38 controls; it does not qualify the later flag correction.
  Installed Server 2.8.6 / Workflow 2.5.22 agrees on 103 history-counter snapshots
  and 110 classifier comparisons (38 task, 14 external, 12 timer, 16 activity and
  30 child), including twelve open-child-wait comparisons. Child checks execute
  62 reference queries per database against empty in-memory mutable-child tables;
  the other classifiers execute zero queries. Formatting and diff checks pass.
  These component results do not establish full published observation API,
  installed native/browser or fresh combined-head qualification.

- Accepted updates now create a retained wait in their admission transaction,
  binding the original update, command and workflow task. Claims expose that
  task's actual metadata. Successful and failed application resolve the same
  wait, clear its task association, and restore the original external wait.
  Active summaries validate typed update history and retained identities before
  applying the published update precedence over signal and condition waits.
  Missing or inconsistent projections become unavailable after refresh without
  read repair or loss of the business completion. Admission and completion
  projection failures roll back the original transition and leave its retry
  usable. Existing default-only update admission and its 64-update bound remain
  unchanged; broader queued, validator, timeout and namespace behavior is separate.
  Three initial controls at `5af485643` fail against the preceding runtime.
  The first implementation `0fe347fbf` fails admission by referencing a column
  absent from the retained-wait schema; `ab762266d` corrects the query without a
  schema change. The reference fixture initially lacked the empty command table
  needed by PHP's ordering query, and the prior external-only comparator omitted
  update precedence. Those failed comparisons remain separate from the corrected
  update comparator, which also checks restoration of the external wait.
  The expanded final native source `28d4af66a`, tree
  `3a644a6bd97f65cc1870d9a88ec120429b5195bc`, passes 79 library tests with
  18 existing ignores and all 172 SQLite execution tests. Five focused controls
  pass on each of PostgreSQL 17.11 and MySQL 8.0.46: four use the network database
  and one is an explicit SQLite rollback fixture. Final comparison source
  `0c2605dac` has the same native tree. Installed Server 2.8.6 / Workflow 2.5.22
  agrees on 79 counter snapshots (57 SQLite, 11 per network database) and 94
  classifier comparisons: 34 task, nine external wait, six timer, eight activity,
  ten child and 27 update. Update comparisons also cover 18 open waits and execute
  twelve reference queries per database against empty mutable command tables;
  mutable update rows are absent. The child comparison executes 62 queries;
  the other classifiers execute zero. Formatting, PHP syntax and diff checks pass.
  These are source/component results, not full observation API equivalence,
  installed native/browser acceptance or a performance claim. The preceding
  `8a0b244b3` PHP/source and Sonar checks pass independently; its Sonar analysis
  has zero open PR issues at 2.2% duplication under the unchanged 3% threshold.
  The remaining activity complexity finding is fixed on that head. Fresh checks
  of the combined update component remain required.
- The next observation component preserves the existing configurable history
  budget in stored summaries, workflow-task delivery and subsequent task-history
  pages. All six settings use the published DW-primary/legacy resolution and
  integer casts. Disabled dimensions, warning clamps, recommendation precedence
  and ordered dimensions share one classifier. Task delivery uses complete
  stored counters or a canonical paged fallback, independently of page size or
  active-liveness support. Missing retained history refuses the original claim
  atomically. No schema, history, admission or enforced execution limit is added.
  The preceding `e64b1ba44` runtime fails both the configured-warning control and
  the missing task-budget metadata control. Its separate Sonar check passes at
  2.1% duplication with no new issues under the unchanged 3% threshold.
  Initial local fixtures incorrectly used a named version marker and expected
  an identical duplicate-completion acknowledgement. They now preserve the
  existing named timer scope and `already_completed` receipt. A mixed local
  build linked the earlier counterfactual library; its 172-pass/two-failure
  selection is not candidate qualification. Cleaning only this task's native
  package artifacts produces the new runtime library. The first PostgreSQL
  fixture exposed an integer-width mismatch in its own reader. Once that reader
  was corrected, `15bc99ded` also exposed the assumption in the new task-history
  route: 503 on pagination. `a939b2dc8` uses the existing database adapter for that
  sequence without changing schema. Final `a939b2dc8`, native tree
  `01813fcd25c1300edf9156c01f7a6c8c7f253833`, passes 80 library tests with the
  18 existing ignores and all 174 SQLite execution tests. Both focused budget
  controls pass on PostgreSQL 17.11 and MySQL 8.0.46. The profile control invokes
  six isolated configuration processes per database; its child entry is ignored
  only when invoked independently. Twelve workflows complete per database in
  default and named namespaces, retaining complete counters across restart,
  single-event task pages, missing/incomplete summaries and duplicate receipts.
  Installed Server 2.8.6 / Workflow 2.5.22 agrees on 22 policy fixtures and
  201 counter/budget snapshots: 144 configured snapshots across three databases
  plus 57 existing SQLite counters. Configured snapshots include 72 task
  deliveries and 72 stored summaries. All 64 SQLite liveness classifications
  also agree: 22 task, nine external, six timer, eight activity, ten child and
  nine update. Child and update classifiers perform 62 and twelve reference
  queries respectively; the counter/budget and other classifiers perform zero.
  Formatting and changed PHP syntax checks pass. Full network matrices,
  published-HTTP/native SDK/browser qualification and performance remain open.
- The admitted mixed activity/timer selection now retains an active summary
  through either winner, a pending or leased loser, workflow replay blocking,
  repair and activity deadline retries. The summary reader binds
  `SelectionResolved` to the member's physical event ID, typed identity and
  original selection metadata. Invalid winner attribution or a missing wait
  removes summary availability without read repair. Retained open waits follow
  the published timestamp/identity order. Published activity-first liveness
  remains independent of which member wins. No admission or schema changes.
  Three augmented existing selection controls fail against `a121f95e5` with
  unavailable summaries; its invalid-batch control still passes. Implementation
  `58112fa7a`, native tree `6b240b24b8bdf99db53b83bf00937d0af9086785`, passes
  80 library tests with 18 existing ignores and all 177 SQLite execution tests.
  Seven focused selection controls pass on SQLite; six pass on each of
  PostgreSQL 17.11 and MySQL 8.0.46. The explicit SQLite fixture additionally
  checks projection-write rollback, physical winner corruption and missing
  loser waits. Real deadline controls retain their original one-second activity
  limits and three/four-second timers.
  Installed Server 2.8.6 / Workflow 2.5.22 agrees on 125 counter snapshots
  (81 SQLite and 22 per network database), 64 existing SQLite classifiers and
  69 selection snapshots with 69 open waits. Selection checks include liveness,
  task-problem state, wait identity, order and labels, separately from complete
  current-wait deadline/API comparison. Selection references execute zero
  queries; existing child/update references retain 62/twelve queries.
  Initial reference fixtures lacked empty update/failure relations and handled
  only ready retry labels. Exporting all retained tasks also exposed old
  single-open-task fixture assumptions. The corrected comparators distinguish
  retained tasks from open tasks and match the installed ready/leased retry
  behavior. Full Rust formatting, all seven comparator syntax checks and diff
  checks pass. The separate README-only main `384578ef` is included without
  changing the tested native tree. Full hosted/installed qualification remains
  pending, and wider parallel/timeout shapes remain separate.
- Command discovery now lists the already admitted `complete_update` and
  `fail_update` handlers alongside the existing command set. Registration,
  polling and cluster discovery share that metadata. Both existing durable
  update controls now choose their actual completion command from advertised
  metadata before exercising the original receipt, fencing, cold-reopen,
  int64 result, failure identity and parent continuation checks. Both fail at
  missing discovery against prior source `e95d31eb4`, whose runtime source is
  unchanged from `a15b87977`. Implementation `3e1b61182`, native tree
  `82b9435281a125e1389119e5d5d46001ac69950a`, passes both controls and full Rust
  formatting. The only runtime change adds the two command names; admission,
  task execution, schema and package/catalog identities are unchanged. This is
  a focused source result. Full matrices and consumer acceptance remain separate.
- Direct signal waits now implement the [published timeout contract](contracts/timed-signal-waits.md)
  within their existing default-namespace admission. Null/absent, immediate zero
  and positive durable timeouts retain their distinct behavior. Buffered input
  wins before timer creation. Firing retains the original wait/timer identities,
  authored sequence and planned deadline; input application cancels a pending
  matching timeout between cursor advancement and `SignalApplied`. Late input
  receives a new occurrence, and receipt before firing remains received even
  when application happens later. One retained signal wait supplies the timeout
  and timer-task metadata. Condition timeouts remain separate.
  The shared cancellation payload now copies original scheduled timer-kind,
  wait/group metadata and planned time for immediate and cooperative cancellation.
  Prior-source controls `00e658050` fail twice at timeout-field admission and
  once at missing selection metadata, with runtime unchanged from `3f72750f1`.
  Implementation `cb5f6226a`, runtime source tree
  `5bfb3d9d27d6347ef42639c055803ba9b0f7fdd4`, passes 80 library tests with the
  existing 18 ignores and 192 SQLite execution tests. The one marked-ignored
  history-budget helper is invoked by its passing isolated-process parent.
  Fourteen timed-signal controls cover cold expiry, buffered/delivered input,
  competing drivers, original identity/deadline, unrelated timers, whole-batch
  validation, lease fencing, cancellation, missing projections and projection
  rollback. Twelve non-SQLite-specific controls plus the shared cancellation
  regression pass on each of PostgreSQL 17.11 and MySQL 8.0.46.
  Published Server 2.8.6 / Workflow 2.5.22 agrees on nine three-database liveness
  and history-counter snapshots, and nine current-wait snapshots containing
  three open waits. Those reference checks execute zero queries. The new
  [wait comparator](../rust/tests/reference/timed_external_waits.php) calls the
  published current-wait projector, preserving its seconds-formatted wire dates;
  its initial handwritten microsecond-date expectation failed and was corrected
  without a runtime change. Existing SQLite references still pass 81 counters
  and the task/external/timer/activity/child/update/selection classifiers.
  No schema, package, catalog, frozen tuple or shared-runner change is included.
  Hosted whole-source, installed-consumer and native browser gates remain open.
- Condition waits now implement their [published timeout contract](contracts/timed-condition-waits.md)
  in the existing default and named namespaces. Immediate and positive expiry
  retain each physical wait's identity, logical occurrence metadata and authored
  deadline. A fired condition stays open until worker application or terminal
  history; later input cannot satisfy that expired physical wait. Applying input
  before expiry records `SignalApplied`, `ConditionWaitSatisfied`, then matching
  `TimerCancelled`. Reopening may retain the logical occurrence while authoring
  a new physical wait and timer. Buffered input does not suppress condition-timer
  creation. Projections retain one condition row and refuse inconsistent state
  without read repair.
  The shared timeout-cancellation path also corrects the physical history
  relationship: worker application links `TimerCancelled` to its workflow task,
  with no workflow-command foreign key, matching published `TimerCancellation`.
  Prior-source contract commit `0d8f3f4d2` fails both timeout admission controls
  and the corrected physical-SQL relationship assertion. Its earlier attempt to
  inspect that foreign key through the public history DTO was an invalid fixture
  and is not defect evidence.
  Implementation `d4adf36bc`, runtime source tree
  `1a3e30250c2b84fda95c79e374bf36a4e50b91be`, passes 80 library tests with 18
  existing ignores. The initial full execution run has 204 passes and two old
  expectations that a positive condition timeout is unsupported. Test-only
  correction `76e1f8fe2` preserves those whole-batch refusal controls using an
  invalid negative timeout; the full SQLite suite then passes all 206 tests.
  The isolated history-budget child remains invoked by its passing parent.
  Fourteen condition controls cover cold expiry, default/named isolation,
  physical reopen, late and claimed input, buffered input, competing drivers,
  fencing, cancellation, invalid batches and projection rollback. Eleven
  applicable condition controls, twelve direct-signal controls and the shared
  selection-cancellation control pass on each of PostgreSQL 17.11 and MySQL
  8.0.46. Full Rust formatting and changed PHP syntax pass.
  Published Server 2.8.6 / Workflow 2.5.22 agrees on all 75 new three-database
  counter/current-wait snapshots, including 69 open wait members and 69
  nonterminal liveness snapshots, with zero reference queries. Condition snapshots
  record actual clock bounds around each read and require agreement at both;
  the earlier seconds-only fixture failed because it could precede an immediate
  deadline, and remains an unsuccessful fixture attempt. Existing 81 counter
  snapshots and all seven prior classifier helpers also pass. The relevant
  published wait/bridge/timer classes are unchanged in Workflow 2.5.32; its
  summary-projector change only wraps summary upserts in a SQLite transaction.
  Separately qualified PHP main `964f9e3d5` is integrated at `7a9efe0a6`,
  retaining published Server 2.8.7 / Helm 0.1.175 metadata. This component does
  not adopt a new native catalog, stored-job property or frozen tuple. Fresh
  combined hosted, installed-consumer, browser and promotion gates remain open.

- Activity/timer `all()/parallel()` now follows the
  [published barrier contract](contracts/parallel-all-groups.md), including nested
  paths, complete atomic batches through 100 leaves, original sequences and
  member identities. Terminal outcomes can coalesce a ready workflow wakeup;
  outcomes arriving during an older lease retain later work. All-groups emit
  no selection winner. Retry, terminal failure, expiry, root cancellation,
  cold nodes, invalid groups and projection rollback retain existing fences
  and receipts. Child/signal/condition groups and installed workers remain
  separate qualification gates.
  Contract/source `803caebc0` fails both admission controls on the preceding
  runtime. Published full-member comparison exposed two clock/order defects.
  Native now uses the published rounded-millisecond opening key and original
  wait ID, including the half-millisecond boundary. A wait's opening clock now
  matches its actual scheduled/started history event; authored deadlines keep
  their earlier scheduling clock. Original IDs and stored times are not
  normalized by the comparison. Corrected ordering control `7219f99ea`
  fails on runtime `5256f5b3`; stored-precision control `52df52bac` finds
  37 mismatched opening clocks on runtime `7f4d8e6f`.
  Final implementation `27e988389`, runtime tree
  `42800bd8e61d6318424e9a62594c0714285fa0d6`, passes 80 library tests
  with 18 existing ignores, all 216 SQLite execution tests and 16 focused
  controls on each of PostgreSQL 17.11 and MySQL 8.0.46. The network selection
  includes nine barrier controls and seven existing selection controls.
  The SQLite profile child remains invoked by its passing parent.
  Published Server 2.8.7 / Workflow 2.5.32 agrees on 61 three-database counter,
  liveness/task-problem and complete current-wait-member snapshots, including
  224 bounded members, with zero queries. Both actual read-clock bounds must
  agree. Existing 25 timed-wait, 44 network-selection and 81 SQLite counter
  snapshots plus all seven existing classifier helpers also pass.
  Earlier fixture failures remain unsuccessful: terminal history uses
  `output`, the 100-member audit must follow history pagination, storage faults
  return 503, and retained attempt widths differ across network databases.
  A seconds-only opening-clock assertion was replaced by an exact stored-time
  comparison. A candidate run reused an older library while switching worktrees
  sharing a Cargo target; its binary lacked the new ordering query. The final
  checks follow package-output cleanup and a fresh build from one unchanged
  implementation checkout. Earlier component passes do not qualify those fixes.
  No PHP package, native catalog, frozen tuple or shared-runner change belongs
  to this component. Fresh combined hosted and full consumer/promotion gates
  remain separate.

- The existing published [workflow failure command](contracts/workflow-failure-command.md)
  now has ordinary native source implementation. It remains distinct from task
  failure/retry. One transaction closes the run and originating task as failed,
  clears the task lease expiry, writes a new terminal failure and retains the
  original leased-task snapshot. Structured exception precedence, properties
  and int64 values survive. Original completion receipts retain duplicate,
  conflict, namespace and stale-attempt fences; no automatic task retry follows.
  Root cooperative cancellation keeps precedence and its original deadline.
  Nonterminal commands retain authored order before the terminal command,
  including when that terminal is placed between selection members.
  Uncaught activity attribution requires the exact latest ActivityFailed boundary.
  Failed sequential children retain the original call/link and new terminal
  failure ID, resolve one parent wakeup, and permit a caught failure followed by
  another sequential child. Parent-notification faults roll back the full transition.
  Child failure payloads omit workflow-only flags and absent nullable diagnostics.
  Update-owned task closure and broader child policies remain separate limits.
  Contract/control `ed17d8d18` fails both initial admission controls before
  implementation. Placement control `ae9117dbe` fails before filtering terminal
  commands from group validation. Stricter controls `387b18c2c` fail on the
  missing task snapshot and extra child failure flag; independent published-PHP
  checks reject both shapes too. Corrected fixture assertions use the actual
  cancellation-cleanup field and canonical activity failure ID. The first full
  run retained 224 passes/one wrong-field assertion; a later partial local run
  was intentionally retired before correcting the identity fixture, with no
  qualification claim. Earlier fixture and hosted failures remain separate.
  Final implementation `d4fb326e0440c1b35c20b6adc3b4044b197bf916`, runtime tree
  `df2633238cf4cb7e23db02785a919ed1573d3f2a`, passes 80 library and 226 SQLite
  execution tests. Eighteen existing library ignores remain; the directly ignored
  SQLite profile child is invoked by its passing parent. Each of PostgreSQL 17.11
  and MySQL 8.0.46 passes 32 controls: nine applicable new failure controls, twelve
  timed-signal and eleven timed-condition controls. Rustfmt, PHP syntax and diff
  checks pass. One unchanged checkout supplies the final builds.
  Published Server 2.8.7 / Workflow 2.5.32 agrees on fifteen workflow-failure
  payloads, twelve child payloads and thirty history-budget snapshots with zero
  reference queries. Twelve child liveness/task-problem snapshots and six open
  waits agree using empty mutable child tables (72 reference queries); six task
  snapshots agree with zero queries. JSON object member order is ignored, while
  array order, keys, scalar types and original durable identities remain exact.
  The selected immutable PHP image index is
  `27c08e15c86e897703c2bab87dcf61847946b379876b15d0a3a491bc91cf30cd`.
  Eight reported native maintainability findings receive focused source cleanup:
  external-wait validation/timeout liveness and signal-timeout projection are
  extracted into helpers, and six redundant static lifetime annotations are
  removed. Existing projection, transaction and three-database timed-wait controls
  pass. Quality settings and thresholds are unchanged; fresh analyzer disposition
  is pending. No PHP package, schema, catalog, stored-job or frozen tuple changes
  belong to this component. Combined CI, installed consumers and promotion remain
  separate gates. Ephemeral compiler and database resources were retired after audit.

- [Accepted updates now close with their original run](contracts/terminal-update-closure.md)
  on direct cancellation, ordinary workflow completion and workflow failure.
  Original update/command/request identities and completed receipts survive.
  Each still-accepted update gets one application-category, non-retryable failure,
  UpdateCompleted event and forward-only cursor advancement. Its workflow sequence
  stays absent because no update step was applied. The run, task, update, failure,
  history and cursor changes share one transaction. Old leased update attempts
  cannot replace the outcome after closure. An unrelated active run stays intact.
  Ordering uses command sequence (non-null first), accepted time and update ID.
  Missing-command fixture rows use the update itself
  as the failure source without inventing a command or cursor event. This does
  not qualify legacy database takeover or widen normal update admission.
  Tests-first `cbbd6dc59` rejects both ready/leased cancellation controls at the
  existing 422 propagation guard. Initial expanded controls also retain their
  unsuccessful outcomes: a zero-time condition reached the busy update-snapshot
  guard, a zero-time poll observed no resume, and a legacy fixture
  omitted the schema's required command-link value. Corrected controls use the
  original future deadline, ordinary long polling and explicit missing-command IDs.
  A strict published-PHP comparison then exposed an actual missing task snapshot
  field. The history model reads Eloquent's protected storage connection property.
  Native snapshots now retain the standard `sqlite`, `pgsql` or `mysql` label.
  This is existing diagnostic metadata, not queue-routing authority. PHP source
  inspection and a real method invocation independently confirm that decision.
  Implementation `58fcc366576673ec5f7e475e18a73da1e0e8223e`, runtime tree
  `2ec0d0e9c98dfb2e0e22fdc1b15b9d849f9c4397`, passes 80 library and 234 SQLite
  execution tests. Eighteen library ignores remain, and the directly ignored
  SQLite profile child is exercised by its passing parent. PostgreSQL 17.11 and
  MySQL 8.0.46 each pass sixteen controls: seven applicable new terminal-update
  controls and nine workflow-failure regressions. Rustfmt, PHP syntax and diff
  checks pass. All final builds use one unchanged source checkout.
  The exact published Server 2.8.7 / Workflow 2.5.32 closer runs in its own
  migrated in-memory PHP database, seeded from pre-closure source inputs.
  Twelve captured closures across the three native database families agree on
  payloads, command/task snapshots, failure relationships, update state and cursor
  history (180 reference queries). Only the newly generated failure ID is mapped
  between independent databases. Timestamp spelling preserves the same instant,
  and JSON object order is ignored while types and list order remain exact.
  Ten workflow-failure and eight child payloads also agree. History-budget checks
  pass for twelve update and twenty failure snapshots with zero queries. Eight
  child-classifier snapshots/four open waits agree using empty mutable child tables
  (48 reference queries), and four task snapshots agree with zero queries.
  Set `DW_TEST_TERMINAL_UPDATES_REFERENCE` when running `terminal_updates::` and
  use the [published-PHP comparator](../rust/tests/reference/terminal_updates.php).
  The PHP image index remains `27c08e15c86e897703c2bab87dcf61847946b379876b15d0a3a491bc91cf30cd`.
  Cooperative-cancellation propagation, continuation transfer and update-owned
  terminal commands keep their existing refusals and separate gates. No PHP
  package/schema/catalog/frozen tuple or shared-runner change belongs to this
  component. Fresh combined checks/analysis and installed-consumer acceptance
  remain separate. Task compiler/database resources and the new PHP reference
  image were retired after the checks.

The separate installed-SDK observation
[checkpoint](https://github.com/durable-workflow/server/pull/478#issuecomment-6205832785)
remains aggregate FAILURE: completed/default and completed/named originals pass
execute/cold reads, while both failed originals are refused at `fail_workflow`.
Their cold attempts also fail after abrupt exception persistence leaves the
identity files incomplete. That qualification is retired and stopped. The
ordinary source implementation above does not rerun that recipe or change the
recorded failed originals.

The preserved `c0b14c15` [shared run](https://github.com/durable-workflow/server/actions/runs/38806818394)
is terminal with 12 passing jobs and one failure in the existing-PHP PostgreSQL
job at the published-query comparison step. The assertion and cause remain
unknown. The component checks above do not explain or replace that result.
The preserved `fbeb03408` [shared run](https://github.com/durable-workflow/server/actions/runs/38824463438)
also ends with twelve passing jobs and one MariaDB 10.11 failure, at the
published-query/installed-Worker comparison. Its assertion and cause remain
unknown. The older `0551089c3` [shared run](https://github.com/durable-workflow/server/actions/runs/38820665798)
has twelve passing jobs and one failure at the markers/ranges/clocks/activity
expiry comparison, likewise without an established assertion or cause.

Next, qualify the combined observation source and finish active wait-summary and
remaining wait production. Independent retained-artifact
reviews remain incomplete. Native Waterline acceptance then needs
qualified discovery, followed by the same
unmodified browser recipe. Keep the incomplete inspector review separate.
No native release, database takeover or full-family parity is claimed.

The earlier installed result checkpoint at `d83a3cb9` remains separate:
normally installed SDK 2.4.2 completed ten fresh native originals across both
namespaces, covering null, false, exact typed values, external results and a
genuine two-attempt retry. Forty processes preserved the original budgets,
history prefixes and closed-parent reads. Stopped state had ten completed
runs/activities, 32 completed tasks, twelve attempts, 64 events and zero repairs.
API SIGTERM exited zero without OOM. Its original hosted MySQL image-pull and
PostgreSQL renewal-observer failures remain failed; later observations do not
explain or replace them.

The earlier foundation stages remain separately qualified as follows:

- Merged [#445](https://github.com/durable-workflow/server/pull/445),
  [#446](https://github.com/durable-workflow/server/pull/446),
  [#448](https://github.com/durable-workflow/server/pull/448) and
  [#450](https://github.com/durable-workflow/server/pull/450) provide memo
  transactions, actual SDK memo authoring, bounded continuation and verified
  local external values. Their individual acceptance records preserve original
  registration, runner and 100 ms upload failures. Current merged execution is
  covered by the separate #457 fresh-main qualification above. External memo
  policy, complete continuation transfer and full external transport remain open.
- [#455](https://github.com/durable-workflow/server/pull/455): opt-in,
  namespace/queue-scoped null-task poll wake. Component `1fa1cc2e6` passes all
  13 jobs and all six databases; promotion is through the separately qualified
  combined #458 tree. An earlier component's SQLite PHP target failed after an activity
  completion response when the Rust actor's registration DELETE returned
  lock-pressure 503; later refusal/repair phases were not reached. That original
  stays failed. Idle/query cost and matched performance remain open.
- [#457](https://github.com/durable-workflow/server/pull/457): extend the actual
  installed external consumers to separate PHP/native databases in all six
  configurations. Candidate and separate merged-main executions now pass all
  six configurations, with five original workflows per target and exact typed
  cold reads. It is delivered at `303ffc633`. This does not qualify automatic
  policy, S3 or completion-upload authority.
- [#458](https://github.com/durable-workflow/server/pull/458): completion-bound
  local activity-result uploads, including exact PHP Unicode context hashing,
  original live authority and durable allowances. Combined head `93368d2ae`
  includes #455 and passes its own 13-job, six-database matrix. The later
  `3a268200` incorporates qualified PHP `ed803519`. Its complete run
  `38680689284` passes twelve jobs and fails PostgreSQL 17 at the independent
  fresh upload still using the preceding stalled request's 100 ms test policy.
  All six small execution summaries were independently read: five cells pass
  113 execution tests; PostgreSQL 17 has 112 passes and that one failure.
  New candidate `3409a776e` keeps that stalled deadline and shared semaphore,
  gives the separate fresh upload normal policy, gates dependent recovered
  readers on successful target preparation and incorporates qualified PHP
  empty-command-contract source `35cd3642`.
  That component's six execution and six health cells pass in run
  `38686850381`. Attempt 2 reran only MySQL 8.0, with unchanged source and
  limits. Its original query transport failure remains failed and unexplained.
  The tested merge tree equals the candidate tree; later combined promotion
  still needs fresh qualification.
- [#460](https://github.com/durable-workflow/server/pull/460): read-only PHP
  catalog/data inventory. Four actual published PHP 2.8.0 profiles pass locally,
  with eight original pending runs, twelve refusals and unchanged source state.
  Head `4459140cd` adds actual FIFO/directory refusal
  and a separately budgeted fresh-upload capacity control. Its matrix is
  incomplete. The SQLite job failed in frozen PHP restart preparation before
  creating the recovered native target. Its later empty response therefore
  reflects an absent prerequisite, not a demonstrated native crash. The earlier
  SQLite 100 ms upload failure also remains failed. Inspection
  does not establish backup consistency, PHP quiescence, stored-representation
  compatibility or takeover authority. All those qualification flags stay false.
- Native [query authority](https://github.com/durable-workflow/server/issues/433)
  is prepared at `5d10c6b74` under the agreed PHP contract: immutable UTC6
  timing, capped leases, non-renewing status, registration/namespace fences and
  content-bound query receipts. Local staged checks cover SQLite, MySQL 8.4
  and PostgreSQL 17. The corrected waiter preserves the original deadline under
  a held writer and refuses a result delayed by codec capacity. This is not a
  clean full-suite or matched PHP/SDK qualification: the earlier complete
  SQLite run still has its failed fresh-upload control. Retained receipts
  now reconcile before writer admission and codec work. Actual held-writer
  HTTP controls pass on SQLite, MySQL 8.4 and PostgreSQL 17 before and after
  expiry, preserving conflicting-payload refusal and the original receipt's
  bytes and expiry. The separate SQLite codec-and-writer counterfactual fails
  the prior implementation and passes the correction. Genuine query/continuation
  controls now pass on SQLite, MySQL 8.4 and PostgreSQL 17: live original queries
  still block continuation, while expired retained snapshots do not.
  A separate published Server 2.8.0 / PHP SDK 2.4.2 profile matches four actual
  named-namespace completed-workflow controls on PHP and native MySQL 8.4:
  timely 1.20/1.19, held poll reply and late synchronous handler. Original clocks
  and histories survive, with zero late submissions and eight zero-exit Workers.
  Its fifth active native original remains failed at unsupported termination
  cleanup. The revised query-only observer delegates database retirement to
  the caller. Its revised five-case profile passes all ten fresh SQLite originals
  with the same four-second query and thirty-second observation budgets. Its
  [original all-six execution](https://github.com/durable-workflow/server/pull/463#issuecomment-6185577908)
  passes all thirteen jobs at `8e4cb2efc`: sixty actual PHP SDK query originals
  and 123 native execution tests per configuration. The separate boundary
  cleanup check fails and remains failed. A later six-case SQLite cohort passes all twelve
  fresh originals, including a query on a genuine waiting named timer workflow.
  That new case uses the same installed SDK, reference image and original budgets.
  The first PHP SQLite observer raced the Worker's
  acknowledgement and stays failed. The revised observer waits for that actual
  acknowledgement inside its original budget.
  Earlier worker-list and named-timer originals remain failed. Main
  keeps `query_task_authority=false`; the unpublished candidate advertises it
  for actual SDK qualification before promotion. Full namespace CRUD remains
  open, including the absent native DELETE route. Worker listing and termination
  remain outside this query slice. The narrow named-timer correction admits the
  existing timer command and carries its persisted namespace through global
  maintenance and backend task lookup. Both new baseline tests fail at the
  former namespace refusal; both corrected cases pass on SQLite, MySQL 8.4 and
  PostgreSQL 17, including cold two-node recovery, duplicate identity, foreign
  completion refusal, atomic invalid batches and zero-delay firing. Three
  existing default timer/selection controls also pass per backend. The initial
  MySQL regression database hit its 768 MiB memory limit and remains failed;
  its separate fresh 1.5 GiB database run passes with unchanged test deadlines.
  These local controls do not establish full namespace or timer parity. See the
  [exact installed checkpoint](https://github.com/durable-workflow/server/issues/433#issuecomment-6184509976).
  The query integration also preserves #455's opt-in poll hints and #458's
  completion uploads. Hint readiness now reads the same immutable UTC6 query
  fields as execution, while the held query poll keeps its original registration
  proof. This combined source needs its own qualification; component passes do
  not qualify the merge.

The next query qualification also includes the separately qualified PHP
`7e215d29` metadata source and normally installed Python/Rust consumers described
in the [local outcome](https://github.com/durable-workflow/server/issues/433#issuecomment-6185525047).
The preceding sixty-case result does not qualify these additions. New consumers
run in every existing database configuration with their original deadlines;
their runtime artifacts are shared without compiler caches. Cleanup is owned by
the existing unconditional workflow steps.

Candidate `396a22e98` first failed during a pinned image pull before scenarios.
Its identical-source retry exposed a missing third namespace binding in
cooperative timer cancellation, producing protocol errors on network databases.
SQLite silently left the original timer task ready at delivery. The
[three-database counterfactual and correction](https://github.com/durable-workflow/server/pull/463#issuecomment-6185925195)
fail before and pass after `a78956cd2`, checking the same original task reaches
cancelled state while preserving cancellation history, deadlines and replay.
Fresh corrected SQLite passes 75 library tests with eighteen existing ignores
and all 125 execution tests. The separate older namespace test now retains its
unqualified-command refusal using a child command, since timers have positive
coverage. Hosted failures remain failed; neither correction qualifies a new
combined matrix or explains historical PHP storage failures.

The current client observer records delivery before a Worker can consume the
response, so stopping immediately at that event does not prove expiry refusal.
The follow-up recipe waits for Python's actual public authority-loss diagnostic
or Rust's recorded expired authority observation. Proven original or monotonic
expiry can legally omit redundant status HTTP; Python's no-status acceptance
requires its actual expiry reason, exact claim identity/timing and affirmative
discovery. Rust exposes no public reason for an immediate local refusal, so such
a case stays unqualified rather than being called an SDK defect. Unexpired work
still requires observation. Observer failures now retain bounded method/path
diagnostics instead of an unexplained assertion. Five fresh installed Python
2.5.15 controls pass against the pinned PHP 2.8.0 SQLite API, with the original
four/thirty-second budgets; the held poll records an actual timeout refusal.
The earlier PostgreSQL observer error remains unexplained. Run `38692717327`
at unchanged `17382228` then passes four execution cells and all six health
cells, while MySQL 8.4 and PostgreSQL 17 fail in the installed-client phase
without a retained client error. Those originals remain failed.

Prepared fixture `c184f28da` preserves bounded client errors ahead of completed
setup logs, refuses invalid calendar dates and compares UTC6 timing using
integer microseconds. Seven focused fixture/exporter controls pass. The old
observer accepts February 30; its calendar-corrected successor still accepts a
one-microsecond deadline change. The corresponding constructed HTTP controls
fail those parents and pass the corrected observer without rewriting bytes.
Five fresh Python 2.5.15 originals also pass against the hosted native binary
from `17382228` / tested merge `3c05f817`, using this revised fixture. The held
poll records `query_task_timed_out` with no callback; async cancellation and
late sync return record their original budget-expiry reasons with no result
submission. Timely 1.20 and 1.19 return exact typed values. All five histories
remain unchanged through their queries and all five Workers exit zero. This
does not qualify the no-status expiry branch, revised Rust consumer or a new
all-database combined tree. Earlier PHP component checks remain scoped to
their original fixture source.

Full transport discovery stays unadvertised. Complete batch workflow semantics,
memo/query reference ingress, upload allowances, automatic result policy, S3,
cleanup/backup/restart and full database/consumer qualification remain open.
Current PHP 2.8 query-authority and activity-result-inspection parity also
require separate native qualification. PHP's #436 empty-declaration correction
is incorporated as qualified source; native empty/omitted/malformed declaration
and selected-build-cohort parity remains a separate open gate.
Broader runtime/operational parity, safe sequential PHP database takeover,
matched performance measurements and release/cutover preparation remain #325
requirements. No native release or deployment is qualified.

Targeted-result candidate `d83a3cb9` fails two independent hosted boundaries in
run `38746470760`: MySQL 8.4 image pull times out before admission, and the
PostgreSQL 17 CPU-health renewal observer exits without its failed-call detail.
The other five execution archives independently show 29 passing comparisons,
20 zero profile statuses and 129 native execution tests each. Those successes
do not qualify the failed cells. A fresh local PostgreSQL 17 diagnostic passes
on the exact optimized binary, without explaining the hosted renewal failure.
The next runner adds bounded original-call renewal diagnostics and upstream
query-response/client-state diagnostics. Native code and original deadlines
remain unchanged; all-database acceptance is a new gate.

Completed local raw archives, transport journals and databases have been deleted.
Historical retention descriptions below do not guarantee that those bytes are
still available. Code, readable owning outcomes and bounded temporary job
summaries are the current evidence surfaces.

### Earlier external input and byte milestones

External workflow inputs now retain their verified opaque reference in the same
native transaction as start admission. Failed starts roll that retention back.
Cold workflow delivery and description keep the small reference, while the
normal installed SDK fetches and decodes the original bytes. Invalid Avro,
including malformed negative collection blocks, and expired/unbound references
refuse without admitting a workflow or retaining an abandoned upload.

The same normal PHP 2.3.9 consumer completes actual frozen PHP 2.6.2 and native
SQLite originals with an exact 4,194,356-byte input, preserving the large int64,
null and Unicode arguments. Each invokes and completes once within its original
30-second observation budget. Stopped SQL/provider inspection independently
confirms one completed first-attempt task, zero repairs, one completion event,
the exact retained input hash/size, null upload expiry and a small stored pointer
instead of expanded bytes. The native binary is
`c58762759f4c03302ee54b3e22a11261ed087783d374ff7537dfb48d1ec5fd0b`.
The first PHP business original completed but its observer incorrectly decoded
the raw external result as inline; that observation remains unsuccessful. The
corrected observer uses the normal SDK result reader on a separate original.
Client header typing and native opt-in/listen setup failures preceded admission.

Validation uses Apache Avro 0.22.0's schema-aware reader and a discarding Serde
visitor, not a decoded collection or rewritten stored bytes. An actual regression
proved the unpatched reader accepted an invalid negative collection byte length
that frozen PHP rejects. The narrowly vendored patch checks those block bounds,
streams discarded bytes, and grows scalar buffers from observed data before a
short-stream refusal. Its source/package provenance and two changed upstream
files are documented in `rust/vendor/apache-avro/PATCHES.md`. Thirteen actual
frozen PHP datum controls pass; native final source passes 66 library tests
with 18 explicit database/subprocess ignores and all 102 SQLite HTTP tests.
Formatting and probe syntax checks pass. All completed local runtime, database,
vendor-install, compiler/cache, formatter and raw evidence resources are deleted.

This is workflow-input acceptance only. General completion/activity/memo/query
reference ingress, automatic external result policy, complete policy/provider
variants, allocation/CPU/deep-nesting qualification, cleanup/backup/restart and
the full database/consumer matrix remain ahead. Full transport discovery stays
unadvertised; no release, performance, migration or cutover claim follows.

The development external-payload transport now exposes authenticated versioned
upload/fetch routes and an admin-only local namespace policy. The byte route has
its own configured size ceiling, original request timeout and one bounded
snapshot worker; it accepts worker, operator and admin credentials without
either protocol-version header, matching the frozen PHP transport. Namespace
object/byte quotas include precommitted writing rows and refuse a new object
before any provider write. Identical retries keep their reference ID. Fetch
verifies independent bytes and updates fetch metadata without retaining the
object or renewing its expiry. Completion-upload headers explicitly refuse
while the required task/lease authority checks remain unfinished.

Actual separate native and frozen PHP 2.6.2 HTTP servers each pass nineteen
byte-transport checks with a 3,145,729-byte object: exact upload/fetch hash,
same-reference retry, conflicting metadata, authentication, namespace isolation
and object-quota refusal. Stopped database/provider inspection confirms two
ready, unclaimed namespace records, distinct reference IDs and one exact shared
content-addressed object on each server. The PHP fixture's initial local-policy
setup returned 404 because this minimal launcher had not seeded its default
namespace; an actual namespace admission preceded its first payload comparison.
Both test runtimes, their databases, network and raw scratch are deleted.
This observes bytes, not a valid Avro datum or an admitted workflow. The full
transport capability remains unadvertised. Complete policy variants, all
database families, local/S3 providers, completion-upload authority, retained
claims, cleanup/backup/restart and real external memo/continuation consumers
remain qualification gates. Tokio-util 0.7.19 supplies the standard streaming
adapters; existing locked crate versions are unchanged.
Final native source checks pass 63 library tests with 18 explicit
database/subprocess ignores and all 100 SQLite execution HTTP tests. The four
new HTTP controls also cover corrupt/missing provider bytes, partial-object
repair, observed oversize, refusal before body polling/provider effects and
recovery of transfer capacity after a stalled upload's original timeout.

The external-storage preparation now writes local content-addressed objects
and records recoverable `writing`/`ready` registry transitions. Exact retries
preserve accepted bytes and reference identity, while partial objects can be
repaired at that identity. Provider work stays off Tokio executor threads;
contended local writer locks refuse instead of blocking indefinitely. Paths
match PHP's encoded file URI, and reads bind the configured local root before
verifying an independent snapshot. Native SQLite controls prove that failed
provider writes and rolled-back ready promotions retain the original writing
row and upload deadline. Cross-namespace lookups, conflicting metadata and
expired uploads refuse; retained retries preserve ownership and no expiry.
The actual frozen PHP registry passes fifteen independent controls, including
an injected provider write failure and recovery with the same reference ID.
Final native library checks pass 62 tests with 18 explicit database/subprocess
ignores, and all 96 SQLite execution HTTP tests pass. These registry methods
were internal preparation at that checkpoint. The subsequent byte handlers
above add local HTTP routing and quota admission; completion-upload fences,
retained-state claims, cleanup/backup
coordination, external database execution and S3 remain pending. No external
transport or memo capability is enabled.

The external byte foundation now captures verified anonymous file snapshots
with fixed 8 KiB reads, declared-size refusal before reading and a one-byte
observed-size probe. SHA-256 measures the exact uploaded Base64 byte string;
Avro frame validation belongs to the later state-bearing request. Blocking
disk/hash work holds a concurrency permit, and a verified snapshot keeps that
permit through its consumer lifetime. The async reader retains capacity and
uses bounded file buffers. Abandoned captures stop between reads and retain
capacity until a blocked source returns; callers must bound individual reads.
Published PHP snapshot controls pass eight exact-size, spill, backing-change
and one-byte-probe cases. That snapshot foundation passed 56 library tests with 18 explicit
external-database/subprocess ignores. A counterfactual that performs capture on
the single Tokio executor fails the responsiveness control after the watchdog
releases the source; restoring the blocking worker passes. Namespace/registry
admission, HTTP upload/fetch and provider persistence were still pending at that
snapshot-only checkpoint. Real workflow consumer acceptance remains pending.

External memo references require the existing authenticated runtime transport,
not provider URIs in clients. The next foundation validates the five-field
opaque reference against 46 published PHP controls, including exact integer
sizes, strict unknown-field refusal, digest canonicalization and the published
present-null/empty codec fallback. The published validator and codec-contract
source hashes match this PHP tree. A reference's permitted identifier alphabet
does not assert that an object exists or that its bytes have passed integrity.
Native storage lookup must separately bind namespace, ready state, metadata and
expiry before byte verification. The primitive was preparatory at that checkpoint;
the byte handlers above now use it. It changes no advertised transport capability
or frozen actor tuple.
The full transport slice remains upload/fetch and normal installed SDK use,
bounded streaming, durable content-addressed admission, integrity and quota
controls, current-lease completion upload fences, runtime-owned resolution and
claim retention, restart/recovery, cleanup/backup holds, three database families
and local/S3 provider compatibility. Memo history/read and continuation tests
must consume those real references before full memo support can be qualified.

The next authored-memo slice follows #445's disabled preparation. Its full
acceptance includes configured structural limits, actual SDK replay, protocol
and capability fences, external envelopes, continuation inheritance and
unchanged state after refusal. The first change wires the published
`DW_V2_LIMIT_MEMO_SIZE_BYTES` / `WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES` precedence and
casts into each native database runtime and its namespace views. Nineteen
configuration controls were observed on the published PHP package. Zero and
negative values disable this structural limit; fixed memo count/value/total
limits still apply. The new actual SQLite control refuses before changing the
original memo/history and restores at the inclusive whole-map boundary.
The configured rejection preserves PHP's 422 task/attempt and measured-limit
diagnostic. Twelve focused controls and the restored full source suite pass:
48 library tests and 90 SQLite HTTP tests, with 18 explicit external-database
and subprocess ignores. Reverting only the transaction's configured limit to
`None` makes the new actual refusal control fail by accepting that upsert.
The correction restores the inclusive whole-map boundary and preserves the
separate actual history-write rollback test. The unpublished local candidate
enables memo commands and passes the unchanged installed PHP 2.3.9
author/activity/cold-successor recipe against native SQLite. Three independent
worker processes preserve the original four- and six-event prefixes and finish
with eight events, memo command sequences 1/3, one activity, typed int64/null/
Unicode values and unchanged run/execution deadlines. An identical committed
receipt while the parent waits returns 200/already_completed/recorded=false;
a conflicting receipt returns 409/conflicting_completion without mutation.
All 23 corrupt-reader controls refuse. Protocol 1.13 refusal preserves the
original task, while the capable 1.14 control upserts and completes it.
The normal installed Python 2.5.13 author and cold successor now complete the
same original around an independent PHP activity on frozen PHP 2.6.2 and the
hosted native candidate. Both preserve the eight events, typed memos, original
deadlines and complete captured first-completion body; each passes all 23
corruption controls. Stopped read-only SQL shows three completed first-attempt
tasks, zero repairs and the same four surviving memo rows on both backends.
The shared candidate selects this additional cross-language recipe in a
separate prefix/output directory and compares it with the embedded PHP recipe.
The existing recorder forwards unchanged bytes; SDKs and clock/history fields
are not edited. The normal registry Rust 3.5.4 author and cold reader also
complete actual PHP/native SQLite originals around the same PHP activity,
passing the identical 23 corruption controls and stopped SQL guards. The
observer checks binary/build identity before worker execution and keeps the
exact published crate/source/lock identity. Its reported SDK constant remains
`durable-workflow-rust/3.5.4`; the reader checks that exact format. The shared
candidate selects all three SDK author families in separate prefixes/output
directories. External envelopes, broader inheritance, complete discovery and
all-database acceptance remain incomplete; no published capability is claimed.
The combined authoring candidate now integrates exact PHP main #449 and #446's
continuation foundation. A focused control exposed the sole-command guard's
refusal of a memo upsert followed by continuation. The correction permits only
validated memo commands before the terminal continuation, retains unsupported
transfer/routing refusal and proves rollback of the preceding memo when routing
refuses. A separate cold-routing control preserves the fenced old claim when
registration changes and delivers memo history only to a capable successor.
The new shared `continue-upserted-memo-activity-replay` case completes actual
frozen PHP and native SQLite originals with one activity, one memo event and one
successor, preserving the merged memo before successor admission. Each passes
35 strict reader corruptions. Its first business originals completed but the
reader failed because the continuation observer omitted typed memo-envelope
decoding. The corrected observer uses the normal codec on fresh originals;
no history clock or stored bytes are rewritten. The normally installed embedded
2.5.16 counterpart also passes with 34 corruption controls. All three originals
have four completed first-attempt tasks, zero repairs and one canonical
continuation link. Final native execution passes 96 tests; the recorder passes
206 controls. The [three-runtime receipt](https://github.com/durable-workflow/server/pull/448#issuecomment-6170709164)
records exact source, binary, original identities and qualification limits.
Full matched acceptance, external references and complete discovery remain
pending. All completed runtime, compiler and raw audit resources were deleted.
Frozen references and independent #444/#445/#446 gates are unchanged. The
broader [draft #448](https://github.com/durable-workflow/server/pull/448) retains
those incomplete acceptance requirements.

[#445](https://github.com/durable-workflow/server/pull/445) prepares authored
memo transactions on top of #444. Apache Avro's schema-aware serde writer
preserves the published PHP canonical map bytes. The internal transaction
replaces or deletes named keys, preserves untouched row metadata and appends
the merged history envelope atomically. A real SQLite history-write failure
rolls back the memo change. Public completions still return
`workflow_memo_updates_unavailable` with `recorded=false`; discovery remains
disabled. Protocol-floor refusal leaves the original task usable. Configuration
limits, external references, inheritance, routing/replay boundaries and actual
installed SDK acceptance remain required before enabling this capability.

[Continuation #446](https://github.com/durable-workflow/server/pull/446)
adds the first atomic native continue-as-new transition after one genuine
activity. Four normally installed PHP SDK 2.3.9 workers complete an actual
native SQLite original and its single successor, preserving typed
int64/null/Unicode results, history prefixes, the original execution deadline
and inherited memo authority. The full captured completion retry includes
sticky metadata and returns `recorded=false` without another successor.
The [actual receipt](https://github.com/durable-workflow/server/pull/446#issuecomment-6163706919)
records 30 corruption controls and stopped read-only SQL showing one canonical
link, four completed first-attempt tasks and zero repairs. Library, execution
and recorder controls pass locally. Published PHP and normal installed embedded
baselines are separate; embedded successor `StartAccepted` remains explicit.
The earlier native request-shape originals stay failed: normal SDK serialization
supplies its default queue, and null-only routing acceptance was insufficient.
Changed routing and related/pending transfer families remain refused and
unqualified. Review also found that the pending-query guard could miss a
predecessor entry beyond its 65-row cache prefix. The draft now checks the
complete native-owned predecessor scope in SQL without loading cached history
snapshots onto the executor. A constructed database boundary control covers
pending/leased refusal, unchanged history and successor count, then same-original
continuation after the query becomes terminal. Exact revised qualification
remains required. The corrected cohort now selects this separate pending fixture
on all six database cells; full matched qualification remains next. This does
not enable memo upserts or establish full continuation, migration or release
readiness. Disposable runtime, compiler and raw records were removed after audit.

An earlier #444/#445 memo comparison failed because its completed-task retry
omitted captured `sticky_cache`, so native correctly refused a
changed body as `conflicting_completion`. The [probe correction](https://github.com/durable-workflow/server/pull/444#issuecomment-6163205713)
preserves all original completion metadata and checks a separate conflicting
payload refusal. Earlier failed records remain failed; no stronger PHP receipt
contract or runtime receipt change follows. #445 and #446's prior candidates
passed all 13 matched jobs. The delivered #444 foundation and qualified PHP
#451 source are now integrated for fresh combined qualification. Earlier
results do not qualify this combined tree. The broader authoring candidate
remains separate from the disabled memo foundation and unpublished.

The [first matched development observations](../benchmarks/server-port/v2/observations-2026-10-14.md)
complete 737 workflows per implementation, including setup and warmup, with
exact outputs and zero task repairs. They do not qualify a performance gain:
PHP has two idle API timeouts and a forced scheduler shutdown, while native
workflow tails vary. The original idle reader also rejects protocol-permitted
`timeout` replies. Separate reader and scheduler controls pass, with original
failures preserved. Separate installed-worker controls identify a native poll
wake gap: published PHP interrupts an opted-in activity/query wait when new
workflow work arrives, while the native baseline consumes the remaining waits.
This does not establish the cause of every earlier tail. The
[scoped correction in #455](../scripts/conformance/server-parity/CROSS-KIND-POLL-WAKE.md)
keeps task/lease authority in ordinary claims. Its five focused HTTP test groups
pass locally on SQLite, PostgreSQL 17 and MySQL 8.4; the unchanged native baseline
fails all three positive groups. Installed-worker activity/query controls also
pass on PHP and native SQLite with the original workflow/run identities.
Six-cell qualification of the new combined tree remains required before
repeating the corrected matched profile or claiming a gain.

The #444 original MySQL 8.4 matrix on `95cd4efa` exposed a native activity
clock disagreement: a retry was recorded 101 ms before its durable
`ActivityStarted` timestamp plus the unchanged two-second start-to-close
timeout. The attempt and deadline already use one original clock; history
used a later publication clock after database writes. The correction records
that original attempt-start instant in history, preserving the stored deadline
and strict reader. A real MySQL control delays attempt insertion by 25 ms;
the prior source fails with a 35 ms event/deadline disagreement. The corrected
source passes that control and all four actual MySQL deadline/receipt/restart
tests, plus 41 library and 89 SQLite HTTP tests (18 explicit external-database
and subprocess ignores). The original hosted failure remains failed. This
changes only new native activity-start events, with no PHP correction or
rewriting of historical records.

The pending [memo fixture](../scripts/conformance/server-parity/MEMOS.md)
widens metadata coverage before native implementation. Normal installed PHP
SDK 2.3.9 against the frozen Server 2.6.2 and embedded Workflow 2.5.16 complete
the same original memo/activity/cold-replay recipe in three independent
processes. The typed merge, two memo command sequences, exact original prefixes
and one activity result agree. The PHP completed-task duplicate returns
409 `task_not_leased` with completed status and no second effect. The first
reader incorrectly required HTTP 200; this was a reader error, not a product
defect or a stronger receipt contract. Full native parity, protocol/capability
and limit refusals, inheritance and external payload gates remain ahead. Existing
frozen profiles and default fixture discovery remain unchanged.
The draft native foundation now persists initial JSON-like memos as both the
PHP host's legacy projection and a sequence-bound Avro envelope. Reads preserve
the current envelope's types and follow the published legacy fallback when the
portable sequence is stale or its value is null. Official codec work runs on a
bounded blocking worker. Focused codec and cold-storage controls pass locally;
full SQLite regressions pass. Initial-memo three-runtime/all-database acceptance
and the authored-upsert gate remain pending. Memo updates are still advertised
as unsupported; no native release or same-database takeover is qualified.
The existing corrected cohort now selects the separate initial-only memo case
for all six cells. It checks original start-history metadata and genuine
activity/cold replay across PHP, native and embedded targets without claiming
upsert support. A source review corrected initial null entries to deletion and
preserved authored metadata in both start events and the instance row. Fresh
native and matched qualification of that revision is required. All 4,845 shared
reader checks pass, with one explicit skip.

Four further raw published admissions establish the JSON number boundary.
Signed int64 maximum remains an Avro long; numbers immediately outside signed
int64 and unsigned int64 maximum become doubles with the recorded exact bits.
The [published storage observation](https://github.com/durable-workflow/server/pull/444#issuecomment-6164763709)
corrects the draft's unsigned overflow refusal in memo JSON and legacy
projections, including start-history metadata. Typed Avro long handling stays
unchanged. These are admission/storage controls, not completed workflows.
Revised native and matched qualification remains required.

Continuation's first revised MySQL/MariaDB gates expose a native query-template
error: literal JSON paths reached the ordered placeholder converter. The draft
now follows the existing driver-owned `?` template path without weakening
shared placeholder checks. MySQL test setup also uses text protocol for
`CREATE TRIGGER`, which binary prepared statements refuse. The [failed original
disposition](https://github.com/durable-workflow/server/pull/446#issuecomment-6165414784)
remains distinct from frozen PHP lock failures. Corrected real database controls
and a new exact-source matrix are required before promotion.

Final number-boundary matrix `38540906439` has twelve original passes and one
MySQL 8.0 failure: the historical producer is killed after its commands commit
but before the proxy observes the completion response. The strict reader
correctly refuses that cancelled completion. Separate upsert-preparation matrix
`38541216269` encounters the same checkpoint race in the Python-clock case on
MySQL 8.4. Both failed originals remain unsuccessful. The producer checkpoint
now requires the actual original admission receipt as well as durable history
within its unchanged 30-second limit. It changes no SDK, reader, clock, receipt
or frozen package contract. Four focused tests, fourteen refusal controls and
both actual failed captures confirm that unacknowledged admission cannot trigger
the kill. The actor helper is included in adapter source integrity checks.
The next matched revision exposed a separate fixture limit: a legitimate
4,955,564-byte PHP HTTP journal was rejected before its matching acknowledged
admission could be read. MySQL 8.4 in `38549329699` and all three MySQL/MariaDB
cells in `38549468335` remain failed. The observer now streams complete lines
instead of imposing a total-file limit. Five tests retain all fourteen refusal
controls and cover large journals without a receipt, a partial receipt and a
complete matching receipt. The actual MariaDB journal passes the same unchanged
identity checks. Fresh exact-source qualification is required before promotion.

The next upsert-preparation matrix has a distinct MySQL 8.4 CPU-cell setup
failure before native startup or original admission. A successful socket query
is followed by a missing-socket version query. The CPU-cell readiness and SQL
checks now require TCP, which does not accept the image's socket-only temporary
initialization server. Setup remains bounded at 120 seconds; database limits
and workload budgets are unchanged. This original is an unsuccessful setup,
not native deadline evidence. The affected cell will use the exact hosted
optimized binary and native-tree/hash provenance for local qualification;
broader corrected-source checks remain required.

Seven actual raw JSON admissions on the frozen PHP image exposed an additional
initial-memo boundary in #444. Empty objects and contiguous numeric-key objects
become lists; sparse or mixed canonical numeric keys refuse; leading-zero
string keys stay maps. Start history keeps PHP's decoded JSON shape, including
an empty list and a reversed numeric-key object, while portable memo storage
canonicalizes the latter to a list. The native correction uses a memo-specific
serde visitor and separate storage conversion. It preserves unrelated request
fields, top-level null metadata and typed Avro memo maps. The baseline native
control reproduces the empty-object mismatch. Fresh exact-head shared checks
are required; no memo-update capability is enabled.

#438 is merged at `50ce8f42967bc14c0169fc31b16fbbbd5827528f`, complete tree
`1065360b83f0dbf531a67a7af8edbece19b97fec`, identical to reviewed `97e63c01`
and its CI merge `e8f4fc49`. All six original final combined jobs in
`38506521977` and applicable source/boundary gates pass. Exact fresh-main
[`38511209412`](https://github.com/durable-workflow/server/actions/runs/38511209412)
also passes all six original jobs without retries. Independent completed logs
confirm the four retry/heartbeat families across PHP, Rust and embedded mode.
The [delivery receipt](https://github.com/durable-workflow/server/pull/438#issuecomment-6161666925)
records final source and boundary success. Conditional cancellation and
prepared-local qualification remain explicitly skipped. Earlier failed
originals and frozen tuples retain their existing dispositions.

#443 is merged at `5bd0bb4dcc3ec43b3186589a8d1f16d2c8972bcf`, complete tree
`b7716f947d979513877d540555e0b622e9435c09`, identical to reviewed `1061491df`
and CI merge `7c77b91b`. All 13 original combined jobs in `38513528725` pass:
six CPU/deadline cells, six ordinary database cells and the SDK/build job.
Source and boundary gates pass. Independent CPU logs confirm the declared
activity, timer, renewal and canonical cleanup observations under load, plus
stopped read-only history and task audits with zero repairs. Exact fresh-main
`38520403960` failed at the killed-producer poll checkpoint, as documented in
#447. That correction is merged at `07015a65`; fresh-main `38544906272` passes
all thirteen original jobs, with applicable source and boundary gates also
passing. [Final delivery](https://github.com/durable-workflow/server/pull/447#issuecomment-6166892004)
preserves both failed originals and the conditional skips. Native and frozen
packages are unchanged. This does not establish matched PHP/Rust performance,
takeover or rewrite release readiness.

#438 candidate `c99b1130090b8e29db0bcab7d1bdfa6fd34a0dbf` qualifies all six
database configurations in run `38492234181`, after two unchanged-head/limits
single-job retries for upstream Composer HTTP 500 responses. Its complete tree
`6eda44f2aadffb046e0c0f018b126ccd1a4be0e3` matches recorded CI merge `6604a3b2`.
Current-main integration includes #442 and reviewed PHP metadata; its final
combined qualification passes as recorded above. Native and frozen tuples stay exact.

Earlier #438 run `38486080308` retains two failed originals. SQLite
stops at the published PHP activity-expiry parent poll's documented
`503/backend_lock_pressure`. Read-only SQL confirms one original timeout and
an unclaimed, ready parent task with zero repairs. PostgreSQL 17 passes both
HTTP deadline and retry families, then Composer receives HTTP 500 fetching
`league/config` before the corrected embedded application is installed.
Neither job qualifies the complete matrix or explains older failures.

The fixture now handles only a matching namespace/queue/task-kind pressure
refusal through the normally installed Client response API. It waits the
advertised backoff with the same logical poll ID inside the original observer
budget. Unknown admission errors and malformed responses still fail. Optional
wire timing and strict reader checks bind each pressure response to its actual
retry. A normally installed PHP SDK 2.3.9 against the unchanged published
PHP 2.6.2 image passes all four expiry originals with one synthetic pressure
response injected before forwarding. Exact prefixes, frozen policies and
original deadlines survive. All four parent tasks are claimed once with zero
repairs. Fifteen installed-Client controls and seventeen reader controls pass.
The complete shared reader suite passes 4,838 cases, with one explicit skip.
An initial local observer setup error occurred before any workflow admission
and is not counted as a workload. Candidate qualification is complete; fresh
combined-main qualification remains pending. The source includes reviewed PHP #440
merge `11261ef7`; frozen images and native capabilities remain unchanged.

Separate [retry and terminal-response controls](../scripts/conformance/server-parity/ACTIVITY-RETRY-RECEIPTS.md)
pass fresh published PHP/embedded SQLite comparison at `b6d5faef2`. Four originals
each retain two actual attempts, eight events, four tasks with zero repairs,
the entire retry snapshot and exact int64/null/Unicode results. Active-parent
duplicates before and after expiry and closed-parent duplicates preserve the
original result. Actual HTTP and embedded refusals remain explicit. Common
comparison, 86 corruption checks and stopped read-only original deadline
verification pass. Separate [global deadline originals](https://github.com/durable-workflow/server/issues/325#issuecomment-6156799442)
pass on PHP and embedded mode while an unused third attempt and the second
attempt's close budget remain. All three [native SQLite cases and a matched
live-heartbeat case](https://github.com/durable-workflow/server/issues/325#issuecomment-6156982520)
also pass. Two heartbeats preserve one original attempt past its initial
heartbeat deadline; status reads do not renew, and later progress is refused.
The new `retries` profile family selects the four fixtures across three runtimes
and runs their actual-record corruption checks. All six candidate database
configurations pass; current-main qualification remains pending. These controls remain outside #435 and the
default corpus. They do not establish a stronger content-verified receipt
contract. Earlier harness failures and unqualified observations remain recorded.
[#435](https://github.com/durable-workflow/server/pull/435) is delivered at
`7b53fc26db4e69a8013b6f1ad991fd1d98266833`, complete tree
`b9f7e9bd652586e23a4e80aa58433c79c1e51ea6` identical to the reviewed candidate.
All six original final-head and fresh-main jobs pass on SQLite, PostgreSQL
16/17, MariaDB 10.11 and MySQL 8.0/8.4. Fresh main
[`38489133816`](https://github.com/durable-workflow/server/actions/runs/38489133816)
passes corrected portable markers, genuine differing-bound version-1 producer,
strict complete retry-policy refusal/restoration, both selected range-repair
checkpoints, fixed Python clocks and all four activity expiry families. It also
passes the existing ordinary corpus, real process recovery, worker fencing,
database pressure and applicable TLS controls. PHP/source/repository and public
boundary checks pass; conditional cancellation/prepared-local qualification is
explicitly skipped. Historical failed originals remain failed. Their causes,
new #438 retry/renewal cases, performance and takeover remain separate.

The separate pending [CPU-load case](../scripts/conformance/server-parity/CODEC-DEADLINE-LOAD.md)
declares real dense Avro reads, independent health observations and a retained
activity's original global deadline. Seven streaming/timeout/status controls and
29 actual-record corruption controls pass. An optimized native SQLite original
passes all 91 dense reads and 88 independent health/readiness reads under measured
busy CPU at one CPU, 512 MiB and no swap. It preserves the original activity
deadline, history prefix, parent delivery and late-outcome fences. The debug
native read-timeout original and PHP OOM/read-timeout originals remain failed.
This does not establish matched performance, embedded CPU behavior or full
timer/lease-renewal/cancellation health under load. Other databases and the
complete CPU/deadline qualification remain ahead.
It leaves the default corpus, native source, dependencies and frozen actors
unchanged.

The separate [timer/renewal/cleanup CPU profile](../scripts/conformance/server-parity/CODEC-OPERATIONS-LOAD.md)
adds three independent installed SDK observers to the same workload. Its fresh
native SQLite original preserves one timer firing/completion, two timely
same-attempt heartbeat/lease renewals and original cooperative cleanup expiry
under measured busy CPU. All 85 dense reads and 88 health/readiness reads succeed,
and the stopped read-only audit finds zero repairs. The failed barrier race,
delayed-description observation, incorrect source identity and PHP OOM/lock
reference remain separate outcomes. The reader preserves fractional deadline
precision. Other database families, published consumers and the full CPU gate
remain pending. This is not matched performance or cutover qualification.

Original `871eec1ec` run `38477701278` passes SQLite, PostgreSQL 16/17,
MariaDB 10.11 and MySQL 8.4. MySQL 8.0 passes its corrected portable, clock and
activity-expiry comparisons, then its fixture database is OOM-killed during
native pressure at the declared 1 GiB limit. The retained state records
exit 137 / OOM true, followed by native storage refusal and lost database
hostname. Its TLS step is unexecuted. The failed original stays failed.
The prospective MySQL 8.0 allocation is now 2 GiB, matching the expanded
MySQL 8.4 fixture. Native/actor limits, scenario deadlines and the 75-minute
whole-job ceiling are unchanged. Fresh qualification is required before
promotion. This does not establish the cause of historical 503s or qualify
performance.

Original SQLite artifact `11858815390` matches GitHub's published SHA-256.
Its recorded CI merge `623d900a` has the same complete tree
`a58061e1c335e0b01de55a14e6a26c8f2d4f2dd1` as the branch head.
Canonical typed values and source fixture hashes remain the evidence for exact
int64s. JavaScript numeric copies in diagnostic fixture inputs are rounded;
they are not claimed as lossless payload evidence. Temporary ZIPs are deleted
after their immediate inspection.

The separate #438 recorder correction now copies validated original fixture
and probe JSON into each saved case, preserving numeric literals without another
complete serialization of the wire bodies. At `6a79f7b9c`, nine focused recorder
controls and all 4,821 shared reader checks pass, with one explicit skip. A fresh
normally installed SDK 2.2.6 workflow on the unchanged published PHP 2.5.14
baseline completes with `9007199254740993` preserved in source input, saved
fixture, decoded history and output. Temporary probe files are absent after
writing, and the local runtime and recordings are removed after verification.
Earlier rounded diagnostics remain unchanged. Final-head matrix qualification
of this recorder revision is still required.

[#429](https://github.com/durable-workflow/server/pull/429) is delivered at
`6299289185caea472b2738a5d4442a617edf0b8a`, tree
`62736363c1e03ceae091ea578d307fb4d2370054`, identical to the reviewed candidate.
All six original candidate and automatic main database jobs pass without reruns.
Independent main artifact audits verify 162 paired namespace observations,
324 original runs, 1,836 full HTTP sidecars, 11,567 byte-checked exchanges and
10,053 member hashes. Each database passes 78 native execution tests and 49
namespace reader/retention tests. Main SDK identity, PHP/source (2,744 tests /
61,718 assertions, six existing deprecations and one skip) and boundary checks
pass. The [delivery receipt](https://github.com/durable-workflow/server/pull/429#issuecomment-6138216847)
records exact artifacts and limits. Historical failures below remain failed;
their causes are not established by this successful qualification.

Current #435 ordinary run `38438905276` fails all six original jobs at the
process-recovery harness's sub-40-second lease wait guard. The candidate now
uses published PHP's 300-second ordinary activity lease. A new machine-owned
recovery allocation allows at most 310 seconds, declared before preparation
and frozen with its hash in every new receipt; missing or changed old receipts
are refused. No existing run receives a new lease or deadline to fit the test.
Original failed jobs remain failed. The later ordinary-corpus run confirms
real process-kill recovery; expanded matched fixture gates remain pending.
That runner has a prospective 55-minute whole-job ceiling to include the real five-minute lease
wait; old 45-minute allocations and all individual scenario limits stay recorded
unchanged. Runtime memory/CPU and compile limits are unchanged.
All six original jobs in the separate `d36856486` run `38447782933` pass.
Original archive identity, native execution/schema/TLS and process-loss audits
confirm recovery after the persisted five-minute lease on every database.
Complete per-profile HTTP sidecar audits were not claimed. That run covers the existing
corpus, rather than the expanded corrected marker cohort. The expanded runner
wires eight portable cases across three runtimes and two service clock cases
across both HTTP runtimes into all six configurations, using one explicit
2.5.16 embedded manifest. Its prospective whole-job ceiling is 75 minutes;
all scenario, phase, lease-wait and resource limits remain unchanged. Existing
45/55-minute run allocations remain unchanged. The first expanded `a96d38ebd`
run `38456291140` fails all six corrected-cohort stages; its PHP feature and
both public boundary gates pass. The original PHP case contains an extra repair
after a killed historical producer's forwarded poll blocks the later parent.
The prospective fixture now retires that registration before the activity
resumes, preserving strict history and existing deadlines. A fresh published
PHP run passes all six marker cases and 99 reader controls, including that
handoff without a repair event. Expanded matched confirmation remains pending.
MariaDB also records a fixture DB OOM under its unchanged 512 MiB cap during
the later pressure stage; TLS is skipped. This does not explain historical
503s. The runner now retires its corrected cohort resources immediately after
that stage, including failure, before subsequent independent pressure checks.
Completed local bulk recordings and archives have been deleted; code, fixtures
and readable GitHub outcomes remain. Earlier local retention promises no longer
apply. New scratch is removed after its immediate inspection.

The expanded `718ea3608` run `38469976743` passes both PostgreSQL jobs and MySQL 8.4.
SQLite passes the published PHP eight portable, two clock and four activity
expiry cases, then fails native discovery because the new stage addressed the
process intentionally killed by the preceding recovery test. The replacement
process is running and the prospective runner now addresses it. MySQL 8.0 and
MariaDB exit their PHP recorder with 137 while writing incomplete JSON after
the final case snapshots complete. The recorder was automatically removed, so
its OOM state is unavailable. Their database and PHP service states are healthy;
later pressure controls pass. No product or historical failure cause is inferred.
Originals keep their recorded dispositions.

The recorder now writes one case at a time to avoid creating another complete
serialization of all retained HTTP bytes. A bounded 48 MiB heap control preserves
32 MiB of request bodies, typed int64, null and Unicode, and failed/empty records.
The corrected HTTP profile retains the recorder's exit/OOM state before removing
it. These are runner changes, with unchanged native bytes, fixture semantics,
actor resource limits and original deadlines. Fresh matched qualification is
required before promotion. Immediate artifact inspections are disposable.

The first incremental-writer revision `3a9370f7a` fails recording with an undefined
optional database-resource field. Unlike whole-object JSON serialization, its
first implementation tried to pass that value to the file API. The correction
omits undefined optional properties and preserves explicit null, with actual
empty, passing and failed record shapes in the encoder checks. These failed
originals are not product regressions or successful matched comparisons.

General's PHP-only dependency update #437 is merged at `158f4c6f2` and included
in the current working branch. It leaves the pinned corrected and historical
cohorts unchanged. The combined tree requires fresh candidate qualification
before #435 promotion; no Server 2.6.3 image qualification is inferred.

The separate actual elapsed-deadline HTTP profile passes fresh matched
published PHP/native SQLite recordings at `67cfd7b95`: four original expiry
families, 142 corruption controls, and independent SQL verification of 19
events per target and unchanged database bytes. Native now returns the ordinary
structured heartbeat refusal atomically, while preserving cooperative
stale-attempt conflicts. All 85 native execution and 35 unit cases pass;
18 database unit cases are explicitly ignored locally. The final restored Rust
tree is byte-identical. Timeout versus closed-attempt diagnostics describe the
actual retained lifecycle state. This profile keeps the parent timeout delivery
without authoring its result. The reusable embedded counterpart now passes
fresh PHP/embedded SQLite comparison at `54c0a3e90`, with 157 reader controls
and independent readback of four originals/19 events per target and zero
repairs. It authors through a real queue job and uses public worker bridges.
New shared three-mode/all-six wiring, retry/duplicate/renewal and final/main
gates remain pending. Existing frozen profiles remain unchanged.

[#434](https://github.com/durable-workflow/server/pull/434) is delivered at
`c6dc5e451bf9ba8651f1f84e386ed6c274f3992c`, preserving reviewed tree
`a823f73d288be436994e1272e020f462991b1dd3`. All six final-head and automatic
main database jobs pass. Independent main original-artifact audits verify
270 paired observations, 540 original runs, 3,300 HTTP sidecars and 11,803
member hashes across the namespace and selected-run signal profiles. Each
database passes 79 native execution cases, both 49-case comparators and 516
actual-record reader checks, with applicable pool/reset and TLS gates. Main
SDK identity, PHP/source and boundary gates pass. The
[delivery receipt](https://github.com/durable-workflow/server/pull/434#issuecomment-6143056720)
preserves earlier failed and cancelled originals and their separate limits.
Frozen Rust's optional variadic signal contract still lacks an invalid-argument
refusal gate; PHP/Python-author recipes omit a required argument.

The next active slice is the separate
[corrected marker/range cohort](rust-server-corrected-markers.md). It adds
supported current readers and Rust authors to genuine numeric, duplicate-bound,
clock, retry-policy and same-run repair cases. Existing frozen actors and
histories remain unchanged. Five positive marker cases pass through the PHP
reference and separately installed embedded roles. The additional published
Rust completed-activity and timer-winner/pending-activity range directions
pass the [original PHP recipes](https://github.com/durable-workflow/server/pull/435#issuecomment-6148196089),
and the [embedded counterparts](https://github.com/durable-workflow/server/pull/435#issuecomment-6148451599)
pass the unchanged PHP definitions. Those separate local recordings preserve
35-second scenario budgets, original task repair, full prefixes/microclocks,
typed values and neighboring runs; 100 actual-record negative controls pass.
Both range families now also pass separate native SQLite recordings at
`4f5d813a8`, with 56 actual-record controls and independent database readback.
Native retains unstructured Rust replay failures and original-task repair,
six-digit history timestamps and genuine legacy policy omission. All 81 native
execution tests and 33 unit tests pass locally, with 18 database tests ignored.
Earlier native admission, timestamp and policy-presence failures remain failed.
These observations do not qualify a matched native/database cohort. The embedded explicit
retry-policy mismatch remains a retained failure; its coordinated
[Workflow source correction](https://github.com/durable-workflow/workflow/pull/776)
is delivered and normally published as 2.5.16. A separately named 2.5.16
embedded input now passes all eight applicable marker/policy/range cases at
`a4c43d26c`, including strict policy refusal, exact restoration and selected
repair on the same original. All 931 installed runtime/resource bytes match the
published tag; 176 actual-record controls and independent SQL checks pass.
The original 2.5.14 profile and its failed run remain unchanged. These backend
handler cases run real independent `queue:work --once` database workers, as
confirmed by the retained RUNNING/DONE receipts. They do not qualify sustained
queue-worker draining. Current Python's
fixed-start clock/UUID cases pass the PHP reference. Native marker/clock
admission now has a candidate authored activity deadline implementation. It
snapshots all four timeout options, fences elapsed completion/failure/heartbeat,
retains the global deadline through same-execution retries, and renews only the
heartbeat deadline with progress. Ordinary activity leases follow PHP's
300-second contract. Local SQLite passes 85 execution cases and 35 unit cases,
with 18 database tests explicitly ignored. Four deadline cases cover terminal
expiry, full-policy retry across reopened storage, heartbeat renewal and
committed receipt ordering. All ten corrected native SQLite HTTP marker, policy,
range and Python clock cases now pass at `dbe6ff3a3`, with 194 actual-record
corruption controls and independent query-only readback of all 84 events and
12 original activities. Four authored-deadline regressions also pass on isolated
MySQL 8.4. Earlier missing-snapshot and new MySQL JSON-path conversion failures
remain failed; the latter is corrected without changing historical diagnoses.
The pending elapsed-deadline fixture still needs actual shared PHP/native/embedded
recordings; these local controls do not qualify that cohort. Embedded UUID semantics
and new matched all-six/final/main qualification remain ahead.
Published PHP remains the default; broader runtime
parity, migration, matched performance and release/cutover remain unqualified.

[#427](https://github.com/durable-workflow/server/pull/427) is merged and its
[fresh-main qualification](https://github.com/durable-workflow/server/pull/427#issuecomment-6130548960)
is complete at `3afb6bb41bd4dd5cc75234bacf0ae5e28df1164e`, tree
`6483bca52bf8e2a22b160acb6d9286c5f47e199d`, identical to the reviewed candidate.
All six database configurations, PHP/source and boundary checks pass. Original
artifact review verifies nine failed-update SDK directions and their embedded
counterparts: 162 observations and 1,188 full HTTP sidecars. Update, query and
signal regressions also pass. The earlier MariaDB storage 503s remain unresolved;
the clean qualification does not establish a repair. Published PHP remains the
default, with no Rust release, takeover or performance claim.

The [next reviewed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6130197979)
is [paired named-namespace SDK signals](../tests/Fixtures/ServerParityProfiles/published-sdk-namespace-signals/README.md).
Nine client/author definitions cover distinct original runs with the
same type and queue, wrong-namespace signal refusal, an empty foreign-Worker
poll and cold completion with exact independent Avro values. The ordinary
fixture actors accept an explicit namespace through the unchanged SDK API.
The paired recorder and strict reader are implemented. All nine published
client/author directions and canonical embedded counterparts pass the SQLite
reference baseline, with 36 original runs, complete transport and persisted
relationships checked. All 4,755 reader guards and the SDK-dependent forced
setup-loss regression pass with the locked PHP SDK. The initial path/typed-error
reader failures remain unchanged. Native current-run signals, canonical refusal
metadata and untimed condition waits are implemented and the new profile runs
in all six database jobs. Candidate qualification and promotion are recorded below. Expanded jobs
have a 45-minute ceiling after the selected-run profile expanded their work;
original run budgets and runtime caps stay fixed. The earlier 35-minute
MySQL cancellations remain cancelled records, as detailed in the
[selected-run qualification](rust-server-selected-run-signals.md).

Exact `6bac8f677` [run 38304548447](https://github.com/durable-workflow/server/actions/runs/38304548447)
passes five jobs and fails MariaDB. Independent original artifact review across
SQLite, PostgreSQL 16/17 and MySQL 8.0/8.4 verifies 135 paired observations,
270 original runs, 1,530 full HTTP sidecars, 9,585 wire exchanges and all 8,352
original member hashes. Each also passes 78 native execution tests.
MariaDB passes all PHP/embedded counterparts, but native's first failing update
in the Python-author/PHP-client direction returns an opaque storage 503. Its
fixed diagnostic identifies the post-commit update receipt lookup; the reason
that lookup found no row remains unknown. The first native namespace setup
separately exhausts its 75-second outer recorder ceiling without a phase sidecar.
Those [original failures remain unchanged](https://github.com/durable-workflow/server/pull/429#issuecomment-6132750228).

The recorder now preserves setup checkpoints and HTTP/arrival journals through
forced child loss, consolidating them only after a final control receipt.
The real locked-SDK regression passes and fails on the prior recorder source.
Always-run native state/limit receipts retain process-loss evidence. This changes
evidence retention, not SQL, HTTP semantics, retries or deadlines.

The subsequent original SQLite job fails in frozen PHP patch replay, before
namespace execution. Its original PHP Worker recovers a documented SQLite
workflow-poll pressure response and the cold replacement completes the same
run. The [reviewed reader correction](https://github.com/durable-workflow/server/pull/429#issuecomment-6133295115)
extends the existing strict PHP pressure check to that original PHP phase,
excluding language SDK producers. Exact response shape, original registration,
bounded retry hint, diagnostic, authority and successful same-phase recovery
remain required. Generic storage failures remain refused; Python/Rust retain
their distinct same-request retry contract. The unchanged observation passes
the corrected reader and still fails the original reader. All 4,817 pure-reader
guards pass; the unchanged SDK-dependent retention regression retains its
separate real-runtime qualification. No tuple or original deadline changes.
The original job and record remain failed.

An isolated exact SQLx 0.9.0/Tokio 1.53.2 MariaDB check now reproduces a pooled
stale read after an ordinary BEGIN reply is delayed and its future is dropped.
The returned session has a physical transaction but no logical transaction;
it misses an independently committed row that a fresh connection sees.
Rollback clears that stale view. The native pool now resets sessions before
return, with a five-second reset budget and disposal on failure. Pool size,
isolation, acquisition/lock budgets and public receipt/retry contracts stay
fixed. This adds a return-time database statement that later performance
qualification must measure. The real native-factory regression passes with
reset and fails `RowNotFound` without it; an actual committed row survives a
lost COMMIT reply. The [correction contract](https://github.com/durable-workflow/server/pull/429#issuecomment-6133464483)
precedes implementation. The injected mechanism is proven; its connection to
the original native failure is unestablished.

The first reset candidate passes SQLite/PostgreSQL but fails all three
MySQL-family jobs at the TLS disposal check. Exact TLS 1.3 reproduction confirms
that reset times out while the driver's hard-close path still awaits TLS I/O;
flushing before the gate produces the same failure. A scoped
[native driver patch](../rust/vendor/sqlx-mysql/DURABLE-PATCH.md)
drops the owned transport on hard close, matching SQLx's immediate-close
contract. Both unchanged TLS controls then pass with socket closure, freed
capacity, a different replacement connection and preserved committed data.
The official archive/VCS and licenses are retained; graceful close and TLS
trust/hostname checks stay unchanged. The original CI/probe failures remain
failed, and the [contract precedes adoption](https://github.com/durable-workflow/server/pull/429#issuecomment-6135933370).
Exact `64ca245e7` [run 38331809158](https://github.com/durable-workflow/server/actions/runs/38331809158)
passes all six original database jobs without retries. Independent original
artifact review verifies 162 paired namespace observations, 324 original runs,
1,836 complete HTTP sidecars, 11,421 wire exchanges and all 10,053 member hashes.
Every configuration passes 78 native execution tests; all three MySQL families
pass nine schema/pool qualifications including the unchanged TLS disposal case.
Pressure, role/fencing, real node recovery, PHP takeover refusal and TLS
trust/hostname gates pass. PHP/source passes 2,744 tests / 61,718 assertions,
with six existing deprecations and one skip. All three original SDK adapter
artifacts retain their frozen installed/source/archive identities and hashes.
The [candidate receipt](https://github.com/durable-workflow/server/pull/429#issuecomment-6136523527)
qualifies these cases; promotion-head, review/merge and fresh-main checks remain
separate gates. Fixture bytes and all original budgets remain unchanged.
The subsequent promotion head's PostgreSQL 17 job fails before namespace
execution in the forced setup-loss regression. Its proxy arrival journal is
visible before the held-request server callback necessarily runs; the test had
assumed those observations were simultaneous. The
[synchronization correction](https://github.com/durable-workflow/server/pull/429#issuecomment-6137161279)
waits for both observations before forced loss, preserving the original
10-second observation and 15-second test ceilings and every retained-stage,
request, deadline, credential and absent-response assertion. A controlled
250-ms forwarding delay reproduces the prior assertion failure and passes the
corrected guard; three ordinary locked-SDK runs pass all 49 selected tests
without skips. This changes only the test barrier, with no recorder, native
runtime, fixture, tuple or retry change. The failed original remains failed.
The subsequent exact-head and fresh-main matrices pass, as recorded in the
#429 delivery receipt above.

The [next proposed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6136891726)
is run-targeted signals. One
[published PHP/PHP reference pair](https://github.com/durable-workflow/server/issues/325#issuecomment-6137058011)
preserves two original completions, six cold authors, 127 wire exchanges and
zero task repairs. Accepted selected-current-run calls retain instance-scoped
commands; unknown, invalid and inactive signals persist rejected commands and
advance sequences without workflow history. Wrong-namespace and mismatched-run
guards mutate neither original run. A separate shared profile must check these
relationships, not just HTTP statuses. Six supported PHP/Rust client by
PHP/Python/Rust author directions, canonical embedded behavior and native/all
database qualification remain pending; Python 2.5.4 has no run-targeted signal
client method and remains an ordinary author in applicable directions.

[Workflow 2.5.13](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6137098532)
is a qualified published marker-bound correction at `19ba333a6fcae3447d35e499d8e1fb4969b96e48`.
The unchanged Server image still bundles 2.5.12; its dependency/rebuild and
consumer follow-through belong to the PHP maintainer. Any corrected shared
marker profile uses a separately qualified exact cohort, preserving frozen
marker/range histories and failures. The
[Rust SDK #80 published tuple](https://github.com/durable-workflow/sdk-rust/issues/80#issuecomment-6137962597)
is now qualified: 3.5.2 / `b9a82ddfd4589351ff64b33685464ac201ce40c4`,
registry archive `e9df4b3e10aada0d7f459a5fc5c8d58b4ec6829422bfc7edb5088047ad5b63b9`.
Six actual published-Server originals cover repeated `get_version` in `[-3,7]`
with one decision, fresh zero/deprecation-first helpers, and explicit same-run
range repair after completed-activity or timer-winner checkpoints. Constructed
version-1 differing-bound aliases are reader controls, not a genuine producer.
The [bridge-prefix receipt](https://github.com/durable-workflow/sdk-rust/issues/80#issuecomment-6138080982)
adds two cold mock-delivery reads of each unchanged marker prefix; full history,
clock, backend admission and takeover remain separate gates.
The [retry-snapshot agreement](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6138245641)
preserves all eight PHP backoff entries with `max_attempts=1`, requiring exact
explicit Rust authoring and strict stored-policy comparison. The SDK owner
retains implementation and qualification; captures cannot be trimmed or compared
by prefix. Corrected shared integration still requires the exact qualified PHP
Server consumer cohort. Frozen actors, dependencies and failures stay unchanged.

The [query-authority proposal](https://github.com/durable-workflow/server/issues/433#issuecomment-6138263790)
and [native/shared acknowledgment](https://github.com/durable-workflow/server/issues/433#issuecomment-6138337171)
agree on immutable original timing, six-digit UTC fields, inclusive authority
expiry, non-renewing protocol-1.20 observation and committed-receipt ordering.
Native implementation is in [#463](https://github.com/durable-workflow/server/pull/463).
Candidate `76c6d35a` passes all thirteen shared jobs in run `38713356493`,
including six execution and six codec/deadline-health database cells. All six
execution artifacts independently match the tested native tree and binary,
with 29 passing comparisons and 20 successful profile statuses each. The
query profiles include normally installed PHP, Python and Rust SDKs, original
timing, delayed poll delivery and late handlers. Conditional prepared-local
and cancellation source qualification remains skipped.

Combined candidate `ee219c0b` incorporates qualified PHP security source
`f7d1bfc7` through merge `500299d6`. Its exact whole tree now passes all thirteen
shared jobs in `38721415410` and every applicable ordinary check. Each of the
six independently audited execution archives has 29 passing comparisons and
20 zero exit records, with exact merge/native/binary identities. The
[qualified checkpoint](https://github.com/durable-workflow/server/pull/463#issuecomment-6191285509)
retains all six digests and earlier failed-original limits.

The next integration includes qualified release-workflow-only main `28914d25`
through `c7e81c24`. Native, shared, runner and OpenAPI bytes remain unchanged;
that whole tree still requires its own qualification before promotion. The
published PHP image acceptance remains separate from these frozen reference
profiles. No current image adoption, complete query capability, performance,
migration or release qualification follows here.

The later [installed-consumer observation](https://github.com/durable-workflow/server/issues/433#issuecomment-6185525047)
passes twenty Python 2.5.15 / Rust 3.5.5 originals across separate PHP 2.8.0 and
candidate native SQLite APIs. Timely 1.20/1.19 and expired poll/async/sync
delivery preserve original four-second query and thirty-second whole budgets,
typed values, history and claim identity without late submissions. The earlier
short poll-transport observer failure stays failed. Subsequent fixture/build
sharing edits and the combined source require fresh hosted qualification;
external loading, pressure and full capability qualification remain open.

Earlier MariaDB/MySQL failure causes remain
unestablished; the new lookup site does not retroactively establish them.
Full namespace authorization, other families, consumers,
migration, multi-node acceptance and matched performance remain open.

[#422](https://github.com/durable-workflow/server/pull/422) is merged and its
[fresh-main verification](https://github.com/durable-workflow/server/issues/325#issuecomment-6119269193)
is complete at e3ae32999d1e14813bf84498a716ad62cddf9813. Candidate and main share
tree eb07a0d709a18a09caac850da02d6c8092799e9a. All six database jobs, PHP/source
and boundary checks pass. Each original artifact independently passes 27 query
observations / 144 full query sidecars, 27 signals / 90 full signal sidecars,
and 76 native execution tests. All 2,041 selected original/archive hashes match.
Fresh main PHP/source passes 2,706 tests / 61,424 assertions. The SDK artifact
passes 297 original member hashes, nine thin comparisons and ten source hashes,
with frozen installed/source/archive identities unchanged. Historical failed
and cancelled jobs retain their original outcomes.

The update slice defines nine published SDK update-client/author
directions in [the qualified profiles](../tests/Fixtures/ServerParityProfiles/published-sdk-updates/README.md).
Its contract preserves duplicate request IDs and original typed results across
cold committed checkpoints, then an ordered finishing signal. Request arrival
is observed separately from durable acceptance.
[#424](https://github.com/durable-workflow/server/pull/424) implements normal
published SDK clients and four cold author processes, complete wire and arrival
capture, a strict reader, canonical embedded PHP and an isolated profile in
all six database jobs. The [final candidate qualification](https://github.com/durable-workflow/server/pull/424#issuecomment-6123569353)
passes at 93c71874b8b90b4383478deb00f7855a75051100. The
[fresh-main qualification](https://github.com/durable-workflow/server/pull/424#issuecomment-6124273589)
is complete at 5fd56e0c37933fdd0af459c965447c6168c4ad81; both have tree
61bdf074734941b493d8d0dd681303c9d61c6509. All six fresh-main original database
jobs pass on their first attempt. Across all six configurations,
162 update observations / 1,188 complete SDK sidecars, 162 query observations /
864 sidecars and 162 signal observations / 540 sidecars pass. Each database
also passes 76 native execution tests; all 3,497 original/archive hashes match.
PHP/source passes 2,716 tests / 61,466 assertions and both boundaries pass.
SDK preparation independently verifies 297 original member hashes, nine thin
comparisons and thirteen source hashes. The original candidate MySQL 8.0
25-minute cancellation remains unqualified; its single bounded retry passes
with the original tuple and budget. The
[source-only PHP integration window](https://github.com/durable-workflow/workflow/issues/753#issuecomment-6124274148)
is confirmed for a separate source cohort. Frozen published tuples stay unchanged.
The first completed update matrix fails in native discovery: unchanged Python
clients require the published `accepted`/`completed` wait-stage list before
submitting an update. Native discovery omitted that list despite implementing
both stages. The [original review and correction contract](https://github.com/durable-workflow/server/pull/424#issuecomment-6121480311)
preserve the failed original. Independent MariaDB review verifies all nine PHP
Server directions and nine embedded cases, plus 99 original PHP SDK sidecars;
that partial evidence does not qualify native or the failed job. Native now
publishes the implemented stages and checks discovery in its existing update
lifecycle/fencing regression. The failed original remains separate from the
completed candidate and main qualification.

The [failed-update boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6121730771)
preserves continued parent execution and is qualified in #427. The
[cohort contract](../tests/Fixtures/ServerParityProfiles/published-sdk-update-failures/README.md)
requires ordinary frozen SDK clients and authors, original accepted failure
identities, pending and completed duplicates, cold replay, and a later successful
update plus finishing signal on the same run. Preliminary SQLite PHP/PHP and
embedded recordings pass the complete lifecycle reader; the first PHP author's
incorrect accepted-input assumption remains a separate failed record.
[#427](https://github.com/durable-workflow/server/pull/427) implements native
failure-command validation, durable failure links, terminal HTTP 422 receipts,
duplicate identity and parent continuation, with database reopen regressions.
A shared normalization corpus is checked against the actual PHP failure rules.
Language-specific exception type, class and message remain observable; a failed
handler emits no successful `UpdateApplied`.

The nine-direction failure profile now runs after the successful-update profile
in all six database jobs. Its original candidate qualification and independent
artifact review permit promotion into the qualified profiles. The expanded
jobs have a 30-minute whole-job limit to include this additional workload;
individual scenario deadlines, frozen tuples and resource limits are unchanged.
The preceding `dc881518` run retains its original failures and cancellations
under the original 25-minute limit. The expanded 6121f343 matrix retains all six
failed runtime jobs, with
native test/build stages and PHP/source passing. A separate local diagnostic
passes all nine directions on published PHP Server, embedded PHP and native,
followed by a passing three-record comparison. Independent inspection binds
198 original HTTP sidecars and 27 persisted runs; all 349 retained raw archive
members match their original hashes. It
[reproduces a reader mismatch](https://github.com/durable-workflow/server/pull/427#issuecomment-6127074676)
on a completed native receipt: the frozen Python SDK hydrates nullable
`result` views recursively, including canonical control metadata. The reader
now checks both decoded views against their original Avro frame and exact typed
result, while preserving the original wire body and all other metadata. Altered
canonical values, frames and int64 values have focused refusal guards. The
original local failure remains preserved. A working GitHub connector path now
retrieves original CI bytes despite workspace download timeouts: the original
612 SQLite artifact independently confirms the hydration mismatch; its MySQL
8.0 log shows a frozen CLI download HTTP 504 before fixtures ran. Neither failed
whole job is relabeled.

The corrected-head SQLite job fails earlier in repeated-patch PHP polling,
before the failed-update profile. Its
[reviewed original recovery contract](https://github.com/durable-workflow/server/pull/427#issuecomment-6127538862)
preserves a declared SQLite lock-pressure reply, actual PHP retry diagnostic
and subsequent successful poll with unchanged worker, queue and options. The
reader checks original registration protocol/capabilities and a bounded retry
hint, acquires no hidden task, and retains every original marker, activity,
history and completion check. Published Python/Rust consumers retain their
separate same-request and HTTP Retry-After requirements. Frozen fixture bytes,
tuples, runtime payloads and scenario budgets are unchanged. Original failures
remain immutable. The subsequent 38def797 original matrix has five successful
database jobs and one MariaDB failure. In native Python/Python's later successful
update, two opaque storage HTTP 503 responses precede the cold author and the
strict recorder refuses. Original failure and cleanup-expired sidecars remain
intact; the original logs do not establish a driver cause or an independent SDK
defect. A [reviewed storage diagnostic](https://github.com/durable-workflow/server/pull/427#issuecomment-6128248670)
now emits only fixed error categories and bounded driver codes, preserving the
HTTP response and excluding error text, SQL, payloads and authentication data.
Five diagnostic tests, 30 library tests and 77 SQLite execution regressions pass
locally. The original 929638262 matrix and normal source checks pass. Independent
inspection verifies 162 failed-update observations and 1,188 original HTTP
sidecars across all six databases, plus successful-update, query and signal
families: 648 observations, 3,780 sidecars and 7,925 original member hashes in
total. Actual runner `1f091ae1` has reviewed tree `64a6729b` on base `5fd56e0c`.
Current-main integration remains a separate checkpoint. The original MariaDB
storage fault remains unresolved; these clean workloads are not a failure
correction or permission to accept generic 503s.
Validators, full query policies, queued controls,
unchanged consumers, migration,
multi-node acceptance, matched performance and release/cutover remain open.

[#408](https://github.com/durable-workflow/server/pull/408) qualifies nine
published parent/child language directions using the one-activity component
tuple. Its original
parent schedules one child on a separate queue, a child-only process commits
the exact typed result, and a cold parent-only replacement completes the same
run. Original call/link/child identities and full parent/child histories are
required. All six [candidate configurations](https://github.com/durable-workflow/server/actions/runs/38161889708)
and [main configurations](https://github.com/durable-workflow/server/actions/runs/38163439081)
pass, along with PHP/source and boundary checks. Independent official SQLite
and MySQL 8.0 artifact rechecks each pass 27 child records; SQLite binds all 54
actual PHP/native phase sidecars. The complete retained SQLite profile matches
all 97 official ZIP members and passes compressed byte round-trip. New recordings capture
effective database memory/swap limits separately from frozen historical
manifest envelopes. Namespaced children, retries, failures, cancellation
propagation, other policies and parallel groups remain separate gates.

The child reader exposed a native claim-field gap in retained published
observations: PHP uses `task_queue`, while native exposed only `queue`.
Native now adds the published field while retaining its existing alias.
The reader checks the actual poll selector and claimed durable queue, and
a database/HTTP regression covers separate parent/child queues and original
parent resumption. Both inspected configurations pass this regression and all
72 native execution tests. The [contract decision](https://github.com/durable-workflow/server/pull/408#issuecomment-6111939851)
and [completed main qualification](https://github.com/durable-workflow/server/pull/408#issuecomment-6112398187)
are tracked in #408. This qualifies one bounded child scenario in nine language
directions; it does not establish nine capability families or performance.

The additive timer profile qualifies normal published SDK authoring in PHP,
Python and Rust. Each requests 5.25 seconds of durable sleep, rounded to six
whole seconds, then cold-replays the original timer and run with a distinct
author process. The [reviewed next contract](https://github.com/durable-workflow/server/issues/325#issuecomment-6112280492)
requires original timer identity/deadline, full unchanged history, no early
firing or rescheduling, actual fenced receipts and exact int64/Unicode/null
Avro values. Worker adapters now call each published SDK's normal sleep API;
dedicated reader guards and isolated profiles are integrated into all six
database configurations. The existing SDK adapter build is reused. Local
reader guards pass, including corrupt original deadlines, stale receipts,
rescheduling, substituted Avro frames and one nanosecond early firing.
[#411](https://github.com/durable-workflow/server/pull/411) is merged with all six
candidate database configurations, normal PHP/source and boundaries passing.
The independently inspected current SQLite artifact passes nine timer
observations, all twelve full phase wire sidecars and all 55 original ZIP
member/archive roundtrip hashes. The MariaDB artifact separately passes nine
observations, 72 native execution tests and TLS checks with inspected 512 MiB
memory/swap caps. Candidate, actual PR runner and merged main have the same
qualified tree. [Automatic main qualification is complete](https://github.com/durable-workflow/server/pull/411#issuecomment-6113537310):
all six Shared configurations, PHP/source and boundary pass on that merge.
Candidate PHP/source passes 2,683 tests/61,212 assertions; main passes
2,683 tests/61,208 assertions. Both report six existing deprecations and one
skip. Completed source resources and branches are removed.

[#415](https://github.com/durable-workflow/server/pull/415) adds all nine
published PHP/Python/Rust signal-client and workflow-author combinations. Its
[initial correction run](https://github.com/durable-workflow/server/actions/runs/38182926674)
passes all six database configurations at `2de70bf8d029728c9cf7a8890461ae56628e34a8`,
with PHP/source and both boundaries passing. PHP reports 2,685 tests/61,266
assertions, six existing deprecations and one skip. Independent original
[SQLite inspection](https://github.com/durable-workflow/server/pull/415#issuecomment-6115075064)
passes all 27 observations, binds all 90 HTTP phase sidecars and verifies all
133 retained original/archive member hashes. Separate
[MySQL 8.0 inspection](https://github.com/durable-workflow/server/pull/415#issuecomment-6115110451)
passes 27 observations and 135 member hashes, with actual 1 GiB database limits
and TLS checks. Both original native outputs pass all 75 execution tests.
Complete signal profiles, tests and thin SDK proof are retained; duplicate
downloads and extraction scratch are removed. [Final candidate qualification](https://github.com/durable-workflow/server/pull/415#issuecomment-6116076532)
passes all six configurations at `75cb353b6c7fde323aa11bd98ff15372ee37d8cb`.
Merged main `567f3cd49e6bd49a35658d56c4ae638395511e0f` has the same tree
`f38c3e1555632400040377b4c5d04278f6e1061a`. Its [fresh main verification](https://github.com/durable-workflow/server/pull/415#issuecomment-6116428625)
passes five databases, PHP/source (2,685 tests/61,266 assertions) and boundary;
SQLite fails in the frozen PHP base child-cancellation fixture before signals.
Main all-six qualification remains unproven. Independent main MySQL 8.0
inspection verifies 27 signal observations, 90 complete HTTP sidecars and 135
original/archive hashes; SDK preparation independently verifies 297 original,
nine thin-byte and seven current-source checks.

An earlier integration exposed a separate frozen-PHP startup overlap:
[original SQLite failure](https://github.com/durable-workflow/server/pull/415#issuecomment-6115559242).
Five directions pass and the sixth completes its original 13-event run, but
the first worker claims `WorkflowStarted` before the successful start response;
PHP subsequently adds ten declaration fields to that same original event.
The strict full-history check fails. Original native output passes 75 tests,
but this failed profile never reaches embedded/native signal execution.
The full failed profile and wire bytes remain retained, with all 62 original
and archive-roundtrip hashes verified; duplicate downloads and scratch are removed.
The [PHP finding is handed off](https://github.com/durable-workflow/server/pull/420#issuecomment-6115559389)
without claiming a current-release reproduction or changing PHP/SDK code.
The [reviewed fixture decision](https://github.com/durable-workflow/server/pull/415#issuecomment-6115559242)
holds the original real workflow poll in the byte-preserving observer until
the matching successful start receipt. This bounds the cohort to post-start
delivery and cold replay. Full metadata/history guards remain strict; startup
overlap remains unqualified, and the failed record is not relabeled.
Focused source validation passes 61 signal models and five socket-based
transport cases; the current Node contract suite passes all 4,681 tests and
the changed PHP recorder passes syntax checking. The native Rust and SDK
adapter sources remain unchanged. The later candidate qualifies the bounded
post-start cohort on all six databases; the original overlap failure remains
retained and outside that claim.

The fresh-main SQLite failure is a distinct frozen Server 2.5.14 / Workflow
2.5.5 / PHP SDK 2.2.6 reference finding. Its original child cancels its pending
timer and closes, but an accepted cancellation is followed by an identical
rejected command and the SDK receives 409. A separate real SQLite writer-lock
fault reproduction proves that PHP can retry the whole cancellation after its
commit when publishing the parent's delivery hint encounters contention. One
ordinary SDK request produces two commands; no retransmission or observer
reentrancy occurs in that reproduction. This identifies a matching source
boundary, without claiming the original run's exact lost exception or a current
released-image reproduction. The [PHP correction is handed to General](https://github.com/durable-workflow/server/issues/325#issuecomment-6116357816),
who acknowledged ownership of correction and current-release qualification.
The original failed main record, frozen tuple and strict accepted/duplicate
receipt guards remain unchanged. Release and cutover qualification stay held.

The original normal worker registers its declaration before the observer starts
the run, and its first workflow claim follows the observed successful start
receipt. Two distinct normal clients send identical exact Avro payloads; three
cold author processes retain both accepted identities and full original
history through completion. The [reviewed scope](https://github.com/durable-workflow/server/issues/325#issuecomment-6113352490)
uses the instance-current-run API, preserves Python's absent client return and
retains actual server receipts. Canonical embedded direct-signal histories stay
intact; the separate manifest preserves prior tuples and resource envelopes.
Normal accepted signals require the published HTTP 202 contract. All 60 signal
reader models and all 4,482 reader tests pass, alongside the recorder, language,
shell and Docker isolation checks. Exact SDK preparation, source and package
hashes pass; those model/build results remain distinct from runtime evidence.

Normal Rust authoring exposed two native gaps. The
[occurrence correction](https://github.com/durable-workflow/server/pull/415#issuecomment-6114363122)
retains nonempty authored condition occurrence IDs through opening, restart and
matching satisfaction, omitting absent/null/empty values as PHP does. The
[variadic correction](https://github.com/durable-workflow/server/pull/415#issuecomment-6114821113)
accepts zero or more tail values after required positional arguments, validates
each value without coercion and preserves int64/float/null distinctions. Three
database/HTTP regressions cover repeated waits, cold reopening, duplicate/stale
completions, optional occurrence types and exact variadic values/refusals.
Earlier failed records and the separate frozen SQLite patch-pressure failure
remain unchanged. Defaults, optional fixed prefixes, non-final variadics,
older-protocol occurrence routing and broader signal policies remain separate
unqualified gates. Normal published SDK query directions are the next breadth
slice; matched performance and cutover remain open.

[#401](https://github.com/durable-workflow/server/pull/401) qualifies all nine
fresh PHP/Python/Rust workflow-author and separately
routed activity-worker directions. An original author schedules one activity,
an activity-only process commits it, and a cold workflow process completes the
same run with unchanged history prefixes and exact typed Avro values. Installed
package identities and real HTTP phase receipts are required. Embedded executes
the canonical PHP definition and labels language transport inapplicable. Its
separate tuple preserves every earlier cohort. All six candidate configurations
pass [Shared qualification](https://github.com/durable-workflow/server/actions/runs/38151936175),
[PHP/source checks](https://github.com/durable-workflow/server/actions/runs/38151936179)
and boundaries. Independent inspection of the official SQLite artifact rechecks
all 27 logical records and binds all 54 separate PHP/native wire sidecars to
their actual process, phase, original run and recorded request/receipt fields.
The complete retained profile compares exactly to the official ZIP members and
passes a compressed archive byte round-trip. Its raw objects remain intact
despite PHP's associative empty-contract-map observation projection.
Merge `b5a6e8d1e478d43af5219c560ec392e6d17258aa` retains the qualified tree;
post-merge checks and their outcome are tracked in #401. Its first main run
passed five runtime configurations; PostgreSQL 16 completed every comparison,
cleanup and artifact upload, but exceeded its 15-minute job limit. Its retry
retains that exact merge; the outcome is tracked in #401. PostgreSQL receives
the same bounded
20-minute allowance as MySQL, including cleanup and evidence retention.
The MySQL 8.0 fixture database receives the existing MySQL 8.4 allowance of
1 GiB, with swap disabled: its 768 MiB container was killed for exceeding that
limit after all comparisons passed, preventing the final trusted TLS probe.
These are CI resource allowances, not performance qualification limits.
This is one authored
workflow/activity scenario in the default namespace, with nine language
directions; it does not establish nine capability families or performance.
Separately routed parent/child SDK directions are qualified in #408.
Normal SDK timer authoring and cold replay are qualified in #411; normal signal
client/author directions are implemented with runtime qualification pending.
Broader signal policies and query/update clients and authors remain ahead.
The observed activity-only Python polling defect is handed to
[SDK Python #119](https://github.com/durable-workflow/sdk-python/issues/119).

[#399](https://github.com/durable-workflow/server/pull/399) implements and
qualifies native pending mixed-selection repair. Its timer winner resumes a
cold incompatible author while the separately routed activity remains pending.
Explicit repair restores that original blocked workflow task; compatible replay
waits, an activity-only worker executes the original activity once, and a later
distinct task completes the same run. All six configurations pass the
[exact-source Shared gate](https://github.com/durable-workflow/server/actions/runs/38141199951),
[PHP/source checks](https://github.com/durable-workflow/server/actions/runs/38141199897)
and boundaries. The 18 actual PHP HTTP/embedded/native recordings verify this
one scenario, including unchanged waiting history and exact typed Avro output.
Merge `2dbf05dcb4cfab8fc89973e24245438a167e3d37` retains the qualified tree;
[post-merge Shared verification](https://github.com/durable-workflow/server/actions/runs/38142464272)
and [PHP verification](https://github.com/durable-workflow/server/actions/runs/38142464238)
pass, with all six main configurations and actual retained pending records.

[Workflow #755](https://github.com/durable-workflow/workflow/issues/755) is
closed as delivered: published Workflow 2.5.10 and Server 2.5.20 repair the
blocked workflow task before the older activity. Reference #397 passes all six
candidate and main configurations. The pending cohort keeps those exact
published artifacts and PHP SDK 2.2.7; its earlier failed recordings and
completed-activity range case #392 remain unchanged. Broader selection/repair,
SDK and consumer directions, matched performance, migration and cutover remain
open under #325. Native execution transitions are still serialized.

The pending `worker-registration-fencing` fixture defines the first shared
Server #358 lifecycle slice on a separate exact PHP/SDK source tuple. It retains
an original leased echo across token rotation, heartbeat, terminal refusals,
original receipt replay after replacement, two explicit repair commands and
completion by a real SDK worker. Embedded execution runs the authored echo and
marks HTTP registration inapplicable. The source cohort is separate from
published feature qualification; the default 29 fixtures and frozen baseline
remain unchanged. The
[source-reference matrix](https://github.com/durable-workflow/server/actions/runs/38050246000)
passes on all six configurations in [#363](https://github.com/durable-workflow/server/pull/363).
All artifact digests match; independent comparisons pass 12 source-fencing,
522 default and 18 role-profile observations. These #363 source results cover
PHP and embedded execution only.
[#366](https://github.com/durable-workflow/server/pull/366) persists registration
incarnations in the registration transaction and passes all six main checks.
Development schema
version 4 adds native bookkeeping without changing frozen PHP relations or
rewriting version 3. Older unpublished native databases remain refused without
mutation; qualified in-place takeover remains a separate gate.
[#367](https://github.com/durable-workflow/server/pull/367) implements native
fenced deregistration: original-task recovery, accepted repair command/history,
registration deletion and terminal receipt share one physical transaction.
Completed receipt replay precedes replacement authority lookup. The unpublished
runtime exposes the registration token and fencing capability; it refuses
unknown, superseded and expired tokens without acknowledging shutdown.
Each registration removes at most 64 expired completed or superseded receipts
in its namespace after ten minutes; active identities stay intact. Explicit
native maintenance remains a separate gate.

[#370](https://github.com/durable-workflow/server/pull/370) releases original
workflow leases when the same worker ID registers again, in the same transaction
as token replacement. It preserves the original history and repair count, as
the exact PHP source does. Native restart, namespace and token-insert rollback
cases and the two-case source profile pass on all six configurations; main
verification also passes. Activity and sticky-lease replacement remain open.
[#371](https://github.com/durable-workflow/server/pull/371) adds actual SDK
receipt reconciliation after explicitly client-injected reply loss following
real HTTP 200 commit. A replacement owns the same original task before the SDK
retries its unchanged token. All six PR configurations pass and their official
artifacts independently compare 522 default, 18 role and 54 source observations.
Models and this observed shutdown timing do not qualify performance or the
complete shutdown budget. Main verification is recorded in #371.

[#372](https://github.com/durable-workflow/server/pull/372) qualifies a real
independent native database transition lock through the existing five-second
timeout on all six configurations, including main verification. It checks
concurrent health reads, actual retryable HTTP 503 / Retry-After, unchanged full
original reads/history, original-attempt completion and same-token repair/replay
of another original task. Each cell passes 56 native tests; the independently
compared default, role and source cohorts total 594 observations.

[#373](https://github.com/durable-workflow/server/pull/373) qualifies shared SDK
temporary database pressure on all six configurations. An independent writer
remains held through actual HTTP 503; after release, an original authority task
completes and the SDK retries its original token using a smaller remaining
request budget. Independent artifact comparisons verify 612 default, role,
source and pressure observations. The separate fault manifest declares
five-second reference limits and labels actual before/after PHP application CLI
session samples separately from HTTP/native sessions. SQLite can refuse
immediately despite its sampled busy timeout. Existing binaries and exact
PHP/SDK sources and locks are reused. Main verification is recorded in #373.

[#374](https://github.com/durable-workflow/server/pull/374) qualifies persistent
SDK shutdown pressure and exact original-worker-error precedence on all six
configurations. The independent writer stays held until the SDK returns after
spending its original ten-second budget. A declared invalid fixture credential
on one real HTTP poll produces the original 401 error; shutdown failure remains
separately visible. After release, a control client reconciles the original
token because a timed-out request may still commit. Immutable receipt replay
and original-peer completion check each repair once. Official archives and
raw comparisons independently verify 648 observations. Main verification is
recorded in #374. Complete authority/error families, explicit native receipt
maintenance and exact published-artifact qualification remain open under #358.

[#375](https://github.com/durable-workflow/server/pull/375) qualifies insertion
before an original pending or completed activity. Separate actual worker
processes choose the legacy branch twice without shifting sequence 1, adding a
marker or repeating the activity. All six exact-head configurations pass;
independent official artifact comparisons verify 684 observations, including
36 new published PHP SDK 2.2.6 / embedded Workflow 2.5.5 deployment controls.
The frozen 29 and old source/fault tuples remain unchanged; main verification
is recorded in #375. Python/Rust SDK directions and corrected published
artifacts remain separate qualification gates.
SDK production repairs belong to
[Python #103](https://github.com/durable-workflow/sdk-python/issues/103) and
[Rust #80](https://github.com/durable-workflow/sdk-rust/issues/80). Existing
Python duplicate-marker histories require a separate explicit compatibility
case; they must not silently be treated as malformed disposable histories.

[#376](https://github.com/durable-workflow/server/pull/376) implements native
`record_version_marker` with its canonical payload and original task annotation,
and includes marker positions in subsequent authored sequence calculation.
All six exact-head configurations pass; independently checked official archives
qualify 720 observations, 58 native tests per configuration, 23 SQLite codec
tests and 1,283 comparator tests. Single-call cold controls preserve marker
sequence 1 and original pending/completed activity sequence 2. Main verification
is recorded in #376. Separate fresh repeated-call fixtures stay
pending after actual embedded Workflow 2.5.5 recorded duplicate markers in the
completed-activity case; the pending case was not executed after that failure.
[Workflow #744](https://github.com/durable-workflow/workflow/issues/744) owns
the engine repair. Deprecation, arbitrary version ranges, repeated fresh calls
and cross-SDK duplicate-marker histories remain separate shared gates.

[#377](https://github.com/durable-workflow/server/pull/377) ports the workflow-task
`/fail` waiting acknowledgement used by
published SDK pending replay. Two pending controls distinguish the structured
`WorkflowTaskWaitingForHistory` type from PHP's retained message fallback.
An actual timer resumes a leased replay while the original activity is still
pending. Acknowledgement must release that lease without adding failure history,
leave the original run waiting, and allow the same activity and run to finish
with their exact typed value and immutable history prefix. HTTP lease fencing
and replay refusal are part of this slice. Embedded mode executes its authored
echo and explicitly marks this service-only endpoint inapplicable. General
task-failure retry/blocking policies, pending signal/update draining and the
actual Rust SDK worker direction require separate qualification. All six configurations
and main PHP verification pass. Independently checked official archives qualify
756 observations, 61 native tests per configuration, 23 SQLite codec tests and
1,331 comparator tests. The new controls comprise 24 actual HTTP exchanges and
12 explicit embedded inapplicability records; main PHP reports 2,625 tests and
58,522 assertions.

[#378](https://github.com/durable-workflow/server/pull/378) qualifies the separate
published Rust SDK 3.4.2 / Python SDK 2.5.1 cold-replacement profile after an
original PHP-authored activity checkpoint. Starts precede an advertised worker
fingerprint, and the original worker declares one-second affinity. Both legacy
patch calls choose false, preserve activity sequence 1 and finish the original
run with unchanged history and exact typed int64 output. All six candidate and
main configurations pass. Independent official archive comparisons verify 828
observations: the four new fixtures add 48 actual HTTP executions and 24 embedded
authored counterparts across the matrix. Each cell has 61 native tests; SQLite
has 23 codec and 1,517 modeled comparator tests. PHP reports 2,625 tests and
58,522 assertions. Native discovery now includes the exact PHP carrier auth
manifest; the byte observer forwards background query cancellation and requires
successful same-request pressure recovery. The locked SDK adapter builds once
per workflow and shares thin artifacts without target trees or dependency caches.
Worker-fingerprinted migration, immediate sticky failover, complete fault budgets
and performance remain separate gates.

The `repeated-patch-clock` profile in merged
[#379](https://github.com/durable-workflow/server/pull/379) qualifies two fresh repeated-call
checkpoints against the published PHP HTTP reference and installed embedded
Workflow 2.5.7. The old Server image still packages Workflow 2.5.5; its component
version is explicit in this new tuple. The embedded cold replacement must observe
the first marker's recorded clock after both calls. Normal Composer installation,
actual installed references and hashes of four autoloaded replay classes bind
both embedded processes to the selected published source. The profile also
freezes the complete embedded dependency lock to prevent registry drift.
The [exact candidate matrix](https://github.com/durable-workflow/server/actions/runs/38086396368)
passes all six configurations. Independent official archive, source and raw
recording checks verify 864 observations, including 24 new actual HTTP
executions and 12 embedded clock observations. These represent 48 selected
fixture cases across three targets and six configurations, rather than 864
distinct semantic families. Both repeated calls keep one original marker at
authored sequence 1, activity sequence 2 and the original run/history prefix;
every captured embedded replay clock retains the marker's full microseconds.
Each configuration passes 61 native tests. SQLite adds 23 codec and 1,542+77
modeled comparator checks; PHP reports 2,625 tests and 58,522 assertions.
The merged tree matches that candidate; main verification is tracked in #379.

Historical failures remain explicit in #379: an earlier MySQL database loss
has an unconfirmed cause, and a later comparator incorrectly rejected PHP's
published optional pressure diagnostics. The corrected guard validates those
fields while retaining a null task, exact status, positive bounded Retry-After
and successful recovery with the original request bytes. Raw source/transport
evidence remains unchanged. All five final database states and their original
512/768 MiB limits were checked independently. CI
reuses its existing native binary and HTTP targets, installs only the separately
selected embedded engine and gives it a new isolated database. The frozen baseline and other profiles
stay unchanged. Retained duplicate-marker cross-SDK histories need a separate
real published-worker fixture; source inspection alone does not prove that
execution. Workflow #744 owns the published engine repair and its downstream
follow-through. A Server package pin retains #358's fault/budget and unpublished
PHP fencing/schema release gates; it does not qualify a new Server release.

The separate `legacy-marker-aliases` profile executes genuine published authors:
Python 2.5.0 for one- and two-marker histories, and Rust 3.4.3 for fresh marker
deduplication. A separate cold Rust 3.4.3 worker resumes each original run after
an activity checkpoint. Its four fixtures cover a pending activity, a completed
activity, a single-marker control and fresh deduplication. Both retained same-ID
markers keep their original positions and timestamps; the original activity and
exact typed int64 output complete once.

Embedded counterparts normally install Workflow 2.5.5 for the retained-history
author and Workflow 2.5.7 for the cold reader, each with its complete dependency
lock and actual autoloaded source hashes. The two-marker controls observe both
distinct original microsecond clocks after the corresponding replay calls;
single-marker and fresh controls reuse the first clock. Each database job builds
neither SDK: a preparation job builds the locked published adapters once using
its existing registry/target cache, then shares only binaries, dependencies and
installation receipts. All four local fixtures and their raw observations pass
on PHP, native and embedded targets. Qualification across the six hosted
database configurations is tracked in [#382](https://github.com/durable-workflow/server/pull/382).
The profile explicitly checks published poll-capacity refusal: a null task,
one-second retry hint and successful recovery with identical worker, queue and
poll options. The published worker may choose a fresh poll identity after this
explicit no-task admission refusal. An idle query worker may instead withdraw
after its own accepted commit; workflow/activity polls require actual recovery.
Backend-lock pressure still requires the same original poll identity.
Immediate sticky failover, blocked
repair, migration, full fault budgets and performance remain separate gates.
Its opt-in HTTP observer records whether the complete request body arrived and
whether an upstream request started. A locally cancelled, incomplete poll has
no inferred worker identity; every active registration must have committed
before cancellation and receive its own accepted completion before authenticated
withdrawal. A forwarded cancellation requires its actual worker and queue with
the same commit and withdrawal checks. Completion
and failure requests cannot use this shutdown exception. Frozen profiles retain
their original wire-record schema.

The qualified #382 candidate passed all six configurations, but its first
merged-source SQLite check exposed a transport-test race: a filesystem creation
notification can arrive before the complete first JSONL receipt. That original
failed artifact is retained in #382. The test now waits up to two seconds for a
newline-terminated receipt before parsing it; all byte, non-forwarding and
zero-upstream assertions remain required. The observer and runtime are
unchanged. Fresh qualification of this correction is tracked separately.

The #384 candidate passed all six configurations, but merged-source MySQL 8.0
exposed a separate ordering assumption. Its actual original-run history showed
`ActivityScheduled` and `ActivityCompleted` before the respective workers
cancelled idle polls; their completion acknowledgments arrived afterward. The
observer records response completion, rather than database commit order. This
profile retains history event prefixes in its read summaries and requires either
an earlier own accepted completion or an earlier original-run checkpoint with
the exact retained prefix, task identity and lease owner. Each worker must still
receive its accepted completion before authenticated withdrawal. Missing, late,
foreign or changed checkpoints and lost-task responses are rejected. The failed
run stays unqualified; runtime, observer and published inputs are unchanged.

The #386 correction passed a fresh six-configuration candidate run with an
independent 954-observation audit. Its separate merged-source MySQL 8.0 run
exposed a native history gap: `ActivityScheduled` omitted the completing
workflow task's snapshot. The original checkpoint preceded idle cancellation,
but its accepted response arrived afterward. Frozen PHP task-owned events
retain that snapshot, as native markers and completion events already do.
Native scheduling now writes the leased task snapshot in the same transaction
as the activity event. The execution regression checks the original task and
lease owner, later-turn task identity, and immutable history after restart,
duplicate/stale completion and final completion. The checkpoint checker stays
strict. [#388](https://github.com/durable-workflow/server/pull/388) merged the
correction at e0960964. Its candidate and
[main matrix](https://github.com/durable-workflow/server/actions/runs/38107240820)
pass all six configurations, with 65 native tests per cell and independently
audited 954 shared observations, including the real task snapshots. The first
main MariaDB attempt stopped on a Composer download HTTP 504 before its tests;
the bounded failed-job retry passed. That first artifact remains unqualified.

The next range/repair investigation reproduced a terminal application failure
on normally installed embedded Workflow 2.5.7 when cold code excludes recorded
version 1. Restoring the original definition and requesting same-run repair
returns `rejected_not_active`. [Workflow #744](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6104611717)
records that unchanged reference observation. The separate published PHP Server
2.5.14 / PHP SDK 2.2.6 HTTP diagnostic blocks the affected replay and repairs the
same original task after compatible source is restored. The
[reviewed decision](https://github.com/durable-workflow/server/issues/325#issuecomment-6104832243)
treats an out-of-range recorded version or legacy default as a replay
compatibility fault: preserve the original nonterminal run, history, marker and
completed work; restore compatible code and explicitly repair with a new fenced
attempt. Already closed runs stay closed.

[Workflow #749](https://github.com/durable-workflow/workflow/pull/749) implements
that source behavior with a specific range exception derived from the existing
base exception, a blocked task and diagnostic range metadata. Ordinary
application exceptions retain their terminal behavior. A new persisted-marker
corpus fixture exercises executor, fiber and query replay; the real repair case
preserves one activity, the complete original history prefix and exact
int64/null/Unicode values. Three cold embedded processes and unchanged published
PHP SDK HTTP diagnostics independently verify the source change.
[Full source qualification](https://github.com/durable-workflow/workflow/actions/runs/38111925625)
and [post-merge main](https://github.com/durable-workflow/workflow/actions/runs/38112937931)
pass MySQL/PostgreSQL feature suites, representative MariaDB checks, Laravel
9–13 upgrades and the aggregate coverage gate (93.45% against 93.08%).

[Workflow #750](https://github.com/durable-workflow/workflow/pull/750) published
the 2.5.8 patch at 58f387d3. Its
[published package check](https://github.com/durable-workflow/workflow/actions/runs/38114525265)
passes the contract and all 16 Laravel/PHP upgrade cells. A fresh Packagist
distribution install and three cold embedded SQLite processes verify the
original nonterminal run, immutable history prefix, one completed activity and
explicit repair of the same blocked task after byte-identical source restoration.
Independent loaded-source and PHP output audits preserve the published identity
and exact int64/null/Unicode values. The
[published qualification record](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6105840885)
distinguishes that embedded proof from the later Server image and consumer
directions. [Server #389](https://github.com/durable-workflow/server/pull/389)
delivered the normal 2.5.18 image and Helm 0.1.155 at c71576fc, using the
published Workflow source and preserving every other locked package identity.
The [published lifecycle run](https://github.com/durable-workflow/server/actions/runs/38118836424)
passes all 12 declared cells, and the
[published Compose run](https://github.com/durable-workflow/server/actions/runs/38118837959)
passes both architectures in local and production modes. A separate unchanged
published PHP SDK probe verifies range blockage after one completed activity
and explicit repair of the original task at attempt 2, repair count 1, preserving
the history prefix and exact int64/null/Unicode output. The
[delivery receipt](https://github.com/durable-workflow/server/issues/325#issuecomment-6106407301)
records the exact artifacts and the limits of those checks.

The separate `version-range-repair` profile freezes that published cohort for
three cold author phases: original compatible code, incompatible range, and
byte-identical restored code with explicit repair. The HTTP and embedded
adapters observe actual SDK requests, durable history and task diagnostics.
The expanded correctness run gives its MySQL 8.4 database 1 GiB after a retained
768 MiB OOM during the later pressure profile; application and author processes
keep one CPU, 512 MiB and no swap. Database caps are declared in the profile.
Reference execution and native qualification remain tracked separately. The
native follow-up implements the worker-reported range blockage as a failed,
released original task with no automatic retry or terminal failure history.
Empty-body selected-run repair restores that task to ready, clears replay-block
metadata and records the published instance-targeted command with official
Avro values and server-derived audit identity. [#392](https://github.com/durable-workflow/server/pull/392)
owns source qualification and the actual three-runtime recordings; its normal
six-configuration differential gates must pass before merge. Modeled readers
and source inspection are not runtime proof. Repair checks the physical
32-bit PostgreSQL/unsigned MySQL or 64-bit SQLite counter bounds before changing
the task, command or history.
The physical debug read covers only this default-namespace checkpoint, with
pending or completed activities, no ready/leased workflow task, no messages,
no cancellation and no application failures. It exposes actual blockage and
failure identity; broader task-queue, compatibility, payload-preview and
cancellation diagnostics remain unqualified. Manual repair of other shapes,
named namespaces and historical/closed-run outcome parity remain separate gates.
The pending-activity case also needs a legitimate cold wake, because repair of
ordinary waiting work does not create a ready workflow task. Frozen fixture
inputs and earlier observations retain their original versions and digests.
This slice does not establish performance, takeover, another SDK author language
or repair while an activity is still pending.

The pending checkpoint is now a separate qualified PHP HTTP/embedded reference
in [#397](https://github.com/durable-workflow/server/pull/397), merged as
`b70ba209384a0959e2c61598c1f8e5bd08c14d9f`. The timer wins a real mixed select
while its activity has never started; an incompatible cold version author
blocks replay. This exposed [Workflow #755](https://github.com/durable-workflow/workflow/issues/755),
where manual repair selected the older activity. Published Workflow 2.5.10 and
Server 2.5.20 correct selection while retaining the truthful activity wait kind.
All six [candidate database jobs](https://github.com/durable-workflow/server/actions/runs/38135489945)
pass actual PHP HTTP and embedded recordings: the repaired original task waits
without changing history, one original activity executes, and a distinct later
workflow task completes the same run with 11 events. Exact post-merge main
[Shared fixtures](https://github.com/durable-workflow/server/actions/runs/38136690281),
[PHP/source](https://github.com/durable-workflow/server/actions/runs/38136690284)
and [boundary](https://github.com/durable-workflow/server/actions/runs/38136690280)
also pass; Workflow #755 is closed as fulfilled. The failed published tuple,
its evidence and the completed-activity reference remain unchanged.

Native follow-through in #399 implements the same checkpoint with complete mixed
activity/timer group metadata and a first committed `SelectionResolved` that
binds the actual terminal history event. Member resolution and wakeup share the
existing database transition lock; a losing member retains its original work
and cannot overwrite the winner. Explicit range repair admits only the
timer-won, unclaimed pending-activity shape, preserves its row/attempt and
restores the blocked workflow task. Messages, cancellation, running activities,
additional open work and unsupported groups remain refused. Native regressions
cover actual HTTP transitions, two-node timer recovery, original-task repair,
waiting replay, stale fencing and whole-batch rollback. The exact-source
six-configuration qualification includes actual published-author native records.
An activity-first regression also preserves the committed winner across a
restart and losing timer, with one outstanding workflow wakeup. Failed/retrying
selection activities are explicitly refused before mutation until their own
shared fixture qualifies that behavior.
Wakeups coalesce only a ready task. A leased worker can hold an older history
snapshot, so a later member keeps a new ready task even if that worker then
acknowledges waiting; a dedicated HTTP regression covers this interleaving.
Native scheduling explicitly initializes unclaimed activities at zero, matching
both PHP references; only an actual worker claim consumes an attempt. The
physical schema and earlier durable data/history remain unchanged. Winner
regressions query the actual persisted event at its public history sequence to
verify the physical relationship; public history intentionally omits internal
event IDs. Native registration accepts the published PHP activity-only worker's
empty contract map representation `[]` and stores an empty map. Nonempty arrays
and scalars remain refused, with history/pending-work preservation checked.
Failed and cancelled candidates retain separate diagnostic receipts in #399.

Actual SQLite execution passes 71 tests. All 18 pending records preserve
5 → 7 → 8 → 8 → 11 events, three cold author PIDs, activity invocations 0/0/1,
the original timer winner and blocked-task repair, a distinct completion task,
and exact int64/Unicode/null output. All six official artifact sizes and digests
were independently matched against their API and uploader receipts. Candidate
`6d40e2d3` and actual checkout `8bf75a7` have tree
`80938cdf8ce0d38965c9006d4ab2745bd8141415`, retained by the merge.

The separate `pending-native` profile binds the same published artifact bytes
and unchanged fixture under a new qualification scope, including the SHA-256
of the qualified reference manifest. Its three isolated runtime recordings
replace the two-reference invocation in normal six-configuration gates; the
original `pending-reference` invocation and frozen manifest remain available.
The source keeps broader selection/repair, another author language, matched
performance and takeover as separate gates. No local Cargo/target tree is
required for this follow-through; normal hosted jobs compile the exact source.

[#381](https://github.com/durable-workflow/server/pull/381) implements ordinary
workflow-task failure retries on the original run. A failed lease and its new
ready task commit together, carrying attempt count and routing without adding
failure history. The explicit fixture reports two real errors, checks stale,
duplicate and late-outcome refusal, then completes once with exact typed output.
The frozen PHP control established that this run stays `pending` during retry
and a terminal late failure reports `run_closed`. Native SQLite restart and
independent-pool races pass; full six-configuration qualification is tracked in
#381. The frozen default corpus stays unchanged. Embedded executes its echo
control and explicitly marks the service-only retry endpoint inapplicable.
Replay-blocking failures are still refused rather than automatically retried;
blocked replay/manual repair, message-bearing retries, nondefault namespaces,
complete retry/backoff policy and published SDK worker directions remain gates.

The source profile now includes Rust on an independent database using the
already compiled binary. Five dedicated native tests check original-task
completion after restart and replacement, concurrent independent pools,
final-receipt-write rollback on each engine family, expired/pruned receipts,
and two namespaces with live original leases and the same worker ID. Repair
commands are checked in storage as well as history. All six PR and main
configurations pass in #367. Next: shared real contention, SDK retry budgets and original
failure precedence, then native receipt maintenance and exact published
PHP/SDK artifacts. No feature publication or performance result is claimed.

The separate reviewed `role-tokens/admission-role-tokens` profile completes an
original authored echo using the unchanged published SDK and the worker token.
Sixteen real HTTP requests check exact worker/operator/admin membership,
disabled legacy full-access bypass and authentication → role → protocol →
namespace precedence. Operator, admin and legacy-admin reads succeed; refusals
preserve an original unclaimed peer's complete description and history; an
operator then cancels that same peer. Embedded execution checks the authored
lifecycle with HTTP authorization explicitly inapplicable. The default corpus
remains 29 legacy-token fixtures; the role profile uses independent databases.

Tests-first `8c3564e5` reproduces both native failures on all six configurations
in [run 38045677626](https://github.com/durable-workflow/server/actions/runs/38045677626):
role credentials return 401 instead of role 403, and the legacy credential still
claims a worker task with 200 instead of 403. Each configuration retains 42
existing passing HTTP tests. The guard now matches configured roles before the
legacy credential, disables legacy bypass when any role is configured and
checks endpoint membership before protocol and namespace lookup. Immutable
configuration survives namespace selection. The already locked
[subtle 2.6.1 primitive](https://docs.rs/subtle/2.6.1/subtle/trait.ConstantTimeEq.html)
compares secret contents without a new transitive dependency.

The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38046430334)
on head `c2901404881ebbf7fd88e67d241cbf1ff7c7527b` and runner merge
`8ac16e25e4ea0e48c00ac3bf12f886025d5f03f7` has identical tree
`d48bf2aca0d695e396a51de97a4872425a851152`. All six configurations pass
the default corpus and the separate role profile. All downloaded artifact
digests match; 522 default and 18 role observations independently compare,
including 192 role-profile HTTP requests. Each configuration passes 45 native
HTTP tests; SQLite passes 21 library tests. All 840 modeled comparator checks
and two original/19 deliberately corrupted reference rechecks pass; these are
not additional workflow executions. PHP/source passes 2,621 tests and 58,473
assertions, with six existing deprecations and one skip. Final qualification of
the permanent profile path is recorded in [#362](https://github.com/durable-workflow/server/pull/362).
The cohort reuses the existing CI binary; owned cleanup always runs.

Unqualified principal, custom-provider, signature/no-auth, runtime-credential,
alias-role and disabled-backward-compatibility settings refuse startup before
opening storage. The development runtime still requires `DW_AUTH_TOKEN`.
Auth environment values must parse as UTF-8. Invalid text cannot become an
absent role credential and restore legacy bypass; empty driver and
backward-compatibility settings refuse explicitly. Actual native process
checks exercise raw environment bytes before storage selection.
Principal/tenant/runtime credentials, role-only configuration, signatures,
namespace administration and actor attribution require separate qualification.
Full authorization, performance, takeover and cutover remain open.

The twenty-ninth reviewed fixture, `namespace-isolation`, creates two named
namespaces and completes two original authored activity workflows. HTTP uses the
unchanged frozen PHP SDK, one queue and the same worker ID in both namespaces.
Five foreign control requests and three foreign task completion attempts preserve
the original descriptions and histories. Workflow IDs remain globally reserved;
worker IDs, poll receipts and leases are scoped by namespace. Completing and
deregistering the first worker leaves the second namespace's original run and
registration intact. Embedded mode supplies host namespace binding and executes
real database-queue jobs; HTTP administration and registration are inapplicable.

Frozen PHP/embedded reference source `55de4c86` passes and independently compares
both original observations. Corrected tests-first `cb3cf3e5` reproduces both
namespace failures on all six databases while retaining 39 existing passing HTTP
tests. Failed recorder/helper revisions and original recordings are retained in
[#361](https://github.com/durable-workflow/server/pull/361).

Actual PHP keeps the first leased workflow `pending`, then retains `waiting`
before activity/replay commits. Native polling now preserves that authored
lifecycle. Foreign-target refusals retain original workflow/run/task/attempt
identities, canonical diagnostics, complete control contracts and worker
protocol/capabilities. The unchanged SDK also polls the query queue during an
activity workflow. That idle poll now scopes registration and cached-task lookup
to its namespace; a native regression preserves a default-namespace same-ID query
lease through a foreign poll and its original completion. Named query authoring
and completion require separate qualification.

The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38043263104)
for head `9b5e77664b42d0526aa80065cd3f32af25954c8b` tests merge revision
`91b49964a84d34d74d0d60621b554fe972f0976e` with current PHP main. All 29
PHP/Rust/embedded cases pass on SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB
10.11. All six downloaded artifact digests match and 522 actual observations
independently compare, including 18 namespace observations. All 42 native HTTP
tests pass per configuration, SQLite passes 21 library tests, the existing 810
modeled comparator checks pass and 54 original/corrupted-reference rechecks pass.
The latter rechecks are not additional workflow executions. PHP/source passes
2,621 tests. Promotion qualification of the reviewed default corpus is recorded
in #361; PHP remains the default published runtime.

Native namespace metadata, request binding and the qualified worker transitions
use bound SQL values and the existing pools, scheduler and serialized transition
lock. Named operations and commands outside this echo/activity/read/cancel and
idle-poll slice explicitly refuse before reaching default-scope queries. Wider
timers/signals/queries/updates/children/schedules/visibility, lifecycle/retention,
quotas and role profiles remain unqualified. Role tokens need a separate profile:
enabling them disables the legacy token's full-access bypass, and published
worker/operator/admin permissions are distinct.

The twenty-eighth reviewed fixture qualifies legacy-token authentication and
default-namespace admission. It completes an original echo, admits one unclaimed
peer and executes 16 actual control/worker requests: credential failures before
protocol/namespace errors, protocol failures before namespace lookup, unknown
header/query namespaces and normalized header/query/configured-default precedence.
Refused requests preserve complete original descriptions and history; authorized
cleanup cancels that same peer. Embedded mode checks the authored workflow and
installed peer lifecycle, with HTTP authentication explicitly inapplicable.

Tests-first source `6d12a021` reproduces two admission failures on all six
configurations while retaining 37 existing passing native HTTP tests: the
noncanonical authentication response and an unknown query namespace cancelling
the default peer. Native corrections resolve namespace selection before dispatch
and supply versioned, plane-specific refusal metadata and original diagnostics.
The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38038527429)
on head `5a2016a7139723d34dc5edf2306bfd7f7d2b82af` passes all 28 PHP/Rust/embedded
cases on SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB 10.11. Its actual
runner revision `d6e8ae25f30cee2442d297e7f72ab468faf80dcc` has the identical tree.
All six downloaded artifact digests match; 504 actual observations independently
compare, including 18 admission observations and 192 HTTP admission requests.
All 39 native HTTP tests pass per configuration, SQLite passes 21 library tests,
810 modeled checks pass (114 admission) and PHP/source passes 2,607 tests.
The reviewed default corpus became 28; promotion qualification and main
verification are recorded in [#359](https://github.com/durable-workflow/server/pull/359).

Role/principal tokens, tenant restrictions, wider named-namespace execution, alternate
auth/default configuration and additional control-operation error contracts
remain open. Malformed/non-scalar selectors fail closed in this development
slice; their broader PHP contract remains pending.

The twenty-seventh reviewed fixture covers default-namespace visibility with one completed authored
echo and one unclaimed pending peer of a different registered type. It records
original run identities, size-one pagination, status/type filters, cancellation
cleanup and persisted embedded summaries. HTTP recordings also execute the
unchanged, checksum-verified published CLI 2.2.0 list/describe/history commands.
Frozen PHP and embedded recordings pass and compare at `8345e18e`; the actual
published CLI commands succeed against PHP and refuse the pending status alias.
All 696 comparator checks pass, including 88 modeled visibility/CLI and selection
checks. Corrected tests-first source `fbc519f1` compiles on all six databases and
reproduces missing listing (405) and canonical request-contract metadata, with
all 35 existing native HTTP tests passing. A later actual CLI run independently
rejects missing describe/history response metadata despite all 37 native HTTP
tests passing. The correction supplies operation-specific response contracts,
original identities and cursors without duplicating payloads in metadata.

The native implementation reads at most 201 authoritative run rows,
uses bound status/type/substring filters and PHP offset cursors, and adds canonical
describe buckets/current-run/count fields. SQLite ordering handles both stored
UTC timestamp forms with exact decimal precision; a native storage regression
checks nanosecond boundaries and ID ties. The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38035180354)
at `0f89276a9aae5f0055e90b24a3fb50339c6462a6` passes all 27 PHP/Rust/embedded
cases on SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB 10.11. All six
downloaded artifact digests match; their 486 actual observations independently
compare, including 18 visibility observations and unchanged CLI 2.2.0 execution
on both HTTP targets. All 37 native HTTP tests pass per configuration, and the
SQLite library suite passes 21 tests. PHP/source checks pass 2,607 tests. Fixture
promotion and final default-corpus qualification are recorded separately in
[#357](https://github.com/durable-workflow/server/pull/357).
Persisted PHP summary projections for Waterline, pinned-build fleet visibility,
structured query grammar and custom search attributes are not part of this slice.
Broader visibility grammar, metadata, namespaces, streams and Waterline are
separate acceptance gates.

The next slices widen reviewed PHP/Rust/embedded fixtures and native behavior:
Timer, signal, quiescent query and state-update slices are qualified. State-update slice
[#342](https://github.com/durable-workflow/server/pull/342) applies two distinct
values while a workflow waits for a finishing
signal, repeats each request ID with different arguments, and checks original
identities/results, state replay and the shared message cursor. All nine cases
pass and compare on the frozen published PHP and embedded references at
`ab105eba4aebc5295d1fe08c3bf70a87e1f7e639`; 83 comparator checks pass.
Native admission, original request receipts, routed update tasks and completion
fences are qualified on all six targets in the
[final shared matrix](https://github.com/durable-workflow/server/actions/runs/37987814790)
at `a6f96db537acdbdfb505373e46e2eed8fbe2d3d2`; #342 records exact source identities
and passing PHP/source outcomes. All nine shared fixtures pass per runtime.
The native HTTP cases exercise independent pools, expired-lease recovery,
registration fences, completion waits and the 64-update inventory boundary.
MariaDB qualification exposed decimal promotion of a nullable integer through
`COALESCE`; update positions now read the physical nullable integer directly.
Admission requires a quiescent wait and bounds each run
to 64 updates, with 64 KiB argument blobs, 256 KiB results and at most 30 seconds
per completion wait. Waiting releases the transaction and database connection.
(Since October 23 native enforces PHP's structural limits instead of the
64-update and 64 KiB bounds, which PHP does not have; see the
[structural limits](rust-server-port-checkpoints/2026-10-23-structural-limits.md)
and [update lifetime cap](rust-server-port-checkpoints/2026-10-23-update-lifetime-cap.md)
checkpoints.)
Queued/busy control routing, validators, failure tasks and full update semantics
remain open.
The qualified tenth child-workflow fixture calls two children in sequence, each executing
an activity with a distinct typed payload, then returns their committed results
in order. Preserve parent/call/child-run and activity-attempt relationships,
registered type keys, original arguments, exact int64 and complete histories.
HTTP-created children omit `StartAccepted`; embedded children record it. Both
inventories must be explicit and checked before projecting their common behavior.
The shared fixture/adapters/comparator are implemented in
[#343](https://github.com/durable-workflow/server/pull/343). Its reference and
exact-head qualification outcomes are recorded there. Native successful,
sequential child creation/completion/resumption passes all ten shared cases,
20 native HTTP tests, 106 comparator tests and the actual process-kill child
recovery probe on SQLite, PostgreSQL 16/17, MySQL 8.0/8.4 and MariaDB 10.11 at
`b51e6aba331bfdcfa2da13fe503dfe5609967c76` in
[run 37993085836](https://github.com/durable-workflow/server/actions/runs/37993085836).
PHP/source gates pass (2,601 tests / 57,160 assertions). The merge
`a4bf3bbf1a4e5d29df35eb29d18d0c30de80ad0e` retains the identical tested tree;
all main-branch shared and PHP/source checks also pass.
Child runs retain PHP's nullable default timeout budgets, original parent
call/link IDs and immutable terminal results. Creation and parent resumption
commit atomically with the leased workflow task; duplicate completions retain
their original receipts. Native tests exercise independent pools, real child
lease expiry, fresh-pool recovery and rollback on inconsistent linkage.
Retry/cancellation policies, explicit timeouts, parallel groups and parent-close
actions remain refused or unqualified beyond this success slice.
The eleventh reviewed fixture reports an application failure on activity attempt one,
waits its durable one-second retry backoff, and completes the original activity
and workflow on attempt two with exact int64 payloads. It checks distinct tasks
and attempts, original execution/idempotency identity, immutable policy/argument
frames, actual SDK claims/completions and no intermediate workflow resumption.
HTTP duplicates must leave history unchanged; embedded redelivery uses the real
queue jobs for both closed tasks. Embedded Throwable class and external worker
failure type are explicit representations of the same original failure.
All eleven cases pass and compare against the published PHP and embedded
references at `8d0da93aa3db059b6bcf2230898ceb2231a1dae6`; 128 comparator tests pass.
The tests-first native regression fails with `422 unsupported_request_field`
before retry support in [run 37995605455](https://github.com/durable-workflow/server/actions/runs/37995605455).
The native transition is implemented in [#344](https://github.com/durable-workflow/server/pull/344):
failure encoding uses the official codec on a bounded blocking worker, then one
transaction closes the original attempt/task, preserves its failure and creates
one ready retry at the original exact deadline. Polling keeps the original
activity/idempotency identity and increments the new task's attempt counter.
No parent task is created until a successful result commits. The current PHP
tables and native schema version remain unchanged. All eleven shared fixtures,
21 native HTTP regressions and actual pending-retry process-kill recovery pass
on all six configurations in [run 37996570260](https://github.com/durable-workflow/server/actions/runs/37996570260)
at `2dfb99d5ee3097aa5f6b99b43f7d192678e550b1`. Recovery retains the original
failure prefix, task/arguments/deadline, executes attempt two through the
published SDK, commits one result and refuses the old failed attempt's late
completion. PHP/source gates pass (2,601 tests / 57,156 assertions). The merge
`9fd7ba8ca1acf3b773fb8ba9e97d7cba7b0f84b9` retains the tested tree; its main
shared, PHP/source and boundary checks also pass.

The next three reviewed fixtures cover exhausted activity retry budget,
a matching non-retryable error filter and successful retry with a nonmatching
filter. The unchanged workflow worker must replay and catch the original durable
failure, then complete with its exact typed input and failure type/message.
Embedded execution records an additional `FailureHandled` event. The published
HTTP protocol has no catch acknowledgement command and omits that event. Both
complete inventories and the original embedded failure/handling relationship
are checked explicitly before comparing the declared common catch behavior;
this does not qualify full failure diagnostics or visibility.
All fourteen reviewed fixtures pass against the frozen PHP/embedded artifacts
at `d116947a70d30cdae66f9c8bd7e5ea6d18126d6a`, and 154 comparator tests pass.
The tests-first native regression at `5e819617e38e0a528700663905c737a2c615f09f`
compiles and fails on the missing terminal transition; its other 21 HTTP tests
pass. Native terminal failure and exact error-type filters are implemented.
All fourteen shared fixtures, 22 native HTTP tests and the actual terminal-failure
kill/recovery checkpoint pass on all six configurations in
[run 37999564905](https://github.com/durable-workflow/server/actions/runs/37999564905)
at `67e14149f804276e47906fb73ab9b07a92543a4a`. Independent digest/source
audits verify all 252 reviewed fixture snapshots and recompare the six retained
three-runtime recordings. Worker history retains event IDs/attribution and
`recorded_at`; the control API projects sequence/type/`timestamp`/payload.
Recovery checks their declared common fields and original worker event IDs and
terminal task attribution without changing either API representation.
Error filters follow PHP's default ASCII/NUL trim character set, preserve
Unicode whitespace as part of the type identity and deduplicate in first-seen
order. A separate reviewed normalization corpus is verified against the actual
frozen Workflow normalizer. Native parser and HTTP regressions cover this edge,
including a Unicode-padded nonmatching filter that must still retry. This corpus
checks normalization; it is additional to the fourteen durable execution cases.
The native regression also covers explicit non-retryable reports and default
no-retry behavior, independent pools, original failure rows, concurrent duplicate
reports/resumption, fresh-pool recovery and stale/conflicting outcomes.
The actual process-kill probe also preserves an acknowledged terminal failure
with retry budget remaining and its original pending workflow-resume task.
After replacement, the published SDK client and replayer catch the original
failure, complete once and reject late success from the closed activity.
This checkpoint uses explicit client claims/replay; the shared terminal fixtures
exercise the normal SDK worker loop.
The final terminal-failure head `ec0e597db9efd13f5f5c2e24adc76aa1a2de5f4d`
passes all six configurations in [run 38001111544](https://github.com/durable-workflow/server/actions/runs/38001111544)
and PHP/source gates (2,601 tests / 57,160 assertions). Merge
`2288e80b8d7610394e38b814afaa62414333d200` retains the identical tested tree;
its main shared and PHP/source checks also pass.

The immediate selected-run cancellation slice defines three
cases: before workflow claim, with a pending 60-second timer, and with a leased
activity. Reviewed expectations preserve the original command/run/failure and
timer/attempt identities, exact int64 input, typed cancellation, immutable
history, repeat refusal and stale-result fencing. The normal SDK activity worker
must submit its result after cancellation and safely discard the stale refusal;
embedded mode executes and redelivers real queue jobs. All seventeen cases pass
and compare on frozen PHP/embedded at `99102930002253f9b32dec87236c5e5c98f86a76`;
185 comparator checks pass. Tests-first native run
[38002864344](https://github.com/durable-workflow/server/actions/runs/38002864344)
compiles, passes its other 22 HTTP tests and fails the new cancellation test on
the absent endpoint (`501 rust_capability_not_implemented`). Native implementation
at `7e83dc7e622f6962099d89b74972edba2d3a0bcf` passes all six configurations in
[run 38003976411](https://github.com/durable-workflow/server/actions/runs/38003976411),
including all 17 shared cases, 23 native HTTP tests and actual process-kill
recovery of the original cancelled activity and timer. The retained records
preserve all 306 fixture snapshots and officially decoded int64 recovery inputs.
Final review then found that HTTP reasons need Laravel's Unicode/invisible
boundary trim before nullable/1000-character validation. Embedded cancellation
preserves its reason verbatim. The reviewed padded-reason fixture explicitly
checks both representations, and a separate corpus pins the complete frozen
HTTP trim set. The correction and its two native normalization checks pass
the final six-configuration qualification described below. Cooperative cleanup
cancellation, child/update cancellation and scoped propagation are separate gates.
The first padded-reason matrix also exposed a published embedded PostgreSQL
defect: a literal NUL truncates the physical failure message while terminal
history retains it. PHP HTTP and Rust passed all seventeen cases, and SQLite,
MySQL and MariaDB embedded comparisons passed; both PostgreSQL embedded
comparisons reported `product-fail`. [Workflow #741](https://github.com/durable-workflow/workflow/issues/741)
owns the portable representation/refusal decision and fix. A small reproducer
is retained in `tests/Fixtures/ServerParityPending/cancel-nul-reason.json`, outside
the passing corpus. The portable padded-reason case excludes NUL while retaining
Unicode/invisible whitespace; the full failure/history consistency check remains
required. NUL is still covered by the separate HTTP normalization corpus. This
does not qualify literal-NUL cancellation reasons across database families.
The correction and published-artifact follow-through remain separately tracked
in #741; the pending fixture is retained for qualification of that fix.
The [reviewed correction](https://github.com/durable-workflow/workflow/issues/741#issuecomment-6091333824)
renders the complete cancellation/termination diagnostic as a JSON string literal
when it contains NUL, identically in failure storage and terminal history.
Decoding recovers the original complete diagnostic; ordinary diagnostics remain
unchanged. Original reasons remain byte-exact in Avro command/history payloads,
with original failure type, source, run and identity. The PHP correction is now
published in Workflow 2.5.5 and Server 2.5.14. Its corrected-tuple Rust
differential is qualified below; the frozen failing reference is retained and
does not become a pass from publication alone.

The separate corrected diagnostic corpus now selects frozen Server 2.5.14 /
Workflow 2.5.5 from `tests/Fixtures/ServerParityBaselines/php-2.5.14.json`.
The original 2.5.13 manifest and pending leading-NUL reproducer remain unchanged.
Two reviewed definitions preserve the leading-NUL embedded case and add an
interior-NUL reason that reaches HTTP/native storage unchanged. Only a complete
diagnostic containing NUL is rendered as one JSON string literal; decoding must
recover the full prefix and reason, while ordinary messages remain unchanged.
The 22-case corpus records published PHP and embedded references before
native execution.
All 289 comparator tests pass, including four corrected diagnostic models and
twelve synchronized semantic corruptions; these are harness checks, not actual
completed workflows. Native qualification is recorded in
[PR #352](https://github.com/durable-workflow/server/pull/352).
The [actual tests-first run](https://github.com/durable-workflow/server/actions/runs/38018390560)
at `19fa7f0a` records 264 passing PHP/embedded observations across six database
configurations, including 24 NUL observations. All existing 29 native HTTP tests
pass per configuration, but the interior-NUL fixture rejects the native
unencoded diagnostic on SQLite/MySQL/MariaDB. PostgreSQL 17/18 instead reject
the history payload because SQLx advertises `jsonb` for the PHP `json` column;
cancellation returns 503, rolls back, and the subsequent worker completes.
The native implementation serializes the whole NUL-containing diagnostic and
binds PostgreSQL documents as `json`, reusing SQLx's encoder and preserving the
existing schema. A new HTTP regression checks original reasons, physical
failure/history equality, duplicate refusal, fresh pools and exact int64 input.
The repaired head `d121dc804977a09d5b9484e602e9fa53b573b384` passes
[all six configurations](https://github.com/durable-workflow/server/actions/runs/38019202998).
Independent review verifies all six downloaded ZIP digests and compares 396
actual PHP/Rust/embedded observations (22 fixtures × three runtimes × six
configurations), including 36 NUL observations. All 30 native HTTP tests pass
per configuration. The existing 54 root cleanup observations and twelve
process-kill cleanup recovery variants also pass; PostgreSQL/MySQL TLS and
read-only PHP takeover refusal gates remain intact. Qualified totals are now
22 representative fixtures; broader cancellation and upgrade remain unqualified.
The final cancellation head `741401bc80e2b13b86ae031f3fe88272855f1d03`
passes all six configurations in [run 38005623457](https://github.com/durable-workflow/server/actions/runs/38005623457),
including all 17 shared cases, 23 native HTTP tests/configuration and actual
cancelled-activity/timer process-kill recovery. Independent artifact/source
audits verify 306 reviewed fixture snapshots, twelve officially decoded cancelled
recovery inputs and six fresh comparisons. The comparator passes 187 checks;
native library tests include both normalization regressions. PHP/source
[run 38005623473](https://github.com/durable-workflow/server/actions/runs/38005623473)
passes 2,601 tests / 57,156 assertions. [PR #346](https://github.com/durable-workflow/server/pull/346)
is merged at `eb863bd3c1548e7fb446cfca7e75b07bbdc250d8` with the exact tested
tree. Its main [shared fixture](https://github.com/durable-workflow/server/actions/runs/38006383023)
and [PHP/source](https://github.com/durable-workflow/server/actions/runs/38006383136)
checks also pass.
The frozen HTTP selected-run route validates the current run, then records an
instance-scoped command; embedded `loadRun` records a run-scoped command.
Fixtures declare and verify both receipt representations before comparing their
common original-run cancellation. Frozen cancellation attempt history omits
lease expiry; embedded physical rows and actual HTTP stale-result refusal prove
lease closure separately. Neither difference changes expected cancellation,
original identities or fencing.
For a correctly identified attempt on an immediately cancelled run, frozen PHP
refuses completion with HTTP 409, `run_cancelled`, `outcome: ignored`, the
original owner/attempt/task and closed statuses. This is distinct from a wrong
attempt's `stale_attempt` refusal. The normal published SDK worker discards this
closed-run result safely; the fixture checks both its actual report and an
explicit repeated report without changing either response.

Root cooperative cancellation in
[#348](https://github.com/durable-workflow/server/pull/348) adds three qualified
definitions: before claim, after an original timer is scheduled, and expiry of
a longer shielded cleanup timer. The unchanged PHP SDK 2.2.6 executes all 20
shared cases on native Rust, frozen PHP Server 2.5.13 and embedded Workflow
2.5.4 across SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB 10.11.
The [shared and recovery matrix](https://github.com/durable-workflow/server/actions/runs/38015362532)
at `5af21079838b5e3e818f43e7d2e8fd0b580ec20e`, including main `605527eb`,
passes all six configurations. All six ZIP digests and 360 actual snapshots
are independently reviewed, including 54 root observations. Each configuration
passes 29 native HTTP tests. The [PHP source checks](https://github.com/durable-workflow/server/actions/runs/38015362517)
and public boundary checks also pass. The reference comparator rejects 80
semantic corruptions; all 273 comparator tests pass, including 86 root cases
and the existing 187 checks. These totals qualify representative fixtures,
not complete cancellation, migration or performance parity.

Cooperative discovery is enabled in the experimental runtime to run the
unchanged SDK handshake. Current admission supports root requests with timer
boundaries; child relationships, existing open activities, waits, updates,
scoped policies and other delivery call kinds are refused explicitly. This
bounded slice does not establish broader cooperative-cancellation parity.

The first unchanged SDK candidate exposed the protocol 1.20 activity ownership
observation hook: the worker calls `/status` before invoking cleanup activity
code. Native delivery and the shielded timer completed, but the missing hook
returned HTTP 501 and the SDK abandoned each activity claim. The native hook
now observes the actual task, attempt, owner, run and existing lease without
renewing it or recording progress. The unchanged SDK and shared matrix now
qualify that hook for the supported cleanup slice.

`cooperative-restart.php` adds real pending-cleanup recovery variants to the
existing externally killed native deployments. Preparation records the original
canonical context, delivered event identity and acknowledged history before
the process kill. A fresh SDK worker then checks completed and expired cleanup,
unchanged request/deadline, original typed activity values, terminal reads and
late heartbeat/delivery/completion fencing. All twelve actual recovery variants
pass across the six configurations; job logs retain the external Docker KILL
and exit-137 check. Independently reviewed receipts preserve the original
history prefix, root context, delivery event identity and exact int64 values.
These longer-timer variants do not add cases to the shared corpus.

The actual first request returns HTTP 202; pending and terminal duplicates
return HTTP 200. The reviewed fixtures now check each recorded transport status,
request body and response. Corruption checks keep history copies consistent so
they must reject the changed semantics themselves. The initial native gap run
compiled on all six configurations and left the 23 existing HTTP tests passing;
the new test failed because the endpoint is absent. Its initial HTTP 200
expectation was corrected to the recorded HTTP 202 before implementation.

Deadline qualification runs the installed ordinary Server repair pass against
actual wall-clock deadlines. It does not construct terminal history, alter time
or patch the SDK. Embedded mode must be selected before Laravel bootstrap;
each original timer is observed in the selected run before cancellation.
Terminal diagnostics use the generated completion/expiry reason, while the
original caller reason remains in canonical context and cleanup arguments.
The separate PHP service-mode queue override inconsistency found during setup
was corrected separately in [#351](https://github.com/durable-workflow/server/pull/351)
and published as Server 2.5.15. The frozen reference tuple remains unchanged.

The direct child-cancellation cases cover cancellation before a child
claim and during its committed timer. Actual published PHP 2.5.14 / SDK 2.2.6
and embedded Workflow 2.5.5 execution on separate SQLite databases completed
both parents by catching their original cancelled children. The SDK exposes
`ChildWorkflowFailed` with failure type `ChildRunCancelled` and the original
child failure message/payload. Embedded replay restores
`WorkflowCancelledException`, names the child run in its parent-facing message
and records `FailureHandled`. Shared assertions check those representations
before projecting common behavior. Deterministic unit examples model the observed
published representations and test
rejection of corrupted identities, history, diagnostics and duplicate effects.
The direct-child slice brings the corpus to 24 representative cases. This adds direct child
cancellation only; propagation trees, parent-close policies, child retries and
hard-kill child-cancellation recovery remain unqualified.

Tests-first head `3feb7871` / test merge `55078239` independently confirms all
288 PHP/embedded observations on the six configurations. Every native recording
stops at direct child cancellation with `422 cancellation_propagation_not_available`;
the preceding 30 native HTTP tests/configuration pass. Failure snapshots also
expose an unclaimed native child described as `running`: only the parent was
polled, whereas both published references describe the child as `pending`.
The repair initializes children as pending, resolves direct cancellation through
the existing checked child-call/link path, records `ChildRunCancelled` with the
original failure and enqueues one original-parent resumption in the same
transaction. Closed cancellation receipts retain the published
`rejected_not_active` outcome. Two added native tests cover fresh pools, physical
failure persistence, immutable duplicate/stale outcomes and relationship-corruption
rollback.

Repair head `4490f61f` / test merge `65e1fde9` passes
[all six configurations](https://github.com/durable-workflow/server/actions/runs/38022923876).
Six downloaded ZIPs match GitHub artifact digests; all 432 PHP/Rust/embedded
observations independently compare, including 36 direct-child observations.
All 32 native HTTP tests/configuration and the twelve existing root-cleanup
kill/restart variants pass, together with TLS and PHP takeover refusal.
[Source gates](https://github.com/durable-workflow/server/actions/runs/38022923848)
pass with 2607 PHP tests / 57,222 assertions, the known six deprecations and one
skip. The final fixture metadata removes its pending-phase claim; that changed
fixture identity requires qualification again on the final source before merge.

The adapter now issues the real SDK cancellation after a committed task-completion
response, before the single SDK worker can poll again. It changes no request or
response and synthesizes no history. Two fresh published PHP executions pass:
before-claim cancellation observes pending state and zero child claims; timer
cancellation observes waiting state and one child claim. Shared checks additionally
require one original parent resumption and its original child/call/failure
relationships. 447 comparator checks pass, including 158 direct-child checks.
Raw per-run observations remain in bounded Actions artifacts; unit examples use
fixed identities and timestamps rather than committing generated run payloads.

Generic HTTP worker failures retain bounded SDK diagnostics and real selected-run
describe/history/debug reads before timeout shutdown. A real 120-second timer
proved that the unchanged 30-second budget still fails and retains the original
waiting run, scheduled timer and pending timer task diagnostic. Failure reads
use a separate one-second request budget; inaccessible debug state is recorded
as an inspection failure. This capture is diagnostic evidence, not a completed
workflow or a diagnosis of the earlier unexplained PHP timer timeout.

Timeout/lease retries, uncaught failures and broader retry policies remain
separate gates. The next steps are broader cooperative cancellation,
schedules, visibility, authorization/namespaces,
streams and existing consumers. Each slice must run on the required database
matrix. Read-only upgrade inspection and backup-first takeover remain required,
but follow wider capability coverage. PHP and unknown databases stay refused.

Two schedule cases extend the reviewed corpus to 26: a manual lifecycle and
one fixed-rate occurrence with a one-action budget. Published source refuses manual triggers
while paused with `status_not_triggerable`; exhausting `max_runs` deletes the
schedule with `max_runs_exhausted`. Actual manual observations now agree across
HTTP/embedded adapters, and embedded execution completed the original fixed-rate
occurrence. The HTTP original fixed-rate observation hit SQLite write contention
under concurrent ordinary repair; Server #355 owns current-stable reproduction
and the intended failed-occurrence contract. The initial evaluator's `sh -ec`
wrapper also stopped on that failure; the published harness uses `sh -c`.
This explains the stopped role, not the original occurrence's failure.
The comparator checks retain original
schedule/fire/workflow/run/history relationships, exact int64 action input,
non-early occurrence timing, completed authored work and no later extra fire.
The 161 deterministic schedule checks reject damaged identity, quota, history,
typed payload, admission timing, deletion and post-close observations. They are
comparator examples, not additional executions. Frozen embedded resume can
record the caller model's stale zero skip count; the manual definition explicitly
pins that audit representation while requiring the fresh description and later
trigger to retain the actual skip. HTTP legacy-admin audit context is checked
before common projection; this is not broad authorization qualification.
Complete pending PHP/embedded records now match in a controlled reference
footprint that sequences the installed repair and evaluator CLI commands in one
maintenance loop. It deliberately excludes overlapping maintenance writes and
does not close #355. All four raw observations compare at adapter source
0212604f5a7a1c47b5664227b4483a6328ca520d. The first receipt with an incorrectly
entered runner SHA is retained and excluded; the valid records use Git's revision.
The tests-first native regressions compile and fail at the expected 501 schedule
creation on all six databases, with the preceding 32 HTTP tests passing.
Native now implements this bounded UTC interval slice, including manual
pause/resume/skips, retained audit history, original due occurrence admission
and one-action exhaustion. Start, workflow marker, schedule audit, accounting
and exhaustion share one transaction; an injected audit failure verifies rollback
without consuming the original occurrence or quota. Unsupported calendar,
timezone and overlap policies remain refused.
Three native HTTP regressions additionally cover close/reopen recovery and two
competing nodes admitting one original occurrence. This is graceful schedule
restart evidence, not schedule process-kill qualification. All 35 native SQLite
HTTP tests pass at `3213ce16`, together with the projection and atomic rollback
units. Its shared run stops earlier at frozen PHP worker shutdown contention;
that failure is retained separately. Repair head `169c2d7a` / test merge
`f1d62c41` passes [all six configurations](https://github.com/durable-workflow/server/actions/runs/38030503934).
Six downloaded ZIPs match official artifact digests and all 468 actual
PHP/Rust/embedded observations independently compare, including 36 schedule
observations. All 35 native HTTP tests/configuration and the twelve existing root
cleanup process-kill variants pass, together with TLS and PHP takeover refusal.
[Source gates](https://github.com/durable-workflow/server/actions/runs/38030504008)
pass with 2607 PHP tests / 57,222 assertions, six known deprecations and one skip.
The earlier frozen-PHP worker shutdown failure remains unexplained; a passing
later revision is not its diagnosis.

Qualification has already exposed missing completion status, the SDK
control-plane worker deletion route, MariaDB DECIMAL promotion when selecting
an aggregate unsigned sequence, and null decoded fields in fresh native workflow
descriptions. Native repairs retain the original stored envelopes and use a
bounded blocking codec worker to project display values after the database read.
The comparator also permits the first HTTP fixed-rate history read to see an
already committed original occurrence, requiring an exact original audit prefix
and the same final identity, deadline, history and quota checks. All 608 comparator
tests pass; they are not extra execution observations. The two definitions now
join the default corpus; their changed fixture identities and integrated runner
require the final source to pass the full matrix again before merge.

The current execution slice shares the native workflow/activity/timer/signal state machine
between SQLite, PostgreSQL and MariaDB/MySQL, with typed database adapters and explicit
transaction locking. Qualification runs the unchanged execution fixtures
and real lease interruption on each backend, with PHP and embedded targets in
independent databases. Existing PHP data remains refused until backup-first
takeover is implemented and qualified.

The PostgreSQL storage foundation prepares the real timestamp/JSON schema,
transactional migrations and ownership checks against an independently
bootstrapped published PHP database. Fresh native bootstrap keeps the full
existing physical schema; it does not authorize adoption of PHP data. Actual
Backup-first database takeover remains unimplemented.

Before the timer slice #339, the three shared fixtures cover echo, an exact int64 and one activity.
Database bootstrap and storage safety evidence do not increase that semantic
coverage. Native transitions remain serialized. The diagnostic PHP observations
are not a matched PHP/Rust performance comparison; that comparison waits for a
broader execution surface and must measure transaction serialization cost.

1. Inventory the current contract, schema and operational surfaces. Establish
   shared reviewed fixtures and a recorder usable against any isolated Server
   URL and a real embedded Laravel engine. Freeze published PHP/consumer artifacts
   and measure PHP before implementing Rust.
2. Add a Rust runtime in this repository through small vertical slices. Run the
   differential fixtures for each change and settle disagreements against the
   published contract before changing either implementation.
3. Complete the capability inventory, published SDK and consumer directions,
   three-database conformance, multi-node interruption and performance gates.
4. Qualify backup-first sequential takeover, interrupted migrations, PHP refusal
   of upgraded databases, operator procedures and the major-release decision.
5. Publish exact artifacts through the existing release process and verify
   downstream consumers. Cloud adoption belongs to its separate owner.

The first slice covers only Avro echo (including an exact large int64) and one external activity, completed by
the published PHP SDK and the embedded engine. Its fixtures require exact event
order, stable public workflow identity, retained run/operation relationships,
decoded input/result values and one committed completion. Passing these cases
does not prove the remaining acceptance criteria.

Normal published SDK query directions proceed from #415's identical previously
qualified tree, independently of General's PHP correction. Fresh main's frozen
SQLite reference failure stays retained; it is not relabeled or substituted
with the successful candidate. The [reviewed next scope](https://github.com/durable-workflow/server/issues/325#issuecomment-6115419641)
adds all nine query-client/workflow-author combinations, overlapping real author
and query-client processes, three cold committed checkpoints, exact fresh
Unicode/int64/boolean/null state and unchanged original durable histories.
The reader preserves Python's hydrated response and zero-argument input omission
and its additional workflow replay callback. Canonical embedded query execution
remains explicit. Source/model checks pass 50 query cases and all 4,731 Node
tests, language/format/shell checks and isolation policy plus 72 regressions.
These do not qualify runtime queries: initial live fixture execution must retain
the ordinary omitted-input disagreement before its reviewed native correction.
See [the query cohort](../tests/Fixtures/ServerParityProfiles/published-sdk-queries/QUERIES.md).

Initial [query #422 qualification](https://github.com/durable-workflow/server/pull/422#issuecomment-6116719443)
fails all six runtime jobs. Independently inspected
SQLite stops on the first PHP/PHP direction: eight real SDK processes complete
three query receipts and the original 13-event run, but the reader compares
computed query availability as durable state. The resumption snapshot can also
mix updated history with its prior progress/wait projection because the recorder
does not await the workflow-completion receipt. The original failed record and
complete wires remain retained. The parallel signal profile independently
passes 27 observations and 90 sidecars; all 173 original signal/query members
and compressed round-trip hashes are verified, with disposable resources removed.

The current recorder waits for the matching successful original workflow-task
receipt before taking each checkpoint snapshot. It binds task/run, owner,
attempt, actual command and request index. Only raw computed `can_query`
availability is separated from durable comparison, retaining boolean/presence
checks and the complete observed values. Every other action, progress/wait
field, control-plane copy and full history stays strict. Validation passes 71
query models, all 4,753 Node tests and 16 PHP recorder tests/80 assertions; these
are source checks, not fresh runtime qualification. Those recorder changes
leave native Rust unchanged.

The first recorder correction's PHP/source passes 2,696 tests/61,322 assertions
and SDK artifact integrity passes 297 original hashes, nine thin comparisons
and ten worker-source hashes. Its untouched PostgreSQL 17 query record remains
failed because the reader expected database row IDs in public history. The
[reviewed response-shape correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117007924)
binds row IDs, links and timestamps across actual worker claims and repeated
full query prefixes, and checks exact sequence/type/payload/timestamp against
the public API. Successful query worker receipts permit absent/null reason,
rejecting supplied errors. The complete original PHP/PHP record passes a
diagnostic replay under the corrected reader; neither tree is runtime-qualified
by that replay. The parallel PostgreSQL 17 signal profile independently passes
27 observations/90 sidecars; all 177 original signal/query files and compressed
round-trip hashes pass. Frozen tuples, resource limits and native code remain
unchanged; fresh full-cohort execution is required.

The later SQLite original records reach actual native SDK execution: all nine
PHP directions, nine embedded directions and native PHP/PHP pass. Native PHP
author/Python client then sends its ordinary zero-argument `{}` query and gets
HTTP 422 `unsupported_request_field`; the original run remains waiting with
three events and an unchanged complete snapshot. The [original refusal and
reviewed correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117247506)
remain explicit. Independent full-reader/package audits validate those 19
successful observations and bind the refusal to the original complete wire.

Native now encodes only a root empty object as an official Avro empty array on
the existing bounded blocking codec path. Supplied input retains its validation;
unknown fields, arrays/null, malformed/non-Avro input and required parameters
keep their refusals. A new real HTTP/database regression completes both omitted
and explicit empty queries and checks unchanged full original state/history;
fifteen invalid cases must leave query-cache inventory unchanged. Formatting
and all 76 execution tests pass in inspected SQLite and MariaDB runs. SQLite
independently passes all 27 query observations; fresh all-six qualification
is still incomplete. Current main's PHP recovery and release preparation are integrated
without changing the frozen tuples or taking over General's release work.

That fresh run fails five database jobs. Independently inspected MariaDB stops
in PHP reference recording after three passing directions, with
`spawnSync php ENOBUFS`: all eight actors for the fourth direction exit zero,
but full evidence exceeds the recorder's 4 MiB pipe buffer. The original failed
record remains intact. The [reviewed capture correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117614915)
uses temporary files, preserves complete observation bytes and enforces
64 MiB stdout/4 MiB stderr admission limits without changing the 45-second probe
deadline or runtime budgets. Five real child-process regressions and all
4,758 Node tests pass. New exact-source runtime qualification remains required;
the other four failed raw causes are not assumed identical.

The capture correction's fresh matrix passes SQLite/MariaDB, fails MySQL
8.0/8.4 and is cancelled on PostgreSQL 16/17. Inspected MySQL records stop on
the recorder's JSON parsing of an actively appended journal. Complete retained
lines parse; an unterminated prefix of an actual line reproduces the same
exception, while the exact original transient read is unavailable. The
[reviewed live-read correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117918727)
reads only newline-committed records incrementally, retaining exact bytes and
indexes. Final parsing binds one retained snapshot and refuses incomplete or
malformed data. PHP recorder tests pass 21 cases/97 assertions; four new checks
fail against the previous live-read path, and all 4,758 Node tests pass.
PostgreSQL 17's query cohort independently passes all 27 observations, but its
later pressure cancellation remains an incomplete job. Fixed execution budgets,
native code, SDKs, tuples and semantic reader rules are unchanged; exact-source
all-six qualification is still required.

The live-read correction's original matrix independently passes 27 query
observations/144 full query wire sidecars and 27 signal observations/90 full
sidecars per database across all six configurations. Each native execution
suite passes all 76 tests; PHP/source passes 2,706 tests/61,420 assertions.
Four whole jobs pass (SQLite, MariaDB, MySQL 8.0/8.4); PostgreSQL 16/17 remain
cancelled at the 20-minute job limit, confirmed by their GitHub annotations.
All retained pressure records match the source reader, but do not qualify
the cancelled jobs or establish completion before the deadline. The
[reviewed CI budget correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6118354755)
gives SQLite and PostgreSQL the same bounded 25-minute job limit already used
by MariaDB/MySQL. Fixture/probe/pressure deadlines, resource limits, SDK/native
code, frozen tuples and semantic assertions are unchanged. Fresh exact-source
full-matrix qualification remains required; earlier failed records stay held.

<a id="evidence-and-next-action"></a>

## Earlier evidence milestones

The sequence below preserves earlier implementation and qualification history.
The current checkpoint at the top of this log owns the next action and open
gates; historical passes remain specific to their recorded sources and tuples.

[PR #340](https://github.com/durable-workflow/server/pull/340) widens the corpus
into one/repeated signal deliveries. The published SDK authors signal-derived
condition waits; embedded PHP authors direct `signal()` waits. Their full,
explicit event inventories retain that difference before comparing payload-wait
semantics, cursor progress, typed values and accepted command/run relationships.
Initial local reference execution exposed the required `MessageCursorAdvanced`
event; it is now checked rather than discarded. Both reviewed cases executed
to completion on the frozen PHP Server and embedded package. Hosted Rust and
database qualification is recorded in the owning PR and issue; a local reference
pass alone does not qualify the native slice.

The native slice adds atomic accepted signal records and wait/resume transitions,
capturing the registered signal declarations in original start history. Public
control command order and deterministic authored call order are separate;
signals must not move replayed activity/timer/wait positions. Official Avro
signal decode/encode runs in `spawn_blocking` with one permit per runtime, held
until that job finishes even when its HTTP request is cancelled. Transaction
serialization, full codec resource limits and CPU/deadline qualification remain
open. Initial signal argument support was positional, without default/variadic
or class-type conversion. The current normal SDK signal slice implements
variadic tails with required positional prefixes; defaults and class-type
conversion remain unsupported and fail before durable writes.
Timed/grouped waits, cancellation, full rejection audit parity, authorization
and namespace coverage still require their own slices.

The initial source and published artifact tuple is in
[`../tests/Fixtures/ServerParity/php-baseline.json`](../tests/Fixtures/ServerParity/php-baseline.json).
It freezes inputs, not a passing performance result. Fixture and runner commits,
hardware, resource limits and actual measurement commands belong in each retained
result. Do not compare performance across concurrent unrelated host workloads.

The first HTTP and embedded recordings passed on the frozen amd64 image and
Workflow package, with an independent SQLite database for each. Hosted
[fixture execution](https://github.com/durable-workflow/server/actions/runs/37889894631)
retains both raw records and the comparison for 90 days. It executes both
workflows to completion, including the activity, with the published PHP SDK.
[PR #326](https://github.com/durable-workflow/server/pull/326) owns source checks
and review. The linked final run covers all three cases, including the large
int64 and explicit decoded type trees.
This is bounded correctness evidence, not performance qualification
or a three-database/three-runtime pass.

The fixture foundation is merged in #326. All three cases and 25 comparator
checks passed at its exact head, together with the full PHP feature suite.

The [development reference profile](../benchmarks/server-port/v1/README.md)
pins the current PHP/SDK tuple and uses existing standard-workflow and mixed
idle-poll commands. Its preparation is merged in [#327](https://github.com/durable-workflow/server/pull/327).
The [diagnostic observations](../benchmarks/server-port/v1/php-observations-2026-10-09.md)
are complete: three repetitions at 0.25 and 0.5 offered starts/s completed all
workflows in-window; at 1/s, only 48–51 of 120 completed in-window before the
remainder drained. The high-rate retry had one readiness 503, and the original
second run's configured hourly worker exit invalidated its resource comparison.
All outcomes and failures are retained. Following drift windows passed; sampling
gaps, missing complete client resource costs and changed background load still
prevent a PHP/Rust improvement claim. The development host's kernel,
runtime and SATA storage differ from the standard capacity topology, so that
profile preserves the full capacity gate and makes no maximum-capacity claim.

Mixed idle observations completed three repetitions each at 6, 12 and 24 polls.
Six polls returned six empty outcomes; twelve returned eight empty outcomes and
four explicit capacity rejections; twenty-four returned eight empty outcomes and
sixteen rejections. All registrations were removed, with no claimed tasks,
runtime restart or OOM. Query waits were clamped to about five seconds, while
workflow/activity waits lasted about ten seconds. Health/readiness probes passed
throughout the six- and twelve-poll runs. All three twenty-four-poll registration
bursts caused at least one two-second API probe timeout, with an Apache
`MaxRequestWorkers` warning observed. These failures remain part of the reference
evidence; API headroom at that burst size is not qualified. Shared-host background
work changed between measurements, so a PHP/Rust performance gain still requires
matched conditions and drift controls.

The unpublished [Rust foundation](../rust/README.md) in
[#329](https://github.com/durable-workflow/server/pull/329) implements the Value
adapter and complete-frame check, retaining exact int64, bytes/text, array/map
and finite-double distinctions. Its first tests exposed Apache's streaming EOF
null sentinel; the frame adapter now rejects incomplete datums. Fifteen reviewed
logical values passed local Rust/PHP cross-decoding in both directions, including
the existing golden long-zero wire. These are codec checks, not execution of a
durable workflow on Rust. Malformed collection blocks, duplicate keys, resource
limits and external payload ingress remain explicit gates before HTTP integration.

[#330](https://github.com/durable-workflow/server/pull/330) adds actual HTTP
execution: published SDK workers lease tasks, commit activity outcomes, replay
persisted history and complete workflows against an independent native SQLite
database. The unchanged three fixtures first passed locally against all three
targets, including exact large-int64 and activity relationships. Transactional
tests cover persistence, independent connection-pool claims, stale fences,
duplicate completion/poll receipts, pagination and unsupported-batch refusal.
An actual SIGKILL with an outstanding activity passed locally through
the normal SDK loop after restart: the original run/history survived, the old
expired claim was refused, and attempt two committed one outcome. These checks establish the first bounded
slice, not full protocol, multi-node, timeout/retry or database parity.

HTTP passes opaque Avro envelopes without invoking the incomplete decoder.
Discovery/readiness explicitly identify development status, and missing
commands/effects are refused. A read-only preflight refuses existing PHP data;
native fresh-database bootstrap does not substitute for sequential backup-first
takeover. Published PHP still has no startup refusal guard, so PHP must never
connect to this development database. MySQL/PostgreSQL, scoped auth/namespaces, external payloads,
timers/retries/cancellation, complete capability/consumer matrices and performance
remain required.

The [native build observations](../rust/runtime-build-observations-2026-10-09.md)
record a clean tests/binary build at 54.65 seconds and 521.4 MiB of targets;
downloads add 202.6 MiB. Clippy and incremental outputs bring the observed
combined state to about 868 MiB. Build-only no-op/comment edits took 0.159/1.513
seconds. These bounded measurements exclude downloads/startup and make no
full-server/cache-saving claim.

#330 is merged. Its exact head passed the three-runtime comparison, seven
SQLite transaction/HTTP tests, four codec tests, two-way codec values and an
actual SIGKILL/replacement-container recovery check in the shared Action. The
complete PHP feature/source suite and repository/boundary checks also passed.

[The PHP fence in #331](https://github.com/durable-workflow/server/pull/331) establishes PHP refusal before a Rust-marked database can be
mutated. The reserved, unprefixed `dw_server_schema` relation is the one-way
ownership fence: its existence refuses PHP even if its rows are empty, malformed
or from an unknown future version. Removing the marker is not a rollback path.
Stop every PHP write-capable role before Rust takeover; a connection preflight
does not make a concurrently mixed fleet safe.

The fence guards Laravel's official SQLite, MySQL/MariaDB and
PostgreSQL connectors before connection setup can execute durable writes. A
SQLite file must first be opened read-only, including visible WAL state; an
immutable snapshot is insufficient. Probe failures must not be mistaken for
marker absence. The normal container entrypoint checks ownership before
starting its role, while connector checks protect direct bootstrap, migration,
worker and scheduler paths and reconnects. Unmarked PHP databases must remain
usable, and unavailable-backend diagnostics must remain truthful.

Acceptance uses real PHP processes and physical database state: refuse startup
and write-capable commands against marked SQLite/MySQL/PostgreSQL databases,
preserve sentinel data and schema, reject empty/future markers, observe a marker
still in SQLite's WAL, and continue ordinary bootstrap against unmarked data.
This protection does not claim backup-first conversion, interrupted migration,
full-schema compatibility or a qualified takeover.

The process fixtures cover physical startup/bootstrap/migration/wipe/worker/
scheduler refusal, malformed/future markers, reconnects, unmarked bootstrap,
URI/WAL visibility, nested named-connection wipes, deferred initialization SQL,
hidden-marker permissions and silent PDO modes. MySQL and PostgreSQL are exercised
in the feature Action. The additional local MariaDB run uses an actual MariaDB
11.4.13 deployment. Existing bootstrap, SQLite lock-pressure, unavailable-backend
and growth-inventory contracts also run; exact-head evidence is linked from #331.

The real SIGKILL fixture initially exposed a recovery regression: SQLite must
repair a hot rollback journal before reading schema. The fence now recovers a
private copy with SQLite, checks committed ownership and unchanged input, and
only then allows ordinary PHP recovery. Fault fixtures verify acknowledged PHP
data, a marker restored from an uncommitted drop without changing the original,
concurrent startup, an interrupted probe, a replaced database, actual low-space
refusal and protection of external super-journal filenames. Root startup and
Apache's runtime UID have separate private copies. The
[operator guide](php-database-ownership.md) records temporary disk requirements,
cleanup and the explicit reserved-name grant needed by table-only MySQL/MariaDB
roles. This does not claim migration or production takeover qualification.
Cache-only restart and scheduler-cache maintenance remain usable during a
database outage; early command preflight targets write-capable roles rather than
every command sharing their prefix.

The next foundation aligns fresh native SQLite databases with the complete
schema created by the frozen published PHP image: 49 tables and 333 explicit
indexes, including credentials, payload holds, timers, cancellation and legacy
infrastructure. SQLx records and validates native migration checksums. Worker
registration uses the existing `workflow_worker_registrations` relation;
execution timeout stays on the workflow instance. Native completion/poll receipts
use separate relations rather than replacing PHP-owned columns.

This is a fresh-database development bootstrap, not permission to adopt PHP
data. PHP databases and the earlier abbreviated native development schema remain
refused before writable connection setup. No observable protocol capability is
added. Qualification must compare the actual published PHP catalog with an
independent native database, preserve the existing execution/restart fixtures,
and test interrupted/concurrent bootstrap and migration-history mismatches.
The local implementation passes the existing seven execution tests, four codec
tests and five new schema cases. The latter check three concurrent fresh nodes,
real SIGKILLs after migration-ledger creation and before the outer commit,
read-only refusal of old development data and nine catalog/history corruptions.
Restart after either acknowledged interruption creates one complete migration
and passes SQLite integrity checking. The actual published/native catalogs
match. The [shared hosted run](https://github.com/durable-workflow/server/actions/runs/37929268377)
passes the unchanged three-runtime fixtures, two-way codec values, native
schema interruption and actual activity-lease kill/restart. Its catalog check
retains the original database and existing journal hashes. An earlier inspection
mount prevented SQLite's temporary WAL metadata access; the corrected fixture
uses `mode=ro` on stopped engines and a writable disposable metadata directory,
without an immutable snapshot that could conceal WAL state.
The final fixture additionally prepares pending work in the published PHP
database and requires native refusal with unchanged database/journal hashes.
Its final-head evidence belongs to [#332](https://github.com/durable-workflow/server/pull/332).
SQLite's [read-only WAL contract](https://sqlite.org/wal.html#read_only_databases)
permits creating shared-memory metadata and an empty WAL when those files are
absent. The refusal fixture exposed this behavior on the real published PHP
database. Native startup now awaits probe closure on success and refusal. The
receipt treats an absent and exactly zero-byte WAL as equivalent; any nonempty
WAL, rollback journal and database must retain its exact hash. Unit cases cover
both a closed WAL database and committed work still present in a nonempty WAL.
The final local suite passes 16 cases plus the two invoked fault subprocesses;
formatting, warning-free Clippy and the 72 Docker-isolation policy cases pass.
The [build observations](../rust/schema-build-observations-2026-10-09.md)
record 64.445 seconds for a clean tests/binary build, 541.9 MiB of targets plus
177.6 MiB of downloads, and 0.137/0.823-second no-op/comment builds. These are
bounded development measurements, not a full-server or improvement claim.

[The PostgreSQL foundation in #333](https://github.com/durable-workflow/server/pull/333)
adds native schema bootstrap and read-only inspection commands. Its migration
uses the actual frozen PHP PostgreSQL schema: 49 tables, 382 indexes including
primary/unique constraints, 92 constraints and 17 owned sequences. Unlike
SQLite, timestamps retain six-digit precision and payload metadata uses native
JSON columns. Native receipts and SQLx history occupy separate relations.
Startup fixes the development schema to `public`/UTC and refuses PHP data in
any user schema, corrupt catalogs or unknown history before writable access.
A transaction-scoped advisory lock serializes initialization; the outer
transaction commits DDL, ledger and ownership together. PostgreSQL execution
is not enabled by these commands.

The real local PostgreSQL 17 checks pass six backend cases: concurrent fresh
nodes, exact typed JSON/int64/microsecond values, SELECT-only inspection,
existing/custom-schema data refusal, thirteen corrupted catalog/history states,
and SIGKILLs after ledger creation and before commit. A restarted initializer
sees no partial schema and creates one valid migration. The actual published
PHP database also contains a leased activity; after stopping PHP, native
bootstrap refuses it and all row/sequence fingerprints remain unchanged.
TLS uses SQLx/Rustls, with a trusted CA and hostname verification; actual
untrusted certificates and wrong hostnames are refused. Hosted qualification
targets the pinned PostgreSQL 16 and 17 images, alongside the unchanged
PHP/Rust/embedded SQLite fixtures and real activity restart.

The first catalog-formatting helper rounded an int64 sequence maximum. Capture
now uses PHP's exact 64-bit values. A PostgreSQL UNION also inferred the fixed
`name` type and truncated composed catalog keys; the query explicitly selects
`text`, and a regression case verifies distinct long constraint identities and
the exact sequence maximum. These corrections strengthen the schema checks;
they do not change the reviewed execution fixtures.

The [PostgreSQL build observations](../rust/postgres-build-observations-2026-10-09.md)
record 86.755 seconds for clean default tests/binary, 747.1 MiB of targets plus
306.3 MiB of Cargo state, and 0.147/1.614-second no-op/comment builds. Additional
checking/incremental outputs reach 921.3 MiB of targets. Inputs and exclusions
are explicit; no full-server or causal performance gain is claimed.

[PostgreSQL execution in #334](https://github.com/durable-workflow/server/pull/334)
uses one typed execution state machine for both native backends. JSONB,
microsecond timestamps and PHP's integer widths retain their physical types.
Decode failures return bounded errors rather than panicking. SQLite keeps its
existing native text layout. SQLite history limits now count UTF-8 bytes rather
than characters; PostgreSQL bounds its JSON serialization before hydration.
No migration or schema version change is needed for this execution slice.

The common HTTP cases pass on both backends, including concurrent starts and
completions, independent claims, revoked/expired fences, exact large integers,
persistent receipts, poll retries, pagination, atomic unsupported-command
refusal, timestamp precision and oversized Unicode history. PostgreSQL uses
a transaction-scoped advisory lock for this first default-namespace slice,
separate from initialization. Empty polls release the transaction before waiting;
serialization and full CPU/throughput/idle cost remain unqualified.

The [shared hosted run](https://github.com/durable-workflow/server/actions/runs/37943502489)
passes on the pinned PostgreSQL 16 and 17 images. Each executes the unchanged
echo, int64 and activity fixtures against independent published PHP, Rust and
embedded databases. Two native processes share only their own database. The
probe prepares a leased activity, SIGKILLs one process, verifies survivor
readiness, starts a replacement, lets the persisted lease expire and finishes
through the published PHP SDK. It retains original run/history relationships,
rejects the old claim and records one activity/workflow outcome. This bounded
case does not establish all failure boundaries, routing or full multi-node HA.
The existing schema, interruption, read-only PHP refusal, TLS and SQLite/codec
checks remain in the Action. Existing PHP data is still refused, not converted.

The [execution build observations](../rust/postgres-execution-build-observations-2026-10-09.md)
record 83.054 seconds for clean default tests/binary, 787.0 MiB of targets plus
306.4 MiB of Cargo state, and 0.145/1.911-second no-op/comment builds. Retained
incremental outputs total 825.5 MiB. These bounded development samples do not
qualify the complete server or establish a causal speedup.

The [MariaDB/MySQL execution slice in #335](https://github.com/durable-workflow/server/pull/335)
adds the third typed adapter to the same state machine. Separate physical
catalogs retain the frozen PHP MySQL and MariaDB schemas. A bounded session
lock and checksummed initialization marker account for implicitly committed
DDL; only unchanged, empty native partial initialization can resume. Existing
PHP databases, changed catalogs and unknown migration history are refused.
The [hosted qualification](https://github.com/durable-workflow/server/actions/runs/37953419450)
passes on MySQL 8.0.46, the existing PHP MySQL 8.4.5 image and MariaDB 10.11.19.
Each runs seven storage cases, including actual process kills at three
acknowledged initialization boundaries and safe refusal of changed or occupied
partial initialization. The common HTTP suite passes nine selected-backend
cases plus its SQLite-specific PHP-file refusal case. All three engines pass
the unchanged PHP/Rust/embedded fixtures, two-node activity kill/replacement,
unchanged PHP rows/catalog/next-ID counters on refusal and actual CA/hostname
TLS acceptance/rejection. PostgreSQL 16/17 and SQLite/codec checks pass in the
same run. A bounded InnoDB ownership-row lock serializes this development
slice; these results do not establish complete multi-node HA or performance.

The [build observations](../rust/mysql-execution-build-observations-2026-10-09.md)
record 89.438 seconds for clean default tests/binary, 865.1 MiB of targets plus
306.4 MiB of Cargo state, and 0.146/1.031-second no-op/comment builds. Retained
incremental outputs reach 879.9 MiB. Full-server build cost remains unqualified.

Next: qualify backup-first sequential takeover with
stored-value and migration-interruption fixtures. The capability, consumer,
performance and operational inventory remains open under #325.
No separate defect issues have been filed yet.

The [pending-task correction in #336](https://github.com/durable-workflow/server/pull/336)
addresses a prerequisite compatibility case: PHP treats a null
`workflow_tasks.available_at` as immediately available, while the native query
excluded it. The focused regression reproduces the native stall; the common
polling predicate now admits null availability while future-dated work waits.
PHP and native HTTP cases cover workflow/activity tasks, original run identities,
duplicate activity completion and the final durable workflow outcome. Exact-head
hosted qualification is recorded in the PR. This correction precedes read-only upgrade
preflight; it does not enable database takeover.

The existing activity-completion contract permits a committed retry to return
`409 stale_attempt` with completed statuses; the PHP fixture checks that response.
Native receipts currently return `200` with `recorded=false` for an identical
retry. Both cases check one durable outcome; complete completion-error envelope
parity remains part of the wider #325 qualification.

Final checks of the pending-task correction caught `SQLITE_BUSY` while three
native runtimes opened one fresh SQLite file, before its HTTP case ran. The [startup
correction in #337](https://github.com/durable-workflow/server/pull/337)
retries only that transient database error, with at most three attempts;
each repeats read-only ownership preflight and retains the existing lock limits.
An unsuccessful bootstrap closes its pool and finishes rollback before retry.
Other errors and ownership refusals return immediately. Ownership inspection
checks all user objects, including view-only databases and names such as
`sqlitex_customer_data`, and repeats the same
empty/native ownership check under the initialization write lock before DDL.
The concurrent-runtime fixture exercises sixteen independent fresh databases;
three unknown-object refusal cases require unchanged file bytes and no WAL/SHM
creation. Catalog inspection uses SQLite's literal internal-name prefix, so a
SQL wildcard cannot hide user objects.
Exact-head qualification is recorded in #337. Ordinary command execution and
PHP takeover remain outside this startup correction.

The [SQLite timestamp correction in #338](https://github.com/durable-workflow/server/pull/338)
addresses the next stored-representation prerequisite. The frozen published PHP
Workflow models write UTC SQL timestamps with microseconds; earlier native
development data used UTC RFC3339. Reads now accept both, while new SQLite writes
use the PHP UTC shape. Candidate ordering, lease expiry and poll-receipt cleanup
compare exact decimal timestamps across both shapes without floating-point date
conversion. Eligible task timestamps are decoded before any lease mutation.
Non-UTC stored offsets require explicit conversion before takeover.

The tests-first commit reproduces both unsupported PHP timestamp decoding and
premature same-day availability at a one-microsecond boundary. Twelve fixed-clock
SQLite cases exercise the actual candidate/receipt queries; the common HTTP
case seeds the PHP microsecond representation and verifies description precision
and stale-lease refusal. The frozen PHP model separately reproduces the three
microsecond fixture strings without opening a database. Exact-head shared and
PHP/source qualification is recorded in #338. These are representation tests
against separate native data, not a sequential takeover or timezone qualification.
Read-only upgrade preflight, pending poll bindings, timer jobs, credential and
external payload preservation remain required follow-up under #325.

[The timer slice in #339](https://github.com/durable-workflow/server/pull/339)
adds reviewed one-timer and repeated zero/delayed-sleep fixtures. Their contract
requires original timer IDs/deadlines, deterministic command sequences,
non-early firing and one completed typed outcome. Twelve new comparator cases
include corrupted timer identity, delay, sequence, deadline and duplicate/early
firing counterexamples. The tests-first native regression refuses `start_timer`
with 422 on the previous execution slice; matrix receipts are in the PR.

The first implementation run completed all five PHP and native SQLite cases,
but exposed two further differences. Embedded PHP's immediate zero-delay
`TimerFired` omits `fire_at`; the reviewed repeated-timer fixture explicitly
permits only that omission. Its scheduled deadline and exact firing timestamp
remain authoritative; positive-delay firing must repeat the unchanged deadline.
The comparator retains sub-millisecond precision rather than rounding early
firing into equality. MySQL's driver-owned JSON-path query must bypass the
generic PostgreSQL placeholder conversion, which otherwise mistakes a literal
`$.timer_id` for a binding and panics. The same recovery case requalifies it.
Comparisons also bind saved expectations directly to the current reviewed source
fixtures; matching edited recordings cannot invent replacement expectations
while retaining copied fixture hashes and pass labels.

Native scheduling is implemented with persisted PHP-layout timers and timer
tasks. A bounded background batch rechecks pending work under the existing
cross-node transition lock, atomically commits firing and the next workflow
task, and wakes polls after commit. Idle ticks use a read probe; they do not take
the transition lock. Shutdown joins the scheduler before closing storage;
database turn failures suppress readiness and emit a bounded diagnostic until
the next successful turn. There are no new dependencies or schema changes.
Qualification outcomes are recorded in #339. Cancellation/parallel timer groups, expiry recovery,
missing timer reconstruction, CPU-heavy deadline health, throughput and complete
operational parity remain open. This slice does not qualify database takeover.

The [external-payload development slice in #450](https://github.com/durable-workflow/server/pull/450)
now accepts an explicit verified local reference for workflow input and terminal
workflow output. Output retention commits in the same transaction as the fenced
completion and history. Wrong attempts and malformed commands leave the object
unclaimed; cold descriptions/history retain its opaque reference. The focused
HTTP case also checks the existing native exact-body duplicate receipt without
claiming a stronger shared PHP receipt or provider-loss contract.

The installed PHP SDK recipe `external-result-client.php` explicitly uploads the
bytes and calls the public task-completion API. A separate cold process reads
the completed output with the normal SDK. Frozen PHP and native SQLite each
preserve the same 4,194,392 encoded bytes and SHA-256, a 3 MiB string, signed
integer 9007199254740993, null and Unicode within the original 30-second
observation budget. Stopped-state inspection confirms one completed
first-attempt task, three events and one retained object per qualified original.
The first PHP fixture original remains unsuccessful after a local field-name
error before completion; the qualified original is separate. Both application
containers stop with 137/no OOM, so this is not graceful-shutdown evidence.

Local verification passes 66 library and 103 SQLite HTTP cases, with 18
explicit database/subprocess library ignores. Full transport discovery stays
unadvertised. Activity, query and memo ingress, completion-upload allowance,
automatic result externalization, S3, cleanup/backup and all-database external
semantics remain open. Exact source and hosted qualifications belong to #450;
these observations do not qualify a release or database takeover.

Explicit activity results now use the same verified reference preparation, with
retention inside the original activity outcome transaction after lease, attempt,
run and activity deadline fences. Stale attempts and elapsed leases leave the
object unclaimed. A real database trigger refuses history publication after
retention; rollback restores both the object and original activity before a
matching completion and cold replay succeed. Local checks pass 66 library and
104 SQLite HTTP cases, followed by all eight external HTTP cases after adding
that final rollback control. Other database families remain hosted gates.

The result recipe's `activity-author` phase uses actual SDK workflow admission
and task polling, then an explicit HTTP activity-result completion. Its separate
`activity-reader` process uses the normally installed SDK to hydrate the original
activity history and complete the same workflow. Frozen PHP and native SQLite
each preserve the exact 4,194,392-byte result, one actual activity attempt,
six events and three first-attempt tasks with zero repairs. Published PHP also
automatically uploads the small final summary under its policy; native keeps
that summary inline because automatic upload discovery remains unadvertised.
This measured difference is not full automatic externalization parity. No
graceful shutdown is claimed from the stopped 137/no-OOM containers. Activity
arguments and the remaining external transport gates stay open under #450.

Activity arguments now accept verified local references too. A completion batch
prepares at most the existing 100 commands under one original deadline and one
shared transfer slot; every verified snapshot retains that slot until the last
consumer finishes. Two distinct 4 MiB references pass one batch without waiting
on their own admission limit. A later terminal completion with pending work
rolls back both schedules, history and retention claims. The same original then
accepts the matching schedules, and both opaque argument deliveries survive a
cold open. This control does not claim a completed two-activity workflow.

The separate installed-worker recipe `external-arguments-worker.php` completes
one real large-argument activity and its original parent through three cold
processes. Frozen PHP and native SQLite preserve 4,194,356 encoded bytes/hash,
the exact 3 MiB string, integer 9007199254740993, null and Unicode, six events,
three first-attempt completed tasks and zero repairs. PHP's automatic summary
upload and native's inline summary remain the earlier explicit policy difference.
The first PHP fixture handler omitted required ActivityContext and never claimed
its activity; only the separate corrected original qualifies.

67 library cases and nine focused external HTTP cases pass. The first full
105-case run has 104 passes and one failure: the existing 100 ms stalled-upload
control's next valid upload returns 503 instead of 201. Three isolated identical
controls and one unchanged full 105-case repeat pass. The original remains
failed, its cause unestablished, with no timeout/source change or claimed fix.
Exact source and hosted qualification remain in #450. No automatic upload,
S3, cleanup/backup, migration or release claim follows.

The same installed-worker recipe now also accepts
`DW_PARITY_EXTERNAL_ACTIVITY_COUNT=2`. Frozen PHP and the unchanged native
binary each complete two distinct 4,194,356-byte argument references admitted
in one workflow-task completion. Two actual activity callbacks, two payload
GETs and two committed activity completions precede a separate cold parent
reader. It joins results by authored activity sequence without changing event
arrival order. Exact int64, null, Unicode and both 3 MiB string hashes survive.
PHP completes in 8.17 seconds and native in 6.68 seconds against the original
30-second budget. These single observations are functional checks, not a
performance comparison.

Stopped-state inspection finds the same nine-event history and task pattern in
both databases: four first-attempt completed tasks, zero repairs, and one extra
unclaimed ready workflow task. A final poll after closure returns no task in
both implementations. Both large objects remain retained with exact provider
bytes and no expiry. PHP also externalizes the two small activity results and
final result; native keeps those inline under its unadvertised automatic policy.
Both containers stop with 137 and no OOM. All local databases, provider objects,
SDK dependencies, compiler output and task resources are removed after this
audit. Hosted all-database external semantics remain pending.

The upload/poll-hint candidate now incorporates exact qualified PHP source
`1f994962` and its `applyStickyCacheClaim` primary-key correction, including
the current PHP release metadata and numeric declaration normalization. Its
WorkerController bytes match the qualified PHP handoff. This combined tree
requires fresh normal and six-database qualification. The preceding `3409a776`
component pass, its MySQL-only retry, and all failed originals remain their
separate records; none qualifies this integration or a native release.

The separate activity-result candidate now has installed consumer evidence on
all three database families: 48 originals and 192 author/activity/reader/cold
process phases match published PHP for null, false, typed int64/null/Unicode maps
and a 2 MiB external value, in default and named namespaces. It reuses an audited
optimized native binary without compiling locally. This is endpoint/client
qualification, not a performance or discovery claim.

The next installed retry probe initially required a terminal `ActivityFailed`
event for a retry. That PHP original remains failed. Correcting the fixture to
the canonical `ActivityRetryScheduled` event produces four fresh SQLite passes,
two per implementation. Eight actual callbacks, four failure submissions and
four completions preserve their original 30-second observation budgets and
authored retry policies. Separate parent/cold readers preserve the scheduled
history prefix and exact typed result. Stopped SQL states match: two completed
runs, two completed activities at attempt2, eight completed tasks and sixteen
events per implementation, with zero repairs. Both APIs exit0 without OOM.
Listing, unrelated-object isolation, embedded outcome checks, all-database
installed retry consumers and Waterline acceptance remain open; discovery stays
unadvertised. Raw phase data and dependencies are deleted after immediate audit.

The next operator read slice is the existing PHP targeted activity-result
endpoint from #439/#440. Its [contract](contracts/workflow-activity-result-inspection.md)
and separate fixture/profile define canonical history selection, exact ownership,
null versus absence, explicit unavailable states and opaque external transport
before native implementation. The selected published PHP reference is separate
from every frozen actor. Native discovery and Waterline consumption remain pending.

The native targeted route now reads one latest canonical activity snapshot after
literal namespace/workflow/run binding. Its state and envelope projection never
uses the mutable activity result. Focused HTTP tests preserve null, false, int64,
Unicode and closed-parent results, refuse foreign identities and worker roles,
distinguish missing/pruned history and follow genuine retry/failure/cancellation
outcomes. All three original route controls fail the preceding runtime. Earlier
fixture setup failures used a wrong retry field and a globally reserved workflow
ID, and do not count as runtime counterfactuals. The first MySQL candidate exposed
a missing dialect-template registration, corrected before its four passing
controls. Those same four cases pass SQLite, MySQL 8.4 and PostgreSQL 17. The
SQLite execution suite passes 117 cases. Library checks pass 72 cases with the
existing 18 database/subprocess ignores.

Separate normally installed PHP SDK 2.4.2 processes complete eight fresh originals
per target on published PHP 2.8.3 and native SQLite. Default and named namespaces
each preserve null, false, the typed map and a 2,796,296-byte external value.
Every original completes one activity and its original parent within its initial
30-second observation budget. Cold readers retain history sequence 5 and exact
decoded values after the different parent output commits. Stopped-state audits
find 48 events and 24 completed first-attempt tasks per target, zero repairs and
no ready backlog. Both APIs stop with explicit SIGTERM, exit 0 and no OOM.
The external producer uses explicit upload/protocol completion. This does not
qualify automatic Worker uploads. Capability discovery, metadata-only listing,
unrelated-object isolation, all-database installed consumers, embedded negative
controls and Waterline acceptance remain open.
