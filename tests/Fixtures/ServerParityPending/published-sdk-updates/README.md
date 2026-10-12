# Pending published SDK update directions

These nine fixtures define the next Server #325 slice. They are pending:
ordinary SDK worker/client adapters, request-arrival capture, the strict reader,
and six-database runtime qualification are still required. Existing profiles
and their frozen manifests remain unchanged.

Each PHP/Python/Rust update client targets each PHP/Python/Rust workflow author.
The component tuple is the existing published signal/query tuple; the separate
manifest labels this new qualification scope as pending. Current package
releases do not replace these frozen components.

The original author registers before observer start and claims only after the
matching successful start receipt. It opens a wait for a finishing signal,
then exits. Two updates carry distinct UTF-8/int64/null/boolean maps. For each
one, the observer starts an ordinary synchronous client with a stable request
ID, then a separate duplicate client with different arguments. A cold author
process applies that original update and reaches a committed checkpoint.
Afterward, another ordinary client repeats the same request ID. A final cold
author consumes the ordered finishing signal and returns both original values
exactly once, in order.

The pending clients overlap the author; phase order describes process starts,
not fabricated completion receipts. Separate bounded request-arrival evidence
must bind complete actual bodies to their submissions before author execution.
The existing response journal is written only when a response closes and cannot
alone prove pending submission. Arrival/forwarding is distinct from durable
acceptance; actual history, responses and worker acknowledgments establish
the accepted identity and outcome.

Every checkpoint requires the matching successful workflow-task completion
receipt, including task, run, owner and attempt. Retain complete public history,
full repeated worker history with database IDs and links, actual SDK result
shapes, raw request/response bytes, exact Avro values and callback counts.
Pending/completed duplicates must preserve the original command/update ID,
arguments, result and history, without another durable application or cursor
advance. Historical callback replay is recorded separately from new durable
application; it must not be forced to equal across SDK authoring models.

PHP/Rust clients return decoded update results; the frozen Python client keeps
its normal response object. The frozen Rust client waits for completion and
offers no accepted-wait option. Use its ordinary API unchanged, with real
overlapping client processes. Do not rewrite the request in the observer or
manufacture an acceptance receipt.

Embedded execution uses the canonical PHP update method and ordered finishing
signal. It preserves its explicit direct execution history and labels HTTP
worker transport inapplicable. Callback authoring positions remain validated
within each target, as the existing state-updates contract specifies.

Reuse the existing locked SDK adapter build and its resource caps. No Cargo
target or registry tree belongs in retained artifacts. Keep individual
fixture/probe deadlines bounded; source/model results do not establish runtime
parity, performance, migration or release readiness.

Validators, handler failures, concurrent queued updates, alternate wait stages,
timeouts, historical selectors, namespace/auth routing and recovery policies
remain separate gates.

[Reviewed next slice](https://github.com/durable-workflow/server/issues/325#issuecomment-6119078545).
[Arrival versus acceptance](https://github.com/durable-workflow/server/issues/325#issuecomment-6119119756).
