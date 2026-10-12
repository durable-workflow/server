# Published SDK query directions

This cohort exercises nine PHP/Python/Rust query-client and workflow-author
combinations on PHP Server, native Server and canonical embedded PHP authors.
It keeps the signal cohort's exact published package tuple and existing resource
limits. HTTP runs use the default namespace and serialized transitions.

CI overlaps the independent signal/query profiles, then awaits both actual
exits and fails if either fails. They use separate identities, queues, PHP and
embedded databases, containers and complete evidence, sharing the existing
native deployment/build. Each application/driver keeps its 512 MiB/one-CPU cap;
database caps and job deadlines stay unchanged. This scheduling has no
performance claim.

The original normal worker registers its workflow, signal and zero-argument
`state` query before an observer starts one run. Its first workflow poll follows
the matching successful start receipt. Three cold author processes serve the
committed checkpoints before signals, after the first resumption and after final
completion. Each author overlaps its independent normal query client until the
real query completion; each process retains a separate complete HTTP journal.
The observer takes each checkpoint snapshot after the matching original
workflow task's actual successful completion receipt, bound to task, run, lease
owner, attempt and request index. Updated history alone cannot release that
checkpoint before the command's final run projection is acknowledged.

Two ordinary PHP signal clients provide the same exact signal map between reads.
The query result is `{"received": 0|1|2, "last": null|<signal map>}`, preserving
Unicode, boolean, null and int64 9007199254740993. Queries leave every original
durable field and history unchanged. The reader binds declarations, original
run and queues, complete claimed snapshots, unique tasks, attempts, lease
owners, real completion receipts, result frames and language-specific results.

The published PHP/Rust clients return decoded values. The frozen Python client
returns its hydrated response object and omits input for zero arguments.
Python's query worker replays the workflow; PHP/Rust use immutable snapshot
handlers. Callback counts remain explicit rather than being forced to match.
Raw `actions.can_query` remains recorded as computed worker availability. When
present, its boolean shape and presence stay checked; this one flag can change
as a cold author deregisters. Every other action, original run field, wait
reason, progress time and control-plane copy remains part of strict unchanged
snapshot comparison. Broader query-availability/routing policy remains open.
Public history exposes sequence/type/payload/timestamp, while worker histories
also carry database row IDs and task/command links. The reader binds those
worker fields across actual workflow/query claims and repeated complete query
prefixes, requiring unique nonempty IDs and exact public timestamps. It does
not expect an unexposed row ID in the public API. A successful HTTP 200 query
worker receipt may omit `reason` or send null; a supplied error reason fails.
Embedded authors run their normal zero-argument method over canonical direct
signal histories, without HTTP worker transport.

Current recorder preparation passes 71 reader models and the full 4,758-test Node suite,
PHP syntax, Python AST, Rust fixture formatting, shell syntax and the Docker
isolation policy with 72 regressions. Twenty-one PHP recorder tests pass 97
assertions, including root empty-object/array distinction, exact int64 values
and cancelled partial/fully received bodies; the feature workflow includes the
new parser regressions. These are source/model checks. No new
SDK compilation is required for these recorder-only corrections. The initial
compiled SDK preparation is independently audited; the frozen PHP/PHP query
direction completes its three reads and original 13-event run, but its
unchanged initial record remains failed on snapshot validation. Embedded/native
queries were not reached. Fresh full-cohort runtime qualification is pending.
The first recorder correction passes PHP/source (2,696 tests/61,322 assertions)
and SDK artifact integrity, but its original PostgreSQL 17 query record stays
failed on the public-history ID assumption. The corrected reader passes a
complete diagnostic replay of that untouched PHP/PHP record; this does not
qualify either source tree or substitute for a fresh runtime run.

The corrected reader reaches the ordinary Python omitted-input disagreement
in the original SQLite records: nine PHP and nine embedded directions pass,
and native PHP/PHP completes its 13-event run. Native PHP author/Python client
then sends a complete uncancelled `{}` request and receives HTTP 422 with
`unsupported_request_field`. Its original run stays waiting with three events
and an unchanged full snapshot. That failed source and all original wires stay
retained; the SDK is unchanged.

