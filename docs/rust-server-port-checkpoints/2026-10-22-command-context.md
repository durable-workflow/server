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
  - **Unknown fields.** Cancel, terminate and cooperative cancellation
    refused any body field besides their own with
    `422 unsupported_request_field`. Published PHP validates only its rules'
    fields and ignores the rest, as a probe of all three showed. Native now
    does too, so a client's `request_id` and a gateway's identity fields no
    longer refuse the command.
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
  - **Described command previews.** PHP's described commands also carry
    `payload_preview_omitted` and, for a cancel or terminate, the decoded
    `reason`. Native's never did; that is part of the description sweep.
- **Verification.**
  - **Integration tests**, on SQLite, PostgreSQL and MySQL:
    - Runtime credentials with forged body fields and headers: start,
      signal, worker completion, cancel and terminate. The history names the
      operator and the worker, and its snapshots and the run description
      carry PHP's fields. No forged value appears.
    - The `none` driver: every event names the anonymous server principal.
  - **Unit tests**, four: principal derivation, worker attribution, snapshot
    fields, and a fingerprint equal to the one published PHP recorded for the
    same request.
  - **Suites.** The full SQLite suite passes. On PostgreSQL and MySQL, the
    command context, cancellation, termination, cooperative, repair,
    credential and schedule tests pass (89 each).
  - **Differential against published PHP 2.5.14.** In the Waterline harness,
    two workflows got a start, signal, cancel or terminate with forged
    fields, request and correlation headers. The command snapshots of all
    nine history events were identical to PHP's apart from ids, times and
    route names, fingerprints included. The described commands were
    identical apart from `payload_preview_omitted` and `reason`.
  - **Found by the differential.** The first run showed every fingerprint
    differing. PHP hashes `x_request_id` before `x_correlation_id`; native's
    sorted map put them in the other order.
