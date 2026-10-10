# Shared Server fixtures

Native cluster discovery publishes the same carrier auth-composition v1
contract as the frozen PHP reference. Published workers validate this manifest
before registration; it declares client setting precedence and secret redaction,
with mTLS and signed headers reserved.

The separate `published-sdk-patch-replay` profile adds four cold replacement
directions: Rust SDK 3.4.2 and Python SDK 2.5.1 after an original PHP SDK 2.2.6
worker. The run starts before any worker advertises a definition fingerprint;
the complete start payload is checked. Migration between worker-fingerprinted
definitions is a separate gate, and the frozen PHP matching guard stays intact.
The original worker leaves its activity pending or completed. Its explicitly declared
one-second sticky affinity expires before the cold replacement takes work;
immediate sticky-owner failover remains a separate gate. Registry checksums, the Rust
Cargo lock and Python wheel/dependency hashes are explicit. Both patch calls
must choose false, retain activity sequence 1 and finish the same original run
with exact typed values and unchanged checkpoint history. The observer forwards
original HTTP bytes, including compression, and keeps credentials out of its
journal. Full transport bytes stay in the raw sidecar; repeated read/poll
capability manifests are summarized in the phase observation. Embedded runs the
corresponding PHP author definitions and explicitly
marks the language SDK direction inapplicable.

The observer propagates client cancellation to the upstream connection and
records a canceled background query poll as having no HTTP response. Only that
same worker's shutdown query poll after accepted workflow completion is allowed.
An incidental workflow poll 503 must identify backend lock pressure, include a
positive Retry-After that fits the worker deadline, and recover by retrying the
same poll ID and unchanged request. Other HTTP and network errors fail the case.
These controls do not establish complete fault or retry-budget qualification.

CI builds the Rust SDK adapter once with no debug information or incremental
compilation. It shares only that binary, locked Python packages and identity
receipts across existing database jobs, reusing their already compiled Server
and isolated targets. The original 29-case baseline and older source/fault
tuples remain unchanged. Qualify this profile through matching PHP, native and
embedded recordings on all six database configurations. This profile does not
qualify a live pending-history failure exchange, fresh repeated calls,
retained duplicate markers or performance. Workflow #744's separately repaired
engine requires its own exact source/publication and workflow-clock evidence.

The two pending `workflow-waiting-history-{type,message}` controls explicitly
exercise the published workflow-task failure endpoint through PHP SDK 2.2.6.
A timer resumes a replay while its original activity remains unresolved.
Structured-type and retained message-fallback acknowledgements must release
the lease without failure history or an unsolicited retry, fence stale and
duplicate requests, and preserve the exact activity and workflow outcome.
Embedded executes its authored echo and declares this HTTP endpoint
inapplicable. These controls do not qualify a Rust SDK worker direction,
general task-failure policies, or pending message/cancellation acknowledgement.

