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

The Python workflow takes two positional values. Rust's typed workflow and
activity each take one tuple request. Both call sites send the tuple as one
nested argument.

Run `python_worker.py` or the compiled Rust worker, then `client.py python long`
or `client.py rust long`. The callback blocks for 360 seconds under the normal
300-second runtime lease. Run/execution/observation limits are 420 seconds. Require
one invocation/attempt, the exact int64/null/Unicode result, unchanged original
deadlines and history prefixes, and zero application heartbeat events.

Rust also accepts `client.py rust cooperative`: the same genuine 360-second
callback yields to Tokio. Qualify it separately from the deliberately blocking
`long` control. Both retain the original 420-second whole observer, including
workflow start. `TASK_QUEUE` overrides the Rust Worker and client queue together;
use separate queues/state directories to run the two controls concurrently.
`WORKER_TOKEN` selects an explicit worker-role credential for Python and Rust, with the
historical synthetic token as the default. Neither option changes the SDK.

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
The Rust blocking case tests progress-independent maintenance on a two-thread
executor. Thread count alone is not evidence that maintenance continues. The
published 4.2.0 failure is tracked in
[Rust SDK #117](https://github.com/durable-workflow/sdk-rust/issues/117).
Keep that failed original distinct from the passing owner-loss control. This is
functional acceptance, not a capacity comparison.
Retire the phase's dependencies/compiler/DB/raw state at its declared boundary.

## Read-only audit inputs

`audit.php` reads the disposable SQLite database at `/data/database.sqlite`.
Mount the database directory read-only. Use `--live` for a transactional prefix
snapshot, then stop the API and take the final snapshot without that flag. The
final audit requires an unchanged source hash. Every table is bounded to 1,000
rows; this helper is for the disposable controls, never a production database.

`verify.py` reads evidence under `/evidence`. Save final snapshots as
`long-final.json` and `loss-final.json`, with each client's state in its own
directory. Supply a `cohort.json` array identifying each original explicitly:

```json
[
  {
    "language": "python",
    "folder": "python/long",
    "mode": "long",
    "run_id": "THE_ORIGINAL_RUN_ID",
    "result_name": "result.log",
    "prefix_name": "long-prefix.json"
  }
]
```

The verifier checks original deadlines, typed output, invocation/attempt/task
bindings, retry policy and expiry reason, immutable history prefixes, absence of
progress-heartbeat events, and stopped database hashes. A retry task retains its
actual second-attempt counter; workflow tasks remain first-attempt. Include all
unsuccessful originals and their reasons in the owning result record, even when
a later corrected fixture has a separately passing cohort.

For each new `loss` row, supply `owner_snapshots` as two explicit live snapshot
filenames taken after the original owner process is confirmed absent and any
bounded in-flight renewal is allowed to drain, before starting its replacement.
The verifier requires the original task and attempt expiry to remain identical
across those reads. The previous cohort's snapshot pair was not separately
compared before deletion; this new requirement does not retroactively verify it.
These additions are preparation until the normal published SDK and fresh
originals are actually qualified.
