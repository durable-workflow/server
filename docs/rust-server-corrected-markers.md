# Corrected marker and range acceptance

[Server #325](https://github.com/durable-workflow/server/issues/325) owns this
separate shared PHP/embedded/native cohort after delivered
[#434](https://github.com/durable-workflow/server/pull/434#issuecomment-6143056720).
The [reviewed next boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6141676028)
records the supported producer inputs and their qualification limits. The draft
now has separate normal-install actors and strict readers. Five positive marker
cases and the two original range families pass independent PHP-reference and
embedded recordings. Current Python service-mode clock/UUID cases also pass the
PHP reference under their agreed fixed workflow-start contract, with two real
activity/cold-worker boundaries. The embedded explicit-policy refusal remains
failed until the separate qualified Workflow correction is published/adopted.
Both range families also pass separate native SQLite recordings at
`4f5d813a8`, with 56 actual-record corruption controls and independent SQL
readback. Native preserves published Rust replay failures without inventing
structured fields, repairs the original task, emits six-digit history clocks
and keeps genuine legacy policy absence. Its 81 execution and 33 unit cases
pass locally; 18 database unit cases are explicitly ignored there.
Native's candidate deadline slice snapshots all four authored timeout fields,
fences expired outcomes and heartbeat renewal, preserves the global deadline
across same-execution retry, and uses PHP's ordinary 300-second activity lease.
Local SQLite passes 85 execution and 35 unit cases, with 18 database unit cases
explicitly ignored. The pending deadline contract still requires actual shared
PHP/native/embedded recordings. Embedded UUID side effects, native marker/clock
SDK recordings and new matched six-database/final/main qualification remain
pending.
Earlier killed-author, reader-expectation and
poll-based repair failures remain separate from fresh confirming runs.

## Artifact and history boundary

Keep existing frozen profiles, actors, prefixes, clocks and failed records
unchanged. The new profile must declare one machine-owned artifact manifest
with exact published Server image, Workflow package, SDK archives/source
commits, installed source hashes and embedded Composer locks. Declare original
and replacement roles explicitly where a genuine historical producer needs a
different published package. A release receipt alone does not qualify the new
shared installed consumers.

Use normal public author APIs and actual backend admission. Historical duplicate
markers must come from genuine producers; constructed history belongs only in
reader corruption controls. Use copies of retained originals and preserve the
producer's event positions, command sequences, original task/run identities,
microsecond clocks, activity policy and immutable execution/run deadlines.
No invented timestamp, changed retry list or newly admitted workflow may stand
in for an incompatible original.

## Required cases

| Case | Required original and replay behavior |
| --- | --- |
| Fresh Rust numeric decision | Repeated `get_version` with `[-3,7]` returns the same numeric 7 and emits one marker. A genuine pending activity completes once through an independent activity process and cold wider-range author replacement. |
| Fresh zero and mixed helpers | Numeric zero, patch and deprecation helpers preserve their distinct return values, one frozen decision and original command sequence across cold replay. |
| Deprecation-first version one | A fresh deprecated marker records numeric 1; later patch/range helpers consume that original decision without another marker. Valid differing bounds remain distinct from a genuine duplicate producer. |
| Genuine differing-bound version one | A normally installed historical Python author emits two actual version-1 markers with bounds `[0,1]` and `[1,1]` before a real pending activity. A supported cold Rust successor preserves both physical positions and clocks, original bounds and one activity/result/completion. Embedded uses a separately declared genuine package recipe. |
| Full PHP retry snapshot | A compatible cold reader accepts every known stored field, including all eight explicit backoff entries in a one-attempt policy. A differing policy refuses; unused entries are not trimmed or treated as equivalent prefixes. |
| Activity range repair | A Rust excluding-range author refuses an original completed/pending-activity checkpoint without a terminal workflow failure or extra history. Explicit repair resumes that same original task and preserves the activity outcome. |
| Selected timer winner repair | A Rust excluding-range author refuses the original timer-winner/pending-activity checkpoint. Explicit same-run repair preserves the winning timer, original pending activity and surviving neighbors. No activity completion is inferred from a timer winner. |

Existing PHP numeric/duplicate-bound package cases remain part of the supported
producer inventory. Fresh helper deduplication and historical alias consumption
are separate checks. The service bridge records each valid submitted marker
command; a helper's fresh deduplication must not erase an older producer's
genuine duplicate history.

## Clock, retry and repair evidence

The published Rust worker reports retry-policy replay refusal as
`RustWorkflowTaskFailure`, with the exact reason and workflow sequence retained
in its message. It does not send structured `reason` or `sequence` fields.
Validate that actual format and the canonical backend replay-blocked state,
unchanged history, selected-run repair and original task/lease relationships.
Keep the first reader's structured-field assumption failure separate from any
fresh confirming run; no SDK or Server correction is implied by that mismatch.

Record whole public worker deliveries and raw histories before and after each
boundary. Clock/UUID APIs must derive from the original durable events through
normal installed workers; marker-prefix mock reads alone do not qualify full
clock/history replay or backend admission. Preserve original absolute clocks
inside each run and meaningful ordering and deadlines across comparison.

Python 2.5.13 has an explicitly agreed
[fixed workflow-start clock](https://github.com/durable-workflow/sdk-python/issues/146#issuecomment-6139915817).
PHP's per-command clock remains separate. The new Python service cases retain
every physical marker/activity microclock while checking that public `now()`
and UUIDv7 values use the same original start anchor across two cold processes.
The historical clock-case producer receives explicit public registration
retirement after its real SIGKILL and before activities resume. This is distinct
from the unchanged unfenced-kill marker case. Embedded UUID APIs record side
effects and need their own declared mode-specific qualification.

The [genuine Python/Rust producer receipt](https://github.com/durable-workflow/sdk-python/issues/103#issuecomment-6138432726)
supplies a real two-marker input. Its first 30-second terminal observer expired
before the independent reader completed. Activity task snapshots retain 49
repairs and the workflow successor retains eight. Keep these observations
explicit; do not infer a within-original-observer or zero-repair result. New
experiments declare their own fixed phase and overall budgets before admission
and retain every failed original without rebasing or replacement.

The [full retry-list agreement](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6138337325)
requires strict comparison of the entire valid snapshot. Preserve raw Avro
frames and decoded int64/null/Unicode values. Semantic policy inequality, missing
or malformed marker fields, inverted/excluding bounds, changed positions/clocks
and wrong-run repair must each receive an independent negative control.

## Delivery gate

Start with exact installed published identities and a real PHP/embedded
reference. Add separate adapters/fixtures and a strict reader using the existing
recorder interfaces. Qualify actual supported PHP/embedded/native cases on
SQLite, PostgreSQL 16/17, MySQL 8.0/8.4 and MariaDB 10.11, retaining source,
fixture, transport and physical-state evidence. Required runtime corrections
remain scoped and precede exact candidate, final-head and fresh-main acceptance.

This cohort does not qualify auth, external payloads, all consumers, migration,
performance, native images or release/cutover. Those remain explicit #325 gates.
