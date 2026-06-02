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

The server publishes or serves source material for these conformance
categories and runtime contracts:

| Category | Source path | Status |
| --- | --- | --- |
| `worker_task_lifecycle` (server side) | `tests/Fixtures/` plus the per-route examples in `docs/contracts/external-task-input.md` and `docs/contracts/external-task-result.md` | stable |
| `signal_query_runtime_contract` (server side) | `GET /api/cluster/info`'s `signal_query_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/signal-query-runtime-scenarios.json`, `scripts/conformance/signals-queries-published-artifacts.sh`, plus the signal/query control-plane routes documented in the protocol catalog | stable |
| `search_attribute_runtime_contract` (server side) | `GET /api/cluster/info`'s `search_attribute_runtime_contract` manifest, the search-attribute control-plane routes, workflow start metadata, workflow-task upsert command, workflow list query parser, and operator visibility surfaces | stable |
| `schedules_runtime_contract` (server side) | `GET /api/cluster/info`'s `schedules_runtime_contract` manifest, the schedule control-plane routes, scheduler tick entrypoint, schedule history, CLI/SDK/PHP client surfaces, and cross-language dispatch behavior | stable |
| `child_workflow_runtime_contract` (server side) | `GET /api/cluster/info`'s `child_workflow_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/child-workflow-runtime-scenarios.json`, plus the child scheduling, completion, failure, cancellation, replay, fan-out, and namespace behavior recorded by the worker protocol and history surfaces | stable |
| `saga_runtime_contract` (server side) | `GET /api/cluster/info`'s `saga_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/saga-runtime-scenarios.json`, and `scripts/conformance/sagas-published-artifacts.sh`, which is the host-runner handoff for published-artifact saga compensation evidence | stable |
| `principal_attribution_contract` (server side) | `GET /api/cluster/info`'s `principal_attribution_contract` manifest, the public scenario manifest at `static/platform-conformance/principal-attribution-scenarios.json`, and `scripts/conformance/principal-attribution-published-artifacts.sh`, which is the host-runner handoff for source-free principal attribution evidence | stable |
| `python_sdk_published_artifact_parity` (host-runner handoff) | `GET /api/cluster/info`'s `python_sdk_parity_contract` manifest and `scripts/conformance/python-published-artifacts.sh`, which is the source-free host-runner handoff for the official CLI install/start/result path, cold first-user setup, Python worker restart evidence, protocol traces, no-PHP audit, and the complete Python capability table accepted by `durable_workflow.python_conformance` | stable |
| `skew_refusal_matrix_contract` (server side) | `GET /api/cluster/info`'s `skew_refusal_matrix_contract` manifest, the public scenario manifest at `static/platform-conformance/skew-refusal-matrix-scenarios.json`, and `scripts/conformance/skew-published-artifacts.sh`, which is the host-runner handoff for the CLI/Python/PHP worker/Waterline skew matrix, worker registration skew classifications, Waterline render classifications, and request/response evidence requirements | stable |
| `worker_versioning_runtime_contract` (server side) | `GET /api/cluster/info`'s `worker_versioning_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/worker-versioning-runtime-scenarios.json`, `scripts/conformance/worker-versioning-published-artifacts.sh`, worker registration/build-id rollout APIs, workflow start pinning, compatible polling, history/visibility pin surfaces, and CLI/Waterline operator visibility | stable |
| `migration_runtime_contract` (server side) | `GET /api/cluster/info`'s `migration_runtime_contract` manifest, the public scenario manifest at `static/platform-conformance/migration-runtime-scenarios.json`, and the host-runner handoff requirements for full published-artifact v1-to-v2 upgrade evidence | stable |
| `namespace_runtime_contract` (server side) | the public scenario manifest at `static/platform-conformance/namespace-runtime-scenarios.json`, `GET /api/cluster/info`'s `namespace_runtime_contract` manifest, `scripts/conformance/namespaces-published-artifacts.sh`, plus namespace, workflow, worker, schedule, search-attribute, Nexus, and operator routes documented in the protocol catalog | stable |
| `failure_repair_actionability` | `docs/contracts/external-task-result.md`, `docs/contracts/replay-verification.md`, plus the artifact objects published from `GET /api/cluster/info`'s `worker_protocol.external_task_result_contract.fixtures` | stable |

Several categories the server is graded against also span client,
runtime, observer, and documentation behavior in the `cli`, `sdk-python`,
`workflow`, and `durable-workflow.github.io` repositories. The harness
loads those companion fixtures alongside the server-owned manifests.

