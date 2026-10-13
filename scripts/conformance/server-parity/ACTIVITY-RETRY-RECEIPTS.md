# Activity retry and terminal-response controls

The pending fixtures `activity-deadline-receipts.json` and
`activity-deadline-active-receipts.json` exercise a real first-attempt timeout,
the original retry backoff, a distinct successful second attempt and one
workflow completion. The active-parent variant repeats the committed activity
submission before and after expiry while preserving the seven-event prefix.
Both retain the full retry list, typed values and original observer budget.

`activity-global-deadline-retry.json` holds the second attempt beyond the
original schedule-to-close deadline while its start-to-close deadline and a
third attempt remain. It requires one schedule-to-close timeout, refused late
outcomes and a real parent timeout delivery. It does not complete that parent.

HTTP uses a normally installed SDK Client's worker-protocol methods. Embedded
uses a real queue author followed by public activity/workflow worker bridges.
Embedded bridge results retain their actual fields and do not acquire HTTP
statuses. Closed activity/workflow refusals and retained verified receipts
follow the [settled boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6155987239).
A terminal refusal does not prove the submitted content equals the original.

Use the existing recorder in a PHP/Node runtime with the locked SDK adapter,
one explicitly selected artifact manifest, a fresh prefix and output directory.
Each target needs its own isolated database. The HTTP target also needs its
normal timeout maintenance scoped to the original instance IDs. Do not run
maintenance against another target's database or rewrite stored clocks.

```sh
node scripts/conformance/server-parity.mjs record \
  --mode http --url "$SERVER_URL" --target php \
  --runner-revision "$SOURCE_REVISION" --prefix "$FRESH_PREFIX" \
  --artifacts tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts-workflow-2.5.16.json \
  --fixture tests/Fixtures/ServerParityPending/activity-deadline-active-receipts.json \
  --fixture tests/Fixtures/ServerParityPending/activity-deadline-receipts.json \
  --fixture tests/Fixtures/ServerParityPending/activity-global-deadline-retry.json \
  --output /evidence/php.json
```

Read `SOURCE_REVISION` from Git rather than copying a shortened identifier.
For embedded mode, replace the URL flags with `--mode embedded
--application-root /runtime --target embedded` and use a separately installed
application from the manifest's normal Composer lock. Keep the same prefix,
fixture bytes and recorder revision across the isolated targets. Existing
transport journals are refused before admission.

Compare both selected records with the same artifact and fixture arguments.
Then run `activity-deadline-receipts-readers.mjs RECORD FIXTURE_ID` once per
fixture. The readers use the original full record and reject corrupted copies
without rewriting the original. Independent stopped, read-only database
inspection verifies the absolute original global deadline, attempts, task
repairs and unchanged database bytes.
Use `activity-global-retry-readers.mjs RECORD` for the global deadline fixture.
Include any nonempty SQLite WAL in that inspection. Reading an immutable main
database file alone would omit uncheckpointed committed data.

Published PHP/embedded SQLite comparison is recorded in
[the owning outcome](https://github.com/durable-workflow/server/issues/325#issuecomment-6156509796).
The separate global deadline comparison also passes on published PHP and
embedded SQLite: seven events, two of three attempts and a retained parent
delivery, with actual timeout between the original global and second-attempt
close deadlines. Native, other databases and live heartbeat/lease renewal
remain pending. These fixtures do not enlarge the
default corpus or establish release or migration readiness.
