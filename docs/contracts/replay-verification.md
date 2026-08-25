# Replay Verification Contract

Replay is the trust contract of durable execution: every history a workflow
emits must be replayable by the runtime and SDK versions a deploy promotes
to. The replay verification contract names the surfaces operators and CI
runners depend on to make that contract first-class — independent of any
single SDK or runtime — so promotion and rollout decisions can be tied to a
deterministic, machine-checkable verdict.

The authoritative machine-readable contract is published from
`GET /api/cluster/info` at `replay_verification_contract`:

- `schema: durable-workflow.v2.replay-verification.contract`
- `version: 4`
- `bundle` — the export envelope schema and where it comes from.
- `offline_cli` — the Artisan command, its inputs, and its exit codes.
- `integrity` — canonicalization, checksum, and signature primitives.
- `integrity_report` — the report schema, severities, and rule names.
- `replay_diff` — the diff schema, statuses, reasons, and the
  `shape_mismatch` fields verifiers expose for drift diagnostics.
- `verification_report` — the composite report schema the offline CLI
  emits, wrapping the integrity report and replay diff under a single
  `verdict` and `promotion_decision`.
- `simulation_report` — the aggregated batch report shape emitted by the
  agent/CI-friendly batch CLI; reduces a directory of bundles to one
  overall verdict and `promotion_decision`.
- `batch_cli` — the Artisan command that consumes a directory of bundles
  and emits the simulation report, plus its inputs and exit codes.
- `promotion_gate` — the server-side helper that translates either
  report into a `pass` / `review` / `block` gate decision.
- `verdicts` — the four overall verdicts and the promotion decision each
  implies.
- `golden_history` — the cross-runtime fixture schema and required
  workflow families.
- `replay_conformance` — the full conformance coverage matrix,
  public runtime-scenario manifest pointer, published-artifact policy,
  required scenario IDs, required runtime axes, diagnostic requirements,
  and pass gate for deterministic replay.

## Bundle envelope

Closed runs export to JSON via:

```text
GET /api/namespaces/{namespace}/workflows/{workflowId}/runs/{runId}/history/export
```

Or, from a runtime shell, via the bundled Artisan command:

```text
php artisan workflow:v2:history-export <workflowId|runId> [--run] [--output PATH]
```

The version 2 bundle carries `schema: durable-workflow.v2.history-export` and an
`integrity` block with the canonical checksum and (when configured) an
HMAC-SHA256 signature.

## Offline CLI

Operators and CI runners verify a bundle without touching the control
plane via:

```text
php artisan workflow:v2:replay-verify <bundle.json> \
    [--signing-key=<KEY>] [--skip-replay] [--strict-warnings] [--json] [--output=<PATH>]
```

The command emits a single JSON document shaped by
`replay_verification_contract.verification_report`
(`schema: durable-workflow.v2.replay-verification.report`). It carries:

- `verdict` — one of `ok`, `warning`, `drifted`, `failed`.
- `promotion_decision` — the promotion gate's recommendation (see below).
- `integrity` — the
  `durable-workflow.v2.history-bundle-verification` report (rules,
  severities, integrity status).
- `replay_diff` — the
  `durable-workflow.v2.replay-diff` report (replay status, reason,
  drift fields).
- `evidence` — booleans and counters that say which verification
  checks actually ran (`integrity_checked`, `replay_checked`,
  `replay_skipped`, and `strict_warnings`).

The command's exit code is the gate signal:

- `0` — `ok` or `warning` (without `--strict-warnings`).
- `1` — `warning` with `--strict-warnings`, `drifted`, or `failed`.

## Batch / agent-friendly CLI

CI pipelines and rollout controllers usually have more than one bundle to
gate on at a time. The batch command runs the same per-bundle checks as
`workflow:v2:replay-verify` across every `*.json` bundle in a directory
and emits one aggregated promotion verdict:

```text
php artisan workflow:v2:replay-simulate <bundle-dir> \
    [--signing-key=<KEY>] [--skip-replay] [--strict-warnings] [--json] [--output=<PATH>]
```

The aggregated report carries
`schema: durable-workflow.v2.replay-simulation.report` and the same
`verdict` + `promotion_decision` vocabulary as the single-bundle report.
Each per-bundle entry under `bundles[]` keeps its own verdict and
promotion decision so a CI runner can render which bundle held up the
gate. The top-level `evidence` block carries `bundle_count`,
`missing_bundle_count`, `integrity_checked_count`, and
`replay_checked_count`, letting an agent distinguish "all sampled
bundles were checked" from "no evidence was supplied." The aggregation
rule is **strictest verdict wins**: any per-bundle
`failed` pins the overall to `failed`; otherwise any `drifted` pins to
`drifted`; otherwise any `warning` pins to `warning`; otherwise `ok`. An
empty directory aggregates to `failed` because a gate with no evidence
is never safe to promote.

