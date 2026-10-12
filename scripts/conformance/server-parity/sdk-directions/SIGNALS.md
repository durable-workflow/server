# Published SDK signal clients and cold authors

The reviewed additive definitions cover all nine PHP/Python/Rust signal-client
and workflow-author combinations. They reuse the timer/child published tuple
without changing prior fixtures or envelopes. Normal published client/worker
adapters, canonical embedded execution, recorder guards and all six database
profiles are implemented. The initial native correction passes all six runtime
configurations; final current-main integration qualification remains pending
in [#415](https://github.com/durable-workflow/server/pull/415).

The original normal worker registers its signal declaration before the PHP
observer starts the original run. The original worker's first workflow poll
is forwarded only after the matching real successful start receipt.
The byte-preserving observer holds that original request without changing its
bytes or synthesizing a result. Three distinct author processes open the
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
hashes. The initial [correction run](https://github.com/durable-workflow/server/actions/runs/38182926674)
passes all six database configurations, PHP/source and both boundaries.
Independent original SQLite inspection passes all 27 observations, binds all
90 HTTP sidecars and verifies 133 original/archive member hashes. Separate
MySQL 8.0 inspection passes 27 observations and 135 member hashes with actual
database limits and TLS checks. Both native outputs pass all 75 execution tests.
Complete signal profiles and thin SDK proof are retained; duplicate downloads
and scratch are removed. Final current-main strict qualification remains ahead.

The new start-order correction passes 61 signal models plus five actual-socket
transport tests, including unrelated/rejected starts, unchanged request/response
bytes and cancellation of a fully received held poll. The complete current
Node contract suite passes 4,681 tests, and the changed PHP recorder passes
syntax checking. Native Rust and installed SDK adapter sources are unchanged;
these source checks do not qualify the new runtime ordering.

Normal Rust authoring exposed native rejection of authored
`condition_wait_occurrence_id` and variadic signal arguments. The
[occurrence decision](https://github.com/durable-workflow/server/pull/415#issuecomment-6114363122)
retains nonempty string metadata through original wait satisfaction and omits
null/empty values as PHP does. The
[variadic decision](https://github.com/durable-workflow/server/pull/415#issuecomment-6114821113)
accepts zero or more tail values after required positional prefixes, validates
each value without coercion and respects nullability. Three HTTP/database
regressions pass for cold reopening/fences, optional metadata/types and exact
variadic values/refusals. Defaults, optional fixed prefixes and non-final
variadics remain unsupported; older-protocol occurrence routing is unqualified.
Earlier failed records and the separate frozen SQLite patch baseline pressure
failure remain unchanged with strict assertions intact.

The latest original SQLite run also catches a frozen-PHP startup overlap:
[retained failure and contract decision](https://github.com/durable-workflow/server/pull/415#issuecomment-6115559242).
Five directions pass; the sixth completes its 13-event original run, but its
first claimed `WorkflowStarted` lacks ten declaration fields present afterward.
The raw poll response precedes the successful start response. All 62 complete
failed-profile member/archive hashes pass; original native output separately
passes 75 tests, while embedded/native SIG profiles are not reached. The full
failure is retained and the [PHP finding handed off](https://github.com/durable-workflow/server/pull/420#issuecomment-6115559389).
The new first-poll ordering excludes this startup interleaving explicitly and
preserves all full-history checks. It requires fresh six-profile qualification;
earlier all-six passes do not qualify this changed fixture.

These definitions do not qualify run-targeted calls, continuation/current-run
races, all signal policies, CPU-load fairness, performance or cutover.
