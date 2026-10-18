# Cooperative cancellation with an abandoned child

The native source component admits parent root cancellation for the existing
sequential `abandon` cancellation and parent-close policies in the default
namespace. It does not cancel
the child, change its lease or require its worker to support cooperative cleanup.
The child can finish independently after its parent closes without resuming it.
Other policies and incoming child cancellation remain explicitly unavailable.

The canonical delivery kind is `child`. An existing boundary must match its
typed scheduled and started history, child call and link identities. A child
resolution physically recorded before the original cancellation request makes
that boundary ineligible. A later resolution remains eligible and reuses any
existing cancellation resume without creating another parent task. Root
completion, failure and the original deadline retain cancellation precedence.
Normal completion still refuses pending durable operations.

This follows published Workflow 2.5.32 `78075b6f`: `ChildCancellation::prepare`
returns false for Abandon, parent-close enforcement excludes abandoned links,
and both parent resume paths skip closed parents. The canonical delivery
validator compares resolution history against the original request sequence.

Source controls cover completion, failure, cold deadline expiry, both resolution
orders, duplicate receipts and a forced terminal-write rollback. Published PHP
model references qualify the delivery and policy decisions separately. These
are component controls, not full shared-runner, installed SDK, propagation,
artifact review, release or promotion acceptance.
