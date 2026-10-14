# Timer, heartbeat and cleanup health during Avro reads

This pending #325 profile extends the bounded activity-expiry read workload.
It uses the same 8,192-row input, eight readers, nine seconds, two-second absolute
request limit, one CPU, 512 MiB and no swap. Each additional installed SDK
observer prepares only an original leased workflow before the barrier. Timed
commands are admitted during the read wave, without changing any old original.

The timer observer waits for one actual three-second timer and completes its
original typed result. The heartbeat observer renews one ordinary activity
twice across its initial heartbeat boundary, checks non-renewing status and
completes the same attempt once. The cancellation observer requests and delivers
one original root cancellation, admits a separate cleanup timer, and lets the
original five-second cleanup deadline expire. Both canonical timer firing and
cleanup closure allow at most one second of lateness under measured busy CPU.
Control requests must finish within two seconds, and every timed boundary and
request must occur during the actual read wave.

Read processes acknowledge their actual start before timed observers are
released. Cleanup closure is observed through the existing canonical history
read, followed by a full typed snapshot and prefix check. A first original
fails because one admission precedes the read process's start by 0.5 ms. Its
stored cleanup closes 221 ms after the deadline, while repeated full descriptions
observe it 1.6 seconds late. That failed original stays failed. The new observer
does not change either deadline or timing limit.

The machine-owned specification is
`tests/Fixtures/ServerParityPending/codec-operations-load.json`. Use the existing
normally installed corrected PHP actor and resource/CPU observation procedure
from [the original load profile](CODEC-DEADLINE-LOAD.md). This specification
adds independent operation children to the same profile command:

```sh
node scripts/conformance/server-parity/codec-deadline-profile.mjs \
  http://isolated-server:8080 unique-fresh-prefix \
  tests/Fixtures/ServerParityPending/codec-operations-load.json \
  tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts-workflow-2.5.16.json \
  /evidence
node scripts/conformance/server-parity/codec-operations-contract.mjs \
  /evidence/record.json \
  tests/Fixtures/ServerParityPending/codec-operations-load.json /evidence/cpu.json
```

No runtime or SDK patch, database backdating, explicit parent repair or frozen
tuple change is allowed. The published PHP reference needs its normal timer,
activity timeout and cleanup watchdog paths scoped to these originals.
`codec-operations-maintenance.php` boots the installed package once, expires
only the two original activities, and invokes the cleanup watchdog only after
the original cancellation deadline is due. A real database queue worker handles
only the original timer/cancellation queues. Each supporting PHP role gets
0.25 CPU, 256 MiB and no swap, separately from the one-CPU/512-MiB Apache service.
Their resources are part of the reference description. This does not compare
complete runtime resource use with native. A
reference headroom failure remains failed.

The fresh native SQLite original on `7eef6c86e` passes all four families at the
unchanged limits. All 85 dense reads and 88 health/readiness reads succeed.
Canonical timer firing is 105 ms late, cleanup closes 92 ms after its original
deadline and is observed 138 ms after it. Two actual heartbeats renew the same
activity attempt across its original heartbeat boundary. A stopped read-only
database audit confirms the original histories and zero task repairs. The
actual record passes 58 corruption controls and keeps original int64 bytes.
The [qualified source outcome](https://github.com/durable-workflow/server/pull/443#issuecomment-6159555660)
also records the failed race/delayed-description original and the next run's
incorrect full source identity. Neither is used to qualify exact source.

The published PHP Apache reference fails the same declared workload with OOM
and dense-read timeouts. Cancellation gets a temporary database-lock refusal
without admission. Stored timer repairs and activity timeouts are observations,
not passing HTTP timer, renewal or cleanup qualification.

The reader now compares canonical deadlines using the shared nanosecond parser,
refusing a one-microsecond-early event or a one-microsecond excess over permitted
lateness. Raw prefixes and clock strings stay unchanged. Fresh other-database
execution must use this stronger reader; its small boundary controls do not
constitute another producer or requalify an old record.

The existing shared-fixture Action builds one locked optimized native binary
with one CPU, 2 GiB, one Cargo job and no swap. It deletes compiler caches and
shares only the binary and provenance manifest across six isolated cells. Each
cell uses the fixed database image/resource declaration in
`tests/Fixtures/ServerParityPending/codec-operations-databases.json`, verifies
startup before admitting an original, and runs the unchanged workload/readers.
After the strict readers and 58 corruption controls pass, it stops the service
and compares canonical histories and task attempts through a read-only database
transaction. Three controls refuse altered duplicate completion, manual repair
and conflicting status rows from that same actual SQL observation. Cleanup
retires only that cell's containers, network and database.
The Action keeps small outcomes/diagnostics for seven days, without DB dumps or
full typed sidecars. The local equivalent is:

```sh
bash scripts/conformance/server-parity/codec-operations-build.sh /fresh/native-bin
bash scripts/conformance/server-parity/codec-operations-cell.sh postgres-17 \
  unique-lowercase-scope /fresh/native-bin /normally-installed/php-sdk /fresh/result
```

Local source originals pass all six database configurations at their recorded
source revisions with the same native subtree and binary. The complete final
hosted matrix, published consumers and promotion remain pending.
Passing the earlier activity-only original does not pass these families.
This is deadline health, with no matched
throughput/capacity, embedded CPU, migration or release claim.
