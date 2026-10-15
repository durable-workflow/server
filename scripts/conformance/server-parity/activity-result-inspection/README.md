# Installed activity-result consumer

This optional profile uses the exact published Server and PHP SDK tuple in
`tests/Fixtures/ActivityResultInspection/profile.json`. It does not select a new
default parity tuple. Run it against independent PHP and native service databases
with the existing `external-consumer-profile.sh` bootstrap/resource limits.
The service token for this disposable profile is `activity-result-fixture-token`.
Mount each server's isolated provider directory at `/state/objects`.

Install this directory's lock normally with `composer install --no-dev
--no-interaction --prefer-dist --no-scripts`. Set `DW_PARITY_SDK_AUTOLOAD` to its
installed `vendor/autoload.php`. Use separate PHP processes for each phase below,
inside the repository's runtime container as the checkout owner. The shared
identity file must be writable by that owner.

```sh
jq -n '{namespace:"default",workflow_id:"inspection-null",case:"encoded-null"}' > original.json
php scripts/conformance/server-parity/activity-result-inspection/probe.php "$SERVER_URL" author original.json
php scripts/conformance/server-parity/activity-result-inspection/probe.php "$SERVER_URL" activity original.json
php scripts/conformance/server-parity/activity-result-inspection/probe.php "$SERVER_URL" reader original.json
php scripts/conformance/server-parity/activity-result-inspection/probe.php "$SERVER_URL" cold-read original.json
```

Repeat with fresh workflow identities for `encoded-false`, `typed-map` and
`external-result` and `retry-completed`, in both profile namespaces. Every original retains the first
author phase's 30-second observation deadline. Activity/run budgets are authored
before admission. A later phase never restarts the original or extends its budget.

The author uses public SDK admission, task polling and an explicit activity
schedule. For the three inline cases the normal installed Worker runs one actual
callback. The external case polls its original activity, uploads explicit Avro
bytes and sends a real protocol completion. It does not qualify automatic Worker
upload policy. Both reader processes call the normal installed targeted-result
method. Its normal payload transport fetches and verifies one external object.
A separate raw response check confirms that selecting the envelope fetches no
object bytes. The first reader completes the original parent with a different
output, and the second reads the retained activity result after closure.

The retry case explicitly authors two attempts with an empty backoff list and
the same 120/600-second activity timeouts before admission. Its actual installed
Worker throws on the first callback. After the successful failure submission,
the probe reads a pending result and one canonical `ActivityRetryScheduled`
event before the second attempt is polled. The second callback uses a distinct
attempt identity and returns the typed map. Separate reader processes require
one original schedule, two starts, one retry and one completion, and preserve
the exact history prefix captured by the author. A retry does not append a
terminal `ActivityFailed` event.

Raw history before and after each read must agree. The four-value probe component
passes 48 originals across independent SQLite, MySQL 8.4 and PostgreSQL 17
PHP/native databases. The corrected retry probe separately passes twelve
originals across the same three database families, PHP/native and default/named
namespaces. Each original uses two actual callbacks, one failure submission and
one completion. The result stays pending between attempts and survives parent
closure with its exact typed bytes and history prefix. Stopped-API SQL inspection
finds two completed runs, two attempt-two activities, eight completed tasks,
sixteen events and zero repairs per implementation and database. All API
processes stop with explicit SIGTERM and exit zero without OOM. Its first original
remains failed because the probe required a terminal failure event for a retry;
the corrected check follows the existing canonical retry event.

Terminal outcomes, role/ownership refusals and malformed retained history have
separate source tests. Metadata-only listing, unrelated-object isolation,
remaining database configurations and Waterline UI acceptance remain
additional gates. Stop and
remove every task-owned service, database, provider object, dependency tree and
raw output after its immediate audit.
