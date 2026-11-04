# Idempotent condition-wait opens

Native Server advertises `condition_wait_idempotent_reopen: true` in cluster
capabilities and worker `server_capabilities` when it recognizes repeated
`open_condition_wait` commands by occurrence ID.

If a command carries a nonempty `condition_wait_occurrence_id` matching an
unresolved condition wait in the same run, Server keeps the existing wait. It
records no second `ConditionWaitOpened`, consumes no new authored sequence and
schedules no second timeout. The original wait identity, key, fingerprint and
deadline stay intact. Other commands in the completion still apply normally.

An occurrence is unresolved while its wait is open and its timeout has not
fired. After satisfaction, timeout or cancellation, another open with the same
occurrence ID creates a new physical wait. A false-predicate wake therefore
keeps the existing reopen behavior. SDKs retain the logical occurrence ID and
send the remaining timeout when they reopen after such a wake.

Occurrence IDs belong to one logical wait call. Use a deterministic per-run
ordinal in workflow-code order, reuse it on replay and false-predicate wakes,
and allocate a different ID for a separate wait call. The ID is independent of
the condition key, predicate fingerprint, worker process and task attempt.

Absent, null and empty occurrence IDs preserve the existing per-open behavior.
Distinct occurrence IDs can share a key or fingerprint without being combined.
Signal waits are unaffected. Published PHP Server does not advertise this
capability and keeps its per-open behavior.

This capability does not settle unresolved waits' timers when a run closes;
that is the second change tracked in
[Server #1015](https://github.com/durable-workflow/server/issues/1015).
