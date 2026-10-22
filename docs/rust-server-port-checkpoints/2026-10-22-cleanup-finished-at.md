# October 22, cancellation cleanup finish time

- **Found by.** The Shared Server fixtures workflow on main. Since the merge
  of #561 it has failed in `codec-deadline-health` on every database,
  `execution-and-codec`, `mysql-execution` and `postgres-storage`. These are
  not required checks, so merges continued. Main run 39671560431 shows the
  same assertion in each of them, `cleanup closes at persisted terminal
  time`:
  - expected `2026-10-21T23:12:52.433000Z`, the run's `closed_at`;
  - actual `2026-10-21T23:12:52.433Z`, the `WorkflowCancelled` payload's
    `cancellation_cleanup.finished_at`.
- **Cause.** Native wrote `finished_at` as a raw chrono time, which serde
  prints with as few fractional digits as the value needs. Its
  `cleanup_deadline_at` and `requested_at` beside it, and the run's
  `closed_at`, use six digits. Published Workflow writes the cleanup's
  `finished_at` as the run's `closed_at->toISOString()`, and
  `CancellationCascadeView` treats a different string as a mismatch.
- **Source.** `finished_at` now uses the same six-digit form as the other
  cancellation times.
- **Verification.** The cooperative cleanup integration test compared the two
  values as parsed instants. It now also compares them as text. It fails
  without the change (`…22.519Z` against `…22.519000Z`) and passes with it.
  The SQLite cleanup, cancellation and cooperative tests pass (54).
- **Still open.** In the same run, `postgres-storage (postgres-17)` stopped
  earlier, in the operator metrics `waiting_after_redispatch` stage. Its
  health snapshot warned `durable_resume_paths`, but the metrics snapshot
  taken just before it counted no run needing repair. The two are separate
  requests, so the run's summary changed between them. That is a timing
  difference in the fixture or a native summary refresh. It is diagnosed
  separately once this assertion no longer hides it.
