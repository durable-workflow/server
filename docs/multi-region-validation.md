# Multi-Region Validation

This note records the public self-serve contract for running the standalone
Durable Workflow Server across regions. The contract is deliberately narrow:
it documents one supported topology — **active/passive with operator-driven
regional failover** — and pins the boundaries that the engine actually
enforces. Active/active and automatic regional failover remain outside the
self-serve boundary.

This document is the server-side view of the workflow library contract in
[`durable-workflow/workflow#docs/deployment/multi-region.md`](https://github.com/durable-workflow/workflow/blob/main/docs/deployment/multi-region.md).
The library contract names the engine guarantees; this document names the
operator contract for the standalone server image and Compose recipes.

The single-region HA contract — managed-database failover, managed-Redis
failover, API-node loss, worker loss, and scheduler-runner restart inside
one region — is documented separately in
[`docs/ha-failover-validation.md`](ha-failover-validation.md). The
multi-region contract assumes the single-region HA contract holds inside
the active region and does not duplicate its rules.

## Decision

Proceed with a narrow active/passive multi-region contract.

The first public multi-region shape is:

- One **active region** running the validated single-region or
  small-cluster contract: 1+ API container(s), shared MySQL or PostgreSQL,
  shared Redis, exactly one scheduler/maintenance runner, external
  workers.
- One **standby region** holding an asynchronously replicated standby
  database, optional standby Redis, no scheduler, and zero or more idle
  API/worker containers.
- A **regional failover** that promotes the standby database, starts the
  singleton scheduler in the standby region, switches worker and operator
  endpoints, and shifts external traffic — performed by the operator,
  not by the server.
- A **failback** that runs the same sequence in reverse once the original
  region returns to service, with the recovered primary fenced before
  re-attaching as a standby.

Do not document active/active multi-region, automatic regional failover,
synchronous cross-region replication, cross-region active visibility, or
region-pinned task queues as supported until those paths have dedicated
validation.

## Rationale

The server's correctness substrate is the workflow database. Every
guarantee the server publishes — claim fencing, lease expiry, scheduler
correctness, rollout safety, mixed-build admission, deployment lifecycle
state, build-id rollouts — assumes a single writable workflow database.
That assumption is compatible with active/passive across regions because
the standby is read-only until it is promoted; it is not compatible with
active/active across regions because two writers cannot share these
guarantees without a multi-master substrate the engine does not model.

Redis is region-local acceleration. Wake signals, query-task queue locks,
and admission locks do not propagate across regions. The single-region
small-cluster contract already documents that pollers fall back to the
durable repair cadence when the acceleration layer is degraded; the
multi-region contract inherits that behavior unchanged within each
region.

The singleton scheduler/maintenance runner is the other boundary that
keeps active/passive narrow. The first multi-region contract requires
exactly one scheduler running across the entire deployment after
promotion: never two, never zero. The standby region's scheduler
container is stopped or scaled to zero in steady state; the failover
runbook starts it in the new active region after the database is
promoted. Two concurrent scheduler runners would violate the invariants
that `schedule:evaluate`, `activity:timeout-enforce`, and `history:prune`
already assume.

Active/active is **explicitly deferred** because it would require:

- a multi-master workflow database with conflict-free claim fencing;
- cross-region wake propagation that does not regress the single-region
  acceleration contract;
- a scheduler model that tolerates concurrent runners without duplicate
  fires;
- rollout-safety, deployment-lifecycle, and build-id rollout admission
  paths that observe both regions' fleet snapshots simultaneously.

None of those exist as engine primitives today. Publishing active/active
as a self-serve shape would silently weaken every guarantee the existing
contracts make.

## Operator Contract

When a deployment claims this multi-region contract, the published
runbook must state at minimum:

- which region is currently active and how to discover that
  programmatically (e.g. DNS, traffic-management endpoint,
  `/api/cluster/info` on the active load balancer);
- the asynchronous replication topology between active and standby
  databases, including the configured RPO and the replication-lag
  alerting threshold;
- the list of containers running in each region in steady state — at a
  minimum, exactly one scheduler/maintenance runner in the active region
  and zero in the standby region;
- the worker endpoint configuration so workers can be redirected to the
  standby region without redeploying worker containers;
- the operator runbook for failover, failback, and split-brain
  prevention, plus the credentials required to fence a recovered
  primary.

Per-region API and worker containers continue to follow the
single-region contract:

- set a unique `DW_SERVER_ID` for each API node, including standby-region
  nodes that are pre-provisioned but idle;
- use the same auth tokens or signature keys, `APP_VERSION`, workflow
  package version, payload codec configuration, and Redis configuration
  shape across both regions, so a promoted standby is interchangeable
  with the original active region;
- set `DB_CONNECTION` to point at the region-local database endpoint;
  failover swaps which endpoint is writable, not which environment
  variable each container reads;
- keep database and Redis services private to the deployment in every
  region.

## Failover Sequence

The minimum sequence the engine relies on is the same one named in the
workflow library contract:

1. Stop write traffic to the failed region.
2. Confirm replication state against the published RPO.
3. Promote the standby database using its native promotion path.
4. Run any release-required migration or bootstrap commands on the new
   primary.
5. Start the singleton scheduler/maintenance runner in the new active
   region.
6. Switch worker endpoints to the new active API endpoint.
7. Switch operator and external traffic to the new active region.
8. Rebuild any derived projections or external visibility exports.

A failback runs the same sequence in reverse, with the recovered
primary fenced (revoke write user, demote with `read_only=on`, sever
replication, or restore from a known-good snapshot) before re-attaching
as a standby.

## CI Harness

Multi-region operation is validated as a **runbook contract**, not as a
container-level CI smoke. The Phase 0 harness for this contract is the
existing single-region small-cluster smoke
(`docker-compose.small-cluster.yml`, `scripts/smoke-small-cluster.sh`)
plus an explicit failover-rehearsal acceptance test that operators run
against their own database replication topology before declaring the
deployment self-serve.

The rehearsal acceptance test, at minimum:

- proves the standby database can be promoted, including the database
  bootstrap or migration step required by the running release;
- proves a worker re-registers against the promoted region's API
  endpoint and resumes claiming tasks;
- proves the singleton scheduler/maintenance runner starts in the
  promoted region and the failed region's runner does not reconnect;
- proves an in-flight workflow run resumes from the last replicated
  history record after promotion;
- records the elapsed RTO and the observed replication-lag RPO at the
  moment authority was withdrawn from the failed region.

A deployment that has not run that rehearsal is not yet self-serve under
this contract; it remains support-led until the rehearsal evidence is
recorded in the operator's recovery packet.

## Unsupported Until Proven

These remain outside the public multi-region support boundary:

- Active/active multi-region execution.
- Automatic or hands-free regional failover.
- Synchronous cross-region database replication (RPO=0).
- Cross-region active visibility, federated search attributes, or
  cross-region history merge.
- Region-pinned task queues or region-aware namespaces as a routing
  axis enforced by the engine.
- Multi-cluster Helm topologies and active/active cross-region database
  topologies. The single-cluster self-serve Helm contract lives in
  [`docs/helm-validation.md`](helm-validation.md); provider-specific
  managed-database failover *inside* one region (RDS Multi-AZ, Aurora cluster
  failover, Cloud SQL HA, and equivalents) is supported by the single-region
  HA contract in [`docs/ha-failover-validation.md`](ha-failover-validation.md).
- Strong cross-region SLA promises beyond the documented active/passive
  failover behavior.

These continue to require a support-led design pass; the topology
itself is part of the product risk.
