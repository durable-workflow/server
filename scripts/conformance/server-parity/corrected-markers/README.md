# Corrected marker actors

This is a separate installed cohort. Existing frozen actors and manifests stay
unchanged. The machine-owned manifest is
`tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts.json`.

`corrected-marker-adapters.sh` installs the locked PHP reader, the hashed genuine
historical Python producer and the locked Rust public worker. Rust builds use
one CPU, 1536 MiB RAM, no swap, debug=0 and no incremental artifacts. The build
cache and target are retired after copying the runnable adapter and lock.

The HTTP recorder executes a producer, a separate activity process and a cold
Rust successor. Each phase has an original 30-second observation timeout inside
a 90-second scenario budget; durable execution/run bounds are 1200 seconds.
The genuine Python case kills the real author after the committed activity
checkpoint. Its forwarded cancelled polls are explicit evidence, never outcome
receipts. Full response bytes, deliveries, histories, clocks and exact typed
values remain in recorder sidecars. `check-readers.mjs` rejects mutated copies
of actual observations, including missing bounds, rewritten prefixes, truncated
retry lists and false completion.

The embedded profile uses distinct normal Composer role locks. Only the genuine
duplicate producer uses the historical package; other originals and every cold
reader use the current package. Public clocks must match original marker
positions and cached reuse, and original task/run relationships remain exact.

The additional Rust modes `range_completed` and `range_pending` register the
original `parity.v1.range_repair` and `parity.v1.range_pending_repair` types and
queues. Their retry-policy argument is the actual original snapshot JSON. They
author every known field and interval explicitly, refuse unsupported input,
restore range `1..1`, and exclude the recorded decision with `2..2`. Pending
repair replays the original three-second timer winner and awaits its retained
activity handle. The separate published PHP-reference directions pass actual
admission, refusal and original-task repair; native admission remains required.

`rust-range-completed.json` and `rust-range-timer-pending.json` add that Rust
direction to the original PHP 2.2.7 recipes. They preserve the original fixture
definitions, typed values and whole 35-second scenario budget. A normal PHP
2.3.9 observer performs the one explicit repair; separate Rust processes reject
the incompatible range, restore the original bounds and finish the original
run. A genuine legacy absent retry snapshot remains absent; no current class
defaults supply missing fields. Every present snapshot still preserves all
known fields and the full list. Two separately queued real neighboring runs must retain their original
histories and tasks. The embedded adapter executes the original PHP definitions
and declares the Rust author direction inapplicable; it still checks the same
durable refusal and repair boundary. Actual recordings remain required before
the whole corrected cohort is qualified. The two PHP-reference recordings and
the original-definition embedded counterparts pass with independent database
readback and 100 actual-record negative controls. Those independent local
observations precede a matched native/six-database cohort; their recorded source
revisions, manifests and original failures remain distinct.

The five positive HTTP and embedded recordings are documented on the draft PR.
The separate published embedded policy-refusal original remains failed; its
coordinated package correction is Workflow #776. Current Python clock consumers,
native integration and the new profile's six-database/final/main matrix remain
pending. The first killed-author reader failures remain separate from fresh
confirming runs. Frozen actors and earlier fixture definitions stay unchanged.
