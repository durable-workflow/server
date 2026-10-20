# Native transition locking

This note records how the native runtime serializes durable transitions today,
what that costs under load, and the order of work toward finer-grained locking.
It is a design input for [Server #325](https://github.com/durable-workflow/server/issues/325),
not a contract change. Source references are to `rust/src/runtime/`.

## Current state

Every write transition calls `Store::begin()`, which takes one lock for the
whole database:

| Backend | Lock |
| --- | --- |
| MySQL and MariaDB | `SELECT … FROM dw_server_schema … FOR UPDATE` on the single ownership row (`backend.rs`) |
| PostgreSQL | `pg_advisory_xact_lock` with one fixed key (`backend.rs`) |
| SQLite | `BEGIN IMMEDIATE`, the database-wide writer lock (`backend.rs`) |

All completions, heartbeats, claims, signals, updates, cancellations, expiry
sweeps, timer firing and maintenance therefore run one at a time. This keeps
fencing simple. Leases, attempt checks and cross-run reads cannot interleave.

Reads that change nothing do not take the lock. Worker history pages, activity
status checks and run activity metadata listings each read one consistent
read-only snapshot (`Store::begin_read()`, `READ_SNAPSHOT_BEGIN` in
`backend.rs`). Control-plane reads such as describe and run history query the
pool without a transaction.

A held long poll takes the lock for its first probe, and afterwards only when
a lock-free read finds something to claim or refuse: an eligible task (or
query), a receipt for the poll's own request ID, or a removed, moved or
replaced registration (`Store::task_poll_ready` and `Store::query_poll_ready`). The read runs at once when a
commit on the same node wakes the poll. Otherwise it runs 50 ms after a probe
and then at doubling intervals up to 250 ms, so a poll observes work committed
by another server process within 250 ms. An idle poll therefore neither waits
for the lock nor delays transitions, and an unrelated commit does not send
every waiting poll to the lock.

## Measured cost

Under the CPU deadline-health profile on a local MySQL 8.0 cell, per-step
timing showed the timer pass waiting 178, 567 and 658 ms to acquire this lock
for timers that fired 424, 779 and 780 ms late. The expiry sweeps and the due
probe took about zero, and the firing transaction 69 to 234 ms. Changing the
scheduler from sleeping after each pass to a fixed tick made no measurable
difference. Deadline health and throughput under load are bounded by how long
other transitions hold the lock.

A scratch build that logged lock wait and hold per call site then ran the same
cell on a host shared with other work, so absolute times are approximate:

- A cancellation request held the lock 0.65 to 0.86 s, almost all of it
  waiting for the single codec permit behind describe decodes of the dense
  input (0.58 to 0.80 s). With a permit of its own the hold fell to 0.05 to
  0.09 s. Codec work under the lock now has its own permit.
- Activity status checks spent about 1.6 s per run waiting for the lock. They
  no longer take it.
- Cancellation delivery and expiry, workflow completions, heartbeats and
  activity expiry held the lock 0.2 to 0.9 s during the read wave without codec
  work, several times longer than the same transitions before it. Their summary
  projections took under 30 ms each. Lowering the decode thread's CPU priority
  changed nothing measurable. This remaining cost is not yet attributed.

## What lengthens lock hold time

- **Summary projection at commit.** `Transaction::commit` reorders open waits
  and re-projects the run summary for each touched run (`transaction.rs`).
  `summary_history` pages through the run's entire history to do so
  (`terminal_summary.rs`). Every heartbeat and claim therefore costs time
  proportional to history length while holding the lock.
- **Codec work inside transactions.** Five paths run Avro work on the blocking
  pool while the transaction is open: the rejected-signal command, signal
  arguments applied by a resumed workflow task, cooperative cancellation
  requests, task repair and fenced worker deregistration. They take
  `transition_codec`, never the read permit `signal_codec`, so they do not
  queue behind reads. The CPU work itself still runs under the lock.
- **Claim reads.** Workflow claims load the first history page (the requested
  page size, 100 by default) and compute the history budget under the lock.
  Query claims parse cached query JSON values.
- **External payload writes.** The local provider write and its SHA-256 run
  inside a payload-bucket transaction (`external_registry.rs`).

## Prerequisites for finer locking

These are correct only because of the global lock today:

- Task leases are written by task ID alone (`UPDATE workflow_tasks … WHERE
  id=…` in `store.rs`). Query leases rewrite cached JSON by key. A finer lock
  needs compare-and-set guards on status, lease and attempt, or row locking
  with `FOR UPDATE SKIP LOCKED` where every admitted database version supports it.
- Timer firing and cooperative deadline expiry each handle up to 50 runs in
  one transaction. They need one transaction per run.
- Worker registration and deregistration touch every task the worker leased,
  an unknown set of runs. They need a worker-scoped lock and per-run follow-up.
- Poll-receipt cleanup deletes across all namespaces on every claim. It belongs
  in maintenance.
- Workflow-ID reservation, schedule creation, namespace creation, the query
  cache and external payload quotas need namespace- or global-scoped locks.

## Proposed direction

- **Lock unit.** Use the workflow instance (namespace and workflow ID), not the
  run. Signals, updates, cancellation, queries, continue-as-new and repair all
  read the instance's current run and update instance counters.
- **Known multi-instance transitions.** Child completion, failure and
  cancellation also lock the parent instance. The link from child run to
  parent never changes, so both instances are known before the transaction.
- **Ordering.** Worker registration, then instance locks in ascending key
  order, all taken before the first read, then namespace-scoped locks, then
  payload buckets in ascending order, then task rows. The current claim order
  (task, then run) and upload order (bucket, then run fence) must be brought
  into this order first.
- **Snapshots.** Take every lock before the first plain read, or use locking
  reads. MySQL's REPEATABLE READ fixes the snapshot at the first read, and
  PostgreSQL's READ COMMITTED can permit write skew across runs.
- **SQLite.** SQLite allows one writer at a time. It keeps the global writer
  lock, and only shorter hold times improve it.
- **Codec permits.** Never wait for an instance lock while holding a codec permit.

## Order of work

1. Shorten hold times without changing locking. Move codec work out of
   transactions, and bound or make incremental the commit-time summary
   projection, with the existing projection corpus as its control.
2. Add compare-and-set guards to task and query leasing, split the 50-run
   batches into per-run transactions, and move poll-receipt cleanup to
   maintenance. These remain correct under the global lock.
3. Write the locking contract and its fixtures: concurrent completions on
   different instances, parent and child together, stale attempts, cross-node
   claims and deadlock-free ordering on PostgreSQL, MySQL and MariaDB.
4. Replace the global lock with instance locks behind that contract, then
   qualify deadline health under load and multi-node kills and restarts on all
   three database families.
