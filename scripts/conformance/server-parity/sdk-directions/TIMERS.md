# Published SDK timer authors and cold replay

Server #325's next additive profile defines three authored timer cases, one
each in published PHP, Python and Rust, using the qualified parent/child
component tuple. These definitions establish no new execution pass.
SDK adapters, dedicated reader guards and runtime profile integration are
still pending.

Start the original run before worker registration. The normal SDK requests
5.25 seconds of durable sleep, rounded to six whole seconds. Stop the original
worker after its scheduling commit. A distinct cold author consumes the
original fired timer and completes the same run.

Require the original timer ID, sequence and exact deadline, complete unchanged
history prefixes, no early firing or rescheduling, original task/run/queue and
lease authority, actual completion receipts and exact int64/Unicode/null Avro
values. Embedded uses a canonical PHP author and explicitly marks SDK language
transport inapplicable.

Reuse the installed direction adapter build and retain separate full phase
wire sidecars. Record inspected database memory/swap limits separately from
historical qualification envelopes. Preserve all earlier manifests and cases.

Existing one/two-timer, zero-delay and cancellation fixtures remain checked.
This profile does not qualify CPU-load fairness, saturated timer/cancellation
latency, all timer semantics, matched performance or cutover.
