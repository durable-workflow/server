# High Availability and Failover Validation

This note records the operator-side contract for self-serve high-availability
deployments of the standalone Durable Workflow Server. It covers the failure
modes that the engine survives **inside one region**: managed-database
failover (MySQL/Aurora writer promotion, RDS Multi-AZ failover, PostgreSQL
patroni promotion, Cloud SQL failover, etc.), managed-Redis failover
(Sentinel promotion, Elasticache replication-group failover, Memorystore
standby promotion, etc.), API-node loss, worker loss, and
scheduler/maintenance runner restart.

It is the server-side view of the engine contract in
[`durable-workflow/workflow#docs/deployment/ha-failover.md`](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/ha-failover.md).
The library contract names what the engine guarantees during each event
class; this document names the operator topology, validation harness, and
recovery-packet evidence required to claim the self-serve HA contract on the
standalone server image and Compose recipes.

Cross-region active/passive recovery is a different contract and lives in
[`docs/multi-region-validation.md`](multi-region-validation.md). HA inside
one region (the subject of this document) and active/passive across regions
are layered: the multi-region contract assumes the single-region HA
contract holds inside the active region.

## Decision

Proceed with a narrow, self-serve single-region HA contract.

The first public HA shape extends the small-cluster contract with explicit
behavior under managed-database failover, managed-Redis failover, API-node
loss, worker loss, and scheduler-runner restart. The contract is bounded
to topologies the engine and small-cluster smoke already validate; it does
not introduce active/active databases, automatic regional failover, or
duplicate scheduler runners.

Provider-specific failover (RDS Multi-AZ, Aurora cluster failover,
Elasticache replication-group failover, Cloud SQL HA, Memorystore HA, etc.)
is in scope **as one configuration of this contract**, on the rule that the
managed service provides a single writable endpoint after promotion and
fences the previous primary before it can re-attach. The engine has no
provider-specific code path; it talks to whatever connection the operator
gives it.

Active/active databases, hands-free regional failover, RPO=0 cross-writer
replication, duplicate scheduler runners, and broad SLA promises remain
support-led.

## Rationale

Three engine properties make a narrow self-serve HA contract possible
without changing the engine:

- **The workflow database is the correctness substrate.** Every supported
  promise — claim fencing, lease expiry, schedule fire dedup, history
  append, build-id rollouts — is durable in the database before any
  acceleration signal fires. A managed-database failover that preserves
  the published RPO preserves every guarantee. The engine never returns
  success for a write that has not committed, so callers cannot be misled
  by an in-flight failover.
- **Redis is acceleration, not correctness.** Wake signals, query-task
  locks, admission locks, and worker-compatibility heartbeats live in
  Redis but every consumer has a documented degraded-mode fallback to the
  durable substrate. A Redis failover is a latency event, never a
  correctness event. The
  [scheduler correctness contract](https://github.com/durable-workflow/workflow/blob/main/docs/architecture/scheduler-correctness.md)
  is the engine source of truth for that.
- **The scheduler/maintenance runner is a singleton, but its outage is
  bounded.** Schedule fires, activity-timeout enforcement, and history
  pruning pause while no scheduler is running and resume cleanly on
  restart from durable state. The orchestrator (Compose, systemd,
  Kubernetes Deployment with `replicas: 1`) is responsible for restart
  latency.

Two engine properties keep duplicate-scheduler topologies out of the
contract:

- `schedule:evaluate`, `activity:timeout-enforce`, and `history:prune` do
  not yet enforce a leader lease in the durable substrate. The
  per-schedule row lock will dedupe a duplicate schedule fire, but
  activity-timeout enforcement and history pruning have not been tested
  under concurrent runners.
- The small-cluster smoke harness intentionally runs **exactly one**
  scheduler. A topology that runs more than one is outside what the
  smoke proves.

The HA shape that fits within these properties is:

- 2 or more API server containers behind a stateless load balancer (per
  the small-cluster contract);
- one shared external MySQL or PostgreSQL writable endpoint, optionally
  fronted by RDS Multi-AZ, Aurora HA, Cloud SQL HA, Patroni, ProxySQL,
  RDS Proxy, or PgBouncer for connection-level failover;
- one shared external Redis endpoint, optionally fronted by Sentinel,
  Elasticache replication-group, or Memorystore HA for cache-level
  failover;
- at least one queue worker consuming the configured Redis queue for
  server-dispatched work such as durable timers;
- one scheduler/maintenance runner under an orchestrator that handles
  restart;
- external SDK workers that talk to the load-balanced API endpoint.

## Operator Contract

When a deployment claims the single-region HA contract, the published
runbook must state at minimum:

- the database service's promotion mechanism (Multi-AZ, Aurora cluster
  failover, Cloud SQL HA, Patroni, etc.), the published RPO, and the
  expected promotion latency window;
