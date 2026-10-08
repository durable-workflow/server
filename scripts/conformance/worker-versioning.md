# Published worker versioning

The existing `worker-versioning-published-artifacts.sh` command covers Server
routing, PHP/Python protocol clients, CLI controls and Waterline visibility.
Set `DW_RUST_SDK_VERSION` to add the managed Rust worker cases. Cargo and Rust
1.86 or newer are required in the runner environment. The selected Rust result
must pass for the aggregate result to pass.

For a focused Rust check, use the **Published Rust worker versioning** Action.
Select an exact Server version, its immutable image digest and an exact Rust SDK
version. It starts an isolated MySQL/Redis/Server stack, verifies the image's
release metadata, runs the command below and removes the stack even on failure.
Results, service logs and the consumer's Cargo lockfile are retained for 30 days.

The same disposable topology can run on a Docker host with
`bash scripts/conformance/worker-versioning-rust-host-published-artifacts.sh --result-dir DIR`.
Set `DW_SERVER_VERSION`, `DW_SERVER_IMAGE` and `DW_RUST_SDK_VERSION` first.

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
It creates three short workflows and registers two build cohorts. Worker calls
use the SDK's `Worker` and authored workflow callbacks. API requests promote the
build and inspect worker identities, run pins, diagnostics and durable history.

## Required outcomes

- Worker registrations expose the actual Rust SDK and each build identity.
- An existing v1 run refuses v2 delivery. After promotion, new starts use v2
  while the existing run retains v1.
- Capacity-one cache eviction forces replay without repeating a side effect.
- Removing v1 exposes `no_compatible_worker` on the original run while v2
  continues polling without claiming its task.
- A restored v1 worker reaches the next authored signal boundary, receives an
  actual SIGKILL and is replaced by a different process with an empty cache.
  The original run and recorded result survive, with one side-effect record and
  one terminal completion. Terminal history is removed from the worker cache.

The five observations are checked before a passing result is written. Missing
observations and command failures return a nonzero exit status. Worker processes
are reaped on fixture exit. Remove the disposable stack and scratch directory
after retaining the small result files. Do not point this experiment at a
customer namespace or a shared production database.

This Rust shard does not cover mixed PHP/Rust or Python/Rust build cohorts,
drain/resume controls or divergent-code registration. Those remain separate
coverage gaps in the organization conformance audit.
