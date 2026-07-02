#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: workflow-updates-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes the workflow updates conformance runner-gap record.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  workflow-updates-result.json
  workflow-updates-record.json
  workflow-updates-findings.json

Environment overrides:
  DW_WORKFLOW_UPDATES_RESULT_DIR   Result directory when --result-dir is omitted.
  DW_SERVER_IMAGE                  Exact server image tag or digest under test.
  DW_SERVER_VERSION                Exact server version under test.
  DW_CLI_VERSION                   Exact CLI release version.
  DW_PYTHON_SDK_VERSION            Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION          Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION             Exact Waterline artifact version.
USAGE
}

result_dir="${DW_WORKFLOW_UPDATES_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      if [[ -z "$result_dir" ]]; then
        printf '%s\n' '--result-dir requires a value' >&2
        usage >&2
        exit 2
      fi
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-workflow-updates.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const startedAt = process.env.STARTED_AT;
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const finishedAt = generatedAt;

function env(name) {
  return (process.env[name] || '').trim();
}

function versionFromImage(image) {
  if (!image || image.includes('@sha256:')) {
    return '';
  }
  const match = image.match(/:([^/:]+)$/);
  return match ? match[1] : '';
}

function unresolved(value) {
  return value || 'unresolved';
}

const serverImage = env('DW_SERVER_IMAGE') || '';
const serverVersion = unresolved(env('DW_SERVER_VERSION') || versionFromImage(serverImage));
const cliVersion = unresolved(env('DW_CLI_VERSION'));
const pythonVersion = unresolved(env('DW_PYTHON_SDK_VERSION'));
const workflowPhpVersion = unresolved(env('DW_WORKFLOW_PHP_VERSION'));
const waterlineVersion = unresolved(env('DW_WATERLINE_VERSION'));

const artifactVersions = {
  server: serverVersion,
  cli: cliVersion,
  'sdk-python': pythonVersion,
  workflow: workflowPhpVersion,
  waterline: waterlineVersion,
};

const publishedArtifactVersions = {
  ...artifactVersions,
  'workflow-php': workflowPhpVersion,
};

const artifactSources = {
  server: serverImage || `docker://durableworkflow/server:${serverVersion}`,
  cli: `github-release://durable-workflow/cli/v${cliVersion}/install.sh`,
  'sdk-python': `pypi://durable-workflow==${pythonVersion}`,
  workflow: `packagist://durable-workflow/workflow@${workflowPhpVersion}`,
  'workflow-php': `packagist://durable-workflow/workflow@${workflowPhpVersion}`,
  waterline: `packagist://durable-workflow/waterline@${waterlineVersion}`,
};

const requiredScenarios = [
  'published_artifact_install_only',
  'declared_update_contract_visibility',
  'accepted_update_control_plane_and_history',
  'running_or_waiting_update_operator_visibility',
  'completed_update_result_round_trip',
  'failed_update_outcome',
  'duplicate_request_idempotency',
  'unknown_update_refusal',
  'invalid_input_refusal',
  'payload_envelope_round_trip',
  'terminal_workflow_update_behavior',
  'principal_attribution_with_auth',
  'php_client_worker_update_surface',
  'python_client_worker_update_surface',
  'operator_diagnostics_surfaces',
];

const runnerAcceptance =
  'Register and run a host workflow-updates conformance runner that installs published server, CLI, Python SDK, PHP workflow package, and Waterline artifacts; starts PHP and Python update-capable workflows; drives accepted, running or waiting, completed, failed, refused, duplicate or idempotent, terminal, payload round-trip, and authenticated-principal update cells; captures control-plane, history, and Waterline evidence; and records typed unsupported SDK cells instead of using local source checkouts.';

