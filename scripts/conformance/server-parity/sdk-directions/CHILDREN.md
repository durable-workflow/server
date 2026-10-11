# Published SDK parent/child directions

This additive Server #325 profile defines nine PHP/Python/Rust parent and child
workflow directions with the same pinned published component tuple as the
qualified one-activity profile. [#408](https://github.com/durable-workflow/server/pull/408)
passes all six candidate and main configurations, PHP/source and boundaries.
Independent official SQLite and MySQL 8.0 rechecks each pass all 27 logical
child records. SQLite binds all 54 actual PHP/native wire sidecars; all 97 full
profile members match the official ZIP and pass compressed byte round-trip.
Main follow-through is recorded in [#408's completed receipt](https://github.com/durable-workflow/server/pull/408#issuecomment-6112398187).

Start the original parent before worker registration. A parent-only process
schedules one child on a separate queue and exits. A distinct child-only
process returns its exact int64, Unicode and null input. A cold parent-only
replacement replays that committed result and completes the original parent.
Retain full history prefixes and the original call, link, child instance and
child run, plus actual claims, completion receipts and separate raw sidecars.

HTTP child histories contain WorkflowStarted and WorkflowCompleted; embedded
also records StartAccepted. Check these event inventories explicitly before
comparing common durable behavior. Embedded uses canonical PHP definitions and
marks language transport inapplicable.

The new profile declares MySQL 8.0's current 1 GiB CI allowance from #406.
Earlier manifests and retained artifacts preserve their frozen qualification
envelopes; effective database limits must be recorded separately for new runs.
Published component versions and case semantics are preserved. These CI
allowances do not establish performance qualification.

Namespaced children, retries, failures, cancellation propagation, other
parent-close policies, parallel groups, activity combinations and performance
remain separate gates.

The shared workflow builds the same installed SDK adapters once for both
activity and child profiles and stops the completed activity reference before
starting the child reference. Invoke the child cohort with
`bash scripts/conformance/server-parity/sdk-direction-profile.sh BACKEND NATIVE_URL child`;
it gives PHP, embedded and native recordings separate evidence directories and
uses independent PHP/embedded databases. Its owning workflow must call
`sdk-direction-cleanup.sh BACKEND child` before removing the shared database
and network. Cleanup preserves captured container logs when invoked again.
