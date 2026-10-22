# Published update-key upgrade consumer

This fixture covers the stop-first legacy-writer upgrade required by Server
#505. It creates genuine completed updates using an old immutable Server image,
stops every old writer, and checks the same receipts on a corrected immutable
image. It never repairs receipt columns or restarts an old writer after upgrade.
The SDK is a normal PyPI 2.6.1 installation. Its production RECORD hashes are
checked before and after each phase. The mounted PHP/Python files are business
definitions, with no replacement of product or vendor code.

Use a new Compose project and empty disposable phase directory for each mode,
`service` and `embedded`. Set `KEY_SERVER_IMAGE` to the exact old digest,
`KEY_CLIENT_IMAGE` to a pinned Python image, `KEY_MODE`, `KEY_COHORT`, and an
absolute `KEY_PHASE_ROOT`. Create `data` and `evidence` beneath the phase root
as the runtime UID. No host port is published. Queue and API roles use one CPU
and 512 MiB each; dependency installation uses a private client tmpfs.

1. Run `docker compose -f compose.yml -p PROJECT up -d --wait api client`.
   In embedded mode, also enable `--profile embedded` and start `queue`.
2. In the client, run `/opt/venv/bin/python /fixture/consumer.py create`.
   Each of the default/named originals has a separate 30-second observation,
   recorded 60-second run and 120-second execution deadline. The SDK worker
   and embedded database queue execute actual typed updates and completion.
3. Stop the API and queue explicitly with SIGTERM, require exit 0/no OOM, and
   remove those old-writer containers. The completed SDK worker must also have
   exited successfully. Retain only the stopped database and client.
4. Audit with ordinary Python: bind the phase root read-only, mount only its
   `evidence` subdirectory separately writable, and run `audit.py before` with
   a private `/audit` tmpfs. This copies the stopped DELETE-mode
   database, opens the copy read-only, proves unchanged source bytes and records
   complete original receipt/history/task rows. WAL presence is a refusal.
5. Change only `KEY_SERVER_IMAGE` to the corrected published digest. Recreate
   bootstrap and API on that same database. Never start the old image again.
6. In the client run `/opt/venv/bin/python /fixture/consumer.py retained`.
   The same body key, a new proxy header, changed arguments and a different
   declared handler must return the original typed result and exact durable
   identities without changing history.
7. Stop the new API. Run `audit.py after` as above. It must retain every old
   receipt/run/history/task field, both original deadlines, zero repairs and
   zero registrations. Additional schema columns may exist after migration.

Record the source commit, image indexes/configs, installed package provenance,
actual start/end times, outcomes and any failed original in #505. Remove the
project's containers, network, dependencies, SQLite data and exports at the
declared cleanup boundary. Preserve borrowed images and other projects. A
passing upgrade does not qualify the separate intake-limit image controls or
the broader signal/update retry contract in shared #216.

## Published intake-limit and retained-rejection controls

Use a separate fresh project with the corrected published image and
`KEY_MODE=service`. Keep the same normal Python 2.6.1 installation. This cohort
creates four ordinary waiting runs: pending-update and payload-size controls
in both default and named namespaces. Each run retains its 60/120-second
run/execution deadlines; every consumer stage has a 30-second total bound.

1. Start API/client with `KEY_PENDING_LIMIT=0 KEY_PAYLOAD_LIMIT=0`, then run
   `/opt/venv/bin/python /fixture/intake_limits.py create` in the client.
   The real Worker commits each original wait, stops, and two pending updates
   are admitted before testing the limits.
2. Stop the API with SIGTERM and require exit 0/no OOM. Run
   `intake_limits.py audit-original` in an ordinary Python container, with the
   phase root mounted read-only, its evidence directory mounted at `/evidence`
   writable, a private `/audit` tmpfs and no network.
3. Recreate only the API with `KEY_PENDING_LIMIT=1 KEY_PAYLOAD_LIMIT=0`.
   Run the consumer's `pending_update_count` stage. Both namespaces must return
   HTTP 409, `structural_limit_exceeded`, the pending-count kind and limit 1.
4. Stop the API. Recreate it with `KEY_PENDING_LIMIT=0 KEY_PAYLOAD_LIMIT=1`
   and run `payload_size`. Both namespaces must return the payload-size refusal.
   Stop it and run `audit-refused` with the same read-only mounts.
5. Recreate the API with both limits 0. Run `retained`, which changes the proxy
   header, handler and arguments while keeping each rejected caller key.
   Every response must retain its original rejection, update/command identities
   and limit metadata. Stop the API and run `audit-retained`.

A new limit refusal deliberately records one `UpdateRejected` event. The audit
requires exactly four such events, preserved original history rows, unchanged
tasks and original run state/deadlines. Only the corresponding run sequence and
progress bookkeeping may advance. Retained retries permit no durable row changes
at all, and the complete stopped database hash must stay exact. Each audit also
checks zero repairs, registrations and failed jobs. Do not extend an original's
deadline or resume an unsuccessful cohort as a new pass. Retire raw databases,
SDK dependencies, runtime and network after review; preserve borrowed images.
