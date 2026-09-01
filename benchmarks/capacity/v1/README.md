# Durable Workflow Capacity Benchmark Suite

Suite version: `1.4.0`

This directory is immutable evidence input. A changed workload, artifact tuple,
metric contract, or operating-point rule requires a new suite version instead
of changing a result after it was recorded.

Validate the suite, schemas, infrastructure profile, and regression corpus:

  python3 scripts/benchmark/capacity_suite.py validate

External tools can discover every versioned capacity JSON Schema, together
with its SHA-256 digest, from the canonical HTTPS manifest:

<https://durable-workflow.github.io/schemas/capacity-benchmark/v1/manifest.json>

The schema `$id` URLs in that manifest are the public retrieval contract.

The standard local profile is executable from this repository. It requires a
Linux x86_64 host with Docker Engine 27.5.1, Compose 2.20 or newer, a 6.8
kernel using the systemd cgroup-v2 policy, and Docker's overlay2 data root on
an ext4-backed non-rotating NVMe device with at least 100 GiB. Validate the
versioned Compose topology without creating containers:

  python3 scripts/benchmark/capacity_local_docker.py validate

Run the bounded no-provider-spend smoke from a clean host:

  python3 scripts/benchmark/capacity_local_docker.py smoke

The launcher pulls every exact image, installs the locked PHP, Python, and
Rust adapters, bootstraps Server with the declared MySQL and Redis settings,
passes all resolved container identities to the controller and collector,
executes the five-second simple Python cell, writes
build/capacity-v1/local-docker-smoke.result.json with qualified=true and
publishable=false, and always removes its containers, network, and volumes.
Host, engine, kernel, cgroup, storage, image, resource, network, Server,
MySQL, or Redis drift stops the run before workload admission.

Run a declared capacity selection with the same provision-and-cleanup
lifecycle (omit the selectors for the complete matrix):

  python3 scripts/benchmark/capacity_local_docker.py run \
    --cell simple-start-complete --binding python

For inspection, `up` leaves the verified topology running and `down` removes
all topology state, including durable volumes:

  python3 scripts/benchmark/capacity_local_docker.py up
  python3 scripts/benchmark/capacity_local_docker.py down

Each first-party SDK adapter runs every cell separately for PHP, Python, and
Rust. After the declared warmup, an adapter emits one observation-schema JSON
object per line for the complete measurement duration. Do not pool languages.
The executable adapter descriptors and sources live under bindings/{binding}.
Validate their artifact pins, source inventory, workload types, roles, and
cell coverage together with the suite:

  python3 scripts/benchmark/capacity_suite.py validate

Install each adapter from its own immutable package manifest before a run:

  (cd benchmarks/capacity/v1/bindings/php && composer install --no-dev)
  (cd benchmarks/capacity/v1/bindings/python && python3 -m pip install -r requirements.lock)
  (cd benchmarks/capacity/v1/bindings/rust && cargo build --release --locked)

Every adapter entrypoint implements four modes. `describe` emits its adapter contract
without credentials. `conformance` evaluates the checked-in payload fixtures
without contacting a runtime. `worker` registers every declared workflow,
activity, signal, and query definition on DURABLE_WORKFLOW_TASK_QUEUE. `client` is a
long-lived stdin/stdout JSONL process with start, signal, query, and result
operations; every response includes ok and elapsed_ms. For example:

  php bindings/php/capacity_adapter.php describe
  python3 bindings/python/capacity_adapter.py describe
  cargo run --release --locked --manifest-path bindings/rust/Cargo.toml -- describe

Run worker and client processes from the binding directory so relative package
manifests resolve. Supply runtime URL, namespace, task queue, and role-scoped
credentials only through the environment. The checked-in controller expands
and enforces the complete matrix. Inspect all 9 cells by 3 bindings without
contacting a runtime:

  python3 scripts/benchmark/capacity_matrix.py dry-run

Payload byte counts measure the UTF-8 encoding of each synthetic string value.
They exclude the Avro envelope and the surrounding argument container. Mixed
admissions inherit the payload and history contract of their selected component
instead of using an aggregate shape. Every activity step checks its input and
result size, including repeated hash steps that expand the prior digest back to
the declared next-input size.

The controller launches the declared client processes and worker slots (or
one-slot PHP worker processes), sends exact payload sizes and the seeded mixed
sequence, drains and discards a separate warmup cohort, stops measurement
admission at duration, and drains every admitted workflow within the declared
timeout. Query cells establish their declared long-lived query cohort before
the measurement clock starts. The query-inspection cohort occupies the full
open-workflow allocation. The mixed cohort receives its deterministic
largest-remainder allocation from the declared mix; the remaining shapes keep
their seeded relative weights while using the other open-workflow slots. Run
one bounded selection during harness development:

  DURABLE_WORKFLOW_RUNTIME_URL=http://127.0.0.1:8080 \
  DURABLE_WORKFLOW_NAMESPACE=capacity \
  python3 scripts/benchmark/capacity_matrix.py run \
    --cell simple-start-complete --binding python \
    --source-revision FULL_GIT_COMMIT \
    --run-timestamp 2026-08-11T00:00:00Z \
    --architecture x86_64

