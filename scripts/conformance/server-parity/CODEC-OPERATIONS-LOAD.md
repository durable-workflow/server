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
reference headroom failure remains failed. Actual originals, record controls
and all-database qualification are pending. Passing the earlier activity-only
original does not pass these families. This is deadline health, with no matched
throughput/capacity, embedded CPU, migration or release claim.
