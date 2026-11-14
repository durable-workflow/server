# Open-run version usage

`GET /api/workflow-version-usage` reports which open runs retain an older
version-marker decision for one workflow type and change point. Discover support
by requiring `capabilities.workflow_version_usage` to be exactly `true` on
`GET /api/cluster/info`. A missing capability or unsuccessful request is not an
empty cohort. Published Server versions without this capability do not serve
this contract.

Use an operator or admin token, the existing control-plane version header, and
the selected namespace. Required query parameters are exact `workflow_type`
(1–255 UTF-8 bytes), exact `change_id` (1–1024 bytes), and signed 32-bit
`min_version`, the proposed minimum supported version. Optional `state` is
`legacy`, `current`, or `unknown`; `limit` is 1–200 (default 50). Continue with
only the returned opaque `cursor` and the same identity/classification filters.
Invalid or mismatched cursors return HTTP 422, rather than restarting a page.
Native namespace echo is always the selected string, including `default`.

A valid retained marker below `min_version` is `legacy`; a value at or above it
is `current`. Missing markers remain `unknown/marker_absent`, even for runs with
a current code fingerprint. Pruned, incomplete or unsupported history remains
`unknown/history_unavailable`. Malformed ranges, integer fields or conflicting
decisions remain `unknown/history_invalid`. More than 1,000 inspected marker
events in one run also makes its history unavailable. The query reads no
application arguments, results, memo or external payload objects.

`counts` includes the entire bounded open-run cohort before the optional state
filter. `runs` is ordered by ascending run ID. The first page fixes the greatest
participating ID as `through_run_id`; continuations retain that upper bound and
advance strictly after the last returned ID. Each response's counts and records
share one read-only database snapshot. Between pages, closures and marker
recording can change counts and membership. A newly matching state behind a
cursor requires a fresh traversal. This live view is not a deployment fence and
never authorizes branch removal; unknowns must stay visible while admissions
are drained or fenced through the operator's rollout process.

The normative fields are in the [shared v1 contract](https://github.com/durable-workflow/workflow/blob/9af1eb53f698f8dd764dfd7841c1851d79555b9d/docs/version-usage.md)
and the [control-plane OpenAPI](../resources/platform-protocol-specs/control-plane-api.openapi.yaml).
The endpoint changes no marker producer, history, workflow definition, schema
or replay behavior.
