# November 1, visibility queries narrowed in the database

- **Before.** A structured list `query` read every queried attribute row in
  the namespace. It then scanned the namespace's runs in list order, 500 at
  a time, deciding each in native code, until it had the page. A query
  selecting three runs in a namespace of a million read every run and every
  attribute row for the queried keys.
- **Contract.** Published PHP 2.11.7 (`WorkflowVisibilityQuery`) applies
  the query in SQL. The answers are pinned by the existing fixtures:
  `search-attributes`, `start-search-attributes`, `visibility-collation`
  (SQLite and MySQL) and `visibility-current-runs`.
- **Native.**
  - **Narrowing.** `Compiled::narrowing` gives, per `OR` group, conditions
    every run matching that group satisfies:
    - status and status bucket;
    - run type, workflow ID, run ID and queue equality or `IN`;
    - a present attribute row for each queried key;
    - keyword equality or `IN`;
    - integer comparisons and `IN`.

    The list statement adds them as one condition, with every value bound.
  - **Text comparisons.** These run under the column's own collation. On
    MySQL that is the schema's `utf8mb4_unicode_ci`, the collation #890's
    comparison keys follow. So a pair the matcher treats as equal is equal
    in the database too.
  - **Presence only.** Comparisons whose database form could differ narrow
    only to the attribute being present: times, floats, booleans, keyword
    lists, long text and text inequality. A run-time comparison narrows not
    at all.
  - **Fallback.** A group with nothing to narrow leaves the whole query
    unnarrowed, which is the previous behavior.
  - **Decision.** The matcher still decides every candidate, so the database
    can only leave out runs the query does not select.
  - **Attribute rows.** These are read per candidate page, for that page's
    runs only, not for the whole namespace.
- **Tests.**
  - `a_selective_query_reads_its_candidates_not_the_whole_namespace` uses
    650 runs, more than one scan page. A keyword query, a keyword-and-integer
    query and an integer `IN` query each read exactly their matches. A
    time-only query still reads one scan page.
  - `narrowing_holds_for_every_match_and_stays_open_when_a_group_cannot_narrow`
    pins which checks narrow.
  - The visibility and search-attribute integration tests pass.
  - Locally, on SQLite, `search-attributes`, `start-search-attributes` and
    `visibility-collation` were recorded against published 2.11.7 and
    against native built from this branch. `compare` accepts each pair. CI
    runs them on PostgreSQL and MySQL too.
