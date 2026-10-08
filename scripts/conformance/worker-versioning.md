# Published worker versioning

The existing `worker-versioning-published-artifacts.sh` command covers Server
routing, PHP/Python protocol clients, CLI controls and Waterline visibility.
Set `DW_RUST_SDK_VERSION` to add the managed Rust worker cases. Cargo and Rust
1.86 or newer are required in the runner environment. The selected Rust result
must pass for the aggregate result to pass.

For a focused Rust check, use the **Published Rust worker versioning** Action.
Select an exact Server version, its immutable image digest and exact Rust, PHP
and Python SDK versions. It starts an isolated MySQL/Redis/Server stack, verifies
the image's release metadata and runs both the Rust cases and four mixed build
cohorts. It removes the stack and consumer image even on failure. Results,
service logs, worker observations and package provenance are retained for 30 days.

The same disposable topology can run on a Docker host with
`bash scripts/conformance/worker-versioning-rust-host-published-artifacts.sh --result-dir DIR`.
Set `DW_SERVER_VERSION`, `DW_SERVER_IMAGE` and `DW_RUST_SDK_VERSION` first.
Add `DW_WV_MIXED_COHORTS=1`, `DW_PHP_SDK_VERSION` and `DW_PYTHON_SDK_VERSION`
to run the mixed cases as well. The host command builds an application image
using Composer and pip registry packages, plus the compiled Rust registry consumer.

To run against your own disposable published Server stack:

```bash
export DW_RUST_SDK_VERSION=<exact-crate-version>
export DW_SERVER_VERSION=<exact-server-version>
export DW_SERVER_IMAGE=durableworkflow/server@sha256:<image-digest>
export DW_WV_SERVER_URL=http://<isolated-server>:8080
export DW_WV_AUTH_TOKEN=<test-token>
export DW_WV_NAMESPACE=rust-worker-versioning
export DW_WV_RESULT_DIR=<result-directory>
export DW_WV_RUN_ROOT=<scratch-directory>
node scripts/conformance/worker-versioning-rust-published-workers.mjs
```

The token must allow namespace creation through the control-plane API. This is
an application fixture compiled against crates.io, with no SDK checkout or patch.
It creates four short workflows across two isolated queues and registers build cohorts. Worker calls
use the SDK's `Worker` and authored workflow callbacks. API requests promote the
build and inspect worker identities, run pins, diagnostics and durable history.

## Required outcomes

- Worker registrations expose the actual Rust SDK and each build identity.
- With an original v1 signal queued and v2 promoted, draining v1 blocks both
  compatible and incompatible delivery. API state shows the drain, absent active
  workers and retained ready backlog. Repeating drain preserves its timestamp.
  The SDK's normal managed loop exits from the drain response without a kill.
  Resume restores the build, and a fresh v1 worker cold-replays the original
  queued signal and recorded result without repeating the producer.
- An existing v1 run refuses v2 delivery. After promotion, new starts use v2
  while the existing run retains v1.
- Capacity-one cache eviction forces replay without repeating a side effect.
- Removing v1 exposes `no_compatible_worker` on the original run while v2
  continues polling without claiming its task.
- A restored v1 worker reaches the next authored signal boundary, receives an
  actual SIGKILL and is replaced by a different process with an empty cache.
  The original run and recorded result survive, with one side-effect record and
  one terminal completion. Terminal history is removed from the worker cache.

The six observations are checked before a passing result is written. Missing
observations and command failures return a nonzero exit status. Worker processes
are reaped on fixture exit. Remove the disposable stack and scratch directory
after retaining the small result files. Do not point this experiment at a
customer namespace or a shared production database.

## Mixed build cohorts

The four cases are Rust v1/PHP v2, PHP v1/Rust v2, Rust v1/Python v2 and
Python v1/Rust v2. Each uses two actual SDK workers with distinct build IDs.
SDK clients start a v1 workflow, promote v2 and start a new workflow. The original
run keeps its v1 build, while the new run uses v2. Both return their recorded
producer label, with one side-effect record and one completion each.

Before compatible delivery, the fixture holds that worker at its next SDK poll
and lets the incompatible worker poll twice. No task may be delivered or executed.
PHP/Python use their normal managed loops with a test transport gate and returned
task observations. Rust uses the SDK's managed `run_once` and authored callback
observations. Registry metadata must identify every actual worker and package.
The result validator rejects incomplete directions, foreign delivery, changed
run/build identity, repeated producers and duplicate completions.

These cases test build routing between separately registered definitions. They do
not claim that different language implementations can share a build identity or
that their histories can be replayed interchangeably. Divergent-code registration
and upgrading the Rust crate within a running cohort
remain separate gaps in the organization conformance audit.

Draining is a routing control, not cancellation of an existing workflow. A drained
SDK loop exits normally. Resuming its build allows compatible workers to claim
again, but does not restart an exited process. The drain case checks this absence
explicitly before starting a fresh worker, then verifies original run/build
identity, queued signal delivery, the recorded result and one terminal completion.

The aggregate command also requires a passing mixed result when
`DW_WV_MIXED_COHORTS=1` is selected. Supply the result from the focused host command
in the aggregate result directory. Missing or invalid selected observations fail
the aggregate check.
