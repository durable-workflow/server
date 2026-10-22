# October 22, external payload cleanup

- **Source.** Server 2.11.1 `SystemController::externalPayloadCleanupStatus`
  and `externalPayloadCleanupPass`, `RuntimeExternalPayloadCleanup::runPass`,
  `RuntimeExternalPayloadCleanupMetrics::snapshot`,
  `RuntimePayloadCompletionUploads::cleanup`, `RuntimePayloadCompletionLease::
  mayResume` and `ExternalPayloadBackupHold::deleting`.
- **Served.** Native answers admin-only
  `GET /api/system/external-payload-cleanup` and
  `POST /api/system/external-payload-cleanup/pass` in the default or a named
  namespace.
  - The status is the cleanup section native's operator metrics already
    report: the expired backlog, batch bounds, totals and the last pass.
  - The pass takes `limit` (`nullable|integer|min:1|max:1000`, default 100).
    It first visits the namespace's expired completion-upload allowances: one
    whose activity can still complete (its task is leased to the same owner
    and its attempt can continue) gets another abandoned-upload period, and
    any other is dropped. It then reclaims the namespace's expired, unclaimed
    references, oldest first, each under its object lock. A reference that
    shares its object with another loses only its row; a sole reference also
    deletes its object. The report lists processed, deleted, preserved,
    skipped and blocked references, the remaining backlog and scan pressure,
    and answers 207 when anything was blocked. Each pass is added to the
    namespace's totals, with whole-second times as Eloquent stores them.
- **Decisions.**
  - Native stores objects only with the local driver. A reference without an
    enabled local policy, or whose object lies outside the current local root,
    is blocked rather than deleted. PHP would delete through its configured
    remote driver or old root.
  - Native never takes an online-backup hold. A hold row left active blocks
    deletion as in PHP; a missing row means no hold, where PHP would report a
    storage failure because its migration seeds the row.
  - Native's completion allowances hold activity contexts only. An allowance
    whose context native cannot read cannot resume and is dropped once
    expired; PHP would fail the pass on a context it cannot parse.
- **Fixture.** `payload-cleanup` checks a fresh namespace's status, a pass,
  the recorded totals, limit validation (0, 1001, text and a fraction answer
  422; null and numeric text are accepted) and the final count of five
  passes. Deletion needs expired rows and stored objects, which an HTTP probe
  cannot make within the abandoned-upload period, so native tests cover it on
  SQLite, PostgreSQL and MySQL: shared, live and retained references, the
  limit and its backlog, an active backup hold, a disabled policy, and an
  allowance renewed while its activity runs and dropped after it completes.
- **Evidence.** The new tests pass on SQLite, PostgreSQL and MySQL, and the
  native suite with `--all-targets` passes on SQLite (652 tests). A local
  copy of the SQLite parity step passed all 54 fixtures on embedded PHP and
  native, including `payload-cleanup`. The published PHP fixture answers
  were checked against a fresh 2.11.1 server. Its record differed only on
  `namespace-delete`, a retryable 503 `backend_lock_pressure` that its probe
  did not retry; that probe and the storage diagnostic now retry it.
