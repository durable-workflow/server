# Self-contained verified history archives

A history export (`GET /api/workflows/{workflow_id}/runs/{run_id}/history/export`)
keeps each external payload as a reference envelope. Its `payload_manifest`
marks these entries `external_storage_reference`, and no bytes are copied. The
export therefore still depends on the source database's payload registrations
and on the payload store. A history archive removes that dependency
([Server #520](https://github.com/durable-workflow/server/issues/520)). It is a
directory that holds:

- the export;
- the exact bytes of every external object the export references;
- a manifest that lists each object's codec, length and SHA-256;
- a receipt, written only after the whole directory has been verified from
  disk while the copy still held the external payload backup hold.

An archive can be verified, and its payload bytes read, after retention has
pruned the run, after payload cleanup has reclaimed its objects, and without
the Server or the payload store.

`dw workflow:history-archive` creates archives and
`dw workflow:history-archive-verify` checks them. The Server binary also
verifies an archive offline:

```bash
durable-workflow-server history-archive verify ./archive-dir
```

The command prints the verification report as JSON. It exits 0 only when the
outcome is `verified`. It reads nothing but the directory: no database, payload
store or network.

## Scope

An archive covers one selected run, so it is not a backup of the application or
its database (`scope.complete_backup` is `false`).

It includes:

- the run's typed history events, from sequence 1 to `last_history_sequence`;
- the commands, signals, updates, tasks, activities, timers, failures, waits
  and timeline that the export carries;
- the identities, but not the histories, of the linked parent, child and
  continued runs;
- the bytes of every runtime external payload the export references.

It excludes other runs of the workflow instance, namespace configuration,
schedules, search attribute definitions, worker state and all other
application data. To keep a run's lineage, archive each run separately. Use
the [self-hosted backup and restore procedure](self-hosted-backup-and-restore.md)
for a database-level recovery copy. Native import or restore of an archive into
a Server is not provided. An archive gives read and verification access to the
history and its payload bytes. It does not promise that a bundle can be
replayed.

## Format, version 1

```text
manifest.json            durable-workflow.history-archive.manifest.v1
history-export.json      the export response body, byte for byte
objects/<reference_id>   the exact bytes of each required object
receipt.json             durable-workflow.history-archive.receipt.v1 (written last)
```

`manifest.json` has these fields:

| Field | Meaning |
| --- | --- |
| `schema`, `format_version` | `durable-workflow.history-archive.manifest.v1`, `1`. A verifier refuses any other value. |
| `identity` | `namespace`, `workflow_id`, `run_id`, `run_number`, `workflow_type`, `status`, `closed_at`, `last_history_sequence`, `history_event_count`, `dedupe_key`. |
| `scope` | `kind: "selected_run"`, `complete_backup: false`, and what is included and excluded. |
| `history` | `path`, `size_bytes` and `sha256` of `history-export.json`, plus the export's `export_schema`, `export_schema_version`, `export_checksum` (its own `integrity.checksum`) and `history_complete`. |
| `objects[]` | One entry per referenced object: `reference_id`, `codec`, `size_bytes`, `sha256`, `path` (`objects/<reference_id>`) and `referenced_by` (every export path that names it). |
| `totals` | `object_count` and `object_bytes`. |
| `hold` | The backup hold the copy ran under: `kind`, `owner`, `acquired_at`, `expires_at`. |
| `encryption` | `{"applied": false, "responsibility": "operator"}`. |
| `lifetime` | `application_owned`. |

`receipt.json` records `outcome: "verified"`, `verification: "cold"`,
`verified_at`, the SHA-256 of the exact manifest bytes (`manifest_sha256`),
`history_sha256`, `object_count`, `object_bytes`, and the hold, including the
`confirmed_at` database time when the hold was confirmed after verification.
Any later change to the manifest or the history no longer matches the receipt.

Object files are named by reference id. A valid id is `ep_` followed by 26
Crockford base-32 characters, so it is always one safe file name. A verifier
derives every path itself and never follows a path from the manifest. Every
file must be a regular file and `objects/` a real directory, because a link
would point outside the archive.

`rust/tests/fixtures/history-archive-v1/` is the version 1 conformance
archive. Every verifier must report it `verified`.

## Creating an archive

`dw workflow:history-archive` follows this procedure; any other client must too.
The copy uses existing routes only. It needs an admin credential, because the
backup hold is an admin route.

1. Generate a new UUID owner and acquire the
   [backup hold](contracts/external-payload-storage.md#coordinating-online-backups):
   `POST /api/system/external-payload-backup-hold/acquire {"owner": "<uuid>", "ttl": 900}`.
   While the hold is active, no payload object is reclaimed and no retention
   prune of a run with external payloads succeeds. If another backup or archive
   holds it, the answer is `409 backup_hold_owned_elsewhere`. Nothing is copied;
   retry later.
2. Read the export under the hold, then plan the archive. The export is refused,
   before any byte is copied, if any of these apply:
   - `history_complete` is not `true` (an open run, or pruned history);
   - `workflow.details_state` is `pruned`;
   - redaction was applied;
   - history sequences do not run from 1 to `last_history_sequence`;
   - it holds any reference the runtime transport cannot fetch. That covers a
     provider-specific `external_storage` reference, a published PHP stored
     reference (`dw-external-payload:v1:`), an invalid runtime reference, and
     a path that the export's own `payload_manifest` marks external but that
     holds no runtime reference.
3. Fetch each object once with
   `GET /api/external-payloads/v1/{reference_id}` and its codec, size and
   digest headers. Check the length and SHA-256 before the file is renamed into
   `objects/`. Renew the hold before it can lapse. A hold never outlives a day
   from acquisition, which bounds one archive's copy to a day.
4. Write `history-export.json` and `manifest.json`, then verify the directory
   from disk. Every byte is read back; nothing in memory is trusted.
5. Confirm that the hold is still this copy's:
   `GET /api/system/external-payload-backup-hold?owner=<uuid>`. An owner is
   single-use, so a hold that is still active proves it never lapsed during the
   copy. Only then write `receipt.json`.
6. Release the hold, whatever the outcome.

A copy that fails at any step leaves no receipt. A directory without a receipt
is never a complete archive. Rerunning the copy into the same directory
resumes it: objects already present with the right length and digest are kept.
If the copying process crashes, its hold expires after its TTL, and the next
run releases the hold it recorded. The offline
`backup-hold release --owner <uuid>` also releases it.

## Verification outcomes

| Outcome | Meaning |
| --- | --- |
| `verified` | The receipt matches the manifest and the history. It records a confirmed hold of the manifest's owner and the archive's object count and bytes. The history matches its recorded length and digest and is complete. Every referenced object is present with its recorded length and digest. The manifest lists exactly those objects, each once. |
| `incomplete` | A supported archive that fails a check. Each finding has a `code`, a message and a `path`. |
| `refused` | Not an archive this verifier can read. The manifest is missing (`manifest_missing`), unreadable or a link (`file_unreadable`, `file_not_regular`), not a JSON object (`manifest_invalid`), or of another schema or format version (`unsupported_archive`, `unsupported_format_version`). |

The finding codes are:

- history: `history_digest_mismatch`, `history_invalid`, `history_incomplete`,
  `history_pruned`, `history_redacted`, `history_sequence_gap`,
  `identity_mismatch`;
- references: `unsupported_export`, `unsupported_payload_reference`,
  `invalid_payload_reference`, `conflicting_payload_reference`;
- objects: `object_missing`, `object_size_mismatch`, `object_digest_mismatch`,
  `object_unlisted`, `object_unreferenced`, `manifest_object_mismatch`,
  `manifest_inconsistent`;
- files: `file_missing`, `file_not_regular`, `file_unreadable`;
- receipt: `receipt_missing`, `receipt_invalid`, `receipt_mismatch`.

## Costs, limits and responsibilities

- **Storage.** An archive takes the export's size plus the sum of its object
  sizes (`totals.object_bytes`). An object shared by several paths is stored
  once. The Server's transport limit bounds each object (`external.max_bytes`).
  Disk space bounds the total.
- **Load.** The copy reads each object from the payload store once. During
  verification the archive reads each object once more, from its own disk.
  While the hold is active, payload reclamation and retention prunes of runs
  with external payloads are paused for the whole Server. Keep copies short,
  and do not run them during a database backup that needs the same hold.
- **Encryption and keys.** Archives are written unencrypted. Payload bytes are
  exactly as stored, so a codec that encrypts application data keeps them
  encrypted, and the keys to read them stay with the application. Encrypting
  the archive at rest, managing its keys, and controlling who can read it are
  the operator's responsibility.
- **Lifetime.** The Server does not manage archives. Retention never deletes
  them, and their lifetime, location and deletion are application-owned.
