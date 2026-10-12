# Failed updates and parent continuation

This pending cohort extends the successful update slice in
[#424](https://github.com/durable-workflow/server/pull/424). Its
[reviewed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6121730771)
is one accepted update whose ordinary handler fails before mutating workflow
state. A later independent update succeeds, and a finishing signal completes
the original parent workflow. A failed update must not fail that parent.

The existing successful-update fixture files and their exact published
Server/Workflow/PHP/Python/Rust tuple remain unchanged. This cohort will select
the same package and image identities in its own manifest; newer SDK releases
and the separate PHP Nexus source integration do not change the reference.

Required observations:

- All nine ordinary PHP/Python/Rust update-client and workflow-author directions,
  plus canonical embedded PHP, in separate databases.
- Original HTTP 422 and SDK failure details, terminal `UpdateCompleted` with
  `update_failed`, complete failure and command/update links, cursor advancement,
  and no `UpdateApplied` for the failed update.
- A real original request and pending duplicate submitted before the cold
  author starts, followed by a completed duplicate. Changed duplicate arguments
  preserve the original request, arguments, durable failure and full history.
- Actual fenced author acknowledgments, complete wire and arrival journals,
  stored worker rows and language-specific historical callback replay.
- Cold parent continuation, a later successful update, and exact typed Avro
  output on the same original run after its finishing signal.
- PHP Server, native and embedded execution on all six existing database
  configurations, independently reviewed original artifacts, and normal source
  checks before promotion into the qualified profiles.

The immutable SDK producers differ: PHP sends its exception class as
`exception_type`; Python sends a short `exception_type`, qualified
`exception_class` and prefixed message; Rust sends `exception_type=UpdateFailed`
and its error display message. The reader must preserve these actual differences
while checking each original failure and its duplicates.

Nine candidate fixtures and failure-aware ordinary SDK actors are implemented.
The observer retains typed SDK failures and requires the matching original
`fail_update` acknowledgment. Strict checks cover terminal failure identity,
unchanged pending/completed duplicates, full history/cursor/worker rows and
parent continuation. These checks remain distinct from runtime qualification.

A preliminary SQLite PHP/PHP recording and canonical embedded recording pass
the full lifecycle reader. The actual HTTP response has `outcome=update_failed`
and `reason=null`. Embedded PHP retains its own original exception metadata and
retry classification. PHP/Rust `updates()` enumerate accepted inputs, including
failed handlers; the fixture's final state replays its explicit rejection
condition. Python reconstructs state through successful `UpdateApplied` events.
The first PHP author assumption is retained as a failed reader record.

Native failure-command validation, durable failure links and terminal HTTP 422
receipts are implemented, with duplicate, fencing and database reopen/parent
continuation regressions. A normalization corpus checks actual PHP rules and
the native implementation. Compilation and runtime qualification remain pending.

The executable pending profile is included after the successful-update profile
in all six automatic database jobs. The additional workload uses a 30-minute
whole-job limit, preserving individual scenario budgets, resource limits and
frozen package identities. Historical 25-minute failures/cancellations remain
unchanged. The complete cross-language baseline and independently inspected
all-six native/PHP/embedded qualification are required before promotion.
Validators, queued routing, other failure policies, alternate wait stages,
namespaces/auth, consumers, migration and performance remain separate gates.
