# Failed updates and parent continuation

This cohort extends the successful update slice in
[#424](https://github.com/durable-workflow/server/pull/424). Its
[reviewed boundary](https://github.com/durable-workflow/server/issues/325#issuecomment-6121730771)
is one accepted update whose ordinary handler fails before mutating workflow
state. A later independent update succeeds, and a finishing signal completes
the original parent workflow. A failed update must not fail that parent.

The nine ordinary PHP/Python/Rust client/author directions and canonical embedded
PHP pass on SQLite, MariaDB 10.11, MySQL 8.0/8.4 and PostgreSQL 16/17 in original
[candidate 929638262 qualification](https://github.com/durable-workflow/server/actions/runs/38278572963).
Its actual runner is `1f091ae1e8eeb9dc430ba485f07c348aa8a93098`, reviewed tree
`64a6729b26ced22a98a810a3dd1c775552f7f6f0`, on base `5fd56e0c`.
Independent original artifact inspection verifies 162 observations in this
family and 1,188 complete HTTP phase sidecars, including submitted bytes,
arrival journals, actual SDK output and canonical failure receipts.
Normal PHP/source and boundary checks pass. Current-main integration is a
separate checkpoint, with original qualified producer identities preserved.

The supported boundary remains the default namespace and serialized transactions.
Individual 35-second scenarios, original resource caps, nine fixture payloads
and exact published Server/Workflow/PHP/Python/Rust tuple are unchanged.
Newer SDK releases and separate PHP Nexus source work do not change that tuple.

The observations require:

- Original HTTP 422 and typed SDK failure details, terminal `UpdateCompleted`
  with `update_failed`, complete failure/command/update links, cursor advancement
  and no successful `UpdateApplied` for the failed update.
- Original request and pending duplicate before the cold author starts, then
  a completed duplicate. Changed duplicate arguments preserve the original
  request, arguments, durable failure and full history.
- Actual fenced acknowledgments, complete wire and arrival journals, stored
  worker rows and language-specific historical callback replay.
- Cold parent continuation, a later successful update and exact typed Avro
  output on the same run after its finishing signal.

PHP sends its exception class as `exception_type`; Python sends a short
`exception_type`, qualified `exception_class` and prefixed message; Rust sends
`exception_type=UpdateFailed` and its error display message. These actual
differences remain observable. PHP/Rust `updates()` enumerate accepted inputs,
including failed handlers, so the fixture replays its explicit rejection
condition. Python reconstructs state through successful `UpdateApplied` events.

Native regression coverage includes malformed commands, duplicate and altered
acknowledgments, stale fences, independent waiting callers and database reopen.
A normalization corpus checks 20 positive cases and four refusals against
actual frozen PHP rules. The reader binds frozen Python's recursive canonical
result hydration to its original Avro frame and exact typed int64 result.
Declared PHP repeated-patch SQLite poll pressure has a separate reviewed reader
boundary; generic storage failures remain refused.

Historical failed and cancelled records remain unchanged. In particular,
[original 38def797](https://github.com/durable-workflow/server/actions/runs/38272723583)
has five successful jobs and a MariaDB failure after two opaque storage 503s
before the later cold author. Its cause remains unresolved. Safe native
diagnostics preserve the HTTP body and log only bounded codes and fixed
categories. Passing local and current full workloads do not establish a repair
or an independent SDK defect.

The profile follows successful updates in all six automatic jobs, within the
documented 30-minute whole-job limit. Validators, queued routing, other failure
policies, alternate wait stages, namespaces/auth, consumers, migration and
performance remain separate gates. No package/image publication or cutover
qualification is established by this cohort.
