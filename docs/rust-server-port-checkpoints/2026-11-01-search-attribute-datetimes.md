# November 1, search attribute datetimes and per-run limits

- **Before.**
  - Native read a datetime search attribute in RFC 3339 form, or as a date,
    or as a date and time without an offset.
  - It refused other forms that published PHP accepts, such as
    `+0200` (PHP's `DATE_ISO8601`), `+02`, a lowercase `t` or `z`, and
    RFC 2822 dates.
  - It kept nanoseconds that PHP truncates to microseconds.
  - An upsert over 100 attributes or 65,536 value bytes was refused
    `422 search_attribute_limit_exceeded`.
- **Contract** (published Server 2.11.7, Core 2.15.7).
  - `SearchAttributeValueValidator` and `WorkflowSearchAttribute::setDatetimeValue`
    read a start or upsert value with `Carbon::parse`.
  - `WorkflowVisibilityQuery` reads a datetime literal the same way.
  - An offset is stored as the instant it names: workflow#898 was fixed in
    Core 2.15.1, which shipped in Server 2.11.5.
  - An empty value stores nothing; the upsert event records it as `null`.
  - `validateAttributeSet` throws `InvalidArgumentException` over a limit.
    `WorkerController::completeWorkflowTask` catches it and answers 422
    with `outcome: rejected`, `recorded: false`, reason
    `invalid_workflow_stream_command` and the message as `error`. It
    answers reason `workflow_memo_validation_failed` instead when the
    completion also carries an `upsert_memo` command.
- **Native.**
  - `parse_datetime` reads every deterministic form `Carbon::parse`
    accepts that a client plausibly sends, and truncates to microseconds:
    - an ISO 8601 date, or a date and time with `T`, `t` or a space;
    - minutes, with optional seconds and fraction;
    - an offset written `Z`, `z`, `+HH:MM`, `+HHMM` or `+HH`, or none (UTC);
    - an RFC 2822 date.
  - Starts, upserts and visibility literals share that parser.
  - Over a limit, native answers PHP's rejection, including the memo case.
- **Decided, recorded with evidence** (a local recording against published
  2.11.7 on SQLite).
  - Native refuses, as an unparsable value, the forms whose meaning depends
    on the clock, a timezone database or PHP's date overflow. PHP accepted
    and stored them as follows:

    | Value | PHP stores it as |
    | --- | --- |
    | `tomorrow` | the next midnight, at write time |
    | `@1700000000` | `2023-11-14T22:13:20Z` |
    | `2026-02-30` | `2026-03-02T00:00:00Z` |
    | `2026-10-08T24:00:00Z` | `2026-10-09T00:00:00Z` |
    | `2026-10-08T12:34:56 Europe/Paris` | `10:34:56Z` |

  - No published SDK sends these forms:
    - Rust SDK 4.10.1 validates RFC 3339 before sending.
    - Python SDK 2.12.2 serializes datetimes with `isoformat()`.
    - PHP SDK 3.6.1 sends the string it is given.
  - ISO 8601 basic format (`20261008T123456Z`) is refused for the same
    reason: no client sends it.
- **Fixture.** `search-attribute-datetimes`, recorded against published
  2.11.7 and passing unchanged against 2.11.5:
  - 14 datetime values, each written on a start and by a first workflow
    task's upsert;
  - a visibility filter by one instant, which matches the 12 runs that
    named it;
  - four limit cases: exactly 100 attributes and exactly 65,536 bytes are
    accepted, and one more of each is rejected.
- **Tests.**
  - `datetimes_parse_as_carbon_reads_their_deterministic_forms` (library);
  - `an_upsert_over_the_run_limits_is_rejected_as_php_rejects_it`
    (execution);
  - `ServerParitySearchAttributeDatetimesContractTest.mjs`.
- **Not covered here:** how the limits are documented and configured
  ([workflow#853](https://github.com/durable-workflow/workflow/issues/853)).
