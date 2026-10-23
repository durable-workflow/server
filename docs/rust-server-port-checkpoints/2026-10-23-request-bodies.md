# October 23, empty and malformed request bodies

- **Empty bodies.** Published Server 2.11.1 reads an empty POST, PUT or
  PATCH body as empty input, as it reads `[]`, so validation answers it. An
  empty start gets 422 `The workflow type field is required.` Native
  answered with axum's plain-text JSON parse error. Native now treats an
  empty body as `{}`, as it already did `[]`.
- **Operation fields on admission refusals.** PHP adds the operation's
  fields, such as `workflow_id`, to refusals made before the controller
  runs:
  - malformed JSON;
  - a bad token;
  - an unknown namespace;
  - a missing version header.

  It does this for start, signal, update and query as for the other
  workflow commands. Native's admission responses now recognise those four
  operations too.
- **How it was checked.** A probe sent these to native and the published
  image:
  - malformed bodies on start, signal, poll and register;
  - an empty start;
  - an array body;
  - plain and scalar signal input;
  - a plain query input;
  - a bad token, an unknown namespace and a missing version header on a
    signal;
  - an unknown namespace on a start.

  Every status and body matched.
