# October 29, descriptions with several open children

- **Before.** Native's child summary (`child_summary.rs`), which describes a
  parent waiting on children, gave up when:
  - a second child was open while another was still waiting;
  - a child's `parent_close_policy` was not `abandon`;
  - more than 100 children had been started over the run's life;
  - a timer wait was also open.

  The parent was then described with no wait (`wait_kind` null).
- **Contract** (published 2.11.5; `WorkflowController::show`, Workflow
  2.15.1 `RunWaitView`): a parent is described by its open waits. With
  several open children it waits for the first. An open timer is described
  ahead of open children, whichever was scheduled first. The parent-close
  policy does not change the description.
- **Fixture.** `child-descriptions`, raw worker HTTP on protocol 1.20 in a
  fresh namespace. Each case completes the parent's first workflow task with
  its commands and records the answer and the description: one child, two
  children, a child then a timer, a timer then a child, and a child under
  `parent_close_policy: terminate`.
- **Native.**
  - The summary keeps any number of open children, with no lifetime cap.
  - Any parent-close policy is allowed.
  - When a timer wait is already chosen and every child is still open, the
    timer stays the run's wait.
- **Not covered here.** A child with a cancellation policy other than
  `abandon` or with a retry or timeout policy, and a child beside an open
  activity, are still described without their wait, pending evidence.
- **Tests.** `child_descriptions.rs`. On the previous source, two children
  and a child beside a timer were described with a null wait.
