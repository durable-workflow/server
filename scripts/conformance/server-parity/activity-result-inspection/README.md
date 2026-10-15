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
`external-result`, in both profile namespaces. Every original retains the first
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

Raw history before and after each read must agree. This profile covers the four
positive values and cold parent completion. Genuine retry/terminal outcomes,
role/ownership refusals and malformed retained history have separate source
tests. Metadata-only listing, unrelated-object isolation, all-database installed
consumer execution and Waterline UI acceptance remain additional gates. Stop and
remove every task-owned service, database, provider object, dependency tree and
raw output after its immediate audit.
