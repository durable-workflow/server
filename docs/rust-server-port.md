# Rust Server port

[Server #325](https://github.com/durable-workflow/server/issues/325) owns the
rewrite and its acceptance evidence. PHP remains the default published runtime.
The unpublished Rust crate has an Avro foundation and an opt-in
SQLite/PostgreSQL/MySQL HTTP execution slice. Full runtime parity, migration,
performance improvement and cutover remain unqualified.

The latest qualified merged native foundation is
[#457](https://github.com/durable-workflow/server/pull/457), at `303ffc633`.
Its separate fresh-main
[38668891437](https://github.com/durable-workflow/server/actions/runs/38668891437)
passes all 13 shared jobs across six databases; all six execution logs were
read. Normal source and boundary checks pass. This includes memo transactions,
bounded continuation, installed SDK memo authoring and local external values
from #445/#446/#448/#450. Earlier failed observations remain failed; no result
is carried across changed trees. Full external transport discovery remains
unadvertised. Frozen PHP, embedded and SDK actors stay separate from current
release work.

## Work order and current status

- [#455](https://github.com/durable-workflow/server/pull/455): opt-in,
  namespace/queue-scoped null-task poll hints. Component `1fa1cc2e6` passes all
  13 jobs across six databases. Promotion is through the combined #458 tree,
  preserving strict branch protection and combined OpenAPI revision 55.
  Its earlier SQLite registration DELETE lock-pressure 503 remains failed,
  without a current-release or historical-cause inference. Hints grant no
  delivery, claim or lease authority. Idle cost remains unqualified.
- [#458](https://github.com/durable-workflow/server/pull/458): activity-result
  completion uploads with exact Unicode ownership hashing, original authority
  and durable allowances. Separate local PHP/native originals pass on three
  database families. Combined `3409a776e`, including qualified PHP `35cd3642`,
  passes all six health jobs and five database jobs with 113 execution tests
  each. MySQL 8.0 fails at a frozen native query transport observation; cause
  remains unknown and one identical-head/limits MySQL-only retry is live in
  [38686850381](https://github.com/durable-workflow/server/actions/runs/38686850381).
  The older PostgreSQL 17 100 ms fresh-upload failure remains failed; its
  separate normal-policy correction preserves the stalled deadline and shared
  semaphore. Current source is integrated here without inheriting qualification.
  No workflow/query/prepared context or full transport capability is advertised.
- [#460](https://github.com/durable-workflow/server/pull/460): read-only upgrade
  source inventory. Four actual published PHP 2.8.0 profiles pass locally with
  unchanged source state. The earlier `4459140cd` hosted run passes all five
  network database cells, with 46 observations each. SQLite fails frozen PHP
  restart preparation with lock-pressure 503 before inventory or recovered
  native admission. Its subsequent cURL 52 is an absent prerequisite, not a
  demonstrated native crash. The preceding 100 ms upload failure remains
  separate and failed. The prospective workflow gates dependent recovered
  checks on successful preparation, while independent profiles and cleanup
  still run. SQLite WAL, FIFO and directory sources refuse without pretending
  to prove backup consistency. This integrated source needs fresh qualification.
  Backup, quiescence, representation compatibility and takeover flags remain
  false; no migration is authorized by inventory success.
- [#463](https://github.com/durable-workflow/server/pull/463): separate native
  query authority under the agreed #433 contract. Its revised normally
  installed PHP SDK 2.4.2 profile passes ten fresh SQLite originals, five each
  on published PHP 2.8.0 and native. Earlier cleanup, observer, worker-list and
  named-timer failures remain failed. All-six qualification and remaining
  consumer/pressure/transport gates are pending. Its source is separate from
  this inventory branch. Native main does not advertise query authority.

Full transport discovery stays unadvertised. Complete batch workflow semantics,
memo/query reference ingress, upload allowances, automatic result policy, S3,
cleanup/backup/restart and full database/consumer qualification remain open.
Current PHP 2.8 query-authority and activity-result-inspection parity also
require separate native qualification.
Broader runtime/operational parity, safe sequential PHP database takeover,
matched performance measurements and release/cutover preparation remain #325
requirements. No native release or deployment is qualified.

Completed local raw archives, transport journals and databases have been deleted.
Historical retention descriptions below do not guarantee that those bytes are
still available. Code, readable owning outcomes and bounded temporary job
summaries are the current evidence surfaces.

### Earlier external input and byte milestones

External workflow inputs now retain their verified opaque reference in the same
native transaction as start admission. Failed starts roll that retention back.
Cold workflow delivery and description keep the small reference, while the
normal installed SDK fetches and decodes the original bytes. Invalid Avro,
including malformed negative collection blocks, and expired/unbound references
refuse without admitting a workflow or retaining an abandoned upload.

The same normal PHP 2.3.9 consumer completes actual frozen PHP 2.6.2 and native
SQLite originals with an exact 4,194,356-byte input, preserving the large int64,
null and Unicode arguments. Each invokes and completes once within its original
30-second observation budget. Stopped SQL/provider inspection independently
confirms one completed first-attempt task, zero repairs, one completion event,
the exact retained input hash/size, null upload expiry and a small stored pointer
instead of expanded bytes. The native binary is
`c58762759f4c03302ee54b3e22a11261ed087783d374ff7537dfb48d1ec5fd0b`.
The first PHP business original completed but its observer incorrectly decoded
the raw external result as inline; that observation remains unsuccessful. The
corrected observer uses the normal SDK result reader on a separate original.
Client header typing and native opt-in/listen setup failures preceded admission.

Validation uses Apache Avro 0.22.0's schema-aware reader and a discarding Serde
visitor, not a decoded collection or rewritten stored bytes. An actual regression
proved the unpatched reader accepted an invalid negative collection byte length
that frozen PHP rejects. The narrowly vendored patch checks those block bounds,
streams discarded bytes, and grows scalar buffers from observed data before a
short-stream refusal. Its source/package provenance and two changed upstream
files are documented in `rust/vendor/apache-avro/PATCHES.md`. Thirteen actual
frozen PHP datum controls pass; native final source passes 66 library tests
with 18 explicit database/subprocess ignores and all 102 SQLite HTTP tests.
Formatting and probe syntax checks pass. All completed local runtime, database,
vendor-install, compiler/cache, formatter and raw evidence resources are deleted.

This is workflow-input acceptance only. General completion/activity/memo/query
reference ingress, automatic external result policy, complete policy/provider
variants, allocation/CPU/deep-nesting qualification, cleanup/backup/restart and
the full database/consumer matrix remain ahead. Full transport discovery stays
unadvertised; no release, performance, migration or cutover claim follows.

The development external-payload transport now exposes authenticated versioned
upload/fetch routes and an admin-only local namespace policy. The byte route has
its own configured size ceiling, original request timeout and one bounded
snapshot worker; it accepts worker, operator and admin credentials without
either protocol-version header, matching the frozen PHP transport. Namespace
object/byte quotas include precommitted writing rows and refuse a new object
before any provider write. Identical retries keep their reference ID. Fetch
verifies independent bytes and updates fetch metadata without retaining the
object or renewing its expiry. Completion-upload headers explicitly refuse
while the required task/lease authority checks remain unfinished.

Actual separate native and frozen PHP 2.6.2 HTTP servers each pass nineteen
byte-transport checks with a 3,145,729-byte object: exact upload/fetch hash,
same-reference retry, conflicting metadata, authentication, namespace isolation
and object-quota refusal. Stopped database/provider inspection confirms two
ready, unclaimed namespace records, distinct reference IDs and one exact shared
content-addressed object on each server. The PHP fixture's initial local-policy
setup returned 404 because this minimal launcher had not seeded its default
namespace; an actual namespace admission preceded its first payload comparison.
Both test runtimes, their databases, network and raw scratch are deleted.
This observes bytes, not a valid Avro datum or an admitted workflow. The full
transport capability remains unadvertised. Complete policy variants, all
database families, local/S3 providers, completion-upload authority, retained
claims, cleanup/backup/restart and real external memo/continuation consumers
remain qualification gates. Tokio-util 0.7.19 supplies the standard streaming
adapters; existing locked crate versions are unchanged.
Final native source checks pass 63 library tests with 18 explicit
database/subprocess ignores and all 100 SQLite execution HTTP tests. The four
new HTTP controls also cover corrupt/missing provider bytes, partial-object
repair, observed oversize, refusal before body polling/provider effects and
recovery of transfer capacity after a stalled upload's original timeout.

The external-storage preparation now writes local content-addressed objects
and records recoverable `writing`/`ready` registry transitions. Exact retries
preserve accepted bytes and reference identity, while partial objects can be
repaired at that identity. Provider work stays off Tokio executor threads;
contended local writer locks refuse instead of blocking indefinitely. Paths
match PHP's encoded file URI, and reads bind the configured local root before
verifying an independent snapshot. Native SQLite controls prove that failed
provider writes and rolled-back ready promotions retain the original writing
row and upload deadline. Cross-namespace lookups, conflicting metadata and
expired uploads refuse; retained retries preserve ownership and no expiry.
The actual frozen PHP registry passes fifteen independent controls, including
an injected provider write failure and recovery with the same reference ID.
Final native library checks pass 62 tests with 18 explicit database/subprocess
ignores, and all 96 SQLite execution HTTP tests pass. These registry methods
were internal preparation at that checkpoint. The subsequent byte handlers
above add local HTTP routing and quota admission; completion-upload fences,
retained-state claims, cleanup/backup
coordination, external database execution and S3 remain pending. No external
transport or memo capability is enabled.

The external byte foundation now captures verified anonymous file snapshots
with fixed 8 KiB reads, declared-size refusal before reading and a one-byte
observed-size probe. SHA-256 measures the exact uploaded Base64 byte string;
Avro frame validation belongs to the later state-bearing request. Blocking
disk/hash work holds a concurrency permit, and a verified snapshot keeps that
permit through its consumer lifetime. The async reader retains capacity and
uses bounded file buffers. Abandoned captures stop between reads and retain
capacity until a blocked source returns; callers must bound individual reads.
Published PHP snapshot controls pass eight exact-size, spill, backing-change
and one-byte-probe cases. That snapshot foundation passed 56 library tests with 18 explicit
external-database/subprocess ignores. A counterfactual that performs capture on
the single Tokio executor fails the responsiveness control after the watchdog
releases the source; restoring the blocking worker passes. Namespace/registry
admission, HTTP upload/fetch and provider persistence were still pending at that
snapshot-only checkpoint. Real workflow consumer acceptance remains pending.

External memo references require the existing authenticated runtime transport,
not provider URIs in clients. The next foundation validates the five-field
opaque reference against 46 published PHP controls, including exact integer
sizes, strict unknown-field refusal, digest canonicalization and the published
present-null/empty codec fallback. The published validator and codec-contract
source hashes match this PHP tree. A reference's permitted identifier alphabet
does not assert that an object exists or that its bytes have passed integrity.
Native storage lookup must separately bind namespace, ready state, metadata and
expiry before byte verification. The primitive was preparatory at that checkpoint;
the byte handlers above now use it. It changes no advertised transport capability
or frozen actor tuple.
The full transport slice remains upload/fetch and normal installed SDK use,
bounded streaming, durable content-addressed admission, integrity and quota
controls, current-lease completion upload fences, runtime-owned resolution and
claim retention, restart/recovery, cleanup/backup holds, three database families
and local/S3 provider compatibility. Memo history/read and continuation tests
must consume those real references before full memo support can be qualified.

The next authored-memo slice follows #445's disabled preparation. Its full
acceptance includes configured structural limits, actual SDK replay, protocol
and capability fences, external envelopes, continuation inheritance and
unchanged state after refusal. The first change wires the published
`DW_V2_LIMIT_MEMO_SIZE_BYTES` / `WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES` precedence and
casts into each native database runtime and its namespace views. Nineteen
configuration controls were observed on the published PHP package. Zero and
negative values disable this structural limit; fixed memo count/value/total
limits still apply. The new actual SQLite control refuses before changing the
original memo/history and restores at the inclusive whole-map boundary.
The configured rejection preserves PHP's 422 task/attempt and measured-limit
diagnostic. Twelve focused controls and the restored full source suite pass:
48 library tests and 90 SQLite HTTP tests, with 18 explicit external-database
and subprocess ignores. Reverting only the transaction's configured limit to
`None` makes the new actual refusal control fail by accepting that upsert.
The correction restores the inclusive whole-map boundary and preserves the
separate actual history-write rollback test. The unpublished local candidate
enables memo commands and passes the unchanged installed PHP 2.3.9
author/activity/cold-successor recipe against native SQLite. Three independent
worker processes preserve the original four- and six-event prefixes and finish
with eight events, memo command sequences 1/3, one activity, typed int64/null/
Unicode values and unchanged run/execution deadlines. An identical committed
receipt while the parent waits returns 200/already_completed/recorded=false;
a conflicting receipt returns 409/conflicting_completion without mutation.
All 23 corrupt-reader controls refuse. Protocol 1.13 refusal preserves the
original task, while the capable 1.14 control upserts and completes it.
The normal installed Python 2.5.13 author and cold successor now complete the
same original around an independent PHP activity on frozen PHP 2.6.2 and the
hosted native candidate. Both preserve the eight events, typed memos, original
deadlines and complete captured first-completion body; each passes all 23
corruption controls. Stopped read-only SQL shows three completed first-attempt
tasks, zero repairs and the same four surviving memo rows on both backends.
The shared candidate selects this additional cross-language recipe in a
separate prefix/output directory and compares it with the embedded PHP recipe.
The existing recorder forwards unchanged bytes; SDKs and clock/history fields
are not edited. The normal registry Rust 3.5.4 author and cold reader also
complete actual PHP/native SQLite originals around the same PHP activity,
passing the identical 23 corruption controls and stopped SQL guards. The
observer checks binary/build identity before worker execution and keeps the
exact published crate/source/lock identity. Its reported SDK constant remains
`durable-workflow-rust/3.5.4`; the reader checks that exact format. The shared
candidate selects all three SDK author families in separate prefixes/output
directories. External envelopes, broader inheritance, complete discovery and
all-database acceptance remain incomplete; no published capability is claimed.
The combined authoring candidate now integrates exact PHP main #449 and #446's
continuation foundation. A focused control exposed the sole-command guard's
refusal of a memo upsert followed by continuation. The correction permits only
validated memo commands before the terminal continuation, retains unsupported
transfer/routing refusal and proves rollback of the preceding memo when routing
refuses. A separate cold-routing control preserves the fenced old claim when
registration changes and delivers memo history only to a capable successor.
The new shared `continue-upserted-memo-activity-replay` case completes actual
frozen PHP and native SQLite originals with one activity, one memo event and one
successor, preserving the merged memo before successor admission. Each passes
35 strict reader corruptions. Its first business originals completed but the
reader failed because the continuation observer omitted typed memo-envelope
decoding. The corrected observer uses the normal codec on fresh originals;
no history clock or stored bytes are rewritten. The normally installed embedded
2.5.16 counterpart also passes with 34 corruption controls. All three originals
have four completed first-attempt tasks, zero repairs and one canonical
continuation link. Final native execution passes 96 tests; the recorder passes
206 controls. The [three-runtime receipt](https://github.com/durable-workflow/server/pull/448#issuecomment-6170709164)
records exact source, binary, original identities and qualification limits.
Full matched acceptance, external references and complete discovery remain
pending. All completed runtime, compiler and raw audit resources were deleted.
Frozen references and independent #444/#445/#446 gates are unchanged. The
broader [draft #448](https://github.com/durable-workflow/server/pull/448) retains
those incomplete acceptance requirements.

[#445](https://github.com/durable-workflow/server/pull/445) prepares authored
memo transactions on top of #444. Apache Avro's schema-aware serde writer
preserves the published PHP canonical map bytes. The internal transaction
replaces or deletes named keys, preserves untouched row metadata and appends
the merged history envelope atomically. A real SQLite history-write failure
rolls back the memo change. Public completions still return
`workflow_memo_updates_unavailable` with `recorded=false`; discovery remains
disabled. Protocol-floor refusal leaves the original task usable. Configuration
limits, external references, inheritance, routing/replay boundaries and actual
installed SDK acceptance remain required before enabling this capability.

[Continuation #446](https://github.com/durable-workflow/server/pull/446)
adds the first atomic native continue-as-new transition after one genuine
activity. Four normally installed PHP SDK 2.3.9 workers complete an actual
native SQLite original and its single successor, preserving typed
int64/null/Unicode results, history prefixes, the original execution deadline
and inherited memo authority. The full captured completion retry includes
sticky metadata and returns `recorded=false` without another successor.
The [actual receipt](https://github.com/durable-workflow/server/pull/446#issuecomment-6163706919)
records 30 corruption controls and stopped read-only SQL showing one canonical
link, four completed first-attempt tasks and zero repairs. Library, execution
and recorder controls pass locally. Published PHP and normal installed embedded
baselines are separate; embedded successor `StartAccepted` remains explicit.
The earlier native request-shape originals stay failed: normal SDK serialization
supplies its default queue, and null-only routing acceptance was insufficient.
Changed routing and related/pending transfer families remain refused and
unqualified. Review also found that the pending-query guard could miss a
predecessor entry beyond its 65-row cache prefix. The draft now checks the
complete native-owned predecessor scope in SQL without loading cached history
snapshots onto the executor. A constructed database boundary control covers
pending/leased refusal, unchanged history and successor count, then same-original
continuation after the query becomes terminal. Exact revised qualification
remains required. The corrected cohort now selects this separate pending fixture
on all six database cells; full matched qualification remains next. This does
not enable memo upserts or establish full continuation, migration or release
readiness. Disposable runtime, compiler and raw records were removed after audit.

An earlier #444/#445 memo comparison failed because its completed-task retry
omitted captured `sticky_cache`, so native correctly refused a
changed body as `conflicting_completion`. The [probe correction](https://github.com/durable-workflow/server/pull/444#issuecomment-6163205713)
preserves all original completion metadata and checks a separate conflicting
payload refusal. Earlier failed records remain failed; no stronger PHP receipt
contract or runtime receipt change follows. #445 and #446's prior candidates
passed all 13 matched jobs. The delivered #444 foundation and qualified PHP
#451 source are now integrated for fresh combined qualification. Earlier
results do not qualify this combined tree. The broader authoring candidate
remains separate from the disabled memo foundation and unpublished.

The [first matched development observations](../benchmarks/server-port/v2/observations-2026-10-14.md)
complete 737 workflows per implementation, including setup and warmup, with
exact outputs and zero task repairs. They do not qualify a performance gain:
PHP has two idle API timeouts and a forced scheduler shutdown, while native
workflow tails vary. The original idle reader also rejects protocol-permitted
`timeout` replies. Separate reader and scheduler controls pass, with original
failures preserved. Separate installed-worker controls identify a native poll
wake gap: published PHP interrupts an opted-in activity/query wait when new
workflow work arrives, while the native baseline consumes the remaining waits.
This does not establish the cause of every earlier tail. The
[scoped correction in #455](../scripts/conformance/server-parity/CROSS-KIND-POLL-WAKE.md)
keeps task/lease authority in ordinary claims. Its five focused HTTP test groups
pass locally on SQLite, PostgreSQL 17 and MySQL 8.4; the unchanged native baseline
fails all three positive groups. Installed-worker activity/query controls also
pass on PHP and native SQLite with the original workflow/run identities.
Six-cell qualification of the new combined tree remains required before
repeating the corrected matched profile or claiming a gain.

The #444 original MySQL 8.4 matrix on `95cd4efa` exposed a native activity
clock disagreement: a retry was recorded 101 ms before its durable
`ActivityStarted` timestamp plus the unchanged two-second start-to-close
timeout. The attempt and deadline already use one original clock; history
used a later publication clock after database writes. The correction records
that original attempt-start instant in history, preserving the stored deadline
and strict reader. A real MySQL control delays attempt insertion by 25 ms;
the prior source fails with a 35 ms event/deadline disagreement. The corrected
source passes that control and all four actual MySQL deadline/receipt/restart
tests, plus 41 library and 89 SQLite HTTP tests (18 explicit external-database
and subprocess ignores). The original hosted failure remains failed. This
changes only new native activity-start events, with no PHP correction or
rewriting of historical records.

The pending [memo fixture](../scripts/conformance/server-parity/MEMOS.md)
widens metadata coverage before native implementation. Normal installed PHP
SDK 2.3.9 against the frozen Server 2.6.2 and embedded Workflow 2.5.16 complete
the same original memo/activity/cold-replay recipe in three independent
processes. The typed merge, two memo command sequences, exact original prefixes
and one activity result agree. The PHP completed-task duplicate returns
409 `task_not_leased` with completed status and no second effect. The first
reader incorrectly required HTTP 200; this was a reader error, not a product
defect or a stronger receipt contract. Full native parity, protocol/capability
and limit refusals, inheritance and external payload gates remain ahead. Existing
frozen profiles and default fixture discovery remain unchanged.
The draft native foundation now persists initial JSON-like memos as both the
PHP host's legacy projection and a sequence-bound Avro envelope. Reads preserve
the current envelope's types and follow the published legacy fallback when the
portable sequence is stale or its value is null. Official codec work runs on a
bounded blocking worker. Focused codec and cold-storage controls pass locally;
full SQLite regressions pass. Initial-memo three-runtime/all-database acceptance
and the authored-upsert gate remain pending. Memo updates are still advertised
as unsupported; no native release or same-database takeover is qualified.
The existing corrected cohort now selects the separate initial-only memo case
for all six cells. It checks original start-history metadata and genuine
activity/cold replay across PHP, native and embedded targets without claiming
upsert support. A source review corrected initial null entries to deletion and
preserved authored metadata in both start events and the instance row. Fresh
native and matched qualification of that revision is required. All 4,845 shared
reader checks pass, with one explicit skip.

Four further raw published admissions establish the JSON number boundary.
Signed int64 maximum remains an Avro long; numbers immediately outside signed
int64 and unsigned int64 maximum become doubles with the recorded exact bits.
The [published storage observation](https://github.com/durable-workflow/server/pull/444#issuecomment-6164763709)
corrects the draft's unsigned overflow refusal in memo JSON and legacy
projections, including start-history metadata. Typed Avro long handling stays
unchanged. These are admission/storage controls, not completed workflows.
Revised native and matched qualification remains required.

Continuation's first revised MySQL/MariaDB gates expose a native query-template
error: literal JSON paths reached the ordered placeholder converter. The draft
now follows the existing driver-owned `?` template path without weakening
shared placeholder checks. MySQL test setup also uses text protocol for
`CREATE TRIGGER`, which binary prepared statements refuse. The [failed original
disposition](https://github.com/durable-workflow/server/pull/446#issuecomment-6165414784)
remains distinct from frozen PHP lock failures. Corrected real database controls
and a new exact-source matrix are required before promotion.

Final number-boundary matrix `38540906439` has twelve original passes and one
MySQL 8.0 failure: the historical producer is killed after its commands commit
but before the proxy observes the completion response. The strict reader
correctly refuses that cancelled completion. Separate upsert-preparation matrix
`38541216269` encounters the same checkpoint race in the Python-clock case on
MySQL 8.4. Both failed originals remain unsuccessful. The producer checkpoint
now requires the actual original admission receipt as well as durable history
within its unchanged 30-second limit. It changes no SDK, reader, clock, receipt
or frozen package contract. Four focused tests, fourteen refusal controls and
both actual failed captures confirm that unacknowledged admission cannot trigger
the kill. The actor helper is included in adapter source integrity checks.
The next matched revision exposed a separate fixture limit: a legitimate
4,955,564-byte PHP HTTP journal was rejected before its matching acknowledged
admission could be read. MySQL 8.4 in `38549329699` and all three MySQL/MariaDB
cells in `38549468335` remain failed. The observer now streams complete lines
instead of imposing a total-file limit. Five tests retain all fourteen refusal
controls and cover large journals without a receipt, a partial receipt and a
complete matching receipt. The actual MariaDB journal passes the same unchanged
identity checks. Fresh exact-source qualification is required before promotion.

The next upsert-preparation matrix has a distinct MySQL 8.4 CPU-cell setup
failure before native startup or original admission. A successful socket query
is followed by a missing-socket version query. The CPU-cell readiness and SQL
checks now require TCP, which does not accept the image's socket-only temporary
initialization server. Setup remains bounded at 120 seconds; database limits
and workload budgets are unchanged. This original is an unsuccessful setup,
not native deadline evidence. The affected cell will use the exact hosted
optimized binary and native-tree/hash provenance for local qualification;
broader corrected-source checks remain required.

Seven actual raw JSON admissions on the frozen PHP image exposed an additional
initial-memo boundary in #444. Empty objects and contiguous numeric-key objects
become lists; sparse or mixed canonical numeric keys refuse; leading-zero
string keys stay maps. Start history keeps PHP's decoded JSON shape, including
an empty list and a reversed numeric-key object, while portable memo storage
canonicalizes the latter to a list. The native correction uses a memo-specific
serde visitor and separate storage conversion. It preserves unrelated request
fields, top-level null metadata and typed Avro memo maps. The baseline native
control reproduces the empty-object mismatch. Fresh exact-head shared checks
are required; no memo-update capability is enabled.

#438 is merged at `50ce8f42967bc14c0169fc31b16fbbbd5827528f`, complete tree
`1065360b83f0dbf531a67a7af8edbece19b97fec`, identical to reviewed `97e63c01`
and its CI merge `e8f4fc49`. All six original final combined jobs in
`38506521977` and applicable source/boundary gates pass. Exact fresh-main
[`38511209412`](https://github.com/durable-workflow/server/actions/runs/38511209412)
also passes all six original jobs without retries. Independent completed logs
confirm the four retry/heartbeat families across PHP, Rust and embedded mode.
The [delivery receipt](https://github.com/durable-workflow/server/pull/438#issuecomment-6161666925)
records final source and boundary success. Conditional cancellation and
prepared-local qualification remain explicitly skipped. Earlier failed
originals and frozen tuples retain their existing dispositions.

#443 is merged at `5bd0bb4dcc3ec43b3186589a8d1f16d2c8972bcf`, complete tree
`b7716f947d979513877d540555e0b622e9435c09`, identical to reviewed `1061491df`
and CI merge `7c77b91b`. All 13 original combined jobs in `38513528725` pass:
six CPU/deadline cells, six ordinary database cells and the SDK/build job.
Source and boundary gates pass. Independent CPU logs confirm the declared
activity, timer, renewal and canonical cleanup observations under load, plus
stopped read-only history and task audits with zero repairs. Exact fresh-main
`38520403960` failed at the killed-producer poll checkpoint, as documented in
#447. That correction is merged at `07015a65`; fresh-main `38544906272` passes
all thirteen original jobs, with applicable source and boundary gates also
passing. [Final delivery](https://github.com/durable-workflow/server/pull/447#issuecomment-6166892004)
preserves both failed originals and the conditional skips. Native and frozen
packages are unchanged. This does not establish matched PHP/Rust performance,
takeover or rewrite release readiness.

#438 candidate `c99b1130090b8e29db0bcab7d1bdfa6fd34a0dbf` qualifies all six
database configurations in run `38492234181`, after two unchanged-head/limits
single-job retries for upstream Composer HTTP 500 responses. Its complete tree
`6eda44f2aadffb046e0c0f018b126ccd1a4be0e3` matches recorded CI merge `6604a3b2`.
Current-main integration includes #442 and reviewed PHP metadata; its final
combined qualification passes as recorded above. Native and frozen tuples stay exact.

Earlier #438 run `38486080308` retains two failed originals. SQLite
stops at the published PHP activity-expiry parent poll's documented
`503/backend_lock_pressure`. Read-only SQL confirms one original timeout and
an unclaimed, ready parent task with zero repairs. PostgreSQL 17 passes both
HTTP deadline and retry families, then Composer receives HTTP 500 fetching
`league/config` before the corrected embedded application is installed.
Neither job qualifies the complete matrix or explains older failures.

The fixture now handles only a matching namespace/queue/task-kind pressure
refusal through the normally installed Client response API. It waits the
advertised backoff with the same logical poll ID inside the original observer
budget. Unknown admission errors and malformed responses still fail. Optional
wire timing and strict reader checks bind each pressure response to its actual
retry. A normally installed PHP SDK 2.3.9 against the unchanged published
PHP 2.6.2 image passes all four expiry originals with one synthetic pressure
response injected before forwarding. Exact prefixes, frozen policies and
original deadlines survive. All four parent tasks are claimed once with zero
repairs. Fifteen installed-Client controls and seventeen reader controls pass.
The complete shared reader suite passes 4,838 cases, with one explicit skip.
An initial local observer setup error occurred before any workflow admission
and is not counted as a workload. Candidate qualification is complete; fresh
combined-main qualification remains pending. The source includes reviewed PHP #440
merge `11261ef7`; frozen images and native capabilities remain unchanged.

Separate [retry and terminal-response controls](../scripts/conformance/server-parity/ACTIVITY-RETRY-RECEIPTS.md)
pass fresh published PHP/embedded SQLite comparison at `b6d5faef2`. Four originals
each retain two actual attempts, eight events, four tasks with zero repairs,
the entire retry snapshot and exact int64/null/Unicode results. Active-parent
duplicates before and after expiry and closed-parent duplicates preserve the
original result. Actual HTTP and embedded refusals remain explicit. Common
comparison, 86 corruption checks and stopped read-only original deadline
verification pass. Separate [global deadline originals](https://github.com/durable-workflow/server/issues/325#issuecomment-6156799442)
pass on PHP and embedded mode while an unused third attempt and the second
attempt's close budget remain. All three [native SQLite cases and a matched
live-heartbeat case](https://github.com/durable-workflow/server/issues/325#issuecomment-6156982520)
also pass. Two heartbeats preserve one original attempt past its initial
heartbeat deadline; status reads do not renew, and later progress is refused.
The new `retries` profile family selects the four fixtures across three runtimes
and runs their actual-record corruption checks. All six candidate database
configurations pass; current-main qualification remains pending. These controls remain outside #435 and the
default corpus. They do not establish a stronger content-verified receipt
contract. Earlier harness failures and unqualified observations remain recorded.
[#435](https://github.com/durable-workflow/server/pull/435) is delivered at
`7b53fc26db4e69a8013b6f1ad991fd1d98266833`, complete tree
`b9f7e9bd652586e23a4e80aa58433c79c1e51ea6` identical to the reviewed candidate.
All six original final-head and fresh-main jobs pass on SQLite, PostgreSQL
16/17, MariaDB 10.11 and MySQL 8.0/8.4. Fresh main
[`38489133816`](https://github.com/durable-workflow/server/actions/runs/38489133816)
passes corrected portable markers, genuine differing-bound version-1 producer,
strict complete retry-policy refusal/restoration, both selected range-repair
checkpoints, fixed Python clocks and all four activity expiry families. It also
passes the existing ordinary corpus, real process recovery, worker fencing,
database pressure and applicable TLS controls. PHP/source/repository and public
boundary checks pass; conditional cancellation/prepared-local qualification is
explicitly skipped. Historical failed originals remain failed. Their causes,
new #438 retry/renewal cases, performance and takeover remain separate.

The separate pending [CPU-load case](../scripts/conformance/server-parity/CODEC-DEADLINE-LOAD.md)
declares real dense Avro reads, independent health observations and a retained
activity's original global deadline. Seven streaming/timeout/status controls and
29 actual-record corruption controls pass. An optimized native SQLite original
passes all 91 dense reads and 88 independent health/readiness reads under measured
busy CPU at one CPU, 512 MiB and no swap. It preserves the original activity
deadline, history prefix, parent delivery and late-outcome fences. The debug
native read-timeout original and PHP OOM/read-timeout originals remain failed.
This does not establish matched performance, embedded CPU behavior or full
timer/lease-renewal/cancellation health under load. Other databases and the
complete CPU/deadline qualification remain ahead.
It leaves the default corpus, native source, dependencies and frozen actors
unchanged.

The separate [timer/renewal/cleanup CPU profile](../scripts/conformance/server-parity/CODEC-OPERATIONS-LOAD.md)
adds three independent installed SDK observers to the same workload. Its fresh
native SQLite original preserves one timer firing/completion, two timely
same-attempt heartbeat/lease renewals and original cooperative cleanup expiry
under measured busy CPU. All 85 dense reads and 88 health/readiness reads succeed,
and the stopped read-only audit finds zero repairs. The failed barrier race,
delayed-description observation, incorrect source identity and PHP OOM/lock
reference remain separate outcomes. The reader preserves fractional deadline
precision. Other database families, published consumers and the full CPU gate
remain pending. This is not matched performance or cutover qualification.

Original `871eec1ec` run `38477701278` passes SQLite, PostgreSQL 16/17,
MariaDB 10.11 and MySQL 8.4. MySQL 8.0 passes its corrected portable, clock and
activity-expiry comparisons, then its fixture database is OOM-killed during
native pressure at the declared 1 GiB limit. The retained state records
exit 137 / OOM true, followed by native storage refusal and lost database
hostname. Its TLS step is unexecuted. The failed original stays failed.
The prospective MySQL 8.0 allocation is now 2 GiB, matching the expanded
MySQL 8.4 fixture. Native/actor limits, scenario deadlines and the 75-minute
whole-job ceiling are unchanged. Fresh qualification is required before
promotion. This does not establish the cause of historical 503s or qualify
performance.

Original SQLite artifact `11858815390` matches GitHub's published SHA-256.
Its recorded CI merge `623d900a` has the same complete tree
`a58061e1c335e0b01de55a14e6a26c8f2d4f2dd1` as the branch head.
Canonical typed values and source fixture hashes remain the evidence for exact
int64s. JavaScript numeric copies in diagnostic fixture inputs are rounded;
they are not claimed as lossless payload evidence. Temporary ZIPs are deleted
after their immediate inspection.

The separate #438 recorder correction now copies validated original fixture
and probe JSON into each saved case, preserving numeric literals without another
complete serialization of the wire bodies. At `6a79f7b9c`, nine focused recorder
controls and all 4,821 shared reader checks pass, with one explicit skip. A fresh
normally installed SDK 2.2.6 workflow on the unchanged published PHP 2.5.14
baseline completes with `9007199254740993` preserved in source input, saved
fixture, decoded history and output. Temporary probe files are absent after
writing, and the local runtime and recordings are removed after verification.
Earlier rounded diagnostics remain unchanged. Final-head matrix qualification
of this recorder revision is still required.

[#429](https://github.com/durable-workflow/server/pull/429) is delivered at
`6299289185caea472b2738a5d4442a617edf0b8a`, tree
`62736363c1e03ceae091ea578d307fb4d2370054`, identical to the reviewed candidate.
All six original candidate and automatic main database jobs pass without reruns.
Independent main artifact audits verify 162 paired namespace observations,
324 original runs, 1,836 full HTTP sidecars, 11,567 byte-checked exchanges and
10,053 member hashes. Each database passes 78 native execution tests and 49
namespace reader/retention tests. Main SDK identity, PHP/source (2,744 tests /
61,718 assertions, six existing deprecations and one skip) and boundary checks
pass. The [delivery receipt](https://github.com/durable-workflow/server/pull/429#issuecomment-6138216847)
records exact artifacts and limits. Historical failures below remain failed;
their causes are not established by this successful qualification.

Current #435 ordinary run `38438905276` fails all six original jobs at the
process-recovery harness's sub-40-second lease wait guard. The candidate now
uses published PHP's 300-second ordinary activity lease. A new machine-owned
recovery allocation allows at most 310 seconds, declared before preparation
and frozen with its hash in every new receipt; missing or changed old receipts
are refused. No existing run receives a new lease or deadline to fit the test.
Original failed jobs remain failed. The later ordinary-corpus run confirms
real process-kill recovery; expanded matched fixture gates remain pending.
That runner has a prospective 55-minute whole-job ceiling to include the real five-minute lease
wait; old 45-minute allocations and all individual scenario limits stay recorded
unchanged. Runtime memory/CPU and compile limits are unchanged.
All six original jobs in the separate `d36856486` run `38447782933` pass.
Original archive identity, native execution/schema/TLS and process-loss audits
confirm recovery after the persisted five-minute lease on every database.
Complete per-profile HTTP sidecar audits were not claimed. That run covers the existing
corpus, rather than the expanded corrected marker cohort. The expanded runner
wires eight portable cases across three runtimes and two service clock cases
across both HTTP runtimes into all six configurations, using one explicit
2.5.16 embedded manifest. Its prospective whole-job ceiling is 75 minutes;
all scenario, phase, lease-wait and resource limits remain unchanged. Existing
45/55-minute run allocations remain unchanged. The first expanded `a96d38ebd`
run `38456291140` fails all six corrected-cohort stages; its PHP feature and
both public boundary gates pass. The original PHP case contains an extra repair
after a killed historical producer's forwarded poll blocks the later parent.
The prospective fixture now retires that registration before the activity
resumes, preserving strict history and existing deadlines. A fresh published
PHP run passes all six marker cases and 99 reader controls, including that
handoff without a repair event. Expanded matched confirmation remains pending.
MariaDB also records a fixture DB OOM under its unchanged 512 MiB cap during
the later pressure stage; TLS is skipped. This does not explain historical
503s. The runner now retires its corrected cohort resources immediately after
that stage, including failure, before subsequent independent pressure checks.
Completed local bulk recordings and archives have been deleted; code, fixtures
and readable GitHub outcomes remain. Earlier local retention promises no longer
apply. New scratch is removed after its immediate inspection.

The expanded `718ea3608` run `38469976743` passes both PostgreSQL jobs and MySQL 8.4.
SQLite passes the published PHP eight portable, two clock and four activity
expiry cases, then fails native discovery because the new stage addressed the
process intentionally killed by the preceding recovery test. The replacement
process is running and the prospective runner now addresses it. MySQL 8.0 and
MariaDB exit their PHP recorder with 137 while writing incomplete JSON after
the final case snapshots complete. The recorder was automatically removed, so
its OOM state is unavailable. Their database and PHP service states are healthy;
later pressure controls pass. No product or historical failure cause is inferred.
Originals keep their recorded dispositions.

The recorder now writes one case at a time to avoid creating another complete
serialization of all retained HTTP bytes. A bounded 48 MiB heap control preserves
32 MiB of request bodies, typed int64, null and Unicode, and failed/empty records.
The corrected HTTP profile retains the recorder's exit/OOM state before removing
it. These are runner changes, with unchanged native bytes, fixture semantics,
actor resource limits and original deadlines. Fresh matched qualification is
required before promotion. Immediate artifact inspections are disposable.

The first incremental-writer revision `3a9370f7a` fails recording with an undefined
optional database-resource field. Unlike whole-object JSON serialization, its
first implementation tried to pass that value to the file API. The correction
omits undefined optional properties and preserves explicit null, with actual
empty, passing and failed record shapes in the encoder checks. These failed
originals are not product regressions or successful matched comparisons.

General's PHP-only dependency update #437 is merged at `158f4c6f2` and included
in the current working branch. It leaves the pinned corrected and historical
cohorts unchanged. The combined tree requires fresh candidate qualification
before #435 promotion; no Server 2.6.3 image qualification is inferred.

The separate actual elapsed-deadline HTTP profile passes fresh matched
published PHP/native SQLite recordings at `67cfd7b95`: four original expiry
families, 142 corruption controls, and independent SQL verification of 19
events per target and unchanged database bytes. Native now returns the ordinary
structured heartbeat refusal atomically, while preserving cooperative
stale-attempt conflicts. All 85 native execution and 35 unit cases pass;
18 database unit cases are explicitly ignored locally. The final restored Rust
tree is byte-identical. Timeout versus closed-attempt diagnostics describe the
actual retained lifecycle state. This profile keeps the parent timeout delivery
without authoring its result. The reusable embedded counterpart now passes
fresh PHP/embedded SQLite comparison at `54c0a3e90`, with 157 reader controls
and independent readback of four originals/19 events per target and zero
repairs. It authors through a real queue job and uses public worker bridges.
New shared three-mode/all-six wiring, retry/duplicate/renewal and final/main
gates remain pending. Existing frozen profiles remain unchanged.

[#434](https://github.com/durable-workflow/server/pull/434) is delivered at
`c6dc5e451bf9ba8651f1f84e386ed6c274f3992c`, preserving reviewed tree
`a823f73d288be436994e1272e020f462991b1dd3`. All six final-head and automatic
main database jobs pass. Independent main original-artifact audits verify
270 paired observations, 540 original runs, 3,300 HTTP sidecars and 11,803
member hashes across the namespace and selected-run signal profiles. Each
database passes 79 native execution cases, both 49-case comparators and 516
actual-record reader checks, with applicable pool/reset and TLS gates. Main
SDK identity, PHP/source and boundary gates pass. The
[delivery receipt](https://github.com/durable-workflow/server/pull/434#issuecomment-6143056720)
preserves earlier failed and cancelled originals and their separate limits.
Frozen Rust's optional variadic signal contract still lacks an invalid-argument
refusal gate; PHP/Python-author recipes omit a required argument.

The next active slice is the separate
[corrected marker/range cohort](rust-server-corrected-markers.md). It adds
supported current readers and Rust authors to genuine numeric, duplicate-bound,
clock, retry-policy and same-run repair cases. Existing frozen actors and
histories remain unchanged. Five positive marker cases pass through the PHP
reference and separately installed embedded roles. The additional published
Rust completed-activity and timer-winner/pending-activity range directions
pass the [original PHP recipes](https://github.com/durable-workflow/server/pull/435#issuecomment-6148196089),
and the [embedded counterparts](https://github.com/durable-workflow/server/pull/435#issuecomment-6148451599)
pass the unchanged PHP definitions. Those separate local recordings preserve
35-second scenario budgets, original task repair, full prefixes/microclocks,
typed values and neighboring runs; 100 actual-record negative controls pass.
Both range families now also pass separate native SQLite recordings at
`4f5d813a8`, with 56 actual-record controls and independent database readback.
Native retains unstructured Rust replay failures and original-task repair,
six-digit history timestamps and genuine legacy policy omission. All 81 native
execution tests and 33 unit tests pass locally, with 18 database tests ignored.
Earlier native admission, timestamp and policy-presence failures remain failed.
These observations do not qualify a matched native/database cohort. The embedded explicit
retry-policy mismatch remains a retained failure; its coordinated
[Workflow source correction](https://github.com/durable-workflow/workflow/pull/776)
is delivered and normally published as 2.5.16. A separately named 2.5.16
embedded input now passes all eight applicable marker/policy/range cases at
`a4c43d26c`, including strict policy refusal, exact restoration and selected
repair on the same original. All 931 installed runtime/resource bytes match the
published tag; 176 actual-record controls and independent SQL checks pass.
The original 2.5.14 profile and its failed run remain unchanged. These backend
handler cases run real independent `queue:work --once` database workers, as
confirmed by the retained RUNNING/DONE receipts. They do not qualify sustained
queue-worker draining. Current Python's
fixed-start clock/UUID cases pass the PHP reference. Native marker/clock
admission now has a candidate authored activity deadline implementation. It
snapshots all four timeout options, fences elapsed completion/failure/heartbeat,
retains the global deadline through same-execution retries, and renews only the
heartbeat deadline with progress. Ordinary activity leases follow PHP's
300-second contract. Local SQLite passes 85 execution cases and 35 unit cases,
with 18 database tests explicitly ignored. Four deadline cases cover terminal
expiry, full-policy retry across reopened storage, heartbeat renewal and
committed receipt ordering. All ten corrected native SQLite HTTP marker, policy,
range and Python clock cases now pass at `dbe6ff3a3`, with 194 actual-record
corruption controls and independent query-only readback of all 84 events and
12 original activities. Four authored-deadline regressions also pass on isolated
MySQL 8.4. Earlier missing-snapshot and new MySQL JSON-path conversion failures
remain failed; the latter is corrected without changing historical diagnoses.
The pending elapsed-deadline fixture still needs actual shared PHP/native/embedded
recordings; these local controls do not qualify that cohort. Embedded UUID semantics
and new matched all-six/final/main qualification remain ahead.
Published PHP remains the default; broader runtime
parity, migration, matched performance and release/cutover remain unqualified.

[#427](https://github.com/durable-workflow/server/pull/427) is merged and its
[fresh-main qualification](https://github.com/durable-workflow/server/pull/427#issuecomment-6130548960)
is complete at `3afb6bb41bd4dd5cc75234bacf0ae5e28df1164e`, tree
`6483bca52bf8e2a22b160acb6d9286c5f47e199d`, identical to the reviewed candidate.
All six database configurations, PHP/source and boundary checks pass. Original
artifact review verifies nine failed-update SDK directions and their embedded
counterparts: 162 observations and 1,188 full HTTP sidecars. Update, query and
signal regressions also pass. The earlier MariaDB storage 503s remain unresolved;
the clean qualification does not establish a repair. Published PHP remains the
default, with no Rust release, takeover or performance claim.

The [next reviewed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6130197979)
is [paired named-namespace SDK signals](../tests/Fixtures/ServerParityProfiles/published-sdk-namespace-signals/README.md).
Nine client/author definitions cover distinct original runs with the
same type and queue, wrong-namespace signal refusal, an empty foreign-Worker
poll and cold completion with exact independent Avro values. The ordinary
fixture actors accept an explicit namespace through the unchanged SDK API.
The paired recorder and strict reader are implemented. All nine published
client/author directions and canonical embedded counterparts pass the SQLite
reference baseline, with 36 original runs, complete transport and persisted
relationships checked. All 4,755 reader guards and the SDK-dependent forced
setup-loss regression pass with the locked PHP SDK. The initial path/typed-error
reader failures remain unchanged. Native current-run signals, canonical refusal
metadata and untimed condition waits are implemented and the new profile runs
in all six database jobs. Candidate qualification and promotion are recorded below. Expanded jobs
have a 45-minute ceiling after the selected-run profile expanded their work;
original run budgets and runtime caps stay fixed. The earlier 35-minute
MySQL cancellations remain cancelled records, as detailed in the
[selected-run qualification](rust-server-selected-run-signals.md).

Exact `6bac8f677` [run 38304548447](https://github.com/durable-workflow/server/actions/runs/38304548447)
passes five jobs and fails MariaDB. Independent original artifact review across
SQLite, PostgreSQL 16/17 and MySQL 8.0/8.4 verifies 135 paired observations,
270 original runs, 1,530 full HTTP sidecars, 9,585 wire exchanges and all 8,352
original member hashes. Each also passes 78 native execution tests.
MariaDB passes all PHP/embedded counterparts, but native's first failing update
in the Python-author/PHP-client direction returns an opaque storage 503. Its
fixed diagnostic identifies the post-commit update receipt lookup; the reason
that lookup found no row remains unknown. The first native namespace setup
separately exhausts its 75-second outer recorder ceiling without a phase sidecar.
Those [original failures remain unchanged](https://github.com/durable-workflow/server/pull/429#issuecomment-6132750228).

The recorder now preserves setup checkpoints and HTTP/arrival journals through
forced child loss, consolidating them only after a final control receipt.
The real locked-SDK regression passes and fails on the prior recorder source.
Always-run native state/limit receipts retain process-loss evidence. This changes
evidence retention, not SQL, HTTP semantics, retries or deadlines.

The subsequent original SQLite job fails in frozen PHP patch replay, before
namespace execution. Its original PHP Worker recovers a documented SQLite
workflow-poll pressure response and the cold replacement completes the same
run. The [reviewed reader correction](https://github.com/durable-workflow/server/pull/429#issuecomment-6133295115)
extends the existing strict PHP pressure check to that original PHP phase,
excluding language SDK producers. Exact response shape, original registration,
bounded retry hint, diagnostic, authority and successful same-phase recovery
remain required. Generic storage failures remain refused; Python/Rust retain
their distinct same-request retry contract. The unchanged observation passes
the corrected reader and still fails the original reader. All 4,817 pure-reader
guards pass; the unchanged SDK-dependent retention regression retains its
separate real-runtime qualification. No tuple or original deadline changes.
The original job and record remain failed.

An isolated exact SQLx 0.9.0/Tokio 1.53.2 MariaDB check now reproduces a pooled
stale read after an ordinary BEGIN reply is delayed and its future is dropped.
The returned session has a physical transaction but no logical transaction;
it misses an independently committed row that a fresh connection sees.
Rollback clears that stale view. The native pool now resets sessions before
return, with a five-second reset budget and disposal on failure. Pool size,
isolation, acquisition/lock budgets and public receipt/retry contracts stay
fixed. This adds a return-time database statement that later performance
qualification must measure. The real native-factory regression passes with
reset and fails `RowNotFound` without it; an actual committed row survives a
lost COMMIT reply. The [correction contract](https://github.com/durable-workflow/server/pull/429#issuecomment-6133464483)
precedes implementation. The injected mechanism is proven; its connection to
the original native failure is unestablished.

The first reset candidate passes SQLite/PostgreSQL but fails all three
MySQL-family jobs at the TLS disposal check. Exact TLS 1.3 reproduction confirms
that reset times out while the driver's hard-close path still awaits TLS I/O;
flushing before the gate produces the same failure. A scoped
[native driver patch](../rust/vendor/sqlx-mysql/DURABLE-PATCH.md)
drops the owned transport on hard close, matching SQLx's immediate-close
contract. Both unchanged TLS controls then pass with socket closure, freed
capacity, a different replacement connection and preserved committed data.
The official archive/VCS and licenses are retained; graceful close and TLS
trust/hostname checks stay unchanged. The original CI/probe failures remain
failed, and the [contract precedes adoption](https://github.com/durable-workflow/server/pull/429#issuecomment-6135933370).
Exact `64ca245e7` [run 38331809158](https://github.com/durable-workflow/server/actions/runs/38331809158)
passes all six original database jobs without retries. Independent original
artifact review verifies 162 paired namespace observations, 324 original runs,
1,836 complete HTTP sidecars, 11,421 wire exchanges and all 10,053 member hashes.
Every configuration passes 78 native execution tests; all three MySQL families
pass nine schema/pool qualifications including the unchanged TLS disposal case.
Pressure, role/fencing, real node recovery, PHP takeover refusal and TLS
trust/hostname gates pass. PHP/source passes 2,744 tests / 61,718 assertions,
with six existing deprecations and one skip. All three original SDK adapter
artifacts retain their frozen installed/source/archive identities and hashes.
The [candidate receipt](https://github.com/durable-workflow/server/pull/429#issuecomment-6136523527)
qualifies these cases; promotion-head, review/merge and fresh-main checks remain
separate gates. Fixture bytes and all original budgets remain unchanged.
The subsequent promotion head's PostgreSQL 17 job fails before namespace
execution in the forced setup-loss regression. Its proxy arrival journal is
visible before the held-request server callback necessarily runs; the test had
assumed those observations were simultaneous. The
[synchronization correction](https://github.com/durable-workflow/server/pull/429#issuecomment-6137161279)
waits for both observations before forced loss, preserving the original
10-second observation and 15-second test ceilings and every retained-stage,
request, deadline, credential and absent-response assertion. A controlled
250-ms forwarding delay reproduces the prior assertion failure and passes the
corrected guard; three ordinary locked-SDK runs pass all 49 selected tests
without skips. This changes only the test barrier, with no recorder, native
runtime, fixture, tuple or retry change. The failed original remains failed.
The subsequent exact-head and fresh-main matrices pass, as recorded in the
#429 delivery receipt above.

The [next proposed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6136891726)
is run-targeted signals. One
[published PHP/PHP reference pair](https://github.com/durable-workflow/server/issues/325#issuecomment-6137058011)
preserves two original completions, six cold authors, 127 wire exchanges and
zero task repairs. Accepted selected-current-run calls retain instance-scoped
commands; unknown, invalid and inactive signals persist rejected commands and
advance sequences without workflow history. Wrong-namespace and mismatched-run
guards mutate neither original run. A separate shared profile must check these
relationships, not just HTTP statuses. Six supported PHP/Rust client by
PHP/Python/Rust author directions, canonical embedded behavior and native/all
database qualification remain pending; Python 2.5.4 has no run-targeted signal
client method and remains an ordinary author in applicable directions.

[Workflow 2.5.13](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6137098532)
is a qualified published marker-bound correction at `19ba333a6fcae3447d35e499d8e1fb4969b96e48`.
The unchanged Server image still bundles 2.5.12; its dependency/rebuild and
consumer follow-through belong to the PHP maintainer. Any corrected shared
marker profile uses a separately qualified exact cohort, preserving frozen
marker/range histories and failures. The
[Rust SDK #80 published tuple](https://github.com/durable-workflow/sdk-rust/issues/80#issuecomment-6137962597)
is now qualified: 3.5.2 / `b9a82ddfd4589351ff64b33685464ac201ce40c4`,
registry archive `e9df4b3e10aada0d7f459a5fc5c8d58b4ec6829422bfc7edb5088047ad5b63b9`.
Six actual published-Server originals cover repeated `get_version` in `[-3,7]`
with one decision, fresh zero/deprecation-first helpers, and explicit same-run
range repair after completed-activity or timer-winner checkpoints. Constructed
version-1 differing-bound aliases are reader controls, not a genuine producer.
The [bridge-prefix receipt](https://github.com/durable-workflow/sdk-rust/issues/80#issuecomment-6138080982)
adds two cold mock-delivery reads of each unchanged marker prefix; full history,
clock, backend admission and takeover remain separate gates.
The [retry-snapshot agreement](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6138245641)
preserves all eight PHP backoff entries with `max_attempts=1`, requiring exact
explicit Rust authoring and strict stored-policy comparison. The SDK owner
retains implementation and qualification; captures cannot be trimmed or compared
by prefix. Corrected shared integration still requires the exact qualified PHP
Server consumer cohort. Frozen actors, dependencies and failures stay unchanged.

The [query-authority proposal](https://github.com/durable-workflow/server/issues/433#issuecomment-6138263790)
and [native/shared acknowledgment](https://github.com/durable-workflow/server/issues/433#issuecomment-6138337171)
agree on immutable original timing, six-digit UTC fields, inclusive authority
expiry, non-renewing protocol-1.20 observation and committed-receipt ordering.
Native implementation and separate shared qualification remain pending; current
internal timing does not establish compliance with the proposed wire contract.

Earlier MariaDB/MySQL failure causes remain
unestablished; the new lookup site does not retroactively establish them.
Full namespace authorization, other families, consumers,
migration, multi-node acceptance and matched performance remain open.

[#422](https://github.com/durable-workflow/server/pull/422) is merged and its
[fresh-main verification](https://github.com/durable-workflow/server/issues/325#issuecomment-6119269193)
is complete at e3ae32999d1e14813bf84498a716ad62cddf9813. Candidate and main share
tree eb07a0d709a18a09caac850da02d6c8092799e9a. All six database jobs, PHP/source
and boundary checks pass. Each original artifact independently passes 27 query
observations / 144 full query sidecars, 27 signals / 90 full signal sidecars,
and 76 native execution tests. All 2,041 selected original/archive hashes match.
Fresh main PHP/source passes 2,706 tests / 61,424 assertions. The SDK artifact
passes 297 original member hashes, nine thin comparisons and ten source hashes,
with frozen installed/source/archive identities unchanged. Historical failed
and cancelled jobs retain their original outcomes.

The update slice defines nine published SDK update-client/author
directions in [the qualified profiles](../tests/Fixtures/ServerParityProfiles/published-sdk-updates/README.md).
Its contract preserves duplicate request IDs and original typed results across
cold committed checkpoints, then an ordered finishing signal. Request arrival
is observed separately from durable acceptance.
[#424](https://github.com/durable-workflow/server/pull/424) implements normal
published SDK clients and four cold author processes, complete wire and arrival
capture, a strict reader, canonical embedded PHP and an isolated profile in
all six database jobs. The [final candidate qualification](https://github.com/durable-workflow/server/pull/424#issuecomment-6123569353)
passes at 93c71874b8b90b4383478deb00f7855a75051100. The
[fresh-main qualification](https://github.com/durable-workflow/server/pull/424#issuecomment-6124273589)
is complete at 5fd56e0c37933fdd0af459c965447c6168c4ad81; both have tree
61bdf074734941b493d8d0dd681303c9d61c6509. All six fresh-main original database
jobs pass on their first attempt. Across all six configurations,
162 update observations / 1,188 complete SDK sidecars, 162 query observations /
864 sidecars and 162 signal observations / 540 sidecars pass. Each database
also passes 76 native execution tests; all 3,497 original/archive hashes match.
PHP/source passes 2,716 tests / 61,466 assertions and both boundaries pass.
SDK preparation independently verifies 297 original member hashes, nine thin
comparisons and thirteen source hashes. The original candidate MySQL 8.0
25-minute cancellation remains unqualified; its single bounded retry passes
with the original tuple and budget. The
[source-only PHP integration window](https://github.com/durable-workflow/workflow/issues/753#issuecomment-6124274148)
is confirmed for a separate source cohort. Frozen published tuples stay unchanged.
The first completed update matrix fails in native discovery: unchanged Python
clients require the published `accepted`/`completed` wait-stage list before
submitting an update. Native discovery omitted that list despite implementing
both stages. The [original review and correction contract](https://github.com/durable-workflow/server/pull/424#issuecomment-6121480311)
preserve the failed original. Independent MariaDB review verifies all nine PHP
Server directions and nine embedded cases, plus 99 original PHP SDK sidecars;
that partial evidence does not qualify native or the failed job. Native now
publishes the implemented stages and checks discovery in its existing update
lifecycle/fencing regression. The failed original remains separate from the
completed candidate and main qualification.

The [failed-update boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6121730771)
preserves continued parent execution and is qualified in #427. The
[cohort contract](../tests/Fixtures/ServerParityProfiles/published-sdk-update-failures/README.md)
requires ordinary frozen SDK clients and authors, original accepted failure
identities, pending and completed duplicates, cold replay, and a later successful
update plus finishing signal on the same run. Preliminary SQLite PHP/PHP and
embedded recordings pass the complete lifecycle reader; the first PHP author's
incorrect accepted-input assumption remains a separate failed record.
[#427](https://github.com/durable-workflow/server/pull/427) implements native
failure-command validation, durable failure links, terminal HTTP 422 receipts,
duplicate identity and parent continuation, with database reopen regressions.
A shared normalization corpus is checked against the actual PHP failure rules.
Language-specific exception type, class and message remain observable; a failed
handler emits no successful `UpdateApplied`.

The nine-direction failure profile now runs after the successful-update profile
in all six database jobs. Its original candidate qualification and independent
artifact review permit promotion into the qualified profiles. The expanded
jobs have a 30-minute whole-job limit to include this additional workload;
individual scenario deadlines, frozen tuples and resource limits are unchanged.
The preceding `dc881518` run retains its original failures and cancellations
under the original 25-minute limit. The expanded 6121f343 matrix retains all six
failed runtime jobs, with
native test/build stages and PHP/source passing. A separate local diagnostic
passes all nine directions on published PHP Server, embedded PHP and native,
followed by a passing three-record comparison. Independent inspection binds
198 original HTTP sidecars and 27 persisted runs; all 349 retained raw archive
members match their original hashes. It
[reproduces a reader mismatch](https://github.com/durable-workflow/server/pull/427#issuecomment-6127074676)
on a completed native receipt: the frozen Python SDK hydrates nullable
`result` views recursively, including canonical control metadata. The reader
now checks both decoded views against their original Avro frame and exact typed
result, while preserving the original wire body and all other metadata. Altered
canonical values, frames and int64 values have focused refusal guards. The
original local failure remains preserved. A working GitHub connector path now
retrieves original CI bytes despite workspace download timeouts: the original
612 SQLite artifact independently confirms the hydration mismatch; its MySQL
8.0 log shows a frozen CLI download HTTP 504 before fixtures ran. Neither failed
whole job is relabeled.

The corrected-head SQLite job fails earlier in repeated-patch PHP polling,
before the failed-update profile. Its
[reviewed original recovery contract](https://github.com/durable-workflow/server/pull/427#issuecomment-6127538862)
preserves a declared SQLite lock-pressure reply, actual PHP retry diagnostic
and subsequent successful poll with unchanged worker, queue and options. The
reader checks original registration protocol/capabilities and a bounded retry
hint, acquires no hidden task, and retains every original marker, activity,
history and completion check. Published Python/Rust consumers retain their
separate same-request and HTTP Retry-After requirements. Frozen fixture bytes,
tuples, runtime payloads and scenario budgets are unchanged. Original failures
remain immutable. The subsequent 38def797 original matrix has five successful
database jobs and one MariaDB failure. In native Python/Python's later successful
update, two opaque storage HTTP 503 responses precede the cold author and the
strict recorder refuses. Original failure and cleanup-expired sidecars remain
intact; the original logs do not establish a driver cause or an independent SDK
defect. A [reviewed storage diagnostic](https://github.com/durable-workflow/server/pull/427#issuecomment-6128248670)
now emits only fixed error categories and bounded driver codes, preserving the
HTTP response and excluding error text, SQL, payloads and authentication data.
Five diagnostic tests, 30 library tests and 77 SQLite execution regressions pass
locally. The original 929638262 matrix and normal source checks pass. Independent
inspection verifies 162 failed-update observations and 1,188 original HTTP
sidecars across all six databases, plus successful-update, query and signal
families: 648 observations, 3,780 sidecars and 7,925 original member hashes in
total. Actual runner `1f091ae1` has reviewed tree `64a6729b` on base `5fd56e0c`.
Current-main integration remains a separate checkpoint. The original MariaDB
storage fault remains unresolved; these clean workloads are not a failure
correction or permission to accept generic 503s.
Validators, full query policies, queued controls,
unchanged consumers, migration,
multi-node acceptance, matched performance and release/cutover remain open.

[#408](https://github.com/durable-workflow/server/pull/408) qualifies nine
published parent/child language directions using the one-activity component
tuple. Its original
parent schedules one child on a separate queue, a child-only process commits
the exact typed result, and a cold parent-only replacement completes the same
run. Original call/link/child identities and full parent/child histories are
required. All six [candidate configurations](https://github.com/durable-workflow/server/actions/runs/38161889708)
and [main configurations](https://github.com/durable-workflow/server/actions/runs/38163439081)
pass, along with PHP/source and boundary checks. Independent official SQLite
and MySQL 8.0 artifact rechecks each pass 27 child records; SQLite binds all 54
actual PHP/native phase sidecars. The complete retained SQLite profile matches
all 97 official ZIP members and passes compressed byte round-trip. New recordings capture
effective database memory/swap limits separately from frozen historical
manifest envelopes. Namespaced children, retries, failures, cancellation
propagation, other policies and parallel groups remain separate gates.

The child reader exposed a native claim-field gap in retained published
observations: PHP uses `task_queue`, while native exposed only `queue`.
Native now adds the published field while retaining its existing alias.
The reader checks the actual poll selector and claimed durable queue, and
a database/HTTP regression covers separate parent/child queues and original
parent resumption. Both inspected configurations pass this regression and all
72 native execution tests. The [contract decision](https://github.com/durable-workflow/server/pull/408#issuecomment-6111939851)
and [completed main qualification](https://github.com/durable-workflow/server/pull/408#issuecomment-6112398187)
are tracked in #408. This qualifies one bounded child scenario in nine language
directions; it does not establish nine capability families or performance.

The additive timer profile qualifies normal published SDK authoring in PHP,
Python and Rust. Each requests 5.25 seconds of durable sleep, rounded to six
whole seconds, then cold-replays the original timer and run with a distinct
author process. The [reviewed next contract](https://github.com/durable-workflow/server/issues/325#issuecomment-6112280492)
requires original timer identity/deadline, full unchanged history, no early
firing or rescheduling, actual fenced receipts and exact int64/Unicode/null
Avro values. Worker adapters now call each published SDK's normal sleep API;
dedicated reader guards and isolated profiles are integrated into all six
database configurations. The existing SDK adapter build is reused. Local
reader guards pass, including corrupt original deadlines, stale receipts,
rescheduling, substituted Avro frames and one nanosecond early firing.
[#411](https://github.com/durable-workflow/server/pull/411) is merged with all six
candidate database configurations, normal PHP/source and boundaries passing.
The independently inspected current SQLite artifact passes nine timer
observations, all twelve full phase wire sidecars and all 55 original ZIP
member/archive roundtrip hashes. The MariaDB artifact separately passes nine
observations, 72 native execution tests and TLS checks with inspected 512 MiB
memory/swap caps. Candidate, actual PR runner and merged main have the same
qualified tree. [Automatic main qualification is complete](https://github.com/durable-workflow/server/pull/411#issuecomment-6113537310):
all six Shared configurations, PHP/source and boundary pass on that merge.
Candidate PHP/source passes 2,683 tests/61,212 assertions; main passes
2,683 tests/61,208 assertions. Both report six existing deprecations and one
skip. Completed source resources and branches are removed.

[#415](https://github.com/durable-workflow/server/pull/415) adds all nine
published PHP/Python/Rust signal-client and workflow-author combinations. Its
[initial correction run](https://github.com/durable-workflow/server/actions/runs/38182926674)
passes all six database configurations at `2de70bf8d029728c9cf7a8890461ae56628e34a8`,
with PHP/source and both boundaries passing. PHP reports 2,685 tests/61,266
assertions, six existing deprecations and one skip. Independent original
[SQLite inspection](https://github.com/durable-workflow/server/pull/415#issuecomment-6115075064)
passes all 27 observations, binds all 90 HTTP phase sidecars and verifies all
133 retained original/archive member hashes. Separate
[MySQL 8.0 inspection](https://github.com/durable-workflow/server/pull/415#issuecomment-6115110451)
passes 27 observations and 135 member hashes, with actual 1 GiB database limits
and TLS checks. Both original native outputs pass all 75 execution tests.
Complete signal profiles, tests and thin SDK proof are retained; duplicate
downloads and extraction scratch are removed. [Final candidate qualification](https://github.com/durable-workflow/server/pull/415#issuecomment-6116076532)
passes all six configurations at `75cb353b6c7fde323aa11bd98ff15372ee37d8cb`.
Merged main `567f3cd49e6bd49a35658d56c4ae638395511e0f` has the same tree
`f38c3e1555632400040377b4c5d04278f6e1061a`. Its [fresh main verification](https://github.com/durable-workflow/server/pull/415#issuecomment-6116428625)
passes five databases, PHP/source (2,685 tests/61,266 assertions) and boundary;
SQLite fails in the frozen PHP base child-cancellation fixture before signals.
Main all-six qualification remains unproven. Independent main MySQL 8.0
inspection verifies 27 signal observations, 90 complete HTTP sidecars and 135
original/archive hashes; SDK preparation independently verifies 297 original,
nine thin-byte and seven current-source checks.

An earlier integration exposed a separate frozen-PHP startup overlap:
[original SQLite failure](https://github.com/durable-workflow/server/pull/415#issuecomment-6115559242).
Five directions pass and the sixth completes its original 13-event run, but
the first worker claims `WorkflowStarted` before the successful start response;
PHP subsequently adds ten declaration fields to that same original event.
The strict full-history check fails. Original native output passes 75 tests,
but this failed profile never reaches embedded/native signal execution.
The full failed profile and wire bytes remain retained, with all 62 original
and archive-roundtrip hashes verified; duplicate downloads and scratch are removed.
The [PHP finding is handed off](https://github.com/durable-workflow/server/pull/420#issuecomment-6115559389)
without claiming a current-release reproduction or changing PHP/SDK code.
The [reviewed fixture decision](https://github.com/durable-workflow/server/pull/415#issuecomment-6115559242)
holds the original real workflow poll in the byte-preserving observer until
the matching successful start receipt. This bounds the cohort to post-start
delivery and cold replay. Full metadata/history guards remain strict; startup
overlap remains unqualified, and the failed record is not relabeled.
Focused source validation passes 61 signal models and five socket-based
transport cases; the current Node contract suite passes all 4,681 tests and
the changed PHP recorder passes syntax checking. The native Rust and SDK
adapter sources remain unchanged. The later candidate qualifies the bounded
post-start cohort on all six databases; the original overlap failure remains
retained and outside that claim.

The fresh-main SQLite failure is a distinct frozen Server 2.5.14 / Workflow
2.5.5 / PHP SDK 2.2.6 reference finding. Its original child cancels its pending
timer and closes, but an accepted cancellation is followed by an identical
rejected command and the SDK receives 409. A separate real SQLite writer-lock
fault reproduction proves that PHP can retry the whole cancellation after its
commit when publishing the parent's delivery hint encounters contention. One
ordinary SDK request produces two commands; no retransmission or observer
reentrancy occurs in that reproduction. This identifies a matching source
boundary, without claiming the original run's exact lost exception or a current
released-image reproduction. The [PHP correction is handed to General](https://github.com/durable-workflow/server/issues/325#issuecomment-6116357816),
who acknowledged ownership of correction and current-release qualification.
The original failed main record, frozen tuple and strict accepted/duplicate
receipt guards remain unchanged. Release and cutover qualification stay held.

The original normal worker registers its declaration before the observer starts
the run, and its first workflow claim follows the observed successful start
receipt. Two distinct normal clients send identical exact Avro payloads; three
cold author processes retain both accepted identities and full original
history through completion. The [reviewed scope](https://github.com/durable-workflow/server/issues/325#issuecomment-6113352490)
uses the instance-current-run API, preserves Python's absent client return and
retains actual server receipts. Canonical embedded direct-signal histories stay
intact; the separate manifest preserves prior tuples and resource envelopes.
Normal accepted signals require the published HTTP 202 contract. All 60 signal
reader models and all 4,482 reader tests pass, alongside the recorder, language,
shell and Docker isolation checks. Exact SDK preparation, source and package
hashes pass; those model/build results remain distinct from runtime evidence.

Normal Rust authoring exposed two native gaps. The
[occurrence correction](https://github.com/durable-workflow/server/pull/415#issuecomment-6114363122)
retains nonempty authored condition occurrence IDs through opening, restart and
matching satisfaction, omitting absent/null/empty values as PHP does. The
[variadic correction](https://github.com/durable-workflow/server/pull/415#issuecomment-6114821113)
accepts zero or more tail values after required positional arguments, validates
each value without coercion and preserves int64/float/null distinctions. Three
database/HTTP regressions cover repeated waits, cold reopening, duplicate/stale
completions, optional occurrence types and exact variadic values/refusals.
Earlier failed records and the separate frozen SQLite patch-pressure failure
remain unchanged. Defaults, optional fixed prefixes, non-final variadics,
older-protocol occurrence routing and broader signal policies remain separate
unqualified gates. Normal published SDK query directions are the next breadth
slice; matched performance and cutover remain open.

[#401](https://github.com/durable-workflow/server/pull/401) qualifies all nine
fresh PHP/Python/Rust workflow-author and separately
routed activity-worker directions. An original author schedules one activity,
an activity-only process commits it, and a cold workflow process completes the
same run with unchanged history prefixes and exact typed Avro values. Installed
package identities and real HTTP phase receipts are required. Embedded executes
the canonical PHP definition and labels language transport inapplicable. Its
separate tuple preserves every earlier cohort. All six candidate configurations
pass [Shared qualification](https://github.com/durable-workflow/server/actions/runs/38151936175),
[PHP/source checks](https://github.com/durable-workflow/server/actions/runs/38151936179)
and boundaries. Independent inspection of the official SQLite artifact rechecks
all 27 logical records and binds all 54 separate PHP/native wire sidecars to
their actual process, phase, original run and recorded request/receipt fields.
The complete retained profile compares exactly to the official ZIP members and
passes a compressed archive byte round-trip. Its raw objects remain intact
despite PHP's associative empty-contract-map observation projection.
Merge `b5a6e8d1e478d43af5219c560ec392e6d17258aa` retains the qualified tree;
post-merge checks and their outcome are tracked in #401. Its first main run
passed five runtime configurations; PostgreSQL 16 completed every comparison,
cleanup and artifact upload, but exceeded its 15-minute job limit. Its retry
retains that exact merge; the outcome is tracked in #401. PostgreSQL receives
the same bounded
20-minute allowance as MySQL, including cleanup and evidence retention.
The MySQL 8.0 fixture database receives the existing MySQL 8.4 allowance of
1 GiB, with swap disabled: its 768 MiB container was killed for exceeding that
limit after all comparisons passed, preventing the final trusted TLS probe.
These are CI resource allowances, not performance qualification limits.
This is one authored
workflow/activity scenario in the default namespace, with nine language
directions; it does not establish nine capability families or performance.
Separately routed parent/child SDK directions are qualified in #408.
Normal SDK timer authoring and cold replay are qualified in #411; normal signal
client/author directions are implemented with runtime qualification pending.
Broader signal policies and query/update clients and authors remain ahead.
The observed activity-only Python polling defect is handed to
[SDK Python #119](https://github.com/durable-workflow/sdk-python/issues/119).

[#399](https://github.com/durable-workflow/server/pull/399) implements and
qualifies native pending mixed-selection repair. Its timer winner resumes a
cold incompatible author while the separately routed activity remains pending.
Explicit repair restores that original blocked workflow task; compatible replay
waits, an activity-only worker executes the original activity once, and a later
distinct task completes the same run. All six configurations pass the
[exact-source Shared gate](https://github.com/durable-workflow/server/actions/runs/38141199951),
[PHP/source checks](https://github.com/durable-workflow/server/actions/runs/38141199897)
and boundaries. The 18 actual PHP HTTP/embedded/native recordings verify this
one scenario, including unchanged waiting history and exact typed Avro output.
Merge `2dbf05dcb4cfab8fc89973e24245438a167e3d37` retains the qualified tree;
[post-merge Shared verification](https://github.com/durable-workflow/server/actions/runs/38142464272)
and [PHP verification](https://github.com/durable-workflow/server/actions/runs/38142464238)
pass, with all six main configurations and actual retained pending records.

[Workflow #755](https://github.com/durable-workflow/workflow/issues/755) is
closed as delivered: published Workflow 2.5.10 and Server 2.5.20 repair the
blocked workflow task before the older activity. Reference #397 passes all six
candidate and main configurations. The pending cohort keeps those exact
published artifacts and PHP SDK 2.2.7; its earlier failed recordings and
completed-activity range case #392 remain unchanged. Broader selection/repair,
SDK and consumer directions, matched performance, migration and cutover remain
open under #325. Native execution transitions are still serialized.

The pending `worker-registration-fencing` fixture defines the first shared
Server #358 lifecycle slice on a separate exact PHP/SDK source tuple. It retains
an original leased echo across token rotation, heartbeat, terminal refusals,
original receipt replay after replacement, two explicit repair commands and
completion by a real SDK worker. Embedded execution runs the authored echo and
marks HTTP registration inapplicable. The source cohort is separate from
published feature qualification; the default 29 fixtures and frozen baseline
remain unchanged. The
[source-reference matrix](https://github.com/durable-workflow/server/actions/runs/38050246000)
passes on all six configurations in [#363](https://github.com/durable-workflow/server/pull/363).
All artifact digests match; independent comparisons pass 12 source-fencing,
522 default and 18 role-profile observations. These #363 source results cover
PHP and embedded execution only.
[#366](https://github.com/durable-workflow/server/pull/366) persists registration
incarnations in the registration transaction and passes all six main checks.
Development schema
version 4 adds native bookkeeping without changing frozen PHP relations or
rewriting version 3. Older unpublished native databases remain refused without
mutation; qualified in-place takeover remains a separate gate.
[#367](https://github.com/durable-workflow/server/pull/367) implements native
fenced deregistration: original-task recovery, accepted repair command/history,
registration deletion and terminal receipt share one physical transaction.
Completed receipt replay precedes replacement authority lookup. The unpublished
runtime exposes the registration token and fencing capability; it refuses
unknown, superseded and expired tokens without acknowledging shutdown.
Each registration removes at most 64 expired completed or superseded receipts
in its namespace after ten minutes; active identities stay intact. Explicit
native maintenance remains a separate gate.

[#370](https://github.com/durable-workflow/server/pull/370) releases original
workflow leases when the same worker ID registers again, in the same transaction
as token replacement. It preserves the original history and repair count, as
the exact PHP source does. Native restart, namespace and token-insert rollback
cases and the two-case source profile pass on all six configurations; main
verification also passes. Activity and sticky-lease replacement remain open.
[#371](https://github.com/durable-workflow/server/pull/371) adds actual SDK
receipt reconciliation after explicitly client-injected reply loss following
real HTTP 200 commit. A replacement owns the same original task before the SDK
retries its unchanged token. All six PR configurations pass and their official
artifacts independently compare 522 default, 18 role and 54 source observations.
Models and this observed shutdown timing do not qualify performance or the
complete shutdown budget. Main verification is recorded in #371.

[#372](https://github.com/durable-workflow/server/pull/372) qualifies a real
independent native database transition lock through the existing five-second
timeout on all six configurations, including main verification. It checks
concurrent health reads, actual retryable HTTP 503 / Retry-After, unchanged full
original reads/history, original-attempt completion and same-token repair/replay
of another original task. Each cell passes 56 native tests; the independently
compared default, role and source cohorts total 594 observations.

[#373](https://github.com/durable-workflow/server/pull/373) qualifies shared SDK
temporary database pressure on all six configurations. An independent writer
remains held through actual HTTP 503; after release, an original authority task
completes and the SDK retries its original token using a smaller remaining
request budget. Independent artifact comparisons verify 612 default, role,
source and pressure observations. The separate fault manifest declares
five-second reference limits and labels actual before/after PHP application CLI
session samples separately from HTTP/native sessions. SQLite can refuse
immediately despite its sampled busy timeout. Existing binaries and exact
PHP/SDK sources and locks are reused. Main verification is recorded in #373.

[#374](https://github.com/durable-workflow/server/pull/374) qualifies persistent
SDK shutdown pressure and exact original-worker-error precedence on all six
configurations. The independent writer stays held until the SDK returns after
spending its original ten-second budget. A declared invalid fixture credential
on one real HTTP poll produces the original 401 error; shutdown failure remains
separately visible. After release, a control client reconciles the original
token because a timed-out request may still commit. Immutable receipt replay
and original-peer completion check each repair once. Official archives and
raw comparisons independently verify 648 observations. Main verification is
recorded in #374. Complete authority/error families, explicit native receipt
maintenance and exact published-artifact qualification remain open under #358.

[#375](https://github.com/durable-workflow/server/pull/375) qualifies insertion
before an original pending or completed activity. Separate actual worker
processes choose the legacy branch twice without shifting sequence 1, adding a
marker or repeating the activity. All six exact-head configurations pass;
independent official artifact comparisons verify 684 observations, including
36 new published PHP SDK 2.2.6 / embedded Workflow 2.5.5 deployment controls.
The frozen 29 and old source/fault tuples remain unchanged; main verification
is recorded in #375. Python/Rust SDK directions and corrected published
artifacts remain separate qualification gates.
SDK production repairs belong to
[Python #103](https://github.com/durable-workflow/sdk-python/issues/103) and
[Rust #80](https://github.com/durable-workflow/sdk-rust/issues/80). Existing
Python duplicate-marker histories require a separate explicit compatibility
case; they must not silently be treated as malformed disposable histories.

[#376](https://github.com/durable-workflow/server/pull/376) implements native
`record_version_marker` with its canonical payload and original task annotation,
and includes marker positions in subsequent authored sequence calculation.
All six exact-head configurations pass; independently checked official archives
qualify 720 observations, 58 native tests per configuration, 23 SQLite codec
tests and 1,283 comparator tests. Single-call cold controls preserve marker
sequence 1 and original pending/completed activity sequence 2. Main verification
is recorded in #376. Separate fresh repeated-call fixtures stay
pending after actual embedded Workflow 2.5.5 recorded duplicate markers in the
completed-activity case; the pending case was not executed after that failure.
[Workflow #744](https://github.com/durable-workflow/workflow/issues/744) owns
the engine repair. Deprecation, arbitrary version ranges, repeated fresh calls
and cross-SDK duplicate-marker histories remain separate shared gates.

[#377](https://github.com/durable-workflow/server/pull/377) ports the workflow-task
`/fail` waiting acknowledgement used by
published SDK pending replay. Two pending controls distinguish the structured
`WorkflowTaskWaitingForHistory` type from PHP's retained message fallback.
An actual timer resumes a leased replay while the original activity is still
pending. Acknowledgement must release that lease without adding failure history,
leave the original run waiting, and allow the same activity and run to finish
with their exact typed value and immutable history prefix. HTTP lease fencing
and replay refusal are part of this slice. Embedded mode executes its authored
echo and explicitly marks this service-only endpoint inapplicable. General
task-failure retry/blocking policies, pending signal/update draining and the
actual Rust SDK worker direction require separate qualification. All six configurations
and main PHP verification pass. Independently checked official archives qualify
756 observations, 61 native tests per configuration, 23 SQLite codec tests and
1,331 comparator tests. The new controls comprise 24 actual HTTP exchanges and
12 explicit embedded inapplicability records; main PHP reports 2,625 tests and
58,522 assertions.

[#378](https://github.com/durable-workflow/server/pull/378) qualifies the separate
published Rust SDK 3.4.2 / Python SDK 2.5.1 cold-replacement profile after an
original PHP-authored activity checkpoint. Starts precede an advertised worker
fingerprint, and the original worker declares one-second affinity. Both legacy
patch calls choose false, preserve activity sequence 1 and finish the original
run with unchanged history and exact typed int64 output. All six candidate and
main configurations pass. Independent official archive comparisons verify 828
observations: the four new fixtures add 48 actual HTTP executions and 24 embedded
authored counterparts across the matrix. Each cell has 61 native tests; SQLite
has 23 codec and 1,517 modeled comparator tests. PHP reports 2,625 tests and
58,522 assertions. Native discovery now includes the exact PHP carrier auth
manifest; the byte observer forwards background query cancellation and requires
successful same-request pressure recovery. The locked SDK adapter builds once
per workflow and shares thin artifacts without target trees or dependency caches.
Worker-fingerprinted migration, immediate sticky failover, complete fault budgets
and performance remain separate gates.

The `repeated-patch-clock` profile in merged
[#379](https://github.com/durable-workflow/server/pull/379) qualifies two fresh repeated-call
checkpoints against the published PHP HTTP reference and installed embedded
Workflow 2.5.7. The old Server image still packages Workflow 2.5.5; its component
version is explicit in this new tuple. The embedded cold replacement must observe
the first marker's recorded clock after both calls. Normal Composer installation,
actual installed references and hashes of four autoloaded replay classes bind
both embedded processes to the selected published source. The profile also
freezes the complete embedded dependency lock to prevent registry drift.
The [exact candidate matrix](https://github.com/durable-workflow/server/actions/runs/38086396368)
passes all six configurations. Independent official archive, source and raw
recording checks verify 864 observations, including 24 new actual HTTP
executions and 12 embedded clock observations. These represent 48 selected
fixture cases across three targets and six configurations, rather than 864
distinct semantic families. Both repeated calls keep one original marker at
authored sequence 1, activity sequence 2 and the original run/history prefix;
every captured embedded replay clock retains the marker's full microseconds.
Each configuration passes 61 native tests. SQLite adds 23 codec and 1,542+77
modeled comparator checks; PHP reports 2,625 tests and 58,522 assertions.
The merged tree matches that candidate; main verification is tracked in #379.

Historical failures remain explicit in #379: an earlier MySQL database loss
has an unconfirmed cause, and a later comparator incorrectly rejected PHP's
published optional pressure diagnostics. The corrected guard validates those
fields while retaining a null task, exact status, positive bounded Retry-After
and successful recovery with the original request bytes. Raw source/transport
evidence remains unchanged. All five final database states and their original
512/768 MiB limits were checked independently. CI
reuses its existing native binary and HTTP targets, installs only the separately
selected embedded engine and gives it a new isolated database. The frozen baseline and other profiles
stay unchanged. Retained duplicate-marker cross-SDK histories need a separate
real published-worker fixture; source inspection alone does not prove that
execution. Workflow #744 owns the published engine repair and its downstream
follow-through. A Server package pin retains #358's fault/budget and unpublished
PHP fencing/schema release gates; it does not qualify a new Server release.

The separate `legacy-marker-aliases` profile executes genuine published authors:
Python 2.5.0 for one- and two-marker histories, and Rust 3.4.3 for fresh marker
deduplication. A separate cold Rust 3.4.3 worker resumes each original run after
an activity checkpoint. Its four fixtures cover a pending activity, a completed
activity, a single-marker control and fresh deduplication. Both retained same-ID
markers keep their original positions and timestamps; the original activity and
exact typed int64 output complete once.

Embedded counterparts normally install Workflow 2.5.5 for the retained-history
author and Workflow 2.5.7 for the cold reader, each with its complete dependency
lock and actual autoloaded source hashes. The two-marker controls observe both
distinct original microsecond clocks after the corresponding replay calls;
single-marker and fresh controls reuse the first clock. Each database job builds
neither SDK: a preparation job builds the locked published adapters once using
its existing registry/target cache, then shares only binaries, dependencies and
installation receipts. All four local fixtures and their raw observations pass
on PHP, native and embedded targets. Qualification across the six hosted
database configurations is tracked in [#382](https://github.com/durable-workflow/server/pull/382).
The profile explicitly checks published poll-capacity refusal: a null task,
one-second retry hint and successful recovery with identical worker, queue and
poll options. The published worker may choose a fresh poll identity after this
explicit no-task admission refusal. An idle query worker may instead withdraw
after its own accepted commit; workflow/activity polls require actual recovery.
Backend-lock pressure still requires the same original poll identity.
Immediate sticky failover, blocked
repair, migration, full fault budgets and performance remain separate gates.
Its opt-in HTTP observer records whether the complete request body arrived and
whether an upstream request started. A locally cancelled, incomplete poll has
no inferred worker identity; every active registration must have committed
before cancellation and receive its own accepted completion before authenticated
withdrawal. A forwarded cancellation requires its actual worker and queue with
the same commit and withdrawal checks. Completion
and failure requests cannot use this shutdown exception. Frozen profiles retain
their original wire-record schema.

The qualified #382 candidate passed all six configurations, but its first
merged-source SQLite check exposed a transport-test race: a filesystem creation
notification can arrive before the complete first JSONL receipt. That original
failed artifact is retained in #382. The test now waits up to two seconds for a
newline-terminated receipt before parsing it; all byte, non-forwarding and
zero-upstream assertions remain required. The observer and runtime are
unchanged. Fresh qualification of this correction is tracked separately.

The #384 candidate passed all six configurations, but merged-source MySQL 8.0
exposed a separate ordering assumption. Its actual original-run history showed
`ActivityScheduled` and `ActivityCompleted` before the respective workers
cancelled idle polls; their completion acknowledgments arrived afterward. The
observer records response completion, rather than database commit order. This
profile retains history event prefixes in its read summaries and requires either
an earlier own accepted completion or an earlier original-run checkpoint with
the exact retained prefix, task identity and lease owner. Each worker must still
receive its accepted completion before authenticated withdrawal. Missing, late,
foreign or changed checkpoints and lost-task responses are rejected. The failed
run stays unqualified; runtime, observer and published inputs are unchanged.

The #386 correction passed a fresh six-configuration candidate run with an
independent 954-observation audit. Its separate merged-source MySQL 8.0 run
exposed a native history gap: `ActivityScheduled` omitted the completing
workflow task's snapshot. The original checkpoint preceded idle cancellation,
but its accepted response arrived afterward. Frozen PHP task-owned events
retain that snapshot, as native markers and completion events already do.
Native scheduling now writes the leased task snapshot in the same transaction
as the activity event. The execution regression checks the original task and
lease owner, later-turn task identity, and immutable history after restart,
duplicate/stale completion and final completion. The checkpoint checker stays
strict. [#388](https://github.com/durable-workflow/server/pull/388) merged the
correction at e0960964. Its candidate and
[main matrix](https://github.com/durable-workflow/server/actions/runs/38107240820)
pass all six configurations, with 65 native tests per cell and independently
audited 954 shared observations, including the real task snapshots. The first
main MariaDB attempt stopped on a Composer download HTTP 504 before its tests;
the bounded failed-job retry passed. That first artifact remains unqualified.

The next range/repair investigation reproduced a terminal application failure
on normally installed embedded Workflow 2.5.7 when cold code excludes recorded
version 1. Restoring the original definition and requesting same-run repair
returns `rejected_not_active`. [Workflow #744](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6104611717)
records that unchanged reference observation. The separate published PHP Server
2.5.14 / PHP SDK 2.2.6 HTTP diagnostic blocks the affected replay and repairs the
same original task after compatible source is restored. The
[reviewed decision](https://github.com/durable-workflow/server/issues/325#issuecomment-6104832243)
treats an out-of-range recorded version or legacy default as a replay
compatibility fault: preserve the original nonterminal run, history, marker and
completed work; restore compatible code and explicitly repair with a new fenced
attempt. Already closed runs stay closed.

[Workflow #749](https://github.com/durable-workflow/workflow/pull/749) implements
that source behavior with a specific range exception derived from the existing
base exception, a blocked task and diagnostic range metadata. Ordinary
application exceptions retain their terminal behavior. A new persisted-marker
corpus fixture exercises executor, fiber and query replay; the real repair case
preserves one activity, the complete original history prefix and exact
int64/null/Unicode values. Three cold embedded processes and unchanged published
PHP SDK HTTP diagnostics independently verify the source change.
[Full source qualification](https://github.com/durable-workflow/workflow/actions/runs/38111925625)
and [post-merge main](https://github.com/durable-workflow/workflow/actions/runs/38112937931)
pass MySQL/PostgreSQL feature suites, representative MariaDB checks, Laravel
9–13 upgrades and the aggregate coverage gate (93.45% against 93.08%).

[Workflow #750](https://github.com/durable-workflow/workflow/pull/750) published
the 2.5.8 patch at 58f387d3. Its
[published package check](https://github.com/durable-workflow/workflow/actions/runs/38114525265)
passes the contract and all 16 Laravel/PHP upgrade cells. A fresh Packagist
distribution install and three cold embedded SQLite processes verify the
original nonterminal run, immutable history prefix, one completed activity and
explicit repair of the same blocked task after byte-identical source restoration.
Independent loaded-source and PHP output audits preserve the published identity
and exact int64/null/Unicode values. The
[published qualification record](https://github.com/durable-workflow/workflow/issues/744#issuecomment-6105840885)
distinguishes that embedded proof from the later Server image and consumer
directions. [Server #389](https://github.com/durable-workflow/server/pull/389)
delivered the normal 2.5.18 image and Helm 0.1.155 at c71576fc, using the
published Workflow source and preserving every other locked package identity.
The [published lifecycle run](https://github.com/durable-workflow/server/actions/runs/38118836424)
passes all 12 declared cells, and the
[published Compose run](https://github.com/durable-workflow/server/actions/runs/38118837959)
passes both architectures in local and production modes. A separate unchanged
published PHP SDK probe verifies range blockage after one completed activity
and explicit repair of the original task at attempt 2, repair count 1, preserving
the history prefix and exact int64/null/Unicode output. The
[delivery receipt](https://github.com/durable-workflow/server/issues/325#issuecomment-6106407301)
records the exact artifacts and the limits of those checks.

The separate `version-range-repair` profile freezes that published cohort for
three cold author phases: original compatible code, incompatible range, and
byte-identical restored code with explicit repair. The HTTP and embedded
adapters observe actual SDK requests, durable history and task diagnostics.
The expanded correctness run gives its MySQL 8.4 database 1 GiB after a retained
768 MiB OOM during the later pressure profile; application and author processes
keep one CPU, 512 MiB and no swap. Database caps are declared in the profile.
Reference execution and native qualification remain tracked separately. The
native follow-up implements the worker-reported range blockage as a failed,
released original task with no automatic retry or terminal failure history.
Empty-body selected-run repair restores that task to ready, clears replay-block
metadata and records the published instance-targeted command with official
Avro values and server-derived audit identity. [#392](https://github.com/durable-workflow/server/pull/392)
owns source qualification and the actual three-runtime recordings; its normal
six-configuration differential gates must pass before merge. Modeled readers
and source inspection are not runtime proof. Repair checks the physical
32-bit PostgreSQL/unsigned MySQL or 64-bit SQLite counter bounds before changing
the task, command or history.
The physical debug read covers only this default-namespace checkpoint, with
pending or completed activities, no ready/leased workflow task, no messages,
no cancellation and no application failures. It exposes actual blockage and
failure identity; broader task-queue, compatibility, payload-preview and
cancellation diagnostics remain unqualified. Manual repair of other shapes,
named namespaces and historical/closed-run outcome parity remain separate gates.
The pending-activity case also needs a legitimate cold wake, because repair of
ordinary waiting work does not create a ready workflow task. Frozen fixture
inputs and earlier observations retain their original versions and digests.
This slice does not establish performance, takeover, another SDK author language
or repair while an activity is still pending.

The pending checkpoint is now a separate qualified PHP HTTP/embedded reference
in [#397](https://github.com/durable-workflow/server/pull/397), merged as
`b70ba209384a0959e2c61598c1f8e5bd08c14d9f`. The timer wins a real mixed select
while its activity has never started; an incompatible cold version author
blocks replay. This exposed [Workflow #755](https://github.com/durable-workflow/workflow/issues/755),
where manual repair selected the older activity. Published Workflow 2.5.10 and
Server 2.5.20 correct selection while retaining the truthful activity wait kind.
All six [candidate database jobs](https://github.com/durable-workflow/server/actions/runs/38135489945)
pass actual PHP HTTP and embedded recordings: the repaired original task waits
without changing history, one original activity executes, and a distinct later
workflow task completes the same run with 11 events. Exact post-merge main
[Shared fixtures](https://github.com/durable-workflow/server/actions/runs/38136690281),
[PHP/source](https://github.com/durable-workflow/server/actions/runs/38136690284)
and [boundary](https://github.com/durable-workflow/server/actions/runs/38136690280)
also pass; Workflow #755 is closed as fulfilled. The failed published tuple,
its evidence and the completed-activity reference remain unchanged.

Native follow-through in #399 implements the same checkpoint with complete mixed
activity/timer group metadata and a first committed `SelectionResolved` that
binds the actual terminal history event. Member resolution and wakeup share the
existing database transition lock; a losing member retains its original work
and cannot overwrite the winner. Explicit range repair admits only the
timer-won, unclaimed pending-activity shape, preserves its row/attempt and
restores the blocked workflow task. Messages, cancellation, running activities,
additional open work and unsupported groups remain refused. Native regressions
cover actual HTTP transitions, two-node timer recovery, original-task repair,
waiting replay, stale fencing and whole-batch rollback. The exact-source
six-configuration qualification includes actual published-author native records.
An activity-first regression also preserves the committed winner across a
restart and losing timer, with one outstanding workflow wakeup. Failed/retrying
selection activities are explicitly refused before mutation until their own
shared fixture qualifies that behavior.
Wakeups coalesce only a ready task. A leased worker can hold an older history
snapshot, so a later member keeps a new ready task even if that worker then
acknowledges waiting; a dedicated HTTP regression covers this interleaving.
Native scheduling explicitly initializes unclaimed activities at zero, matching
both PHP references; only an actual worker claim consumes an attempt. The
physical schema and earlier durable data/history remain unchanged. Winner
regressions query the actual persisted event at its public history sequence to
verify the physical relationship; public history intentionally omits internal
event IDs. Native registration accepts the published PHP activity-only worker's
empty contract map representation `[]` and stores an empty map. Nonempty arrays
and scalars remain refused, with history/pending-work preservation checked.
Failed and cancelled candidates retain separate diagnostic receipts in #399.

Actual SQLite execution passes 71 tests. All 18 pending records preserve
5 → 7 → 8 → 8 → 11 events, three cold author PIDs, activity invocations 0/0/1,
the original timer winner and blocked-task repair, a distinct completion task,
and exact int64/Unicode/null output. All six official artifact sizes and digests
were independently matched against their API and uploader receipts. Candidate
`6d40e2d3` and actual checkout `8bf75a7` have tree
`80938cdf8ce0d38965c9006d4ab2745bd8141415`, retained by the merge.

The separate `pending-native` profile binds the same published artifact bytes
and unchanged fixture under a new qualification scope, including the SHA-256
of the qualified reference manifest. Its three isolated runtime recordings
replace the two-reference invocation in normal six-configuration gates; the
original `pending-reference` invocation and frozen manifest remain available.
The source keeps broader selection/repair, another author language, matched
performance and takeover as separate gates. No local Cargo/target tree is
required for this follow-through; normal hosted jobs compile the exact source.

[#381](https://github.com/durable-workflow/server/pull/381) implements ordinary
workflow-task failure retries on the original run. A failed lease and its new
ready task commit together, carrying attempt count and routing without adding
failure history. The explicit fixture reports two real errors, checks stale,
duplicate and late-outcome refusal, then completes once with exact typed output.
The frozen PHP control established that this run stays `pending` during retry
and a terminal late failure reports `run_closed`. Native SQLite restart and
independent-pool races pass; full six-configuration qualification is tracked in
#381. The frozen default corpus stays unchanged. Embedded executes its echo
control and explicitly marks the service-only retry endpoint inapplicable.
Replay-blocking failures are still refused rather than automatically retried;
blocked replay/manual repair, message-bearing retries, nondefault namespaces,
complete retry/backoff policy and published SDK worker directions remain gates.

The source profile now includes Rust on an independent database using the
already compiled binary. Five dedicated native tests check original-task
completion after restart and replacement, concurrent independent pools,
final-receipt-write rollback on each engine family, expired/pruned receipts,
and two namespaces with live original leases and the same worker ID. Repair
commands are checked in storage as well as history. All six PR and main
configurations pass in #367. Next: shared real contention, SDK retry budgets and original
failure precedence, then native receipt maintenance and exact published
PHP/SDK artifacts. No feature publication or performance result is claimed.

The separate reviewed `role-tokens/admission-role-tokens` profile completes an
original authored echo using the unchanged published SDK and the worker token.
Sixteen real HTTP requests check exact worker/operator/admin membership,
disabled legacy full-access bypass and authentication → role → protocol →
namespace precedence. Operator, admin and legacy-admin reads succeed; refusals
preserve an original unclaimed peer's complete description and history; an
operator then cancels that same peer. Embedded execution checks the authored
lifecycle with HTTP authorization explicitly inapplicable. The default corpus
remains 29 legacy-token fixtures; the role profile uses independent databases.

Tests-first `8c3564e5` reproduces both native failures on all six configurations
in [run 38045677626](https://github.com/durable-workflow/server/actions/runs/38045677626):
role credentials return 401 instead of role 403, and the legacy credential still
claims a worker task with 200 instead of 403. Each configuration retains 42
existing passing HTTP tests. The guard now matches configured roles before the
legacy credential, disables legacy bypass when any role is configured and
checks endpoint membership before protocol and namespace lookup. Immutable
configuration survives namespace selection. The already locked
[subtle 2.6.1 primitive](https://docs.rs/subtle/2.6.1/subtle/trait.ConstantTimeEq.html)
compares secret contents without a new transitive dependency.

The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38046430334)
on head `c2901404881ebbf7fd88e67d241cbf1ff7c7527b` and runner merge
`8ac16e25e4ea0e48c00ac3bf12f886025d5f03f7` has identical tree
`d48bf2aca0d695e396a51de97a4872425a851152`. All six configurations pass
the default corpus and the separate role profile. All downloaded artifact
digests match; 522 default and 18 role observations independently compare,
including 192 role-profile HTTP requests. Each configuration passes 45 native
HTTP tests; SQLite passes 21 library tests. All 840 modeled comparator checks
and two original/19 deliberately corrupted reference rechecks pass; these are
not additional workflow executions. PHP/source passes 2,621 tests and 58,473
assertions, with six existing deprecations and one skip. Final qualification of
the permanent profile path is recorded in [#362](https://github.com/durable-workflow/server/pull/362).
The cohort reuses the existing CI binary; owned cleanup always runs.

Unqualified principal, custom-provider, signature/no-auth, runtime-credential,
alias-role and disabled-backward-compatibility settings refuse startup before
opening storage. The development runtime still requires `DW_AUTH_TOKEN`.
Auth environment values must parse as UTF-8. Invalid text cannot become an
absent role credential and restore legacy bypass; empty driver and
backward-compatibility settings refuse explicitly. Actual native process
checks exercise raw environment bytes before storage selection.
Principal/tenant/runtime credentials, role-only configuration, signatures,
namespace administration and actor attribution require separate qualification.
Full authorization, performance, takeover and cutover remain open.

The twenty-ninth reviewed fixture, `namespace-isolation`, creates two named
namespaces and completes two original authored activity workflows. HTTP uses the
unchanged frozen PHP SDK, one queue and the same worker ID in both namespaces.
Five foreign control requests and three foreign task completion attempts preserve
the original descriptions and histories. Workflow IDs remain globally reserved;
worker IDs, poll receipts and leases are scoped by namespace. Completing and
deregistering the first worker leaves the second namespace's original run and
registration intact. Embedded mode supplies host namespace binding and executes
real database-queue jobs; HTTP administration and registration are inapplicable.

Frozen PHP/embedded reference source `55de4c86` passes and independently compares
both original observations. Corrected tests-first `cb3cf3e5` reproduces both
namespace failures on all six databases while retaining 39 existing passing HTTP
tests. Failed recorder/helper revisions and original recordings are retained in
[#361](https://github.com/durable-workflow/server/pull/361).

Actual PHP keeps the first leased workflow `pending`, then retains `waiting`
before activity/replay commits. Native polling now preserves that authored
lifecycle. Foreign-target refusals retain original workflow/run/task/attempt
identities, canonical diagnostics, complete control contracts and worker
protocol/capabilities. The unchanged SDK also polls the query queue during an
activity workflow. That idle poll now scopes registration and cached-task lookup
to its namespace; a native regression preserves a default-namespace same-ID query
lease through a foreign poll and its original completion. Named query authoring
and completion require separate qualification.

The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38043263104)
for head `9b5e77664b42d0526aa80065cd3f32af25954c8b` tests merge revision
`91b49964a84d34d74d0d60621b554fe972f0976e` with current PHP main. All 29
PHP/Rust/embedded cases pass on SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB
10.11. All six downloaded artifact digests match and 522 actual observations
independently compare, including 18 namespace observations. All 42 native HTTP
tests pass per configuration, SQLite passes 21 library tests, the existing 810
modeled comparator checks pass and 54 original/corrupted-reference rechecks pass.
The latter rechecks are not additional workflow executions. PHP/source passes
2,621 tests. Promotion qualification of the reviewed default corpus is recorded
in #361; PHP remains the default published runtime.

Native namespace metadata, request binding and the qualified worker transitions
use bound SQL values and the existing pools, scheduler and serialized transition
lock. Named operations and commands outside this echo/activity/read/cancel and
idle-poll slice explicitly refuse before reaching default-scope queries. Wider
timers/signals/queries/updates/children/schedules/visibility, lifecycle/retention,
quotas and role profiles remain unqualified. Role tokens need a separate profile:
enabling them disables the legacy token's full-access bypass, and published
worker/operator/admin permissions are distinct.

The twenty-eighth reviewed fixture qualifies legacy-token authentication and
default-namespace admission. It completes an original echo, admits one unclaimed
peer and executes 16 actual control/worker requests: credential failures before
protocol/namespace errors, protocol failures before namespace lookup, unknown
header/query namespaces and normalized header/query/configured-default precedence.
Refused requests preserve complete original descriptions and history; authorized
cleanup cancels that same peer. Embedded mode checks the authored workflow and
installed peer lifecycle, with HTTP authentication explicitly inapplicable.

Tests-first source `6d12a021` reproduces two admission failures on all six
configurations while retaining 37 existing passing native HTTP tests: the
noncanonical authentication response and an unknown query namespace cancelling
the default peer. Native corrections resolve namespace selection before dispatch
and supply versioned, plane-specific refusal metadata and original diagnostics.
The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38038527429)
on head `5a2016a7139723d34dc5edf2306bfd7f7d2b82af` passes all 28 PHP/Rust/embedded
cases on SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB 10.11. Its actual
runner revision `d6e8ae25f30cee2442d297e7f72ab468faf80dcc` has the identical tree.
All six downloaded artifact digests match; 504 actual observations independently
compare, including 18 admission observations and 192 HTTP admission requests.
All 39 native HTTP tests pass per configuration, SQLite passes 21 library tests,
810 modeled checks pass (114 admission) and PHP/source passes 2,607 tests.
The reviewed default corpus became 28; promotion qualification and main
verification are recorded in [#359](https://github.com/durable-workflow/server/pull/359).

Role/principal tokens, tenant restrictions, wider named-namespace execution, alternate
auth/default configuration and additional control-operation error contracts
remain open. Malformed/non-scalar selectors fail closed in this development
slice; their broader PHP contract remains pending.

The twenty-seventh reviewed fixture covers default-namespace visibility with one completed authored
echo and one unclaimed pending peer of a different registered type. It records
original run identities, size-one pagination, status/type filters, cancellation
cleanup and persisted embedded summaries. HTTP recordings also execute the
unchanged, checksum-verified published CLI 2.2.0 list/describe/history commands.
Frozen PHP and embedded recordings pass and compare at `8345e18e`; the actual
published CLI commands succeed against PHP and refuse the pending status alias.
All 696 comparator checks pass, including 88 modeled visibility/CLI and selection
checks. Corrected tests-first source `fbc519f1` compiles on all six databases and
reproduces missing listing (405) and canonical request-contract metadata, with
all 35 existing native HTTP tests passing. A later actual CLI run independently
rejects missing describe/history response metadata despite all 37 native HTTP
tests passing. The correction supplies operation-specific response contracts,
original identities and cursors without duplicating payloads in metadata.

The native implementation reads at most 201 authoritative run rows,
uses bound status/type/substring filters and PHP offset cursors, and adds canonical
describe buckets/current-run/count fields. SQLite ordering handles both stored
UTC timestamp forms with exact decimal precision; a native storage regression
checks nanosecond boundaries and ID ties. The [candidate matrix](https://github.com/durable-workflow/server/actions/runs/38035180354)
at `0f89276a9aae5f0055e90b24a3fb50339c6462a6` passes all 27 PHP/Rust/embedded
cases on SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB 10.11. All six
downloaded artifact digests match; their 486 actual observations independently
compare, including 18 visibility observations and unchanged CLI 2.2.0 execution
on both HTTP targets. All 37 native HTTP tests pass per configuration, and the
SQLite library suite passes 21 tests. PHP/source checks pass 2,607 tests. Fixture
promotion and final default-corpus qualification are recorded separately in
[#357](https://github.com/durable-workflow/server/pull/357).
Persisted PHP summary projections for Waterline, pinned-build fleet visibility,
structured query grammar and custom search attributes are not part of this slice.
Broader visibility grammar, metadata, namespaces, streams and Waterline are
separate acceptance gates.

The next slices widen reviewed PHP/Rust/embedded fixtures and native behavior:
Timer, signal, quiescent query and state-update slices are qualified. State-update slice
[#342](https://github.com/durable-workflow/server/pull/342) applies two distinct
values while a workflow waits for a finishing
signal, repeats each request ID with different arguments, and checks original
identities/results, state replay and the shared message cursor. All nine cases
pass and compare on the frozen published PHP and embedded references at
`ab105eba4aebc5295d1fe08c3bf70a87e1f7e639`; 83 comparator checks pass.
Native admission, original request receipts, routed update tasks and completion
fences are qualified on all six targets in the
[final shared matrix](https://github.com/durable-workflow/server/actions/runs/37987814790)
at `a6f96db537acdbdfb505373e46e2eed8fbe2d3d2`; #342 records exact source identities
and passing PHP/source outcomes. All nine shared fixtures pass per runtime.
The native HTTP cases exercise independent pools, expired-lease recovery,
registration fences, completion waits and the 64-update inventory boundary.
MariaDB qualification exposed decimal promotion of a nullable integer through
`COALESCE`; update positions now read the physical nullable integer directly.
Admission requires a quiescent wait and bounds each run
to 64 updates, with 64 KiB argument blobs, 256 KiB results and at most 30 seconds
per completion wait. Waiting releases the transaction and database connection.
Queued/busy control routing, validators, failure tasks and full update semantics
remain open.
The qualified tenth child-workflow fixture calls two children in sequence, each executing
an activity with a distinct typed payload, then returns their committed results
in order. Preserve parent/call/child-run and activity-attempt relationships,
registered type keys, original arguments, exact int64 and complete histories.
HTTP-created children omit `StartAccepted`; embedded children record it. Both
inventories must be explicit and checked before projecting their common behavior.
The shared fixture/adapters/comparator are implemented in
[#343](https://github.com/durable-workflow/server/pull/343). Its reference and
exact-head qualification outcomes are recorded there. Native successful,
sequential child creation/completion/resumption passes all ten shared cases,
20 native HTTP tests, 106 comparator tests and the actual process-kill child
recovery probe on SQLite, PostgreSQL 16/17, MySQL 8.0/8.4 and MariaDB 10.11 at
`b51e6aba331bfdcfa2da13fe503dfe5609967c76` in
[run 37993085836](https://github.com/durable-workflow/server/actions/runs/37993085836).
PHP/source gates pass (2,601 tests / 57,160 assertions). The merge
`a4bf3bbf1a4e5d29df35eb29d18d0c30de80ad0e` retains the identical tested tree;
all main-branch shared and PHP/source checks also pass.
Child runs retain PHP's nullable default timeout budgets, original parent
call/link IDs and immutable terminal results. Creation and parent resumption
commit atomically with the leased workflow task; duplicate completions retain
their original receipts. Native tests exercise independent pools, real child
lease expiry, fresh-pool recovery and rollback on inconsistent linkage.
Retry/cancellation policies, explicit timeouts, parallel groups and parent-close
actions remain refused or unqualified beyond this success slice.
The eleventh reviewed fixture reports an application failure on activity attempt one,
waits its durable one-second retry backoff, and completes the original activity
and workflow on attempt two with exact int64 payloads. It checks distinct tasks
and attempts, original execution/idempotency identity, immutable policy/argument
frames, actual SDK claims/completions and no intermediate workflow resumption.
HTTP duplicates must leave history unchanged; embedded redelivery uses the real
queue jobs for both closed tasks. Embedded Throwable class and external worker
failure type are explicit representations of the same original failure.
All eleven cases pass and compare against the published PHP and embedded
references at `8d0da93aa3db059b6bcf2230898ceb2231a1dae6`; 128 comparator tests pass.
The tests-first native regression fails with `422 unsupported_request_field`
before retry support in [run 37995605455](https://github.com/durable-workflow/server/actions/runs/37995605455).
The native transition is implemented in [#344](https://github.com/durable-workflow/server/pull/344):
failure encoding uses the official codec on a bounded blocking worker, then one
transaction closes the original attempt/task, preserves its failure and creates
one ready retry at the original exact deadline. Polling keeps the original
activity/idempotency identity and increments the new task's attempt counter.
No parent task is created until a successful result commits. The current PHP
tables and native schema version remain unchanged. All eleven shared fixtures,
21 native HTTP regressions and actual pending-retry process-kill recovery pass
on all six configurations in [run 37996570260](https://github.com/durable-workflow/server/actions/runs/37996570260)
at `2dfb99d5ee3097aa5f6b99b43f7d192678e550b1`. Recovery retains the original
failure prefix, task/arguments/deadline, executes attempt two through the
published SDK, commits one result and refuses the old failed attempt's late
completion. PHP/source gates pass (2,601 tests / 57,156 assertions). The merge
`9fd7ba8ca1acf3b773fb8ba9e97d7cba7b0f84b9` retains the tested tree; its main
shared, PHP/source and boundary checks also pass.

The next three reviewed fixtures cover exhausted activity retry budget,
a matching non-retryable error filter and successful retry with a nonmatching
filter. The unchanged workflow worker must replay and catch the original durable
failure, then complete with its exact typed input and failure type/message.
Embedded execution records an additional `FailureHandled` event. The published
HTTP protocol has no catch acknowledgement command and omits that event. Both
complete inventories and the original embedded failure/handling relationship
are checked explicitly before comparing the declared common catch behavior;
this does not qualify full failure diagnostics or visibility.
All fourteen reviewed fixtures pass against the frozen PHP/embedded artifacts
at `d116947a70d30cdae66f9c8bd7e5ea6d18126d6a`, and 154 comparator tests pass.
The tests-first native regression at `5e819617e38e0a528700663905c737a2c615f09f`
compiles and fails on the missing terminal transition; its other 21 HTTP tests
pass. Native terminal failure and exact error-type filters are implemented.
All fourteen shared fixtures, 22 native HTTP tests and the actual terminal-failure
kill/recovery checkpoint pass on all six configurations in
[run 37999564905](https://github.com/durable-workflow/server/actions/runs/37999564905)
at `67e14149f804276e47906fb73ab9b07a92543a4a`. Independent digest/source
audits verify all 252 reviewed fixture snapshots and recompare the six retained
three-runtime recordings. Worker history retains event IDs/attribution and
`recorded_at`; the control API projects sequence/type/`timestamp`/payload.
Recovery checks their declared common fields and original worker event IDs and
terminal task attribution without changing either API representation.
Error filters follow PHP's default ASCII/NUL trim character set, preserve
Unicode whitespace as part of the type identity and deduplicate in first-seen
order. A separate reviewed normalization corpus is verified against the actual
frozen Workflow normalizer. Native parser and HTTP regressions cover this edge,
including a Unicode-padded nonmatching filter that must still retry. This corpus
checks normalization; it is additional to the fourteen durable execution cases.
The native regression also covers explicit non-retryable reports and default
no-retry behavior, independent pools, original failure rows, concurrent duplicate
reports/resumption, fresh-pool recovery and stale/conflicting outcomes.
The actual process-kill probe also preserves an acknowledged terminal failure
with retry budget remaining and its original pending workflow-resume task.
After replacement, the published SDK client and replayer catch the original
failure, complete once and reject late success from the closed activity.
This checkpoint uses explicit client claims/replay; the shared terminal fixtures
exercise the normal SDK worker loop.
The final terminal-failure head `ec0e597db9efd13f5f5c2e24adc76aa1a2de5f4d`
passes all six configurations in [run 38001111544](https://github.com/durable-workflow/server/actions/runs/38001111544)
and PHP/source gates (2,601 tests / 57,160 assertions). Merge
`2288e80b8d7610394e38b814afaa62414333d200` retains the identical tested tree;
its main shared and PHP/source checks also pass.

The immediate selected-run cancellation slice defines three
cases: before workflow claim, with a pending 60-second timer, and with a leased
activity. Reviewed expectations preserve the original command/run/failure and
timer/attempt identities, exact int64 input, typed cancellation, immutable
history, repeat refusal and stale-result fencing. The normal SDK activity worker
must submit its result after cancellation and safely discard the stale refusal;
embedded mode executes and redelivers real queue jobs. All seventeen cases pass
and compare on frozen PHP/embedded at `99102930002253f9b32dec87236c5e5c98f86a76`;
185 comparator checks pass. Tests-first native run
[38002864344](https://github.com/durable-workflow/server/actions/runs/38002864344)
compiles, passes its other 22 HTTP tests and fails the new cancellation test on
the absent endpoint (`501 rust_capability_not_implemented`). Native implementation
at `7e83dc7e622f6962099d89b74972edba2d3a0bcf` passes all six configurations in
[run 38003976411](https://github.com/durable-workflow/server/actions/runs/38003976411),
including all 17 shared cases, 23 native HTTP tests and actual process-kill
recovery of the original cancelled activity and timer. The retained records
preserve all 306 fixture snapshots and officially decoded int64 recovery inputs.
Final review then found that HTTP reasons need Laravel's Unicode/invisible
boundary trim before nullable/1000-character validation. Embedded cancellation
preserves its reason verbatim. The reviewed padded-reason fixture explicitly
checks both representations, and a separate corpus pins the complete frozen
HTTP trim set. The correction and its two native normalization checks pass
the final six-configuration qualification described below. Cooperative cleanup
cancellation, child/update cancellation and scoped propagation are separate gates.
The first padded-reason matrix also exposed a published embedded PostgreSQL
defect: a literal NUL truncates the physical failure message while terminal
history retains it. PHP HTTP and Rust passed all seventeen cases, and SQLite,
MySQL and MariaDB embedded comparisons passed; both PostgreSQL embedded
comparisons reported `product-fail`. [Workflow #741](https://github.com/durable-workflow/workflow/issues/741)
owns the portable representation/refusal decision and fix. A small reproducer
is retained in `tests/Fixtures/ServerParityPending/cancel-nul-reason.json`, outside
the passing corpus. The portable padded-reason case excludes NUL while retaining
Unicode/invisible whitespace; the full failure/history consistency check remains
required. NUL is still covered by the separate HTTP normalization corpus. This
does not qualify literal-NUL cancellation reasons across database families.
The correction and published-artifact follow-through remain separately tracked
in #741; the pending fixture is retained for qualification of that fix.
The [reviewed correction](https://github.com/durable-workflow/workflow/issues/741#issuecomment-6091333824)
renders the complete cancellation/termination diagnostic as a JSON string literal
when it contains NUL, identically in failure storage and terminal history.
Decoding recovers the original complete diagnostic; ordinary diagnostics remain
unchanged. Original reasons remain byte-exact in Avro command/history payloads,
with original failure type, source, run and identity. The PHP correction is now
published in Workflow 2.5.5 and Server 2.5.14. Its corrected-tuple Rust
differential is qualified below; the frozen failing reference is retained and
does not become a pass from publication alone.

The separate corrected diagnostic corpus now selects frozen Server 2.5.14 /
Workflow 2.5.5 from `tests/Fixtures/ServerParityBaselines/php-2.5.14.json`.
The original 2.5.13 manifest and pending leading-NUL reproducer remain unchanged.
Two reviewed definitions preserve the leading-NUL embedded case and add an
interior-NUL reason that reaches HTTP/native storage unchanged. Only a complete
diagnostic containing NUL is rendered as one JSON string literal; decoding must
recover the full prefix and reason, while ordinary messages remain unchanged.
The 22-case corpus records published PHP and embedded references before
native execution.
All 289 comparator tests pass, including four corrected diagnostic models and
twelve synchronized semantic corruptions; these are harness checks, not actual
completed workflows. Native qualification is recorded in
[PR #352](https://github.com/durable-workflow/server/pull/352).
The [actual tests-first run](https://github.com/durable-workflow/server/actions/runs/38018390560)
at `19fa7f0a` records 264 passing PHP/embedded observations across six database
configurations, including 24 NUL observations. All existing 29 native HTTP tests
pass per configuration, but the interior-NUL fixture rejects the native
unencoded diagnostic on SQLite/MySQL/MariaDB. PostgreSQL 17/18 instead reject
the history payload because SQLx advertises `jsonb` for the PHP `json` column;
cancellation returns 503, rolls back, and the subsequent worker completes.
The native implementation serializes the whole NUL-containing diagnostic and
binds PostgreSQL documents as `json`, reusing SQLx's encoder and preserving the
existing schema. A new HTTP regression checks original reasons, physical
failure/history equality, duplicate refusal, fresh pools and exact int64 input.
The repaired head `d121dc804977a09d5b9484e602e9fa53b573b384` passes
[all six configurations](https://github.com/durable-workflow/server/actions/runs/38019202998).
Independent review verifies all six downloaded ZIP digests and compares 396
actual PHP/Rust/embedded observations (22 fixtures × three runtimes × six
configurations), including 36 NUL observations. All 30 native HTTP tests pass
per configuration. The existing 54 root cleanup observations and twelve
process-kill cleanup recovery variants also pass; PostgreSQL/MySQL TLS and
read-only PHP takeover refusal gates remain intact. Qualified totals are now
22 representative fixtures; broader cancellation and upgrade remain unqualified.
The final cancellation head `741401bc80e2b13b86ae031f3fe88272855f1d03`
passes all six configurations in [run 38005623457](https://github.com/durable-workflow/server/actions/runs/38005623457),
including all 17 shared cases, 23 native HTTP tests/configuration and actual
cancelled-activity/timer process-kill recovery. Independent artifact/source
audits verify 306 reviewed fixture snapshots, twelve officially decoded cancelled
recovery inputs and six fresh comparisons. The comparator passes 187 checks;
native library tests include both normalization regressions. PHP/source
[run 38005623473](https://github.com/durable-workflow/server/actions/runs/38005623473)
passes 2,601 tests / 57,156 assertions. [PR #346](https://github.com/durable-workflow/server/pull/346)
is merged at `eb863bd3c1548e7fb446cfca7e75b07bbdc250d8` with the exact tested
tree. Its main [shared fixture](https://github.com/durable-workflow/server/actions/runs/38006383023)
and [PHP/source](https://github.com/durable-workflow/server/actions/runs/38006383136)
checks also pass.
The frozen HTTP selected-run route validates the current run, then records an
instance-scoped command; embedded `loadRun` records a run-scoped command.
Fixtures declare and verify both receipt representations before comparing their
common original-run cancellation. Frozen cancellation attempt history omits
lease expiry; embedded physical rows and actual HTTP stale-result refusal prove
lease closure separately. Neither difference changes expected cancellation,
original identities or fencing.
For a correctly identified attempt on an immediately cancelled run, frozen PHP
refuses completion with HTTP 409, `run_cancelled`, `outcome: ignored`, the
original owner/attempt/task and closed statuses. This is distinct from a wrong
attempt's `stale_attempt` refusal. The normal published SDK worker discards this
closed-run result safely; the fixture checks both its actual report and an
explicit repeated report without changing either response.

Root cooperative cancellation in
[#348](https://github.com/durable-workflow/server/pull/348) adds three qualified
definitions: before claim, after an original timer is scheduled, and expiry of
a longer shielded cleanup timer. The unchanged PHP SDK 2.2.6 executes all 20
shared cases on native Rust, frozen PHP Server 2.5.13 and embedded Workflow
2.5.4 across SQLite, PostgreSQL 17/18, MySQL 8.0/8.4 and MariaDB 10.11.
The [shared and recovery matrix](https://github.com/durable-workflow/server/actions/runs/38015362532)
at `5af21079838b5e3e818f43e7d2e8fd0b580ec20e`, including main `605527eb`,
passes all six configurations. All six ZIP digests and 360 actual snapshots
are independently reviewed, including 54 root observations. Each configuration
passes 29 native HTTP tests. The [PHP source checks](https://github.com/durable-workflow/server/actions/runs/38015362517)
and public boundary checks also pass. The reference comparator rejects 80
semantic corruptions; all 273 comparator tests pass, including 86 root cases
and the existing 187 checks. These totals qualify representative fixtures,
not complete cancellation, migration or performance parity.

Cooperative discovery is enabled in the experimental runtime to run the
unchanged SDK handshake. Current admission supports root requests with timer
boundaries; child relationships, existing open activities, waits, updates,
scoped policies and other delivery call kinds are refused explicitly. This
bounded slice does not establish broader cooperative-cancellation parity.

The first unchanged SDK candidate exposed the protocol 1.20 activity ownership
observation hook: the worker calls `/status` before invoking cleanup activity
code. Native delivery and the shielded timer completed, but the missing hook
returned HTTP 501 and the SDK abandoned each activity claim. The native hook
now observes the actual task, attempt, owner, run and existing lease without
renewing it or recording progress. The unchanged SDK and shared matrix now
qualify that hook for the supported cleanup slice.

`cooperative-restart.php` adds real pending-cleanup recovery variants to the
existing externally killed native deployments. Preparation records the original
canonical context, delivered event identity and acknowledged history before
the process kill. A fresh SDK worker then checks completed and expired cleanup,
unchanged request/deadline, original typed activity values, terminal reads and
late heartbeat/delivery/completion fencing. All twelve actual recovery variants
pass across the six configurations; job logs retain the external Docker KILL
and exit-137 check. Independently reviewed receipts preserve the original
history prefix, root context, delivery event identity and exact int64 values.
These longer-timer variants do not add cases to the shared corpus.

The actual first request returns HTTP 202; pending and terminal duplicates
return HTTP 200. The reviewed fixtures now check each recorded transport status,
request body and response. Corruption checks keep history copies consistent so
they must reject the changed semantics themselves. The initial native gap run
compiled on all six configurations and left the 23 existing HTTP tests passing;
the new test failed because the endpoint is absent. Its initial HTTP 200
expectation was corrected to the recorded HTTP 202 before implementation.

Deadline qualification runs the installed ordinary Server repair pass against
actual wall-clock deadlines. It does not construct terminal history, alter time
or patch the SDK. Embedded mode must be selected before Laravel bootstrap;
each original timer is observed in the selected run before cancellation.
Terminal diagnostics use the generated completion/expiry reason, while the
original caller reason remains in canonical context and cleanup arguments.
The separate PHP service-mode queue override inconsistency found during setup
was corrected separately in [#351](https://github.com/durable-workflow/server/pull/351)
and published as Server 2.5.15. The frozen reference tuple remains unchanged.

The direct child-cancellation cases cover cancellation before a child
claim and during its committed timer. Actual published PHP 2.5.14 / SDK 2.2.6
and embedded Workflow 2.5.5 execution on separate SQLite databases completed
both parents by catching their original cancelled children. The SDK exposes
`ChildWorkflowFailed` with failure type `ChildRunCancelled` and the original
child failure message/payload. Embedded replay restores
`WorkflowCancelledException`, names the child run in its parent-facing message
and records `FailureHandled`. Shared assertions check those representations
before projecting common behavior. Deterministic unit examples model the observed
published representations and test
rejection of corrupted identities, history, diagnostics and duplicate effects.
The direct-child slice brings the corpus to 24 representative cases. This adds direct child
cancellation only; propagation trees, parent-close policies, child retries and
hard-kill child-cancellation recovery remain unqualified.

Tests-first head `3feb7871` / test merge `55078239` independently confirms all
288 PHP/embedded observations on the six configurations. Every native recording
stops at direct child cancellation with `422 cancellation_propagation_not_available`;
the preceding 30 native HTTP tests/configuration pass. Failure snapshots also
expose an unclaimed native child described as `running`: only the parent was
polled, whereas both published references describe the child as `pending`.
The repair initializes children as pending, resolves direct cancellation through
the existing checked child-call/link path, records `ChildRunCancelled` with the
original failure and enqueues one original-parent resumption in the same
transaction. Closed cancellation receipts retain the published
`rejected_not_active` outcome. Two added native tests cover fresh pools, physical
failure persistence, immutable duplicate/stale outcomes and relationship-corruption
rollback.

Repair head `4490f61f` / test merge `65e1fde9` passes
[all six configurations](https://github.com/durable-workflow/server/actions/runs/38022923876).
Six downloaded ZIPs match GitHub artifact digests; all 432 PHP/Rust/embedded
observations independently compare, including 36 direct-child observations.
All 32 native HTTP tests/configuration and the twelve existing root-cleanup
kill/restart variants pass, together with TLS and PHP takeover refusal.
[Source gates](https://github.com/durable-workflow/server/actions/runs/38022923848)
pass with 2607 PHP tests / 57,222 assertions, the known six deprecations and one
skip. The final fixture metadata removes its pending-phase claim; that changed
fixture identity requires qualification again on the final source before merge.

The adapter now issues the real SDK cancellation after a committed task-completion
response, before the single SDK worker can poll again. It changes no request or
response and synthesizes no history. Two fresh published PHP executions pass:
before-claim cancellation observes pending state and zero child claims; timer
cancellation observes waiting state and one child claim. Shared checks additionally
require one original parent resumption and its original child/call/failure
relationships. 447 comparator checks pass, including 158 direct-child checks.
Raw per-run observations remain in bounded Actions artifacts; unit examples use
fixed identities and timestamps rather than committing generated run payloads.

Generic HTTP worker failures retain bounded SDK diagnostics and real selected-run
describe/history/debug reads before timeout shutdown. A real 120-second timer
proved that the unchanged 30-second budget still fails and retains the original
waiting run, scheduled timer and pending timer task diagnostic. Failure reads
use a separate one-second request budget; inaccessible debug state is recorded
as an inspection failure. This capture is diagnostic evidence, not a completed
workflow or a diagnosis of the earlier unexplained PHP timer timeout.

Timeout/lease retries, uncaught failures and broader retry policies remain
separate gates. The next steps are broader cooperative cancellation,
schedules, visibility, authorization/namespaces,
streams and existing consumers. Each slice must run on the required database
matrix. Read-only upgrade inspection and backup-first takeover remain required,
but follow wider capability coverage. PHP and unknown databases stay refused.

Two schedule cases extend the reviewed corpus to 26: a manual lifecycle and
one fixed-rate occurrence with a one-action budget. Published source refuses manual triggers
while paused with `status_not_triggerable`; exhausting `max_runs` deletes the
schedule with `max_runs_exhausted`. Actual manual observations now agree across
HTTP/embedded adapters, and embedded execution completed the original fixed-rate
occurrence. The HTTP original fixed-rate observation hit SQLite write contention
under concurrent ordinary repair; Server #355 owns current-stable reproduction
and the intended failed-occurrence contract. The initial evaluator's `sh -ec`
wrapper also stopped on that failure; the published harness uses `sh -c`.
This explains the stopped role, not the original occurrence's failure.
The comparator checks retain original
schedule/fire/workflow/run/history relationships, exact int64 action input,
non-early occurrence timing, completed authored work and no later extra fire.
The 161 deterministic schedule checks reject damaged identity, quota, history,
typed payload, admission timing, deletion and post-close observations. They are
comparator examples, not additional executions. Frozen embedded resume can
record the caller model's stale zero skip count; the manual definition explicitly
pins that audit representation while requiring the fresh description and later
trigger to retain the actual skip. HTTP legacy-admin audit context is checked
before common projection; this is not broad authorization qualification.
Complete pending PHP/embedded records now match in a controlled reference
footprint that sequences the installed repair and evaluator CLI commands in one
maintenance loop. It deliberately excludes overlapping maintenance writes and
does not close #355. All four raw observations compare at adapter source
0212604f5a7a1c47b5664227b4483a6328ca520d. The first receipt with an incorrectly
entered runner SHA is retained and excluded; the valid records use Git's revision.
The tests-first native regressions compile and fail at the expected 501 schedule
creation on all six databases, with the preceding 32 HTTP tests passing.
Native now implements this bounded UTC interval slice, including manual
pause/resume/skips, retained audit history, original due occurrence admission
and one-action exhaustion. Start, workflow marker, schedule audit, accounting
and exhaustion share one transaction; an injected audit failure verifies rollback
without consuming the original occurrence or quota. Unsupported calendar,
timezone and overlap policies remain refused.
Three native HTTP regressions additionally cover close/reopen recovery and two
competing nodes admitting one original occurrence. This is graceful schedule
restart evidence, not schedule process-kill qualification. All 35 native SQLite
HTTP tests pass at `3213ce16`, together with the projection and atomic rollback
units. Its shared run stops earlier at frozen PHP worker shutdown contention;
that failure is retained separately. Repair head `169c2d7a` / test merge
`f1d62c41` passes [all six configurations](https://github.com/durable-workflow/server/actions/runs/38030503934).
Six downloaded ZIPs match official artifact digests and all 468 actual
PHP/Rust/embedded observations independently compare, including 36 schedule
observations. All 35 native HTTP tests/configuration and the twelve existing root
cleanup process-kill variants pass, together with TLS and PHP takeover refusal.
[Source gates](https://github.com/durable-workflow/server/actions/runs/38030504008)
pass with 2607 PHP tests / 57,222 assertions, six known deprecations and one skip.
The earlier frozen-PHP worker shutdown failure remains unexplained; a passing
later revision is not its diagnosis.

Qualification has already exposed missing completion status, the SDK
control-plane worker deletion route, MariaDB DECIMAL promotion when selecting
an aggregate unsigned sequence, and null decoded fields in fresh native workflow
descriptions. Native repairs retain the original stored envelopes and use a
bounded blocking codec worker to project display values after the database read.
The comparator also permits the first HTTP fixed-rate history read to see an
already committed original occurrence, requiring an exact original audit prefix
and the same final identity, deadline, history and quota checks. All 608 comparator
tests pass; they are not extra execution observations. The two definitions now
join the default corpus; their changed fixture identities and integrated runner
require the final source to pass the full matrix again before merge.

The current execution slice shares the native workflow/activity/timer/signal state machine
between SQLite, PostgreSQL and MariaDB/MySQL, with typed database adapters and explicit
transaction locking. Qualification runs the unchanged execution fixtures
and real lease interruption on each backend, with PHP and embedded targets in
independent databases. Existing PHP data remains refused until backup-first
takeover is implemented and qualified.

The PostgreSQL storage foundation prepares the real timestamp/JSON schema,
transactional migrations and ownership checks against an independently
bootstrapped published PHP database. Fresh native bootstrap keeps the full
existing physical schema; it does not authorize adoption of PHP data. Actual
Backup-first database takeover remains unimplemented.

Before the timer slice #339, the three shared fixtures cover echo, an exact int64 and one activity.
Database bootstrap and storage safety evidence do not increase that semantic
coverage. Native transitions remain serialized. The diagnostic PHP observations
are not a matched PHP/Rust performance comparison; that comparison waits for a
broader execution surface and must measure transaction serialization cost.

1. Inventory the current contract, schema and operational surfaces. Establish
   shared reviewed fixtures and a recorder usable against any isolated Server
   URL and a real embedded Laravel engine. Freeze published PHP/consumer artifacts
   and measure PHP before implementing Rust.
2. Add a Rust runtime in this repository through small vertical slices. Run the
   differential fixtures for each change and settle disagreements against the
   published contract before changing either implementation.
3. Complete the capability inventory, published SDK and consumer directions,
   three-database conformance, multi-node interruption and performance gates.
4. Qualify backup-first sequential takeover, interrupted migrations, PHP refusal
   of upgraded databases, operator procedures and the major-release decision.
5. Publish exact artifacts through the existing release process and verify
   downstream consumers. Cloud adoption belongs to its separate owner.

The first slice covers only Avro echo (including an exact large int64) and one external activity, completed by
the published PHP SDK and the embedded engine. Its fixtures require exact event
order, stable public workflow identity, retained run/operation relationships,
decoded input/result values and one committed completion. Passing these cases
does not prove the remaining acceptance criteria.

Normal published SDK query directions proceed from #415's identical previously
qualified tree, independently of General's PHP correction. Fresh main's frozen
SQLite reference failure stays retained; it is not relabeled or substituted
with the successful candidate. The [reviewed next scope](https://github.com/durable-workflow/server/issues/325#issuecomment-6115419641)
adds all nine query-client/workflow-author combinations, overlapping real author
and query-client processes, three cold committed checkpoints, exact fresh
Unicode/int64/boolean/null state and unchanged original durable histories.
The reader preserves Python's hydrated response and zero-argument input omission
and its additional workflow replay callback. Canonical embedded query execution
remains explicit. Source/model checks pass 50 query cases and all 4,731 Node
tests, language/format/shell checks and isolation policy plus 72 regressions.
These do not qualify runtime queries: initial live fixture execution must retain
the ordinary omitted-input disagreement before its reviewed native correction.
See [the query cohort](../tests/Fixtures/ServerParityProfiles/published-sdk-queries/QUERIES.md).

Initial [query #422 qualification](https://github.com/durable-workflow/server/pull/422#issuecomment-6116719443)
fails all six runtime jobs. Independently inspected
SQLite stops on the first PHP/PHP direction: eight real SDK processes complete
three query receipts and the original 13-event run, but the reader compares
computed query availability as durable state. The resumption snapshot can also
mix updated history with its prior progress/wait projection because the recorder
does not await the workflow-completion receipt. The original failed record and
complete wires remain retained. The parallel signal profile independently
passes 27 observations and 90 sidecars; all 173 original signal/query members
and compressed round-trip hashes are verified, with disposable resources removed.

The current recorder waits for the matching successful original workflow-task
receipt before taking each checkpoint snapshot. It binds task/run, owner,
attempt, actual command and request index. Only raw computed `can_query`
availability is separated from durable comparison, retaining boolean/presence
checks and the complete observed values. Every other action, progress/wait
field, control-plane copy and full history stays strict. Validation passes 71
query models, all 4,753 Node tests and 16 PHP recorder tests/80 assertions; these
are source checks, not fresh runtime qualification. Those recorder changes
leave native Rust unchanged.

The first recorder correction's PHP/source passes 2,696 tests/61,322 assertions
and SDK artifact integrity passes 297 original hashes, nine thin comparisons
and ten worker-source hashes. Its untouched PostgreSQL 17 query record remains
failed because the reader expected database row IDs in public history. The
[reviewed response-shape correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117007924)
binds row IDs, links and timestamps across actual worker claims and repeated
full query prefixes, and checks exact sequence/type/payload/timestamp against
the public API. Successful query worker receipts permit absent/null reason,
rejecting supplied errors. The complete original PHP/PHP record passes a
diagnostic replay under the corrected reader; neither tree is runtime-qualified
by that replay. The parallel PostgreSQL 17 signal profile independently passes
27 observations/90 sidecars; all 177 original signal/query files and compressed
round-trip hashes pass. Frozen tuples, resource limits and native code remain
unchanged; fresh full-cohort execution is required.

The later SQLite original records reach actual native SDK execution: all nine
PHP directions, nine embedded directions and native PHP/PHP pass. Native PHP
author/Python client then sends its ordinary zero-argument `{}` query and gets
HTTP 422 `unsupported_request_field`; the original run remains waiting with
three events and an unchanged complete snapshot. The [original refusal and
reviewed correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117247506)
remain explicit. Independent full-reader/package audits validate those 19
successful observations and bind the refusal to the original complete wire.

Native now encodes only a root empty object as an official Avro empty array on
the existing bounded blocking codec path. Supplied input retains its validation;
unknown fields, arrays/null, malformed/non-Avro input and required parameters
keep their refusals. A new real HTTP/database regression completes both omitted
and explicit empty queries and checks unchanged full original state/history;
fifteen invalid cases must leave query-cache inventory unchanged. Formatting
and all 76 execution tests pass in inspected SQLite and MariaDB runs. SQLite
independently passes all 27 query observations; fresh all-six qualification
is still incomplete. Current main's PHP recovery and release preparation are integrated
without changing the frozen tuples or taking over General's release work.

That fresh run fails five database jobs. Independently inspected MariaDB stops
in PHP reference recording after three passing directions, with
`spawnSync php ENOBUFS`: all eight actors for the fourth direction exit zero,
but full evidence exceeds the recorder's 4 MiB pipe buffer. The original failed
record remains intact. The [reviewed capture correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117614915)
uses temporary files, preserves complete observation bytes and enforces
64 MiB stdout/4 MiB stderr admission limits without changing the 45-second probe
deadline or runtime budgets. Five real child-process regressions and all
4,758 Node tests pass. New exact-source runtime qualification remains required;
the other four failed raw causes are not assumed identical.

The capture correction's fresh matrix passes SQLite/MariaDB, fails MySQL
8.0/8.4 and is cancelled on PostgreSQL 16/17. Inspected MySQL records stop on
the recorder's JSON parsing of an actively appended journal. Complete retained
lines parse; an unterminated prefix of an actual line reproduces the same
exception, while the exact original transient read is unavailable. The
[reviewed live-read correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6117918727)
reads only newline-committed records incrementally, retaining exact bytes and
indexes. Final parsing binds one retained snapshot and refuses incomplete or
malformed data. PHP recorder tests pass 21 cases/97 assertions; four new checks
fail against the previous live-read path, and all 4,758 Node tests pass.
PostgreSQL 17's query cohort independently passes all 27 observations, but its
later pressure cancellation remains an incomplete job. Fixed execution budgets,
native code, SDKs, tuples and semantic reader rules are unchanged; exact-source
all-six qualification is still required.

The live-read correction's original matrix independently passes 27 query
observations/144 full query wire sidecars and 27 signal observations/90 full
sidecars per database across all six configurations. Each native execution
suite passes all 76 tests; PHP/source passes 2,706 tests/61,420 assertions.
Four whole jobs pass (SQLite, MariaDB, MySQL 8.0/8.4); PostgreSQL 16/17 remain
cancelled at the 20-minute job limit, confirmed by their GitHub annotations.
All retained pressure records match the source reader, but do not qualify
the cancelled jobs or establish completion before the deadline. The
[reviewed CI budget correction](https://github.com/durable-workflow/server/pull/422#issuecomment-6118354755)
gives SQLite and PostgreSQL the same bounded 25-minute job limit already used
by MariaDB/MySQL. Fixture/probe/pressure deadlines, resource limits, SDK/native
code, frozen tuples and semantic assertions are unchanged. Fresh exact-source
full-matrix qualification remains required; earlier failed records stay held.

## Contract and verification inventory

| Surface | Current authority and reusable checks | Port status |
| --- | --- | --- |
| Control plane, namespaces, authorization, errors, visibility | `resources/platform-protocol-specs/control-plane-api.openapi.yaml`, `docs/contracts/auth-composition.md`, `routes/api.php`, Feature control-plane/auth/namespace tests; namespace and principal-attribution published runners | Bounded default listing/CLI metadata, two named echo/activity namespaces, legacy admission and a separate exact-role token profile; broader visibility/namespace families and principal authorization pending |
| Worker registration, sessions, leases, fencing, retries, timeouts, heartbeats, routing, affinity, backpressure, versioning | Worker OpenAPI and stream AsyncAPI in `resources/platform-protocol-specs/`; Feature worker/activity/prepared-local/cancellation tests; activity, heartbeat and worker-versioning published runners | Bounded PHP registration, polling, completion/fencing, activity retries and cleanup heartbeat; broader fleet, routing, affinity and timeout policies pending |
| Avro values, external task inputs/results, payload storage and reclamation | `docs/contracts/external-task-{input,result}.md`, `external-payload-storage.md`, external payload OpenAPI; `regression-corpus-policy.json`, `tests/Fixtures/CodecRegression/`, payload Feature tests | PHP/embedded echo slice; Rust codec foundation in #329, full ingress and external payloads pending |
| Workflow lifecycle, duplicate commands, typed history, continuation, child workflows, cancellation, cleanup replay | Workflow lifecycle, migration, child-workflow and replay runners; Feature cooperative cancellation, history, migration and repeated-signal tests | Bounded authored completion, activity retry/failure, direct children/cancellation and root cooperative cleanup; broader propagation, replay and continuation semantics pending |
| Timers, schedules, signals, queries, updates, search attributes, memo, sagas | Corresponding runners in `scripts/conformance/`, manifests in `static/platform-conformance/`; Sample App polyglot experiments | Bounded durable sleep, repeated signals, state queries/updates and two UTC schedule cases; broader policies, search attributes, memo and sagas pending |
| Local activities, cancellation scopes, worker sessions, streams, service catalog/Nexus, bridge adapters, standalone activities, debugging and repair | `routes/api.php`, `docs/contracts/`, worker/control-plane specifications, corresponding Feature tests and Nexus runner | Pending; not omitted from parity |
| Actual PHP/Python/Rust directions and existing CLI/Waterline/Sample App | Organization [conformance runbook](https://github.com/durable-workflow/.github/blob/main/conformance/README.md) and its SDK coverage inventory; Sample App activity, child, timer, saga, update, namespace and search experiments | Published SDK and canonical embedded directions: #401 nine workflow/activity, #408 nine parent/child, #411 timer authors, #415 nine signals, #422 nine queries, #424 nine successful updates and #427 nine failed updates. Current default query/update/signal families pass all six configurations on #427 main; the original frozen-PHP SQLite cancellation failure remains immutable. #429 paired named-namespace and #434 selected-run signal profiles pass all six final-head and fresh-main configurations. A separate corrected marker/range cohort is next. CLI 2.2.0 list/describe/history has bounded coverage; broader SDK families, CLI commands, Waterline and Sample App remain pending |
| SQL schema and stored representations | `database/migrations/`, Workflow package `src/migrations/`, model casts, `Workflow\\Serializers\\Serializer`, queue job payloads, exported history and credential digests | [Representation checkpoints](rust-server-storage-audit.md) recorded; complete mapping and executable migration tests pending |
| Images, architecture, bootstrap, configuration, readiness, metrics and graceful shutdown | `Dockerfile`, `docker/`, `config/`, `docker-compose*.yml`, `k8s/helm/`, `docs/server-reference.md`, small-cluster/multi-region validation docs | Pending |
| Backup and in-place upgrade | `docs/self-hosted-backup-and-restore.md`, external payload backup holds; last PHP image writes, all PHP roles stop, Rust takes over same DB sequentially | Pending on SQLite, MariaDB/MySQL, PostgreSQL |
| Throughput, memory, CPU, latency, backlog/drain, idle mixed long polls | `benchmarks/capacity/v1/` and `scripts/benchmark/`; repeated runs and drift checks using frozen artifacts | [Diagnostic PHP reference recorded](../benchmarks/server-port/v1/php-observations-2026-10-09.md); matched comparison and full qualification pending |

Ordinary PHP, Rust and embedded runs use independent databases. Never connect
different engines to one database concurrently. SQLite multi-node qualification
must state its shared-file topology and locking limits; existing single-node
support is not evidence of multi-node safety.

## Decisions

- Shared fixtures are expectations reviewed against public contracts, not PHP
  recordings promoted to expected behavior. The recorder keeps raw observations;
  the comparator checks the declared fixture projection. Coverage is explicit.
- The HTTP adapter uses the published PHP SDK and its official Avro codec. The
  embedded adapter executes real Workflow jobs and persisted history. Neither
  counts an accepted start as completion or substitutes mocked execution.
- Generated run/activity/attempt identifiers may differ between independent
  databases. Compare their relationships with bijective aliases; retain actual
  IDs in raw evidence. Preserve workflow IDs, event order, command sequences,
  attempt counts and decoded values. Wall-clock observations remain raw.
- The first Rust foundation uses Apache's official Avro single-object
  reader/writer with the immutable schema. The first HTTP/storage slice uses
  Axum/Tokio and SQLx with selected features and native transactions. Avoid
  unnecessary dependencies and code generation. Record
  clean and incremental compile time plus target-directory size for the first
  runtime slice. Use one task-owned Cargo target directory, low debug information
  for development, bounded build concurrency and cleanup after qualification.
- Evaluate [Kache](https://github.com/kunobi-ninja/kache) in a task-local runtime
  before choosing the Rust build setup. Use an explicit cache budget and local
  storage; measure physical disk blocks across cache and targets together. Keep
  cache and build outputs under one container mount so cross-mount copying does
  not erase sharing. Use a per-run compiler wrapper and scoped cleanup.
  The [first real crate measurements](../rust/build-observations-2026-10-09.md)
  observed native clean builds around 22 seconds and 182 MiB targets. Kache
  restored a fresh target in 3.5 seconds but added cold-build time and retained
  disk; native no-op/comment-edit builds were faster in-place. Keep it optional.
  Full-server build cost and any disk-saving claim remain pending.
- Tokio tasks are [cooperatively scheduled](https://docs.rs/tokio/latest/tokio/task/coop/index.html).
  Potentially expensive replay, decoding and synchronous database operations
  need bounded work away from the async request executor. `spawn_blocking` needs
  an explicit CPU concurrency limit, and a started blocking job cannot be
  aborted. Preserve durable fencing and cancellation checks around that work;
  qualify timer, lease and cancellation deadlines under CPU-heavy load.

## Evidence and next action

[PR #340](https://github.com/durable-workflow/server/pull/340) widens the corpus
into one/repeated signal deliveries. The published SDK authors signal-derived
condition waits; embedded PHP authors direct `signal()` waits. Their full,
explicit event inventories retain that difference before comparing payload-wait
semantics, cursor progress, typed values and accepted command/run relationships.
Initial local reference execution exposed the required `MessageCursorAdvanced`
event; it is now checked rather than discarded. Both reviewed cases executed
to completion on the frozen PHP Server and embedded package. Hosted Rust and
database qualification is recorded in the owning PR and issue; a local reference
pass alone does not qualify the native slice.

The native slice adds atomic accepted signal records and wait/resume transitions,
capturing the registered signal declarations in original start history. Public
control command order and deterministic authored call order are separate;
signals must not move replayed activity/timer/wait positions. Official Avro
signal decode/encode runs in `spawn_blocking` with one permit per runtime, held
until that job finishes even when its HTTP request is cancelled. Transaction
serialization, full codec resource limits and CPU/deadline qualification remain
open. Initial signal argument support was positional, without default/variadic
or class-type conversion. The current normal SDK signal slice implements
variadic tails with required positional prefixes; defaults and class-type
conversion remain unsupported and fail before durable writes.
Timed/grouped waits, cancellation, full rejection audit parity, authorization
and namespace coverage still require their own slices.

The initial source and published artifact tuple is in
[`../tests/Fixtures/ServerParity/php-baseline.json`](../tests/Fixtures/ServerParity/php-baseline.json).
It freezes inputs, not a passing performance result. Fixture and runner commits,
hardware, resource limits and actual measurement commands belong in each retained
result. Do not compare performance across concurrent unrelated host workloads.

The first HTTP and embedded recordings passed on the frozen amd64 image and
Workflow package, with an independent SQLite database for each. Hosted
[fixture execution](https://github.com/durable-workflow/server/actions/runs/37889894631)
retains both raw records and the comparison for 90 days. It executes both
workflows to completion, including the activity, with the published PHP SDK.
[PR #326](https://github.com/durable-workflow/server/pull/326) owns source checks
and review. The linked final run covers all three cases, including the large
int64 and explicit decoded type trees.
This is bounded correctness evidence, not performance qualification
or a three-database/three-runtime pass.

The fixture foundation is merged in #326. All three cases and 25 comparator
checks passed at its exact head, together with the full PHP feature suite.

The [development reference profile](../benchmarks/server-port/v1/README.md)
pins the current PHP/SDK tuple and uses existing standard-workflow and mixed
idle-poll commands. Its preparation is merged in [#327](https://github.com/durable-workflow/server/pull/327).
The [diagnostic observations](../benchmarks/server-port/v1/php-observations-2026-10-09.md)
are complete: three repetitions at 0.25 and 0.5 offered starts/s completed all
workflows in-window; at 1/s, only 48–51 of 120 completed in-window before the
remainder drained. The high-rate retry had one readiness 503, and the original
second run's configured hourly worker exit invalidated its resource comparison.
All outcomes and failures are retained. Following drift windows passed; sampling
gaps, missing complete client resource costs and changed background load still
prevent a PHP/Rust improvement claim. The development host's kernel,
runtime and SATA storage differ from the standard capacity topology, so that
profile preserves the full capacity gate and makes no maximum-capacity claim.

Mixed idle observations completed three repetitions each at 6, 12 and 24 polls.
Six polls returned six empty outcomes; twelve returned eight empty outcomes and
four explicit capacity rejections; twenty-four returned eight empty outcomes and
sixteen rejections. All registrations were removed, with no claimed tasks,
runtime restart or OOM. Query waits were clamped to about five seconds, while
workflow/activity waits lasted about ten seconds. Health/readiness probes passed
throughout the six- and twelve-poll runs. All three twenty-four-poll registration
bursts caused at least one two-second API probe timeout, with an Apache
`MaxRequestWorkers` warning observed. These failures remain part of the reference
evidence; API headroom at that burst size is not qualified. Shared-host background
work changed between measurements, so a PHP/Rust performance gain still requires
matched conditions and drift controls.

The unpublished [Rust foundation](../rust/README.md) in
[#329](https://github.com/durable-workflow/server/pull/329) implements the Value
adapter and complete-frame check, retaining exact int64, bytes/text, array/map
and finite-double distinctions. Its first tests exposed Apache's streaming EOF
null sentinel; the frame adapter now rejects incomplete datums. Fifteen reviewed
logical values passed local Rust/PHP cross-decoding in both directions, including
the existing golden long-zero wire. These are codec checks, not execution of a
durable workflow on Rust. Malformed collection blocks, duplicate keys, resource
limits and external payload ingress remain explicit gates before HTTP integration.

[#330](https://github.com/durable-workflow/server/pull/330) adds actual HTTP
execution: published SDK workers lease tasks, commit activity outcomes, replay
persisted history and complete workflows against an independent native SQLite
database. The unchanged three fixtures first passed locally against all three
targets, including exact large-int64 and activity relationships. Transactional
tests cover persistence, independent connection-pool claims, stale fences,
duplicate completion/poll receipts, pagination and unsupported-batch refusal.
An actual SIGKILL with an outstanding activity passed locally through
the normal SDK loop after restart: the original run/history survived, the old
expired claim was refused, and attempt two committed one outcome. These checks establish the first bounded
slice, not full protocol, multi-node, timeout/retry or database parity.

HTTP passes opaque Avro envelopes without invoking the incomplete decoder.
Discovery/readiness explicitly identify development status, and missing
commands/effects are refused. A read-only preflight refuses existing PHP data;
native fresh-database bootstrap does not substitute for sequential backup-first
takeover. Published PHP still has no startup refusal guard, so PHP must never
connect to this development database. MySQL/PostgreSQL, scoped auth/namespaces, external payloads,
timers/retries/cancellation, complete capability/consumer matrices and performance
remain required.

The [native build observations](../rust/runtime-build-observations-2026-10-09.md)
record a clean tests/binary build at 54.65 seconds and 521.4 MiB of targets;
downloads add 202.6 MiB. Clippy and incremental outputs bring the observed
combined state to about 868 MiB. Build-only no-op/comment edits took 0.159/1.513
seconds. These bounded measurements exclude downloads/startup and make no
full-server/cache-saving claim.

#330 is merged. Its exact head passed the three-runtime comparison, seven
SQLite transaction/HTTP tests, four codec tests, two-way codec values and an
actual SIGKILL/replacement-container recovery check in the shared Action. The
complete PHP feature/source suite and repository/boundary checks also passed.

[The PHP fence in #331](https://github.com/durable-workflow/server/pull/331) establishes PHP refusal before a Rust-marked database can be
mutated. The reserved, unprefixed `dw_server_schema` relation is the one-way
ownership fence: its existence refuses PHP even if its rows are empty, malformed
or from an unknown future version. Removing the marker is not a rollback path.
Stop every PHP write-capable role before Rust takeover; a connection preflight
does not make a concurrently mixed fleet safe.

The fence guards Laravel's official SQLite, MySQL/MariaDB and
PostgreSQL connectors before connection setup can execute durable writes. A
SQLite file must first be opened read-only, including visible WAL state; an
immutable snapshot is insufficient. Probe failures must not be mistaken for
marker absence. The normal container entrypoint checks ownership before
starting its role, while connector checks protect direct bootstrap, migration,
worker and scheduler paths and reconnects. Unmarked PHP databases must remain
usable, and unavailable-backend diagnostics must remain truthful.

Acceptance uses real PHP processes and physical database state: refuse startup
and write-capable commands against marked SQLite/MySQL/PostgreSQL databases,
preserve sentinel data and schema, reject empty/future markers, observe a marker
still in SQLite's WAL, and continue ordinary bootstrap against unmarked data.
This protection does not claim backup-first conversion, interrupted migration,
full-schema compatibility or a qualified takeover.

The process fixtures cover physical startup/bootstrap/migration/wipe/worker/
scheduler refusal, malformed/future markers, reconnects, unmarked bootstrap,
URI/WAL visibility, nested named-connection wipes, deferred initialization SQL,
hidden-marker permissions and silent PDO modes. MySQL and PostgreSQL are exercised
in the feature Action. The additional local MariaDB run uses an actual MariaDB
11.4.13 deployment. Existing bootstrap, SQLite lock-pressure, unavailable-backend
and growth-inventory contracts also run; exact-head evidence is linked from #331.

The real SIGKILL fixture initially exposed a recovery regression: SQLite must
repair a hot rollback journal before reading schema. The fence now recovers a
private copy with SQLite, checks committed ownership and unchanged input, and
only then allows ordinary PHP recovery. Fault fixtures verify acknowledged PHP
data, a marker restored from an uncommitted drop without changing the original,
concurrent startup, an interrupted probe, a replaced database, actual low-space
refusal and protection of external super-journal filenames. Root startup and
Apache's runtime UID have separate private copies. The
[operator guide](php-database-ownership.md) records temporary disk requirements,
cleanup and the explicit reserved-name grant needed by table-only MySQL/MariaDB
roles. This does not claim migration or production takeover qualification.
Cache-only restart and scheduler-cache maintenance remain usable during a
database outage; early command preflight targets write-capable roles rather than
every command sharing their prefix.

The next foundation aligns fresh native SQLite databases with the complete
schema created by the frozen published PHP image: 49 tables and 333 explicit
indexes, including credentials, payload holds, timers, cancellation and legacy
infrastructure. SQLx records and validates native migration checksums. Worker
registration uses the existing `workflow_worker_registrations` relation;
execution timeout stays on the workflow instance. Native completion/poll receipts
use separate relations rather than replacing PHP-owned columns.

This is a fresh-database development bootstrap, not permission to adopt PHP
data. PHP databases and the earlier abbreviated native development schema remain
refused before writable connection setup. No observable protocol capability is
added. Qualification must compare the actual published PHP catalog with an
independent native database, preserve the existing execution/restart fixtures,
and test interrupted/concurrent bootstrap and migration-history mismatches.
The local implementation passes the existing seven execution tests, four codec
tests and five new schema cases. The latter check three concurrent fresh nodes,
real SIGKILLs after migration-ledger creation and before the outer commit,
read-only refusal of old development data and nine catalog/history corruptions.
Restart after either acknowledged interruption creates one complete migration
and passes SQLite integrity checking. The actual published/native catalogs
match. The [shared hosted run](https://github.com/durable-workflow/server/actions/runs/37929268377)
passes the unchanged three-runtime fixtures, two-way codec values, native
schema interruption and actual activity-lease kill/restart. Its catalog check
retains the original database and existing journal hashes. An earlier inspection
mount prevented SQLite's temporary WAL metadata access; the corrected fixture
uses `mode=ro` on stopped engines and a writable disposable metadata directory,
without an immutable snapshot that could conceal WAL state.
The final fixture additionally prepares pending work in the published PHP
database and requires native refusal with unchanged database/journal hashes.
Its final-head evidence belongs to [#332](https://github.com/durable-workflow/server/pull/332).
SQLite's [read-only WAL contract](https://sqlite.org/wal.html#read_only_databases)
permits creating shared-memory metadata and an empty WAL when those files are
absent. The refusal fixture exposed this behavior on the real published PHP
database. Native startup now awaits probe closure on success and refusal. The
receipt treats an absent and exactly zero-byte WAL as equivalent; any nonempty
WAL, rollback journal and database must retain its exact hash. Unit cases cover
both a closed WAL database and committed work still present in a nonempty WAL.
The final local suite passes 16 cases plus the two invoked fault subprocesses;
formatting, warning-free Clippy and the 72 Docker-isolation policy cases pass.
The [build observations](../rust/schema-build-observations-2026-10-09.md)
record 64.445 seconds for a clean tests/binary build, 541.9 MiB of targets plus
177.6 MiB of downloads, and 0.137/0.823-second no-op/comment builds. These are
bounded development measurements, not a full-server or improvement claim.

[The PostgreSQL foundation in #333](https://github.com/durable-workflow/server/pull/333)
adds native schema bootstrap and read-only inspection commands. Its migration
uses the actual frozen PHP PostgreSQL schema: 49 tables, 382 indexes including
primary/unique constraints, 92 constraints and 17 owned sequences. Unlike
SQLite, timestamps retain six-digit precision and payload metadata uses native
JSON columns. Native receipts and SQLx history occupy separate relations.
Startup fixes the development schema to `public`/UTC and refuses PHP data in
any user schema, corrupt catalogs or unknown history before writable access.
A transaction-scoped advisory lock serializes initialization; the outer
transaction commits DDL, ledger and ownership together. PostgreSQL execution
is not enabled by these commands.

The real local PostgreSQL 17 checks pass six backend cases: concurrent fresh
nodes, exact typed JSON/int64/microsecond values, SELECT-only inspection,
existing/custom-schema data refusal, thirteen corrupted catalog/history states,
and SIGKILLs after ledger creation and before commit. A restarted initializer
sees no partial schema and creates one valid migration. The actual published
PHP database also contains a leased activity; after stopping PHP, native
bootstrap refuses it and all row/sequence fingerprints remain unchanged.
TLS uses SQLx/Rustls, with a trusted CA and hostname verification; actual
untrusted certificates and wrong hostnames are refused. Hosted qualification
targets the pinned PostgreSQL 16 and 17 images, alongside the unchanged
PHP/Rust/embedded SQLite fixtures and real activity restart.

The first catalog-formatting helper rounded an int64 sequence maximum. Capture
now uses PHP's exact 64-bit values. A PostgreSQL UNION also inferred the fixed
`name` type and truncated composed catalog keys; the query explicitly selects
`text`, and a regression case verifies distinct long constraint identities and
the exact sequence maximum. These corrections strengthen the schema checks;
they do not change the reviewed execution fixtures.

The [PostgreSQL build observations](../rust/postgres-build-observations-2026-10-09.md)
record 86.755 seconds for clean default tests/binary, 747.1 MiB of targets plus
306.3 MiB of Cargo state, and 0.147/1.614-second no-op/comment builds. Additional
checking/incremental outputs reach 921.3 MiB of targets. Inputs and exclusions
are explicit; no full-server or causal performance gain is claimed.

[PostgreSQL execution in #334](https://github.com/durable-workflow/server/pull/334)
uses one typed execution state machine for both native backends. JSONB,
microsecond timestamps and PHP's integer widths retain their physical types.
Decode failures return bounded errors rather than panicking. SQLite keeps its
existing native text layout. SQLite history limits now count UTF-8 bytes rather
than characters; PostgreSQL bounds its JSON serialization before hydration.
No migration or schema version change is needed for this execution slice.

The common HTTP cases pass on both backends, including concurrent starts and
completions, independent claims, revoked/expired fences, exact large integers,
persistent receipts, poll retries, pagination, atomic unsupported-command
refusal, timestamp precision and oversized Unicode history. PostgreSQL uses
a transaction-scoped advisory lock for this first default-namespace slice,
separate from initialization. Empty polls release the transaction before waiting;
serialization and full CPU/throughput/idle cost remain unqualified.

The [shared hosted run](https://github.com/durable-workflow/server/actions/runs/37943502489)
passes on the pinned PostgreSQL 16 and 17 images. Each executes the unchanged
echo, int64 and activity fixtures against independent published PHP, Rust and
embedded databases. Two native processes share only their own database. The
probe prepares a leased activity, SIGKILLs one process, verifies survivor
readiness, starts a replacement, lets the persisted lease expire and finishes
through the published PHP SDK. It retains original run/history relationships,
rejects the old claim and records one activity/workflow outcome. This bounded
case does not establish all failure boundaries, routing or full multi-node HA.
The existing schema, interruption, read-only PHP refusal, TLS and SQLite/codec
checks remain in the Action. Existing PHP data is still refused, not converted.

The [execution build observations](../rust/postgres-execution-build-observations-2026-10-09.md)
record 83.054 seconds for clean default tests/binary, 787.0 MiB of targets plus
306.4 MiB of Cargo state, and 0.145/1.911-second no-op/comment builds. Retained
incremental outputs total 825.5 MiB. These bounded development samples do not
qualify the complete server or establish a causal speedup.

The [MariaDB/MySQL execution slice in #335](https://github.com/durable-workflow/server/pull/335)
adds the third typed adapter to the same state machine. Separate physical
catalogs retain the frozen PHP MySQL and MariaDB schemas. A bounded session
lock and checksummed initialization marker account for implicitly committed
DDL; only unchanged, empty native partial initialization can resume. Existing
PHP databases, changed catalogs and unknown migration history are refused.
The [hosted qualification](https://github.com/durable-workflow/server/actions/runs/37953419450)
passes on MySQL 8.0.46, the existing PHP MySQL 8.4.5 image and MariaDB 10.11.19.
Each runs seven storage cases, including actual process kills at three
acknowledged initialization boundaries and safe refusal of changed or occupied
partial initialization. The common HTTP suite passes nine selected-backend
cases plus its SQLite-specific PHP-file refusal case. All three engines pass
the unchanged PHP/Rust/embedded fixtures, two-node activity kill/replacement,
unchanged PHP rows/catalog/next-ID counters on refusal and actual CA/hostname
TLS acceptance/rejection. PostgreSQL 16/17 and SQLite/codec checks pass in the
same run. A bounded InnoDB ownership-row lock serializes this development
slice; these results do not establish complete multi-node HA or performance.

The [build observations](../rust/mysql-execution-build-observations-2026-10-09.md)
record 89.438 seconds for clean default tests/binary, 865.1 MiB of targets plus
306.4 MiB of Cargo state, and 0.146/1.031-second no-op/comment builds. Retained
incremental outputs reach 879.9 MiB. Full-server build cost remains unqualified.

Next: qualify backup-first sequential takeover with
stored-value and migration-interruption fixtures. The capability, consumer,
performance and operational inventory remains open under #325.
No separate defect issues have been filed yet.

The [pending-task correction in #336](https://github.com/durable-workflow/server/pull/336)
addresses a prerequisite compatibility case: PHP treats a null
`workflow_tasks.available_at` as immediately available, while the native query
excluded it. The focused regression reproduces the native stall; the common
polling predicate now admits null availability while future-dated work waits.
PHP and native HTTP cases cover workflow/activity tasks, original run identities,
duplicate activity completion and the final durable workflow outcome. Exact-head
hosted qualification is recorded in the PR. This correction precedes read-only upgrade
preflight; it does not enable database takeover.

The existing activity-completion contract permits a committed retry to return
`409 stale_attempt` with completed statuses; the PHP fixture checks that response.
Native receipts currently return `200` with `recorded=false` for an identical
retry. Both cases check one durable outcome; complete completion-error envelope
parity remains part of the wider #325 qualification.

Final checks of the pending-task correction caught `SQLITE_BUSY` while three
native runtimes opened one fresh SQLite file, before its HTTP case ran. The [startup
correction in #337](https://github.com/durable-workflow/server/pull/337)
retries only that transient database error, with at most three attempts;
each repeats read-only ownership preflight and retains the existing lock limits.
An unsuccessful bootstrap closes its pool and finishes rollback before retry.
Other errors and ownership refusals return immediately. Ownership inspection
checks all user objects, including view-only databases and names such as
`sqlitex_customer_data`, and repeats the same
empty/native ownership check under the initialization write lock before DDL.
The concurrent-runtime fixture exercises sixteen independent fresh databases;
three unknown-object refusal cases require unchanged file bytes and no WAL/SHM
creation. Catalog inspection uses SQLite's literal internal-name prefix, so a
SQL wildcard cannot hide user objects.
Exact-head qualification is recorded in #337. Ordinary command execution and
PHP takeover remain outside this startup correction.

The [SQLite timestamp correction in #338](https://github.com/durable-workflow/server/pull/338)
addresses the next stored-representation prerequisite. The frozen published PHP
Workflow models write UTC SQL timestamps with microseconds; earlier native
development data used UTC RFC3339. Reads now accept both, while new SQLite writes
use the PHP UTC shape. Candidate ordering, lease expiry and poll-receipt cleanup
compare exact decimal timestamps across both shapes without floating-point date
conversion. Eligible task timestamps are decoded before any lease mutation.
Non-UTC stored offsets require explicit conversion before takeover.

The tests-first commit reproduces both unsupported PHP timestamp decoding and
premature same-day availability at a one-microsecond boundary. Twelve fixed-clock
SQLite cases exercise the actual candidate/receipt queries; the common HTTP
case seeds the PHP microsecond representation and verifies description precision
and stale-lease refusal. The frozen PHP model separately reproduces the three
microsecond fixture strings without opening a database. Exact-head shared and
PHP/source qualification is recorded in #338. These are representation tests
against separate native data, not a sequential takeover or timezone qualification.
Read-only upgrade preflight, pending poll bindings, timer jobs, credential and
external payload preservation remain required follow-up under #325.

[The timer slice in #339](https://github.com/durable-workflow/server/pull/339)
adds reviewed one-timer and repeated zero/delayed-sleep fixtures. Their contract
requires original timer IDs/deadlines, deterministic command sequences,
non-early firing and one completed typed outcome. Twelve new comparator cases
include corrupted timer identity, delay, sequence, deadline and duplicate/early
firing counterexamples. The tests-first native regression refuses `start_timer`
with 422 on the previous execution slice; matrix receipts are in the PR.

The first implementation run completed all five PHP and native SQLite cases,
but exposed two further differences. Embedded PHP's immediate zero-delay
`TimerFired` omits `fire_at`; the reviewed repeated-timer fixture explicitly
permits only that omission. Its scheduled deadline and exact firing timestamp
remain authoritative; positive-delay firing must repeat the unchanged deadline.
The comparator retains sub-millisecond precision rather than rounding early
firing into equality. MySQL's driver-owned JSON-path query must bypass the
generic PostgreSQL placeholder conversion, which otherwise mistakes a literal
`$.timer_id` for a binding and panics. The same recovery case requalifies it.
Comparisons also bind saved expectations directly to the current reviewed source
fixtures; matching edited recordings cannot invent replacement expectations
while retaining copied fixture hashes and pass labels.

Native scheduling is implemented with persisted PHP-layout timers and timer
tasks. A bounded background batch rechecks pending work under the existing
cross-node transition lock, atomically commits firing and the next workflow
task, and wakes polls after commit. Idle ticks use a read probe; they do not take
the transition lock. Shutdown joins the scheduler before closing storage;
database turn failures suppress readiness and emit a bounded diagnostic until
the next successful turn. There are no new dependencies or schema changes.
Qualification outcomes are recorded in #339. Cancellation/parallel timer groups, expiry recovery,
missing timer reconstruction, CPU-heavy deadline health, throughput and complete
operational parity remain open. This slice does not qualify database takeover.

The [external-payload development slice in #450](https://github.com/durable-workflow/server/pull/450)
now accepts an explicit verified local reference for workflow input and terminal
workflow output. Output retention commits in the same transaction as the fenced
completion and history. Wrong attempts and malformed commands leave the object
unclaimed; cold descriptions/history retain its opaque reference. The focused
HTTP case also checks the existing native exact-body duplicate receipt without
claiming a stronger shared PHP receipt or provider-loss contract.

The installed PHP SDK recipe `external-result-client.php` explicitly uploads the
bytes and calls the public task-completion API. A separate cold process reads
the completed output with the normal SDK. Frozen PHP and native SQLite each
preserve the same 4,194,392 encoded bytes and SHA-256, a 3 MiB string, signed
integer 9007199254740993, null and Unicode within the original 30-second
observation budget. Stopped-state inspection confirms one completed
first-attempt task, three events and one retained object per qualified original.
The first PHP fixture original remains unsuccessful after a local field-name
error before completion; the qualified original is separate. Both application
containers stop with 137/no OOM, so this is not graceful-shutdown evidence.

Local verification passes 66 library and 103 SQLite HTTP cases, with 18
explicit database/subprocess library ignores. Full transport discovery stays
unadvertised. Activity, query and memo ingress, completion-upload allowance,
automatic result externalization, S3, cleanup/backup and all-database external
semantics remain open. Exact source and hosted qualifications belong to #450;
these observations do not qualify a release or database takeover.

Explicit activity results now use the same verified reference preparation, with
retention inside the original activity outcome transaction after lease, attempt,
run and activity deadline fences. Stale attempts and elapsed leases leave the
object unclaimed. A real database trigger refuses history publication after
retention; rollback restores both the object and original activity before a
matching completion and cold replay succeed. Local checks pass 66 library and
104 SQLite HTTP cases, followed by all eight external HTTP cases after adding
that final rollback control. Other database families remain hosted gates.

The result recipe's `activity-author` phase uses actual SDK workflow admission
and task polling, then an explicit HTTP activity-result completion. Its separate
`activity-reader` process uses the normally installed SDK to hydrate the original
activity history and complete the same workflow. Frozen PHP and native SQLite
each preserve the exact 4,194,392-byte result, one actual activity attempt,
six events and three first-attempt tasks with zero repairs. Published PHP also
automatically uploads the small final summary under its policy; native keeps
that summary inline because automatic upload discovery remains unadvertised.
This measured difference is not full automatic externalization parity. No
graceful shutdown is claimed from the stopped 137/no-OOM containers. Activity
arguments and the remaining external transport gates stay open under #450.

Activity arguments now accept verified local references too. A completion batch
prepares at most the existing 100 commands under one original deadline and one
shared transfer slot; every verified snapshot retains that slot until the last
consumer finishes. Two distinct 4 MiB references pass one batch without waiting
on their own admission limit. A later terminal completion with pending work
rolls back both schedules, history and retention claims. The same original then
accepts the matching schedules, and both opaque argument deliveries survive a
cold open. This control does not claim a completed two-activity workflow.

The separate installed-worker recipe `external-arguments-worker.php` completes
one real large-argument activity and its original parent through three cold
processes. Frozen PHP and native SQLite preserve 4,194,356 encoded bytes/hash,
the exact 3 MiB string, integer 9007199254740993, null and Unicode, six events,
three first-attempt completed tasks and zero repairs. PHP's automatic summary
upload and native's inline summary remain the earlier explicit policy difference.
The first PHP fixture handler omitted required ActivityContext and never claimed
its activity; only the separate corrected original qualifies.

67 library cases and nine focused external HTTP cases pass. The first full
105-case run has 104 passes and one failure: the existing 100 ms stalled-upload
control's next valid upload returns 503 instead of 201. Three isolated identical
controls and one unchanged full 105-case repeat pass. The original remains
failed, its cause unestablished, with no timeout/source change or claimed fix.
Exact source and hosted qualification remain in #450. No automatic upload,
S3, cleanup/backup, migration or release claim follows.

The same installed-worker recipe now also accepts
`DW_PARITY_EXTERNAL_ACTIVITY_COUNT=2`. Frozen PHP and the unchanged native
binary each complete two distinct 4,194,356-byte argument references admitted
in one workflow-task completion. Two actual activity callbacks, two payload
GETs and two committed activity completions precede a separate cold parent
reader. It joins results by authored activity sequence without changing event
arrival order. Exact int64, null, Unicode and both 3 MiB string hashes survive.
PHP completes in 8.17 seconds and native in 6.68 seconds against the original
30-second budget. These single observations are functional checks, not a
performance comparison.

Stopped-state inspection finds the same nine-event history and task pattern in
both databases: four first-attempt completed tasks, zero repairs, and one extra
unclaimed ready workflow task. A final poll after closure returns no task in
both implementations. Both large objects remain retained with exact provider
bytes and no expiry. PHP also externalizes the two small activity results and
final result; native keeps those inline under its unadvertised automatic policy.
Both containers stop with 137 and no OOM. All local databases, provider objects,
SDK dependencies, compiler output and task resources are removed after this
audit. Hosted all-database external semantics remain pending.
