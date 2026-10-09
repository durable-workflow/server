# MySQL and MariaDB execution development build observations

This observation covers the shared SQLite/PostgreSQL/MySQL echo/activity slice
under #325. It does not qualify full-server build cost or runtime performance.
Measured Rust sources and lockfile: `baa7da054c2bae8ca9e6d7d981676500226f1298`.

Linux amd64 uses the pinned Rust 1.99.0 image in the shared Action. Limits are
two CPUs, two GiB RAM with no extra swap, two Cargo jobs and disabled development
and test debug information. Cargo's development incremental compilation remains
enabled. Dependencies are downloaded first; the initial target is empty.
Bash measures complete offline Cargo commands inside the container, excluding
container startup and downloads. `du -sk` measures allocated blocks for complete
target and Cargo directories. Concurrent host work makes these observations
unsuitable for a controlled comparison with earlier slices.

| Operation | Elapsed | Physical targets | Physical Cargo state |
| --- | ---: | ---: | ---: |
| Empty target: `cargo test --locked --offline`, then `cargo build --locked --offline` | 89.438 s | 865.1 MiB | 306.4 MiB |
| Binary-only no-op | 0.146 s | 865.1 MiB | 306.4 MiB |
| Binary-only build after a temporary source comment | 1.031 s | 879.9 MiB | 306.4 MiB |

The clean command passes twenty codec/schema/SQLite execution cases, including
real SQLite migration-boundary subprocess kills. Database-dependent tests are
explicitly run separately and are excluded from the build timing. Hosted
qualification passes seven storage cases and nine common HTTP cases on each
of MySQL 8.0, the existing PHP MySQL matrix image and MariaDB 10.11. The HTTP
suite's additional PHP-file refusal case remains SQLite-specific. All three
also pass the unchanged PHP/Rust/embedded fixtures, real native-node activity
kill/replacement, PHP-data refusal and TLS checks. PostgreSQL and SQLite checks
remain passing in the same Action.

Commands and source edits ran sequentially. The temporary comment is removed
and the original binary rebuilt afterward. Separate checking artifacts reached
790.4 MiB before the empty-target measurement; warning-free Clippy passes.
The later incremental size includes retained outputs from prior commands.

One task-owned target/Cargo home, bounded jobs and low debug information remain
the local defaults. No global cache wrapper or cleanup daemon is installed.
Retain thin evidence, then remove task targets, downloads, images and database
fixtures. Full capability, upgrade, architecture and performance gates remain
open under #325.