- whether a connection proxy (RDS Proxy, ProxySQL, PgBouncer) sits
  between the API/scheduler containers and the database, and the
  proxy's switchover behavior on primary promotion;
- the Redis service's promotion mechanism (Sentinel, replication-group
  failover, Memorystore HA) and the expected client reconnect latency;
- the load balancer's health-check endpoint, interval, and removal
  threshold (must be `/api/ready`, not `/api/health` alone — see the
  load-balancer rules below);
- the scheduler/maintenance runner's host and orchestrator, with proof
  that "exactly one runner" is enforced by the orchestrator (a Compose
  service with `deploy.replicas: 1` and `restart: always`, a systemd
  unit guarded by a host-level lease, or a Kubernetes
  `Deployment` with `replicas: 1` and a strict `RollingUpdate`
  strategy);
- the credentials and procedure to fence the failed database primary
  before it can re-attach as a secondary;
- the frequency and last-run timestamp of the failover rehearsal
  acceptance test (see CI Harness below).

Per-node configuration continues to follow the small-cluster contract:

- set a unique `DW_SERVER_ID` for each API node;
- use the same auth tokens or signature keys, `APP_VERSION`, workflow
  package version, payload codec configuration, and Redis configuration
  shape on every node, queue worker, and scheduler runner;
- set `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` and the same
  Redis connection settings everywhere;
- set `DB_CONNECTION=mysql` or `DB_CONNECTION=pgsql` with one external
  database endpoint shared by all nodes;
- keep database and Redis services private to the deployment;
- run at least one `php artisan queue:work redis` process and monitor it as a
  required server process;
- run exactly one scheduler/maintenance runner.

The HA contract does not introduce a new env-var contract; it is the
small-cluster contract plus rehearsed failover behavior plus the
load-balancer rules below.

## Load-Balancer, Readiness, and Traffic-Shift Rules

The load balancer in front of the API nodes is the single decision
point for traffic admission during a failover. Operators MUST wire it
as follows:

- Use **`GET /api/ready`** as the health/readiness check. Do not use
  `/api/health` alone. `/api/health` only proves the process is
  serving HTTP; `/api/ready` proves the server can use its durable database
  and reports whether Redis wake acceleration is healthy or degraded. During a
  database outage, `/api/ready` will
  fail on every node — that is the correct signal, and the load
  balancer MUST NOT fall back to a stale "last known good" roster.
- Give each readiness request a transport timeout of at least **two seconds**.
  The Redis portion of readiness runs in a short-lived child process through
  the configured Laravel Redis client. Its selected-only configuration and
  probe value travel over stdin, not process arguments. The connection is
  non-persistent, disables retries, and gives each blocking transport phase a
  200 ms limit. The parent terminates the child after 1.5 seconds, so a stopped
  or unresponsive Redis endpoint still produces the complete readiness
  response within two seconds while the durable database remains available.
  After that warning, the same readiness snapshot reads required worker-
  compatibility evidence directly from durable heartbeat rows and does not
  fall back to the unavailable Redis cache.
  Runtime Redis operations retain their configured retry and backoff policy.
- Use a check **interval of 5–10 seconds** and a removal threshold of
  2–3 consecutive failures. Smaller intervals shorten recovery time;
  choose the threshold to balance database-outage removal time against
  transient connection resets during substrate promotion.
