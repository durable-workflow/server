# October 23, CLI 2.3.0 sweep and archive

- **What ran.** The published `dw` 2.3.0 phar, with its checksum verified
  against the release's `SHA256SUMS`, ran 60 commands against native and
  against published Server 2.11.1. Both servers ran on SQLite with the same
  seed data and used `--output=json`. The commands covered:
  - server and workflow reads, start, signal, query, repair, cancel and
    terminate;
  - archive and redrive;
  - standalone activities;
  - namespaces, schedules and search attributes;
  - task queues and build ids, and workers;
  - each `system:*` pass and status, storage, and worker polls.
- **Matched.** 53 commands had the same exit code on both servers:
  - `workflow:query` against a registered worker that never polls is
    refused by both with `query_worker_unavailable`, after the same
    45-second wait. The CLI's own timeout races that wait, so a run can
    show either outcome on either server.
  - `namespace:delete` succeeded on native. On the SQLite reference it hit
    a backend lock refusal.
- **Ported here: archive.** `POST /api/workflows/{id}/archive` and its
  run-targeted route now behave as `WorkflowStub::attemptArchive`:
  - An open run is refused durably, 409 `rejected_run_not_closed`.
  - A closed run records an archive command and `ArchiveRequested`. The
    first archive also sets the run's archive columns and records
    `WorkflowArchived`. A repeat answers `archive_not_needed`.
  - Laravel's rules (`reason` up to 1000 characters, `request_id` a
    string) refuse invalid fields with PHP's messages.

  A live comparison matched PHP's statuses, outcomes, history events and
  command payload bytes. Native's describe still reports an accepted
  command's `reason` as null, where PHP reads it from the payload. That is
  a describe-wide difference, tracked with the other response shapes.
- **Still missing for the CLI.**
  - `workflow:redrive` (Server 2.11's redrive of a failed run).
  - `task-queue:promote`, `task-queue:drain` and `task-queue:resume`, which
    publish PHP's worker-deployment lifecycle and its start routing.
