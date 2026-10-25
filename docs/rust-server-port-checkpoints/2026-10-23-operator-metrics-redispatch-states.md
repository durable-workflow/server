# October 23, operator metrics after PHP redispatches an overdue task

- **Found by.** On PR #673's parity run 39995620421, the Shared Server
  fixtures failed on PostgreSQL 17 and MySQL 8.0. The diff came before
  any PHP pass line, so published PHP 2.11.1 failed the
  `operator-metrics` contract, not native. The failing stage was
  `waiting_after_redispatch`.
- **The race in published PHP.** PHP's repair pass redispatches the
  overdue ready task in two steps:
  - `TaskWatchdog::recoverExistingTask` raises the task's
    `repair_count` and projects the run summary, in one transaction. The
    run is marked `repair_needed`.
  - `TaskDispatcher::markDispatched` then stamps the dispatch, and
    `refreshRunSummary` clears `repair_needed` in a separate step.

  `OperatorMetrics::snapshot` reads runs, then tasks, then activities,
  then the backlog, with no shared snapshot. `HealthCheck` takes its own
  snapshot after the metrics request. So one response can mix states.
- **The four PHP states.** In order, the overdue task is:
  0. overdue and unrepaired;
  1. overdue, repaired, run marked `repair_needed`;
  2. dispatched, run still marked `repair_needed`;
  3. dispatched, run summary refreshed.

  The old contract accepted states 0, 1 and 3 read consistently. It
  refused state 2 (the MySQL 8.0 shape) and a backlog read after the
  redispatch while the tasks were read before it (the PostgreSQL 17
  shape).
- **First model, refused in review.** The first correction (head
  `3fa5cd5288`) let each section of the response show one state, with
  sections never going backwards. Security's review (#708 comment
  6373392835) showed this was not enough. Published Workflow `aca7928484`
  reads each value with its own query, even inside one section. So a
  forward-only change can split the `tasks` section: `dispatch_overdue`
  at state 0, `max_repair_count` at state 1, and `unhealthy` at state 2.
  The timestamps are read before the counts, so a timestamp can also
  outlive its count.
- **Contract decision.** For published PHP only, the
  `waiting_after_redispatch` stage follows PHP's actual query order. The
  reads that depend on the repair state are, in order:
  - runs: `oldest_repair_needed_at`, then `repair_needed`;
  - tasks: `oldest_dispatch_overdue_since` (with `oldest_unhealthy_at`,
    which PHP builds from the same timestamps), then `dispatch_overdue`,
    then `max_repair_count`, then `unhealthy`, counted again;
  - backlog: `unhealthy_tasks`, then `repair_needed_runs`.

  The response passes when some sequence of states that never goes
  backwards in that order produces exactly its counts and timestamps. The
  health request reads its own snapshot afterwards, starting at or after
  the response's last read. Each health warning follows the field it
  reads:
  - `task_transport` follows `tasks.unhealthy`;
  - `routing_health` follows `tasks.dispatch_overdue`;
  - `durable_resume_paths` follows `backlog.repair_needed_runs`.

  Native runs no repair loop, so native must still report the overdue
  task consistently, with each timestamp present exactly when its count
  is. A native response that shows a redispatch must show it as one
  settled state.
- **How it was checked.**
  - Security's counterexample passes for PHP and fails for native. So
    does the timestamp kept from before a redispatch.
  - New tests refuse each backward step: a recount that is overdue after
    the dispatch count was not; a backlog that is overdue after the
    recount was not; an overdue count after its timestamp showed the
    redispatch; a run timestamp from a repair the later reads have not
    reached; and health whose recount is overdue after its dispatch count
    was not.
  - An exhaustive test covers all 256 combinations of the eight values
    that depend on the state. The 37 combinations that some forward-only
    order produces pass; the other 219 fail.
  - Two earlier tests assumed one read per section. They now accept
    health whose backlog was read after the run was projected again, and
    whose dispatch count was read before the redispatch. A native
    response still fails both.
  - The original CI shapes (MySQL 8.0 state 2, PostgreSQL 17 backlog
    after the redispatch) still pass. The server-parity unit tests pass:
    5,502 of 5,504, with one intended skip and one test that needs Docker
    inside its container.
- **Not changed.** The fixture, its stages and the other stages' expected
  values. Native's behaviour is unchanged.