The native correction now encodes an omitted zero-argument request through the
official Avro Value codec on the existing bounded blocking path. Only a root
empty object takes this path. Supplied envelopes, unknown fields, root
arrays/null and required parameters keep their validation. A new HTTP/database
regression completes omitted and explicit-empty queries, checks exact worker
arguments/results and full original state/history, and rejects fifteen invalid
requests without creating query-cache entries. Formatting and all 76 native
execution tests pass on inspected SQLite and MariaDB runs. No JSON payload fallback or
tuple advance. Current main, including General's PHP recovery correction, is
integrated for this next qualification.

Fresh SQLite independently passes all 27 query observations. The same all-six
run still fails five database jobs. Inspected MariaDB stops in PHP reference
recording with `spawnSync php ENOBUFS` after three passing directions: all eight
actors for the fourth fixture exit zero, but complete retained output exceeds
the recorder's 4 MiB pipe buffer. Its original record stays runner-blocked;
native query recording was not reached. File-backed probe capture now admits
complete stdout up to 64 MiB and stderr up to 4 MiB, removes temporary files,
and preserves the 45-second probe deadline and all execution resource limits.
Five real subprocess regressions check large JSON, nonzero exit, missing
executable, timeout and oversized output. This transport correction still
requires a new exact-source all-six qualification.

That correction's fresh run passes SQLite/MariaDB but fails MySQL 8.0/8.4 and
is cancelled on PostgreSQL 16/17. The MySQL records retain a recorder JSON
exception while observing a live append-only journal. Complete retained lines
parse correctly; a controlled unterminated prefix of an actual retained line
reproduces the original exception. The exact transient read bytes were not
retained. Live observation now consumes only newline-committed records once,
keeping complete bytes and stable request indexes. Final observation parses
the exact retained snapshot and refuses an unterminated tail; malformed
committed lines still fail. Five regressions cover partial receipts, large
pending writes, malformed committed records, final refusal and lost committed
bytes. Four checks fail against the former live-read path. PostgreSQL 17's
query cohort independently passes all 27 observations, but its later native
pressure step is cancelled; that job remains unqualified. Execution deadlines
and resource budgets are unchanged. A new all-six run is required.

The live-read correction's original run independently passes all 27 query
observations and 144 complete query wire sidecars on each of the six databases.
The existing signal cohort passes 27 observations and 90 full wire sidecars
per database; all 76 native execution tests pass on each. PHP/source passes
2,706 tests/61,420 assertions. Whole jobs pass on SQLite, MariaDB and MySQL
8.0/8.4; PostgreSQL 16/17 remain cancelled. Both GitHub check annotations
explicitly report the 20-minute job limit. PG16 reports all pressure targets
passing immediately before cancellation; PG17's log stops during native
pressure, although retained pressure records later include all three targets.
Those original records do not qualify the cancelled jobs. The reviewed harness
correction gives SQLite and PostgreSQL the same bounded 25-minute CI job limit
already used by MariaDB/MySQL. Individual fixture/probe/pressure deadlines,
resource limits, frozen SDK identities and semantic assertions stay unchanged.
A fresh exact-source full matrix is required before acceptance.

Next: qualify this exact source on all six database configurations, proceeding
from #415's identical previously qualified candidate/main tree. Its fresh main
SQLite frozen-PHP cancellation failure remains independently recorded; General
owns that PHP correction and current-release qualification. Release and cutover
qualification remain held. Retain query disagreements and correct the responsible
implementation, then repeat strict qualification and independently inspect
original records and complete wires. Busy snapshots, errors/recovery policies,
historical selectors, older-protocol routing, namespace/auth policies and
performance are separate gates.

[Reviewed acceptance](https://github.com/durable-workflow/server/issues/325#issuecomment-6115419641).
[Process/result clarification](https://github.com/durable-workflow/server/issues/325#issuecomment-6115688730).
[Bounded scheduling](https://github.com/durable-workflow/server/issues/325#issuecomment-6116091307).
[Independent PHP correction](https://github.com/durable-workflow/server/issues/325#issuecomment-6116357816).
[Initial recorder disagreement and correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6116719443).
[Worker/public history and completion response shapes](https://github.com/durable-workflow/server/pull/422#issuecomment-6117007924).
[Ordinary Python refusal and native correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117247506).
[Original output capture failure and correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117614915).
[Original live-journal disagreement and correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117918727).
[Original job timeout and bounded CI budget correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6118354755).
