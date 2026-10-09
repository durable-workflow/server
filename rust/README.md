# Rust Server development

This crate is an unpublished development foundation for [Server #325](https://github.com/durable-workflow/server/issues/325).
The published PHP image remains the default. The opt-in Rust HTTP runtime now
executes the first echo/activity fixtures through an unchanged published SDK.
It is an incomplete development slice, with no qualified database takeover,
performance improvement or release. The codec module uses Apache's official
Avro library; HTTP forwards opaque envelopes without calling its unfinished
ingress decoder.

The toolchain is pinned in `rust-toolchain.toml`; dependency resolution is in
`Cargo.lock`. Compression and Avro schema-generation features are disabled.
Development/test debug information is disabled to bound artifact size. This
does not select release optimization or remove the later profiling requirement.

Run language tooling in a runtime container as the checkout's owner:

```sh
docker run --rm --user="$(id -u):$(id -g)" --cpus=2 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/tmp/cargo -e CARGO_BUILD_JOBS=2 \
  -v "$PWD:/source" -w /source/rust \
  rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0 \
  cargo test --locked
```

The pinned development image above is amd64. Supported release architectures,
CPU-heavy async deadlines, the complete database/fixture matrix and safe upgrade
remain acceptance gates in the [port log](../docs/rust-server-port.md).
Kache remains an optional task-scoped experiment, with an explicit disk budget;
no global Cargo wrapper or cleanup daemon is installed.
The [first build observations](build-observations-2026-10-09.md) record clean,
no-op, comment-edit and cache results with their disk costs and limits.
The [HTTP slice observations](runtime-build-observations-2026-10-09.md) record
its larger native build, test and dependency costs.

The shared [codec values](../tests/Fixtures/ServerParity/Codec/v1.json) declare
logical expectations from the immutable schema: exact long boundaries, finite
doubles (including negative zero), Unicode, binary values, nested arrays and
string-keyed maps. The existing long-zero corpus supplies the reviewed golden
wire. Map order is compared semantically; it is not a byte identity.
`cargo run --locked --example codec-fixtures -- FIXTURE [OTHER_OBSERVATIONS]`
checks Rust round trips and optionally decodes the other binding's bytes.
`scripts/conformance/server-parity/codec.php` verifies those bytes with the
published Workflow codec and emits PHP bytes for Rust to decode. The ordinary
HTTP/embedded execution fixtures run separately; codec checks do not replace them.

The framing tests reject truncated headers/datums, unknown fingerprints, trailing
bytes and non-finite doubles. Strict ingress parity is still open: malformed
collection blocks, duplicate map keys, recursion/allocation limits and streaming
external payloads require the existing negative corpus before enabling HTTP
datum decoding or qualifying ingress.

## First HTTP execution slice

Build with `cargo build --locked` in the same container/mount setup. Run the
binary with `DW_RUST_EXPERIMENTAL=1`, a nonempty `DW_AUTH_TOKEN`,
`DB_CONNECTION=sqlite` and `DB_DATABASE` naming its own isolated file.
`DW_BIND_ADDRESS` defaults to `127.0.0.1:8080`; set `0.0.0.0:8080` for a
development container network. Use the same UID and persistent database mount
across restarts. This opt-in does not authorize use as a production replacement.

The first slice uses Axum/Tokio for HTTP and SQLx for SQLite transactions, with
only the required features enabled and no build-time database or SQL macro
generation. Four connections and `BEGIN IMMEDIATE` serialize durable state
changes; SQLite runs on SQLx's connection workers. HTTP uses two Tokio executor
threads. WAL with full synchronous writes retains acknowledged state.
This is not qualification of throughput, CPU-heavy deadlines or multi-node HA.

Implemented paths cover health/schema readiness, limited capability discovery,
workflow start/describe/paginated history, worker registration/heartbeat/removal,
workflow/activity polling, workflow lease renewal and both completion paths.
`schedule_activity` and `complete_workflow` are the only admitted commands;
unsupported effects/options are refused before batch mutation. Published PHP
workers replay the persisted activity result and complete the workflow normally.
Discovery marks the runtime as development, advertises the admitted commands
and sets the major unimplemented worker capabilities to false. Accepted protocol
headers identify transport shapes, not a full protocol conformance claim.
Query polling explicitly reports no query capability. Other missing paths return
`rust_capability_not_implemented`; no missing operation is presented as success.

Starts, ordered history, task claims and outcomes are transactional. Expired or
revoked owner/attempt fences cannot commit. Identical completion retries return
the existing receipt without another outcome; conflicting retries are refused.
Poll IDs retain the original task/snapshot across process boundaries, with
expired receipts reclaimed. Worker/control-plane history is paginated, capped
at 1000 records and eight MiB of encoded payloads per page. Requests are capped
at three MiB and inline envelopes at two MiB. Full customer payload/resource
limits and external transport remain unqualified.

The native development bootstrap creates the complete SQLite schema from the
frozen published PHP image: 49 tables and 333 explicit indexes. Native completion
and poll receipts use separate relations. SQLx records a checksummed migration;
the ledger, schema and version-2 `dw_server_schema` marker commit atomically.
Concurrent fresh nodes serialize bootstrap. Startup checks the full catalog and
migration history read-only before enabling WAL or opening writable connections.
PHP/unknown databases, changed native catalogs/checksums and the old abbreviated
version-1 development schema are refused without conversion. Keep needed old
development data separately; this unpublished slice supplies no version-1
converter. Use a new isolated file for version 2.

This refusal is not the upgrade mechanism. Backup-first conversion of PHP's
stored representations and interrupted takeover remain required on all three
database families. The PHP ownership fence is merged in source, while the frozen
published PHP image still lacks it. **Never connect PHP to a Rust development database.**
The only enabled namespace/auth setup is `default` with the compatibility token;
scoped credentials, other namespaces and the full authorization contract remain
open. Timers, failure/retry policy, cancellation and the rest of the API also
remain open under #325. Existing databases and published PHP artifacts are
unchanged by this opt-in crate.

`cargo test --locked --all-targets` exercises file persistence, independent
connection-pool claims, stale fences, duplicate outcomes/polls, worker history
pagination, atomic unsupported-command refusal and read-only PHP refusal.
The shared Action builds once, cross-decodes the 15 codec cases, executes all
three reviewed fixtures against separate PHP/Rust/embedded databases, then
kills the Rust process with a leased activity. The restart probe lets the actual
lease expire, rejects the old claim and completes through a new published PHP
SDK worker, retaining original run/history IDs and one committed outcome.
That bounded kill check does not qualify all failure boundaries or databases.
