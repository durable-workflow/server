# Server capabilities digest (opt-in)

Every worker-plane JSON answer carries `server_capabilities`, as PHP's
`WorkerProtocol::json` requires. A completion that carries a poll
(`activity_poll` or `workflow_poll`) carries a second copy inside its `answer`.
The object is about 17 KB, so it is most of a worker's response bytes. In a lab
run of the standard one-activity workflow it was about 306 KB of the 385 KB of
responses per workflow.

## Request

A worker opts in by sending

```
X-Durable-Workflow-Server-Capabilities-Digest: <digest>|none
```

on worker-plane requests. The value is the digest of the capabilities the
worker holds, or `none` if it holds none yet. A request without the header gets
exactly the same bytes as before.

## Answer

For an opted-in request, the server compares the request's header with the
digest of its current capabilities.

- **Match:** each copy of the current capabilities in the answer is replaced by
  the digest string. That covers the top level and up to two objects deep, as in
  a carried poll's `answer`.
- **No match:** each copy is sent in full with one added member, `digest`. Its
  value is the digest of the capabilities without that member.
- Every opted-in answer also carries the current digest in the same response
  header.

The digest is `sha256:` followed by the lowercase hex SHA-256 of the
capabilities' compact JSON. A `server_capabilities` value that differs from the
current capabilities is never replaced.

## Resolving a digest string: per request

The server replaces copies only with the digest **that request sent**. A digest
string in an answer therefore always names the capabilities the worker held when
it sent that request, never a value it learned later.

Requests can overlap. A worker can have a completion in flight under digest
`D1` while another answer moves it to `D2`; this happens during a rolling
deploy, or when the capabilities change at run time. A worker must handle this:

- Capture the digest and the capabilities object when it sends each request.
- Resolve a digest string in that request's answer against the captured pair,
  not against whatever it holds when the answer arrives.
- Learn a newer digest only from a tagged full copy, and use it for requests
  sent afterwards.

A worker that keeps only its latest digest would reject a correct `D1` answer
that arrives after it switched to `D2`. If that answer came after the server
had already committed the request, the worker would lose a committed outcome.

A digest string that matches neither the digest captured for that request nor a
tagged copy in the same answer means the worker cannot resolve it. That should
not happen, but a worker must recover from it without losing work.

## Recovering from a digest the worker cannot resolve

Never repeat a request that may have changed state just to get the
capabilities again. That covers completions, failure reports, heartbeats that
record progress, starts and signals. A repeated request is not the same as the
original: a workflow-task completion's answer can carry the next leased task
(`activity_poll.answer` or `workflow_poll.answer`), and the server answers the
repeat as a duplicate, without that task. The task then stays leased to nobody
until its lease expires.

Instead, a worker that receives a success answer naming a digest it cannot
resolve:

1. **Keeps and processes that answer as received.** Everything in it except the
   capabilities object is valid: the outcome, receipts, and any carried task.
2. **Reads the capabilities separately** with a read-only call,
   `GET /api/cluster/info`, whose `worker_protocol.server_capabilities` is the
   same object. It uses that object in place of the digest string in this
   answer.
3. **Forgets its held digest**, so its next request sends `none` and learns a
   tagged copy again.

Only a task poll, which is safe to repeat, may instead be sent again without
the header to get the full object.

In an error answer, a digest the worker cannot resolve is dropped; the HTTP
error is the answer and is never replaced or repeated.

## Compatibility and layering

Servers that do not support the header ignore it and always answer in full, so
a worker that sends it works against both.

On the server, the digest layer runs after the worker envelope is complete and
inside response compression. Compression then encodes the smaller body. The
digest layer never rewrites an already-encoded body.
