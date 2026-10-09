# Full-schema development build observations

The version-2 SQLite foundation retains the frozen published PHP schema and
adds SQLx migration support. This is a bounded build observation for #325,
not a full-server cost, performance qualification or cache-saving claim.

The measured Rust sources and lockfile are at
`4bc8c41d89f2c8931f390ebe3db3598f98a72f44`.
The Linux amd64 toolchain uses the same pinned Rust image as
[`server-parity.yml`](../.github/workflows/server-parity.yml): Rust 1.99.0,
edition 2024, two CPUs, two GiB RAM without extra swap, two Cargo jobs and
development/test debug information disabled. Formatting/Clippy components are
installed in a disposable image. Dependencies are already downloaded; builds
run offline. Image download, dependency download and container startup are
excluded. The shared development host has other work, so these results do not
isolate the migration feature's causal cost against earlier observations.

| Operation | Elapsed | Physical targets | Physical Cargo downloads/index |
| --- | ---: | ---: | ---: |
| Empty target: `cargo test --locked --offline --all-targets`, then `cargo build --locked --offline` | 64.445 s | 541.9 MiB | 177.6 MiB |
| Binary-only no-op build | 0.137 s | Same target | Same Cargo home |
| Binary-only build after one temporary source comment | 0.823 s | 550.9 MiB | 177.6 MiB |

Shell `time` measured the complete commands inside the limited container;
`du -sk` measured allocated filesystem blocks across each whole directory.
The clean command includes 15 passing codec/schema/execution tests and real
subprocess interruption. One ignored child entry is invoked by the parent test
at two acknowledged transaction boundaries; it is not an unexecuted recovery
case. The temporary comment was removed and the original binary rebuilt.
Formatting and warning-free Clippy also pass. Further checking/incremental
artifacts consume additional space and are included in cleanup receipts.

Keep one task-owned target/Cargo home, bounded build jobs and low debug
information. The earlier [Kache experiment](build-observations-2026-10-09.md)
remains optional; this measurement uses ordinary Cargo incremental builds.
Remove disposable targets/downloads and the temporary tool image after retaining
thin evidence. The full backend/capability port still needs its own build-cost
observations and customer performance qualification.
