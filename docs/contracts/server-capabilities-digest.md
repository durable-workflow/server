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
tagged copy in the same answer is a protocol failure.

## Compatibility and layering

Servers that do not support the header ignore it and always answer in full, so
a worker that sends it works against both.

On the server, the digest layer runs after the worker envelope is complete and
inside response compression. Compression then encodes the smaller body. The
digest layer never rewrites an already-encoded body.
