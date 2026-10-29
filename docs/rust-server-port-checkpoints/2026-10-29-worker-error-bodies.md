# October 29, worker-plane error bodies

- **Before.** Sending the same malformed worker requests to published
  2.11.5 and native showed three differences:
  - a workflow or activity poll that failed its request rules was answered
    with native's own reasons (`422 invalid_request`, `invalid_poll_timeout`
    and the like), not Laravel's `validation_failed`;
  - failing or heartbeating an activity task the namespace does not hold was
    answered with a bare `{message, reason}` outside the worker envelope;
  - completing a workflow task the namespace does not hold with a
    `complete_workflow` command without a result was answered
    `422 unsupported_payload_codec`, where PHP answers `404 task_not_found`.

  Validation failures on other worker routes, and unknown routes and wrong
  methods in the default namespace, already matched.
- **Contract** (published 2.11.5; `bootstrap/app.php` exception rendering
  with `WorkerProtocol::json`, `WorkerController::pollWorkflowTasks`,
  `ActivityTaskController::poll`, `guardAttemptOwnership`):
  - a worker-plane validation failure answers `422 validation_failed` with
    Laravel's messages in `errors` and `validation_errors`, plus
    `protocol_version` and `server_capabilities`;
  - the workflow poll's rules are `worker_id` and `task_queue` (required
    strings), `build_id`, `poll_request_id` (at most 255),
    `timeout_seconds` (0 to 60), `history_page_size` (1 to 1000),
    `accept_history_encoding` (at most 64) and `task_kinds` (1 or 2 of
    `workflow` and `update_validation`, distinct). The activity poll takes
    the first five;
  - completing, failing or heartbeating an activity task the namespace does
    not hold answers `404` with `task_id`, `activity_attempt_id`,
    `error: "Activity task not found."` and `reason: task_not_found`, in the
    envelope;
  - completing a workflow task the namespace does not hold answers `404`
    with `task_id`, `workflow_task_attempt` and "Workflow task not found."
    The ownership guard runs before the command normalizer reads payloads.
- **Fixture.** `worker-error-bodies`, raw worker HTTP on protocol 1.20 in a
  fresh namespace: ten requests. The probe records each status and body,
  with `server_capabilities` reduced to whether it is present.
- **Native.** `http.rs` validates both polls with the published rules before
  reading them (native's task-kinds reader still checks distinct values),
  and answers an unknown activity task on the fail and heartbeat routes as
  the complete route already did. In a named namespace, a request PHP has
  no route for used to be refused as an unqualified named-namespace
  operation (`422 development_namespace_operation_unqualified`); it now gets
  PHP's 404 or 405, as in the default namespace. Only operations PHP serves
  are still refused that way. A workflow task completion or checkpoint now
  looks the task up before checking or fetching any command payload.
- **Tests.** `worker_error_bodies.rs` fails on the previous source and
  passes now.
