# Optional worker poll wake

Published PHP already supports the worker registration capability
`cross_kind_poll_wake`. The current OpenAPI documents its existing
`task=null, poll_status=task_queue_changed` response. This is a capability-only
opt-in. It adds no worker-protocol floor and changes no frozen schema or actor.
The agreed boundary and actual published/native observations are recorded in
[Server #325](https://github.com/durable-workflow/server/issues/325#issuecomment-6178479885)
and the [PHP contract acknowledgment](https://github.com/durable-workflow/server/issues/325#issuecomment-6178506403).

The response asks the worker to sweep its task kinds again. It supplies no
task, creates no lease, accepts no command and grants no execution authority.
Normal authentication, registration, namespace, queue, routing and claim fences
still apply to each subsequent request. A wake does not guarantee that a task
remains available when that worker polls again. It does not renew a poll,
workflow, activity, query or cleanup deadline.

The hint is scoped to the registered namespace and task queue. An unrelated
namespace, queue or global notification grants no authority to emit it.
Workers that do not opt in retain their existing behavior, including other
already-defined empty or blocked statuses.

The first real worker controls park an ordinary installed mixed worker in an
activity poll or a query poll, then admit one normal one-activity workflow while
that request remains unanswered. The PHP reference interrupts the held request
with the explicit hint. Its worker follows with a workflow sweep and completes
the original. The unchanged native source instead consumes the remaining
other-kind waits. Both still complete exact outputs and ordered histories.
These observations identify the controlled parity gap. They are not a matched
latency comparison and do not explain every prior performance tail.

The reusable observer uses the unchanged capacity definitions and normal
installed SDK selected by `benchmarks/server-port/v2/matched.json`. Set
`DW_PARITY_SDK_AUTOLOAD` to that installation's autoloader and
`DW_PARITY_TOKEN` to the isolated target's test token. Give each backend a
separate fresh database and owned output directory. Then run in the repository
runtime:

```sh
profile=benchmarks/server-port/v2/matched.json
php scripts/conformance/server-parity/cross-kind-poll-observe.php \
  "$runtime_url" "$unique_prefix" "$profile" "$output_directory" \
  > "$output_directory/observations.ndjson"
node scripts/conformance/server-parity/cross-kind-poll-check.mjs \
  "$profile" "$output_directory/observations.ndjson"
```

Observer exit 0 proves completed observations, not wake acceptance. The separate
reader requires the actual null-task hint, same worker/queue/namespace, a
zero-timeout workflow sweep, exact output/history and the original 35-second
budget. Constructed reader controls refuse ordinary timeout completion and
other malformed observations. The reusable observer and native correction
still need fresh real qualification. They are not selected in default fixtures.

Native qualification must cover those two cases, opt-in refusal boundaries,
namespace/queue isolation, ordinary same-kind claims and all database families.
Other task-kind pairs and registration replacement remain explicit follow-up
gates. Preserve raw statuses, request/response ordering, durable identities and
original deadlines. Completion alone does not prove the wake behavior. A reader
must verify the actual null-task hint and the subsequent workflow poll.

This is a service-mode worker HTTP contract. Embedded execution has no such
worker HTTP wait and is inapplicable to this specific observation. It retains
its independent workflow/activity/replay qualification.
