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
- `version: 1`
- `bundle` — the export envelope schema and where it comes from.
- `offline_cli` — the Artisan command, its inputs, and its exit codes.
- `integrity` — canonicalization, checksum, and signature primitives.
- `integrity_report` — the report schema, severities, and rule names.
- `replay_diff` — the diff schema, statuses, and reasons.
- `verdicts` — the four overall verdicts and the promotion decision each
  implies.
- `golden_history` — the cross-runtime fixture schema and required
  workflow families.

## Bundle envelope

Closed runs export to JSON via:

```text
GET /api/namespaces/{namespace}/workflows/{workflowId}/runs/{runId}/history/export
```

Or, from a runtime shell, via the bundled Artisan command:

```text
php artisan workflow:v2:history-export <workflowId|runId> [--run] [--output PATH]
```

The bundle carries `schema: durable-workflow.v2.history-export` and an
`integrity` block with the canonical checksum and (when configured) an
HMAC-SHA256 signature.

## Offline CLI

Operators and CI runners verify a bundle without touching the control
plane via:

```text
php artisan workflow:v2:replay-verify <bundle.json> \
    [--signing-key=<KEY>] [--skip-replay] [--strict-warnings] [--json] [--output=<PATH>]
```

The command emits a single JSON report (when `--json` or `--output` is
set) shaped by `replay_verification_contract`. The command's exit code is
the gate signal:

- `0` — `ok` or `warning` (without `--strict-warnings`).
- `1` — `warning` with `--strict-warnings`, `drifted`, or `failed`.

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
