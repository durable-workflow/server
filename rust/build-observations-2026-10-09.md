# First Rust crate build observations

These diagnostics cover the codec foundation at source
`8c15c9864601bc3beda52a968716071f11a5c0a2`, not the future complete HTTP/SQL
server. Toolchain and dependency inputs are committed in `rust-toolchain.toml`,
`Cargo.toml` and `Cargo.lock`. The compiler was `rustc 1.99.0
(b940084d7 2026-09-28)`, Cargo 1.99.0, from development base image
`rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0`.
The local tool image additionally installed that toolchain's rustfmt/clippy.

Builds used two Cargo jobs, a two-CPU container limit, 2 GiB memory with no extra
swap, and development/test debug information disabled. The development host has
four Intel i5-6500 cores, ext4 and SATA SSD storage. Dependencies were fetched
before timing: each command was `cargo test --locked --offline --all-targets`.
Network/download time, Docker startup and external host workload are not part of
the shell's reported Cargo wall time. All four focused tests passed in the valid
measurement windows. The shared codec/HTTP/embedded checks qualify correctness
separately; build timing is not a Server runtime performance result.

| Build | Cargo wall time, seconds | Complete disk observation |
| --- | --- | --- |
| Native, fresh target | 21.973 | 182.2 MiB target |
| Native, another fresh target | 21.903 | 182.2 MiB target |
| Native, unchanged target | 0.063 | 182.3 MiB target |
| Native, comment-only edit | 0.374 | 188.2 MiB target |
| Kache, empty cache and fresh target | 32.165 | 248.5 MiB across cache and target |
| Kache, warm cache and another fresh target | 3.462 | 410.6 MiB across cache and both targets |
| Kache, comment-only edit in first target | 0.925 | Subsequent complete disk walk retained separately |

Disk figures are allocated filesystem blocks from one `du` traversal, counting
shared hardlinks once across all listed roots. They are not file lengths or the
cache's registered-blob byte count. Registry/download state adds 46.9 MiB,
shared by all these builds. The development image and the Kache executable are
additional disk costs outside the table. The initial compile exposed the EOF
framing bug and failed a test; that failure is retained and is not a valid build
result. A post-edit disk walk raced a transient cache bookkeeping-file rename;
its error is retained, with a later quiescent walk for that state.

Kache was upstream release v1.0.0, with a 1 GiB local store budget and restore
digests verified. Automatic target cleanup, target sharing and orphan cleanup
were disabled. Cache and targets shared one mount; no global wrapper or host
daemon was installed. The warm build reported 90 local hits, 139,374,820 bytes
copied and 40,657,808 bytes restored without copying. Ext4 lacks FICLONE here;
hardlink reuse does not make every additional target free. Active targets can
also keep blocks beyond cache garbage collection.

The bounded result favors native Cargo for edits in one working tree. Kache
helps reconstruct a fresh target but adds cold-build time and retained disk.
Keep it optional for repeated fresh builds with an explicit cache budget and
cleanup. Repeat these measurements as HTTP/SQL dependencies arrive; these
small-crate timings do not establish full-server compile cost or universal gains.
