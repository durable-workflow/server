# October 23, standalone activities

- **Source.** Server 2.11.1 `ActivityController` (`POST /api/activities`,
  `GET /api/activities`, `GET /api/activities/{id}`), Workflow 2.9
  `StandaloneActivityStartService`, and the host-run paths in
  `ActivityOutcomeRecorder::closeStandaloneHostRun` and
  `ActivityTimeoutEnforcer::closeStandaloneHostRunAfterTimeout`.
- **Published behavior.** Observed on a fresh 2.11.1 SQLite server:
  - **Start.** The activity runs inside a host run of type
    `dw.standalone_activity`. The run starts `running` and records
    `StartAccepted`, `WorkflowStarted` (with `business_key`) and
    `ActivityScheduled`. It never gets a workflow task, only a ready
    activity task. The retry policy is always snapshotted, with one
    attempt by default. Without `input` the activity has no arguments, and
    its poll omits them. The answer is 201 with the activity, run and
    deadline fields.
  - **Identifiers.** An omitted `activity_id` is generated. A running one
    is refused, 422 on `activity_id`. A closed one starts the next run of
    the same instance. A workflow's id is refused, also 422 on
    `activity_id`. An id reserved in another namespace answers 409
    `activity_id_reserved_in_namespace`.
  - **Outcomes.** A result completes the host run, with `WorkflowCompleted`
    and the result as output. A terminal failure fails it with
    `WorkflowFailed`, carrying the activity's failure fields. A timeout
    fails it with `closed_reason` `timed_out` (`failed` for a lost lease)
    and PHP's full exception shape. The worker is told `next_task_id` null.
    Cancelling the host run cancels the activity.
  - **Reads.** `GET /api/activities/{id}` describes the current run of a
    host, with its attempts from the run's activity view. Any other id
    answers 404 `activity_not_found`. The list is the run list for
    `dw.standalone_activity`, newest first, filtered by status bucket and
    paged by an offset token.
- **Evidence.** One script drove both servers through validation
  failures, a completion, a failure, a schedule-to-start timeout, reuse of
  an id, a generated id, a workflow's id, listing with filters and pages,
  and a cancel. After masking ids and times, native and PHP gave the same
  statuses, messages, bodies and host-run histories. What still differs is
  native-wide, not specific to these routes: activity snapshot details in
  history, and the extra fields in native's activity poll answer.
- **Also fixed.** The run list now reports a run's own business key, then
  its instance's. A host instance has none, so PHP's list shows the run's.
- **Tests.** `standalone_activities` covers the lifecycle, failure and
  timeout, refusals and listing on SQLite, PostgreSQL and MySQL.
- **Not ported.** PHP's task-queue drain refusal (`task_queue_draining`),
  which native's workflow start does not apply either, and its durable
  quotas for host runs, which native enforces nowhere.