Exit code: `0` when the overall verdict is `ok` or `warning`; `1` for
`drifted` or `failed`. Sampling-based rollout controllers should batch
recent histories into one directory and gate on this single command's
exit code.

## Promotion gate (server side)

Server-side controllers consume either report through the helper
`App\Support\ReplayPromotionGate`. The helper returns a normalized
`gate_status` (`pass` / `review` / `block`) so a deployment lifecycle or
rollout endpoint can act on a verify or simulation report without
re-implementing the verdict-to-decision table:

| verdict   | promotion decision        | gate status |
|-----------|---------------------------|-------------|
| `ok`      | `safe_to_promote`         | `pass`      |
| `warning` | `review_before_promote`   | `review`    |
| `drifted` | `block_until_compatible`  | `block`     |
| `failed`  | `block_and_investigate`   | `block`     |

`ReplayPromotionGate::aggregate()` reduces a list of reports with the
same strictest-verdict-wins rule as the batch CLI, so any caller that
sees multiple reports can collapse them to one decision deterministically.
Known v1 verify and simulation reports must include their `evidence`
block. A clean verdict with missing evidence blocks promotion; a report
that intentionally used `--skip-replay` is reduced to
`review_before_promote`; and a simulation report that did not replay
every bundle without declaring a skip blocks promotion as incomplete
evidence.

## Verdicts and promotion

| verdict   | meaning                                                                                  | promotion decision         |
|-----------|------------------------------------------------------------------------------------------|----------------------------|
| `ok`      | Bundle integrity holds and current code replays the recorded history without drift.     | safe to promote            |
| `warning` | Structural advisories that do not block replay; review before broad rollout.            | review before promote      |
| `drifted` | Current code yields a different workflow step shape than the recorded history.          | block until compatible     |
| `failed`  | Bundle integrity does not hold or replay raised an unexpected error.                    | block and investigate      |

The verdict feeds promotion and staged-rollout gates: a `drifted` or
`failed` verdict on any sampled bundle should hold the rollout until the
replay diff is resolved.

## Integrity surface

The bundle uses canonicalization `json-recursive-ksort-v1` and
SHA-256 for the checksum. When `workflows.v2.history_export.signing_key`
is configured, bundles also carry an HMAC-SHA256 signature with
`key_id` set to `workflows.v2.history_export.signing_key_id`. The
verifier accepts the same key via `--signing-key` or via configuration.

## Replay-diff diagnostics

Replay diffs identify the workflow sequence at which new code diverges
from the recorded history. A `shape_mismatch` reason names:

- `workflow_sequence` — the workflow step where the divergence happened.
- `expected_shape` — the workflow step shape the current code yielded.
- `recorded_event_types` — the history event types stored at that
  sequence in the bundle.

These three fields are the operator-facing handle for "which step changed
between the build that emitted this history and the build under test."

## Cross-runtime golden histories

The `golden_history` block names the fixture schema
(`durable-workflow.golden-history.v1`) and the workflow families every
official runtime must replay consistently. The fixtures live alongside
each runtime (`tests/Fixtures/V2/GoldenHistory` for `workflow-php`,
`tests/fixtures/golden_history/` for `sdk-python`) and are consumed by
each runtime's replay test suite. New official runtimes must extend the
fixture set with their own emitter version and replay every required
family.

The `sdk-python` runtime ships an equivalent batch surface as
`python -m durable_workflow.replay_verify --simulate-bundles <dir>`,
which emits the same `durable-workflow.v2.replay-simulation.report`
shape as the PHP `workflow:v2:replay-simulate` command. A multi-runtime
rollout pipeline can therefore parse one schema regardless of which
runtime produced the report.

## Replay conformance matrix

The `replay_conformance` block names the minimum surface a conformance
run must cover before deterministic replay can count as passing. The run
uses only published install channels resolved at run time: the server
Docker image, the official `dw` install script, the Composer
`durable-workflow/workflow` package, and the PyPI `durable-workflow`
package. Local product source checkouts are forbidden as artifacts under
test.

Every run record must carry artifact versions, start and finish
timestamps, an overall outcome, scenario results, findings, and finding
links. Artifact versions cover the current published tuple: server, CLI,
PHP workflow runtime, Python SDK, and Waterline. They must be concrete
published versions pinned at run time; placeholder or unresolved tokens
such as `latest`, `current`, `head`, `unresolved`, `placeholder`,
`<latest>`, `${VERSION}`, or `{{ version }}` keep the run non-passing.
The required runtime axis is `workflow-php` and `sdk-python`; a passing
run covers both.