The server repo also ships a source-free signals/queries runner at
`scripts/conformance/signals-queries-published-artifacts.sh`. Host
conformance runners can discover that handoff from `GET /api/cluster/info`
under `signal_query_runtime_contract.host_runner_contract` and invoke it
against the current published server image, CLI release, Python SDK, PHP
workflow runtime, and Waterline package versions. The runner records the
Python/CLI smoke evidence only when an external smoke evidence file carries
the exact advertised fields for CLI signal/query, SDK signal/query, repeat
query consistency, and ordered ten-signal delivery. All unexecuted parity
cells remain non-passing `not_covered` scenario results with focused findings
for the ordered-delivery proof, dedup contract observation, PHP worker mirror,
cross-language client matrix, replay timing, completed-run handling,
unknown-handler errors, malformed-payload errors, and Waterline observer
comparison. Product behavior failures route to the owning surface through the
manifest's `finding_policy`; coverage gaps route to the conformance harness
instead of being counted as product passes.

The server repo also ships a source-free saga runner at
`scripts/conformance/sagas-published-artifacts.sh`. Host conformance
runners can discover that handoff from `GET /api/cluster/info` under
`saga_runtime_contract.host_runner_contract` and invoke it to exercise
`saga_runtime_contract` against the current published server image, CLI
release artifact, Python SDK, PHP workflow runtime, and Waterline package
install. Saga evidence records the PHP package under the runtime key
`workflow-php` and the platform release key `workflow` so both the runtime
contract and release coverage compare the same published artifact. The
server-only runner does not boot a Waterline app or probe
Waterline routes; Waterline operator visibility remains an explicit
unsupported surface until a host topology runs Waterline against the saga
run database. The script emits
`durable-workflow.v2.saga-runtime-conformance.result` evidence with every
required saga scenario reported as `pass`, `fail`, `unsupported`,
`not_covered`, or `runner_blocked`; a partial or runner-blocked run is
therefore non-passing instead of being recorded as green.

The server repo also ships a source-free principal-attribution runner at
`scripts/conformance/principal-attribution-published-artifacts.sh`. Host
conformance runners can discover that handoff from `GET /api/cluster/info`
under `principal_attribution_contract.host_runner_contract` and invoke it
against the current published server image plus public CLI, Python SDK, PHP
workflow, and Waterline packages. Principal-attribution evidence must record
the server-derived actor for workflow start, signal, query, cancellation,
completion, failure, anonymous, and server-originated history surfaces, plus
adversarial payload/header spoofing attempts. Any missing public surface is
non-passing unless it links a focused root-cause finding.

The server repo also ships a source-free namespace runner at
`scripts/conformance/namespaces-published-artifacts.sh`. Host conformance
runners can discover that handoff from `GET /api/cluster/info` under
`namespace_runtime_contract.host_runner_contract` and invoke it against the
current published server image, CLI release, Python SDK, PHP workflow runtime,
and Waterline package versions. The runner exercises namespace creation,
workflow read/mutation isolation, same-queue worker matching, search-attribute
schema and value isolation, schedule isolation, namespace deletion cleanup and
recreate, explicit Nexus crossing, CLI default-scope behavior, SDK namespace
selection, and adversarial namespace-name refusal. The published Workflow PHP
namespace shard is required for a passing result and must be attached through
`DW_NAMESPACES_WORKFLOW_PHP_RESULT`; the aggregated evidence records the shard
report as executed before the PHP worker task-queue cell can pass. A published
Waterline shard is run automatically from a disposable Laravel app unless
`DW_NAMESPACES_WATERLINE_RESULT` points at a pre-generated
`waterline:namespace-conformance` report for the same artifact tuple. When a
required or expected shard is absent or cannot be executed, the result remains
non-passing and carries a focused surface finding instead of silently treating
the cell as covered.

