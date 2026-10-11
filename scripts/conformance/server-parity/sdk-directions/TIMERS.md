# Published SDK timer authors and cold replay

Server #325's additive profile runs three authored timer cases, one each in
published PHP, Python and Rust, using the qualified parent/child component
tuple. The normal SDK worker adapters, dedicated reader guards and isolated
profile are implemented. Runtime qualification on the candidate remains pending.

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

Run the shared job's existing SDK adapter preparation once, then use
`bash scripts/conformance/server-parity/sdk-direction-profile.sh BACKEND NATIVE_URL timer`
after the activity and child profiles. The owning job's always-run cleanup
invokes `sdk-direction-cleanup.sh BACKEND timer` before removing its database
and network. All six existing database configurations run this profile.

Reader guard models check the distinct processes, original task/run/queue,
unchanged scheduling/firing prefixes, real registration and fenced completion
receipts, rounded delay, stored result frame and exact typed values. These
tests validate the reader; actual worker execution requires retained runtime
records and phase wire sidecars from the shared qualification.
