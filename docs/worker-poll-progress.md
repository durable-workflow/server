# Worker poll progress transport

Server 2.11.3 includes this optional transport. Server 2.11.4 requires an
explicit progress media type; wildcard Accept values retain ordinary JSON.
Task-delivery recovery and first-party SDK integration remain part
of [Server #526](https://github.com/durable-workflow/server/issues/526).

## Negotiation

Workflow, activity, query and dedicated update-validation polls may explicitly
request:

```http
Accept: application/vnd.durable-workflow.poll.v1+json, application/json;q=0.9
```

The media type is case-insensitive. A zero quality value declines it, and a
higher preference for `application/json` retains the ordinary response.
Requests without the explicit media type retain the existing JSON contract.
Other routes and HTTP methods retain their existing responses.

The negotiated response uses that vendor media type and contains one JSON
document. Newlines sent during a wait are leading JSON whitespace, at most
once per second. They keep a proxy's idle timer from expiring and let the
Server observe a closed client connection while waiting.

```json
{
  "schema": "durable-workflow.poll-transport.v1",
  "status": 200,
  "headers": {"x-durable-workflow-protocol-version": "1.20"},
  "body": {"task": null, "poll_status": "empty"}
}
```

`body` preserves the original encoded worker response. The transport does not
decode and re-encode task payloads or change the worker protocol version.
`headers` carries the original worker protocol header and `Retry-After`, when
present, with lowercase keys. An empty header map is `{}`.

Before waiting, an error can retain its HTTP status as well as its envelope
status. Once progress bytes have committed HTTP 200, the final `status` and
`body` still preserve the original result, including late 409, 422, 429 and
5xx errors. Clients must unwrap the document and apply the original status,
headers and error/retry rules before treating it as a task response.

The response disables intermediary buffering with `X-Accel-Buffering: no`
and uses `Cache-Control: no-store`. It bypasses the Server's ordinary JSON
body compression so progress is not combined with a separately compressed
terminal response.

## Lifetime and client requirements

The request runs inside a Fiber. Its first actual wait yields before response
progress begins. Closing the client throws a dedicated interruption into that
same Fiber so existing `finally` blocks release its exact wait resources.
Duplicate poll-result waits also yield; abandoning a duplicate does not remove
the live leader's pending key. The previous `ignore_user_abort` setting is
restored after response processing.

SDKs must accept both ordinary JSON and the negotiated envelope, reject invalid
envelopes, and preserve an absolute HTTP attempt deadline across all progress
bytes. An idle/read timeout alone can be renewed by each byte. Early cutoffs
must produce a warning with the observed duration and requested wait.

A completed socket write does not prove that an SDK received or started a
task. A failed duplicate response must not revoke a lease delivered through
another response. Authoritative task-delivery confirmation and recovery remain
separate work: qualification must cover exact namespace, task, owner, attempt
and poll-request identity, successful replay and concurrent response loss.
Wait-slot or cache hints do not authorize task revocation.

## Queue diagnostics

Task-queue list and detail responses include `poll_transport`. A recent early
disconnect produces a warning with its poll kind, observed duration, configured
wait and observation time, and sets `repair.needs_attention` with the same
warning in `repair.transport_warnings`. This identifies an observed disconnect;
it does not establish that a proxy caused it or that a task was delivered.

The observation store keeps only the latest cutoff per namespace and queue for
15 minutes under `server:worker-poll-diagnostic:`. There is no per-request,
worker or task index. A cache outage reports `status: unavailable` rather than
claiming that no disconnect occurred. An available store without a retained
observation reports `no_recent_observation`. Neither state changes task leases.

## Qualification

The focused PHP tests are in `tests/Feature/WorkerPollResponseProgressTest.php`.
They cover negotiation, raw JSON preservation, late framework errors,
resource cleanup and duplicate-poll ownership. Run them using the repository's
documented container test environment. Owning #526 records the actual PHP,
nginx, database and SDK observations and retains the full published-image
acceptance gate. The Rust owner owns the shared parity case when the contract
and release are ready; existing frozen fixtures are unchanged.
