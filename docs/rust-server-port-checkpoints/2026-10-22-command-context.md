# October 22, command contexts and history principals

- **Found by.** The Sample App's children and namespaces scenarios require
  each history event a caller causes to name the authenticated actor:
  - `WorkflowStarted` and `SignalReceived` name the operator credential's
    subject;
  - `WorkflowCompleted` and `WorkflowFailed` name the worker's;
  - a cancellation names the operator who sent it;
  - with `DW_AUTH_DRIVER=none`, every event names
    `{"type":"server","id":"anonymous"}`.

  Forged body fields and headers must never become that identity. A probe of
  published PHP 2.5.14 and native showed PHP recording a principal on every
  command's events, and native recording none.
- **Source.** Native follows published PHP 2.5.14:
  - **Context.** `WorkflowCommandContextFactory::make` for start, signal,
    update, cancel, terminate, cooperative cancellation, repair and the
    schedule operations. The context holds:
    - the caller, `Standalone Server`;
    - the authentication status and method, the roles and the principal's
      audit context;
    - the request's method, path, address, user agent, request and
      correlation ids, and its SHA-256 fingerprint;
    - the server's operation id, namespace, target and operation fields;
    - the principal. Its type is `auth:<method>`, or `server` with the
      `none` driver. Its label is a `display_name`, `name` or `email` claim,
      else the capitalized primary role.

    Each command row stores its context. Schedules and repair used a fixed
    legacy identity before; they now use the same code.
  - **History.** `WorkflowHistoryEvent::record`: an event recorded with a
    command carries PHP's snapshot of that command row, read in the same
    transaction. The snapshot holds its public context, principal,
    authentication and request fields. Six hand-built partial snapshots
    (signal, update, cooperative cancellation and two repair paths) now come
    from the row. Their principals had been missing or fixed. The history
    API's `principal` already reads it.
  - **Worker results.** `WorkerTerminalEventAttribution`: before the
    completion commits, the task's `WorkflowCompleted` or `WorkflowFailed`
    gets the worker's identity as a `complete_workflow` or `fail_workflow`
    command from `worker_protocol`.
  - **Description.** Each described command carries PHP's context, principal,
    authentication and request fields.
  - **Cooperative cancellation.** Its requester is the context's principal,
    as `WorkflowStub` derives it.
  - **Forwarded headers.** Native never records forwarded attribution
    headers. It refuses to start with
    `DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS` on, rather than drop them.
- **Differences from PHP.**
  - **Route names.** PHP records a route name, and its workflow routes have
    names generated per deployment. Native records names only for the named
    schedule routes.
  - **Query principal.** Query responses and query tasks do not yet carry
    PHP's `principal` and `command_context`. That follows the query admission
    change in #619.
  - **Server address.** Without a peer address, as in in-process tests,
    native leaves out `ip`. PHP always has one.
- **Verification.** See the pull request for the suites and the PHP probe.
