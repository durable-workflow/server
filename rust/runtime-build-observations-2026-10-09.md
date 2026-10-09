# First HTTP runtime build costs

These observations accompany [#330](https://github.com/durable-workflow/server/pull/330)
under [#325](https://github.com/durable-workflow/server/issues/325). They measure
the incomplete execution slice, not a release build or runtime performance gain.
The Rust source/lock was at `d82ecc3ccdea29902bcab309a43563b76d77d4ae`;
Later workflow/docs edits and an additional readiness test do not change the
runtime module or lock inputs. A temporary comment probe
was removed and source/lock hashes compared equal afterward.

The compiler image/toolchain is pinned by the manifest and shared Action.
The native experiment used two CPU cores, two GiB RAM, no extra swap, two Cargo
jobs and development/test debug information disabled. Dependencies were fetched
before timing. One task-owned target directory was reused; no Kache/global
wrapper or cache service was enabled. Shared-host background work varied, so
these are diagnostic build costs rather than statistically qualified results.

| Operation | Observed elapsed time | Physical target blocks |
| --- | ---: | ---: |
| Clean `cargo test --locked --offline --all-targets` plus `cargo build --locked --offline` | 54.650 s | 546,721,792 bytes (521.4 MiB) |
| Unchanged repetition of both commands, including real SQLite tests | 1.038 s | Same |
| `cargo build --locked --offline` alone after tests/Clippy | 0.159 s | 668,962,816 bytes (638.0 MiB) |
| Comment-only edit and `cargo build --locked --offline` | 1.513 s | 697,954,304 bytes (665.6 MiB) |

The clean Cargo compilation interval was 53.72 seconds; the measured total also
contains four codec tests, six real SQLite transaction/HTTP tests, command
startup and the following binary build. The subsequent unchanged combined
test interval includes about 0.74 seconds executing those SQLite tests. Clippy
added its own check artifacts, explaining the larger later target directory.
An earlier first compilation of the initial HTTP source took 57.15 seconds;
it did not yet contain the final six execution tests.

Cargo's fetched registry/source storage occupied another 212,402,176 physical
bytes (202.6 MiB). Clean target plus downloads totaled 724.0 MiB; after Clippy
and the comment probe they totaled about 868.2 MiB. Compiler/container image
layers and the published PHP SDK adapter's dependency tree are separate costs,
not included in target figures. The earlier codec-only Kache observations do
not establish its benefit for this larger crate; it remains optional.

Commands ran inside the same bounded compiler container, with `CARGO_HOME` and
`CARGO_TARGET_DIR` under one owned parent mount. `date +%s%N` bounded each Cargo
interval and `du -s -B1` measured allocated blocks. Container startup, dependency
downloads and compiler-tool installation are excluded. An initial `cargo clean`
against a target mounted directly at the container mount root failed to remove
that directory; no build interval from it is used. Mounting the owned parent
allowed normal cleanup and the retained successful measurement.

Thin logs, source identities, measurements and failures are retained with the
owning qualification record. Disposable targets, downloads, adapter dependencies,
compiler tool images and runtime resources are removed at the task handoff.
The full-server build and resource budget remains a port gate as capabilities
and databases are added. Do not extrapolate this slice to the final binary.
