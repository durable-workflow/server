# Run activity metadata projection

`v1.json` records the metadata-only output of released Workflow 2.5.22
`RunActivityView::activitiesForRun(..., decodePayloads: false)` for 31
constructed, fully loaded model collections. These are projection inputs,
not histories claimed to have been produced by executed workflows.

The corpus covers scheduled, running, heartbeat, retry, completed, failed,
timed-out and cancelled activities, history-only records, stale mutable rows,
diagnostic fallback, separate attempt identities, terminal lease clearing,
synthetic attempts, local activity counters, reused/external payload metadata,
parallel paths, progress normalization and rounded-millisecond ordering ties.
The ordering controls include PHP 8.3's decimal pre-rounding near a half
millisecond, which differs from truncation or a single float rounding step.
See the [PHP rounding implementation](https://github.com/php/php-src/blob/php-8.3.35/ext/standard/math.c).

Typed activity history takes precedence over mutable activity rows. Attempt
history also takes precedence, including an explicitly cleared terminal lease.
An open mutable activity without history is diagnostic fallback. A terminal
mutable activity without typed history is unsupported. Listing omits arguments,
results and exceptions and never resolves an external object.

The `can_continue` fields report the released projector's recorded-state
comparison. They are not worker authority or a fresh lease/deadline check.

The PHP generator checks the five loaded projector source hashes against the
published 2.5.22 tag. The initial generation used an installed 2.5.20 package
whose five source files are byte-identical. This does not claim a fresh 2.5.22
installation or qualify a live Server image. Generation and verification reject
all database queries and external storage policy calls.

Run the generator inside a normal Server PHP runtime with `/app` as its app
root and the fixture directory mounted at `/fixture`:

```sh
php -d opcache.enable_cli=0 /app/scripts/conformance/server-parity/activity-metadata-corpus.php --verify
```

When using the published Server image, override its entrypoint with `php` so
bootstrap console output does not precede the generator's JSON. To regenerate,
omit `--verify` and save stdout as `v1.json`. Review a reference-source change
before changing the pinned hashes or expected outputs.

The Rust library test compares the complete JSON output of every case. Actual
authenticated HTTP reads, namespace/run isolation, database variants and live
workflow/attempt transitions remain separate qualification requirements.

## Installed consumer

`consumer.json` selects a separate published Server and PHP SDK tuple. It does
not update the earlier frozen parity profiles. Install the normal Composer lock
from `scripts/conformance/server-parity/activity-result-inspection` in a disposable
directory and set `DW_PARITY_SDK_AUTOLOAD` to its `vendor/autoload.php`.

Run `activity-metadata-consumer.php URL execute IDENTITY.json`, followed immediately
by `activity-metadata-consumer.php URL cold-read IDENTITY.json` in a fresh PHP
process. Both commands are in `scripts/conformance/server-parity`. Initially the
identity file contains only `namespace` and a unique `workflow_id`. Each original
workflow has one immutable 30-second observation budget covering both processes.
The runtime uses the disposable `metadata-fixture-token` credential. Run each
target against its own database and repeat for both namespaces in the profile.

The installed SDK registers types, starts an actual workflow, schedules an
activity, claims it, verifies decoded arguments, heartbeats, reports a retryable
failure, claims a distinct second attempt and completes it with an exact
int64/null/Unicode result. It then completes the original parent with a different
output. These are public SDK task primitives; the fixture does not claim that
an authored `Worker` workflow callback ran.

Eight snapshots cover pending, running, heartbeat, retry pending, resumed,
activity completed, parent closed and fresh-client reads. Every listing is
bracketed by equal raw history reads, binds its original activity and attempt
claims and must omit payload columns and external object fetches. Genuine event
counts and a decoded canonical activity result establish completed execution.

Compare the two retained identity files with:

```sh
php scripts/conformance/server-parity/activity-metadata-compare.php php.json native.json
```

The comparator compares every response field, preserving nulls, collection
order, types, policy, event kinds, attempt flags and relationships. It substitutes
only known generated run/activity/attempt/task IDs and non-null UTC timestamps.
Distinct retry task IDs remain distinct. Timestamps must parse, fall within the
authored lifecycle bounds and follow their own attempt start. Fractional-second
precision is not fixed; both millisecond and microsecond RFC 3339 strings are
valid. This normalization does not qualify deadline accuracy or latency.

The first exact hosted binary comparison passed six pairs: SQLite, PostgreSQL 17
and MySQL 8.0, each in default and named namespaces. That is twelve completed
original workflows and 48 full metadata comparisons, with a fresh client on
each original. The qualification receipt and exact binary identity are linked
from PR #476. MariaDB and the other database configurations still need this
installed-consumer fixture, independently of the existing full source matrix.
This fixture does not qualify run observation, Waterline or discovery promotion.
