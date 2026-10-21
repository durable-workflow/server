# October 21, repair status

- **Found by.** The published dw 2.3.0 CLI sweep. `system:repair-status`
  (`GET /api/system/repair`) got native's 501.
- **Source.** Native serves the admin-only `GET /api/system/repair` in the
  default or a named namespace. Like published PHP 2.5.14, the response has
  two parts: `policy` and `candidates`.
  - **Candidates.** This is `TaskRepairCandidates::snapshot`, scanned across
    every namespace, as in PHP:
    - **Ready tasks** that are due and whose dispatch failed, whose claim
      failed, or whose dispatch is overdue past the 3-second redispatch
      cutoff, each with PHP's backoff rules.
    - **Leased tasks** whose lease expired.
    - **Open runs without a next task** that need repair, or that wait on
      children that have all closed.
    - **Grouping and selection.** Candidates are grouped by connection,
      queue and compatibility. Null, empty and public-default values share a
      scope, and each scope's selection uses its representative's exact
      values, as PHP's `applyScope` does. Selection takes up to 25 per scope
      and goes round-robin, oldest scope first, up to 25 in all.
    - **Order.** Scopes are sorted busiest first, then oldest, then by key.
    - **Time comparisons** bind "now" in whole seconds, as Laravel does.
  - **Policy.** `policy` is native's own `repair_policy`, the same one
    operator metrics reports: claim-time lease recovery, with no repair loop,
    loop throttle or failure backoff. PHP reports its maintenance loop's
    policy instead.
  - **Operator metrics.** These keep their empty `repair` section, which is
    what PHP returns when the request names a namespace.
- **Still open.**
  - `POST /api/system/repair/pass`.
  - Repairing a run whose task is ready (`workflow:repair` gets
    `development_workflow_task_repair_unqualified`).
- **Verification.**
  - **Integration test.** `repair_status` passes on SQLite, PostgreSQL and
    MySQL. It covers two overdue ready tasks in one queue, an expired lease
    in another, and a run in a third queue that lost its task. Unit tests
    cover the round-robin selection and the merging of default scopes.
  - **Harness, empty.** On the seeded data, both servers reported no
    candidates.
  - **Harness, candidates.** With three runs started on two queues and no
    worker, the candidates matched published PHP 2.5.14 field for field,
    apart from times. dw 2.3.0's `system:repair-status` succeeds against
    both servers.