This is the first, deliberately bounded slice of
[Server #325](https://github.com/durable-workflow/server/issues/325).
It executes Avro echo (including an exact large int64), a one-activity workflow,
one/repeated durable sleeps, one/repeated signal deliveries, state queries and
state updates with immutable duplicate receipts and two sequential child
workflows with real nested activities, a reported activity failure with a
durable retry, exhausted retry budget and matching/nonmatching error filters against any
isolated Server URL using the **published** PHP SDK. The embedded adapter runs
the same logical cases through a Laravel application, real database queue jobs,
and the installed Workflow package. Rust will use the HTTP adapter unchanged.
Immediate cancellation covers a run before claim, a leased activity and a
pending timer, preserving the original reason, command, failure and closed work.
The padded-reason fixture declares HTTP's published Laravel boundary trim and
embedded cancellation's verbatim reason separately. The cancellation reason
normalization corpus pins the full frozen character set and nullable/length
rules; meaningful history fields are checked before comparing common behavior.
`tests/Fixtures/ServerParityPending` retains known failing cases separately;
they are not counted in the passing corpus. Its literal-NUL cancellation
reproducer preserves the original PHP 2.5.13 / Workflow 2.5.4 finding under
Workflow #741: frozen embedded PostgreSQL silently truncates the physical failure
message. The separate corrected PHP 2.5.14 / Workflow 2.5.5 tuple qualifies new
leading/interior NUL definitions with complete diagnostics. The portable
Unicode-padded case retains the complete failure/history consistency check.

The reviewed root cooperative-cancellation cases use explicit worker protocol 1.20 and
the normal published cooperative worker. Cleanup completion is still a
cancelled workflow; its original context is carried through a real timer,
activity and heartbeat. A separate expiry case closes the original budget
before the cleanup activity runs. The HTTP reference needs its ordinary
`workflow:v2:repair-pass` role running against its isolated database so expired
cleanup is enforced. Embedded execution invokes the installed watchdog and
uses `DW_MODE=embedded` before application bootstrap. Neither adapter edits
time or writes its own history/terminal rows.

Direct child-cancellation cases additionally exercise cancellation before
claim and during a committed child timer. Published PHP, native and embedded
execution pass the six-configuration matrix. The embedded-only
`FailureHandled` event and parent-facing diagnostic are asserted before common
projection. Deterministic unit examples model these representations, not extra
qualified workflow cases. HTTP failure capture uses bounded real SDK reads of
the original run and first original child before timeout shutdown, preserving
the 30-second execution budget and recording diagnostic read refusals honestly.

Requirements: Node 20+, PHP 8.3+ with PDO SQLite/pcntl, and Composer. The pinned
published Server image contains these tools. Install the exact adapter:

```bash
composer install --working-dir=scripts/conformance/server-parity --no-interaction
node --test tests/Unit/ServerParityRunnerTest.mjs tests/Unit/ServerParityCooperativeContractTest.mjs tests/Unit/ServerParityChildCancellationContractTest.mjs tests/Unit/ServerParityScheduleContractTest.mjs tests/Unit/ServerParityVisibilityContractTest.mjs tests/Unit/ServerParityAdmissionContractTest.mjs
```

Use **separate, already bootstrapped databases** for PHP, Rust and embedded.
The default reviewed corpus includes 29 cases.
The pending patch deployment controls are selected explicitly with
`--include-fixture tests/Fixtures/ServerParityPending/patch-insertion-activity-pending.json`
and `--include-fixture tests/Fixtures/ServerParityPending/patch-insertion-activity-completed.json`
on recording and comparison. CI selects both in addition to the unchanged 29
default definitions, reusing existing isolated targets and compiled binaries.
An original worker stops after scheduling or completing the original activity;
a separate cold process inserts two calls to the same patch ID. Both must choose
the legacy branch, preserve command sequence 1 and complete the original run
without a new activity or version marker. Complete checkpoint histories and
typed int64 outcomes are compared. These are unchanged published PHP SDK and
embedded authoring controls. Python/Rust SDK adapters, their corrected exact
artifacts and retained Python duplicate-marker histories remain separate gates
under SDK Python #103 and SDK Rust #80.

The fresh marker controls add
`--include-fixture tests/Fixtures/ServerParityPending/patch-created-activity-pending.json`
and `--include-fixture tests/Fixtures/ServerParityPending/patch-created-activity-completed.json`
on every recording and comparison. CI selects all four pending controls beside
the unchanged 29 defaults. A fresh worker calls the patch ID once and
commits exactly one frozen marker at sequence 1 plus an activity at sequence 2.
A separate cold process calls it again, chooses true and completes the
original activity/run without another marker. The full original checkpoint,
five-field marker, actual SDK requests and exact typed outcome are retained.
Real qualification of these fresh marker cases is recorded in their owning PR;
it does not qualify deprecation or Python/Rust SDK directions. The separate
`patch-marked-activity-pending/completed` definitions preserve the reviewed
fresh repeated-call contract. They are excluded from CI selection while
[Workflow #744](https://github.com/durable-workflow/workflow/issues/744) repairs
the observed embedded duplicate-marker behavior; their expectations remain
unchanged.

Its visibility fixture runs
the unchanged published CLI 2.2.0 on both HTTP targets. Download its PHAR,
verify the fixture's pinned checksum, and expose its absolute path to the PHP
probe with `DW_PARITY_CLI_PHAR`:

```bash
mkdir -p parity-consumers
curl --fail --location --output parity-consumers/dw.phar https://github.com/durable-workflow/cli/releases/download/2.2.0/dw.phar
printf '%s  %s\n' "$(jq -r '.visibility.cli.phar_sha256' tests/Fixtures/ServerParity/visibility-current-runs.json)" parity-consumers/dw.phar | sha256sum -c
export DW_PARITY_CLI_PHAR="$PWD/parity-consumers/dw.phar"
```

When running the probe in Docker, mount the PHAR into that container and set
`DW_PARITY_CLI_PHAR` to its absolute container path. Embedded mode checks the
installed engine's original persisted summaries and peer cleanup; its namespace
and workflow identities must match the independent HTTP recordings.

The two schedule cases can
also be selected explicitly for a bounded inspection:

```bash
node scripts/conformance/server-parity.mjs record --mode http --url "$URL" --target php --runner-revision "$SHA" --output php-schedules.json --fixture tests/Fixtures/ServerParity/schedule-manual-lifecycle.json --fixture tests/Fixtures/ServerParity/schedule-fixed-rate-one-occurrence.json
node scripts/conformance/server-parity.mjs compare php-schedules.json embedded-schedules.json --fixture tests/Fixtures/ServerParity/schedule-manual-lifecycle.json --fixture tests/Fixtures/ServerParity/schedule-fixed-rate-one-occurrence.json
```

The HTTP reference additionally needs the installed `schedule:evaluate` role.
Use the published harness's `sh -c` loop and retain failed evaluation reports;
do not stop or erase the failed original occurrence by changing its deadline.
The embedded adapter restores the installed normal PHP-class starter in its
Server fixture host and uses the real `ScheduleManager` against wall time.
The bounded manual lifecycle and original fixed-rate occurrence pass the six
database configurations with PHP/Rust/embedded authored completion. Their
comparator checks paused-trigger refusal, original occurrence, quota deletion,
HTTP audit context and embedded deletion visibility. The PHP qualification
sequences installed repair and evaluator commands in one maintenance loop;
concurrent SQLite contention remains separately tracked in Server #355.

The reviewed `tests/Fixtures/ServerParity/namespace-isolation.json` uses the existing
legacy shared-token configuration and two real authored activity workflows in
named namespaces. It checks original histories and payloads, foreign control and
completion refusals, global workflow-ID reservation and same-ID worker isolation,
including SDK idle query polling. It is included in the 29-case default corpus
and can be selected with `--fixture`. Role-token profiles,
namespace lifecycle/retention and cross-namespace orchestration require separate
qualification.

The separate `tests/Fixtures/ServerParityProfiles/role-tokens/admission-role-tokens.json`
requires a separate HTTP cohort configured with distinct `DW_AUTH_TOKEN`,
`DW_WORKER_TOKEN`, `DW_OPERATOR_TOKEN` and `DW_ADMIN_TOKEN`. Set the recorder's
`DW_PARITY_TOKEN`, `DW_PARITY_WORKER_TOKEN`, `DW_PARITY_OPERATOR_TOKEN` and
`DW_PARITY_ADMIN_TOKEN` to the corresponding disposable credentials. Select
this profile with `--fixture` on every recording and comparison; it does
not change the default 29-case legacy cohort or frozen consumer tuple. The
published SDK completes the original echo with the worker token, operator and
admin reads are exercised, and sixteen actual requests check exact roles and
auth → role → protocol → namespace precedence. An operator then cancels the
unchanged original peer. Embedded execution proves the authored lifecycle;
HTTP roles are inapplicable. Qualification is recorded in
[Server #362](https://github.com/durable-workflow/server/pull/362).

The recorder does not migrate a database. Do not run it against production or
shared customer namespaces. The admission fixture executes sixteen real HTTP
requests, checks authentication/version/namespace precedence and preserves the
original pending peer through refusals. Wider namespace families and role/principal
authorization require additional qualification. Use a fresh prefix or database
for each recording;
use the same prefix across the targets being compared.

```bash
export DW_PARITY_TOKEN=isolated-test-token
revision=$(git rev-parse HEAD)
node scripts/conformance/server-parity.mjs record \
  --mode http --url http://localhost:8080 --target php \
  --runner-revision "$revision" --output php.json

# In the isolated embedded application's runtime, with its DB_* environment:
node scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /app --target embedded \
  --runner-revision "$revision" --output embedded.json

node scripts/conformance/server-parity.mjs compare php.json embedded.json
```

Root cleanup recovery uses the unchanged locked PHP SDK and actual wall-clock
timers against an isolated native database:

```bash
php scripts/conformance/server-parity/cooperative-restart.php prepare "$URL" receipt.json
# Kill the owned native process externally, then restart it on the same database.
php scripts/conformance/server-parity/cooperative-restart.php finish "$RECOVERED_URL" receipt.json
```

The two variants retain the original root request, deadline, delivered event
identity and pending shielded timer across the interruption. Completion and
deadline expiry must both end cancelled; fresh SDK reads and stale worker calls
check the durable outcome. Their longer cleanup timers provide a real process
kill checkpoint and do not increase fixture counts. CI performs the actual
Docker kill and verifies exit code 137 before restarting the native process.

For an adapter installed elsewhere, set `DW_PARITY_SDK_AUTOLOAD` to its locked
`vendor/autoload.php`. `--artifacts` selects a frozen tuple manifest. The default
is `tests/Fixtures/ServerParityBaselines/php-2.5.14.json`; its SDK version must match
the installed lock. A frozen tuple does not prove every listed consumer ran.
The original `tests/Fixtures/ServerParity/php-baseline.json` remains an immutable
2.5.13 / Workflow 2.5.4 reference, together with its known NUL reproducer outside
the passing corpus. The corrected tuple is a separate experiment; its new NUL
definitions do not qualify from the older records. The current command executes
only the PHP SDK. The embedded host supplies
namespace assignment and maps the fixture's registered type keys to local PHP
classes. Its database queue runs the real jobs with a 30-second budget; the HTTP
worker registers, polls and completes with the normal SDK loop.

Each recording retains raw execution/history and decoded values with explicit
type trees (int64 values use decimal strings to avoid JSON numeric rounding), fixture hashes,
runner commit, tuple, timestamps and per-case outcomes. No tokens or request
headers are recorded. `pass` requires persisted completion and the complete
expected event inventory. A valid observation that disagrees with the fixture
is `product-fail`; a failed/incomplete probe is `runner-blocked` until diagnosed.
Neither permits a release. CLI failure exits nonzero and keeps the partial record.

The comparison rechecks observations instead of trusting stored pass labels.
Terminal activity fixtures use actual unchanged workers to report failure,
replay the original `ActivityFailed` and catch it in workflow code. Check the
original execution/attempt/task/failure identities, failure type/message/code,
policy, closed status, exact arguments and one parent resumption. A matching
filter is non-retryable despite unused budget; exhausting a budget alone does
not make a failure non-retryable. A nonmatching filter still permits retry.
Error-type normalization uses PHP's default ASCII/NUL trim set; Unicode spaces
remain part of the type identity. A separate reviewed normalization corpus in
`tests/Fixtures/ServerParityNormalization` checks this parser contract in
addition to the durable cases, with a native HTTP nonmatching-filter regression.
Embedded replay additionally records `FailureHandled`, while the published
HTTP protocol omits this catch acknowledgement event. Their complete inventories
and original embedded handling relationship are explicit fixture expectations;
only their declared common catch behavior is compared after these checks pass.
This does not qualify full failure diagnostics, visibility or uncaught failures.
It preserves public workflow IDs, event/command order, type keys, namespace and
queue, decoded input/result types, deadline budgets, start-command relationships
and activity/attempt relationships. Timer cases require distinct timer IDs,
original fire deadlines, non-early firing and deterministic command sequences
through replay. Generated IDs are aliased only after those
relationships pass. Absolute timestamps remain raw, are checked for order, and
deadline offsets are checked against persisted start time with the published
history's second-resolution tolerance. They are not compared across runs.
Firing/deadline comparisons preserve fractional precision. Embedded PHP's
immediate zero-delay firing omits `fire_at`; the repeated-sleep fixture explicitly
permits only that omission while checking its scheduled deadline and `fired_at`.
Positive-delay firing must repeat its original deadline.

Signal cases return a payload different from the workflow input and require a
persisted wait before each delivery. Repeated identical payloads are distinct
commands under the existing signal API. Check the accepted command/run IDs,
decoded arguments and result types, authored wait sequence, message sequence,
cursor advance and single application. The published PHP SDK authors
`waitCondition` with `signals()`; embedded PHP authors `signal()`. Their explicit
event inventories preserve that authoring difference: the SDK adds
`ConditionWaitSatisfied` and checks its original key/fingerprint, while embedded
`SignalApplied` resolves its original `SignalWaitOpened` ID/sequence. The common
payload-wait projection is compared only after both complete histories pass.

The state-query fixture reads zero/one/two applied signals: before delivery,
while the next wait remains open, and after completion. A separate published SDK
client process sends each blocking HTTP query while the real worker keeps polling.
Embedded mode calls its normal replayed query method. Both check exact arguments
and result types, original run/status and unchanged durable history before/after
each query. HTTP observations retain actual query task, lease and worker snapshot.

The child fixture calls two children sequentially with distinct typed payloads.
Each child executes an activity and returns an echo with a child-workflow marker;
the parent returns the two committed results in declaration order. Record the
original parent/call/link/child-run and activity-attempt relationships, full
histories and exact argument/result types. HTTP-created child histories omit
`StartAccepted`, while embedded children retain their original start command.
Both explicit inventories are checked before comparing common durable behavior.
HTTP mode observes actual polls/completions through the published PSR-18 transport,
including leases, attempts, original history prefixes, parent resume bindings and
unchanged Avro frames. No SDK response, request or authentication header is edited;
headers are excluded from observations. Failure/retry/cancellation, parent-close
effects and cross-language child directions remain separate qualification gates.

The activity-retry fixture reports a retryable application failure on attempt
one, then succeeds on attempt two after the original one-second deadline.
It checks original execution/run/idempotency identities, distinct tasks and
attempts, exact argument/result types and unchanged Avro frames, policy snapshots,
closed first-attempt failure and exact retry task/deadline relationships. SDK
polls and outcome reports must match the actual history; a retry cannot resume
the workflow before the successful result. HTTP mode repeats the original
failure and completion through the published SDK and checks unchanged history.
Embedded mode redelivers the two original closed tasks through real queue jobs.
An embedded Throwable retains its class; an external failure retains the
reported type. Those explicit representations are checked before projecting
the same original failure. Separate terminal fixtures cover exhausted budget
and matching/nonmatching error filters. Timeout retries, other retry families
and complete error-envelope parity remain separate gates.

Three immediate cancellation fixtures stop a run before workflow claim, with a
pending timer, or with a leased activity. The unchanged SDK worker submits its
actual result after cancellation and discards the original HTTP 409
`run_cancelled`/`ignored` refusal. The probe repeats cancellation with another
reason and repeats the old activity result; neither may replace the original
command, failure, input or history. A fresh client reloads the original terminal
run and verifies that no workflow/activity work remains. Embedded mode uses its
public cancelled outcome and physical failure/attempt/task rows, then redelivers
the original cancelled activity task through the real queue.
The frozen HTTP selected-run route guards the current run but records an
instance-scoped command, while embedded `loadRun` records a run-scoped command.
Fixtures declare both receipt forms. Cancelled attempt history omits expiry;
physical rows and actual outcome refusals prove closure separately.
Cooperative cleanup, cancellation scopes and child/update propagation remain
unqualified. The experimental native runtime explicitly refuses cancellation
requiring pending child/update/query handling and bounds cancellation to 1,000
open activities and 1,000 pending timers. These bounds do not establish full parity.

The current type recorder covers null, boolean, int64, double, string, list and
map values. Other decoded PHP objects fail explicitly; binary/logical-type and
empty-map distinctions still need their own fixtures and adapters.

This projection **does not yet compare** actor/authentication metadata, PHP
class names and source fingerprints, other task snapshot transport metadata, broader retries,
broader lease fencing, cooperative cancellation, timer parallel groups, or any other listed port gate. Those fields
remain in raw observations. They need their own reviewed fixtures; omitting them
here cannot establish full parity. See `docs/rust-server-port.md` for the complete
inventory and next action. Add expectations from a contract decision before
recording a new case; never regenerate expected results from PHP output.

The `Shared Server fixtures` Action builds the unpublished Rust development
runtime in each job, installs the locked SDK and runs PHP, Rust and embedded targets
against independent databases. The matrix includes SQLite, PostgreSQL 16/17,
MySQL 8.0/the existing PHP matrix image and MariaDB 10.11.
PHP/embedded use the exact frozen PHP image, pulled from its existing public GHCR
mirror with the unchanged manifest digest. Pinned official Rust/database images
use the public Google registry mirror. Registry transport does not change the
frozen artifact tuple; no registry credentials are required for pull-request CI.
PHP source CI caches public mirrored Composer/PHP build inputs under their
canonical image names on its isolated hosted runner. It retains the ordinary
product Dockerfile without overrides.
Rust uses the PR's exact source. It compares all three and runs the separate
`restart.php prepare|finish URL RECEIPT` probe around an actual Rust process kill.
That probe preserves a leased activity, a pending durable timer, an acknowledged
pending signal and an acknowledged child creation with its child task leased.
It also preserves an acknowledged activity failure and pending retry, including
the original closed attempt, ready retry task, arguments and backoff deadline.
Another checkpoint reports a terminal non-retryable failure with unused retry
budget and preserves its original pending workflow-resume task. After replacement,
the unchanged published SDK client and replayer claim that task, catch the original
failure and commit one typed workflow result. Duplicate failure/completion reports
leave the original history unchanged, and late activity success is refused. This
checkpoint uses explicit client claims/replay; the shared terminal fixtures use
the normal SDK worker loop.
Two additional checkpoints immediately cancel an original leased activity and
a pending timer before the real process kill. After replacement, the published
client reloads the same cancelled histories and typed cancellation outcomes,
refuses repeated cancellation and late success/failure from the old attempt,
and polls no revived work. The cancelled timer's original deadline has passed;
its original cancelled history must still contain no firing.
It waits for the actual activity/child lease expiry, refuses both old claims and
completes the original runs through fresh published SDK workers.
The timer must keep its pre-kill identity/deadline and commit exactly one firing.
The signal must retain its accepted command, typed arguments and original condition
wait/fingerprint, then advance its cursor and commit application exactly once.
The child task keeps its original run, history/input and task identity as attempt
two. Its nested activity executes once, and its original parent receives one
terminal result. Retrying the acknowledged child creation retains its immutable
receipt and leaves the completed parent history unchanged.
The reported retry executes its original task as attempt two, returns one typed
result and retains the complete pre-kill failure/history prefix. It cannot
recompute the deadline or accept the failed attempt's late completion; repeating
the original failure or successful completion has no new durable effect.
PostgreSQL and MySQL/MariaDB jobs also use two native nodes with a killed node,
survivor and replacement sharing only their own database. Separate storage
checks exercise actual initialization kills, corrupt catalog/history refusal,
typed values and read-only roles. PHP-pending-data refusal preserves schema,
rows and sequence/next-ID counters; TLS checks use real CA and hostname validation.
The Action retains records for 90 days and removes its containers/network after execution.
This bounded correctness matrix does not qualify capacity, every multi-node
failure boundary, database takeover or a published Rust artifact.

The explicitly selected pending `worker-registration-fencing` fixture uses the
separate `ServerParityProfiles/worker-fencing/source-tuple.json` manifest. Its
PHP Server and SDK inputs are exact unreleased commits; the published image is
only the PHP runtime platform for those checked-out Server files. The SDK adapter
lock pins its source reference, and both recording and comparison verify the
installed SDK reference. The frozen default tuple and 29 fixtures stay unchanged.

The fixture first completes an authored echo. HTTP execution then follows a
distinct original echo and leased task through heartbeat, an unknown-token
refusal, original deregistration and repair, two replacement incarnations,
superseded-token refusal, original receipt replay, stale result refusals, a second
repair and completion by a real SDK worker. Full original reads and histories
must stay unchanged across refusals and replay. Both repair commands and the
final original task/attempt and Avro output are checked. Embedded mode executes
the authored echo and records the HTTP lifecycle as explicitly inapplicable.

`worker-fencing-profile.sh` runs PHP, Rust and embedded observations in separate
databases alongside each normal hosted database cell. It adds no Cargo invocation.
It also selects `worker-registration-live-lease`, which replaces the registration
while its original workflow task is leased. The original token loses authority;
the original task becomes ready and receives a new attempt when polled. PHP's
registration boundary does not append a repair command or history event. Full
original reads remain unchanged across replacement and stale-publication
refusals, and the same task later completes through the existing fenced recovery
sequence. Activity replacement and sticky-execution semantics remain separate
qualification gates. Embedded mode records this HTTP lifecycle as inapplicable.
The third selected case, `worker-sdk-reply-reconciliation`, runs the exact
source SDK's worker shutdown. Controlled setup leases the original task under
its accepted SDK incarnation and requests shutdown before SDK polling. After
the actual HTTP 200 commit, the client adapter loses that reply deliberately;
replacement registrations and a claim of the same original task happen before
the SDK retries. Wire statuses, original token/namespace/credential hash,
bounded request timeouts and SDK diagnostics are retained. The shared contract
checks immutable receipt reconciliation and preservation of the replacement's
original lease/history, followed by actual SDK recovery and typed completion.
This is explicitly client-injected reply loss, not a TCP disconnect. Observing
this path within ten seconds does not qualify persistent pressure, exhausted
shutdown budgets or original worker failure precedence. The source transport
class loads only for this case, leaving the frozen default SDK adapter intact.
Cleanup and logs belong to the owning workflow's always-run steps. Select the
pending fixtures with repeated `--fixture` arguments and the source manifest
with `--artifacts` when recording; compare all three records with those same
fixtures. The native terminal transaction and original task recovery passed #367;
role/namespace boundaries, expiry/pruning and rollback have dedicated native
tests. Native real contention passes all six configurations in #372; client
reply-loss reconciliation passes #371. Complete SDK shutdown qualification
remains separate from these bounded cases.

`worker-fencing-profile.sh <backend> pressure` selects only the pending
`worker-sdk-database-pressure` fixture and the separate
`ServerParityProfiles/worker-pressure/source-tuple.json` manifest. Its exact
PHP/SDK source references and locks match the preceding source cohort. It
reuses that cohort's dependencies and the already compiled Rust binary, with
three additional isolated databases and separate records/comparison.

An independent PDO connection holds a real SQLite writer or original worker row
lock while the actual SDK submits fenced shutdown. The contract requires the
real HTTP 503 and Retry-After, unchanged original peer and authority reads while
the lock remains held, completion of the authority's original attempt after
release, and an actual SDK retry of the same token/namespace/credential using a
smaller bounded timeout. The recovered peer then follows the same receipt,
replacement, stale-publication and typed SDK completion contract. Embedded mode
executes the authored echo and labels HTTP shutdown inapplicable.

The fault manifest explicitly declares five-second waits. SQLite uses the
existing PHP busy timeout; PostgreSQL changes only the isolated PHP database's
lock timeout; MySQL/MariaDB saves and restores the task-owned database server's
global lock wait on script exit. These are configured limits; SQLite may return
its actual contention refusal immediately rather than waiting five seconds.
The recorded timing remains authoritative. Separate PHP application CLI connections
record actual session values before and after configuration. These samples do
not represent the HTTP connection or native pool. No default tuple, production
setting or published SDK is changed. All six configurations pass this temporary
pressure cohort in #373, including independent checks of its official artifacts.
Successful temporary recovery within ten seconds does not qualify persistent
pressure, exhaustion of the entire shutdown budget, original-error precedence,
performance or publication.

The same explicit pressure profile now also selects pending
`worker-sdk-persistent-pressure` and `worker-sdk-original-failure`. The
independent writer remains held until the actual SDK returns failure after
spending its original shutdown budget. Requests must keep the original
token/namespace/credential and use decreasing bounded timeouts. Persistent
503s and a real bounded I/O timeout remain uncertain outcomes; the SDK cannot
claim deregistration success. Full original reads are checked after the first
actual refusal while the writer remains held. The original-error case declares
one actual SDK workflow poll with an invalid fixture credential; its real HTTP
401 becomes the worker error. Exact exception identity must survive the separate
shutdown failure. No SQL errors or responses are mocked.

Only after the SDK returns does the fixture release the lock. A separate
control client reconciles and replays the original token, permitting an already
in-flight request to have committed. Full original history/read preservation,
one original receipt, replacement fences, two deliberate repair commands and
eventual actual SDK completion of that same peer are checked. This does not
restart the stopped worker's budget. Embedded execution labels HTTP shutdown
inapplicable. The frozen default, three-case source tuple and fault manifest
are unchanged; the profile reuses compiled Rust and locked dependencies. These
two new real executions are pending hosted qualification. Explicit native
maintenance, published artifacts and broader #325 remain open.
