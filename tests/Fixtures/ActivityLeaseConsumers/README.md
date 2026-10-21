# Installed Python and Rust activity-lease controls

These explicit, slow consumer inputs complement the [PHP fixture](../PhpActivityLeaseConsumer/README.md)
for [Workflow #878](https://github.com/durable-workflow/workflow/issues/878).
They do not run in the unit suite. Compilation, source tests and discovery do
not establish a six-minute pass or published-image acceptance.

Use a disposable database and a Server that actually advertises the boolean
`worker_protocol.server_capabilities.activity_lease_renewal=true`. Both clients
refuse before starting a run when discovery does not advertise it. Credentials
are the synthetic fixture token. Do not use production data or credentials.

## Install and launch

Use the exact normal registry pins in `python/requirements.txt` and
`rust/Cargo.toml`. Do not substitute a source checkout or a path dependency.
The Python pin is the planned release: wait until that exact package is
published before recording published-consumer acceptance. Preserve the resolved
Rust lock and dependency identity with the acceptance result.

```sh
python -m pip install -r python/requirements.txt
cargo build --locked --manifest-path rust/Cargo.toml
```

Set `SERVER_URL`, `NAMESPACE`, `WORKER_ID`, a unique `WORKFLOW_ID`, and a fresh
writable `STATE_DIR`. Keep the worker and client in separate processes. Run
`python python/consumer.py worker` or `rust/target/debug/activity-lease-acceptance worker`.
Use that same program with `long` or `loss` in the client process. The worker
writes its PID, then the real callback appends original task/attempt identities
to `invocations.jsonl`. A PID file alone does not prove registration completed.

## Real-duration and owner-loss cases

For `long`, retain the normal 300-second lease. The callback blocks for **360
real seconds**, with no application heartbeat or authored activity timeout.
The original execution/run/observation limits are **420 seconds**. The exact
int64/null/Unicode result and one invocation/attempt are required. After the
result, stop the worker with SIGTERM and inspect its real exit status.

For `loss`, use an explicitly configured five-second lease in a named
namespace. Wait for the first actual callback record, then SIGKILL only the
worker PID while keeping its container holder alive. Its in-process renewal
tasks must disappear with that process, and the original lease expiry must
stop moving. Start a new worker with a distinct ID, then use the ordinary
instance-scoped `workflow:v2:repair-pass` after expiry. The declared second
attempt uses the same activity identity, two-attempt budget and zero backoff,
and returns immediately. The client observer remains **30 seconds**; business
limits remain 420 seconds. This configured-lease case does not measure the
default worker-loss latency.

Capture the original history prefix, exact lease before/after owner loss and
final durable state. Reuse the PHP fixture's read-only `snapshot.php` and check
the actual language-specific workflow/run identities. Require no application
heartbeats, no unexpected task repairs, one `lease_expired` retry reason, exact
original/retry task and activity binding, preserved prefix hashes and zero
failed jobs. A later passing original never rescues a failed one. Retire every
owned runtime, database, dependency, binary and raw capture after recording the
result.
