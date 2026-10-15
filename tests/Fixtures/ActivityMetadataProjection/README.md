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
