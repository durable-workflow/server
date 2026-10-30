# October 30, text comparisons in visibility queries follow the database

- **Before.** Native evaluated a structured list query's text comparisons
  in Rust, byte for byte, on every backend.
- **Contract** (published Server 2.11.7 on SQLite and MySQL 8.0.46,
  recorded first). PHP filters list queries in SQL, so text compares with
  the column collation.
  - SQLite compares exactly. PostgreSQL's default collation is
    deterministic, so its equality is exact too; the fixture's PostgreSQL
    corpus run checks that against PHP.
  - Every compared column in the published MySQL schema
    (`workflow_search_attributes.value_keyword` and `value_string`,
    `workflow_run_summaries.workflow_type`, `workflow_instance_id`, `id`,
    `queue`, `compatibility`) is `utf8mb4_unicode_ci`. It ignores case and
    accents, and, as a PAD SPACE collation, trailing spaces:
    `Region = "eu"` matches `EU`, `éu` and `eu `, and `WorkflowId` matches
    in any case.
  - Keyword, text and run-column fields accept only `=`, `!=` and `IN`;
    `>` is refused `422 validation_failed` on both. So only equality needs
    the collation.
- **Fixture.** `visibility-collation`, raw HTTP in a fresh namespace with a
  keyword and a text attribute. Five runs differ only by case, accents and
  trailing spaces. Twelve queries record their matching runs. The probe
  names its collation (`mysql` when the job sets `MYSQL_IMAGE`, otherwise
  `exact`) and the contract checks that collation's answers.
- **Native** (`visibility.rs`, `visibility_query.rs`).
  - Text comparisons take a key function. On SQLite and PostgreSQL the key
    is the value itself.
  - On MySQL and MariaDB the key is MySQL's own
    `HEX(WEIGHT_STRING(TRIM(TRAILING ' ' FROM value) COLLATE
    utf8mb4_unicode_ci))`. `WEIGHT_STRING` alone keeps trailing spaces,
    which the collation's equality ignores. Keys are read in batches of 64
    for the query's literals, the compared attribute values and each page's
    compared run columns.
  - `keyword_list` values are unchanged: they still compare exactly. The
    fixture does not cover them.
- **Tests.**
  - `visibility_query` unit tests with a folding key.
  - The execution test `visibility_collation` runs the same kinds of query
    on the backend under test. It passed locally on SQLite, MySQL 8.0.46
    and MariaDB 10.11; CI also runs it on MySQL and PostgreSQL.
  - The fixture: PHP and native recordings compared on SQLite and on
    MySQL 8.0.46.
