# October 21, structured workflow list queries

- **Found by.** The Sample App's search attribute scenario lists workflows
  with typed predicates such as
  `SearchCount > 9007199254740992 AND SearchCount <= 9007199254740993`. It
  checks that each listed run carries its typed attributes. Native refused
  any `query` containing `=`, `<`, `>` or a quote with
  `422 unsupported_visibility_query`, and listed `search_attributes: []`.
- **Grammar.** Native parses the query like published PHP's
  `WorkflowVisibilityQuery`:
  - groups joined by `OR`, each joined by `AND`, outside quotes and
    parentheses;
  - `NOT`, the comparison operators, `IN (...)` lists and bare boolean
    fields;
  - quoted strings with PHP's `stripcslashes` escapes, booleans, integers,
    floats and bare identifiers.

  A plain term that does not look like a query keeps the free-text workflow
  ID and business key search.
- **Fields.** `Status` and `ExecutionStatus` compare the status or its
  bucket. `WorkflowType`, `WorkflowId`, `RunId`, `TaskQueue`, `BuildId`,
  `StartTime`, `ExecutionTime` and `CloseTime` compare run columns. Any
  other field must be a registered search attribute and compares its typed
  row: a run without the row never matches. Keyword lists match by
  membership.
- **Refusals.** Parse and type errors answer `422 validation_failed` with
  PHP's message on `query`. Examples: an undefined attribute, an ordering
  operator on a keyword, a literal of the wrong type, or `NOT IN` on a
  keyword list.
- **Lists.** Every listed run carries its typed `search_attributes`, or `[]`
  when it has none, as PHP's `getTypedSearchAttributes` returns them.
- **Remaining.** The differences in evaluation cost, text collation and
  error order are recorded as known gaps.
- **Verification.**
  - Unit tests in `visibility_query.rs` cover the Sample App's queries, plain
    terms, PHP's refusals, a missing attribute and the `NOT ... IN` form.
  - A new test lists two runs through keyword, integer-range,
    keyword-list, boolean, `OR`, `NOT`, `IN`, status and free-text queries.
    It checks the listed attributes and an undefined-attribute refusal.
