# Platform Conformance — Server Claim

The standalone `durable-workflow/server` participates in the platform
conformance suite specified in
[`workflow/docs/architecture/platform-conformance-suite.md`](https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/platform-conformance-suite.md)
and mirrored by `Workflow\V2\Support\PlatformConformanceSuite`. This
document is the per-repo claim: it lists the conformance targets the
server claims, the fixture sources it serves, and the release gate that
blocks publication when conformance is broken.

## Claimed targets

The server claims three targets from the suite's matrix:

- `standalone_server` — implements the `server_api`, `worker_protocol`,
  and `cluster_info_manifests` surface families.
- `worker_protocol_implementation` — implements the worker plane and
  the frozen history-event wire formats.
- `repair_actionability_surface` — emits the failure / repair /
  actionability shapes consumed by operators and AI clients.

## Fixture sources served by this repo

The fixture catalog in the suite manifest names paths in this repo as
the source of truth for two categories:

| Category | Source path | Status |
| --- | --- | --- |
| `worker_task_lifecycle` (server side) | `tests/Fixtures/` plus the per-route examples in `docs/contracts/external-task-input.md` and `docs/contracts/external-task-result.md` | stable |
| `failure_repair_actionability` | `docs/contracts/external-task-result.md`, `docs/contracts/replay-verification.md`, plus the artifact objects published from `GET /api/cluster/info`'s `worker_protocol.external_task_result_contract.fixtures` | stable |

The other categories the server is graded against
(`control_plane_request_response`, `history_replay_bundles`) live in
the `cli`, `sdk-python`, and `workflow` repositories and are loaded by
the harness from there.

## Release gate

A release of `durable-workflow/server` must produce a passing harness
result document before tag, with the conformance level at `full` or
`provisional` (provisional categories enumerated in release notes).

| Field | Value |
| --- | --- |
| Required claimed targets | `standalone_server`, `worker_protocol_implementation`, `repair_actionability_surface` |
| Required suite version | `PlatformConformanceSuite::VERSION` (currently `1`) — the harness must run against the suite version exposed by the build under test |
| CI job | `platform-conformance` (lands when the harness reference implementation publishes; until then the server release reviewer manually verifies parity against the existing fixture-driven tests under `tests/Feature` and `tests/Unit/EnvContractTest.php`) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

A `nonconforming` result blocks the release. A failure in a provisional
category emits a warning and does not block.

## Cross-references

- Authority spec: `workflow/docs/architecture/platform-conformance-suite.md`
- Authority manifest class: `Workflow\V2\Support\PlatformConformanceSuite`
- Surface stability authority: `Workflow\V2\Support\SurfaceStabilityContract`
  re-exported by this server from `GET /api/cluster/info` under
  `surface_stability_contract`. The conformance suite manifest is
  re-exported from the same endpoint under `platform_conformance_suite`,
  carrying the target matrix, fixture catalog, pass / fail rules,
  harness contract, and release gate set verbatim from
  `Workflow\V2\Support\PlatformConformanceSuite`. Third-party harnesses
  that target this server can read the suite manifest live without
  vendoring the static mirror.
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Existing per-route contract docs: `docs/contracts/external-task-input.md`,
  `docs/contracts/external-task-result.md`, `docs/contracts/replay-verification.md`,
  `docs/contracts/external-execution-surface.md`,
  `docs/contracts/auth-composition.md`, `docs/contracts/bridge-adapters.md`.
