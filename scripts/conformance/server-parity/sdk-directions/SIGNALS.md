# Published SDK signal clients and cold authors

The reviewed additive definitions cover all nine PHP/Python/Rust signal-client
and workflow-author combinations. They reuse the timer/child published tuple
without changing prior fixtures or envelopes. Normal published client/worker
adapters, canonical embedded execution, recorder guards and all six database
profiles are implemented. Actual runtime qualification remains pending.

The original normal worker registers its signal declaration before the PHP
observer starts the original run. Three distinct author processes open the
first condition wait, cold-replay the first signal into a second wait, then
cold-replay both accepted signals and complete that same run. Two separate
published client processes send identical Unicode/int64/boolean/null values.

Use the normal instance-current-run signal API. Frozen Python returns no
receipt object and has no run selector; inspect the actual HTTP receipt and
durable history against the original run. Do not invent a Python return value
or change an SDK. The observer start is identified separately from the SDK
worker, and its actual request must follow successful original registration.

Require distinct accepted signal and command IDs, ordered acceptance/application
and cursor advances, original wait identities, full unchanged history prefixes,
original queue/run/namespace and lease authority, real registration/claim/fenced
completion receipts, exact typed Avro results and the stored result frame.
Retain complete phase wire sidecars. Canonical embedded execution uses direct
signal waits and explicitly marks SDK language transport inapplicable; preserve
its different full event inventory before comparing common signal semantics.
The common projection contains delivery and typed-completion semantics only.
Three distinct cold SDK author processes are HTTP-specific proof; embedded
execution explicitly marks those SDK transport roles inapplicable.

Qualification requires all six database configurations, exact installed package
and runner identities, inspected resource limits and independent runtime/wire
inspection. Reuse one shared SDK preparation and the native binary. The owning
job must clean the isolated profile through its always-run path.

Local source checks pass: 56 signal reader models, all 4,478 reader models,
PHP recorder regressions (5 tests/24 assertions), PHP/JavaScript syntax,
Python parsing, Rust formatting, shell syntax and the Docker isolation policy
with 72 regression tests. These checks do not establish SDK compilation or
completed durable signal execution. Qualification must inspect the actual
published processes, receipts, typed histories and complete retained wires.

These definitions do not qualify run-targeted calls, continuation/current-run
races, all signal policies, CPU-load fairness, performance or cutover.