The server repo also ships a source-free Python SDK parity runner at
`scripts/conformance/python-published-artifacts.sh`. Host conformance
runners can discover that handoff from `GET /api/cluster/info` under
`python_sdk_parity_contract.host_runner_contract` and invoke it against the
current published server image, official CLI installer, PyPI
`durable-workflow` package, and matching workflow/Waterline artifacts. The
runner writes host evidence, composes it with the installed SDK's
`durable_workflow.python_conformance` contract, and evaluates the resulting
`durable-workflow.v2.python-sdk-parity.result` document. Smoke-only Python
worker evidence remains non-passing because the SDK result gate requires the
official CLI start/result path, cold first-user setup, control and worker
protocol traces, a no-PHP-assumption audit, and the complete capability
table before rollup can count the Python parity result as passing.

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
  missing-workflow and malformed-payload error diagnostics, Waterline
  comparison against server, CLI, and SDK observations, the public runtime
  scenario-manifest pointer, run-record fields, the coverage gate that
  keeps a smoke-only subset non-passing, and a result-gate evaluator that
  rejects incomplete, placeholder, or finding-free non-pass scenario
  records. A run record that reports
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
  codec round trips with encoded-payload or wire-value context,
  equality/range/bool and OR/NOT grammar, keyword-list membership,
  type-safety probes, undefined-key refusal, indexing latency distribution,
  load profile,
  namespace isolation, query-injection hardening, run-record fields, the
  coverage gate that keeps smoke-only search-attribute evidence non-passing,
  and a result-gate evaluator that rejects incomplete, placeholder, or
  finding-free non-pass scenario records.
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
- Saga runtime contract: `GET /api/cluster/info` re-exports
  `saga_runtime_contract`, schema
  `durable-workflow.v2.saga-runtime.contract`. It names the required
  published-artifact install policy, the source-free host runner script,
  PHP/Python workflow and activity matrices, reverse compensation order,
  early-failure, retry idempotence, compensation failure visibility,
  worker-restart replay, cross-language compensation, typed compensation
  error, and operator visibility evidence. A result with
  `runner_blocked=true`, an incomplete release asset handoff, a smoke-only
  subset, a missing Waterline visibility finding, or any non-pass scenario
  without a root-cause finding is non-passing and cannot be counted as
  saga product evidence.
- Skew-refusal matrix contract: `GET /api/cluster/info` re-exports
  `skew_refusal_matrix_contract`, schema
  `durable-workflow.v2.skew-refusal-matrix.contract`. It names the
  published-artifact install policy, required CLI, Python SDK, PHP worker,
  and Waterline surfaces, compatible/backward-skew/forward-skew/outside-window
  pairing classes, workflow, worker, schedule, cluster-info, and Waterline
  operation groups, and the wire evidence required for every skewed operation.
  Worker skew is classified as `register_refused`, `register_and_serve`, or
  `register_and_drop`; `register_and_drop` is blocking. Waterline skew is
  classified as `banner`, `render_refused`, or `stale_render`; `stale_render`
  is blocking. Its result gate rejects cluster-info smoke as passing evidence
  and requires current published artifact versions, every required surface,
  every pairing class, every operation group, request/response evidence, and
  focused linked findings for any non-pass cell before rollup can count the
  result. Its `host_runner_contract` names
  `scripts/conformance/skew-published-artifacts.sh` as the executable handoff
  and lists the CLI, Python SDK, PHP worker, Waterline, future-version
  boundary, and request/response evidence shards a runner must execute against
  published artifacts; uncovered cells must be recorded as `not_covered` with
  `conformance_runner_coverage_gap` findings owned by the conformance harness,
  while `register_and_drop` and `stale_render` route as blocking product gaps.
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
  the owning public surface. Its `host_runner_contract` names
  `scripts/conformance/worker-versioning-published-artifacts.sh` as the
  executable handoff and requires the runner to record v1 and v2 workflow-task
  delivery counts for the same v1-pinned run, including the cache-eviction or
  worker-restart replay cell, the no-compatible-worker diagnostic cell after
  stopping the compatible cohort, and both PHP/Python cross-language pinning
  directions; any incompatible delivery count above zero is blocking product
  evidence.
- Migration runtime contract: `GET /api/cluster/info` re-exports
  `migration_runtime_contract`, schema
  `durable-workflow.v2.migration-runtime.contract`. It names the
  published-artifact install policy for the latest supported v1 source
  release set and current v2 target release set, the public guide steps,
  completed-history replay, in-flight progress, mid-activity retry state,
  schedule cadence, worker registration projection, CLI and Waterline
  visibility, new v2 starts, rollback behavior, and version-skew refusal.
  Its result gate records the storage-connection smoke as useful context
  but keeps that smoke-only result non-passing until every required upgrade
  scenario has evidence or a linked root-cause finding, and until every
  required install channel records its published artifact source. Missing or
  placeholder v1/v2 install prerequisites are recorded as scenario `fail`
  results with `missing_or_invalid_published_migration_artifact` findings
  routed to the owning artifact surface instead of being collapsed into a
  generic uncovered-cell result. Its
  `host_runner_contract` names
  `scripts/conformance/migration-published-artifacts.sh` as the executable
  handoff; the runner writes `migration-conformance-result.json` and
  `migration-conformance-record.json`, accepts host-supplied full-migration
  evidence through `DW_MIGRATION_EVIDENCE_JSON`, and records unexecuted
  required cells as `not_covered` with `conformance_runner_coverage_gap`
  findings instead of treating storage-connection smoke as passing evidence.
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
- Migration runtime scenarios:
  <https://durable-workflow.github.io/platform-conformance/migration-runtime-scenarios.json>
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
