# October 22, query worker results

- **Found by.** The Shared Server fixtures workflow on main. After #626, its
  `mysql-execution` and `postgres-storage` jobs still failed in the SDK query
  directions profile (main run 39704552600). The PHP-worker cases differed in
  `control_plane.result`:
  - native's wire response had `null`;
  - the published Python client's hydrated response had the query state.
- **Cause.** Published PHP SDK workers complete a query task with both the
  plain `result` and its `result_envelope`. Published PHP Server's
  `completeQueryTask` accepts both and stores both. Its answer returns the
  plain `result` beside the envelope, and the control-plane envelope copies
  it. Native required the envelope, ignored the plain `result`, and always
  answered `result: null`.
- **Source.**
  - **Storage.** A completed query task keeps the worker's plain `result`.
  - **Answer.** The answer returns it, and its control-plane envelope copies
    it, as PHP does.
  - **Plain-only completions.** A completion with a plain `result` and no
    envelope is accepted, as PHP's rules allow. A completion with neither is
    still refused.
  - **Budget.** The plain result counts toward the query result budget.
- **Verification.** A new integration test completes one query with a plain
  result and an envelope, and one with only a plain result. Both answers
  carry the plain result at the top level and in `control_plane`. The query
  tests pass on SQLite, PostgreSQL and MySQL.
