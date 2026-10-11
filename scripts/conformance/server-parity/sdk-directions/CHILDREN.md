# Published SDK parent/child directions

This additive Server #325 profile defines nine PHP/Python/Rust parent and child
workflow directions with the same pinned published component tuple as the
qualified one-activity profile. Definitions, normal SDK adapters, reader guards
and isolated profile invocation are implemented. Actual runtime recordings and
hosted qualification are pending.

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
activity and child profiles. Invoke the child cohort with
`bash scripts/conformance/server-parity/sdk-direction-profile.sh BACKEND NATIVE_URL child`;
it gives PHP, embedded and native recordings separate evidence directories and
uses independent PHP/embedded databases. Its owning workflow must call
`sdk-direction-cleanup.sh BACKEND child` before removing the shared database
and network. Cleanup preserves captured container logs when invoked again.
