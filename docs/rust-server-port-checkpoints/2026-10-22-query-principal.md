# October 22, query audit principal

- **Found by.** The Sample App's namespaces scenario queries a completed run
  in each namespace. The query result deliberately contains an application
  value named `principal` claiming to be Mallory. The scenario's gateway
  reads the Server's separate top-level audit principal from the query
  response, and requires it to name the authenticated operator. Native's
  query responses carried no principal.
- **Source.** Native follows published PHP 2.5.14's
  `WorkflowQueryTaskBroker`:
  - **Context.** A query builds PHP's command context, command `query` with
    the query name as its operation field. The query task stores it as
    `command_context`, and stores its principal's type, id and label as
    `principal`.
  - **Worker.** The worker's query task carries both, as PHP delivers them.
  - **Answer.** A completed query's response carries `principal` beside the
    result. A failed or timed-out query's response does not, as in PHP.
- **Verification.**
  - A new integration test uses runtime credentials:
    - an operator queries a run with a forged body field and headers;
    - the worker's query task names the operator and carries the `query`
      context;
    - the answer's `principal` names the operator.
  - The query and command-context tests pass on SQLite (29).
