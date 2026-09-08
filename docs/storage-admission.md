# Storage Admission

Storage admission is optional and disabled by default. It consumes a local
operator-owned observation; it does not measure, reserve, grow, or bill storage.
Namespace row limits and payload quotas remain separate protections.

Set these on every Server HTTP and background-worker process:

```dotenv
DW_STORAGE_ADMISSION_FILE=/run/durable-workflow/storage/observation.json
DW_STORAGE_ADMISSION_SOURCE=runtime-data-volume
DW_STORAGE_ADMISSION_MAX_AGE_SECONDS=15
```

The operator publishes this UTF-8 JSON document using an atomic rename in a
trusted directory mounted read-only into Server. `observed_at` is an integer
Unix timestamp, not a string. The source identifies the exact backing resource,
not a namespace that happens to use it.

```json
{
  "schema": "durable-workflow.storage-pressure.v1",
  "source": "runtime-data-volume",
  "observed_at": 1788770000,
  "state": "normal"
}
```

| State | Behavior |
| --- | --- |
| `normal` | Normal admission and execution. |
| `draining` | Reject producers and new task claims. Allow existing task acknowledgements and heartbeats to use the operator's emergency reserve. |
| `fenced` | Reject ordinary API mutations, including task acknowledgements. Keep read-only inspection available. |

Missing, malformed, future, stale, wrong-source, symlinked, or group/world-writable
files are treated as fenced, not healthy. Mount the directory rather than a
single file so atomic replacement is visible. Use a regular file no larger than
4 KiB. Keep the collector and its output outside the capacity-limited volume.

Refusals return HTTP 503, `Retry-After: 5`, and `storage_pressure` or
`storage_admission_unavailable`. Authentication, role, protocol, and namespace
checks still run first. Paths and storage identities are not exposed. A refusal
before the handler includes `request_admitted: false`; it says nothing about a
previous attempt. Poll responses include `task: null`, `claim_admitted: false`,
and instructions to retain the same poll request ID. Already-waiting polls
recheck admission before each new probe. Never treat pressure as an activity
failure or blindly repeat side effects after an ambiguous acknowledgement.

`/api/ready` reports pressure as not ready; `/api/health` retains its database
connectivity meaning. `php artisan server:storage-status --json` reports the
current observation and exits nonzero when new work is paused. The queue daemon
pauses before claiming another job. Schedule evaluation, timeout enforcement,
history pruning, and payload cleanup do not start a new pass under pressure.

## Deployment Requirements

### External Completion Payloads

When discovery advertises `upload.completion_context`, a worker may retry a
draining upload refusal with `X-Durable-Workflow-Payload-Completion`. The header
is a JSON object, at most 4096 bytes, containing exactly:

```json
{"schema":"durable-workflow.v2.payload-completion-context.v1","kind":"activity","task_id":"task-id","attempt":"activity-attempt-id","lease_owner":"worker-id","operation":"complete","slot":["result"]}
```

`activity` uses its attempt ID; `workflow` and `query` use their positive integer
attempt counter. The context must describe the current namespace-scoped lease.
An activity allows `complete`/`["result"]` or `fail`/`["failure","details"]`;
a query allows `complete`/`["result_envelope"]`. Workflow completion slots are
`["commands", index, field]` for `arguments`, `entries`, `request_payload`, or
`result`, plus `exception/details` and `workflow_stream/items/index/payload`.
Indices are non-negative integers, not numeric strings.

This is not a general upload permission. The worker role and current lease are
checked before reading and before committing new bytes. Every slot is immutable
within a lease. All its slots and complete/fail outcomes share at most 128 slots
and `DW_EXTERNAL_PAYLOAD_COMPLETION_MAX_BYTES` distinct bytes, defaulting to the
ordinary maximum external payload size. Matching retries do not consume another
allowance. A recorded ready reference can be returned without a new write after
the lease closes. Expired references cannot be recreated without a current lease.
Existing namespace quotas still apply, and fenced/stale admission refuses uploads.

Normal uploads without this header keep their existing behavior. SDKs must not
attach it to workflow starts, signals, updates, or other new input. The allowance
does not reserve physical disk space or promise that arbitrarily many leased
results fit; operators still need the headroom qualification below.

### Physical Reserve

This is cooperative admission, **not a database write fence or an exact byte
reservation system**. A request, queue job, or maintenance pass already in
progress can still write. A storage transition can race a successful check.
Operators must bound request size, concurrency, lease count, collector cadence,
and drain duration, and measure sufficient headroom for their write
amplification. Separate the promised customer allocation from emergency reserve.

Guard other entry points too: custom schedulers, `queue:work --once`, direct
maintenance commands from the Workflow package, bootstrap, and independent
database writers are not intercepted by this HTTP/daemon hook. Restore capacity
before resuming; do not delete committed history to manufacture free space.
Recovery and authorized growth belong to the deployment's durable lifecycle.

Before enabling this on a supported deployment, qualify its collector, reserve,
all SDK retry paths (including large-result uploads), active leases, maintenance,
and recovery against a real bounded backing volume. A passing admission unit
test does not prove healthy operation at the hard disk limit.

The [native MySQL experiment](../tests/Integration/StorageAdmission/README.md)
provides a reproducible bounded-database case, with explicit limits on what it
qualifies. It is not a production collector or a suggested reserve size.
