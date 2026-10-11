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
Normal accepted signal submissions require HTTP 202. Registration and start
retain HTTP 201; task claims and fenced completions retain HTTP 200. The
[first runtime discrepancy](https://github.com/durable-workflow/server/pull/415#issuecomment-6114095382)
was the new reader's incorrect HTTP 201 signal expectation, corrected to the
published PHP and native contract while preserving the failed record.

Require distinct accepted signal and command IDs, ordered acceptance/application
and cursor advances, original wait identities, full unchanged history prefixes,
including the frozen Rust author's `rust:condition-wait:0` and
`rust:condition-wait:1` occurrence identities in opened and satisfied waits,
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

Local source checks pass: 60 signal reader models, all 4,482 reader models,
PHP recorder regressions (5 tests/24 assertions), PHP/JavaScript syntax,
Python parsing, changed-file Rust formatting, shell syntax and the Docker isolation policy
with 72 regression tests. SDK preparation passes with exact source and package
hashes. The corrected reader diagnostically passes the unchanged first failed
PHP observation; complete durable signal qualification remains pending.
Qualification must inspect the actual
published processes, receipts, typed histories and complete retained wires.

The corrected HTTP-202 profile records all nine PHP and embedded directions
passing on PostgreSQL 17. Native records six PHP/Python-author directions,
then exposes its rejection of the normal Rust worker's optional authored
`condition_wait_occurrence_id`. The [contract decision](https://github.com/durable-workflow/server/pull/415#issuecomment-6114363122)
preserves that nullable string metadata and omits null/empty values as PHP
does. The native validator/payload correction and two database/HTTP restart,
duplicate/stale completion and invalid-type regressions are implemented;
fresh native compilation and all-six qualification remain pending. Earlier
failed records remain unchanged. The separate frozen SQLite patch baseline
pressure failure also remains recorded with its strict assertions intact.

These definitions do not qualify run-targeted calls, continuation/current-run
races, all signal policies, CPU-load fairness, performance or cutover.
