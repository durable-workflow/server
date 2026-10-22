# October 22, workflow definition routing

- **Gap.** Native records the definition fingerprint a start's worker cohort
  agrees on, with `workflow_definition_fingerprint_source: worker`, as PHP
  does. It then offered that run's workflow tasks to any worker, so a worker
  with another definition could take a pinned run while the recorded one was
  live.
- **Source.** Server 2.10.0's `WorkflowDefinitionRouting::forWorker`,
  which `WorkflowTaskPoller::matchesRecordedWorkflowDefinition` applies at
  claim, and `WorkflowReplayEligibility`.
  - **Decision.** For a workflow task of a worker-pinned run:
    - a worker advertising the recorded fingerprint for the type takes it
      (`matching_definition`);
    - without a recorded fingerprint (`recorded_definition_missing`), or
      without one from the worker (`worker_definition_missing`), nobody
      does;
    - a worker with another definition waits while an eligible peer
      advertises the recorded one (`matching_definition_preferred`);
    - otherwise it takes the task only if it advertises
      `workflow_entry_arguments_validation` (`replay_required`, else
      `entry_validation_unavailable`).

    An unpinned run goes to any worker (`not_worker_pinned`). Native
    refuses to start with `DW_V2_PIN_TO_RECORDED_FINGERPRINT` off, so
    pinning is always on.
  - **Eligible peers** (`eligibleWorkers`). Active registrations on the
    run's namespace and queue that:
    - match the build the task or run requires;
    - support the type, with workflow capacity above zero;
    - replay the run (`WorkflowReplayEligibility::allows`). That means a
      heartbeat inside the stale window and cooperative-cancellation support
      when the run has a request, at the protocol version the worker last
      reported. It also means the memo and typed search attribute
      capabilities its history needs.
  - **Protocol version.** Each registration and heartbeat records its
    protocol version under `_worker_protocol_version` in the worker's process
    metrics, after the metrics it reported. A heartbeat without metrics
    keeps the stored ones. Worker listings therefore show the key, as PHP
    2.10.0's do; 2.5.14 had no such key.
- **Reports** (`inspect` and `forQueue`).
  - Describe, repair and debug report `definition_routing` for the run's
    next due ready task:
    - the context (`pinning_enabled`, `worker_pinned`,
      `recorded_fingerprint`, type, queue and required build);
    - PHP's status, the counts of eligible workers by definition, up to ten
      example workers, `blocked` and `runnable_task`;
    - PHP's resolution text.
  - A blocked route adds debug's `workflow_definition_routing_blocked`
    warning and `diagnostic_status: needs_attention`.
  - Task queue describe samples up to ten runs with a due ready task. A
    blocked sample sets `repair.needs_attention`.
- **Tests** (`definition_routing.rs`).
  - A pinned run goes to the worker with its recorded definition. Workers
    with another definition, or without one, wait even when they validate
    entry arguments.
  - Once the recorded definition leaves, only an entry-validating worker
    takes the run.
  - An unpinned run goes to any worker.
  - Registrations and heartbeats record the protocol version with the
    reported metrics.
  - Describe, debug, task queue and repair report a blocked route, then the
    route an entry-validating replacement unblocks.
  - The worker listing test now expects the protocol key.
  - Full SQLite suite: 398 passed. Routing, worker, poll, child, cooperative,
    debug, task queue, repair and describe tests: PostgreSQL 134, MySQL 134.
