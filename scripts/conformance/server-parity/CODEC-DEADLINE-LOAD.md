# Avro read load and original activity expiry

This pending #325 HTTP qualification uses the published corrected actor
manifest beside the frozen default fixtures. It does not establish capacity,
performance improvement, embedded CPU behavior, or complete timer, lease and
cancellation health under load.

The installed PHP SDK admits one real dense input and checks its exact decoded
value. Eight independent readers repeatedly describe that same run for nine
seconds. They discard bodies and keep bounded status, byte, hash and timing
summaries. A separate process reads health and readiness with an absolute
two-second request timeout. Streaming bytes cannot keep that timeout alive.

Before releasing the read wave, the SDK admits and claims a different activity
with an authored four-second schedule-to-close timeout. Its first attempt,
policy and original run bounds remain fixed. Late completion, failure and
heartbeat requests cross that boundary during the read wave. The author keeps
the actual canonical timeout and parent delivery, then checks that the dense
input still decodes identically.

Limits are declared in
`tests/Fixtures/ServerParityPending/codec-deadline-load.json` before admission.
The service gets one CPU, 512 MiB and no swap. The preparation budget is 120
seconds, the activity-to-load handoff allows at most one second, and the
observer budget is 35 seconds. Requests retain a bounded response size.
Qualification requires actual service CPU samples during the load wave. A
healthy mock or a low-CPU workload cannot satisfy that gate. PHP headroom
failures remain failed reference observations rather than weaker native checks.

Run application commands inside the existing repository runtime or an isolated
container as its unprivileged owner. Prepare separate, already bootstrapped
PHP and native databases with the exact declared image/source and resource
limits. Install the corrected PHP actor normally from its unchanged lock.
The PHP reference uses `codec-deadline-maintenance.php` with the same fresh
`DW_PARITY_CODEC_PREFIX`. It boots the published package once and calls its
normal timeout enforcer only for this original's active activity. It never
repairs the retained parent task. These commands do
not bootstrap, patch, restart, backdate or repair a target.

```sh
export DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php
export DW_PARITY_RUNNER_REVISION=FULL_SOURCE_COMMIT
node scripts/conformance/server-parity/codec-deadline-profile.mjs \
  http://isolated-server:8080 unique-fresh-prefix \
  tests/Fixtures/ServerParityPending/codec-deadline-load.json \
  tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts-workflow-2.5.16.json \
  /evidence
```

Supply authentication through `DW_PARITY_TOKEN`, never an argument or output.
Collect actual target-container CPU and memory at the same time. The contract
command takes a JSON object with `resource_limits` containing inspected
`nano_cpus`, `memory_bytes` and `memory_swap_bytes`, and a `samples` array
with `observed_at` (UTC ISO timestamp),
`cpu_percent` (one CPU is 100%) and `memory_bytes`. Preserve the original
resource-limit inspection when qualifying that record. The busy samples must
surround the original expiry observation, rather than describing another phase.

```sh
node scripts/conformance/server-parity/codec-deadline-contract.mjs \
  /evidence/record.json \
  tests/Fixtures/ServerParityPending/codec-deadline-load.json /evidence/cpu.json
```

The optimized native SQLite original passes the unchanged workload and strict
contract: 91/91 dense reads, 88/88 health/readiness reads, measured busy CPU,
original timeout history and parent delivery, and refused late outcomes.
Maximum read times are 926 ms and 27 ms. The service has one CPU, 512 MiB and
no swap. A stopped read-only database audit confirms one activity attempt,
one timeout, and zero parent repairs. This is one bounded source qualification,
not a matched performance comparison or complete CPU/deadline qualification.

Run corruption controls against that actual passing record:

```sh
node scripts/conformance/server-parity/codec-deadline-controls.mjs \
  /evidence/record.json \
  tests/Fixtures/ServerParityPending/codec-deadline-load.json /evidence/cpu.json
```

The controls refuse 29 changes to original identity, typed values, clocks,
policies, fencing, reader outcomes and inspected resource/CPU evidence. They
also check that the profile preserves original int64 bytes. They do not create
another producer or replace the actual observation.

The first missing-header original fails. After the header correction, PHP's
development-server observation reaches OOM and read/health timeouts at the
declared 512 MiB limit. Its earlier explicit-repair maintenance invalidates
parent-repair qualification. A fresh Apache-entrypoint original uses the scoped
timeout enforcer and retains its original budget. It also reaches OOM: all
40 dense reads time out and 3/12 health reads time out. Its author is killed
at the original 35-second observer limit, with its prepared original checkpoint
intact. Its database audit is recorded separately in the owning PR. The native
development build keeps deadline fences
and healthy responses but fails the dense-read timeout gate. Those failed
originals stay failed. Optimizing the native artifact does not weaken their
workload or limits. Seven focused streaming/timeout/status controls run without
a target Server. Keep failed
originals readable in GitHub, then remove task-owned applications, dependency
trees, raw recordings and temporary load resources after the immediate audit.
