# October 28, closing a run with over 1,000 open activities or timers

- **Before.** Native refused to close a run with more than 1,000 open
  activities or more than 1,000 open timers:
  - HTTP cancel and terminate answered
    `503 cancellation_inventory_exceeds_budget`;
  - a parent-close policy recorded `ParentClosePolicyFailed` with that
    error and left the child open;
  - completing a cooperative cancellation was refused the same way.

  The close itself loaded at most 1,001 of each. A run timeout already
  cancelled everything.
- **Contract** (Core 2.15.1 source; `WorkflowStub::attemptTerminalCommand`,
  `StructuralLimits`):
  - The close loads every open activity execution and pending timer and
    cancels each, recording `ActivityCancelled` and `TimerCancelled`.
  - The default pending limits are 2,000 activities and 2,000 timers per
    run, so a valid run can hold more than 1,000 of either.
- **Published reference.** Raw worker HTTP against published 2.11.5 on
  SQLite, with its maintenance loop stopped. Each workflow task opens 99
  timers and a signal wait; a signal brings the next task. The run is then
  terminated:

  | Open timers | Terminate |
  | --- | --- |
  | 198 | 200 in 4.0 s |
  | 396 | 200 in 10.3 s |
  | 792 | 200 in 28.9 s |
  | 1,089 | 500 after PHP's 30 s execution limit; the run stays `waiting` |

  Cancelling a run with 1,089 open activities also answers 500. With the
  maintenance loop running, both closes fail earlier with
  `database is locked`. No published reference can record a passing close
  at this size, so there is no parity fixture. Native follows the Workflow
  source.
- **Native.**
  - `close_workflow` no longer pre-counts.
  - `finish_close` and `finish_root_cancellation` load every open activity
    and timer.
  - `close_child_for_policy` no longer refuses.

  Other `cancellation_inventory_exceeds_budget` checks are unchanged: those
  on admitting a cooperative request (links, claims) and on a parent's
  cancellation delivery to its open tasks.
- **Tests** (`close_inventory.rs`). All three fail on the previous source:
  - HTTP terminate of a run with 1,089 open activities;
  - a parent's `terminate` policy closing a child with 1,089 open timers;
  - a cooperative cancellation delivered at a signal wait and completed
    over 1,089 open timers.
