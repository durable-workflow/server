# Shared Server fixtures

This is the first, deliberately bounded slice of
[Server #325](https://github.com/durable-workflow/server/issues/325).
It executes Avro echo (including an exact large int64), a one-activity workflow,
and one/repeated durable sleeps against any
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
It preserves public workflow IDs, event/command order, type keys, namespace and
queue, decoded input/result types, deadline budgets, start-command relationships
and activity/attempt relationships. Timer cases require distinct timer IDs,
original fire deadlines, non-early firing and deterministic command sequences
through replay. Generated IDs are aliased only after those
relationships pass. Absolute timestamps remain raw, are checked for order, and
deadline offsets are checked against persisted start time with the published
history's second-resolution tolerance. They are not compared across runs.

The current type recorder covers null, boolean, int64, double, string, list and
map values. Other decoded PHP objects fail explicitly; binary/logical-type and
empty-map distinctions still need their own fixtures and adapters.

This projection **does not yet compare** actor/authentication metadata, PHP
class names and source fingerprints, task snapshot transport metadata, retries,
lease fencing, cancellation, timer cancellation/parallel groups, or any other listed port gate. Those fields
remain in raw observations. They need their own reviewed fixtures; omitting them
here cannot establish full parity. See `docs/rust-server-port.md` for the complete
inventory and next action. Add expectations from a contract decision before
recording a new case; never regenerate expected results from PHP output.

The `Shared Server fixtures` Action builds the unpublished Rust development
runtime in each job, installs the locked SDK and runs PHP, Rust and embedded targets
against independent databases. The matrix includes SQLite, PostgreSQL 16/17,
MySQL 8.0/the existing PHP matrix image and MariaDB 10.11.
PHP/embedded use the exact frozen PHP image;
Rust uses the PR's exact source. It compares all three and runs the separate
`restart.php prepare|finish URL RECEIPT` probe around an actual Rust process kill.
That probe preserves a leased activity and a pending durable timer, waits for its actual lease expiry,
refuses the old claim and completes both original runs through a fresh published SDK worker.
The timer must keep its pre-kill identity/deadline and commit exactly one firing.
PostgreSQL and MySQL/MariaDB jobs also use two native nodes with a killed node,
survivor and replacement sharing only their own database. Separate storage
checks exercise actual initialization kills, corrupt catalog/history refusal,
typed values and read-only roles. PHP-pending-data refusal preserves schema,
rows and sequence/next-ID counters; TLS checks use real CA and hostname validation.
The Action retains records for 90 days and removes its containers/network after execution.
This bounded correctness matrix does not qualify capacity, every multi-node
failure boundary, database takeover or a published Rust artifact.
