# Native MySQL Storage Admission

This disposable experiment exercises the candidate Server over concurrent HTTP
against a real, capacity-limited MySQL database. It uses the published Server
image's PHP, dependencies and web server, overlaid with the candidate source.
It is not a benchmark, production collector, SDK qualification, or Cloud plan
configuration. No provider infrastructure or credentials are needed.

MySQL stores application tables in a system tablespace capped at 256 MiB.
The test leases eight workflow tasks, prefills the database, then runs eight
concurrent producers with 256 KiB inputs. A test-only observer checks native
extent statistics at most every 100 ms and changes admission to draining at
64 MiB estimated headroom. The simulated worker maintains its heartbeat during
prefill and draining. Already-leased tasks return 896 KiB values encoded by the
bundled Avro serializer. MySQL's default sort buffer is not increased.

After draining, the test fences Server and deliberately bypasses admission with
an independent SQL writer until MySQL refuses another insertion. This verifies
the actual hard limit, not a mocked error. Completed runs must remain readable.
Recovery increases the same tablespace's limit and restarts MySQL without
deleting any rows, then checks original results and accepts another start.

## Run

Use Linux Docker/Compose with a UID 1000 checkout and an empty disposable
directory writable by that user. Select a published image digest compatible with
the candidate's locked Workflow dependency; record both the digest and candidate
commit with the result. Never use a live database or a customer's volume.

From the repository root:

```bash
export SERVER_IMAGE='durableworkflow/server@sha256:<published-image-digest>'
export COMPOSE_FILE=tests/Integration/StorageAdmission/compose.yml
export COMPOSE_PROJECT_NAME="storage-admission-$(date +%s)"
export EXPERIMENT_DIRECTORY="$(mktemp -d)"

docker compose --env-file /dev/null build server
docker compose --env-file /dev/null up -d --wait --wait-timeout 180 mysql
docker compose --env-file /dev/null up -d --no-build server
docker compose --env-file /dev/null exec -T --user 1000:1000 server php artisan server:bootstrap --force
docker compose --env-file /dev/null run --rm --no-deps experiment pressure

# At the hard limit, the queue daemon must pause before maintenance callbacks.
docker compose --env-file /dev/null run --rm --no-deps maintenance

# Keep the data volume. This is capacity growth, not deletion or reseeding.
MYSQL_MAX_MIB=384 docker compose --env-file /dev/null up -d --no-deps --wait --wait-timeout 180 mysql
docker compose --env-file /dev/null run --rm --no-deps experiment recover

docker compose --env-file /dev/null down --volumes --remove-orphans
docker image rm "${COMPOSE_PROJECT_NAME}-candidate"
```

Stop on a failed phase and inspect its logs before proceeding. The experiment
fences admission on failure. If the fixture itself needs correction, use a fresh
project and directory; do not label deletion or a partial run as successful
recovery. Always remove task containers and volumes, including after failure.
Summarize results on the owning issue/PR and remove the disposable evidence
directory afterwards; do not commit per-run artifacts.

## Interpretation

Passing requires actual accepted producers followed by explicit storage
refusals, successful bounded completions, no unexpected HTTP failures, native
MySQL error 1114 from the independent writer, and data-preserving capacity
recovery. The report includes observed headroom and accepted/refused counts.
Run counts and results are checked again in a fresh PHP process after restart.

The system tablespace cap does not constrain redo, undo, temporary files,
payload files, binary logs, Redis or the host filesystem. This experiment uses
file-backed cache and no independent scheduler process; it cannot qualify a
deployment's complete writer set, combined quota, reserve, or observation
cadence. Native extent statistics also have allocation granularity and are not
a byte reservation. Apply the [deployment requirements](../../../docs/storage-admission.md)
to the actual storage topology before enabling admission.

References: MySQL's [system tablespace limits and growth](https://dev.mysql.com/doc/refman/8.0/en/innodb-system-tablespace.html)
and [native extent statistics](https://dev.mysql.com/doc/refman/8.0/en/information-schema-files-table.html).

## Published SDK Recovery

The separate `sdk/compose.yml` override exercises exact registry-installed PHP,
Python and Rust SDKs against an unmodified published Server image. Package pins
live in `sdk/versions.env`; record them and the Server digest with the result.
This is a protocol recovery test using synthetic admission observations, not a
new capacity measurement or a production reserve qualification.

Each activity computes a value larger than the 64-KiB external-payload threshold
and records its execution before waiting at a test barrier. The test fences
Server, releases all three handlers and verifies that no acknowledgement changes
the original attempts. During draining, PHP/Rust inline completions may finish;
Python's separate payload upload remains paused because uploads are producers.
After normal admission resumes, every workflow must consume its original result,
verify the bytes and complete without re-executing its activity. A fresh-process
check after Server/MySQL restart verifies the same results and identities.

Use a new project and directory, separate from the native full-capacity test:

```bash
export SERVER_IMAGE='durableworkflow/server@sha256:<published-image-digest>'
export COMPOSE_FILE=tests/Integration/StorageAdmission/compose.yml:tests/Integration/StorageAdmission/sdk/compose.yml
export COMPOSE_PROJECT_NAME="storage-sdk-$(date +%s)"
export EXPERIMENT_DIRECTORY="$(mktemp -d)"
export COMPOSE_ENV_FILES=tests/Integration/StorageAdmission/sdk/versions.env

docker compose build php
docker compose up -d --wait --wait-timeout 180 mysql
docker compose up -d --no-build server
docker compose exec -T --user 1000:1000 server php artisan server:bootstrap --force
docker compose run --rm --no-deps --no-build experiment prepare
docker compose up -d --no-build php python rust
docker compose run --rm --no-deps --no-build experiment pressure

# Inspect actual refused acknowledgement/upload requests; no payloads are logged.
docker compose logs --no-color server
docker compose stop php python rust
docker compose restart mysql server
docker compose up -d --no-build --wait --wait-timeout 180 mysql
docker compose run --rm --no-deps --no-build experiment recover

docker compose down --volumes --remove-orphans
docker image rm "${COMPOSE_PROJECT_NAME}-sdk"
```

Retain a concise issue/PR summary, then remove the disposable directory. Stop on
a failed phase and clean up the fixture rather than counting a partial run as a
pass. The restart check preserves both MySQL data and the Server container's
local payload files; it is not a replacement-host or backup-restore experiment.
