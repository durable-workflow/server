# November 1, external payload cleanup: blocked streams and namespace delete

- **Before.**
  - A retention prune that could not settle a run's external payloads left
    the instance's inbound streams unmarked.
  - Namespace delete refused with 503 whenever the namespace referenced or
    registered any external payload, and deleted nothing.
- **Contract, blocked streams** (published Server 2.11.7,
  `HistoryRetentionEnforcer::pruneRun` with
  `MessageStreamRetentionCleanup::markBlocked`).
  - `DetachedActivityRetentionHold` keeps a run before its streams are planned
    and marks nothing.
  - When the payload cleanup is blocked, every inbound stream of the run's
    instance records `cleanup_blocked_at`, the reason (at most 64 characters)
    and the run. That covers `external_payload_storage_driver_unavailable`, and
    native's `external_payload_cleanup_unavailable`.
  - When anything in the prune's transaction throws, the mark is
    `external_payload_cleanup_failed`.
  - A completed prune that keeps the streams clears the marks naming its run.
  - PHP reads the marks only in the capacity evidence gauge
    `message_stream_cleanup_blocked_instances`. Native serves no capacity
    evidence, so no HTTP response shows the mark. The stored columns are the
    contract, and they matter to a PHP server reading the same database.
- **Contract, namespace delete** (`NamespaceController::destroy` over
  `NamespaceLifecycleCleanup`, in one transaction before the rows go).
  - `ExternalPayloadRetentionCleanup::deleteForNamespaceCleanup` collects the
    references in the namespace's rows. A row outside the namespace that
    references an object holds it.
  - Deleting any object needs the namespace's storage driver. Without one,
    the delete answers 503 `external_payload_storage_driver_unavailable` with
    `External payload storage driver is unavailable for namespace cleanup.`,
    and nothing changes.
  - Each deletable object is asked again under its object lock, in lock order.
    This namespace's registration goes, and the object too when nothing else
    registers it. Each one counts in `external_payloads_deleted`.
  - Then `RuntimeExternalPayloadRegistry::deleteForNamespace` removes every
    registration left: uploads nothing referenced, and objects held from
    outside. An object goes too when no other namespace registers it. Each
    registration counts in `runtime_external_payload_references`.
  - When the registry pass needs storage and has none, the delete answers PHP's
    typed external payload error: 503 `external_payload_unavailable`, `External
    payload storage is unavailable for namespace cleanup.`, retryable.
  - A failed delete in the reference pass, such as an online-backup hold, is
    a plain server error. In the registry pass it is the typed error with
    `External payload storage could not complete namespace cleanup.`
- **Native.**
  - `prune_run` marks the streams after any skip or failure past the
    detached-activity hold (`mark_streams_blocked`).
  - `namespace_cleanup::delete_namespace_payloads` reads the columns native
    writes its stored reference to, the same set run retention reads. It
    settles each whole native reference as above, then removes the remaining
    registrations.
  - Any other reference marker in those columns, or any reference on the
    namespace's durable or inbound streams, still refuses before anything
    changes. Nothing native writes produces one.
  - Objects are deleted inside the transaction, before the rows. A later
    failure rolls back the rows, and objects already deleted stay deleted,
    as in PHP.
- **Fixture.** `namespace-delete-payloads`, raw HTTP. The namespace and a peer
  share one local root.
  - The namespace holds a payload only its run references, one the peer also
    registers and references, and an unreferenced upload. A third namespace
    holds only an unreferenced upload.
  - The first delete runs without storage, then with it. The peer reads its
    payload afterwards and is deleted in turn. The third namespace is deleted
    with its storage.
  - The SQLite reference's registry pass can meet its maintenance loop's
    writer under the object lock. It then answers 503 `External payload
    registry could not complete namespace cleanup.` (retryable), after rolling
    back. The probe asks such a delete again, up to five times. For the same
    reason the registry pass's refusal without storage stays out of the
    fixture: on that harness it answered with either message. Native tests
    cover that refusal.
  - Recorded locally against published 2.11.7 on three fresh harnesses, all
    passing, and on native from this branch, passing.
- **Native tests** (`external_payloads::namespace_delete` and
  `external_payloads::retention`, on SQLite, PostgreSQL and MySQL):
  - the fixture's cases, with objects and registrations checked on disk and in
    the database;
  - the marks for a storage-off skip, a backup-hold failure and an
    unsettleable reference, and the clearing of a mark by a completed prune.
