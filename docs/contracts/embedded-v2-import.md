# Embedded v2 import contract

The standalone server supports importing embedded v2 history through the v2
history-export bundle:

- Export source: `workflow:v2:history-export`
- Import CLI: `workflow:v2:history-import {bundle}`
- Import HTTP: `POST /api/workflows/import/embedded-v2`
- Discovery: `/api/cluster/info` exposes
  `embedded_v2_import_contract`

Eligibility:

- The bundle schema must be `durable-workflow.v2.history-export` version `2`.
- The bundle must identify an embedded source runtime with
  `workflow.source_runtime=embedded`.
- v1 history is out of scope.
- Non-terminal runs must be the current embedded run.
- Leased workflow/activity tasks and running activity attempts must be released
  before import.
- Redacted bundles are not importable as server-managed state.
- Terminal runs require `history_complete=true`.

Source of truth:

- `history_events` are copied as the authoritative replay/audit log.
- Workflow, payload, task, activity, timer, command, signal, update, failure,
  and lineage rows are reconstructed from the bundle.
- Server summary, wait, timer, timeline, and lineage projections are rebuilt
  after durable rows are written.

Rollback and audit:

- The import writes inside one database transaction.
- A failed or interrupted import commits no partial rows.
- Retrying the same bundle is idempotent by `run_id` plus `dedupe_key`.
- Imported runs carry `import_source=embedded_v2`, `import_id`,
  `import_dedupe_key`, `import_contract_version`, `imported_at`, and summary
  `engine_source=embedded_v2_import`.