- Do **not** require sticky sessions. Worker registration, workflow
  task polling, and workflow task completion all flow through any
  API node; the small-cluster smoke proves an external worker can
  poll `server-a` and complete on `server-b`.
- During a Redis-only failover, readiness remains green on every
  node while the durable database is available (the engine treats Redis loss
  as acceleration degradation, not substrate failure). The acceleration-layer
  health check surfaces `checks.cache.status=warning` and
  `checks.cache.degraded_capability=long_poll_wake_acceleration`; do not
  configure the load balancer to remove nodes on that warning.
- During a database failover, readiness fails on every node
  simultaneously. The load balancer MUST tolerate the all-down
  state; it returns a `5xx` to clients and the engine does not need
  manual draining.
- After substrate recovery, the first node to pass `/api/ready` is
  the readiness anchor. The load balancer admits it on its next
  check interval; remaining nodes follow as their connections
  recover.

### Recommended traffic-shift sequence during a failover

The engine does not require any of the following steps to be run
manually during a managed failover; they are the recommended
operator sequence for verifying recovery before declaring traffic
restored:

1. Watch `/api/ready` from outside the load balancer. Wait for at
   least one API node to return 200.
2. Curl `/api/cluster/info` through the load balancer with an admin
   token. Confirm `topology.current_shape` is `standalone_server`,
   `topology.current_roles` includes `api_ingress`, `control_plane`,
   `matching`, and `history_projection`, and `topology.matching_role`
   matches the rolled deployment shape (the same fields the
   small-cluster contract already pins).
3. Issue `POST /api/worker/register` for a probe worker through the
   load-balanced endpoint and confirm 2xx.
4. Verify the queue worker is running and consuming the configured Redis queue.
5. Verify the singleton scheduler/maintenance runner is reachable
   in its orchestrator and that no second runner has been started.
6. Resume external traffic and watch task throughput resume from the
   metrics surface in
   [Multi-Node Requirements](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/multi-node-requirements.md).

## Failover Behavior by Event

The engine contract in
[`workflow#docs/deployment/ha-failover.md`](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/ha-failover.md)
names the engine guarantees. The operator contract on the standalone
server is:

| Event | Operator-visible behavior | Engine recovery bound (after substrate / runner is back) |
| --- | --- | --- |
| Managed-database failover | Writes fail with the underlying database error and are not silently buffered. Reads fail. `/api/ready` fails on every API node and on the scheduler. No work is silently lost. | Connection-pool reconnect, plus one `task_repair` cadence (default 3s), plus one long-poll timeout (default 30s, max 60s) for in-flight pollers. |
| Managed-Redis failover | Wake signals dropped → discovery falls back to long-poll timeout. `checks.cache.status` goes to **warning** and names `long_poll_wake_acceleration` as the degraded capability. `/api/ready` stays green while the durable database remains available. Admission locks fall back to "allow"; query-task locks reacquired on next attempt. | Redis client reconnect interval. |
| API node loss (1 of N) | Load balancer removes the failed node within its readiness interval. A worker retrying the interrupted workflow, activity, or query poll with the same `poll_request_id` receives that poll's still-live lease without spending admission or dispatch budget. A different concurrent poll ID never receives the observed attempt. Clients without coordinated poll request IDs retain normal lease-expiry recovery. | Load-balancer readiness interval (operator-controlled, typically 5–10s), plus the client retry interval for coordinated polls. |
| Worker loss | Tasks held by the failed worker pause until lease expiry. Other workers continue claiming their own tasks. The engine never mutates run status to "recover" from worker loss. | Lease expiry (5 min for activity tasks per `ActivityLease::DURATION_MINUTES`), plus one `task_repair` cadence. |
| Scheduler/maintenance restart | Schedule fires pause; activity-timeout enforcement pauses; history pruning pauses. All three resume on the next tick after the orchestrator brings the runner back. No duplicate fires occur because the runner is a singleton. | Orchestrator restart latency, plus one `ScheduleManager::tick()` cadence. |

These bounds are the engine's contract — they hold whether the
acceleration layer is propagating signals or not. They do **not**
include the managed service's own promotion latency, the load
balancer's reconfiguration latency, or DNS propagation.

