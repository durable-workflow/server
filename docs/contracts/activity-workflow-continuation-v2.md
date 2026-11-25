# Activity workflow continuation v2

This is the proposed native contract for
[Server #1181](https://github.com/durable-workflow/server/issues/1181). It is a
design for review before Server or SDK work starts. No released Server
implements it yet. Source references are to `rust/src/runtime/` on `main`.

Version 1 (`activity_workflow_continuation` v1,
[#1078](https://github.com/durable-workflow/server/issues/1078)) stays served
unchanged. Version 2 is a separate capability version that a worker selects per
request. Continuations of either version stay default-off in every SDK until
the qualification in [Qualification](#qualification) passes.

## Why v2 exists

The [#1078 A/B](https://github.com/durable-workflow/server/issues/1078#issuecomment-6827690149)
measured v1 against the ordinary carried-poll path. With v1 on, every loaded
arm was 24% to 49% slower and COMMITs per workflow doubled.

Per activity step, the paths compare as follows.

| Path | Request 1 | Request 2 |
| --- | --- | --- |
| Off (carried poll) | Activity completion with a carried workflow poll. One write transaction appends `ActivityCompleted` and leases the resumed workflow task. | Workflow task completion with a carried activity poll. One write transaction appends the command events and leases the next activity. |
| v1 | `POST …/continuation/history`. A read transaction runs five eligibility queries and reads the full history page. | `POST …/continuation/complete`. A receipt read probe, then a write transaction that reads the full history page again in `continuation_prefix_holds`, a savepoint, the nested workflow completion and the receipt write. |
| v2 | `POST …/continuation/complete` with `version: 2`. One write transaction proves the prefix in O(1), appends `ActivityCompleted`, completes the resumed workflow task with the worker's commands, leases the next activity and returns the new history events. | None. |

v2 removes a request and a write transaction per step because the worker
speculates from its own sticky cache. It never asks the Server for history.
Every answer that hands the worker an activity returns the events the Server
appended, so the cache stays exact for the next step.

## Terms

- **Cache.** The worker's sticky cache entry for one run, keyed by
  `workflow_id`, `run_id` and `build_id` (sdk-php `StickyWorkflowCache`). It
  holds the run's complete durable history as delivered by the Server.
- **Boundary.** The last history sequence in the cache.
- **`through`.** The boundary a continuation request was speculated from. The
  Server records the activity result at `through + 1`.
- **Tail.** The history events with sequence greater than the worker's
  boundary, read by the Server at the end of its write transaction.

## Negotiation

### Server capability

Registration and carried poll answers carry `server_capabilities`. The
existing `activity_workflow_continuation` object keeps every v1 field and its
`version: 1` value, because released sdk-php versions accept the descriptor
only when `version` is exactly `1`. v2 adds two members.

```json
{
  "activity_workflow_continuation": {
    "version": 1,
    "history_endpoint": "/worker/activity-tasks/{taskId}/continuation/history",
    "complete_endpoint": "/worker/activity-tasks/{taskId}/continuation/complete",
    "supported_commands": ["schedule_activity", "start_timer", "complete_workflow", "record_version_marker"],
    "max_history_events": 256,
    "max_commands": 16,
    "supported_versions": [1, 2],
    "v2": {
      "version": 2,
      "complete_endpoint": "/worker/activity-tasks/{taskId}/continuation/complete",
      "supported_commands": ["schedule_activity", "start_timer", "complete_workflow", "record_version_marker"],
      "max_commands": 16,
      "requires_worker_capabilities": ["sticky_execution", "sticky_history_suffix"],
      "tail_max_events": 64,
      "tail_max_bytes": 262144
    }
  }
}
```

A worker uses v2 only when all of the following hold.

- `supported_versions` contains `2` and `v2.version` is exactly the integer `2`.
- `v2.complete_endpoint` matches exactly.
- The worker's registration lists `sticky_execution` and `sticky_history_suffix`,
  and the manifest supports `sticky_execution` (the checks in
  `sticky_execution::registration_supports` and `takes_suffixes`).
- The SDK's continuation option is enabled. It defaults to off.

A malformed `v2` member disables v2 for that worker. It does not disable v1.
A Server without the `v2` member refuses a version-2 continuation with `422
unsupported_continuation_version` before any write (`continuation::parse`), so
a worker that sends v2 to an older Server loses nothing. No worker protocol
version change is needed. The capability and the request member carry the
version.

### Worker opt-in per request

v2 has two request shapes. Both are opt-in per request.

1. A workflow task completion that asks for a tail
   ([Tail on workflow task completion](#tail-on-workflow-task-completion)).
   This seeds the cache for the first activity after a workflow task.
2. An activity completion with a v2 continuation
   ([Continuation request](#continuation-request)). An accepted answer returns
   the tail for the following step.

The `/continuation/history` endpoint is not part of v2.

## Tail on workflow task completion

`POST /api/worker/workflow-tasks/{taskId}/complete` gains one optional
top-level member.

```json
{
  "lease_owner": "worker-a",
  "workflow_task_attempt": 1,
  "commands": [{"type": "schedule_activity", "...": "..."}],
  "sticky_cache": {
    "worker_id": "worker-a",
    "workflow_id": "order-17",
    "run_id": "01JD…",
    "build_id": "build-9",
    "ttl_seconds": 300,
    "history_last_sequence": 4
  },
  "activity_poll": {"worker_id": "worker-a", "task_queue": "default", "poll_request_id": "ap-81"},
  "continuation_tail": {"version": 2}
}
```

- `continuation_tail` is valid only with a `sticky_cache` claim whose
  `history_last_sequence` is set. Otherwise the request is refused with `422
  invalid_continuation_tail` before any write.
- The Server reads the tail after every write of the completion, including the
  carried activity claim and the worker-terminal attribution update in
  `attribute_worker_terminal`, and before `record_sticky_cache`.
- With `continuation_tail`, the carried `activity_poll` claims only an activity
  task created by this completion, in `created_task_ids` order. It uses the
  existing `claim_only` target, as v1 already does for the resumed workflow
  task. When no created activity task is claimable, the carrier answers
  `{"leased": false}` and does not claim from the queue. Without
  `continuation_tail`, carried claims are unchanged.

Older Servers ignore unknown top-level completion fields. A worker treats a
missing `continuation_tail` answer exactly like an omitted tail.

## Continuation request

`POST /api/worker/activity-tasks/{taskId}/continuation/complete`, with the same
authentication, headers and request body cap
(`limits.max_workflow_task_completion_bytes`) as v1.

```json
{
  "activity_attempt_id": "01JE…",
  "lease_owner": "worker-a",
  "result": {"codec": "avro", "blob": "…"},
  "workflow_poll": {"worker_id": "worker-a", "task_queue": "default", "poll_request_id": "wp-82"},
  "activity_poll": {"worker_id": "worker-a", "task_queue": "default", "poll_request_id": "ap-82"},
  "continuation": {
    "version": 2,
    "history_through_sequence": 6,
    "history_last_event_id": "01JE…",
    "sticky_cache": {
      "worker_id": "worker-a",
      "workflow_id": "order-17",
      "run_id": "01JD…",
      "build_id": "build-9",
      "ttl_seconds": 300,
      "history_last_sequence": 6
    },
    "commands": [{"type": "schedule_activity", "...": "..."}]
  }
}
```

Fields:

- `activity_attempt_id`, `lease_owner` and `result` are the ordinary activity
  completion fields.
- `workflow_poll` is required, as in v1. It supplies poll admission and the
  registration fence for the resumed workflow task. On a decline it is the
  fallback: the Server applies it exactly as on the off path.
- `activity_poll` is optional and new in v2. On acceptance, the nested
  workflow completion carries it with the claim targeting described in
  [Tail on workflow task completion](#tail-on-workflow-task-completion). One
  answer never leases both kinds, as today.
- `continuation.version` is the integer `2`.
- `continuation.history_through_sequence` is the cache boundary, an integer of
  at least 1. v2 has no history length limit.
- `continuation.history_last_event_id` is the cached event ID at `through`,
  1 to 64 characters.
- `continuation.sticky_cache` is the cache identity and the nested
  completion's sticky claim. It uses the ordinary `sticky_cache` rules in
  `completion_rules.rs`. `history_last_sequence` must equal `through`.
- `continuation.commands` holds 1 to 16 commands of the v1 command set, with
  no `cancellation_scope_id` or `parallel_group_path`. Widening the set is out
  of scope.

Unknown `continuation` members, a non-integer version or a malformed field are
refused before any write, as in v1.

### Worker preconditions

The worker sends a v2 request only when every check below passes. Otherwise it
sends the ordinary activity completion with its carried workflow poll, which
costs nothing extra.

1. The cache entry for the activity's run exists, starts with
   `WorkflowStarted`, is contiguous, and contains the `ActivityStarted` event
   for this `activity_attempt_id`.
2. The worker replays the cache plus one synthetic `ActivityCompleted` at
   `through + 1`. It carries the result, `activity_attempt_id` and
   `attempt_number`, with no ID and no `recorded_at`, as sdk-php's v1
   `speculateActivityContinuation` builds it today. The replay may depend only
   on fields the worker supplies. The Server's own fields arrive in the tail.
3. The replay produced 1 to 16 supported commands, read no unavailable time,
   and produced no terminal failure, message stream, cancellation delivery,
   prepared local activity or cancellation scope work. These are the v1
   speculation checks.
4. The worker reserved a workflow poll slot for `workflow_poll`.

## Server transaction

The whole request runs in one write transaction under the transition lock
(`Store::begin()`, see [transition locking](../rust-server-transition-locking.md)).
In order:

1. **Receipt check.** Read the activity task row. If it holds an
   `activity_workflow_continuation_v2` receipt, answer from it
   ([Exact retry](#exact-retry-and-idempotency)). This replaces v1's separate
   read probe in `replay_activity_continuation`.
2. **Ordinary activity fencing.** Attempt, lease, run and activity checks as
   in `complete_activity_in`. A failure here is the ordinary refusal, for
   example `409 stale_attempt`.
3. **Prefix proof** ([O(1) prefix proof](#o1-prefix-proof)) and the other
   eligibility checks ([Declines](#declines-and-fallback)).
4. **Savepoint** (`continuation_savepoint`). Append `ActivityCompleted` at
   `through + 1`, resolve the selection and create the resumed workflow task.
   The carried workflow poll claims only that task (`claim_only`).
5. **Nested completion.** `complete_or_checkpoint_in` applies the worker's
   commands, the nested `sticky_cache` claim and the carried `activity_poll`.
6. **Tail.** Read the tail after `through` and apply the budgets.
7. **Receipt write.** Store the bounded decision and the tail range in
   `dw_task_completions.receipt`. Then commit.

When step 3 finds a decline, or step 4 shows that the carried workflow poll
did not lease the resumed task, the transaction rolls back to the savepoint
and applies the ordinary completion with the carried workflow poll, as v1
does. Every command, budget, decoder or storage fault in steps 4 to 7 rolls
back the whole transaction, as in v1.

## O(1) prefix proof

Under the transition lock, before the `ActivityCompleted` append, the prefix
holds when all of the following are true.

1. `workflow_runs.last_history_sequence == through`. The run row is the one
   step 2 already read through `activity_run`. It is not read again.
2. `SELECT id FROM workflow_history_events WHERE workflow_run_id=$1 AND
   sequence=$2` with `through` returns exactly `history_last_event_id`. This is
   one point read on the `(workflow_run_id, sequence)` key.
3. The existing run, task, update and cancellation checks pass. See the
   reasons in [Declines](#declines-and-fallback).

The proof reads no history page. Its statement count does not depend on
history length.

### Why it is sound

The proof relies on the history invariant that every native writer keeps
today.

- A run's history is append-only. Every event the runtime records gets its
  sequence from `allocate_history_sequence` or `allocate_history_sequences`,
  which advance `workflow_runs.last_history_sequence` in the same transaction
  as the insert (`HISTORY_ADVANCE_SQL` and `HISTORY_BATCH_ADVANCE_SQL` in
  `backend.rs`). So no stored event has a sequence above
  `last_history_sequence`, and runs the runtime writes are gap-free.
- `workflow_import` sets an imported run's `last_history_sequence` to at
  least its largest imported sequence. An imported run can have gaps, but no
  event above its last sequence.
- Event IDs are unique, and a stored event never changes after its
  transaction commits. The one in-place change, the worker-terminal
  attribution, happens in the same transaction that records the terminal
  event, before the tail is read.
- The cache holds only events the Server delivered: poll history, verified
  suffixes and tails. `StickyWorkflowCache` already refuses a suffix that does
  not continue the cached boundary exactly.

So when the run's last sequence is `through` and the event at `through` is the
one the worker cached, no event follows `through`. Every earlier event is the
one the Server delivered into the cache, so the stored history is exactly the
cached history. The global transition lock means no other writer can append
between the proof and the `ActivityCompleted` append.

### Required guard against a bypassing writer

The O(1) proof cannot see a writer that inserts, deletes or rewrites history
without advancing `last_history_sequence`. v1's
`completion_independently_refuses_a_gapped_prefix_with_the_same_last_event`
caught that case only because v1 re-read the whole page. v2 replaces that read
with three controls. Each must fail if a writer bypasses the invariant.

1. **Writer inventory test.** A unit test scans non-test sources under
   `rust/src` for SQL that inserts into, updates or deletes from
   `workflow_history_events`. It compares each statement with an allowlist
   that names its owner and why it keeps the invariant. Today that is
   `Store::record` and `local_activities::HISTORY_INSERT` (both use the
   allocator), `workflow_import` and `waterline_import` (new imported runs),
   the worker-terminal attribution `UPDATE` in `store.rs`, and the
   `retention.rs` delete of closed runs. A new writer fails the test until it
   is reviewed and listed.
2. **Independent assertion in test builds.** Under `cfg(test)` and
   `debug_assertions`, the v2 proof also reads `SELECT COUNT(*),MAX(sequence)
   FROM workflow_history_events WHERE workflow_run_id=$1`. For a run without
   `import_source`, both must equal `last_history_sequence`. For an imported
   run, `MAX(sequence)` must not exceed it. Otherwise the request fails with
   `500 history_invariant_violated` and writes nothing. Release builds skip
   this read.
3. **Negative control.** A real-HTTP test deletes or inserts a history row
   behind the allocator, as the v1 gapped-prefix test does, then sends a v2
   continuation with a matching `through` and event ID. It must get `500
   history_invariant_violated` with no new events. Removing control 2 makes
   this test fail.

A statement-count test also measures the v2 path with `sql_counts::measure`
on a short and a long history. It asserts the same statements for both and no
`HISTORY_PAGE` read.

## Answer

### Accepted

HTTP 200. The ordinary activity completion fields, the decision, the tail and
the optional carried activity poll.

```json
{
  "task_id": "01JE…activity",
  "activity_attempt_id": "01JE…",
  "outcome": "completed",
  "recorded": true,
  "reason": null,
  "next_task_id": "01JE…workflow",
  "continuation": {
    "version": 2,
    "outcome": "accepted",
    "workflow_task_id": "01JE…workflow",
    "workflow_task_attempt": 1,
    "completion": {
      "task_id": "01JE…workflow",
      "run_id": "01JD…",
      "run_status": "waiting",
      "workflow_task_attempt": 1,
      "outcome": "completed",
      "recorded": true,
      "reason": null,
      "created_task_ids": ["01JE…next-activity"]
    }
  },
  "continuation_tail": {
    "version": 2,
    "after_sequence": 6,
    "last_sequence": 9,
    "history_events": [
      {"id": "01JE…", "sequence": 7, "event_type": "ActivityCompleted", "...": "..."},
      {"id": "01JE…", "sequence": 8, "event_type": "ActivityScheduled", "...": "..."},
      {"id": "01JE…", "sequence": 9, "event_type": "ActivityStarted", "...": "..."}
    ]
  },
  "activity_poll": {"leased": true, "answer": {"task": {"...": "..."}, "poll_status": "leased"}}
}
```

The native history model has no workflow-task events, so a typical step's tail
is `ActivityCompleted`, the command events (`ActivityScheduled`,
`TimerScheduled`, `VersionMarkerRecorded` or `WorkflowCompleted`) and the
`ActivityStarted` of the carried claim. The tail is whatever the transaction
left after `through`, so it also covers any event type the Server adds.

### Tail rules

These apply to both answers that carry a tail.

- `after_sequence` is the worker's boundary: `through` for a continuation, or
  `sticky_cache.history_last_sequence` for a workflow task completion.
- The Server reads `sequence > after_sequence ORDER BY sequence LIMIT
  tail_max_events + 1` in the write transaction after the last write. This
  reads only new rows.
- `last_sequence` is the run's `last_history_sequence` at that point.
  `history_events` covers exactly `after_sequence + 1` through
  `last_sequence`. A non-contiguous result is an invariant fault: `500
  history_invariant_violated` and a full rollback.
- Events use the same presentation as a workflow poll's `history_events` for
  this worker, including `with_signal_sequences`. They are plain JSON. A tail
  ignores `accept_history_encoding`.
- `tail_max_events` and `tail_max_bytes` come from the capability.
  `tail_max_bytes` is measured as the UTF-8 length of the JSON-encoded
  `history_events` array.
- Over either budget, the answer carries
  `{"version": 2, "after_sequence": 6, "omitted": "tail_event_budget_exceeded"}`
  or `"omitted": "tail_byte_budget_exceeded"` and no events. Exceeding a
  budget never rolls back or declines the completion.
- With a tail, the Server records `last_sequence` as the completed workflow
  task's `sticky_history_last_sequence`. The next sticky poll's suffix then
  starts after the tail. With an omitted tail, it records `after_sequence`, as
  today.

Worker handling:

- With a tail, the worker checks that it continues the cache exactly, with the
  same rule as `StickyWorkflowCache::continues`. It then appends the events.
  A tail that does not continue the cache evicts the entry.
- With an omitted tail, the worker keeps the cache at its old boundary. It
  does not speculate on the activity this answer leased. The next sticky poll
  delivers the suffix after that boundary, so the cache stays valid.
- If the run closed, the worker evicts the entry.

### Declined

HTTP 200. The ordinary activity completion, applied afresh after the savepoint
rollback, plus the decision and the carried workflow poll answer.

```json
{
  "task_id": "01JE…activity",
  "activity_attempt_id": "01JE…",
  "outcome": "completed",
  "recorded": true,
  "reason": null,
  "next_task_id": "01JE…workflow",
  "continuation": {"version": 2, "outcome": "declined", "reason": "history_changed"},
  "workflow_poll": {"leased": true, "answer": {"task": {"...": "..."}, "poll_status": "leased"}}
}
```

A declined answer has no `continuation_tail` and no `activity_poll`. A decline
is exactly the off path's first request, so it costs no extra round trip.

## Declines and fallback

The Server checks in this order and reports the first reason. Every reason is
a stable machine string stored in the receipt.

| Reason | Condition | Worker fallback |
| --- | --- | --- |
| `external_payload` | The result or a command uses external payload storage. | Process the carried workflow poll answer. Keep the cache. |
| `cache_identity_mismatch` | `sticky_cache` does not name the lease owner, the activity's run and workflow, and the registered build. | Process the carried workflow poll answer. Evict the cache entry. |
| `run_not_open` | The run is not `running` or `waiting`, or its execution or run deadline has passed. | Process the carried workflow poll answer. Evict the cache entry. |
| `standalone_activity` | The run is a standalone activity host. | Process the carried workflow poll answer. |
| `cancellation_requested` | The run has a cancellation request. | Process the carried workflow poll answer. Keep the cache. |
| `parallel_group` | The completed activity belongs to a parallel group. | Process the carried workflow poll answer. Keep the cache. |
| `pending_workflow_task` | The run already has a `ready` or `leased` workflow task, for example after a signal or a fired timer. | Process the carried workflow poll answer. Keep the cache. |
| `pending_update` | The run has an accepted update or a pending or leased update validation task. | Process the carried workflow poll answer. Keep the cache. |
| `history_changed` | `last_history_sequence` is not `through`, or the event at `through` has another ID. | Process the carried workflow poll answer. Keep the cache. Its suffix shows what changed. |
| `workflow_slot_unavailable` | The carried workflow poll was not admitted: unregistered, draining, a stale fence, or the worker holds a lease of the other kind. | Nothing is leased. Ordinary polling picks up the resumed task. |
| `no_workflow_task` | The carrier was admitted but leased nothing, for example because the result resumed no task. | Nothing is leased. Continue polling. |
| `dispatch_selected_other_task` | The carrier leased a task other than the resumed one. `claim_only` makes this a defensive case. | Process the carried workflow poll answer for the task it leased. |

v1 reported several of these conditions as `history_changed`. v2 reports
them separately so benchmark receipts show why a step declined.

For every decline, the activity result is recorded. The worker never resends
the activity completion after a decline.

### Refusals

A refusal is an HTTP error. Nothing is written.

| Status and reason | Cause | Worker action |
| --- | --- | --- |
| `422 invalid_continuation`, `unsupported_continuation_version`, `unsupported_continuation_command`, `continuation_requires_workflow_poll` | Malformed request, or a Server without v2. | Send the ordinary activity completion with the same carried workflow poll. Disable v2 for this Server if the version is unsupported. |
| `413 continuation_result_exceeds_budget`, `413 continuation_receipt_exceeds_budget` | Result or receipt over its budget. | Send the ordinary activity completion with the same carried workflow poll. |
| `409 completion_budget_exceeded`, `409 continuation_run_deadline_expired` and other nested completion refusals | The speculative commands cannot apply. | Send the ordinary activity completion with the same carried workflow poll. The run's own workflow task reports the limit. |
| `409 stale_attempt` | The attempt is no longer current. | Ordinary stale-attempt handling. Do not retry. |
| `409 conflicting_completion` | A different request already completed this attempt. | Ordinary duplicate handling. Do not retry. |
| `500 invalid_continuation_receipt`, `500 history_invariant_violated` | Stored state is corrupt. | Send the ordinary activity completion. Report the error. |
| 5xx other than the two above, or a transport failure | The outcome is unknown. | Resend the identical v2 request ([Exact retry](#exact-retry-and-idempotency)). |

Resending with the same `workflow_poll.poll_request_id` is safe after a
refusal, because the refused transaction rolled back the poll receipt too.

## Exact retry and idempotency

- The receipt is stored under `activity_workflow_continuation_v2` in
  `dw_task_completions.receipt`, beside the existing activity receipt fields,
  as `{"version": 2, "request_hash": …, "answer": …}`. `request_hash` is
  `continuation::request_hash` over the whole request body and the protocol
  header, as in v1.
- The stored answer holds the decision and IDs (as v1's `valid_saved_answer`
  validates them) plus the tail range: `{"after_sequence", "last_sequence"}`
  or `{"after_sequence", "omitted"}`. It never holds tail events. The wrapper
  stays within 16 KiB.
- The receipt check is the first read inside the write transaction (step 1).
  There is no separate read probe. A concurrent identical request waits for
  the transition lock and then finds the receipt.
- An identical request returns the stored answer with `"recorded": false` and
  `"reason": "already_completed"`. The Server re-reads the tail events by
  sequence range, which is safe because committed events never change. If
  retention has deleted them, the tail is answered as omitted with
  `"omitted": "tail_unavailable"`.
- A retry never claims, renews or creates a lease. An accepted retry replays
  the carried `activity_poll` through the existing read-only poll receipt
  replay, only while that lease is still live. A declined retry replays the
  carried `workflow_poll` the same way, as v1 does in
  `present_continuation_replay`.
- A different request for the same attempt gets `409 conflicting_completion`.
- A corrupt or unknown receipt version gets `500 invalid_continuation_receipt`.
- A retried workflow task completion with `continuation_tail` returns its
  ordinary receipt with `"omitted": "tail_unavailable"`. The worker then does
  not speculate on that activity.

If a worker sends an ordinary activity completion after a v2 request whose
outcome it does not know, the ordinary path answers `stale_attempt` as today.
Any activity lease the v2 request took then expires and is retried by the
Server. This is correct but slower, so SDKs resend the identical v2 request
instead.

## Reusing transition state (#1079)

[#1079](https://github.com/durable-workflow/server/issues/1079) is closed and
its goal moves here. The nested completion runs in the same transaction, under
the same lock and fence, as the proof. It reuses what that transaction already
holds instead of reading it again.

| State | Held by | v2 reuse |
| --- | --- | --- |
| History sequence | The proof (`last_history_sequence == through`) and every allocation in the transaction. | The transaction remembers its last allocated sequence. The nested meter charges the `ActivityCompleted` payload it built in step 4 instead of re-reading `sequence > through` (`continuation_after` in `complete_or_checkpoint_in`). |
| Run status and cancellation | The run row from `activity_run` and the proof. | The nested completion uses it, updated with this transaction's own writes, instead of `active_run`. |
| Registration | `fenced_claim_registration` during the carried workflow claim. | `guard_sticky_cache` and `require_target_build_registration` use the same row when the worker ID and queue match. |
| Owner freshness | The workflow task this transaction just claimed with that fence. | The nested lease check uses the claim's task ID, attempt, owner and expiry. |

The exact list is confirmed with `sql_counts` during implementation. Each
reuse needs these controls on SQLite, MySQL and PostgreSQL.

- Every normal and local-activity history write updates the remembered
  sequence. `rollback_continuation` rewinds it with the meter and the other
  savepoint state in `ContinuationSavepoint`.
- The bypass negative in
  [Required guard](#required-guard-against-a-bypassing-writer) also compares
  the remembered sequence with the independent read at commit.
- A test replaces the worker's registration after request admission and
  before the transaction. It must get `workflow_slot_unavailable`, not a
  reused registration.
- A fault injected after the nested completion (for example, an oversized
  receipt) rolls back every effect, and the ordinary completion still
  succeeds afterwards.
- v1 continuations and ordinary completions keep today's reads and answers.
  Reuse applies only on the v2 path.

## Qualification

### Real-HTTP controls

New tests in `rust/tests/execution/` (beside `continuations_v1.rs`) run on
SQLite and, with `DW_EXECUTION_MYSQL_URL` and `DW_EXECUTION_POSTGRES_URL`, on
MySQL and PostgreSQL.

- **Accept.** One request records the result and the commands, leases the next
  activity, and returns a contiguous tail whose last event is that activity's
  `ActivityStarted`. The next v2 request built from the tail is accepted.
- **Each decline.** One test per reason in the table. Each asserts the
  ordinary completion and the carried workflow poll answer, no command events,
  and the stored reason.
- **Stale cache.** `through` behind the Server, and the right `through` with
  another event ID, both decline `history_changed`.
- **Concurrent signal and update.** A signal or update that waits on the
  transition lock while a v2 request holds it lands after the commit. A signal
  committed first makes the request decline. This uses the existing
  `test_continuation_gate`.
- **Rollback.** A command, budget or storage fault after the savepoint leaves
  no result, events, tasks, leases or receipt.
- **Exact retry.** An identical retry returns the same decision and tail with
  `already_completed`. A changed body gets `conflicting_completion`. A retry
  after the activity lease expired does not claim again.
- **Budgets.** A result larger than `tail_max_bytes`, and a completion that
  appends more than `tail_max_events` events, both commit with an omitted
  tail. The next sticky poll returns the suffix after `through`.
- **Bypass guard and O(1).** The three controls and the statement-count test
  from the guard section.
- **v1 unchanged.** The existing `continuations_v1.rs` tests pass unmodified.

### Benchmark

Use the #1078 harness and shapes, with published Server images and a published
sdk-php that has the v2 consumer.

- 200 workflows started back to back, 6 workers, `bench.five_steps` (5
  sequential activities, each sleeping 20 ms and echoing 1 KiB).
- Each loaded shape with and without `tc netem delay 20ms` on worker egress.
- 30 workflows run one at a time with the same workers, with and without the
  delay.
- MySQL with stock durability and a fresh volume per run. 3 runs per arm in
  rotated order. Report the median and range.
- Report wall time, Server and MySQL CPU, DB statements per workflow, COMMITs
  per workflow, and receipt counts by outcome and reason.

The result passes when v2 on is faster than off in every loaded arm with
non-overlapping ranges, no slower at the sequential median, and has fewer
COMMITs per workflow than off. The design expects one write transaction per
step instead of two. That is a hypothesis for the benchmark to test, not a
claim.

Until the benchmark passes, continuations stay default-off in sdk-php. The
documentation keeps saying that enabling them costs throughput. The sdk-php
consumer lands in the same release window as the Server implementation.
Retiring v1 is a separate decision after v2 qualifies.

## Open questions

1. **Capability shape.** This proposal nests `v2` and `supported_versions` in
   the existing `activity_workflow_continuation` object and keeps `version: 1`
   for released SDKs. The alternative is a separate top-level capability key.
2. **Carried activity claim.** With a tail requested, the carried activity
   poll claims only an activity created by the same completion, so the tail
   includes its `ActivityStarted`. This gives runs worker affinity and may
   change fairness under load. The benchmark decides whether to keep it or
   fall back to an ordinary queue claim.
3. **Activities leased by ordinary polls.** They carry no tail, so the worker
   never speculates on them. Adding a tail to ordinary activity polls is left
   out of v2.
4. **Budget defaults.** 64 events and 256 KiB are starting values sized for up
   to 16 commands and small results. Should they be fixed or
   operator-configurable?
5. **Exact retries take the transition lock.** Folding the receipt probe into
   the write transaction removes a read transaction per step but makes a
   retry wait for the lock. Retries are rare, and the benchmark should confirm
   the cost is negligible.
