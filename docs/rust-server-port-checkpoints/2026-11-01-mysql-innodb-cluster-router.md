# Native on MySQL InnoDB Cluster behind MySQL Router (November 1)

A highly available native deployment on MySQL runs native 3.x on a MySQL 8
InnoDB Cluster behind MySQL Router. This checkpoint qualifies that topology
on one disposable host with the
[harness](../../scripts/perf/mysql-innodb-cluster/README.md). It is evidence
for the topology and its failure handling, not a capacity measurement.

## Conditions

- MySQL 8.4.13 (`mirror.gcr.io/library/mysql@sha256:a0225d1e…`), three
  members, single primary, default Group Replication settings (MySQL
  communication stack, `group_replication_member_expel_timeout` 5 s), 1 GiB
  and a 128 MiB buffer pool each.
- MySQL Router 8.4.10
  (`container-registry.oracle.com/mysql/community-router@sha256:d704471c…`),
  bootstrapped defaults; native uses the classic read-write port 6446.
- Native from this branch (main at `bbed2bec` plus the two fixes below), with
  `DW_RUST_MAINTENANCE_LOOP=1`, 512 MiB.
- Load: published Python SDK 2.12.2, one worker (eight workflow and eight
  activity slots), workflows of a 1 s timer then two activities.

## Results

| Run | Failure | Workflows completed | Starts refused (503) | Readiness non-200 | Integrity |
| --- | --- | --- | --- | --- | --- |
| Baseline | none | 60/60, p50 5.1 s | 0 | none | exact |
| 2 | primary crash (`docker kill`) | 106 of 107 started; the 107th made ready again at its activity lease expiry | 43 | +4.4 s to +40.7 s | exact |
| 3 | primary clean shutdown (`docker stop`) | 150/150 | 0 | one sample, ready again by +3.8 s | exact |
| 4 | primary hung (`docker pause`) | 74/74 | 16 | +0.5 s to +32.7 s | exact |

"Exact" means: every completed run has one `WorkflowCompleted`, one
`TimerFired` and two `ActivityCompleted` events, no `(run, sequence)` history
row is duplicated, and no workflow task or activity attempt is left leased.

- **Schema creation through Router** works: native created and verified its
  schema on a fresh database through port 6446.
- **Crash recovery time** (run 2, measured from the kill): Group Replication
  elected the new primary at +23.4 s, Router routed writes to it at +36 s,
  native's health answered 200 at +37.0 s and readiness at +40.7 s. Native
  adds 1 to 5 s after Router switches; the rest is election and Router's
  metadata refresh, which operators can tune.
- **Honest answers during the window**: with no primary, every request,
  including starts, answers `503 storage_unavailable`; readiness answers 503
  and health answers 503 or times out at the probe. Nothing answers 200 while
  writes cannot commit. Native reconnects without a restart; the scheduler
  loop logs `runtime_scheduler_storage_unavailable` once and resumes.
- **In-flight work**: an activity claimed 0.4 s before the crash could not be
  completed by its worker during the window. At its lease expiry native
  expired the attempt, failed the activity under its retry policy and made a
  workflow task ready, so the run resumes; nothing stays leased.
- **Client-visible errors**: the Python SDK's `start_workflow` and
  `result()` do not retry 503, so a caller sees `storage_unavailable` for
  calls made during the window (19 `result()` calls in run 2, whose runs did
  complete server-side). Callers should retry 503 with backoff.
- **Throughput is the driver's limit, not the failover's**: at 2 starts per
  second the single SDK worker saturates (a no-failover control had a 73 s
  median); run 4 used 1 per second.

A first crash run used Docker's embedded DNS, which drops a killed
container's name. The surviving members, on the MySQL communication stack,
then reported each other `UNREACHABLE` intermittently and lost quorum; no
primary was elected and the old one could not rejoin. With fixed addresses
and static host entries, as real hosts have, the same crash failed over
cleanly. Cluster members need names that keep resolving while a member is
down.

## Defects found and fixed

- **Activity poll without `server_time`** (any topology). Published PHP has
  answered `POST /api/worker/activity-tasks/poll` with a top-level
  `server_time` since 2.11.0; native did not. Python SDK 2.12.2 measures a
  claimed activity's lease against it and refused every activity native
  delivered (`ActivityLeaseAuthorityLost`, `invalid_activity_claim`), so no
  activity could run under that SDK. Native now adds `server_time` to every
  activity poll answer, leased or empty
  (`activity_poll_answers_carry_the_server_clock`).
- **Failover error classes**. MySQL errors a primary switch can surface are
  now classified transient: `backend_unavailable` for 1053, 1290, 1927, 2055,
  3100 and 4031, `backend_lock_pressure` for 3101
  (`mysql_cluster_failover_errors_are_transient`). Every database error
  already answered 503; the class drives the bounded diagnostic and the
  retryable worker deregistration receipt.

## Settings for operators

- **Connection**: `DB_CONNECTION=mysql`, `DB_HOST=<router>`, `DB_PORT=6446`
  (Router's classic read-write port, never the read-only 6447 or the
  read/write-split 6450), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, plus
  `DB_SSLMODE`/`DB_SSLROOTCERT` for TLS to Router.
- **Pool** (fixed in native): four connections per process, 5 s acquire
  timeout, connections tested before reuse, `lock_wait_timeout` and
  `innodb_lock_wait_timeout` of 5 s per session. Size Router's
  `max_connections` for four per native process plus headroom.
- **Probes**: readiness gates traffic. While no primary is reachable, health
  and readiness can take up to about 5 s to answer 503 (the pool's acquire
  timeout), so give probes a timeout of at least 6 s. Do not restart native on
  a database outage: health is 503 for the whole failover window and a
  restart does not shorten it. Expect 35 to 45 s of 503 after a primary crash
  with default Group Replication and Router settings, and a few seconds after
  a clean shutdown.
- **Router**: keep `connection_sharing` off (the default); native sets
  session variables on connect. Router's metadata `ttl` and Group Replication
  notifications, and Group Replication's `member_expel_timeout`, set the
  failover window.
- **Named locks**: native takes MySQL `GET_LOCK` only while bootstrapping or
  upgrading its schema and in upgrade takeover, never at runtime. Named locks
  are local to the member, so a failover during a schema upgrade releases the
  lock; run upgrades with one native process.

## Not covered

Multi-primary mode, Router read/write split, Router on a separate host or
several Routers, long-running load, cross-host network partitions, TLS to
Router, and the Rust and PHP SDKs.
