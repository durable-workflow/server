# Back up and restore a self-hosted Server

This procedure applies to the published single-node Compose stack with MySQL,
Redis, and a shared S3-compatible external-payload store. A backup is a recovery
point, not a live migration. It contains operations committed before the MySQL
snapshot begins. Operations acknowledged after that point can be absent from a
restore. For a planned move with no write gap, stop or fence writers and take a
final backup before moving traffic.

Keep the exact Server image digest, Compose file, environment and encryption
key with the backup. Protect that bundle as a secret. The external-payload
bucket and every namespace prefix used by the snapshot are part of the same
recovery point. A MySQL dump alone is incomplete when any namespace stores
external payloads.

## Take a recovery point

1. Confirm the published Server processes all use the same external-store
   settings and that the bucket is reachable. Run the authenticated
   `POST /api/storage/test` diagnostic for each external-storage namespace.
   Check that the database tables being dumped use InnoDB. Do not run
   migrations or other schema changes during the dump. Ensure the object store
   has headroom while reclamation is held.
2. Create a protected backup directory and a new UUID for this backup. Record
   the Server image digest, Compose file, environment, source bucket and
   namespace prefixes. Copy the environment only into an encrypted or otherwise
   access-controlled backup location.
3. Acquire a [backup hold](contracts/external-payload-storage.md#coordinating-online-backups)
   before the database dump. The UUID is the owner for acquire, renew, status
   and release. Renew before it expires until the object copy has been verified.

   ```bash
   umask 077
   BACKUP_ID=$(uuidgen)
   BACKUP_DIR="backup-$BACKUP_ID"
   mkdir "$BACKUP_DIR"
   printf '%s\n' "$BACKUP_ID" > "$BACKUP_DIR/hold-owner"
   docker compose --env-file durable-workflow.prod.env \
     -f docker-compose.published.yml exec -T server \
     php artisan external-payloads:backup-hold acquire \
       --owner="$BACKUP_ID" --ttl=1800
   ```

4. Take the consistent MySQL snapshot while the hold is active.

   ```bash
   docker compose --env-file durable-workflow.prod.env \
     -f docker-compose.published.yml exec -T mysql \
     sh -lc 'mysqldump --single-transaction --quick --no-tablespaces -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
     > "$BACKUP_DIR/database.sql" 2> "$BACKUP_DIR/database.stderr"
   ```

   Check the command status, nonempty SQL, its completion footer and *all*
   stderr. An earlier documented command emitted a `PROCESS` privilege error
   while returning status 0, so exit status and footer alone are insufficient.
   Record a SHA-256 digest of the accepted SQL file.
5. Using the provider's object-copy tooling, copy every key under every
   configured external-payload namespace prefix after the SQL snapshot. Save
   each key, byte count and SHA-256 of the downloaded body in a manifest.
   Re-read or otherwise verify each saved body. Do not use an S3 ETag as a
   general SHA-256 substitute. The [published drill evidence](evidence/server-236-20260927/README.md)
   contains the exact S3 SDK copy and read-back code used for qualification.
6. Confirm the owner-scoped hold is still active **after** copying and
   verifying the objects. If it expired, changed owner, or any copy is missing,
   reject this backup candidate. Release the hold in finalization even when
   the backup fails. Do not replace the last verified recovery point with a
   failed candidate.

   ```bash
   docker compose --env-file durable-workflow.prod.env \
     -f docker-compose.published.yml exec -T server \
     php artisan external-payloads:backup-hold status --owner="$BACKUP_ID"
   docker compose --env-file durable-workflow.prod.env \
     -f docker-compose.published.yml exec -T server \
     php artisan external-payloads:backup-hold release --owner="$BACKUP_ID"
   ```

Keep the SQL, object bodies and manifest, image digest, protected environment,
owner UUID and verification results together. Test each recovery point by
restoring it into an isolated stack.

## Restore into an isolated replacement

1. Prepare a **fresh** MySQL volume, fresh Redis, and a separate object store
   or bucket. Keep the API, worker and scheduler stopped and keep customer
   traffic away. Restore the same logical bucket name and object keys used by
   the database policy. Reuse the backed-up Server image digest, encryption
   key and authentication settings. Do not point an active source and a
   replacement at the same mutable object prefix.
2. Start only MySQL and Redis in the replacement Compose project. Import the
   accepted SQL file into the empty MySQL database. Review import stderr and
   verify the expected tables are present.

   ```bash
   docker compose --env-file restored.env \
     -f docker-compose.published.yml up -d mysql redis
   docker compose --env-file restored.env \
     -f docker-compose.published.yml exec -T mysql \
     sh -lc 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' \
     < "$BACKUP_DIR/database.sql"
   ```

3. Restore every manifest key to the replacement store. Read each target body
   back and compare its length and SHA-256 to the manifest **before** starting
   Server processes. A successful upload response alone is insufficient.
4. Start the remaining published Compose services. Verify `/api/ready`, an
   authorized and unauthorized request, and the external-storage diagnostic.
   Release any historical backup hold restored with the database, using its
   recorded owner UUID.

   ```bash
   docker compose --env-file restored.env \
     -f docker-compose.published.yml up -d --wait
   docker compose --env-file restored.env \
     -f docker-compose.published.yml exec -T server \
     php artisan external-payloads:backup-hold release --owner="$BACKUP_ID"
   ```

5. Compare acknowledged workflow IDs, run IDs, histories and payload bytes to
   the source inventory. Register workers on the expected queues and verify
   pending workflow and activity tasks, due timers, outputs and completion
   counts. Check recovery and ordinary API requests before moving traffic.
   Keep the former stack fenced when the replacement begins serving writes.

The [2026-09-27 published-artifact drill](evidence/server-236-20260927/README.md)
records pinned versions, commands, raw results and recovery time. Its test
environment is isolated and contains only synthetic workflows.