Runtime-specific evidence shards are merged by the conformance harness
before applying the full `durable-workflow.v2.replay-conformance.result`
gate. A standalone runtime shard is evidence but not a full passing run
by itself, and harnesses must probe runtime-published surfaces from the
resolved package under test instead of assuming a command exists in every
published Composer artifact.
The host runner records the install and probe evidence for server, CLI,
Python SDK, the exact Packagist PHP SDK, the embedded PHP workflow engine,
and Waterline in
`published-artifact-install.json`; the install-only scenario cannot pass
from runtime shard metadata alone.

The `host_runner_contract` block is the machine-readable harness contract
for that merge step. A replay host runner must emit one
`scenario_results` entry for every required scenario. The PHP runtime
shard comes from `scripts/conformance/php-sdk-published-artifacts.sh` in the
resolved public server image and installs only `durable-workflow/sdk` in its
disposable Composer project; if that published runtime surface is missing,
the affected scenarios are `unsupported` with a linked root-cause finding.
The Python shard covers the replay verifier plus live
worker restart query replay. A smoke summary without the PHP shard,
adversarial refusal scenarios, and in-flight signal timing remains
`non_passing`, even when the smoke path itself succeeds.

The `result_gate` block is the server-published evaluator contract for
`durable-workflow.v2.replay-conformance.result` documents. It rejects
smoke-only evidence, omitted required scenarios, missing PHP or Python
runtime cells, missing replay evidence for passing scenarios, missing
linked findings for non-passing scenarios, and adversarial refusal cases
that do not include actionable diagnostics. It also rejects unresolved
artifact version placeholders even when every scenario result is green.
A full-matrix result may declare `fail` or another non-passing coverage
outcome when it links concrete product findings for every non-passing
cell; that is complete product evidence, not malformed metadata. A
result whose scenario matrix is green must declare a passing outcome, and
a result with missing or non-passing cells must not declare `pass`; the
run verdict and the gate verdict must agree.

The machine-readable `required_scenarios` list mirrors the public
scenario manifest at
`https://durable-workflow.github.io/platform-conformance/replay-runtime-scenarios.json`.
Harnesses must include a result for every listed scenario using one of
the statuses published in `replay_conformance.scenario_statuses`:
`pass`, `fail`, `unsupported`, `not_covered`, or `runner_blocked`.
`pass` and `fail` describe product behavior that was actually exercised,
`unsupported` marks a missing public surface, `not_covered` marks a
required scenario the run did not execute, and `runner_blocked` marks a
harness or environment blocker. Omitted scenarios are treated as
`not_covered` for gate evaluation and keep the replay conformance
outcome non-passing.

For each required runtime, completed-history replay must cover these
families:

- `activity`
- `signal-update`
- `wait-condition`
- `version-marker`
- `saga-compensation`

Worker-restart replay must cover completed-history query replay plus
activity, signal/update, wait-condition, version-marker, and
saga-compensation state after worker restart. The live timing surface
must also cover `in_flight_signal_restart_timing`, where a signal
received around worker restart and history reload leads to the same next
decision as the original execution. Passing evidence must report the
required outcome `same_next_decision_after_replay` with timestamps for
signal send, worker restart, history reload, and the replayed next
decision.

Adversarial replay must cover:

- `code_divergence_refusal` — changed workflow code refuses with a
  non-determinism error that names the diverging workflow sequence,
  expected shape, recorded event types, and message. Passing evidence
  must report the required outcome `non_determinism_error`.
- `server_history_mutation_refusal` — mutated server history is refused
  as an invalid or drifted bundle with integrity and replay-diff
  diagnostics. Passing evidence must report the required outcome
  `bundle_invalid_or_drifted`.
- `malformed_history_refusal` — malformed history is refused as invalid
  or failed with an integrity rule, path, and message. Passing evidence
  must report the required outcome `bundle_invalid_or_failed`.

A conformance run may report scenario status as `pass`, `fail`,
`unsupported`, `not_covered`, or `runner_blocked`. The coverage gate can
report a passing outcome only when every required runtime is present,
every required matrix cell passes, every refusal is actionable, the
artifact versions are concrete pins for the latest published set, and no
local product source checkout is used. Any uncovered required scenario
remains non-passing so a smoke subset cannot appear green as full
deterministic replay coverage.

Findings are routed by root cause. Nondeterminism belongs to the
owning runtime or SDK; silent history-mutation acceptance belongs to the
server; unclear refusal messages belong to the emitting surface; runtime
asymmetry belongs to the asymmetric runtime; and unsupported public
surfaces belong to the surface owner.
