# Rust Server development

This crate is an unpublished development foundation for [Server #325](https://github.com/durable-workflow/server/issues/325).
The published PHP image remains the default. There is no Rust HTTP server,
database takeover or qualified runtime yet. The first module adapts the immutable
Avro Value schema with Apache's official library; it is not a durable execution
or full ingress qualification.

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
CPU-heavy async deadlines, databases, shared workflow fixtures and safe upgrade
remain acceptance gates in the [port log](../docs/rust-server-port.md).
Kache remains an optional task-scoped experiment, with an explicit disk budget;
no global Cargo wrapper or cleanup daemon is installed.
The [first build observations](build-observations-2026-10-09.md) record clean,
no-op, comment-edit and cache results with their disk costs and limits.

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
external payloads require the existing negative corpus before HTTP integration.
