# November 1, retention of runs with external payloads

- **Before.** Native skipped a closed run holding any external payload
  reference with `external_payload_cleanup_unavailable` and kept its
  details. Its objects were never deleted, so storage grew for anyone using
  external payloads.
- **Contract** (published Server 2.11.7, `HistoryRetentionEnforcer::pruneRun`
  and `ExternalPayloadRetentionCleanup::deleteForRun`).
  - The payload cleanup runs inside the prune's transaction, under the run's
    stream lock, before the run's rows are deleted.
  - It collects the run's referenced object URIs and drops those a retained
    run still references: any other run whose details are not pruned, in any
    namespace.
  - With nothing deletable it needs no storage. Otherwise, with no storage
    driver for the namespace (no policy, or a disabled one), the run is
    skipped with `external_payload_storage_driver_unavailable`.
  - Each URI is asked again under its object lock, in lock order. A held one
    stays. Otherwise this namespace's registration is deleted, and the object
    too when no other namespace registers it. Each URI asked and not held
    counts in the result's `external_payloads_deleted`.
  - Any failure (an active online-backup hold, a storage error) fails the
    prune: the transaction rolls back, the result is `outcome: error` with
    `Unable to delete external payload reference during retention cleanup.`,
    and the pass answers 207. Run, registration and object stay; a later pass
    deletes them. Recorded by hand on the 2.11.7 harness with a backup hold
    row and with a read-only object directory.
  - Local objects are content-addressed, so equal bytes in one namespace are
    one registration, and two namespaces over one root share the object.
- **Native** (`retention_payloads.rs`).
  - Native stores a reference as `dw-native-external-payload-v1:` and the
    reference JSON. A run's payload columns are read inside the prune's
    transaction. A column that is exactly such a reference is settled; any
    other value with a reference marker keeps the run as before.
  - A retained run holds an object when its run, command, signal, update or
    activity columns name any reference registered for the object's URI.
  - Objects are deleted after the run's rows and before the commit, so a
    failed row delete cannot strand deleted objects. A failure rolls back and
    reports PHP's message.
  - An object whose stored URI the current root no longer names fails the
    prune instead of deleting another path.
- **Fixture.** `retention-external-payloads`, raw HTTP. Two namespaces share
  one local root. It prunes a sole payload, one shared by two runs, one the
  peer namespace's run holds, and runs with storage disabled, fetching each
  payload afterwards. It passes on a fresh 2.11.7 harness and on native, and
  `compare` accepts the pair.
- **Native tests** (`external_payloads::retention`, on SQLite, PostgreSQL and
  MySQL): the fixture's cases, a backup hold and a storage failure that keep
  everything until a later pass, and a run holding PHP's stored format.
- **Not covered here:** inbound and durable stream references (nothing native
  writes them), marking inbound streams blocked on a skip, and namespace
  deletion's payload cleanup.