## Split-Brain and Duplicate-Scheduler Prevention

The contract requires the operator to preserve three invariants. The
engine's last-line defenses (per-row claim locks, lease holder
checks, per-schedule row lock on fire) reduce the impact of a
temporary violation, but the contract is built on these invariants
holding:

- **One writable database endpoint, always.** Managed failover MUST
  fence the previous primary (revoke write user, demote with
  `read_only=on`, sever replication, or restore from a known-good
  snapshot) before it can re-attach. RDS Multi-AZ, Aurora cluster
  failover, Cloud SQL HA, and Patroni all do this by construction;
  custom replication topologies MUST do it explicitly.
- **One scheduler/maintenance runner, always.** A second runner MUST
  NOT be started until the orchestrator confirms the first has
  exited. Running two concurrent schedulers temporarily — for
  example during a careless rolling update of the scheduler service
  — is **not** a supported topology. The per-schedule row lock will
  prevent duplicate fires, but activity-timeout enforcement and
  history pruning behavior under concurrent runners is not in this
  contract. Use orchestrator features (Compose `deploy.replicas: 1`,
  Kubernetes `Deployment` with `RollingUpdate.maxSurge: 0`, systemd
  with a host-level lease) to enforce the singleton.
- **Acknowledged-writes are durable.** The engine never returns a
  `2xx` for a write that has not committed. Any acknowledged start,
  signal, update, claim, completion, or schedule fire is durable
  through every event class above.

## Exact-artifact Rehearsal

The reusable baseline rehearsal is
`scripts/conformance/single-region-failover-published-artifacts.sh`, backed by
`docker-compose.failover-rehearsal.yml`. It requires an explicit public server
tag or digest, pulls it, resolves every runtime image to a repository digest,
and then launches only those immutable references. The Compose file has no
build section or product-source bind mount. The runner refuses local, rolling,
or non-public server references before any topology starts.

Run it from a clean host with Docker Engine, Docker Compose v2, Python 3.11 or
newer, and public registry access:

```bash
DW_SERVER_IMAGE=durableworkflow/server:<released-version> \
  scripts/conformance/single-region-failover-published-artifacts.sh \
  --result-dir ./failover-result
```

The runner connects to all three published endpoints through `127.0.0.1` by
default. When the runner itself is in a container that controls a Docker daemon
through a mounted socket, set `DW_FAILOVER_CONNECT_HOST` to the Docker host
gateway hostname or IP:

```bash
DW_SERVER_IMAGE=durableworkflow/server:<released-version> \
DW_FAILOVER_CONNECT_HOST=host.docker.internal \
  scripts/conformance/single-region-failover-published-artifacts.sh \
  --result-dir ./failover-result
```

The override is one hostname, IPv4 address, or IPv6 address, without a URL
scheme, path, or port. The runner adds IPv6 URL brackets when needed. Port
publication remains independently configurable with
`DW_FAILOVER_SERVER_A_PORT`, `DW_FAILOVER_SERVER_B_PORT`, and
`DW_FAILOVER_LB_PORT`.

The bounded CI invocation runs the same required failure cells with the
contract recovery limits and uploads
`single-region-failover-result.json`. Public CI runs it weekly and on manual
dispatch. Provider-specific rehearsals should invoke the same scenario and
result contract while replacing the Compose stop/start actions with the
provider's promotion API.

The rehearsal:

- proves a database interruption completes without any
  acknowledged-write loss. The test starts a long-running workflow,
  triggers the database failover, and asserts the workflow resumes
  from the last committed history record after promotion. Any
  acknowledged write before the failover must be present after
  promotion. The runner completes under the original workflow-task lease when
  it remains live. If database recovery crosses the advertised expiry, it
  verifies the original owner receives the typed `lease_expired` fence, then
  measures a separately bounded replacement-worker reclaim and exactly-once
  completion.
- proves a Redis interruption does not cause any
  acknowledged-work loss and surfaces `checks.cache.status=warning` with
  `long_poll_wake_acceleration` as the degraded capability, not as an
  `/api/ready` failure. The test starts a workflow, triggers the
  Redis failover during steady-state polling, and asserts the
  workflow continues making progress at the long-poll cadence, including a
  request-ID poll retried through the other API node as the same durable lease, and
  resumes accelerated discovery within the recorded recovery bound after the
  cache reconnects.
