# Installed PHP SDK activity-lease controls

These are the small PHP inputs used for [Workflow #878](https://github.com/durable-workflow/workflow/issues/878).
The recorded [source-candidate result](https://github.com/durable-workflow/workflow/issues/878#issuecomment-6325709459)
distinguishes local Server qualification from published-image acceptance.
They are explicit, slow integration controls and do not run in the unit suite.

Use a disposable Server/database that advertises `activity_lease_renewal`.
Check actual discovery before admission. Do not infer that capability from
the Server version, and do not run these controls against a production database.
The fixture uses the test-only credential `synthetic`.

## Package and mounts

Run `composer install` from this directory using the committed lock. It installs
the published PHP SDK without a path repository or source overlay. The Worker
checks the loaded SDK version and reference.

Mount this directory at `/consumer` and a fresh writable evidence directory at
`/state`, using the container's unprivileged user. Set `SERVER_URL`, `NAMESPACE`
and a unique `WORKER_ID`. Run `php /consumer/worker.php` in a separate process.
Wait for `worker.registered` in `/state/worker-events.jsonl` before starting a
client. Give every new original its own evidence directory and `WORKFLOW_ID`.

## Six-minute callback

Keep the runtime's default 300-second activity-task lease. Run
`php /consumer/client.php long`. The callback blocks for 360 seconds without
an authored activity timeout or application heartbeat; original run/execution
limits are 420 seconds. The result must retain the exact int64/null/Unicode
values, one invocation and one attempt. Stop the Worker with SIGTERM after
completion and inspect its actual exit status.

## Callback-owner loss

Use an explicitly configured five-second lease and a named namespace. Namespace
setup uses control-plane version 2. Start `client.php loss` with a fresh workflow
ID and synchronize the fault with the first invocation record. Its result wait
is 30 seconds, with the same 420-second business limits and declared two attempts.

Keep the container alive while killing only the callback-owner PID from
`/state/worker.pid`. `helpers.php` identifies the independent maintainer. Within
two seconds it must be gone, and the original task's expiry must stop moving.
Start a replacement Worker with a different ID. After the lease expires, run
the ordinary, instance-scoped recovery command:

```sh
php artisan workflow:v2:repair-pass --instance-id="$WORKFLOW_ID" --json
```

The retry must keep the original activity, two-attempt policy and exact typed
result. History must record `lease_expired`; no application heartbeat is added.
This short configured-lease case does not measure default worker-loss latency.

## Durable audit

`snapshot.php` opens the default Server SQLite database read-only and records
bounded state plus hashes of history rows. Capture a prefix before the fault
and a final snapshot after stopping the API. `verify-long.php` and
`verify-loss.php` check the evidence names used in the recorded result when
mounted at `/evidence`, including helper exit, fixed expiry, original/retry
binding, heartbeat absence and unchanged history. Preserve every failed
original separately; a later original does not rescue it. Retire all task
containers, databases, images, installed packages and raw evidence afterward.
