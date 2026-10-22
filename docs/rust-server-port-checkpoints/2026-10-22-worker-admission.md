# October 22, worker registration admission

- **Gap.** Native accepted a registration without the `capability_manifest`
  PHP requires from protocol 1.18. It also skipped most of PHP's request
  rules and capability guards, refused a missing `worker_id` or type list,
  and answered metadata-capability mismatches in its own shape.
- **Source.** Server 2.10.0 `WorkerController::register`:
  - its Laravel rules;
  - the prepared-local-activity, Nexus and cooperative-cancellation guards;
  - `guardPortableWorkerCapabilityManifest`;
  - `WorkflowMetadataCapabilityPolicy::firstProtocolMismatch`;
  - the message-streams floor;
  - `portableWorkerCapabilityManifest`.
- **Request rules.** Laravel trims strings and turns empty ones into null
  first; native now does the same for the whole body.
  - Every field follows PHP's rules: `runtime` from PHP's list, sizes,
    capacities, task slots, process metrics, heartbeat interval.
  - `capability_manifest` is required from protocol 1.18 and must hold the
    `local_activities`, `worker_sessions` and `sticky_execution` entries.
    Each entry needs `supported` (boolean) and `minimum_protocol_version`.
  - Failures answer Laravel's 422 `validation_failed` with its messages. The
    rule engine gained `numeric` and `required_array_keys`. A `*.field` rule
    now covers only array items, as Laravel's expansion does.
- **Guards.** Each answers 409 with `registered: false` and PHP's fields, in
  PHP's order:
  - `prepared_local_activity_cancellation_policy_capability_mismatch`,
    `prepared_local_activity_group_capability_mismatch` and
    `prepared_local_activity_capability_mismatch`;
  - `nexus_cooperative_cancellation_capability_mismatch` and
    `cooperative_cancellation_protocol_mismatch`, below protocol 1.20;
  - `worker_capability_manifest_mismatch`, when a flat affinity capability
    and its `supported` flag disagree;
  - `worker_capability_protocol_floor_mismatch`, when an entry's floor is
    below 1.18 or above the request's protocol;
  - `workflow_metadata_capability_protocol_mismatch`, for `memo_upserts`
    below 1.14 and `typed_search_attributes` below 1.16. This now uses PHP's
    `capability`, `requested_version` and `remediation` fields, not native's
    former `worker_capability` and `recorded`;
  - `message_streams_unavailable`, below 1.15.
- **Accepted registrations.**
  - A missing `worker_id` gets a ULID, as PHP does.
  - Missing type lists are stored empty.
  - Capabilities keep their non-empty strings.
  - The manifest is stored normalized: the three entries, a trimmed floor,
    and non-empty `implementation` and `reason`.
- **Worker protocol bodies.** Registration refusals now carry
  `protocol_version` and `server_capabilities`, as `WorkerProtocol::json`
  does. Other worker-plane refusals still lack them (#46's shape sweep).
- **Tests.**
  - `worker_admission.rs` uses the server's own router. It covers the
    manifest requirement and its errors, field rules with Laravel's
    messages, the generated id, the stored manifest, and every guard.
  - The other tests' router adds the manifest an SDK sends from 1.18,
    matching each registration's flat capabilities.
  - Two cooperative tests registered `cooperative_cancellation` at protocol
    1.19, which PHP refuses. They now register at 1.20 and still poll at the
    protocol each case tests.
  - Full SQLite run (all targets): 230 unit, 401 execution and 2 upgrade
    tests passed. Worker, registration, admission, cooperative and
    namespace tests: PostgreSQL 124, MySQL 124.
