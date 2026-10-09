# PostgreSQL execution development build observations

This observation covers the shared SQLite/PostgreSQL echo/activity execution
slice under #325, not full-server build cost or a customer performance gain.
Measured Rust sources and lockfile: `061d79b66ed769dc2a2b138463340a0d9aa7b079`.

Linux amd64 uses the pinned Rust 1.99.0 image in the shared Action. Limits are
two CPUs, two GiB RAM with no extra swap, two Cargo jobs and disabled development
and test debug information. Dependencies are downloaded first; the initial
target is empty. Bash measures complete offline Cargo commands inside the
container, excluding container startup and downloads. `du -sk` measures allocated
blocks for the complete target and Cargo directories. The host has concurrent
work; these numbers are not a controlled comparison with prior observations.

| Operation | Elapsed | Physical targets | Physical Cargo state |
| --- | ---: | ---: | ---: |
| Empty target: `cargo test --locked --offline`, then `cargo build --locked --offline` | 83.054 s | 787.0 MiB | 306.4 MiB |
| Binary-only no-op after incremental samples | 0.145 s | 825.5 MiB | 306.4 MiB |
| Binary-only build after a temporary source comment | 1.911 s | 825.5 MiB | 306.4 MiB |

The clean command passes twenty codec/schema/SQLite execution cases, including
real migration-boundary subprocess kills. PostgreSQL storage tests are explicitly
run separately, not included in this build timing. The same execution suite
passes nine common cases on PostgreSQL; its one PHP-file refusal case remains
SQLite-specific. Both pinned PostgreSQL versions also pass the unchanged
PHP/Rust/embedded fixtures and an actual native-node kill/replacement check.

Two initial incremental samples overlapped a source edit and build and were
discarded. The reported incremental timings use sequential completed commands;
their directory size includes retained incremental outputs from earlier samples.
The temporary comment is removed and the original binary rebuilt afterward.
Separate checking artifacts reached 955.2 MiB before the clean measurement.
Warning-free Clippy passes.

One task-owned target/Cargo home, bounded jobs and low debug information remain
the local defaults. No shared cache wrapper or cleanup daemon is installed.
Retain thin evidence, then remove task targets, downloads, images and database
fixtures. Full capability, database and performance qualification remain open.
