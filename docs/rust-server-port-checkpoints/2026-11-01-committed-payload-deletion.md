# November 1, committed external payload deletion

- **Before.**
  - Run retention (#900) deleted a pruned run's provider objects inside the
    prune's transaction, before the commit. A later SQL or commit failure
    rolled back the registrations and rows, but the deleted bytes stayed
    deleted. Security confirmed it for retention (#325 comment 6555755439) and
    for the held namespace delete (#908, comments 6555970244 and 6556097626)
    with a disposable trigger aborting the registration delete.
  - Namespace delete refused with 503 whenever the namespace referenced or
    registered any external payload. #908 settled them, but with the same
    in-transaction provider deletion, so it was held.
- **Contract** (published Server 2.11.8, Core 2.15.12, source `fa3fc2f2`,
  index `sha256:3848d5d5cd1b23bd2881e3fdecea64cc44b72e6fb812e577c2db6d0872d065dd`;
  PR #911, issue #789; installed receipts #789/6558026433 and #765/6558025643;
  `docs/contracts/external-payload-storage.md`, "Committed pruning and provider
  cleanup").
  - Run, stream and namespace pruning records durable deletion intentions
    (`RuntimeExternalPayloadDeletions::stage`, table
    `runtime_external_payload_deletions`) in the transaction that releases the
    payload references, under the object lock, with the namespace's storage
    policy. Staging refuses while an online backup holds reclamation.
  - Provider deletion starts only after that transaction commits. Under the
    object lock, a registration of the object in any namespace, or a retained
    reference, fences the intention: the row goes and the object stays.
    Otherwise the driver (or one rebuilt from the saved policy) deletes the
    object; an absent object is success; then the row goes.
  - A provider failure after the commit leaves the intention pending with
    `failure_code` `backup_hold` or `storage_unavailable`; it never restores
    the pruned references. `external_payloads_deleted` still counts released
    references, and a committed prune stays `pruned`.
  - Status counts per namespace: `pending` (every row), `failed` (a failure
    other than `backup_hold`) and `blocked` (`backup_hold`).
  - The retention pass retries up to `limit` pending intentions first, and
    reports `external_payload_cleanup`. The cleanup pass retries up to `limit`
    first, reports `cleanup.retention_cleanup`, and answers 207 while any is
    pending. `GET /api/system/external-payload-cleanup` reports
    `retention_cleanup`. Namespace delete reports `external_payload_cleanup`
    and answers 207 while any is pending; `status: deleted` still means the
    catalog and workflow state are gone.
- **Native.**
  - Schema version 8 adds the native-owned `dw_external_payload_deletions`
    with PHP's columns, unique on (namespace, storage_uri_sha256), and an index
    on (namespace, updated_at, id) for retries. It is a native relation, like
    the version 7 tables, so takeover layout recognition is unchanged. The
    catalogs were captured from MySQL 8.0.46, MariaDB 10.11.19 and PostgreSQL
    17.11 (`rust/migrations/*/0008_catalog.json`).
  - `payload_deletions::stage_payload_deletion` records a deletion where
    retention (`retention_payloads::delete_retention_payloads`) and namespace
    delete (`namespace_cleanup::delete_namespace_payloads`, both passes) used
    to delete the object. The callers settle the recorded deletions after the
    commit (`settle_payload_deletions`).
  - The fence is a registration of the object in any namespace. PHP also
    scans its retained payload inventory for the URI; native references name a
    registration id, a prune records a deletion only when no retained row
    holds any reference registered for the object, and no later claim can
    name a registration that is gone. So a registration is the only way a
    retained reference can reach the object again.
  - `LocalStorage::delete_uri` deletes from the URI alone, accepting only the
    root's content address, so a retry needs no reference.
  - Like 2.11.8, staging no longer checks that the object lies under the
    current root. An object outside it now leaves the deletion pending after
    a committed prune, where 2.11.7 and the earlier native failed the prune.
  - Native has no scheduled cleanup like PHP's unscoped
    `external-payloads:cleanup`, so a cleanup pass also retries up to `limit`
    deletions whose namespace has been deleted, which no namespace's own pass
    would reach.
- **Fixtures.** `retention-external-payloads` and `namespace-delete-payloads`
  observe only fields 2.11.7 and 2.11.8 share: CI's live reference is still
  2.11.7, and recording the new counts would fail that comparison until the
  parity reference moves to 2.11.8. Both pass unchanged on a fresh 2.11.8
  harness (SQLite). The namespace delete probe now also retries the SQLite
  reference's `backend_lock_pressure` refusal, which 2.11.8 answered once
  while its maintenance loop held the writer. The new counts, the 207 answers
  and the rollback are pinned by native tests. A provider failure cannot be
  produced against the containerized reference from the probe.
- **Native tests** (`external_payloads::retention`,
  `external_payloads::namespace_delete`, `external_payload::local`):
  - a registration delete aborted by a disposable trigger after the deletion
    is recorded rolls back the prune or the namespace delete, with the object,
    registration and run or namespace intact and no deletion left;
  - a provider failure after the commit leaves a pending, failed deletion
    (207 on the cleanup pass and the namespace delete), which a later pass
    reclaims, also after the namespace is gone;
  - an online backup hold blocks a pending deletion until it is released, and
    a new registration of the same bytes fences an older deletion;
  - `delete_uri` refuses URIs outside the root and replays an absent object.
- **Not covered.** Takeover of a 2.11.8 source, whose new
  `runtime_external_payload_deletions` table is not yet a recognized source
  layout, and copying its pending intentions, is separate work.
- **Database upgrade.** No native migration runs between schema versions, so
  a database created by release candidates 1 or 2 (schema version 7) refuses
  to open on this schema with `unsupported_rust_schema`; create it again.
