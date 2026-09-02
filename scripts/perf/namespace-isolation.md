# Namespace isolation experiment

`run-namespace-isolation.sh` proves a bounded shared-Server operating envelope.
It starts an isolated Server, MySQL, Redis, and scheduler, then runs one noisy
namespace beside one control namespace on both shared and distinct queue names.
The driver uses only published Server and Python SDK artifacts.

## Shared-resource inventory

| Resource | Server-owned control | Authority and failure behavior | Remaining boundary |
| --- | --- | --- | --- |
| Customer API requests | Per-namespace requests/minute and concurrent-request slots, with default, override, and hard ceilings | Shared cache locks and counters; configured isolation fails closed with retryable `503` | Requests rejected after PHP/Laravel begins handling them still consume ingress and application CPU |
| Workflow and activity dispatch | Per-queue and per-namespace active leases and dispatch-rate budgets | Shared cache; configured admission fails closed instead of leasing without authority | Database and process CPU remain shared |
| Held worker polls | Global and per-namespace workflow/activity slots | Shared cache with finite leases; excess polls receive a bounded cooldown response | Global slots must be sized for the worker/queue topology |
| Query and update-validation polls | A separate global and per-namespace held-poll pool | Shared cache with finite leases | Query execution still uses shared HTTP and database capacity |
| Durable workflow state | Database-authoritative limits for instances, runs, open runs, schedules, schedule history, worker registrations, workflow history, tasks, pending tasks, timers, pending timers, waits, open waits, commands, streams, and stream items | Namespace row lock and the same transaction as the growth mutation; typed rejection or fail-closed evaluation | Row counts do not reserve database CPU, IOPS, connection slots, or total database bytes |
| Schedule evaluation | Namespace-fair bounded batches plus schedule and schedule-history quotas | Database ordering and transactions | Scheduler process CPU is shared |
| External payloads | Per-object limit plus cumulative namespace bytes and object counts, with hard ceilings and overrides | Database-serialized admission; typed retryable `429`/`503` | Backing-provider throughput remains shared |
| Nexus/service calls | Per-caller-namespace request-rate and in-flight reservations | Shared cache with finite abandoned-reservation recovery | A downstream endpoint can impose its own shared bottleneck |
| Redis/cache state | Bounded keys, finite leases, and fail-closed configured admission | Redis is shared authority for short-lived admission state | A Redis outage pauses configured admission for every namespace until authority returns |
| CPU, memory, network, and database capacity | Container/process limits and deployment sizing | Deployment operator | Server does not partition these physical resources inside one PHP process |

The operator metrics endpoint exposes the fixed-cardinality
`dw_namespace_request_admission_rejections`,
`dw_namespace_durable_state_usage`, and
`dw_runtime_external_payload_namespace_usage` surfaces for the requested
namespace. Task-queue diagnostics expose queue admission and lease pressure.

## Workload

The noisy workload continuously attempts workflow starts, standalone
activities, timers, child workflows, signals, queries, updates, schedules,
Nexus calls, replay-heavy histories, and runtime-externalized payloads. It has a
30-request/minute, one-concurrent-request API budget and a ten-open-run durable
budget. The control workload starts one workflow at a time; each workflow runs
one activity and alternates between the shared and control-only queue.

During pressure, the driver stops Redis and Server in turn. Workflows that
overlap those deliberate outages are excluded from the steady-state latency
sample. After pressure, the load workers are replaced and both namespaces must
complete fresh work without data repair. Docker CPU, memory, process, network,
and block-I/O samples are retained in the result. Task-queue backlog, lease,
and admission snapshots are captured before pressure, after each disruption,
and after pressure for both namespaces. Post-pressure operator metrics retain
each namespace's configured limits, usage, and bounded rejection counters.

## Run it

```bash
scripts/perf/run-namespace-isolation.sh
```

The default exact tuple and cell are:

- `durableworkflow/server:2.0.3`
- Python SDK `2.0.0`
- Server: 1 vCPU and 512 MiB
- MySQL: 0.5 vCPU and 512 MiB
- Redis: 0.25 vCPU and 128 MiB
- scheduler: 0.25 vCPU and 256 MiB
- eight global/four per-namespace workflow/activity poll slots
- four global/two per-namespace query poll slots
- 120 seconds of pressure

Useful overrides:

```bash
DW_ISOLATION_SERVER_VERSION=2.0.3 \
DW_ISOLATION_SERVER_IMAGE=durableworkflow/server:2.0.3 \
DW_ISOLATION_PYTHON_SDK_VERSION=2.0.0 \
DW_ISOLATION_DURATION_SECONDS=120 \
DW_ISOLATION_NOISY_PRODUCERS=1 \
DW_ISOLATION_NOISY_REQUESTS_PER_MINUTE=30 \
DW_ISOLATION_NOISY_CONCURRENT_REQUESTS=1 \
DW_ISOLATION_CONTROL_LATENCY_LIMIT_SECONDS=15 \
scripts/perf/run-namespace-isolation.sh
```

The command succeeds only when the control namespace completes at least five
workflows within the configured p95 envelope, the noisy namespace receives a
quota rejection distinct from an infrastructure outage, every operation family
is attempted, queue-depth diagnostics exist for both namespaces, both
operator metric snapshots exist, at least one noisy-namespace rejection is
visible through those metrics, both disruptions recover, and both namespaces
complete fresh work afterward. Compose resources and volumes are removed on
exit. The JSON result and service logs remain under
`build/namespace-isolation/`.

## Verified envelope

The default cell passed against Server `2.0.3` and Python SDK `2.0.0`:

- 7 measured control completions; p50 10.1554 seconds, p95/max 11.2527 seconds
- noisy rejections: 10 concurrency, 8 request-rate, and 2 open-run quota
- Redis readiness recovery: 4.532 seconds
- Server readiness recovery: 15.095 seconds
- post-pressure completion: 2.878 seconds for control and 2.7909 seconds for noisy
- peak sampled stack memory: 706.888 MiB
- peak sampled Server memory: 157.5 MiB
- peak sampled aggregate CPU: 185.6% of one core

This supports shared mutually untrusted namespaces only when operators configure
non-null namespace limits, provision the documented poll slots, retain capacity
headroom, and enforce connection/request ceilings at ingress. The image's
backward-compatible defaults are intentionally unlimited and do not constitute
tenant isolation by themselves.

A five-producer boundary run, with callers continuously retrying despite
backpressure, failed the same 1-vCPU Server envelope: only one uncontaminated
control workflow completed and its latency was 16.9974 seconds. Application
admission is therefore not volumetric DoS protection. Deployments exposed to
untrusted clients need an ingress layer that rejects abusive traffic before it
consumes a PHP request worker. Larger workloads need their own measured cell or
stronger physical isolation; this experiment does not support an unqualified
"scale forever" claim for one shared Server.
