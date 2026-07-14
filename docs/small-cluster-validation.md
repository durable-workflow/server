# Small Cluster Validation

This note records the Phase 0 decision and CI harness shape for non-Kubernetes
clustered Durable Workflow Server deployments. The harness is intentionally
narrow: it validates the first boring topology without broad HA, rolling
upgrade, Kubernetes, or provider-failover promises.

## Decision

Proceed with a narrow small-cluster contract.

The first public clustered shape should be:

- 2 or 3 API server containers behind a stateless L4 or L7 load balancer.
- External MySQL or external PostgreSQL as the durable database. SQLite is not
  a clustered persistence backend.
- Shared Redis for cache, long-poll wake signals, query-task queue locks,
  task-queue admission locks, and Laravel queue state.
- At least one queue worker consuming the configured Redis queue for
  server-dispatched work such as durable timers.
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

## CI Harness

`docker-compose.small-cluster.yml` and `scripts/smoke-small-cluster.sh` validate
the first supported shape in CI. The smoke runs once with MySQL and once with
PostgreSQL. Each run starts:

- 2 API nodes behind an nginx load balancer;
- one bootstrap or migration job;
- one Redis queue worker;
- one scheduler or maintenance runner;
- shared Redis for cache and queue state;
- either MySQL or PostgreSQL as the shared durable database.

The smoke checks `/api/health`, `/api/ready`, and `/api/cluster/info` through
the load balancer, registers an external worker through the load balancer,
starts a workflow through the load balancer, polls the workflow task from
`server-a`, completes the leased task through `server-b`, and verifies the run
through the load balancer. That direct node split is deliberate: it proves
worker lease and completion state survives API-node crossing without sticky
sessions.

The harness remains boring Docker Compose. It avoids provider-specific
orchestration and does not prove the separately documented Helm or raw-manifest
Kubernetes contracts, nor imply multi-region, automated database failover,
rolling upgrades, or SLA-grade HA.

## Unsupported Until Proven

These remain outside the public support boundary:

- SQLite clustered mode.
- Duplicate scheduler or maintenance runners.
- Redis-less multi-node mode.
- Rolling upgrades.
- Active/active multi-region deployments. Active/passive multi-region with
  operator-driven regional failover is documented separately in
  [`docs/multi-region-validation.md`](multi-region-validation.md); it
  extends the small-cluster contract per region and does not weaken any of
  these boundaries.
- Arbitrary process supervisors or orchestrators.
- Self-serve Helm charts and provider-specific managed-Kubernetes validation.
  Those Kubernetes deployment contracts are documented separately and are not
  proven by this Compose harness.
- Strong "five-nines" or "zero-downtime" SLA claims. Single-region HA
  failover behavior — managed-database failover, managed-Redis failover,
  API-node loss, worker loss, and scheduler-runner restart — is its own
  self-serve contract layered on top of this one and is documented in
  [`docs/ha-failover-validation.md`](ha-failover-validation.md). Marketing
  uptime claims beyond the bounded recovery times in that contract remain
  outside this support boundary.

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
- run at least one `php artisan queue:work redis` process with the same
  database, Redis, and server configuration as the API nodes;
- run exactly one scheduler or maintenance loop;
- use stop-the-world upgrades until a rolling-upgrade contract lands.
