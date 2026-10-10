# Fenced worker deregistration

This is the agreed, unreleased contract for Server #358. The published legacy
DELETE remains available. Feature qualification uses a new PHP/SDK tuple and
keeps the Rust comparison's frozen baseline unchanged.

## Discovery and identity

`server_capabilities.worker_deregistration_fencing` advertises
`schema: durable-workflow.v2.worker-deregistration.v1`, `supported: true`,
`receipt_retention_seconds: 600`, and
`endpoint: /worker/registrations/{workerId}/deregister`.

A successful worker registration returns `registration_token`, an opaque
32-character lowercase hexadecimal incarnation identifier. Its scope is the
resolved namespace, worker ID and that successful registration. Every
successful registration rotates the token. Heartbeats never rotate it.
Clients must capture the token returned by the registration they accepted.
The token is a fence, not an authentication credential.

Fencing is advertised only when registrations and workflow tasks use the same
database connection. Independent connections cannot commit one atomic receipt.
Such deployments advertise `supported: false`, omit the token and preserve the
legacy lifecycle. A direct fenced request returns `412 /
worker_registration_transaction_boundary_unavailable` before any mutation.

## Completion and replay

`POST /api/worker/registrations/{workerId}/deregister` accepts exactly the
registration token in its JSON body and requires the existing worker role.
There is no fallback to DELETE when this route is unsupported.

The operation checks a completed original receipt before inspecting a current
registration. A valid original receipt returns the original worker ID, token,
`outcome: deregistered` and recovered workflow-task count. A replacement with
the same worker ID is unaffected.

Without a completed receipt, only the current matching incarnation can
deregister. Its registration fence, workflow-task lease recovery, compatibility
cleanup, registration deletion and terminal receipt commit atomically. If
recovery fails, the database mutation rolls back. The existing contract
recovers workflow-task leases. It does not promise an activity cancellation.

A superseded incarnation returns `409 / worker_registration_lost_authority`.
An unknown or mismatched token returns `404 /
worker_registration_token_not_found`. An expired retained receipt returns
`410 / worker_registration_receipt_expired`. Once pruned, it is unknown. These
failures cannot mutate a replacement or acknowledge deregistration.

Completed and superseded receipts are retained for ten minutes after their
terminal transition and then pruned by the existing `history:prune` maintenance
pass, with its limit and optional namespace filter. Active
incarnations remain bound to their registration. Receipt expiry never renews a
client's retry budget.

## Transient errors and shutdown

Classified transient responses identify `operation: deregister_worker`, the
worker ID and registration token, with `outcome: unknown`, `retryable: true`
and a positive `retry_after_seconds`. Unknown includes a potentially committed
operation. Repeating the same fenced operation reconciles it. Do not infer
rollback from a non-successful reply.

The PHP Worker uses one ten-second budget for the complete deregistration operation,
including connection setup, requests and backoff. Any remaining cancellation
authority deadline is an additional ceiling. It preserves namespace,
credentials, worker ID and token, polls no new work, and never renews the
budget. Persistent failure remains visible. If the worker already failed,
that original failure retains precedence.

Automatic retries require both the negotiated fence and a transport supporting
bounded I/O. Without bounded transport, make one fenced call and report that
retry is unavailable. Without negotiated fencing, preserve one legacy DELETE.
Authorization, protocol, unknown-token, expired-receipt and lost-authority
errors are terminal. A generic 404 is never success.

## Qualification

Shared PHP/Rust fixtures cover transient recovery, persistent pressure, lost
reply after commit, original-receipt replay after replacement, token rotation
and stable heartbeat identity, namespace and role boundaries, retention,
atomic rollback, stale task publication and original-error preservation.
Embedded mode has no standalone HTTP worker-registration lifecycle. Its
existing package fixtures continue to run alongside the service-mode suite.

Feature completion requires both implementations passing the shared cases.
Published PHP/SDK artifact verification remains separate from source results.
