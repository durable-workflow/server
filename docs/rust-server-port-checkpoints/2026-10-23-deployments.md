# October 23, build-id rollouts and deployments

- **What is served.** The rollout surface of published Server 2.11.1:
  - `POST /api/task-queues/{queue}/build-ids/{promote,drain,resume}`;
  - `GET /api/deployments`, `GET /api/deployments/{namespace/queue@build}`;
  - `POST /api/deployments/{name}/{promote,drain,resume,rollback}`.

  They write the same `workflow_worker_build_id_rollouts` rows PHP writes.
- **What the rows change.**
  - **Start.** A promoted build pins new starts on its queue before the
    live fleet's cohort is considered (`WorkflowStartVersionPin`). The
    latest promotion that is active and not rolled back wins. A promoted
    unversioned cohort pins nothing, and takes the unversioned workers'
    contract and fingerprint.
  - **Workers.** Draining a build marks its registrations `draining`.
    Registration and heartbeat keep them draining. Their workflow,
    activity and query polls answer 409 `worker_draining`. Resume returns
    them to `active`.
  - **Admission.** A queue whose live workers all drain refuses workflow
    starts and standalone activity starts with 409 `task_queue_draining`.
    The response carries PHP's worker counts and draining build ids.
  - **Promotion checks.** Promotion runs PHP's lifecycle plan. Refusals
    come with PHP's ordered blockages:
    - unknown deployment;
    - a draining or terminal deployment;
    - no compatible or no freshly heartbeating workers.

    The fleet joins PHP's two views. Compatibility heartbeats last 30
    seconds and include draining workers; native reads them from the
    registration's heartbeat time. Registrations count only live,
    undrained workers.
  - **Reads.** The build-ids snapshot reports each rollout's drain intent,
    times and new-start selection. A rollout without workers gets its own
    row.
  - **Takeover.** Takeover no longer refuses a PHP database with rollout
    rows.
- **How it was checked.** A probe ran the same lifecycle against native and
  the published 2.11.1 image, with builds `v1` and `v2` on one queue. It
  covered:
  - each route's validation, refusals and successes;
  - draining polls and re-registration;
  - the draining start refusal;
  - promotion while draining;
  - rollback;
  - the deployments list.

  Every status, reason, blockage and timestamp format matched. The
  timestamp formats are `Z` on task-queue routes and `+00:00` on
  deployment routes. The remaining differences are the worker response
  fields native already lacks on every poll, such as `protocol_version`.

  The published `dw` 2.3.0 CLI now matches PHP's exit code on 58 of its 60
  sweep commands, including `task-queue:promote`, `drain` and `resume`. The
  other two are not gaps:
  - a lock refusal on the SQLite reference;
  - the query timing race in the [CLI sweep](2026-10-23-cli-sweep-and-archive.md).

  `build_ids_promote_drain_resume_and_roll_back_like_php` passes on SQLite,
  PostgreSQL and MySQL. Two build-ids tests that expected the rollout
  routes to be refused now expect PHP's answers.
