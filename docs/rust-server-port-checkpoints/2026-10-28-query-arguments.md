# October 28, query arguments the server cannot decode

- **Before.** Native decoded every query's arguments at admission. It
  refused an undecodable blob, or one that did not decode to a list, with a
  bare `422 invalid_query_arguments`, whatever the run declared.
- **Contract** (published 2.11.5; `WorkflowController::query`,
  `WorkflowQueryTaskBroker::query`, `validateContractedQueryArguments`):
  - The server decodes the arguments only when the run's start recorded a
    query contract (declared queries).
  - **Declared query.** After the unknown-query check, arguments that do
    not decode are refused before routing: `422 invalid_query_arguments`,
    with the message `Workflow query [<name>] arguments could not be
    decoded.` and `validation_errors.arguments` `["Query arguments could
    not be decoded."]`. Arguments that decode to a scalar rather than a PHP
    array are refused the same way with "must decode to an array". No query
    task is created.
  - **Named arguments (not ported here).** A string-keyed Avro map decodes
    to a PHP array too. With a durable query contract, Core normalizes it as
    named arguments (`WorkflowQueryContract::normalizeNamedArguments`), and
    the worker receives the positional result. Native still refuses a map as
    "must decode to an array". The port log records the gap (found in
    review); the query-contract-arguments slice closes it.
  - **No declared queries.** The query is routed with its arguments as
    given. A query-capable worker leases a task carrying the blob unchanged,
    and the client receives the worker's answer.
- **Fixture.** `query-arguments` (fixture key `query_argument_routing`; a
  `query_arguments` field already belongs to `state-queries`). Raw HTTP on
  protocol 1.20 in a fresh namespace:
  - a run whose worker declares no queries: the client query is held while
    the probe leases and answers the task;
  - a run whose worker declared the query.

  Each run receives an undecodable blob (`AAAA`) and the avro encoding of
  a single string.
- **Native.** Admission reads the arguments without refusing them
  (`queries::read_arguments`). `check_query_contract` refuses
  undecodable or non-list arguments only for a declared query, with PHP's
  message and `validation_errors`.
- **Tests.** `query_argument_routing.rs` (routed to the worker; refused
  before routing). Both fail on the previous source.
- **Also corrected.** The port log's "Start build-id pin" gap was stale:
  native has pinned starts since #696 (`build_pins.rs`).
