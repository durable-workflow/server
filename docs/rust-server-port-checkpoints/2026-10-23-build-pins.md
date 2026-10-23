# October 23, build pins at start and in polls

- **The gap.** Published Server 2.11.1 pins each new run to a worker build,
  and its pollers give a pinned run's tasks only to that build's workers.
  Native stamped no pin and gave any task to any worker. A run started
  under build `v1` could replay on a `v2` worker. A PHP database whose runs
  were already pinned would have lost that routing after takeover.
- **What native does now.**
  - **Start.** A start resolves the cohort as
    `WorkflowStartVersionPin::resolveForStart` does, apart from promoted
    rollouts, which native does not keep yet:
    - the one build among the type's live workers;
    - else the one build on the queue;
    - else no pin.

    Only unversioned workers is also no pin. The run's `compatibility` is
    that build. `WorkflowStarted` records it as `compatibility` when set.
    The declared contract and the definition fingerprint come only from
    workers in that cohort.
  - **Tasks.** Every task carries its run's pin.
  - **Polls.** A task pinned to a build goes only to workers registered
    with that build id. The build id is trimmed; a blank one is
    unversioned. An unpinned task goes to any worker. The workflow task
    response includes `compatibility`, as PHP's does.
  - **Children.** A child keeps its parent's pin. A child of an unpinned
    parent is unpinned.
  - **Redrive.** A redrive checks the definition fingerprint against the
    source run's own cohort, its build or the unversioned workers.
- **Contract decision: unpinned activities.** On 2.11.1 an unpinned
  activity task is never offered to a versioned worker. With only
  versioned workers on the queue it waits forever. Native offers it to any
  worker, as both PHP and native do for unpinned workflow tasks. This is
  [Workflow #908](https://github.com/durable-workflow/workflow/issues/908).
  If the package decides differently there, native follows that decision.
- **How it was checked.** Probes ran against native and the published
  2.11.1 image on SQLite, comparing:
  - three workers (`v1`, `v2` and unversioned);
  - starts with one cohort, two cohorts and a type served by one build;
  - workflow and activity polls from each worker;
  - a child of an unpinned parent and of a pinned parent.

  Pins, describe output, `WorkflowStarted` and which worker received each
  task matched, apart from the unpinned activity above.

  Two integration tests pass on SQLite, PostgreSQL and MySQL:
  `runs_are_pinned_to_their_start_cohort_s_build_like_php` and
  `children_keep_their_parent_s_build_pin`. A first child test followed
  my reading of the package source, which gives an unpinned parent's child
  the starting worker's build. The live comparison showed PHP leaves it
  unpinned, and the test now follows PHP.
- **Still missing.** Build-id rollouts:
  - promote, drain, resume and rollback on the task-queue and
    `/api/deployments` routes;
  - worker drain status;
  - the promoted cohort at start.

  Native refuses those routes with `task_queue_build_id_rollout_unqualified`.
  Takeover refuses a database with rollout rows.
