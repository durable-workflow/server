# November 1, cluster info capabilities and the activity result read

- **Before.**
  - Native's `/api/cluster/info` reported `version` `0.0.0`.
  - It had no `capabilities`, `structural_limits`, `discovery`,
    `supported_sdk_versions`, `client_compatibility` or `topology`, and
    none of PHP's conformance manifests.
  - Waterline's activity result view waits on
    `capabilities.workflow_activity_result_inspection`.
- **Contract** (published Server 2.11.7, `HealthController::clusterInfo`).
  - PHP's capability flags are fixed booleans. Its `*_contract` flags each
    announce a manifest document it publishes beside them.
  - Structural limits are `StructuralLimits::snapshot`'s configured
    ceilings plus the external payload transaction limit.
  - Discovery names the diagnostics view, operator metrics and task repair
    paths.
  - The activity result read answers `pending` before the activity
    completes, then `available` with its result envelope, and `404
    activity_not_found` for an activity the run does not have.
- **Native.**
  - **Capabilities:** true where native serves the feature, false for the
    21 manifests it does not publish. `response_compression` is `[]`,
    because native sends uncompressed responses.
  - **Structural limits:** the snapshot operator metrics already reports.
    The external payload transaction limit is left out.
  - **Discovery:** operator metrics and task repair only.
  - **Version:** the release version, passed into the image as
    `DW_SERVER_VERSION` from the `native-` tag; the crate version otherwise.
  - **`native_unqualified`:** names each member left out, with the reason.
- **Fixture.** `cluster-capabilities`, raw HTTP in a fresh namespace. PHP
  answers `expected` exactly. Native answers it with 34 reviewed
  `native_differences`, each with a reason. The fixture passes on a fresh
  2.11.7 harness and on native, and `compare` accepts the pair. The
  activity result read matches exactly, so native advertises
  `workflow_activity_result_inspection`.
- **Not covered here:** SDK version ranges, the client compatibility
  matrix, the topology manifest and PHP's conformance manifests. Native
  publishes none of them yet.
