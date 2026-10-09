# PostgreSQL storage development build observations

This bounded observation adds SQLx PostgreSQL, typed JSON/timestamps and
Rustls certificate verification to the native foundation under #325. It does
not qualify full-server compile cost, customer performance or a speedup.

Measured Rust sources/lockfile: `4996bb2381ff7155fe74c8a21c1f0c8eff922466`.
Linux amd64 uses the pinned Rust 1.99.0 image in
[`server-parity.yml`](../.github/workflows/server-parity.yml), with formatting
and Clippy components in a disposable derived image. Limits are two CPUs,
two GiB RAM without extra swap, two Cargo jobs and disabled development/test
debug information. Dependencies are downloaded before measurement; commands
run offline with an empty task-owned target directory. Image/dependency
downloads and container startup are excluded. The shared host has concurrent
work, so this is not a controlled comparison with earlier observations.

| Operation | Elapsed | Physical targets | Physical Cargo downloads/index |
| --- | ---: | ---: | ---: |
| Empty target: `cargo test --locked --offline --all-targets`, then `cargo build --locked --offline` | 86.755 s | 747.1 MiB | 306.3 MiB |
| Binary-only no-op build | 0.147 s | Same target | Same Cargo home |
| Binary-only build after a temporary source comment, following additional checks | 1.614 s | 921.3 MiB | 306.3 MiB |

An in-container nanosecond clock measures complete commands. `du -sk` measures
allocated filesystem blocks across each complete target/Cargo directory. The
timed default suite executes 17 codec/schema/SQLite execution cases, including
real SQLite migration-boundary subprocess kills. Backend-dependent PostgreSQL
tests are reported as ignored there and run separately: six real database cases,
including two acknowledged PostgreSQL migration-boundary SIGKILLs. Their elapsed
time is excluded from the build measurement. All checks and warning-free Clippy
pass. Checking artifacts increased targets to 888.1 MiB before the comment build.
The comment is removed and the original binary rebuilt afterward.

One target/Cargo home, bounded jobs and low debug information remain the local
development defaults. No Kache wrapper or shared cleanup daemon is enabled.
Remove task downloads, targets, derived/base images and database fixtures after
retaining thin evidence. The complete backend/capability rewrite still needs
its own build-cost and customer performance qualification.
