# October 27, `service-calls` keeps its SQLite maintenance pause

#815 moved the reference to published 2.11.5 and recorded both
`update-validation` and `service-calls` in the SQLite main corpus with PHP's
maintenance loop running. That was based on Cloud's installed #726 acceptance
(15 of 15) and General's isolated #796 check (10 of 10). This walks part of it
back.

- **What failed.** #821's merge-group run
  [40579733447](https://github.com/durable-workflow/server/actions/runs/40579733447),
  `execution-and-codec (corpus)`. Published 2.11.5's `service-calls` record
  stopped with `RuntimeException: No charge with options.id for a later path.`
  - Its server log shows `Control-plane storage contention exhausted retries`
    and SQLite `database is locked` on an insert into `workflow_service_calls`
    while the maintenance loop ran its repair pass.
  - One earlier execute got the typed 503 `backend_lock_pressure`, and the
    probe retried it; this refusal was not that reply, so it was not retried.
  - Native and embedded were not involved.
- **Frequency.** One failure in five full SQLite runs since #815: #815's and
  #816's merge groups and two local runs passed. That is too flaky for a
  required check. #796 is still open.
- **Change.** The SQLite job and the native image corpus exclude
  `service-calls` from the main corpus again. They record it with
  `update-validation-sqlite.sh … service-calls`, which stops PHP's
  maintenance loop for the PHP record only, as before #815.
  - `update-validation` stays in the main corpus: #726 is closed with
    installed acceptance, and it has not failed since.
  - The script, `php-maintenance-window.sh` and their tests are restored
    from before #815, now pointing at the 2.11.5 baseline.
  - The MySQL, MariaDB and PostgreSQL jobs keep `service-calls` in their
    main corpus with the loop running.
- **Next.** Remove the pause again once a published reference no longer
  exhausts its retries under full-corpus SQLite load (#796).
