# Update outcome reads

A caller can read an accepted update's outcome later by its update id,
without admitting another update (Server #1131, the Server slice of
durable-workflow/.github#212). This is a native capability. Published PHP
Server 2.x has no such route.

## Route

`GET /api/workflows/{workflow_id}/updates/{update_id}`

Query fields, both optional and validated as on submission:

- `wait_for`: `accepted` (the default) answers at once. `completed` waits
  for the outcome.
- `wait_timeout_seconds`: how long a completion wait lasts, 1 to the
  submission maximum. Without it the server's update wait default applies.

The answer is the same receipt that submitting the update returns, with the
same status codes:

- `202` with `update_status: "accepted"`: admitted and not yet finished.
  `result_envelope` is null and `wait_timed_out` says whether a completion
  wait ran out. This is a pending update, never a null result.
- `200` with `update_status: "completed"`: the handler returned.
  `result_envelope` holds the result, including an encoded null.
- `422` with `update_status: "failed"`: the handler failed, with
  `failure_id` and `failure_message`.
- A rejected update answers with its original rejection status and fields.

A read takes the same roles as submitting an update (operator or admin) in the
request's namespace. It writes nothing: no command, history event or task.

`404` with `reason: "update_not_found"` means no retained update has that id
for that workflow in the namespace. That includes an update pruned with its
run. It does not prove the update was never admitted.

The update follows its workflow instance, so it can still be read after a
continue-as-new hands it to the successor run. `run_id` names the run that
owns it now.

## Advertisement

Native advertises `update_outcome_reads: true` in cluster info
`capabilities`. A server that does not advertise it has no read route. An SDK
must check the capability before offering a read, and must not resubmit the
update as a substitute.

`tests/execution/update_reads.rs` covers the pending receipt, a timed-out
completion wait, a waiting read that sees a completed null result, a later
plain read, that only one `UpdateAccepted` is recorded, unknown, other-workflow
and other-namespace reads, and invalid wait fields.