- proves losing one API node mid-traffic does not produce
  acknowledged-write loss. The test claims a workflow task through one node,
  stops that node, and accepts shared-endpoint traffic only when the workflow
  and run identity match and the public run-status fields consistently describe
  a nonterminal run. It then completes the acknowledged task through the
  surviving node and verifies the same run reaches `completed`. On failure, the
  result retains the last redacted run description together with Compose state
  and bounded load-balancer and survivor logs.
- proves worker lease loss preserves the running workflow until the configured
  lease expires, then reclaims and completes it without mutating workflow input.
- proves the scheduler/maintenance runner can be stopped and
  restarted on a different host without firing duplicate schedules
  and without leaving any schedule unevaluated past its
  `next_fire_at` plus one tick. The test creates a schedule with a
  short `next_fire_at`, kills the scheduler runner before its tick,
  starts a new runner on a different host, and asserts exactly one
  fire is observed.

The result uses schema `durable-workflow.v2.single-region-failover.result` and
records, for the operator's recovery packet:

- the elapsed wall-clock recovery time for each event class;
- the observed substrate behavior (no acknowledged-write loss,
  bounded discovery latency on Redis failover, bounded traffic
  removal interval on API node loss, bounded resume latency on
  scheduler restart);
- the configuration of the database, Redis, queue worker, load balancer, and
  scheduler orchestrator at the time of the rehearsal so the
  evidence is reproducible;
- the requested image references, resolved repository digests, runtime image
  IDs, Docker/Compose/Python versions, and a hash of the normalized Compose
  configuration;
- resolved probe endpoints, Compose service state, published-port mappings,
  and bounded readiness observations when topology startup fails;
- every phase outcome, readiness transition, workflow/run/task/schedule
  identity, duplicate/loss assertion, measured recovery time, and bound
  verdict.

The baseline uses one MySQL container to exercise the engine-visible outage
class; it does not claim to validate a cloud provider's promotion mechanism or
promotion time. An operator claiming managed MySQL, PostgreSQL, or Redis HA
must retain provider-native promotion evidence alongside this result.

A deployment that has not run the rehearsal is not yet self-serve
under this contract; it remains support-led until the rehearsal
evidence is recorded in the operator's recovery packet and refreshed
on the cadence published in the
[Operator Operating Envelope](https://durable-workflow.github.io/docs/2.0/operator-operating-envelope).

## Boundary Against Unsupported HA Claims

The single-region HA contract is intentionally narrow. The following
remain **outside** it and continue to require a support-led design
pass; the topology itself is part of the product risk:

- Active/active multi-writer database topologies (no engine primitive
  models conflict-free claim fencing across two writable substrates).
- Automatic or hands-free regional failover. Active/passive
  multi-region with operator-driven regional failover lives in
  [`docs/multi-region-validation.md`](multi-region-validation.md);
  hands-free is not in that contract either.
- Synchronous cross-region database replication (RPO=0).
- Duplicate scheduler/maintenance runners as a steady-state topology.
- Engine-enforced region-pinned task queues as a routing axis.
- Multi-cluster Helm topologies and provider-specific managed-Kubernetes
  validation. The single-cluster self-serve Helm contract lives in
  [`docs/helm-validation.md`](helm-validation.md) and
  [`k8s/helm/durable-workflow/`](../k8s/helm/durable-workflow/); provider-specific
  or multi-cluster HA on top of it remains a support-led design pass.
- Strong "five-nines" or "zero-downtime" SLA promises beyond the
  bounded recovery times above. The contract is *bounded recovery
  during named events*, not an uptime promise that depends on the
  operator's database, network, and orchestrator choices.

The line is intentional: anything in this document is a self-serve
guarantee backed by the engine contract and the rehearsal evidence;
anything in the list above remains support-led. Marketing and SLA
language for self-hosted deployments MUST NOT cross that line
without dedicated validation.
