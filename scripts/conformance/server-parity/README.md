# Shared Server fixtures

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

Requirements: Node 20+, PHP 8.3+ with PDO SQLite/pcntl, and Composer. The pinned
published Server image contains these tools. Install the exact adapter:

```bash
composer install --working-dir=scripts/conformance/server-parity --no-interaction
node --test tests/Unit/ServerParityRunnerTest.mjs
```

Use **separate, already bootstrapped databases** for PHP, Rust and embedded.
The recorder does not migrate a database. Do not run it against production or
shared customer namespaces. Use a fresh prefix or database for each recording;
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

For an adapter installed elsewhere, set `DW_PARITY_SDK_AUTOLOAD` to its locked
`vendor/autoload.php`. `--artifacts` selects a frozen tuple manifest. The default
is `tests/Fixtures/ServerParity/php-baseline.json`; its SDK version must match
the installed lock. A frozen tuple does not prove every listed consumer ran.
The current command executes only the PHP SDK. The embedded host supplies
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
the same original failure. Terminal/non-retryable failures, timeout retries,
other retry families and complete error-envelope parity remain separate gates.

The current type recorder covers null, boolean, int64, double, string, list and
map values. Other decoded PHP objects fail explicitly; binary/logical-type and
empty-map distinctions still need their own fixtures and adapters.

This projection **does not yet compare** actor/authentication metadata, PHP
class names and source fingerprints, other task snapshot transport metadata, broader retries,
lease fencing, cancellation, timer cancellation/parallel groups, or any other listed port gate. Those fields
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
