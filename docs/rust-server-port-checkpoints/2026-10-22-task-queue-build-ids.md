# October 22, task queue build ids

- **Found by.** The unchanged CLI command `dw task-queue:build-ids <queue>`
  against native. It reads `GET /api/task-queues/{queue}/build-ids`, which
  native did not route: the default namespace got
  `501 rust_capability_not_implemented` and a named namespace
  `422 development_namespace_operation_unqualified`.
- **Source.** Native follows published PHP Server 2.10.0's
  `TaskQueueController::buildIds` and
  `TaskQueueBuildIdRolloutSnapshot::forTaskQueue`, in the default or a named
  namespace, for an operator or administrator:
  - **Cohorts.** The queue's registrations, newest heartbeat first and then
    by worker id, grouped by trimmed build id. A missing or blank build id is
    the unversioned cohort (`build_id: null`).
  - **Worker counts.** A worker is stale when its heartbeat is older than
    `stale_after_seconds`; one that never heartbeated is not. Otherwise its
    declared status decides, compared untrimmed: `stale` and `draining`
    count as such, and any other status counts as active.
  - **Runtimes and SDK versions.** Trimmed, distinct and sorted with PHP's
    `sort()`. Like PHP's array keys, an integer-like value such as an SDK
    version `10` comes back as a JSON number.
  - **Definition fingerprints.** `workflowDefinitionFingerprints` and
    `fingerprintSummary`: the count of distinct fingerprints per workflow
    type, and the types with more than one, by type. As in PHP, a workflow
    type stored as an integer-like key is skipped.
  - **Times.** `last_heartbeat_at` is the latest heartbeat and
    `first_seen_at` the earliest registration, in Carbon's `toJSON()` form.
  - **Pending workflow tasks.** `pendingWorkflowTaskCountsForTaskQueue`:
    ready and leased workflow tasks counted by the task's trimmed build id,
    else its run's. A build id with pending tasks and no workers gets its own
    `no_workers` row. `pendingWorkflowTaskDiagnostic` reports
    `no_compatible_worker`, with PHP's message, for a build id with pending
    tasks and no active worker.
  - **Status and order.** `buildIdRolloutStatus` and
    `buildIdRolloutRank`: active, active with draining, draining, stale only,
    then no workers, the unversioned cohort after a build id with the same
    status. Ties go to the latest heartbeat, then to first appearance.
  - **Rollouts.** Native keeps no build-id rollouts. Takeover refuses a
    database that holds any (`upgrade_source_build_id_rollouts_unsupported`)
    and native never writes one. So every row reads as PHP reads a build id
    without a rollout: `drain_intent: active`, null `drained_at`,
    `promoted_at` and `rolled_back_at`, and `new_start_selected: false`,
    since `WorkerBuildIdNewStartSelector::selectedKeyFromRollouts` selects
    nothing from no rollouts.
- **Differences from PHP.**
  - `POST /api/task-queues/{queue}/build-ids/promote`, `/drain` and
    `/resume` change build-id rollouts, which native does not have. Native
    refuses them in every namespace with
    `422 task_queue_build_id_rollout_unqualified` and changes nothing. PHP
    records the rollout. The `task-queue:promote`, `drain` and `resume` CLI
    commands therefore fail with that reason.
  - PHP's pending-task query has no `ORDER BY`. When several build ids have
    pending tasks and no workers, PHP lists them in the database's grouping
    order. Native uses SQLite's order on every backend: leased before ready,
    then by build id.
  - JSON key order differs, as in every native response.
- **Tests.** Three integration tests and three unit tests:
  - One registers nine workers across four build ids and the unversioned
    cohort, with stale, draining, never-heartbeated and declared-stale
    workers, two fingerprint sets with a conflict, numeric SDK versions and
    eight runs whose tasks carry build ids on the task or the run. It checks
    the whole response, including the order, and that promote, drain and
    resume are refused and change nothing.
  - One checks an empty queue.
  - One checks namespace scoping, operator and administrator access, the
    worker role's 403 and the unknown namespace's 404, each identical to
    `GET /api/task-queues/{queue}`, and the refusals in a named namespace.
  - Unit tests cover PHP's array keys and sorting, fingerprint reading and
    the pending diagnostic.
  - After merging main (#659): the full SQLite suite passes (384), and the
    task-queue tests pass on PostgreSQL and MySQL (5 each).
