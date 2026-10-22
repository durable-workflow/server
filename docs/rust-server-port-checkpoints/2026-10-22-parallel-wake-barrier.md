# October 22, parallel group wake barrier

- **Found by.** The fourth disposable VM run of the Sample App against
  native (`dw-rs325-20261022T050116`). In the timers scenario's concurrent
  upgrade phases, each member of a three-timer parallel group fired at a
  different time. Native created a workflow task on every fire. The freshly
  replaced Python 2.7.0 worker replayed the partly fired group and sent
  `start_timer` for all three members again. Native refused that with
  `409 parallel_sequence_mismatch`, then refused the worker's failure report
  with `development_workflow_task_failure_unqualified`. The refused tasks
  stayed leased until the worker stopped, and the class replacement phase's
  retirement then recovered them (`recovered_workflow_task_count` 1, not 0).
- **Cause.** Published PHP wakes the workflow for a closed member only when
  `ParallelChildGroup::shouldWakeParentOnTimerClosure` (or
  `...OnActivityClosure`) allows it. This is the same in Workflow 2.5.5 and
  2.9.0, and Server's `ServiceModeParallelChildBarrierContractTest` asserts
  it. In an all-of group a successful close wakes the workflow only once
  every member of every enclosing group has closed successfully. A failed or
  timed-out member wakes it at once. Later successful closures in that group
  do not wake it again. Native woke the workflow on every member close, and
  native's barrier contract document said so.
- **Source.** The shared member-close wake now applies PHP's rule for
  all-of groups. A timer fire or activity completion wakes the workflow only
  when every member sequence on its group path has a fired timer or a
  completed activity. An activity failure or timeout still wakes it at once.
  A suppressed wake returns `next_task_id: null`, as PHP does. Selections
  keep their winner rule.
- **Tests.** Three existing tests encoded the per-member wake. They now
  expect PHP's behavior:
  - an out-of-order activity group creates its one workflow task after the
    last member;
  - a sibling's completion while a member's retry is pending creates none;
  - after a member's timeout woke the workflow, the group's timer fires
    without another task.
  A new test staggers a two-timer group and checks that the first fire
  creates no workflow task and the last one does.
- **Contract.** `docs/contracts/parallel-all-groups.md` now states PHP's
  wake rule.
- **Verification.** The parallel and selection tests pass on SQLite,
  PostgreSQL and MySQL. The rest of the SQLite suite passes.
