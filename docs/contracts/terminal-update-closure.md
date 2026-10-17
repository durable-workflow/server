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

Cold request retries return the same failed update and failure ID. Old leased
update attempts cannot apply or replace that outcome after closure. Duplicate
terminal receipts cannot create another failure or cursor event. A separate
active run and previously resolved updates are unaffected.

Native source controls cover existing direct cancellation, cooperative closure,
normal completion and workflow failure paths where an accepted update is
reachable. Existing update admission and continuation transfer restrictions
remain explicit, with no newly advertised acceptance for those paths.
