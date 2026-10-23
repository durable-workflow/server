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
- **Contract decision.** For published PHP only, each read in the
  metrics response may show the earliest state that matches it, provided
  the states never go backwards in PHP's read order: runs, then tasks,
  then each backlog count. The health response must be at or after the
  last metrics state, and its warnings follow the same rule. A PHP
  response that goes backwards, such as health still overdue after the
  backlog showed the redispatch, still fails. Native runs no repair loop,
  so native must still report the overdue task consistently. A native
  response that shows a redispatch must show it as one settled state.
- **How it was checked.** Five new contract tests cover:
  - the MySQL 8.0 shape (state 2), which passes for PHP and fails for
    native;
  - the PostgreSQL 17 shape (backlog after the redispatch), which passes;
  - two PHP responses that go backwards, which fail.

  Both CI shapes fail against the old contract. All 39 server-parity
  unit test files pass.
- **Not changed.** The fixture, its stages and the other stages' expected
  values. Native's behaviour is unchanged.
