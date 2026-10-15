# Activity metadata consumer

This separate profile uses the immutable PHP image and normally installed SDK
listed in `tests/Fixtures/ActivityMetadataConsumer/profile.json`. It does not
replace the activity-result or earlier frozen profiles. Install the existing
`activity-result-inspection/composer.lock` into a disposable SDK directory and
set `DW_PARITY_SDK_AUTOLOAD` to its installed `vendor/autoload.php`.

Create an identity JSON file with `namespace`, unique `workflow_id`, `case`
(`ordinary`, `retry-completed` or `external-result`) and `identity_path` set to
that file's path as seen by the PHP process. Run separate processes:

```sh
php scripts/conformance/server-parity/activity-metadata-consumer/probe.php "$SERVER_URL" author original.json
php scripts/conformance/server-parity/activity-metadata-consumer/probe.php "$SERVER_URL" activity original.json
php scripts/conformance/server-parity/activity-metadata-consumer/probe.php "$SERVER_URL" reader original.json
php scripts/conformance/server-parity/activity-metadata-consumer/probe.php "$SERVER_URL" cold-read original.json
```

Each original has a fixed 30-second observation budget beginning at admission.
Do not resume an expired original or extend its budget. The public installed
Client methods author one activity with an explicit retry policy, poll its
actual task, heartbeat typed details, submit its outcome and complete the same
parent. The retry case commits one actual failure and observes pending metadata
before claiming a distinct second attempt. This uses the SDK's public task
methods, not its callback Worker or automatic external-upload policy.

The external case explicitly uploads an Avro result. Between `activity` and
`reader`, temporarily make only the isolated local provider's object directory
unavailable and run the `unavailable` phase. Restore it before `reader`, even
when the check fails. Metadata must remain byte-for-byte identical with no
payload GET. Explicit result and history hydration are separate and may fetch
the restored object. The reader then checks exact typed output, unchanged
history prefix, one completion and a cold metadata read after parent closure.

Run independent PHP/native originals in default and named namespaces. Keep the
full metadata snapshots until their immediate comparison. Compare every
business field, including retry policy, class, connection, queue, history
authority, attempt status and heartbeat details. Independent physical IDs and
clock values require explicit binding and validation before comparison. JSON
member order is immaterial; array order, null, false and int64 values are not.
`compare.php php-original.json native-original.json` binds the physical run,
activity, task and attempt IDs to the recorded deliveries, then compares all
snapshot fields. It validates UTC timestamps and reports observed fractional
precision, while excluding independent clock values from the comparison. This
does not establish identical timestamp formatting or precision between servers.
This is functional qualification and does not establish performance, callback
Worker behavior, a discovery capability or release readiness.