Omit `--cell` and `--binding` to execute the full matrix. The local-Docker
collector verifies the exact engine, kernel, cgroup/runtime policy, image
architecture, container image, CPU/memory limit, network, storage, Server
environment, MySQL configuration, and Redis configuration before admission.
It samples Docker CPU/memory, MySQL durable storage and connection/lock/write
counters, Redis memory/operations, and Server task-queue backlog. The topology
launcher resolves and supplies every CAPACITY_CONTAINER_* variable named by
collectors/local-docker/collector.json together with its declared Docker
project and network identity. Direct controller callers may still supply
those values themselves. Collector credentials stay in their declared
environment variables and never enter an observation or result.

Each cell/binding gets a separate observation stream and result. The controller
derives schedule-to-start and replay samples from versioned history exports and
query latency from adapter operations. Before accepting a completion, it checks
the closed export's typed workflow, activity, signal, query, task, and child-run
evidence and requires the exact declared typed-history-event count. Task rows
remain shape and latency evidence, but do not enter the count because ready-task
coalescing is intentionally timing-dependent. It emits measurement observations and
exactly one final drain observation. The reducer rejects omitted drain evidence,
altered controls, contradictory phase ordering, or non-contiguous samples. It
uses only measurement-phase completions, dispatches, errors, throttles, and
latencies for operating-point metrics. The separate drain summary proves final
open-work and task-queue convergence without contributing performance evidence.

Every load step declares workflow-start and query-operation targets per second,
the resident long-lived query cohort size, and a minimum delivery ratio. A pure
query cell has no measurement workflow-start rate: its fixed cohort and query
operation delivery are the workload. Mixed cells retain the workflow-start
rate for completion-required shapes alongside the resident query cohort.
Demand counters distinguish requests actually attempted by the generator from
requests accepted, completed, rejected, or throttled by Server. A full-duration
observation window remains ineligible when controller overhead, exhausted
open-work slots, or query serialization leaves either the attempted or
delivered count below the declared tolerance.

Completion ratios use only completion-required workflows started during the
measurement window. The observation stream reports those workflows separately
from the long-lived query cohort. A query-only window therefore qualifies its
completion rule by retaining the complete declared cohort for the whole window,
delivering the required queries, and converging during drain. Mixed cells apply
the ratio to their completion-required shapes instead of making the resident
query share an impossible denominator. Query-cohort churn during measurement is
ineligible.

Client protocol output is operation evidence; the controller combines it with
history timing and resource samples to emit observation JSONL.
Reduce one cell and one binding into a machine-readable result:

  python3 scripts/benchmark/capacity_suite.py evaluate observations.jsonl \
    --source-revision FULL_GIT_COMMIT \
    --run-timestamp 2026-08-11T00:00:00Z \
    --architecture x86_64 \
    --output result.json

The reducer rejects incomplete identity, profile-resource drift, mixed cells,
and mixed bindings. Missing workload-specific metrics, a measurement duration
that differs from the declared window, an incomplete long-lived query cohort,
or an unconverged drain makes the load step ineligible. Drain completions,
dispatches, failures, and latency samples remain a separate convergence summary
and never improve measurement rates, ratios, or percentiles. The maximum
sustained operating point is the highest entire load step that meets every
measurement-phase latency, error, throttle, resource, and backlog limit. A
capacity result with no qualified operating point is marked publishable=false
so it cannot be presented as a customer-facing capacity claim.

Before comparing two results, require compatible identities:

  python3 scripts/benchmark/capacity_suite.py compare left.json right.json

Exit status 2 means the results differ in suite, source, artifacts,
infrastructure, architecture, or SDK binding and cannot be silently compared.
Run timestamps remain attached to both otherwise-comparable results.

The deterministic reference command qualifies only the reducer and result
shape. Its result has evidence_class=harness_reference and publishable=false;
it is never capacity evidence. Correctness conformance remains a separate
evidence class.

Provider-backed execution requires separate no-spend authorization. Until
then, use the checked-in local Docker profile. Never place customer data,
credentials, provider account details, or private fleet topology in suite
inputs, observations, results, or regression fixtures.

When a field defect exposes a workload shape absent from this suite, append
the smallest synthetic deterministic reproducer to the regression corpus.
Do not rewrite an existing fixture or broaden it with unrelated scenarios.
