# SQLx MySQL hard-close patch

This directory contains the official `sqlx-mysql` 0.9.0 crate with one scoped
driver correction. Its published archive has SHA-256
`90b8020fe17c5f2c245bfa2505d7ef59c5604839527c740266ad2214acebea27` and VCS
commit `003b698e99e024f3621b8043a2426fde5b741171`.

`MySqlConnection::close_hard` drops the owned connection instead of awaiting
TLS shutdown. SQLx defines this operation as immediate closure without graceful
shutdown. Awaiting shutdown can retain a pool slot after a cancelled operation
and a timed-out reset when the peer's TLS reply is unavailable. Graceful close,
Rustls and certificate/hostname validation are unchanged.

The real regression in `rust/src/runtime/mysql_pool_tests.rs` preserves its
five-second reset and seven-second disposal budgets. It verifies fresh reads
after a cancelled BEGIN, a committed row after a lost COMMIT reply, disposal of
a stalled connection, replacement identity and preserved committed data.
The original and flush-before-gate TLS controls fail with the published driver;
both pass with this correction. Qualification and original evidence are in
[Server #429](https://github.com/durable-workflow/server/pull/429#issuecomment-6135933370).

All upstream source is retained. The only source-code change is in
`src/connection/mod.rs`. The packaged license-reference files are replaced with
the full MIT and Apache license texts from the same upstream commit. This file
records the patch; no upstream release is represented as containing it.

Remove this override when a reviewed published driver provides equivalent
hard-close behavior and passes the same database and TLS qualification.
