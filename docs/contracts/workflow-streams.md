# Workflow Streams Contract

Durable streaming out of a running Workflow execution. An external
consumer (UI, log sink, guardrail) subscribes to a named, run-scoped
stream produced by a Workflow and reliably receives ordered items
without missing or duplicating them, even if the worker restarts
mid-stream.

This is the parity-named view of the Replay 2026 "Workflow Streams"
capability. The underlying durability primitive is the existing
workflow signal / update / command pipeline plus an append-only
per-stream item table: an emit becomes a durable row keyed by
`(run, stream, offset)`. A reconnecting consumer resumes by passing
the next offset it expects.

This document is the authoritative description of the wire surface,
lifecycle, ordering / durability guarantees, and backpressure
semantics. The machine-readable mirror is published from
`GET /api/cluster/info` at `workflow_streams_contract`. Removing
routes, lifecycle states, or response fields is a breaking change
and requires a `version` bump in `App\Support\WorkflowStreamsContract`.

- Schema: `durable-workflow.v2.workflow-streams.contract`
- Version: `1`
- Capability flag: `workflow_streams`

## Surface

| Concern | Method + Route | Notes |
|---|---|---|
| List streams on a run | `GET /api/workflows/{workflowId}/runs/{runId}/streams` | Returns one row per opened stream, with status and last delivered offset. |
| Describe a stream | `GET /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}` | Lifecycle, last offset, total items, pending items. |
| Subscribe (read window) | `GET /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}/items?from=N&max_items=M&wait_seconds=S` | Returns items from offset `N` (inclusive). `next_offset` is the value the consumer should pass on the next call. `terminal=true` indicates the stream has closed and the consumer has reached the end. |
| Append (producer) | `POST /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}/items` | Body `{ items: [...] }`. Each item may carry an `idempotency_key`; retries with the same key collapse to the same offset. |
| Close (producer) | `POST /api/workflows/{workflowId}/runs/{runId}/streams/{streamName}/close` | Marks the stream `closed` (success) or `errored` (when `error_reason` is provided). Subsequent appends are rejected; subscribers continue to read up to retention. |

All routes are `operator`-role (or higher), control-plane-versioned,
and namespace-scoped.

## Addressing

A stream is identified by `(workflow_run_id, stream_name)`. The
stream name is up to 191 bytes and matches `^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$`.

A run can declare any number of streams, opened lazily on first
append. Naming is the producer's choice — typical names are
`tokens`, `progress`, `log`, `audit`. SDKs that wrap an LLM emit
loop typically use one stream per logical channel.

## Lifecycle

A stream walks through three statuses:

- **`open`** — accepting appends. Default on first emit.
- **`closed`** — producer finished. `error_reason` is null.
- **`errored`** — producer terminated with an error. `error_reason`
  is the producer's reason string.

`closed` and `errored` are terminal: appends are rejected with
`stream_closed` / `stream_errored`. Consumers may continue reading
the persisted history up to the configured retention.

## Ordering and durability

Per stream, items are assigned a monotonically increasing 0-based
`offset`. The DB enforces unique `(stream_id, offset)`, and an
append request is a single transaction — the offsets it returns are
contiguous against the stream's prior `last_offset`. Cross-stream
order is undefined; consumers that need cross-stream causality must
use one stream and tag items with `item_type`.

Every appended item is durably persisted before the response
returns. A consumer that crashes after seeing offset `N` reconnects
with `from=N+1` and resumes without loss. Duplicates on the wire are
expected on consumer-side retries (at-least-once); the DB enforces
at-most-once on the durable record via the `idempotency_key`
collapse.

Producer idempotency: when an `idempotency_key` is supplied and a
prior append on the same stream used the same key, the existing row
is returned (no new offset is allocated, and the `deduped` counter
on the response increments).

## Backpressure

The contract names two distinct flows:

- **Slow consumer.** Each append increments `pending_items`. Each
  successful read with `next_offset > last_offset` (consumer caught
  up) advisory-decrements pending back toward zero. When
  `pending_items >= max_pending_items`, the next append returns
  `429` with `reason=stream_full`. The default
  `max_pending_items` per stream is 10000; producers may pass an
  explicit `max_pending_items` per request to negotiate a lower or
  higher cap on a per-stream basis.
- **Fast producer / idle consumer.** A subscriber may pass
  `wait_seconds` (default 0, max 60) to long-poll. When no items
  are available within the window the request returns an empty
  `items` array and the consumer's submitted `from` offset
  unchanged. A producer is never blocked by an absent consumer;
  durability is the queue.

Per-request limits:

- `max_items` on subscribe: default 100, max 500.
- `items` on append: max 500 per request.
- `wait_seconds` on subscribe: max 60.

## Observability

`GET /api/workflows/{workflowId}/runs/{runId}/streams` returns one
row per opened stream:

```json
{
  "stream_name": "tokens",
  "status": "open",
  "last_offset": 412,
  "total_items": 413,
  "pending_items": 18,
  "opened_at": "2026-05-09T12:34:56.000Z",
  "last_appended_at": "2026-05-09T12:35:01.000Z",
  "closed_at": null,
  "error_reason": null,
  "retention_seconds": null
}
```

This is the surface UIs use to render "stream X is alive at offset
N". The same fields appear inline on the describe and subscribe
responses.

## Retention

- An `open` stream is retained until close.
- A `closed` or `errored` stream is retained for
  `retention_seconds` after `closed_at`. Default is 3600 seconds.
  The producer may override on close. The namespace retention
  sweep removes expired closed streams.

## SDK implementation notes

- **Producer** should append from inside a workflow command
  boundary so the produced offsets are part of the run's durable
  side-effects, not best-effort side channels. A clean idiom is
  `streamAppend("tokens", [...])` inside an update or signal
  handler, with `idempotency_key` derived from the workflow
  command id and a within-batch index.
- **Consumer** should persist the last consumed offset before
  processing each item, so a crashed consumer resumes from an
  offset it has already seen — duplicates are expected on replay.
- **Idempotency key** should be the producer's deterministic
  identifier for the logical item so retries collapse to one
  durable offset rather than emitting twice.
- **Close semantics**: a closed stream rejects further appends
  with `stream_closed`; consumers continue to read the persisted
  history up to retention.
- **Large payloads**: payloads larger than the namespace inline
  limit should be uploaded through the existing external payload
  storage and referenced via `payload_reference`.

## Out of scope (v1)

- A generalized pub/sub fabric across workflow runs. Streams are
  scoped to a single run; cross-run fan-in or cross-cluster
  fan-out belong in a separate transport.
- Integration with external streaming systems (Kafka, NATS, SSE
  proxy, etc.). These are implemented by adapters that subscribe
  to this surface.
- Log compaction / tail-only retention. Consumers that only care
  about the latest item should call describe and use
  `last_offset`.

## Cross-references

- Surface stability authority: `surface_stability_contract` field
  of `GET /api/cluster/info`.
- Underlying primitives: signal (`POST /api/workflows/{id}/signal/{name}`),
  update (`POST /api/workflows/{id}/update/{name}`), and the
  workflow command pipeline.
- Bounded-growth policy (caches, retention, label cardinality):
  `docs/bounded-growth.md`.
