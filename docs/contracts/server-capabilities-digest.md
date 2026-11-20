# Server capabilities digest (opt-in)

Every worker-plane JSON answer carries `server_capabilities` (PHP's
`WorkerProtocol::json`, server#1001). A completion that carries a poll
(`activity_poll` / `workflow_poll`) carries a second copy inside its `answer`.
On native 3.15/3.16 the object is about 17 KB, so it is most of a worker's
response bytes: in the lab, DW Standard Workflow v1 (one start, one activity,
one completion) moved about 385 KB of responses per workflow, and about 18 of
those copies (about 320 KB) were the same capabilities.

## Request

A worker opts in by sending

```
X-Durable-Workflow-Server-Capabilities-Digest: <digest>|none
```

on worker-plane requests. `none` means it holds no capabilities yet. A request
without the header gets exactly the bytes it got before this change.

## Answer

For an opted-in request, the server compares the header with the digest of its
current capabilities.

- **Match:** each copy of the current capabilities in the answer (the top level,
  or up to two objects deep, as in a carried poll's `answer`) is replaced by the
  digest string. The worker restores its held object before using the answer.
- **No match:** each copy is sent in full with one added member, `digest`, whose
  value is the digest of the capabilities without that member. The worker keeps
  the object (without `digest`) and sends the digest on its next request.
- Every opted-in answer also carries the current digest in the same response
  header.

The digest is `sha256:` followed by the lowercase hex SHA-256 of the
capabilities' compact JSON. A `server_capabilities` value that differs from the
current capabilities is never replaced. The capabilities can change while the
server runs (the external payload transport manifest is read on each call);
the next answer then has a different digest and is sent in full.

A worker that receives a digest string it does not hold must treat the answer
as a protocol failure. Older servers ignore the header and always answer in
full, so a worker that sends it works against both.

## Measured effect

Lab, native build of main with this change and SDK support, 1 wf/s, 4 workers,
1 s task polls, origin response bytes per workflow (see the PR for the table).
