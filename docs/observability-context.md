# Optional PHP Server trace context

PHP Server 2.10 adds opt-in durable trace context using the published Core tracing package. Enable it only on a qualified application image with the optional OpenTelemetry API and actual nullable carrier columns. Workers and clients must discover the exact capability; a version number alone does not enable propagation. [Workflow #773](https://github.com/durable-workflow/workflow/issues/773) records the published Server/SDK tuple and its acceptance results. Earlier Server images do not gain this capability from an SDK update.

The implementation persists an optional caller origin on a newly admitted run or command. Authentication, namespace and business admission checks precede extraction. Metadata is attached to the existing business insert, using Core's nullable carrier columns. Returning an existing run does not replace its origin. A rejected or retained operation gains no tracing row or replacement metadata.

The carrier is `observability_context` with schema `durable-workflow.trace-context`, integer version `1`, and a `carrier` map. The official OpenTelemetry W3C propagator validates trace context. Limits are 256 bytes for traceparent, 512 for tracestate, 2,048 for baggage and 4,096 for the complete JSON envelope. Baggage uses an explicit allowlist; the default is empty. Applications own the optional OpenTelemetry API, with 1.10.1 as the minimum supported 1.x version. An absent API or disabled feature leaves the ordinary business path available.

Request fingerprints exclude only the admitted top-level caller carrier on start, signal-with-start, signal, update and cancellation operations. Same-named properties inside business arguments remain ordinary compared data. Dispatch/link fields at unadopted input locations are not blanket exclusions. Carriers do not enter authoritative histories or business payloads. Invalid metadata may emit only the fixed `discarded` or `filtered` disposition on an existing recording span; no raw carrier, baggage value, request body or exception text is logged by this integration.

Authorized workflow, activity and query polls deliver the run origin only after the existing original claim, routing and history fences. An actual database-queue job can supply the task-bound `dispatch_observability_context`; its configured connection, queue, row ID, payload UUID, task and run bindings must match, and its serialized command is never decoded. The default service poll mode has no such producer and omits it. `observability_links` includes only real admitted commands and validated committed Core outcomes. Child and continue-as-new commands may supply a producer for a newly created run after successful fenced completion; a disabled source or malformed producer inherits the previously recorded parent origin. Optional fields are removed only at their accepted structural paths before business comparison. Published-tuple acceptance remains required. No missing producer, outcome or event identity will be synthesized. Lease/receipt generations and original deadlines remain business authority, independent of telemetry.

Set `DW_OBSERVABILITY_CONTEXT_ENABLED=true` only on an application image that installs `open-telemetry/api:^1.10.1` and the qualified Core tracing package. The ordinary Server manifest does not require this API. The API alone does not export spans; the application owns its SDK/exporter configuration. Set `DW_OBSERVABILITY_BAGGAGE_ALLOWLIST='["tenant.id"]'` to permit an explicit key; malformed or non-list configuration permits none. Config caching preserves both controls. Disabling delivery strips cached carriers without modifying business fields, original leases or stored histories.

## Build an application image with the optional API

The ordinary image keeps the API optional. Start with the immutable published
Server image from its release receipt, then install the API in your application
image. This example uses Composer as UID 1000; root changes file ownership only.

```dockerfile
ARG SERVER_IMAGE
FROM ${SERVER_IMAGE}
USER root
RUN chown 1000:1000 /app/composer.json /app/composer.lock \
    && chown -R 1000:1000 /app/vendor
USER 1000:1000
RUN COMPOSER_HOME=/tmp/composer COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer require 'open-telemetry/api:^1.10.1' \
    --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress \
    && rm -rf /tmp/composer /tmp/composer-cache
```

Set `SERVER_IMAGE` to the immutable image reference from the release receipt and
pass it with `docker build --build-arg SERVER_IMAGE="$SERVER_IMAGE"`. Bootstrap
that application image normally, then enable the flag on its HTTP and worker
roles and refresh their configuration caches. The API can validate and persist
context without an exporter. Configure your own providers and bounded exporters
when the Server itself should emit spans. A locally built application image is
a configured consumer of the published base; it is not another official Server
release digest.

## Capability discovery

Discovery exposes `worker_protocol.server_capabilities.observability_context` only for the enabled PHP implementation with the required API and all three actual nullable carrier columns. Its exact value is:

```json
{"supported":true,"schema":"durable-workflow.trace-context","version":1,"dispatch_schema":"durable-workflow.queue-trace","links_schema":"durable-workflow.trace-links","max_context_bytes":4096,"max_links":32,"max_links_bytes":4096}
```

This capability adds no mandatory worker protocol floor. Workers must treat a missing, malformed or unknown capability as unavailable and must not infer it from a version string. Bounded discovery failure never changes business retries or admission. A fixed `discarded` or `filtered` disposition may be attached to an existing recording span; no diagnostic span or raw metadata log is created.

## Schema upgrade and runtime boundaries

The package migration adds nullable `observability_context` columns to the run,
command and task tables even when tracing is disabled. Back up before upgrading,
run the ordinary Server bootstrap, and verify those migrations on every
configured workflow storage connection. Existing rows retain null metadata and
their business/history values. Disabling tracing omits carriers from responses;
it does not delete stored context or roll back the schema.

Native catalogs, shared/frozen fixtures, Cloud adoption and native
migration/cutover have separate qualification. Preserve the PHP native-ownership
fence on every connector. The [native layout decision](https://github.com/durable-workflow/server/issues/325#issuecomment-6306545520)
acknowledges these PHP columns but does not establish an executed native takeover
or native carrier support. Use the native owner's published layout and conversion
qualification before attempting that migration.
