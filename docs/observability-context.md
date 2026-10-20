# Optional trace context preparation

This is draft PHP Server work for [Workflow #773](https://github.com/durable-workflow/workflow/issues/773). It does not advertise a capability or claim published service-mode tracing support. The source profile uses the unpublished Core tracing branch; stable delivery must use a qualified published Core package and a separately qualified Server/SDK tuple.

The first source slice persists an optional caller origin on a newly admitted run or command. Authentication, namespace and business admission checks precede extraction. Metadata is attached to the existing business insert, using Core's nullable carrier columns. Returning an existing run does not replace its origin. A rejected or retained operation gains no tracing row or replacement metadata.

The carrier is `observability_context` with schema `durable-workflow.trace-context`, integer version `1`, and a `carrier` map. The official OpenTelemetry W3C propagator validates trace context. Limits are 256 bytes for traceparent, 512 for tracestate, 2,048 for baggage and 4,096 for the complete JSON envelope. Baggage uses an explicit allowlist; the default is empty. Applications own the optional OpenTelemetry API, with 1.10.1 as the minimum supported 1.x version. An absent API or disabled feature leaves the ordinary business path available.

Request fingerprints exclude only the admitted top-level caller carrier on start, signal-with-start, signal, update and cancellation operations. Same-named properties inside business arguments remain ordinary compared data. Dispatch/link fields at unadopted input locations are not blanket exclusions. Carriers do not enter authoritative histories or business payloads. Invalid metadata may emit only the fixed `discarded` or `filtered` disposition on an existing recording span; no raw carrier, baggage value, request body or exception text is logged by this integration.

Remaining implementation covers authorized workflow/activity/query poll delivery, optional task-bound `dispatch_observability_context`, bounded `observability_links`, worker child/continue transitions, actual capability negotiation and published-tuple acceptance. No missing producer, outcome or event identity will be synthesized. Lease/receipt generations and original deadlines remain business authority, independent of telemetry.

Native catalogs, shared/frozen fixtures, Cloud adoption and native migration/cutover remain outside this PHP implementation. Its release follows Server #552's qualified Core 2.8 adoption and requires fresh combined-source checks.
