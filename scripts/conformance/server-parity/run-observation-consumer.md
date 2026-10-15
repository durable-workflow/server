# Installed run observation consumer

`run-observation-consumer.php` drives one original workflow using normal PHP SDK
task APIs, then reads it from a separate PHP process. It uses the exact author
SDK and original time budget in
[`activity-result-browser/profile.json`](../activity-result-browser/profile.json).
Install the unchanged lock in `activity-result-inspection/` with Composer and
set `DW_PARITY_SDK_AUTOLOAD` to that installation's `vendor/autoload.php`.
The caller must identify and retain the exact Server image or native artifact
being tested; the script verifies the loaded SDK identity.

Use an isolated, bootstrapped database and API with the synthetic token
`observation-fixture-token`. No scheduler or SDK Worker callback is involved:
the fixture authors commands and performs actual public task claims, heartbeat,
failure, retry, and completion directly. Namespace creation uses the public API.
Create a unique original identity file, for example:

```json
{"namespace":"observation-control","workflow_id":"observation-original-001"}
```

Run both phases in the same bounded qualification, with separate PHP processes:

```sh
php scripts/conformance/server-parity/run-observation-consumer.php "$SERVER_URL" execute "$IDENTITY_JSON"
php scripts/conformance/server-parity/run-observation-consumer.php "$SERVER_URL" cold-read "$IDENTITY_JSON"
```

The second phase retains the first phase's deadline. A product gap returns exit
1 after saving its evidence; a harness may still perform that original's cold
read, but must retain the failed aggregate. An exception or expired deadline is
not a pass. Use a new workflow identity after a failed development original.

Nine stages cover initial admission, activity scheduling, first claim,
heartbeat, retry, second claim, activity completion, parent completion, and cold
read. Each checks current and selected-run observation against actual retained
history, identity and namespace, summary counters, and wait dependency/attempt
metadata. The fixture retains the published fresh-start zero-byte shortcut;
later stages require the exact sum of stored event type and canonical JSON
payload bytes. Application payload fields remain absent from the observation
root. The history API's transport envelope is not mistaken for the observation
API's original stored payload shape.

The first history page is captured before later execution. Its continuation
must retain the original ceiling and return exactly the remaining original
sequences after completion. History is compared before and after every read.
Only observation clock fields are omitted from within-original response
comparisons. The recorded external-payload GET count concerns requests made by
the client, not uninstrumented internal server reads.

Full response snapshots, claims, history counts, pagination and SDK identity are
saved in the original identity file for immediate audit. Missing projections
are recorded as explicit product failures; they are not replaced by empty
healthy summaries. The recipe does not establish cross-runtime parity, browser
acceptance, all-database qualification, performance or native discovery.

Two published PHP originals exposed terminal summary undercounting, tracked in
[Server #479](https://github.com/durable-workflow/server/issues/479). Two fresh
originals pass with the helper from [#480](https://github.com/durable-workflow/server/pull/480)
overlaid on that image. This is source validation, not a published correction.
Native installed-consumer qualification remains open in
[#478](https://github.com/durable-workflow/server/pull/478).
