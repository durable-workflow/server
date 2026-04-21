# Small Cluster Validation

This note records the Phase 0 decision for non-Kubernetes clustered Durable
Workflow Server deployments. It is a validation outcome, not the full supported
cluster harness.

## Decision

Proceed with a narrow small-cluster contract.

The first public clustered shape should be:

- 2 or 3 API server containers behind a stateless L4 or L7 load balancer.
- External MySQL or external PostgreSQL as the durable database. SQLite is not
  a clustered persistence backend.
- Shared Redis for cache, long-poll wake signals, query-task queue locks,
  task-queue admission locks, and Laravel queue state.
- One scheduler or maintenance process for `schedule:evaluate`,
  `activity:timeout-enforce`, and `history:prune`.
- External SDK workers scaled independently from API nodes.
- Stop-the-world upgrades for the first supported contract.

Do not document duplicate schedulers, rolling upgrades, or Redis-less
multi-node mode as supported until those paths have dedicated tests.

## Rationale

The API surface is mostly stateless. Worker registration, workflow history,
task rows, task leases, namespaces, and search attributes are stored in the
database. Workflow and activity task polling uses durable task rows for the
lease source of truth, so a load balancer does not need sticky sessions for
poll, heartbeat, complete, or fail requests.

The first cluster harness should prove worker traffic works without sticky sessions.

Redis remains required for the first cluster contract because several
cross-node coordination paths use the configured cache store:

- Long-poll wake signals are cache keys. Without a shared cache, correctness
  still comes from periodic database reprobes, but wake latency regresses and
  node behavior becomes harder to reason about.
- Query tasks use cache-backed queues and require an atomic cache lock.
- Server-side task-queue admission budgets use cache locks when configured.
- The published Compose recipe already uses Redis for queue and cache state.

The scheduler and maintenance shape is the main boundary. The current server
entrypoints run `schedule:evaluate`, `activity:timeout-enforce`, and
`history:prune` in one loop. Those passes are intended to be bounded and
idempotent where possible, but they are not yet documented or tested as safe to
run concurrently on every API node. The initial cluster contract should
therefore keep exactly one scheduler or maintenance runner.

Rolling upgrades should be explicitly deferred. The current server has
readiness checks, role-scoped auth, protocol manifests, and package provenance,
but there is no CI proof that mixed image versions can safely process the same
database and Redis state during a rolling deploy. The first contract should
require draining workers, stopping scheduler/maintenance, replacing API nodes,
running bootstrap or migrations, then starting workers and scheduler again.

## Proceed Criteria For The Next Phase

The next phase can build the supported harness if it proves exactly this shape:

- one MySQL-backed small cluster;
- one PostgreSQL-backed small cluster;
- 2 API nodes behind a load balancer;
- one bootstrap or migration job;
- one scheduler or maintenance runner;
- shared Redis;
- at least one external worker registration through the load balancer;
- `/api/health`, `/api/ready`, and `/api/cluster/info` through the load
  balancer;
- a worker poll and completion path that can cross API nodes without sticky
  sessions.

The harness should be boring Docker Compose or an equivalent CI topology. It
should avoid provider-specific orchestration and should not imply Helm,
Kubernetes, multi-region, automated database failover, or SLA-grade HA.

## Unsupported Until Proven

These remain outside the public support boundary:

- SQLite clustered mode.
- Duplicate scheduler or maintenance runners.
- Redis-less multi-node mode.
- Rolling upgrades.
- Multi-region deployments.
- Arbitrary process supervisors or orchestrators.
- Helm charts and provider-specific managed-Kubernetes validation.
- Strong HA/SLA promises beyond the documented small-cluster failure behavior.

## Operator Contract Draft

When the next phase publishes the harness, the corresponding docs should state:

- set a unique `DW_SERVER_ID` for each API node;
- use the same auth tokens or signature keys on every node;
- use the same `APP_VERSION`, workflow package version, and payload codec
  configuration on every node;
- set `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, and the same Redis
  connection settings on every node;
- set `DB_CONNECTION=mysql` or `DB_CONNECTION=pgsql` with one external
  database shared by all nodes;
- route only HTTP traffic through the load balancer;
- keep database and Redis services private to the deployment;
- run exactly one scheduler or maintenance loop;
- use stop-the-world upgrades until a rolling-upgrade contract lands.
