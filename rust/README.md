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
