# Child resolution and an existing cancellation resume

In the admitted default-namespace sequential child path, a parent cancellation
resume can already be ready or leased when its abandoned child closes. The
child's existing typed outcome is recorded once. Its existing wait must point
to that actual parent task, with its real type and status. The parent task's
payload, claim identity, owner, attempt and lease expiry do not change.

Published Workflow 2.5.32 `78075b6f` supplies that actual task through
`ChildResolutionProjectionContext`. `RunWaitProjector::projectChildResolutionEvent`
updates the original wait and records the task association. A missing wait is
not recreated. Native applies the same association inside the child outcome
transaction, so a rejected projection write rolls back both runs' history,
child call and outcome, failure rows and the original claim changes.

Source controls cover completed, failed and directly cancelled children with
ready and leased parent resumes, cold reads, duplicate outcomes, a missing wait
and a forced association-write failure. The published PHP projector qualifies
the stored-field decisions separately. Ordinary non-cancelling parent task
conflicts, incoming child cancellation and wider propagation remain separate.
An update-only task is not labelled as a root resume; its association stays
unavailable, and the original cancellation deadline still closes the run and
accepted update. Update admission and root replay scheduling are unchanged.
No installed-observation, shared-runner, SDK, release or promotion pass follows
from these source controls.
