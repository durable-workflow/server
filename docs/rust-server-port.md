# Rust Server port

[Server #325](https://github.com/durable-workflow/server/issues/325) owns the
rewrite and its acceptance evidence. PHP remains the default published runtime.
The unpublished Rust crate has an Avro foundation and an opt-in SQLite/PostgreSQL/MySQL HTTP
execution slice. Full runtime parity, migration, performance improvement and
cutover remain unqualified.

Query slice #341 adds a shared state query before signals, after one delivery,
and after completion. The [hosted matrix](https://github.com/durable-workflow/server/actions/runs/37981215484)
on [06499eb7](https://github.com/durable-workflow/server/commit/06499eb7)
passes all eight PHP/Rust/embedded fixtures on SQLite, PostgreSQL 16/17,
MySQL 8.0/8.4 and MariaDB 10.11. It also passes native query lease/limit tests,
complete schema qualification and actual process-kill recovery of pending
timers/signals and leased activities. This qualifies these representative cases;
full query semantics, #325, takeover and performance remain open.
Native admission uses immutable quiescent snapshots,
original query contracts and leased transient tasks in a separate native
`dw_query_cache` table. The frozen PHP schema has no Laravel cache table;
development schema version 3 adds only native bookkeeping and refuses older
native development databases. PHP-owned relations remain unchanged.
Arguments/results use the official codec outside Tokio executor threads.
Bounded pending/result inventory, expiry, independent-pool completion and stale
fences have dedicated tests. Busy workflow/query routing and query poll-request
receipts remain unqualified; the latter capability is advertised as false.

## Work order and current status

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

The direct child-cancellation candidates cover cancellation before a child
claim and during its committed timer. Actual published PHP 2.5.14 / SDK 2.2.6
and embedded Workflow 2.5.5 execution on separate SQLite databases completed
both parents by catching their original cancelled children. The SDK exposes
`ChildWorkflowFailed` with failure type `ChildRunCancelled` and the original
child failure message/payload. Embedded replay restores
`WorkflowCancelledException`, names the child run in its parent-facing message
and records `FailureHandled`. Shared assertions check those representations
before projecting common behavior. Saved, trimmed reference observations test
rejection of corrupted identities, history, diagnostics and duplicate effects.
The runner now attempts 24 cases: 22 qualified and these two native-pending
candidates. Native repair and the complete database matrix remain ahead.

Generic HTTP probe failures retain bounded SDK diagnostics and real selected-run
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

## Contract and verification inventory

| Surface | Current authority and reusable checks | Port status |
| --- | --- | --- |
| Control plane, namespaces, authorization, errors, visibility | `resources/platform-protocol-specs/control-plane-api.openapi.yaml`, `docs/contracts/auth-composition.md`, `routes/api.php`, Feature control-plane/auth/namespace tests; namespace and principal-attribution published runners | Pending |
| Worker registration, sessions, leases, fencing, retries, timeouts, heartbeats, routing, affinity, backpressure, versioning | Worker OpenAPI and stream AsyncAPI in `resources/platform-protocol-specs/`; Feature worker/activity/prepared-local/cancellation tests; activity, heartbeat and worker-versioning published runners | Pending |
| Avro values, external task inputs/results, payload storage and reclamation | `docs/contracts/external-task-{input,result}.md`, `external-payload-storage.md`, external payload OpenAPI; `regression-corpus-policy.json`, `tests/Fixtures/CodecRegression/`, payload Feature tests | PHP/embedded echo slice; Rust codec foundation in #329, full ingress and external payloads pending |
| Workflow lifecycle, duplicate commands, typed history, continuation, child workflows, cancellation, cleanup replay | Workflow lifecycle, migration, child-workflow and replay runners; Feature cooperative cancellation, history, migration and repeated-signal tests | Bounded echo/activity/timer/signal slice; other lifecycle semantics pending |
| Timers, schedules, signals, queries, updates, search attributes, memo, sagas | Corresponding runners in `scripts/conformance/`, manifests in `static/platform-conformance/`; Sample App polyglot experiments | Durable sleep slice in #339 and signal waits/delivery in #340; broader semantics and other families pending |
| Local activities, cancellation scopes, worker sessions, streams, service catalog/Nexus, bridge adapters, standalone activities, debugging and repair | `routes/api.php`, `docs/contracts/`, worker/control-plane specifications, corresponding Feature tests and Nexus runner | Pending; not omitted from parity |
| Actual PHP/Python/Rust directions and existing CLI/Waterline/Sample App | Organization [conformance runbook](https://github.com/durable-workflow/.github/blob/main/conformance/README.md) and its SDK coverage inventory; Sample App activity, child, timer, saga, update, namespace and search experiments | Published PHP SDK echo/activity/timer/signal fixtures; other clients and consumers pending |
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
open. Initial signal argument support is positional, without default/variadic
or class-type conversion; unsupported contracts fail before durable writes.
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
