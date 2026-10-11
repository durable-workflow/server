# Service catalog operator reads

`GET /api/cluster/info` advertises `service_catalog_read_contract`, schema
`durable-workflow.v2.service-catalog-read.contract`, version 1. Clients should
check this capability before browsing a backend. An absent contract means the
backend does not support these reads, rather than an empty catalog.

All routes use `GET`, control-plane version 2 and the resolved observer
namespace. Operator and admin credentials may read. Worker credentials are
refused before namespace lookup. These routes grant no catalog mutation,
workflow execution or foreign payload access.

| Collection | List | Detail |
| --- | --- | --- |
| Endpoints | `/api/service-catalog/endpoints` | `/api/service-catalog/endpoints/{id}` |
| Services | `/api/service-catalog/services` | `/api/service-catalog/services/{id}` |
| Operations | `/api/service-catalog/operations` | `/api/service-catalog/operations/{id}` |
| Calls | `/api/service-catalog/calls` | `/api/service-catalog/calls/{id}` |

## Lists and filters

List responses contain `data`, `current_page`, `per_page`, `total`, `last_page`
and `namespace`. `page` is a positive integer. `per_page` defaults to 50 and
accepts 1 through 100. Catalog objects sort by their name ascending and ID
ascending. Calls sort by acceptance descending, creation descending and ID
ascending. Pagination has stable ties, without promising a snapshot while
other requests change the catalog.

Services accept `endpoint_id`. Operations accept `endpoint_id` and `service_id`.
Calls accept those IDs plus `operation_id`, `scope`, `status`, `status_bucket`,
`outcome` and `outcome_bucket`. `bucket` is an alias for `status_bucket`. Supplying
both aliases with different values returns HTTP 422. Explicit status/outcome
filters take precedence over their corresponding bucket, as in embedded
Waterline. Calls also return the selected scope and bucket values, the supported
statuses/outcomes and the bucket maps.

The default call scope is `relevant`, matching either the durable caller or
target namespace snapshot. `caller` and `target` select the corresponding
snapshot. `owned` selects the durable call row's namespace. Registry edits do
not change these scopes. Every filter narrows the authorized query. A foreign
catalog ID used as a filter does not authorize reading its namespace.

Unknown keys and malformed or unsupported filter values return HTTP 422.
The optional transport `namespace` query parameter is resolved by the existing
namespace middleware. An `X-Namespace` header takes precedence. Detail routes
accept only that transport parameter.

## Details and payloads

Endpoint, service and operation objects reuse the embedded catalog view fields.
Endpoint detail includes at most 100 services and 100 operations. Service detail
includes at most 100 operations. `related_has_more` identifies truncated related
collections. Use the filtered paginated lists to browse the rest. All related
objects use the observer namespace and the same stable catalog ordering.

Call detail reuses `ServiceCallView`, including original catalog/caller/linked
identities, durable status/outcome, attempts, terminal message and immutable
`call_deadline_at`. Acceptance and lifecycle timestamps use UTC with six
fractional digits. `failure_type` exposes the recorded terminal type when
present. This read does not reset deadlines, admit another attempt or execute
the service.

For the payload owner, `result_envelope` uses the same committed result envelope
as service execution reads. It includes inline Avro results or authenticated
opaque external envelopes. A valid Avro null, false or zero is a recorded result.
For a foreign observer it is null, and the internal `metadata.nexus_result`
copy is removed. `failure_type` retains the original committed service error
type, including the existing control-plane metadata forms.

`caller_link`, `linked_run_ref` and `linked_update_ref` identify whether each
reference belongs to the observer namespace. A foreign reference remains an
opaque identity and does not grant authority to browse the run or update.
`payloads_in_observer_namespace` identifies whether payload inspection is
authorized. For an observer outside the call's owning namespace, input, output
and failure payload references are null. For an authorized owner, non-null
references pass through the existing runtime external payload transport as
opaque reference objects. Provider URIs are not returned.

A missing detail and an out-of-scope detail both return HTTP 404 with
`reason: catalog_object_not_found`. Catalog objects use their own namespace.
Call details use the `relevant` caller/target scope. These reads remain available
for recorded data during execution bootstrap blockers, subject to existing
authentication, topology and namespace admission rules.

`tests/Feature/ServiceCatalogReadControllerTest.php` proves HTTP projection,
namespace visibility, role ordering, bounds and ties, filters, original
deadlines/attempts, indistinguishable 404s and opaque/redacted payloads. Native
and embedded differential qualification belongs to the shared Server rewrite
cohort. Published SDK and Waterline API/browser checks are separate consumer
acceptance, tracked in durable-workflow/waterline#174.
