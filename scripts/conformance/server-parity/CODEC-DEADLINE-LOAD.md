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

Actual complete PHP/native qualification and record corruption controls remain
pending. The first missing-header original fails. After the header correction,
PHP reaches an OOM event and read/health timeouts at its declared 512 MiB limit.
The native development build retains deadline fences and healthy responses but
fails the dense-read timeout gate. An optimized native build is the next
qualified-artifact candidate, with the same workload and limits. These
observations do not qualify the complete case or establish a performance number.
Focused streaming/timeout/status controls run without a target Server. Keep failed
originals readable in GitHub, then remove task-owned applications, dependency
trees, raw recordings and temporary load resources after the immediate audit.
