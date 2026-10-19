# Independent cooperative cancellation of an abandoned child

An operator may request cooperative cancellation of an existing sequential child
whose operation and parent-close policies are both `abandon`. The child's own
request has a new root identity, one-entry lineage and original cleanup deadline.
It does not inherit or replace a parent's request, reason or deadline. Duplicate
requests retain the child's first receipt even after closure.

The child's cooperative completion, failure or deadline expiry commits one
canonical cancellation. That same transaction resolves the original child call
and appends the parent's canonical `ChildRunCancelled` when its original parent
is still active. It creates the ordinary parent resume, or retains an actual
existing cancellation root task and its ownership, attempt and expiry. A closed
parent is not resumed. Missing wait projections remain missing. A failed parent
write rolls back the child's terminal history, failure, tasks and parent changes.

Published Workflow 2.5.32 distinguishes an independent request from
`attemptRequestCancellationFromParent`. Its cooperative terminal path closes
accepted updates and dispatches parent resumes. This component adopts that
existing behavior in the bounded default-namespace native slice. It does not
admit inherited cancellation, other child policies, grouped children, named
cooperative cancellation or new update admission.

Focused HTTP controls cover waiting, ready and leased parent states, actual
cleanup completion/failure/deadline closure, independent root context, cold
reads, duplicate receipts, stale child attempts, missing waits, closed parents,
invalid policies and transactional rollback. Captured parent projections use
the committed published-PHP wait projector reference. These controls are
separate from installed SDK acceptance, full parity and release qualification.
