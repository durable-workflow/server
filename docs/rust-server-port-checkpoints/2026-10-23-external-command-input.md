# October 23, external payload references on signal, update and query input

Published Server 2.11.1 accepts a runtime external payload reference,
`{codec, external_payload}`, as the input of a signal, an update or a query,
as it does for a start. Native accepted one only on start, and refused it on
the other three with `invalid_payload_envelope`. Native now accepts it on all
four.

- **Signals keep the reference.** The signal's history shows the external
  envelope (`{codec, external_payload}`), and its worker reads the bytes as it
  does for a start input.
- **Updates and queries carry the bytes inline.** PHP's `resolveToArray`
  (update) and `resolve` (query) fetch the referenced bytes, so the update's
  `UpdateAccepted` arguments and the query task's `query_arguments` are the
  plain `{codec, blob}`. Native now does the same.
- **Each claims the object.** The referenced object is retained, as PHP's
  `verifyFetchedBytesAndClaim` retains it. A signal or update claims it in the
  transaction that records the command. A query claims it in a transaction of
  its own, since it records nothing else.
- **Refusals are PHP's typed external payload errors.** A reference that is
  missing, expired, malformed, from another provider or for another codec is
  refused with PHP's `durable-workflow.v2.runtime-external-payload-error.v1`
  body. That body has `schema`, `reason`, `message`, `retryable` and
  `status`, plus the route's envelope: `workflow_id` and the `control_plane`
  block on a workflow command, and the protocol fields on a worker route.
  SDKs read `retryable` from this body. Native used to answer
  `{reason, message}` with the reason as the message.
- **Check order follows PHP's `incomingEnvelope`.**
  1. A provider `external_storage` key is refused.
  2. Then an envelope that is not exactly `codec` and `external_payload`.
  3. Then the reference itself: its fields, size, registry row and expiry.
  4. Only then a codec that differs from the registered reference, as
     `external_payload_integrity_mismatch`.

  Native used to refuse a codec other than `avro` before the lookup, as
  `external_payload_unsupported`.
- **An input object naming a reference stays an object.** Native's input
  reader turns a plain object into its values, as PHP's controller does. PHP's
  transport middleware looks first, so `{codec, external_payload, extra}` is
  refused there. Native took it as three plain arguments and accepted the
  signal. Native now keeps any input object that names `external_payload` or
  `external_storage`, and refuses it.

## Differences that remain

- **When the claim happens.** PHP claims the object once its bytes are
  fetched, before the command's own transaction. A command refused after that
  point, such as a signal to a missing workflow, still leaves the object
  retained. Native claims inside the command's transaction, so a refused
  command leaves the upload unclaimed, and it expires as an abandoned upload.
  Native already did this for start. The reference, the bytes and every
  accepted command are the same.
- **Stored form of a signal's reference.** PHP stores the provider reference
  (`dw-external-payload:v1:`). Native stores its opaque runtime reference
  (`dw-native-external-payload-v1:`), as it does for start input. The
  [storage audit](../rust-server-storage-audit.md) records both forms.
  Takeover still refuses a PHP database with runtime external payload rows
  (`upgrade_source_external_payloads_unsupported`).
- **Two refusal messages.** PHP words an invalid reference codec and some
  transport-route refusals by context. Native gives one message per reason
  there. The reason, status and retryability are the same.

## How it was checked

A probe sent the same requests to native and the published image, each
against its own SQLite database. The requests were:

- a signal, an update and a query, each carrying an uploaded reference;
- a signal reusing a claimed reference;
- refusals:
  - a missing reference on start and query;
  - a codec mismatch;
  - an extra envelope key;
  - a provider reference;
  - a malformed reference object.

It then compared:

- the stored update arguments;
- the `UpdateAccepted` and `query_arguments` payloads;
- the query answer;
- the objects' retention state;
- every refusal body, field by field.

All matched, apart from the claim timing described above.
`tests/execution/external_payloads/commands.rs` covers the same cases on
SQLite, PostgreSQL and MySQL.
