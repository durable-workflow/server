# Accepted updates when a run closes

An accepted update must receive a durable failed outcome when its original run
closes before application. This is existing published Workflow 2.5.32 behavior
from `PendingUpdateCloser::closeForTerminalRun`. It adds no command or schema.

Close only accepted updates belonging to that run. Preserve every original
update, command, request, message and run identity. Order updates by non-null
command sequence first, then command sequence, accepted time and update ID.
Completed, failed and rejected updates remain unchanged.

Each closure creates an application-category, non-retryable update failure.
Its source is the original command when present, otherwise the update. Record
one UpdateCompleted event with the original identities and terminal reason,
without inventing an authored workflow sequence or UpdateApplied event. Set
the update's failed status, update_failed outcome, failure ID/message and close
time. The command retains its accepted status and receives update_failed and
its application time. Advance the original message cursor only forward, once
per applicable update, with the published MessageCursorAdvanced shape.

The message is `Workflow run closed as REASON before accepted update NAME could
be applied.` The exception class is RuntimeException. Keep the published event
fields, zero code and empty trace/properties exactly. Retain the originating
terminal task snapshot when one exists. Closure shares the terminal transition's
transaction: a failure while recording any update must roll back the terminal
run, update, failure, history, cursor and task changes together.

Published task snapshots include the model's storage connection name. The
standard native backends retain `sqlite`, `pgsql` and `mysql`, independently of
the task row's nullable queue connection. Timestamp comparisons preserve the
same instant and precision while allowing equivalent fractional-digit spelling.

Cold request retries return the same failed update and failure ID. Old leased
update attempts cannot apply or replace that outcome after closure. Duplicate
terminal receipts cannot create another failure or cursor event. A separate
active run and previously resolved updates are unaffected.

Native source controls cover direct cancellation, normal completion and workflow
failure. Cooperative root cancellation uses that same closer after recording
WorkflowCancelled. An authored completion or failure retains cancellation
precedence and supplies the original terminal task snapshot. Maintenance expiry
uses the original cleanup deadline and does not invent a task snapshot.

Accepted updates remain pending during cleanup and close only with their
original run. The root request, delivery identity and deadline cannot be replaced
by a duplicate request. Failure to record an update outcome rolls back the root
closure, update, leases, failures, history, cursor and summary together. After a
successful close, cold retries retain the first failed outcome and stale update
attempts cannot apply.

Existing update admission, child/activity/open-wait cooperative request guards,
wider propagation and continuation transfer retain their separate gates. The
published PHP closer/model comparison qualifies the update transformation, not
an installed native SDK or complete cooperative-cancellation execution path.
