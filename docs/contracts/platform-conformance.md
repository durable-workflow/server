# Platform Conformance — Server Claim

The standalone `durable-workflow/server` participates in the platform
conformance suite specified in
the public
[Platform Conformance Suite](https://durable-workflow.github.io/docs/2.0/platform-conformance)
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

The fixture catalog in the suite manifest names server-owned surfaces
as source material for eight categories:

| Category | Source path | Status |
| --- | --- | --- |
| `worker_task_lifecycle` (server side) | `tests/Fixtures/` plus the per-route examples in `docs/contracts/external-task-input.md` and `docs/contracts/external-task-result.md` | stable |
| `signal_query_runtime_contract` (server side) | `GET /api/cluster/info`'s `signal_query_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/signal-query-runtime-scenarios.json`, plus the signal/query control-plane routes documented in the protocol catalog | stable |
| `search_attribute_runtime_contract` (server side) | `GET /api/cluster/info`'s `search_attribute_runtime_contract` manifest, the search-attribute control-plane routes, workflow start metadata, workflow-task upsert command, workflow list query parser, and operator visibility surfaces | stable |
| `schedules_runtime_contract` (server side) | `GET /api/cluster/info`'s `schedules_runtime_contract` manifest, the schedule control-plane routes, scheduler tick entrypoint, schedule history, CLI/SDK/PHP client surfaces, and cross-language dispatch behavior | stable |
| `child_workflow_runtime_contract` (server side) | `GET /api/cluster/info`'s `child_workflow_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/child-workflow-runtime-scenarios.json`, plus the child scheduling, completion, failure, cancellation, replay, fan-out, and namespace behavior recorded by the worker protocol and history surfaces | stable |
| `worker_versioning_runtime_contract` (server side) | `GET /api/cluster/info`'s `worker_versioning_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/worker-versioning-runtime-scenarios.json`, worker registration/build-id rollout APIs, workflow start pinning, compatible polling, history/visibility pin surfaces, and CLI/Waterline operator visibility | stable |
| `namespace_runtime_contract` (server side) | the public scenario manifest at `static/platform-conformance/namespace-runtime-scenarios.json`, plus namespace, workflow, worker, schedule, search-attribute, Nexus, and operator routes documented in the protocol catalog | stable |
| `failure_repair_actionability` | `docs/contracts/external-task-result.md`, `docs/contracts/replay-verification.md`, plus the artifact objects published from `GET /api/cluster/info`'s `worker_protocol.external_task_result_contract.fixtures` | stable |

The other categories the server is graded against
(`control_plane_request_response`, `signal_query_runtime_contract`,
`search_attribute_runtime_contract`, `namespace_runtime_contract`,
`child_workflow_runtime_contract`, `saga_runtime_contract`,
`worker_versioning_runtime_contract`, and `history_replay_bundles`) live
in the `cli`, `sdk-python`, `workflow`, and `durable-workflow.github.io`
repositories and are loaded by the harness from there.

The server repo also ships a source-free saga runner at
`scripts/conformance/sagas-published-artifacts.sh`. Host conformance
runners can invoke it to exercise `saga_runtime_contract` against the
current published server image, CLI release artifact, Python SDK, PHP
workflow runtime, and Waterline package install. The server-only runner
does not boot a Waterline app or probe Waterline routes; Waterline
operator visibility remains an explicit unsupported surface until a host
topology runs Waterline against the saga run database. The script emits
`durable-workflow.v2.saga-runtime-conformance.result` evidence with every
required saga scenario reported as `pass`, `fail`, `unsupported`,
`not_covered`, or `runner_blocked`; a partial run is therefore
non-passing instead of being recorded as green.

## Release gate

A release of `durable-workflow/server` must produce a passing harness
result document before tag, with the conformance level at `full` or
`provisional` (provisional categories enumerated in release notes).

| Field | Value |
| --- | --- |
| Required claimed targets | `standalone_server`, `worker_protocol_implementation`, `repair_actionability_surface` |
| Required suite version | The build's `PlatformConformanceSuite::VERSION` — the harness must run against the suite version exposed by the build under test |
| CI job | `platform-conformance` (lands when the harness reference implementation publishes; until then the server release reviewer manually verifies parity against the existing fixture-driven tests under `tests/Feature` and `tests/Unit/EnvContractTest.php`) |
| Block on `nonconforming` | yes |
| Artifact attached to release | harness result document, schema `durable-workflow.v2.platform-conformance.result` |

A `nonconforming` result blocks the release. A failure in a provisional
category emits a warning and does not block.

## Cross-references

- Authority spec: <https://durable-workflow.github.io/docs/2.0/platform-conformance>
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
- Signals/queries runtime contract: `GET /api/cluster/info` re-exports
  `signal_query_runtime_contract`, schema
  `durable-workflow.v2.signal-query-runtime.contract`. It names the
  required published-artifact install policy with concrete pinned
  artifact versions, PHP/Python runtime matrix, CLI and SDK client
  paths, replay timing scenarios, terminal-run behavior,
  malformed-payload expectations, Waterline observer comparison, the
  public runtime scenario-manifest pointer, run-record fields, the
  coverage gate that keeps a smoke-only subset non-passing, and a
  result-gate evaluator that rejects incomplete, placeholder, or
  finding-free non-pass scenario records. A run record that reports
  placeholder or unresolved artifact versions such as `latest`,
  `current`, `head`, `unresolved`, `placeholder`, `<latest>`,
  `${VERSION}`, or `{{ version }}` is non-passing even when those tokens
  are embedded in image, Composer, or PyPI install strings and every
  scenario result is green.
- Search-attributes runtime contract: `GET /api/cluster/info` re-exports
  `search_attribute_runtime_contract`, schema
  `durable-workflow.v2.search-attribute-runtime.contract`. It names the
  required published-artifact install policy, PHP/Python worker matrix,
  CLI query and error surface, Waterline operator visibility, cross-language
  codec round trips, equality/range/bool and OR/NOT grammar, keyword-list
  membership, type-safety probes, undefined-key refusal, indexing latency
  distribution, load profile, namespace isolation, query-injection
  hardening, run-record fields, the coverage gate that keeps smoke-only
  search-attribute evidence non-passing, and a result-gate evaluator that
  rejects incomplete, placeholder, or finding-free non-pass scenario records.
- Schedules runtime contract: `GET /api/cluster/info` re-exports
  `schedules_runtime_contract`, schema
  `durable-workflow.v2.schedules-runtime.contract`. It names the required
  published-artifact install policy, cron and fixed-rate cadence evidence,
  list/describe/pause/resume/delete controls, the documented missed-fire
  policy, restart survival, CLI/Python/PHP client surfaces, cross-language
  schedule-to-workflow cells, invalid cron refusal, and non-existent workflow
  type behavior. The result gate keeps schedule CRUD smoke non-passing until
  every required scenario has concrete evidence or a linked root-cause finding.
  The published host-runner contract splits the run into cadence, controls,
  missed-fire/restart, public-client, cross-language, and adversarial shards;
  any shard the trusted runner has not executed is recorded as `not_covered`
  with a conformance-harness finding instead of being treated as passing
  schedule evidence.
- Child-workflow runtime contract: `GET /api/cluster/info` re-exports
  `child_workflow_runtime_contract`, schema
  `durable-workflow.v2.child-workflow-runtime.contract`. It names the
  required published-artifact install policy, PHP/Python parent-child
  matrix, typed child failure propagation, parent and direct child
  cancellation evidence, replay across parent-worker restart, N=5
  fan-out concurrency evidence, namespace behavior, published artifact
  versions including Waterline, run timestamps and outcome, the coverage
  gate that keeps a Python-only smoke subset non-passing, and a
  result-gate evaluator that rejects incomplete, placeholder, or
  finding-free non-pass scenario records. A child-workflow result whose
  scenario matrix is green but whose declared outcome is non-passing
  remains non-passing; every declared outcome alias (`outcome`, `status`,
  `verdict`) and the evaluated gate status must agree before rollup can
  count the evidence as passing.
- Worker-versioning runtime contract: `GET /api/cluster/info` re-exports
  `worker_versioning_runtime_contract`, schema
  `durable-workflow.v2.worker-versioning-runtime.contract`. It names the
  required published-artifact install policy, PHP/Python worker matrix,
  CLI/Python/PHP/Waterline operator surfaces, pin-on-start evidence,
  compatible replay after cache eviction or restart, new-start promotion,
  explicit no-compatible-worker behavior, cross-language PHP/Python
  pinning, adversarial no-version-bump capture, history API pin evidence,
  and a result-gate evaluator that rejects smoke-only rollout evidence or
  uncovered required scenarios as non-passing unless linked findings name
  the owning public surface.
- Namespace runtime contract: the public suite's
  `namespace_runtime_contract` category is the load-bearing namespace
  parity gate. It requires published-artifact evidence for namespace
  lifecycle cleanup and recreate, cross-namespace workflow visibility
  and mutation isolation, PHP worker task-queue isolation, CLI and SDK
  namespace selection, schedule isolation, Waterline/operator scoped
  visibility, explicit Nexus crossing, reserved-name refusal, and
  search-attribute schema and value query isolation. A namespace smoke
  that omits those cells is nonconforming.
- Public docs page: <https://durable-workflow.github.io/docs/2.0/compatibility>
- Normative protocol spec catalog:
  <https://durable-workflow.github.io/docs/2.0/platform-protocol-specs>.
  The catalog links the server-owned OpenAPI documents for the control-plane
  API and worker protocol, the JSON Schema for `cluster_info`, and the MCP
  discovery/result schemas. It also names the object families each server
  surface governs and the schema/version authority for those families.
  Server route docs are explanatory; the catalog is the machine-readable
  authority for SDKs and validation tooling.
- Existing per-route contract docs: `docs/contracts/external-task-input.md`,
  `docs/contracts/external-task-result.md`, `docs/contracts/replay-verification.md`,
  `docs/contracts/external-execution-surface.md`,
  `docs/contracts/auth-composition.md`, `docs/contracts/bridge-adapters.md`.
