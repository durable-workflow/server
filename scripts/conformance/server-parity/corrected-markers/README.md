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

Implementation is still a draft: embedded producers/readers, range-repair
phases, retry-policy refusal, current Python clock consumers, native integration
and the six-database source/final/main matrix remain pending. A metadata check
or one PHP observation does not qualify those boundaries. The first killed
author reader failures remain separate from fresh confirming runs.
