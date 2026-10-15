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

Set `"terminal_outcome":"failed"` in the identity file to exercise a failed
parent workflow after the same completed activity. The default is `completed`.
Both directions assert their exact terminal event and failure counts.

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
Observation clock fields are omitted from within-original response comparisons.
PHP's encrypted failure cursors have fresh random IVs. Each original token is
consumed and must return its exact supporting event and original terminal
history window before its opaque bytes are omitted from comparison. Raw tokens
and their returned pages are retained. The recorded external-payload GET count concerns requests made by
the client, not uninstrumented internal server reads.

For a summary written by an affected PHP release, retain the failed `execute`
and `cold-read` receipts, then explicitly run the existing repair command in
that isolated Server runtime:

```sh
php artisan workflow:v2:rebuild-projections --run-id="$RUN_ID" --json
php scripts/conformance/server-parity/run-observation-consumer.php "$SERVER_URL" recovered-read "$IDENTITY_JSON"
```

The recovery phase keeps the same original deadline and original product gaps.
It verifies exact summary bytes and compares all retained observation fields,
including history and failure identities, allowing only the byte counter and
summary projection timestamp to change. A recovery pass is separate from the
original product failure; it does not make that original pass. The caller must
retain and verify the repair command's selected-run report as well.

Full response snapshots, claims, history counts, pagination and SDK identity are
saved in the original identity file for immediate audit. Missing projections
are recorded as explicit product failures; they are not replaced by empty
healthy summaries. The recipe does not establish cross-runtime parity, browser
acceptance, all-database qualification, performance or native discovery.

Published PHP originals exposed terminal summary undercounting, tracked in
[Server #479](https://github.com/durable-workflow/server/issues/479). The expanded
recipe reproduces the completed/default and failed/named undercounts, then
verifies explicit selected-run repair for each while retaining the original
failure. Four fresh originals covering completed/failed and default/named
namespaces pass with the helper from
[#480](https://github.com/durable-workflow/server/pull/480) overlaid on the image.
This is source validation, not a published correction.
Native installed-consumer qualification remains open in
[#478](https://github.com/durable-workflow/server/pull/478).
