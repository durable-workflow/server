# Explicit installed SDK runtime-lease controls

These slow inputs complement `../PhpActivityLeaseConsumer` for Workflow #878.
Install published Python 2.8.0 in an ordinary venv, and build the independent Rust
crate against the normal crates.io dependency. Do not use a path dependency or
replace SDK source. Record the resolved package checksums and loaded versions.

All Workers use synthetic `synthetic` authentication on a disposable runtime.
Set `SERVER_URL`, `NAMESPACE`, `WORKER_ID`, and a unique `WORKFLOW_ID`. Mount a
fresh writable directory at `/state` for each original. No application heartbeat
or authored activity timeout is added. Register the actual Worker before starting
the client; check real `activity_lease_renewal` discovery, not the image version.
Python checks that flag itself; confirm registration and discovery independently
for Rust before admitting an original.

The Python workflow takes two positional values. Rust's typed workflow takes
one tuple request, so its client sends that tuple as one nested argument.

Run `python_worker.py` or the compiled Rust worker, then `client.py python long`
or `client.py rust long`. The callback blocks for 360 seconds under the normal
300-second runtime lease. Run/execution/observation limits are 420 seconds. Require
one invocation/attempt, the exact int64/null/Unicode result, unchanged original
deadlines and history prefixes, and zero application heartbeat events.

For `loss`, use a separate named namespace and configured five-second lease.
The original observer is 30 seconds and business deadlines remain 420. After the
first actual invocation, kill only that callback-owner process while retaining
the container holder. Verify the original expiry stops moving, start a distinct
replacement Worker, and use the ordinary instance-scoped repair pass after lease
expiry. Require the same activity, declared two-attempt/zero-backoff policy and
exact result. Preserve failed originals; never extend their observers.

Use the PHP fixture's read-only snapshot and verification patterns for the exact
original task/attempt bindings and stopped SQL. Record actual worker/API exits,
any intentionally killed owner, remaining stale registrations and audit hashes.
The Rust fixture's two executor threads let one callback block while asynchronous
maintenance runs; this is functional acceptance, not a capacity comparison.
Retire the phase's dependencies/compiler/DB/raw state at its declared boundary.
