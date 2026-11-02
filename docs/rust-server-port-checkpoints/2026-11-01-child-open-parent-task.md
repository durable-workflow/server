# November 1, a child's resolution while its parent has an open workflow task

- **Before.** Without a cooperative cancellation request, native gave every
  child resolution its own resume task. A parent with a ready workflow task
  then had two, and a worker replayed it twice.
- **Contract** (published Server 2.11.7, Workflow 2.15.7).
  - `WorkflowExecutor::dispatchParentResumeTasks` and
    `DefaultWorkflowTaskBridge::dispatchParentResumeTasksForRun` record the
    resolution. When the parent has a ready or leased workflow task they
    create no task and leave that task's payload as it is.
  - Unlike an update's admission (#902), nothing is merged into the task.
    Its next claim reads the resolution from history.
  - The published Rust (4.10.1), Python (2.12.2) and PHP (3.6.1) SDKs branch
    on a task's `workflow_update_id` only. A child resume task, or a reused
    ready task, is an ordinary replay for all three.
  - `PendingMessageTask::signalCanAdvanceRun` delivers a signal only to an
    open signal or condition wait, so a signal creates no resume while the
    parent waits only on a child. Native matches it.
- **Leased task, published 2.11.7.** Recorded over raw worker HTTP on
  SQLite with the maintenance container stopped:
  1. the parent's first task starts a child on its own queue and schedules
     an activity;
  2. the activity completes, and the parent's resume task is leased;
  3. the child completes (`200`);
  4. the lease, whose history has no `ChildRunCompleted`, completes with a
     3600-second timer (`200 waiting`).

  Afterwards no workflow task is claimable for the parent, which is described
  as `Waiting for timer`. One `workflow:v2:repair-pass` changes nothing. The
  child's result waits for the timer, and a parent with no other pending
  resume would not wake. This is
  [workflow#962](https://github.com/durable-workflow/workflow/issues/962). Core
  2.15.10's `createPendingChildResumeTask` creates one child resume task when
  such a lease completes, but no published Server carries it yet.
- **Native.**
  - `child_parent_resume` reuses the parent's ready workflow task that
    carries no `workflow_update_id`, and marks the child wait as backed by it.
  - Beside only a leased task, or only an update task, the resolution keeps
    its own resume task, as activity and timer resolutions do on both
    servers. The parent therefore cannot lose the wakeup.
  - The cooperative cancellation path is unchanged.
- **Evidence.**
  - Fixture `child-open-parent-task`: one case with a ready parent task,
    recorded against published 2.11.7. It passes unchanged against 2.11.5,
    the version CI's SQLite comparison uses, and against native on SQLite.
    Exactly one parent task is claimable, with no update id, and its history
    carries `ChildRunCompleted`.
  - Execution tests `child_open_parent_task`:
    - `a_child_resolution_reuses_the_parents_ready_task_like_php`;
    - `a_child_resolution_during_a_lease_keeps_its_own_resume_task`.