const finding = {
  finding_id: 'workflow-updates-host-runner-gap',
  finding_type: 'conformance_runner_blocked',
  classification: 'runner-gap',
  owning_surface: 'conformance_harness',
  summary: 'Workflow updates conformance has a public contract and handoff, but the current gate has no registered host runner for the workflow-updates experiment.',
  next_acceptance_criterion: runnerAcceptance,
};

const scenarioResults = {};
const updateCellOutcomes = {};

for (const scenarioId of requiredScenarios) {
  updateCellOutcomes[scenarioId] = 'runner_blocked';
  scenarioResults[scenarioId] = {
    scenario_id: scenarioId,
    status: 'runner_blocked',
    classification: 'runner-gap',
    published_artifact_cell_executed: false,
    local_product_source_checkouts_used: false,
    observed_outputs: {
      artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      published_artifact_cell_executed: false,
      local_product_source_checkouts_used: false,
      runner_blocked_reason: finding.summary,
    },
    linked_findings: [finding],
  };
}

const sourcePolicy = {
  pass_requires_published_artifacts_only: true,
  local_product_source_checkouts_used_must_be_false: true,
  local_product_source_checkouts_used: false,
  local_checkout_execution_counts_as_pass: false,
};

const result = {
  schema: 'durable-workflow.v2.workflow-update-runtime.result',
  result_version: 1,
  experiment: 'workflow-updates',
  runner: 'scripts/conformance/workflow-updates-published-artifacts.sh',
  generated_at: generatedAt,
  started_at: startedAt,
  finished_at: finishedAt,
  outcome: 'fail',
  runner_blocked: true,
  runner_blocked_reason: finding.summary,
  artifact_versions: artifactVersions,
  published_artifact_versions: publishedArtifactVersions,
  artifact_sources: artifactSources,
  source_policy: sourcePolicy,
  local_product_source_checkouts_used: false,
  scenario_results: scenarioResults,
  update_cell_outcomes: updateCellOutcomes,
  findings: [finding],
  finding_links: {
    'workflow-updates-host-runner-gap': {
      owning_surface: 'conformance_harness',
      next_acceptance_criterion: runnerAcceptance,
    },
  },
};

const pins = {
  schema: 'durable-workflow.v2.workflow-update-runtime.pins',
  generated_at: generatedAt,
  artifact_versions: artifactVersions,
  published_artifact_versions: publishedArtifactVersions,
  artifact_sources: artifactSources,
  local_product_source_checkouts_used: false,
};

const metadata = {
  schema: 'durable-workflow.v2.workflow-update-runtime.run-metadata',
  experiment: 'workflow-updates',
  started_at: startedAt,
  finished_at: finishedAt,
  outcome: 'fail',
  runner_blocked: true,
  result_file: 'workflow-updates-result.json',
  record_file: 'workflow-updates-record.json',
  findings_file: 'workflow-updates-findings.json',
};

const record = {
  experiment: 'workflow-updates',
  outcome: 'fail',
  runnerBlocked: true,
  artifactVersions,
  artifactSources,
  sourcePolicy,
  findings: [finding.summary],
  findingLinks: result.finding_links,
  notes: [
    runnerAcceptance,
    'No local product source checkout execution was used as pass evidence.',
  ],
  local_product_source_checkouts_used: false,
  result_file: 'workflow-updates-result.json',
  findings_file: 'workflow-updates-findings.json',
};

function writeJson(file, payload) {
  fs.writeFileSync(path.join(resultDir, file), `${JSON.stringify(payload, null, 2)}\n`);
}

writeJson('pins.json', pins);
writeJson('run-metadata.json', metadata);
writeJson('workflow-updates-result.json', result);
writeJson('workflow-updates-record.json', record);
writeJson('workflow-updates-findings.json', [finding]);

console.log(JSON.stringify({
  result_dir: resultDir,
  result: path.join(resultDir, 'workflow-updates-result.json'),
  record: path.join(resultDir, 'workflow-updates-record.json'),
  outcome: 'fail',
  runner_blocked: true,
}));
NODE
