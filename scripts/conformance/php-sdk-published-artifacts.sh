#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: php-sdk-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--scope lifecycle|namespace|search-attributes] [--validate-definitions]

Runs the released durable-workflow/sdk package against an already-running,
exact public server image. The runner creates a disposable Composer project,
starts independent PHP worker and client processes, and never mounts or loads
a product source checkout.

Required environment:
  DW_PHP_SDK_VERSION                  Exact Packagist durable-workflow/sdk version.
  DW_SERVER_VERSION                   Exact public server image version.
  DW_SERVER_IMAGE                     Exact durableworkflow/server image tag or digest.
  DW_PHP_SDK_CONFORMANCE_SERVER_URL   Reachable public server endpoint.

Optional environment:
  DW_PHP_SDK_CONFORMANCE_RESULT_DIR   Result directory when --result-dir is omitted.
  DW_PHP_SDK_CONFORMANCE_NAMESPACE    Defaults to workflow-lifecycle-conformance.
  DW_PHP_SDK_CONFORMANCE_TOKEN        Defaults to dev-token.
  DW_PHP_SDK_CONFORMANCE_CONTROL_TOKEN Optional control-plane token; defaults to TOKEN.
  DW_PHP_SDK_CONFORMANCE_WORKER_TOKEN Optional worker-plane token; defaults to TOKEN.
  DW_PHP_SDK_CONFORMANCE_PHP_BIN      PHP binary override.
  DW_PHP_SDK_CONFORMANCE_COMPOSER_BIN Composer binary override.
  DW_PHP_SDK_CONFORMANCE_SCOPE        lifecycle (default), namespace, or search-attributes.
  DW_PHP_SDK_SEARCH_ATTRIBUTES_PYTHON_FIXTURE_JSON
                                        Optional Python-writer fixture for the PHP reader codec cell.
  DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX=1 Exercise the full deterministic replay matrix.
  DW_PHP_SDK_CONFORMANCE_WORKER_RUN_DELAY_MS Delay managed registration for readiness regression probes.

Validation mode:
  --validate-definitions                Install the exact requested SDK, validate every generated
                                        handler definition, and exercise the zero-argument
                                        workflow/activity rejection contract without a server.
USAGE
}

result_dir="${DW_PHP_SDK_CONFORMANCE_RESULT_DIR:-}"
scope="${DW_PHP_SDK_CONFORMANCE_SCOPE:-lifecycle}"
validate_definitions=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      shift
      ;;
    --scope)
      scope="${2:?--scope requires a value}"
      shift 2
      ;;
    --scope=*)
      scope="${1#--scope=}"
      shift
      ;;
    --validate-definitions)
      validate_definitions=true
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

if [[ "$scope" != lifecycle && "$scope" != namespace && "$scope" != search-attributes ]]; then
  printf 'unsupported PHP SDK conformance scope: %s\n' "$scope" >&2
  usage >&2
  exit 2
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$script_dir/php-sdk-runtime-failure-evidence.sh"

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-php-sdk-conformance.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

sdk_version="${DW_PHP_SDK_VERSION:-}"
server_version="${DW_SERVER_VERSION:-}"
server_image="${DW_SERVER_IMAGE:-}"
server_url="${DW_PHP_SDK_CONFORMANCE_SERVER_URL:-${DW_WORKFLOW_LIFECYCLE_SERVER_URL:-}}"
namespace="${DW_PHP_SDK_CONFORMANCE_NAMESPACE:-workflow-lifecycle-conformance}"
token="${DW_PHP_SDK_CONFORMANCE_TOKEN:-${DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN:-dev-token}}"
control_token="${DW_PHP_SDK_CONFORMANCE_CONTROL_TOKEN:-$token}"
worker_token="${DW_PHP_SDK_CONFORMANCE_WORKER_TOKEN:-$token}"
php_bin="${DW_PHP_SDK_CONFORMANCE_PHP_BIN:-${PHP_BIN:-php}}"
composer_bin="${DW_PHP_SDK_CONFORMANCE_COMPOSER_BIN:-${COMPOSER_BIN:-composer}}"
project_dir="$result_dir/php-sdk-project"
result_file="$result_dir/php-sdk-conformance-result.json"
sidecar_file="$result_dir/php-sdk-lifecycle-evidence.json"
distribution_identity_file="$result_dir/executed-distribution-identities.json"
started_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
worker_pid=""
worker_start_outcome=""
worker_start_worker_id=""
worker_start_attempts=""
worker_start_process_id=""
worker_start_process_alive=""
worker_start_process_exit_code=""
worker_start_observation_file=""
failure_companion_file=""
replay_matrix_enabled="${DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX:-0}"

write_failure() {
  local classification="${1:?classification is required}"
  local owning_surface="${2:?owning surface is required}"
  local stage="${3:?stage is required}"
  local summary="${4:?summary is required}"
  local diagnostic_file="${5:-}"

  RESULT_DIR="$result_dir" \
  SDK_VERSION="$sdk_version" \
  SERVER_VERSION="$server_version" \
  SERVER_IMAGE="$server_image" \
  SERVER_URL="$server_url" \
  NAMESPACE="$namespace" \
  CONFORMANCE_SCOPE="$scope" \
  STARTED_AT="$started_at" \
  FAILURE_CLASSIFICATION="$classification" \
  FAILURE_OWNER="$owning_surface" \
  FAILURE_STAGE="$stage" \
  FAILURE_SUMMARY="$summary" \
  FAILURE_DIAGNOSTIC_FILE="$diagnostic_file" \
  FAILURE_COMPANION_FILE="$failure_companion_file" \
  FAILURE_EVIDENCE_HELPER="$script_dir/php-sdk-runtime-failure-evidence.cjs" \
  DISTRIBUTION_IDENTITY_FILE="$distribution_identity_file" \
  WORKER_START_OUTCOME="$worker_start_outcome" \
  WORKER_START_WORKER_ID="$worker_start_worker_id" \
  WORKER_START_ATTEMPTS="$worker_start_attempts" \
  WORKER_START_PROCESS_ID="$worker_start_process_id" \
  WORKER_START_PROCESS_ALIVE="$worker_start_process_alive" \
  WORKER_START_PROCESS_EXIT_CODE="$worker_start_process_exit_code" \
  WORKER_START_OBSERVATION_FILE="$worker_start_observation_file" \
  CONTROL_TOKEN="$control_token" \
  WORKER_TOKEN="$worker_token" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const {
  assertCompleteHttpFailureEvidence,
  boundedEvidence,
  diagnosticExcerpt,
  extractReadinessHttpFailureEvidence,
  extractRuntimeFailureEvidence,
  failureSummary,
  serializedBytes,
} = require(process.env.FAILURE_EVIDENCE_HELPER);

const resultDir = process.env.RESULT_DIR;
const version = process.env.SDK_VERSION || '';
const scope = process.env.CONFORMANCE_SCOPE || 'lifecycle';
const failureScenarioId = {
  lifecycle: 'php_sdk_lifecycle_surface',
  namespace: 'php_worker_task_queue_namespace_isolation',
  'search-attributes': 'php_worker_start_and_upsert_visibility',
}[scope] || 'php_sdk_lifecycle_surface';
const failureScenarioMaxBytes = 24576;
const failureEvidenceComponentMaxBytes = 3072;
const fallbackSummary = process.env.FAILURE_SUMMARY || 'PHP SDK conformance failed.';
const requestedClassification = process.env.FAILURE_CLASSIFICATION || 'sdk';
let classification = requestedClassification;
let owningSurface = process.env.FAILURE_OWNER || 'sdk-php';
const diagnosticFile = process.env.FAILURE_DIAGNOSTIC_FILE || '';
const readJson = (file) => {
  try {
    const value = JSON.parse(fs.readFileSync(file, 'utf8'));
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
  } catch {
    return null;
  }
};
const secrets = [process.env.CONTROL_TOKEN, process.env.WORKER_TOKEN];
const companion = boundedEvidence(readJson(process.env.FAILURE_COMPANION_FILE || ''), secrets, 6144);
const workerStartOutcome = process.env.WORKER_START_OUTCOME || '';
const workerProcessAlive = process.env.WORKER_START_PROCESS_ALIVE === 'true';
const processExitCode = process.env.WORKER_START_PROCESS_EXIT_CODE === ''
  ? null
  : Number(process.env.WORKER_START_PROCESS_EXIT_CODE);
const workerExitedDuringStartup = workerStartOutcome === 'process_exit' && !workerProcessAlive;
const workerStartObservation = readJson(process.env.WORKER_START_OBSERVATION_FILE || '');
const boundedWorkerStartObservation = boundedEvidence(workerStartObservation, secrets);
let diagnostic = null;
let runtimeFailure = null;
if (diagnosticFile && fs.existsSync(diagnosticFile)) {
  const excerpt = fs.readFileSync(diagnosticFile, 'utf8');
  diagnostic = diagnosticExcerpt(excerpt, secrets);
  runtimeFailure = extractRuntimeFailureEvidence(excerpt, {
    secrets,
  });
  if (runtimeFailure) {
    runtimeFailure.failure_stage = process.env.FAILURE_STAGE || 'unknown';
    classification = runtimeFailure.classification;
    owningSurface = runtimeFailure.owning_surface;
  }
}
const liveReadinessProbeFailed = workerStartOutcome === 'readiness_probe_failure'
  && workerProcessAlive;
if (!runtimeFailure && requestedClassification === 'server' && liveReadinessProbeFailed) {
  runtimeFailure = extractReadinessHttpFailureEvidence(workerStartObservation, {secrets});
  if (runtimeFailure) {
    runtimeFailure.failure_stage = process.env.FAILURE_STAGE || 'unknown';
    classification = runtimeFailure.classification;
    owningSurface = runtimeFailure.owning_surface;
  }
}
if (workerExitedDuringStartup && !runtimeFailure) {
  classification = 'sdk';
  owningSurface = 'sdk-php';
}
assertCompleteHttpFailureEvidence(runtimeFailure, classification);
if (companion) {
  classification = companion.classification || classification;
  owningSurface = companion.owning_surface || owningSurface;
}
const runnerBlocked = classification === 'runner';
const renderedProcessExitCode = Number.isInteger(processExitCode) ? ` with code ${processExitCode}` : '';
const processExitSummary = [
  `The released PHP SDK worker process exited${renderedProcessExitCode}`,
  `during ${process.env.FAILURE_STAGE || 'worker startup'};`,
  'the bounded crash diagnostic is retained in structured evidence.',
].join(' ');
const companionState = companion?.worker?.process_state?.state || 'unknown';
const companionBasis = companion?.classification_basis || '';
const companionSummary = companion
  ? `The released PHP SDK client failed during ${process.env.FAILURE_STAGE || 'unknown'}; companion worker state=${companionState} and retained server/run evidence assign ownership to ${owningSurface} (${companionBasis}).`
  : '';
const summary = companionSummary || failureSummary(
  runtimeFailure,
  process.env.FAILURE_STAGE || 'unknown',
  workerExitedDuringStartup ? processExitSummary : fallbackSummary,
);
const finding = {
  finding_id: `php-sdk-${process.env.FAILURE_STAGE || 'unknown'}-failure`,
  scenario_id: failureScenarioId,
  finding_type: runnerBlocked
    ? 'conformance_runner_blocked'
    : (classification === 'package-publication' ? 'package_publication_gap' : 'product_behavior_gap'),
  classification,
  owning_surface: owningSurface,
  failure_stage: process.env.FAILURE_STAGE,
  summary,
  observed_behavior: summary,
  next_acceptance_criterion: 'Correct the named failure surface and rerun the exact Packagist SDK against the exact public server image.',
};
if (runtimeFailure) {
  finding.owning_surface = runtimeFailure.owning_surface;
  finding.observed_evidence = runtimeFailure;
}
if (companion) {
  finding.owning_surface = owningSurface;
  finding.observed_evidence = {
    ...(finding.observed_evidence || {}),
    companion_failure_evidence: companion,
  };
}
if (diagnostic) {
  finding.diagnostic = diagnostic;
}
const observed = {
  sdk: 'sdk-php',
  artifact_version: version,
  server_version: process.env.SERVER_VERSION || '',
  artifact_source: version ? `packagist://durable-workflow/sdk@${version}` : 'packagist://durable-workflow/sdk@unresolved',
  composer_package: 'durable-workflow/sdk',
  server_image: process.env.SERVER_IMAGE || '',
  server_url: process.env.SERVER_URL || '',
  namespace: process.env.NAMESPACE || '',
  published_artifact_cell_executed: process.env.FAILURE_STAGE !== 'preflight',
  local_product_source_checkouts_used: false,
  failure_stage: process.env.FAILURE_STAGE,
  failure_classification: classification,
  failure_owner: owningSurface,
  failure_summary: summary,
};
if (runtimeFailure) {
  observed.runtime_failure_evidence = runtimeFailure;
}
if (diagnostic) {
  observed.failure_diagnostic = diagnostic;
}
if (companion) {
  observed.failure_kind = companion.failure_kind;
  observed.operation = companion.operation;
  observed.process_state = companion.worker?.process_state || null;
  observed.companion_failure_evidence = companion;
}
if (workerStartOutcome) {
  const observation = boundedWorkerStartObservation;
  observed.worker_startup = {
    outcome: workerStartOutcome,
    worker_id: process.env.WORKER_START_WORKER_ID || null,
    attempts: Number(process.env.WORKER_START_ATTEMPTS || 0),
    process_id: Number(process.env.WORKER_START_PROCESS_ID || 0) || null,
    process_alive_at_failure: workerProcessAlive,
    process_exit_code: Number.isInteger(processExitCode) ? processExitCode : null,
    last_server_observation: observation?.last_server_observation ?? null,
    readiness_observation: observation,
  };
  finding.worker_startup_evidence = observed.worker_startup;
}
const startedContractEvidence = readJson(path.join(resultDir, 'php-sdk-addressable-start-contract.json'));
const startedHistoryEvidence = readJson(path.join(resultDir, 'php-sdk-addressable-start-history.json'));
if (startedContractEvidence) {
  observed.workflow_started_command_contract = startedContractEvidence;
} else if (startedHistoryEvidence) {
  observed.workflow_started_command_contract = {
    command_contract_source: 'durable_history',
    history_reads: 1,
    validation_status: 'rejected_incomplete_snapshot',
    history_response: startedHistoryEvidence,
  };
}
const namespaceEvidence = readJson(path.join(resultDir, 'php-sdk-namespace-evidence.json'));
const namespaceWorker = readJson(path.join(resultDir, 'php-sdk-worker-php-sdk-worker-1.json'));
if (namespaceEvidence) {
  const lifecycle = namespaceEvidence.namespace_lifecycle || {};
  const simple = namespaceEvidence.simple_workflow || {};
  const identity = namespaceEvidence.identity || {};
  observed.namespace_evidence = lifecycle;
  observed.client_processes = [identity];
  observed.worker_processes = namespaceWorker ? [namespaceWorker] : [];
  observed.scenario_assertions = {
    namespace_lifecycle: lifecycle.created === true
      && lifecycle.described === true
      && lifecycle.updated === true
      && lifecycle.listed === true
      && lifecycle.deleted === true,
    namespace_selection: Boolean(lifecycle.selected_namespace)
      && lifecycle.selected_namespace === lifecycle.created_namespace
      && lifecycle.selected_namespace_workflow_count === 0,
    worker_namespace_registration: Boolean(namespaceWorker)
      && namespaceWorker.namespace === process.env.NAMESPACE
      && namespaceWorker.server_visible_registration
      && typeof namespaceWorker.server_visible_registration === 'object'
      && namespaceWorker.readiness?.client_release_after_authoritative_registration === true,
    namespace_worker_execution: simple.namespace === process.env.NAMESPACE
      && simple.status === 'completed'
      && Object.prototype.hasOwnProperty.call(simple, 'result'),
    distinct_client_worker_processes: Boolean(namespaceWorker)
      && identity.process_id !== namespaceWorker.process_id,
  };
}
const result = {
  schema: 'durable-workflow.v2.php-sdk-published-artifact-conformance',
  version: 1,
  generated_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  started_at: process.env.STARTED_AT,
  finished_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  outcome: runnerBlocked ? 'runner_blocked' : 'fail',
  runner_blocked: runnerBlocked,
  artifact_versions: {'sdk-php': version, server: process.env.SERVER_VERSION || ''},
  executed_distribution_identities: readJson(process.env.DISTRIBUTION_IDENTITY_FILE || '') || {},
  artifact_sources: {
    'sdk-php': observed.artifact_source,
    server: observed.server_image ? `docker://${observed.server_image.replace(/^docker:\/\//, '')}` : '',
  },
  local_product_source_checkouts_used: false,
  process_boundary: {client_worker_distinct_processes: false},
  worker_startup: observed.worker_startup || null,
  workflow_started_command_contract: observed.workflow_started_command_contract || null,
  scenario_results: {},
  findings: [finding],
};
const partialScopeEvidence = scope === 'namespace'
  ? readJson(path.join(resultDir, 'php-sdk-namespace-evidence.json'))
  : (scope === 'search-attributes'
    ? readJson(path.join(resultDir, 'php-sdk-search-attributes-evidence.json'))
    : null);
const workerProcessState = companion?.worker?.process_state || (workerStartOutcome
  ? {
    state: workerProcessAlive ? 'alive' : (workerExitedDuringStartup ? 'exited' : 'unknown'),
    alive: workerProcessAlive,
    exit_code: Number.isInteger(processExitCode) ? processExitCode : null,
  }
  : {state: 'not_started', alive: null, exit_code: null});
const failureScenarioObserved = {
  published_artifact_cell_executed: observed.published_artifact_cell_executed,
  failure_stage: process.env.FAILURE_STAGE,
  failure_classification: classification,
  failure_owner: owningSurface,
  failure_summary: summary,
  artifact_evidence: {
    sdk: observed.sdk,
    sdk_version: version,
    sdk_source: observed.artifact_source,
    server_version: observed.server_version,
    server_image: observed.server_image,
    server_url: observed.server_url,
    namespace: observed.namespace,
    local_product_source_checkouts_used: false,
  },
  worker_evidence: {
    process_state: workerProcessState,
    startup: boundedEvidence(observed.worker_startup, secrets, failureEvidenceComponentMaxBytes),
    companion: boundedEvidence(companion?.worker, secrets, failureEvidenceComponentMaxBytes),
  },
  server_evidence: {
    version: observed.server_version,
    image: observed.server_image,
    url: observed.server_url,
    namespace: observed.namespace,
    runtime_failure: boundedEvidence(runtimeFailure, secrets, failureEvidenceComponentMaxBytes),
    companion: boundedEvidence(companion?.server, secrets, failureEvidenceComponentMaxBytes),
  },
  partial_scope_evidence: boundedEvidence(
    partialScopeEvidence,
    secrets,
    failureEvidenceComponentMaxBytes,
  ),
  evidence_bounds: {
    scenario_max_bytes: failureScenarioMaxBytes,
    component_max_bytes: failureEvidenceComponentMaxBytes,
    retained_diagnostic_excerpt_max_bytes: 4096,
    companion_diagnostic_max_bytes: 6144,
    public_error_envelope_max_bytes: 2048,
  },
};
const scenarioFinding = {
  finding_id: finding.finding_id,
  scenario_id: finding.scenario_id,
  finding_type: finding.finding_type,
  classification: finding.classification,
  owning_surface: finding.owning_surface,
  failure_stage: finding.failure_stage,
  summary: finding.summary,
};
const failureScenario = {
  scenario_id: failureScenarioId,
  status: runnerBlocked ? 'runner_blocked' : 'fail',
  observed_outputs: failureScenarioObserved,
  linked_findings: [scenarioFinding],
};
if (serializedBytes(failureScenario) > failureScenarioMaxBytes) {
  throw new Error(`PHP SDK failure scenario exceeds ${failureScenarioMaxBytes} bytes.`);
}
result.scenario_results[failureScenarioId] = failureScenario;
result.evidence_bounds = failureScenarioObserved.evidence_bounds;
if (process.env.CONFORMANCE_SCOPE === 'search-attributes') {
  const searchEvidence = boundedEvidence(
    partialScopeEvidence,
    secrets,
    failureEvidenceComponentMaxBytes,
  ) || {};
  const searchShard = {
    schema: 'durable-workflow.v2.search-attribute-runtime.sdk-php-shard',
    version: 1,
    generated_at: result.generated_at,
    status: failureScenario.status,
    runner_blocked: runnerBlocked,
    artifact_versions: result.artifact_versions,
    artifact_sources: result.artifact_sources,
    package_ownership: {
      standalone_connectivity: 'durable-workflow/sdk',
      embedded_engine: 'durable-workflow/workflow',
      workflow_standalone_client_or_worker_loaded: false,
    },
    observed_outputs: {
      published_artifact_cell_executed: observed.published_artifact_cell_executed,
      failure_stage: process.env.FAILURE_STAGE,
      failure_classification: classification,
      failure_owner: owningSurface,
      worker_startup: observed.worker_startup || null,
      partial_evidence: searchEvidence,
    },
    scenario_results: {
      [failureScenarioId]: {
        scenario_id: failureScenarioId,
        status: failureScenario.status,
        observed_outputs: {
          published_artifact_cell_executed: observed.published_artifact_cell_executed,
          failure_stage: process.env.FAILURE_STAGE,
          failure_classification: classification,
          failure_owner: owningSurface,
          partial_evidence: searchEvidence,
        },
        linked_findings: [finding],
      },
    },
    findings: [finding],
    linked_findings: [finding],
    evidence_bounds: failureScenarioObserved.evidence_bounds,
  };
  fs.writeFileSync(
    path.join(resultDir, 'sdk-php-search-attributes-shard.json'),
    `${JSON.stringify(searchShard, null, 2)}\n`,
  );
}
const replayFailureScenario = {
  replay_matrix_completed_history: 'php_completed_history_activity_replay',
  replay_matrix_in_flight_start: 'php_in_flight_signal_restart_timing',
  replay_matrix_worker_restart: 'php_worker_restart_completed_query',
}[process.env.FAILURE_STAGE || ''];
if (process.env.DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX === '1' && replayFailureScenario && !runnerBlocked) {
  const cellId = `php-${String(process.env.FAILURE_STAGE).replaceAll('_', '-')}`;
  result.replay_matrix = {
    enabled: true,
    executed: true,
    failed_cell: cellId,
  };
  result.replay_scenario_results = {
    [replayFailureScenario]: {
      scenario_id: replayFailureScenario,
      status: 'fail',
      executed_runtime_cell: true,
      runtime_cell: {
        cell_id: cellId,
        executed: true,
        artifact_source: 'packagist',
        server_source: 'public_image',
      },
      observed_outputs: {
        runtime_cell_executed: true,
        cell_id: cellId,
        failure_stage: process.env.FAILURE_STAGE,
        failure_classification: classification,
        failure_owner: owningSurface,
        failure_summary: summary,
        runtime_failure_evidence: runtimeFailure,
      },
      linked_findings: [finding],
    },
  };
}
const sidecar = {
  schema: 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
  generated_at: result.generated_at,
  runner: 'published-php-sdk-process-boundary-conformance',
  runner_blocked: runnerBlocked,
  scenario_results: {
    php_sdk_lifecycle_surface: {
      scenario_id: 'php_sdk_lifecycle_surface',
      status: result.outcome,
      classification,
      published_artifact_cell_executed: observed.published_artifact_cell_executed,
      observed_outputs: observed,
      linked_findings: [finding],
    },
  },
  evidence_bounds: failureScenarioObserved.evidence_bounds,
};
fs.writeFileSync(path.join(resultDir, 'php-sdk-conformance-result.json'), `${JSON.stringify(result, null, 2)}\n`);
fs.writeFileSync(path.join(resultDir, 'php-sdk-lifecycle-evidence.json'), `${JSON.stringify(sidecar, null, 2)}\n`);
NODE
}

write_namespace_result() {
  RESULT_DIR="$result_dir" \
  SDK_VERSION="$sdk_version" \
  SERVER_VERSION="$server_version" \
  SERVER_IMAGE="$server_image" \
  SERVER_URL="$server_url" \
  NAMESPACE="$namespace" \
  STARTED_AT="$started_at" \
  DISTRIBUTION_IDENTITY_FILE="$distribution_identity_file" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const readJson = (name) => JSON.parse(fs.readFileSync(path.join(resultDir, name), 'utf8'));
const evidence = readJson('php-sdk-namespace-evidence.json');
const worker = readJson('php-sdk-worker-php-sdk-worker-1.json');
const lock = readJson('php-sdk-project/composer.lock');
const packages = [...(lock.packages || []), ...(lock['packages-dev'] || [])];
const sdk = packages.find((item) => item && item.name === 'durable-workflow/sdk') || {};
const normalizeVersion = (value) => String(value || '').replace(/^v/, '');
const cluster = evidence.cluster_info || {};
const clusterVersion = cluster.version || cluster.server_version || '';
const lifecycle = evidence.namespace_lifecycle || {};
const simple = evidence.simple_workflow || {};
const assertions = {
  exact_sdk_version: normalizeVersion(sdk.version) === normalizeVersion(process.env.SDK_VERSION),
  exact_server_version: Boolean(clusterVersion)
    && normalizeVersion(clusterVersion) === normalizeVersion(process.env.SERVER_VERSION),
  sdk_dist_provenance: Boolean(sdk.dist && sdk.dist.type && sdk.dist.url && sdk.dist.type !== 'path'),
  distinct_client_worker_processes: evidence.identity?.process_id !== worker.process_id,
  namespace_lifecycle: lifecycle.created === true
    && lifecycle.described === true
    && lifecycle.updated === true
    && lifecycle.listed === true
    && lifecycle.deleted === true,
  namespace_selection: Boolean(lifecycle.selected_namespace)
    && lifecycle.selected_namespace === lifecycle.created_namespace
    && lifecycle.selected_namespace_workflow_count === 0,
  worker_namespace_registration: worker.namespace === process.env.NAMESPACE
    && worker.scope === 'namespace'
    && worker.server_visible_registration
    && typeof worker.server_visible_registration === 'object'
    && worker.readiness?.client_release_after_authoritative_registration === true,
  namespace_worker_execution: simple.namespace === process.env.NAMESPACE
    && simple.status === 'completed'
    && Object.prototype.hasOwnProperty.call(simple, 'result'),
  local_product_source_checkouts_used_false: true,
};
const domains = {
  exact_sdk_version: 'package-publication',
  exact_server_version: 'server',
  sdk_dist_provenance: 'package-publication',
  distinct_client_worker_processes: 'runner',
  namespace_lifecycle: 'server',
  namespace_selection: 'sdk',
  worker_namespace_registration: 'sdk',
  namespace_worker_execution: 'server',
  local_product_source_checkouts_used_false: 'runner',
};
const failures = {};
for (const [assertion, passed] of Object.entries(assertions)) {
  if (!passed) {
    const domain = domains[assertion] || 'sdk';
    (failures[domain] ||= []).push(assertion);
  }
}
const policies = {
  sdk: {owner: 'sdk-php', type: 'product_behavior_gap'},
  server: {owner: 'server', type: 'product_behavior_gap'},
  'package-publication': {owner: 'sdk-php-release', type: 'package_publication_gap'},
  runner: {owner: 'conformance_harness', type: 'conformance_runner_blocked'},
};
const runnerBlocked = Object.keys(failures).length === 1 && Boolean(failures.runner);
const status = Object.keys(failures).length === 0 ? 'pass' : (runnerBlocked ? 'runner_blocked' : 'fail');
const findings = Object.entries(failures).map(([domain, failedAssertions]) => {
  const policy = policies[domain];
  const observed = `The focused PHP namespace probe failed ${domain} assertions: ${failedAssertions.join(', ')}.`;
  return {
    finding_id: `php-sdk-namespace-${domain.replaceAll('_', '-')}-failure`,
    finding_type: policy.type,
    classification: domain,
    owning_surface: policy.owner,
    failure_stage: 'namespace_assertions',
    failed_assertions: failedAssertions,
    summary: observed,
    observed_behavior: observed,
    next_acceptance_criterion: 'Correct the named namespace failure and rerun the exact Packagist SDK against the exact public server image.',
  };
});
const observed = {
  sdk: 'sdk-php',
  coverage_scope: 'sdk-php-namespace-shard',
  artifact_version: sdk.version || null,
  server_version: process.env.SERVER_VERSION || '',
  server_image: process.env.SERVER_IMAGE || '',
  server_cluster_info: cluster,
  artifact_source: `packagist://durable-workflow/sdk@${process.env.SDK_VERSION}`,
  composer_package: 'durable-workflow/sdk',
  client_processes: [evidence.identity || {}],
  worker_processes: [worker],
  worker_identities: [worker.worker_id || null],
  namespace_evidence: lifecycle,
  namespace_worker_execution: simple,
  scenario_assertions: assertions,
  failure_domains: failures,
  published_artifact_cell_executed: true,
  client_worker_distinct_processes: assertions.distinct_client_worker_processes,
  local_product_source_checkouts_used: false,
};
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const result = {
  schema: 'durable-workflow.v2.php-sdk-published-artifact-conformance',
  version: 1,
  coverage_scope: 'sdk-php-namespace-shard',
  generated_at: generatedAt,
  started_at: process.env.STARTED_AT,
  finished_at: generatedAt,
  outcome: status,
  runner_blocked: runnerBlocked,
  artifact_versions: {'sdk-php': process.env.SDK_VERSION || '', server: process.env.SERVER_VERSION || ''},
  executed_distribution_identities: readJson('executed-distribution-identities.json'),
  artifact_sources: {
    'sdk-php': observed.artifact_source,
    server: `docker://${String(process.env.SERVER_IMAGE || '').replace(/^docker:\/\//, '')}`,
  },
  namespace: process.env.NAMESPACE || '',
  process_boundary: {
    client_worker_distinct_processes: assertions.distinct_client_worker_processes,
    client_processes: observed.client_processes,
    worker_processes: observed.worker_processes,
  },
  scenario_results: {
    namespace_create_update_describe_and_list: {status},
    sdk_namespace_selection_parity: {status},
    php_worker_task_queue_namespace_isolation: {status},
  },
  assertions,
  local_product_source_checkouts_used: false,
  failure_domains: failures,
  findings,
};
const sidecar = {
  schema: 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
  generated_at: generatedAt,
  runner: 'published-php-sdk-namespace-conformance',
  runner_blocked: runnerBlocked,
  scenario_results: {
    php_sdk_lifecycle_surface: {
      scenario_id: 'php_sdk_lifecycle_surface',
      status,
      classification: status === 'pass' ? 'passed' : Object.keys(failures).join('+'),
      published_artifact_cell_executed: true,
      observed_outputs: observed,
      linked_findings: findings,
    },
  },
};
fs.writeFileSync(path.join(resultDir, 'php-sdk-conformance-result.json'), `${JSON.stringify(result, null, 2)}\n`);
fs.writeFileSync(path.join(resultDir, 'php-sdk-lifecycle-evidence.json'), `${JSON.stringify(sidecar, null, 2)}\n`);
NODE
}

write_search_attribute_result() {
  RESULT_DIR="$result_dir" \
  SDK_VERSION="$sdk_version" \
  SERVER_VERSION="$server_version" \
  SERVER_IMAGE="$server_image" \
  NAMESPACE="$namespace" \
  STARTED_AT="$started_at" \
  node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const resultDir = process.env.RESULT_DIR;
const readJson = (name) => JSON.parse(fs.readFileSync(path.join(resultDir, name), 'utf8'));
const evidence = readJson('php-sdk-search-attributes-evidence.json');
const worker = readJson('php-sdk-worker-php-sdk-worker-1.json');
const lock = readJson('php-sdk-project/composer.lock');
const packages = [...(lock.packages || []), ...(lock['packages-dev'] || [])];
const sdk = packages.find((item) => item && item.name === 'durable-workflow/sdk') || {};
const normalizeVersion = (value) => String(value || '').replace(/^v/, '');
const expectedTypes = {
  customer_id: 'string',
  order_total_cents: 'int',
  discount_ratio: 'double',
  priority_tier: 'keyword',
  is_vip: 'bool',
  created_at: 'datetime',
  tags: 'keyword_list',
};
const normalizeValue = (name, value) => {
  if (name === 'created_at' && typeof value === 'string') {
    const parsed = Date.parse(value);
    return Number.isNaN(parsed) ? value : new Date(parsed).toISOString();
  }
  return value;
};
const mapsMatch = (expected, actual) => Object.entries(expected || {}).every(
  ([name, value]) => Object.hasOwn(actual || {}, name)
    && JSON.stringify(normalizeValue(name, value)) === JSON.stringify(normalizeValue(name, actual[name])),
);
const definitionTypesMatch = Object.entries(expectedTypes).every(
  ([name, type]) => evidence.schema_definitions?.[name] === type,
);
const actualAttributes = evidence.actual_search_attributes || {};
const expectedAttributes = evidence.expected_search_attributes || {};
const queryVisibility = evidence.query_visibility || {};
const namespaceIsolation = evidence.namespace_isolation || {};
const cluster = evidence.cluster_info || {};
const clusterVersion = cluster.version || cluster.server_version || '';
const workflowPackageAbsent = !packages.some((item) => item && item.name === 'durable-workflow/workflow');
const assertions = {
  exact_sdk_version: normalizeVersion(sdk.version) === normalizeVersion(process.env.SDK_VERSION),
  exact_server_version: Boolean(clusterVersion)
    && normalizeVersion(clusterVersion) === normalizeVersion(process.env.SERVER_VERSION),
  sdk_dist_provenance: Boolean(sdk.dist?.type && sdk.dist?.url && sdk.dist.type !== 'path'),
  standalone_workflow_package_absent: workflowPackageAbsent,
  distinct_client_worker_processes: evidence.identity?.process_id !== worker.process_id,
  worker_runtime_is_sdk_php: evidence.worker_runtime === 'sdk-php',
  worker_started_and_upserted_typed_values: mapsMatch(expectedAttributes, actualAttributes)
    && mapsMatch(evidence.upserted_search_attributes || {}, evidence.workflow_result?.upserted_search_attributes || {}),
  schema_definitions_are_typed: definitionTypesMatch,
  query_visibility: evidence.visibility_query_match === true
    && queryVisibility.attribute_source === 'upserted_search_attributes'
    && queryVisibility.attribute_name === 'priority_tier'
    && queryVisibility.attribute_value === evidence.upserted_search_attributes?.priority_tier,
  namespace_isolation: namespaceIsolation.cross_namespace_leak_detected === false
    && namespaceIsolation.attribute_source === 'start_search_attributes'
    && namespaceIsolation.attribute_name === 'customer_id'
    && namespaceIsolation.attribute_value === evidence.start_search_attributes?.customer_id
    && namespaceIsolation.peer_execution_required === false
    && namespaceIsolation.primary_visibility_match === true
    && namespaceIsolation.peer_visibility_match === true
    && Number(namespaceIsolation.primary_query_count) >= 1
    && Number(namespaceIsolation.peer_query_count) >= 1,
  local_product_source_checkouts_used_false: true,
};
const assertionOwners = {
  exact_sdk_version: ['sdk-php-release', 'package_publication_gap'],
  exact_server_version: ['server', 'product_behavior_gap'],
  sdk_dist_provenance: ['sdk-php-release', 'package_publication_gap'],
  standalone_workflow_package_absent: ['sdk-php-release', 'package_boundary_gap'],
  distinct_client_worker_processes: ['conformance_harness', 'conformance_runner_gap'],
  worker_runtime_is_sdk_php: ['sdk-php', 'product_behavior_gap'],
  worker_started_and_upserted_typed_values: ['sdk-php', 'product_behavior_gap'],
  schema_definitions_are_typed: ['server', 'product_behavior_gap'],
  query_visibility: ['server', 'product_behavior_gap'],
  namespace_isolation: ['server', 'product_behavior_gap'],
  local_product_source_checkouts_used_false: ['conformance_harness', 'conformance_runner_gap'],
};
const failedAssertions = Object.keys(assertions).filter((name) => assertions[name] !== true);
const findings = failedAssertions.map((name) => {
  const [owner, findingType] = assertionOwners[name];
  return {
    finding_id: `php-sdk-search-attributes-${name.replaceAll('_', '-')}`,
    scenario_id: 'php_worker_start_and_upsert_visibility',
    finding_type: findingType,
    classification: owner === 'conformance_harness' ? 'runner' : 'product',
    owning_surface: owner,
    failure_stage: 'search_attribute_assertions',
    failed_assertions: [name],
    observed_behavior: `Published PHP SDK search-attribute assertion failed: ${name}.`,
    next_acceptance_criterion: 'Correct the named public surface and rerun the exact Packagist SDK against the exact public server image.',
  };
});
const onlyRunnerFailures = findings.length > 0
  && findings.every((finding) => finding.owning_surface === 'conformance_harness');
const runtimeStatus = failedAssertions.length === 0 ? 'pass' : (onlyRunnerFailures ? 'runner_blocked' : 'fail');

const codecRoundTrips = evidence.codec_round_trips || {};
const pythonToPhp = codecRoundTrips.python_to_php;
const pythonToPhpStatus = pythonToPhp?.status === 'pass' ? 'pass' : 'not_covered';
const pythonToPhpFindings = pythonToPhpStatus === 'pass' ? [] : [{
  finding_id: 'php-sdk-search-attributes-python-to-php-observer-required',
  scenario_id: 'python_to_php_codec_round_trip',
  finding_type: 'conformance_runner_coverage_gap',
  classification: 'runner',
  owning_surface: 'conformance_harness',
  observed_behavior: 'The PHP SDK cell did not receive a published Python-writer fixture with CLI verification.',
  next_acceptance_criterion: 'Provide the Python-writer fixture to the PHP SDK search-attributes scope and retain both PHP SDK and CLI reader evidence.',
}];
const phpToPythonFindings = [{
  finding_id: 'php-sdk-search-attributes-php-to-python-observer-required',
  scenario_id: 'php_to_python_codec_round_trip',
  finding_type: 'conformance_runner_coverage_gap',
  classification: 'runner',
  owning_surface: 'conformance_harness',
  observed_behavior: 'The PHP SDK writer fixture is ready for the published Python SDK observer.',
  next_acceptance_criterion: 'Run the published Python SDK observer against the emitted namespace and workflow identity, then attach its decoded typed values.',
}];
const scenarioResults = {
  php_worker_start_and_upsert_visibility: {
    scenario_id: 'php_worker_start_and_upsert_visibility',
    status: runtimeStatus,
    observed_outputs: {
      workflow_id: evidence.workflow_id,
      run_id: evidence.run_id,
      worker_runtime: evidence.worker_runtime,
      start_search_attributes: evidence.start_search_attributes,
      upserted_search_attributes: evidence.upserted_search_attributes,
      expected_search_attributes: expectedAttributes,
      actual_search_attributes: actualAttributes,
      typed_values: evidence.typed_values,
      visibility_query_match: evidence.visibility_query_match,
      query_visibility: evidence.query_visibility,
      namespace_isolation: evidence.namespace_isolation,
      worker_process: worker,
      package_ownership: evidence.package_ownership,
    },
    linked_findings: findings,
  },
  python_to_php_codec_round_trip: {
    scenario_id: 'python_to_php_codec_round_trip',
    status: pythonToPhpStatus,
    observed_outputs: pythonToPhp ? {python_to_php: pythonToPhp} : {},
    linked_findings: pythonToPhpFindings,
  },
  php_to_python_codec_round_trip: {
    scenario_id: 'php_to_python_codec_round_trip',
    status: 'not_covered',
    observed_outputs: {php_to_python: codecRoundTrips.php_to_python_writer || {}},
    linked_findings: phpToPythonFindings,
  },
};
const generatedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const artifactVersions = {'sdk-php': process.env.SDK_VERSION || '', server: process.env.SERVER_VERSION || ''};
const artifactSources = {
  'sdk-php': `packagist://durable-workflow/sdk@${process.env.SDK_VERSION || ''}`,
  server: `docker://${String(process.env.SERVER_IMAGE || '').replace(/^docker:\/\//, '')}`,
};
const shard = {
  schema: 'durable-workflow.v2.search-attribute-runtime.sdk-php-shard',
  version: 1,
  coverage_scope: 'sdk-php-search-attribute-shard',
  generated_at: generatedAt,
  started_at: process.env.STARTED_AT,
  finished_at: generatedAt,
  status: runtimeStatus,
  runner_blocked: runtimeStatus === 'runner_blocked',
  artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  package_provenance: {
    package: 'durable-workflow/sdk',
    version: sdk.version || null,
    source: 'packagist',
    dist: sdk.dist || null,
  },
  package_ownership: evidence.package_ownership,
  process_boundary: {
    client_worker_distinct_processes: assertions.distinct_client_worker_processes,
    client_processes: [evidence.identity || {}],
    worker_processes: [worker],
  },
  assertions,
  observed_outputs: scenarioResults.php_worker_start_and_upsert_visibility.observed_outputs,
  codec_round_trips: {
    python_to_php: pythonToPhp || null,
    php_to_python: codecRoundTrips.php_to_python_writer
      ? {status: 'not_covered', ...codecRoundTrips.php_to_python_writer}
      : null,
  },
  scenario_results: scenarioResults,
  evidence_bounds: {
    matched_workflow_ids_max_items: 20,
    retained_diagnostic_excerpt_max_bytes: 4096,
  },
  local_product_source_checkouts_used: false,
  findings: [...findings, ...pythonToPhpFindings, ...phpToPythonFindings],
  linked_findings: findings,
};
const result = {
  schema: 'durable-workflow.v2.php-sdk-published-artifact-conformance',
  version: 1,
  coverage_scope: 'sdk-php-search-attribute-shard',
  generated_at: generatedAt,
  started_at: process.env.STARTED_AT,
  finished_at: generatedAt,
  outcome: runtimeStatus,
  runner_blocked: runtimeStatus === 'runner_blocked',
  artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  process_boundary: shard.process_boundary,
  scenario_results: scenarioResults,
  assertions,
  local_product_source_checkouts_used: false,
  findings: shard.findings,
  search_attribute_shard: shard,
};
fs.writeFileSync(path.join(resultDir, 'sdk-php-search-attributes-shard.json'), `${JSON.stringify(shard, null, 2)}\n`);
fs.writeFileSync(path.join(resultDir, 'php-sdk-conformance-result.json'), `${JSON.stringify(result, null, 2)}\n`);
NODE
}

runtime_failure_summary() {
  local classification="${1:?classification is required}"
  local stage="${2:?stage is required}"
  local diagnostic_file="${3:?diagnostic file is required}"
  case "$classification" in
    server) printf 'The released PHP SDK probe received a server HTTP failure during %s; the bounded diagnostic is retained in structured evidence.\n' "$stage" ;;
    runner) printf 'The released PHP SDK probe encountered a transport failure during %s; the bounded diagnostic is retained in structured evidence.\n' "$stage" ;;
    *) printf 'The released PHP SDK process failed during %s; the bounded diagnostic is retained in structured evidence.\n' "$stage" ;;
  esac
}

failure_owner_for() {
  case "${1:?classification is required}" in
    server) printf '%s\n' server ;;
    runner) printf '%s\n' conformance_harness ;;
    package-publication) printf '%s\n' sdk-php-release ;;
    *) printf '%s\n' sdk-php ;;
  esac
}

classify_composer_failure() {
  local log_file="${1:?log file is required}"
  if [[ -f "$log_file" ]] && grep -Eqi 'curl error|could not resolve|network is unreachable|connection timed out|failed to open stream|temporary failure' "$log_file"; then
    printf '%s\n' runner
    return
  fi
  printf '%s\n' package-publication
}

cleanup() {
  local exit_code=$?
  if [[ -n "$worker_pid" ]] && kill -0 "$worker_pid" >/dev/null 2>&1; then
    kill -TERM "$worker_pid" >/dev/null 2>&1 || true
    wait "$worker_pid" >/dev/null 2>&1 || true
  fi
  exit "$exit_code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

exit_after_setup_failure() {
  if [[ "$validate_definitions" == true ]]; then
    exit 1
  fi
  exit 0
}

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'node is required to write typed conformance evidence' >&2
  exit 2
fi
if [[ -z "$sdk_version" || ! "$sdk_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  write_failure package-publication sdk-php-release preflight 'DW_PHP_SDK_VERSION must be an exact published Composer version.'
  exit_after_setup_failure
fi
if [[ "$validate_definitions" != true ]]; then
  if [[ -z "$server_version" \
    || "$server_version" =~ (^|[-.])(latest|current|head|main|master|dev|snapshot|unresolved|placeholder)([-.]|$) \
    || ! "$server_version" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?$ ]]; then
    write_failure runner conformance_harness preflight 'DW_SERVER_VERSION must be an exact published server version.'
    exit_after_setup_failure
  fi
  if [[ -z "$server_image" || -z "$server_url" ]]; then
    write_failure runner conformance_harness preflight 'DW_SERVER_VERSION, DW_SERVER_IMAGE, and DW_PHP_SDK_CONFORMANCE_SERVER_URL are required.'
    exit_after_setup_failure
  fi
  if [[ "$server_image" != "durableworkflow/server:${server_version}" \
    && "$server_image" != "docker.io/durableworkflow/server:${server_version}" \
    && ! "$server_image" =~ ^(docker\.io/)?durableworkflow/server(@sha256:[0-9a-fA-F]{64})$ ]]; then
    write_failure runner conformance_harness preflight 'DW_SERVER_IMAGE must be the exact requested durableworkflow/server tag or a digest pin.'
    exit_after_setup_failure
  fi
fi
if ! command -v "$php_bin" >/dev/null 2>&1; then
  write_failure runner conformance_harness preflight "PHP binary is unavailable: $php_bin"
  exit_after_setup_failure
fi
if ! command -v "$composer_bin" >/dev/null 2>&1; then
  write_failure runner conformance_harness preflight "Composer binary is unavailable: $composer_bin"
  exit_after_setup_failure
fi

rm -rf "$project_dir"
mkdir -p "$project_dir"

cat > "$project_dir/composer.json" <<JSON
{
  "name": "durable-workflow/php-sdk-conformance",
  "type": "project",
  "require": {
    "durable-workflow/sdk": "$sdk_version"
  },
  "config": {
    "preferred-install": "dist",
    "sort-packages": true,
    "allow-plugins": {}
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
JSON

if ! (
  cd "$project_dir"
  COMPOSER_ALLOW_SUPERUSER=1 \
  COMPOSER_HOME="$result_dir/composer-home" \
  COMPOSER_CACHE_DIR="$result_dir/composer-cache" \
  "$composer_bin" install --no-interaction --no-progress --prefer-dist --no-scripts
) >"$result_dir/php-sdk-composer-install.log" 2>&1; then
  composer_classification="$(classify_composer_failure "$result_dir/php-sdk-composer-install.log")"
  write_failure "$composer_classification" "$(failure_owner_for "$composer_classification")" composer_install "Composer could not install durable-workflow/sdk:$sdk_version from Packagist."
  exit_after_setup_failure
fi

if ! python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" sdk-php "$sdk_version" \
  "$result_dir/composer-cache/files/durable-workflow/sdk" '**/*' \
  --artifact-name durable-workflow/sdk; then
  write_failure package-publication sdk-php-release composer_identity \
    "Composer installed durable-workflow/sdk:$sdk_version without retaining its consumed distribution bytes."
  exit_after_setup_failure
fi

cp "$script_dir/php-sdk-runtime-failure.php" "$project_dir/runtime-failure.php"
cp "$script_dir/php-sdk-started-contract.php" "$project_dir/started-contract.php"
cp "$script_dir/php-sdk-search-attribute-probe.php" "$project_dir/search-attribute-probe.php"
cp "$script_dir/php-sdk-assertion-failure-evidence.php" "$project_dir/assertion-failure-evidence.php"
cp "$script_dir/php-sdk-activity-callback-cardinality.php" "$project_dir/activity-callback-cardinality.php"
cp "$script_dir/php-sdk-signal-input-decoder.php" "$project_dir/signal-input-decoder.php"

cat > "$project_dir/worker.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/runtime-failure.php';
require __DIR__.'/activity-callback-cardinality.php';
require __DIR__.'/signal-input-decoder.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ActivityFailed;
use DurableWorkflow\Exception\InvalidWorkerDefinition;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\Replayer;
use DurableWorkflow\Worker\WorkflowContext;

if ($argc < 9) {
    fwrite(STDERR, "usage: worker.php <server> <namespace> <control-token> <worker-token> <queue> <worker-id> <result-dir> <scope>\n");
    exit(2);
}

[$script, $server, $namespace, $controlToken, $workerToken, $queue, $workerId, $resultDir, $scope] = $argv;
install_runtime_failure_handler('worker', $scope, [$controlToken, $workerToken]);
$client = new Client($server, namespace: $namespace, controlToken: $controlToken, workerToken: $workerToken);
$callbackFile = $resultDir.'/php-sdk-callback-counts.json';
$activityCallbackFile = $resultDir.'/php-sdk-activity-callbacks.json';
$signalReplayFile = $resultDir.'/php-sdk-waiting-signal-replay.json';
$operationEvidenceFile = $resultDir.'/php-sdk-worker-operation-responses.json';
$namespaceScope = $scope === 'namespace';

function increment_callback(string $file, string $name): int
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open callback counter {$file}.");
    }
    try {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock callback counter.');
        }
        $raw = stream_get_contents($handle);
        $counts = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (! is_array($counts)) {
            $counts = [];
        }
        $counts[$name] = (int) ($counts[$name] ?? 0) + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        fflush($handle);
        flock($handle, LOCK_UN);

        return $counts[$name];
    } finally {
        fclose($handle);
    }
}

/** @param array<string, mixed> $response */
function record_operation_response(string $file, string $operation, array $response): void
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open operation response evidence {$file}.");
    }
    try {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock operation response evidence.');
        }
        $raw = stream_get_contents($handle);
        $responses = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (! is_array($responses)) {
            $responses = [];
        }
        $responses[$operation] = $response;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($responses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

/** @return list<int> */
function decoded_signal_inputs(QueryContext $context, Client $client, string $signalName): array
{
    return php_sdk_decoded_signal_inputs(
        $context->events('SignalReceived'),
        $signalName,
        static fn (array|string $raw): mixed => $client->payloadCodec()->decodeEnvelope($raw),
    );
}

/** @return list<int> */
function decoded_update_inputs(QueryContext $context, Client $client): array
{
    $inputs = [];
    $seen = [];
    foreach ($context->events('UpdateAccepted') as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (($payload['update_name'] ?? null) !== 'set') {
            continue;
        }
        $updateId = (string) ($payload['update_id'] ?? '');
        if ($updateId !== '' && isset($seen[$updateId])) {
            continue;
        }
        if ($updateId !== '') {
            $seen[$updateId] = true;
        }
        $raw = $payload['arguments'] ?? null;
        $decoded = is_array($raw) || is_string($raw) ? $client->payloadCodec()->decodeEnvelope($raw) : [];
        $arguments = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $inputs[] = (int) ($arguments[0] ?? 0);
    }

    return $inputs;
}

/** @return array<string, int> */
function history_event_counts(QueryContext $context): array
{
    $counts = [];
    foreach ($context->history as $event) {
        $type = (string) ($event['event_type'] ?? $event['type'] ?? '');
        if ($type !== '') {
            $counts[$type] = (int) ($counts[$type] ?? 0) + 1;
        }
    }
    ksort($counts);

    return $counts;
}

/** @return list<string> */
function history_activity_types(QueryContext $context): array
{
    $types = [];
    foreach ($context->history as $event) {
        if (($event['event_type'] ?? $event['type'] ?? null) !== 'ActivityScheduled') {
            continue;
        }
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $activityType = (string) ($payload['activity_type'] ?? $payload['activity_name'] ?? '');
        if ($activityType !== '') {
            $types[] = $activityType;
        }
    }

    return $types;
}

function replay_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

/** @param array<string, mixed> $evidence */
function record_first_timing(string $file, array $evidence): void
{
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open replay timing evidence {$file}.");
    }
    try {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock replay timing evidence.');
        }
        $raw = stream_get_contents($handle);
        if (! is_string($raw) || trim($raw) === '') {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            fflush($handle);
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

/** @param list<list<mixed>> $signals */
function record_replay_signals(string $file, array $signals): void
{
    $inputs = array_map(
        static fn (array $arguments): int => (int) ($arguments[0] ?? 0),
        $signals,
    );
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Unable to open signal replay evidence {$file}.");
    }
    try {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock signal replay evidence.');
        }
        $raw = stream_get_contents($handle);
        $previous = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        $previousInputs = is_array($previous['inputs'] ?? null) ? $previous['inputs'] : [];
        if (count($inputs) >= count($previousInputs)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode([
                'signal_name' => 'increment',
                'inputs' => $inputs,
                'total' => array_sum($inputs),
                'observed_during_workflow_replay' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            fflush($handle);
        }
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

$worker = new Worker($client, $queue, workerId: $workerId, heartbeatIntervalSeconds: 1);
$worker->registerActivity(
    'php.sdk.echo',
    static function (ActivityContext $context, mixed $value) use ($activityCallbackFile, $callbackFile, $workerId): array {
        $callbackCount = increment_callback($callbackFile, 'activity');
        increment_callback($callbackFile, 'activity_heartbeat');
        $context->heartbeat(['phase' => 'activity', 'callback_count' => 1]);
        php_sdk_record_activity_callback(
            $activityCallbackFile,
            php_sdk_activity_callback_phase($value),
            [
                'worker_id' => $workerId,
                'worker_process_id' => getmypid(),
                'task_id' => $context->taskId,
                'activity_attempt_id' => $context->activityAttemptId,
                'activity_type' => $context->activityType,
                'attempt_number' => $context->attemptNumber,
                'global_callback_count' => $callbackCount,
                'heartbeat_recorded' => true,
            ],
        );

        return ['echo' => $value, 'activity_process_id' => getmypid()];
    },
);
$worker->registerWorkflow(
    'php.sdk.simple',
    static function (WorkflowContext $context, mixed $value) use ($callbackFile): array {
        increment_callback($callbackFile, 'simple_workflow_replays');
        $activity = $context->activity('php.sdk.echo', [$value]);

        return ['result' => $activity, 'workflow_process_id' => getmypid()];
    },
);
$worker->registerWorkflow(
    'php.sdk.search-attributes',
    static function (WorkflowContext $context, array $upsertedAttributes) use ($callbackFile): array {
        increment_callback($callbackFile, 'search_attribute_workflow_replays');
        $context->upsertSearchAttributes($upsertedAttributes);

        return [
            'upserted_search_attributes' => $upsertedAttributes,
            'workflow_process_id' => getmypid(),
        ];
    },
);
if (! $namespaceScope) {
    $worker->registerWorkflow(
        'php.sdk.replay',
        static function (WorkflowContext $context, mixed $value) use ($callbackFile): array {
            increment_callback($callbackFile, 'replay_workflow_replays');
            $activity = $context->activity('php.sdk.echo', [$value]);
            $context->sleep(10);

            return ['replayed_result' => $activity, 'workflow_process_id' => getmypid()];
        },
    );
    $worker->registerWorkflow(
        'php.sdk.waiting',
        static function (WorkflowContext $context) use ($callbackFile, $signalReplayFile): array {
            increment_callback($callbackFile, 'waiting_workflow_replays');
            record_replay_signals($signalReplayFile, $context->signals('increment'));
            $context->throwIfCancellationRequested();
            $context->sleep(300);

            return ['unexpected' => 'timer-fired'];
        },
    );
    $worker->declareSignal(
        'php.sdk.waiting',
        'increment',
        static fn (int $amount): mixed => null,
    );
    $worker->registerWorkflow(
        'php.sdk.failure',
        static function (WorkflowContext $context) use ($callbackFile): never {
            increment_callback($callbackFile, 'failure_workflow');
            throw new DomainException('php-sdk-conformance-failure');
        },
    );
    $worker->registerWorkflow(
        'php.sdk.search',
        static function (WorkflowContext $context, string $name, string $value) use ($callbackFile): array {
            increment_callback($callbackFile, 'search_workflow_replays');
            $context->upsertSearchAttributes([$name => $value]);

            return ['search_attribute' => $name, 'value' => $value, 'workflow_process_id' => getmypid()];
        },
    );
    $worker->registerQuery(
        'php.sdk.waiting',
        'current',
        static function (QueryContext $context) use ($client, $callbackFile, $operationEvidenceFile): array {
            increment_callback($callbackFile, 'query');
            $inputs = decoded_signal_inputs($context, $client, 'increment');
            $response = [
                'inputs' => $inputs,
                'total' => array_sum($inputs),
                'query_process_id' => getmypid(),
            ];
            record_operation_response($operationEvidenceFile, 'workflow.query:current', $response);

            return $response;
        },
    );
    $worker->registerUpdate(
        'php.sdk.waiting',
        'set',
        static function (QueryContext $context, int $value) use ($callbackFile, $operationEvidenceFile): array {
            increment_callback($callbackFile, 'update');
            $response = ['accepted' => true, 'value' => $value, 'run_id' => $context->runId];
            record_operation_response($operationEvidenceFile, 'workflow.update:set', $response);

            return $response;
        },
    );
    if (getenv('DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX') === '1') {
        $worker->registerActivity(
            'php.sdk.replay-matrix-fail',
            static function (ActivityContext $context) use ($callbackFile): never {
                increment_callback($callbackFile, 'replay_matrix_failed_activity');
                throw new DomainException('php-sdk-replay-matrix-intentional-failure');
            },
        );
        $worker->registerWorkflow(
            'php.sdk.replay-matrix',
            static function (WorkflowContext $context) use ($callbackFile): array {
                increment_callback($callbackFile, 'replay_matrix_workflow_replays');
                $activity = $context->activity('php.sdk.echo', [['matrix' => 'activity']]);
                $versionMarker = $context->sideEffect(
                    static function () use ($callbackFile): array {
                        increment_callback($callbackFile, 'replay_matrix_version_marker_callbacks');

                        return ['change_id' => 'php-replay-matrix', 'version' => 1];
                    },
                );
                $waitCycles = 0;
                do {
                    $context->sleep(1);
                    ++$waitCycles;
                    $signals = $context->signals('release');
                    $updates = $context->updates('set');
                } while ($signals === [] || $updates === []);

                try {
                    $context->activity('php.sdk.replay-matrix-fail', [], [
                        'retry_policy' => ['max_attempts' => 1, 'backoff_seconds' => [0]],
                    ]);
                    throw new LogicException('The replay matrix failure activity unexpectedly completed.');
                } catch (ActivityFailed $failure) {
                    $compensation = $context->activity('php.sdk.echo', [[
                        'matrix' => 'compensation',
                        'failure_type' => $failure->failureType,
                    ]]);
                }
                $afterSignal = $context->activity('php.sdk.echo', [['matrix' => 'after-signal']]);

                return [
                    'activity' => $activity,
                    'signals' => array_map(static fn (array $arguments): int => (int) ($arguments[0] ?? 0), $signals),
                    'updates' => array_map(static fn (array $arguments): int => (int) ($arguments[0] ?? 0), $updates),
                    'wait_cycles' => $waitCycles,
                    'version_marker' => $versionMarker,
                    'compensation' => $compensation,
                    'after_signal' => $afterSignal,
                    'workflow_process_id' => getmypid(),
                ];
            },
        );
        $worker->declareSignal(
            'php.sdk.replay-matrix',
            'release',
            static fn (int $value): mixed => null,
        );
        $worker->registerUpdate(
            'php.sdk.replay-matrix',
            'set',
            static fn (QueryContext $context, int $value): array => [
                'accepted' => true,
                'value' => $value,
                'run_id' => $context->runId,
            ],
        );
        $worker->registerQuery(
            'php.sdk.replay-matrix',
            'state',
            static fn (QueryContext $context): array => [
                'event_counts' => history_event_counts($context),
                'activity_types' => history_activity_types($context),
                'signals' => decoded_signal_inputs($context, $client, 'release'),
                'updates' => decoded_update_inputs($context, $client),
                'query_process_id' => getmypid(),
            ],
        );
        $worker->registerWorkflow(
            'php.sdk.replay-in-flight-signal',
            static function (WorkflowContext $context) use ($resultDir, $workerId): array {
                $cycles = 0;
                do {
                    $context->sleep(3);
                    ++$cycles;
                    $signals = $context->signals('advance');
                } while ($signals === []);
                record_first_timing($resultDir.'/php-sdk-in-flight-worker-timing.json', [
                    'history_reloaded_at' => replay_timestamp(),
                    'replayed_next_decision' => 'schedule_activity:php.sdk.echo',
                    'worker_id' => $workerId,
                    'worker_process_id' => getmypid(),
                    'signal_inputs' => array_map(
                        static fn (array $arguments): int => (int) ($arguments[0] ?? 0),
                        $signals,
                    ),
                    'wait_cycles' => $cycles,
                ]);
                $next = $context->activity('php.sdk.echo', [['matrix' => 'in-flight-after-signal']]);

                return [
                    'signals' => array_map(static fn (array $arguments): int => (int) ($arguments[0] ?? 0), $signals),
                    'next_decision_result' => $next,
                    'wait_cycles' => $cycles,
                    'workflow_process_id' => getmypid(),
                ];
            },
        );
        $worker->declareSignal(
            'php.sdk.replay-in-flight-signal',
            'advance',
            static fn (int $value): mixed => null,
        );
    }
}
if (getenv('DW_PHP_SDK_CONFORMANCE_VALIDATE_DEFINITIONS') === '1') {
    $worker->validate();
    $fiberRuntimeScenarios = [
        'lifecycle_activity' => [
            'handler' => static function (WorkflowContext $context): array {
                $result = $context->activity('php.sdk.echo', [['fiber' => 'activity']]);

                return ['result' => $result];
            },
            'expected_first_command' => 'schedule_activity',
        ],
        'replay_timer' => [
            'handler' => static function (WorkflowContext $context): array {
                $context->sleep(1);

                return ['timer' => 'fired'];
            },
            'expected_first_command' => 'start_timer',
        ],
        'search_attributes' => [
            'handler' => static function (WorkflowContext $context): array {
                $context->upsertSearchAttributes(['fiber' => 'direct']);

                return ['upserted' => true];
            },
            'expected_first_command' => 'upsert_search_attributes',
        ],
        'signal_query_wait' => [
            'handler' => static function (WorkflowContext $context): array {
                $context->sleep(300);

                return ['signals' => count($context->signals('increment'))];
            },
            'expected_first_command' => 'start_timer',
        ],
    ];
    $fiberRuntimeEvidence = [];
    $replayer = new Replayer($client->payloadCodec());
    foreach ($fiberRuntimeScenarios as $scenario => $contract) {
        $commands = $replayer->replay($contract['handler'], [], [], $queue)->commands;
        $observed = $commands[0]['type'] ?? null;
        if ($observed !== $contract['expected_first_command']) {
            throw new RuntimeException(
                "Fiber runtime scenario {$scenario} emitted "
                .json_encode($observed, JSON_THROW_ON_ERROR)
                .'; expected '
                .json_encode($contract['expected_first_command'], JSON_THROW_ON_ERROR)
                .'.',
            );
        }
        $fiberRuntimeEvidence[$scenario] = [
            'executed' => true,
            'expected_first_command' => $contract['expected_first_command'],
            'observed_first_command' => $observed,
        ];
    }
    $invalidDefinitions = [
        [
            'contract' => 'workflow php.sdk.failure',
            'context' => WorkflowContext::class,
            'register' => static fn (Worker $candidate): Worker => $candidate->registerWorkflow(
                'php.sdk.failure',
                static fn (): null => null,
            ),
        ],
        [
            'contract' => 'activity php.sdk.replay-matrix-fail',
            'context' => ActivityContext::class,
            'register' => static fn (Worker $candidate): Worker => $candidate->registerActivity(
                'php.sdk.replay-matrix-fail',
                static fn (): null => null,
            ),
        ],
    ];
    $rejections = [];
    foreach ($invalidDefinitions as $index => $definition) {
        $candidate = new Worker(
            $client,
            $queue.'-invalid-'.$index,
            workerId: $workerId.'-invalid-'.$index,
        );
        try {
            $definition['register']($candidate);
            throw new RuntimeException("Zero-argument {$definition['contract']} was accepted.");
        } catch (InvalidWorkerDefinition $exception) {
            $expectedMessage = "Invalid worker contract {$definition['contract']}. "
                ."Make the first handler parameter {$definition['context']}.";
            if ($exception->contract !== $definition['contract'] || $exception->getMessage() !== $expectedMessage) {
                throw new RuntimeException(
                    "Unexpected validation for {$definition['contract']}: {$exception->getMessage()}",
                    previous: $exception,
                );
            }
            $rejections[$definition['contract']] = [
                'exception_type' => $exception::class,
                'contract' => $exception->contract,
                'message' => $exception->getMessage(),
                'required_context' => $definition['context'],
            ];
        }
    }
    file_put_contents(
        $resultDir.'/php-sdk-handler-definition-validation.json',
        json_encode([
            'schema' => 'durable-workflow.v2.php-sdk-handler-definition-validation',
            'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
            'replay_matrix_enabled' => getenv('DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX') === '1',
            'registered_contracts' => $worker->contracts(),
            'fiber_runtime_scenarios' => $fiberRuntimeEvidence,
            'zero_argument_rejections' => $rejections,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
    );
    exit(0);
}
$workerRunDelayMs = filter_var(
    getenv('DW_PHP_SDK_CONFORMANCE_WORKER_RUN_DELAY_MS') ?: 0,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 0, 'default' => 0]],
);
if ($workerRunDelayMs > 0) {
    usleep($workerRunDelayMs * 1000);
}
set_runtime_failure_context('worker.run', 'MULTIPLE', '/api/worker-protocol/*');
$worker->run(1);
PHP

if [[ "$validate_definitions" == true ]]; then
  validation_log="$result_dir/php-sdk-handler-definition-validation.log"
  if ! DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX=1 \
    DW_PHP_SDK_CONFORMANCE_VALIDATE_DEFINITIONS=1 \
    "$php_bin" "$project_dir/worker.php" \
      http://handler-validation.invalid validation validation-control validation-worker \
      validation-queue validation-worker "$result_dir" lifecycle \
      >"$validation_log" 2>&1; then
    cat "$validation_log" >&2
    exit 1
  fi
  if [[ ! -s "$result_dir/php-sdk-handler-definition-validation.json" ]]; then
    printf '%s\n' 'PHP SDK handler validation did not emit its result.' >&2
    exit 1
  fi
  exit 0
fi

cat > "$project_dir/client.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/runtime-failure.php';
require __DIR__.'/started-contract.php';
require __DIR__.'/search-attribute-probe.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\NonDeterministicWorkflow;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\WorkflowCancelled;
use DurableWorkflow\Exception\WorkflowFailed;
use DurableWorkflow\Exception\WorkflowTerminated;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;
use DurableWorkflow\Worker\Replayer;
use DurableWorkflow\Worker\WorkflowContext;

if ($argc < 8) {
    fwrite(STDERR, "usage: client.php <phase> <server> <namespace> <token> <queue> <result-dir> <suffix>\n");
    exit(2);
}

[$script, $phase, $server, $namespace, $token, $queue, $resultDir, $suffix] = $argv;
install_runtime_failure_handler('client', $phase, [$token]);
$client = new Client($server, token: $token, namespace: $namespace);
$stateFile = $resultDir.'/php-sdk-replay-state.json';
$matrixStateFile = $resultDir.'/php-sdk-replay-matrix-state.json';
$inFlightStateFile = $resultDir.'/php-sdk-in-flight-state.json';

function emit(array $payload): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(0);
}

function event_types(array $history): array
{
    return array_values(array_map(
        static fn (array $event): string => (string) ($event['event_type'] ?? $event['type'] ?? ''),
        array_values(array_filter($history['events'] ?? $history['history'] ?? [], 'is_array')),
    ));
}

/** @return list<array<string, mixed>> */
function history_events(array $history): array
{
    return array_values(array_filter($history['events'] ?? $history['history'] ?? [], 'is_array'));
}

/** @return array<string, int> */
function event_counts(array $history): array
{
    $counts = [];
    foreach (event_types($history) as $type) {
        if ($type !== '') {
            $counts[$type] = (int) ($counts[$type] ?? 0) + 1;
        }
    }
    ksort($counts);

    return $counts;
}

function replay_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

/** @return array<string, array<string, mixed>> */
function worker_operation_responses(string $resultDir): array
{
    $path = $resultDir.'/php-sdk-worker-operation-responses.json';
    if (! is_file($path)) {
        return [];
    }
    $responses = json_decode((string) file_get_contents($path), true);

    return is_array($responses) ? $responses : [];
}

/** @return array{exception_type: string, status_code: int, reason: string|null, details: array<mixed>|null} */
function capture_signal_refusal(callable $operation, int $expectedStatus, string $expectedReason): array
{
    try {
        $operation();
    } catch (ServerException $exception) {
        if ($exception->status !== $expectedStatus || $exception->reason !== $expectedReason) {
            throw new RuntimeException(sprintf(
                'Signal refusal was HTTP %d reason=%s; expected HTTP %d reason=%s.',
                $exception->status,
                $exception->reason ?? 'null',
                $expectedStatus,
                $expectedReason,
            ), previous: $exception);
        }

        return [
            'exception_type' => $exception::class,
            'status_code' => $exception->status,
            'reason' => $exception->reason,
            'details' => $exception->details,
        ];
    }

    throw new RuntimeException("Signal command unexpectedly succeeded; expected {$expectedReason}.");
}

/** @return list<int> */
function history_signal_inputs(array $history, Client $client, string $signalName): array
{
    $inputs = [];
    foreach (array_filter($history['events'] ?? $history['history'] ?? [], 'is_array') as $event) {
        if (($event['event_type'] ?? $event['type'] ?? null) !== 'SignalReceived') {
            continue;
        }
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (($payload['signal_name'] ?? null) !== $signalName) {
            continue;
        }
        $raw = $payload['value'] ?? $payload['input'] ?? $payload['arguments'] ?? null;
        $decoded = is_array($raw) || is_string($raw)
            ? $client->payloadCodec()->decodeEnvelope($raw)
            : [];
        $arguments = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $inputs[] = (int) ($arguments[0] ?? 0);
    }

    return $inputs;
}

function run_namespace_probe(
    Client $client,
    string $namespace,
    string $queue,
    string $resultDir,
    string $suffix,
    array $identity,
): array {
    set_runtime_failure_context('cluster.info', 'GET', '/api/cluster/info');
    $cluster = $client->clusterInfo(includeDiagnostics: true);
    set_runtime_failure_context('namespace.list', 'GET', '/api/namespaces');
    $namespacesBefore = $client->listNamespaces();
    $temporaryNamespace = 'php-sdk-'.$suffix;
    set_runtime_failure_context('namespace.create', 'POST', '/api/namespaces');
    $created = $client->createNamespace($temporaryNamespace, 'PHP SDK published-artifact conformance', 1);
    set_runtime_failure_context('namespace.describe', 'GET', '/api/namespaces/{namespace}');
    $described = $client->describeNamespace($temporaryNamespace);
    set_runtime_failure_context('namespace.update', 'PUT', '/api/namespaces/{namespace}');
    $updated = $client->updateNamespace($temporaryNamespace, 'updated by PHP SDK conformance', 2);
    set_runtime_failure_context('namespace.describe_after_update', 'GET', '/api/namespaces/{namespace}');
    $describedAfterUpdate = $client->describeNamespace($temporaryNamespace);
    set_runtime_failure_context('namespace.list_after_create', 'GET', '/api/namespaces');
    $namespacesAfterCreate = $client->listNamespaces();
    $listedNamesAfterCreate = array_map(static fn ($item): string => $item->name, $namespacesAfterCreate);
    $scopedClient = $client->withNamespace($temporaryNamespace);
    set_runtime_failure_context('workflow.list_in_selected_namespace', 'GET', '/api/workflows');
    $scopedWorkflows = $scopedClient->listWorkflows();
    set_runtime_failure_context('namespace.delete', 'DELETE', '/api/namespaces/{namespace}');
    $deleted = $client->deleteNamespace($temporaryNamespace);
    set_runtime_failure_context('namespace.list_after_delete', 'GET', '/api/namespaces');
    $namespacesAfterDelete = $client->listNamespaces();
    $listedNamesAfterDelete = array_map(
        static fn ($item): string => $item->name,
        $namespacesAfterDelete,
    );

    $simpleWorkflowId = 'php-sdk-simple-'.$suffix;
    set_runtime_failure_context('workflow.start:simple', 'POST', '/api/workflows', $simpleWorkflowId);
    $simple = $client->startWorkflow(
        'php.sdk.simple',
        $simpleWorkflowId,
        $queue,
        [['message' => 'hello', 'count' => 7]],
    );
    set_runtime_failure_context('workflow.result:simple', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $simple->workflowId, $simple->selectedRunId);
    $simpleResult = $simple->result(timeoutSeconds: 30, pollIntervalSeconds: 0.2);
    set_runtime_failure_context('workflow.describe:simple', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}', $simple->workflowId, $simple->selectedRunId);
    $simpleDescription = $simple->describeSelectedRun();
    set_runtime_failure_context('workflow.history:simple', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', $simple->workflowId, $simple->selectedRunId);
    $simpleHistory = $client->workflowHistory($simple->workflowId, (string) $simple->selectedRunId);

    $evidence = [
        'identity' => $identity,
        'cluster_info' => $cluster->raw,
        'namespace_count' => count($namespacesBefore),
        'namespace_lifecycle' => [
            'created' => $created->name === $temporaryNamespace,
            'described' => $described->name === $temporaryNamespace,
            'updated' => $updated->name === $temporaryNamespace
                && $describedAfterUpdate->description === 'updated by PHP SDK conformance'
                && $describedAfterUpdate->retentionDays === 2,
            'listed' => in_array($temporaryNamespace, $listedNamesAfterCreate, true),
            'deleted' => $deleted->name === $temporaryNamespace
                && ! in_array($temporaryNamespace, $listedNamesAfterDelete, true),
            'created_namespace' => $temporaryNamespace,
            'selected_namespace' => $scopedClient->namespace,
            'selected_namespace_workflow_count' => $scopedWorkflows->workflowCount,
            'worker_namespace' => $namespace,
        ],
        'simple_workflow' => [
            'namespace' => $namespace,
            'workflow_id' => $simple->workflowId,
            'run_id' => $simple->selectedRunId,
            'status' => $simpleDescription->status,
            'result' => $simpleResult,
            'history_event_types' => event_types($simpleHistory),
            'history_event_counts' => event_counts($simpleHistory),
        ],
    ];
    file_put_contents(
        $resultDir.'/php-sdk-namespace-evidence.json',
        json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    return $evidence;
}

/** @param array<string, mixed> $expected @param array<string, mixed> $actual */
function search_attribute_maps_match(array $expected, array $actual): bool
{
    foreach ($expected as $name => $value) {
        if (! array_key_exists($name, $actual)) {
            return false;
        }
        $observed = $actual[$name];
        if (is_array($value)) {
            if (! is_array($observed) || array_values($value) !== array_values($observed)) {
                return false;
            }
            continue;
        }
        if (is_string($value) && is_string($observed) && str_contains($name, 'created_at')) {
            try {
                if ((new DateTimeImmutable($value))->getTimestamp() !== (new DateTimeImmutable($observed))->getTimestamp()) {
                    return false;
                }
                continue;
            } catch (Throwable) {
                return false;
            }
        }
        if ($value !== $observed) {
            return false;
        }
    }

    return true;
}

/** @return array{page: DurableWorkflow\Model\WorkflowPage, elapsed_ms: float} */
function wait_for_search_attribute_query(
    Client $client,
    string $query,
    string $workflowId,
    float $timeoutSeconds = 5.0,
): array {
    $started = microtime(true);
    do {
        $page = $client->listWorkflows(query: $query);
        $workflowIds = array_map(
            static fn ($execution): string => $execution->workflowId,
            $page->executions,
        );
        if (in_array($workflowId, $workflowIds, true)) {
            return ['page' => $page, 'elapsed_ms' => round((microtime(true) - $started) * 1000, 3)];
        }
        usleep(100000);
    } while (microtime(true) - $started < $timeoutSeconds);

    return ['page' => $page, 'elapsed_ms' => round((microtime(true) - $started) * 1000, 3)];
}

/** @param array<string, mixed> $identity @return array<string, mixed> */
function run_search_attribute_probe(
    Client $client,
    string $namespace,
    string $queue,
    string $resultDir,
    string $suffix,
    array $identity,
): array {
    set_runtime_failure_context('cluster.info', 'GET', '/api/cluster/info');
    $cluster = $client->clusterInfo(includeDiagnostics: true);
    $probeValues = php_sdk_search_attribute_probe_values($suffix);
    $definitions = $probeValues['definitions'];
    $listDefinitions = static function () use ($client): array {
        set_runtime_failure_context('search_attribute.list', 'GET', '/api/search-attributes');

        return $client->listSearchAttributes()->customAttributes;
    };
    $createDefinition = static function (string $name, string $type) use ($client): array {
        set_runtime_failure_context('search_attribute.create', 'POST', '/api/search-attributes');
        try {
            $created = $client->createSearchAttribute($name, $type);

            return [
                'outcome' => 'created',
                'name' => $created->name,
                'type' => $created->type,
            ];
        } catch (ServerException $exception) {
            if ($exception->status !== 409 || $exception->reason !== 'attribute_already_exists') {
                throw $exception;
            }

            return [
                'outcome' => 'already_exists',
                'name' => is_string($exception->details['name'] ?? null)
                    ? $exception->details['name']
                    : $name,
                'type' => is_string($exception->details['type'] ?? null)
                    ? $exception->details['type']
                    : null,
            ];
        }
    };
    $listedDefinitions = php_sdk_ensure_search_attribute_definitions(
        $definitions,
        $listDefinitions,
        $createDefinition,
    );

    $startAttributes = $probeValues['start_search_attributes'];
    $upsertedAttributes = $probeValues['upserted_search_attributes'];
    $expectedAttributes = $probeValues['expected_search_attributes'];
    $workflowId = 'php-sdk-search-attributes-'.$suffix;
    set_runtime_failure_context('workflow.start:search_attributes', 'POST', '/api/workflows', $workflowId);
    $handle = $client->startWorkflow(
        'php.sdk.search-attributes',
        $workflowId,
        $queue,
        [$upsertedAttributes],
        searchAttributes: $startAttributes,
    );
    set_runtime_failure_context('workflow.result:search_attributes', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $handle->workflowId, $handle->selectedRunId);
    $workflowResult = $handle->resultOfSelectedRun(timeoutSeconds: 30, pollIntervalSeconds: 0.2);
    set_runtime_failure_context('workflow.describe:search_attributes', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}', $handle->workflowId, $handle->selectedRunId);
    $description = $handle->describeSelectedRun();
    $observedAttributes = is_array($description->searchAttributes) ? $description->searchAttributes : [];
    $query = $probeValues['visibility_query']['query'];
    set_runtime_failure_context('workflow.list_by_search_attribute', 'GET', '/api/workflows?query={query}');
    $primaryQuery = wait_for_search_attribute_query($client, $query, $workflowId);
    $upsertWorkflowIds = array_map(
        static fn ($execution): string => $execution->workflowId,
        $primaryQuery['page']->executions,
    );

    $peerNamespace = 'php-sdk-search-peer-'.$suffix;
    set_runtime_failure_context('namespace.create:search_attribute_peer', 'POST', '/api/namespaces');
    $client->createNamespace($peerNamespace, 'PHP SDK search-attribute namespace-isolation probe', 1);
    $peerClient = $client->withNamespace($peerNamespace);
    php_sdk_ensure_search_attribute_definitions(
        $definitions,
        static function () use ($peerClient): array {
            set_runtime_failure_context('search_attribute.list:peer', 'GET', '/api/search-attributes');

            return $peerClient->listSearchAttributes()->customAttributes;
        },
        static function (string $name, string $type) use ($peerClient): array {
            set_runtime_failure_context('search_attribute.create:peer', 'POST', '/api/search-attributes');
            try {
                $created = $peerClient->createSearchAttribute($name, $type);

                return [
                    'outcome' => 'created',
                    'name' => $created->name,
                    'type' => $created->type,
                ];
            } catch (ServerException $exception) {
                if ($exception->status !== 409 || $exception->reason !== 'attribute_already_exists') {
                    throw $exception;
                }

                return [
                    'outcome' => 'already_exists',
                    'name' => is_string($exception->details['name'] ?? null)
                        ? $exception->details['name']
                        : $name,
                    'type' => is_string($exception->details['type'] ?? null)
                        ? $exception->details['type']
                        : null,
                ];
            }
        },
    );
    $peerWorkflowId = 'php-sdk-search-attributes-peer-'.$suffix;
    set_runtime_failure_context('workflow.start:search_attribute_peer', 'POST', '/api/workflows', $peerWorkflowId);
    $peerClient->startWorkflow(
        'php.sdk.search-attributes',
        $peerWorkflowId,
        $queue,
        [$upsertedAttributes],
        searchAttributes: $startAttributes,
    );
    $isolationQuery = $probeValues['namespace_isolation_query']['query'];
    set_runtime_failure_context('workflow.list_by_start_search_attribute:primary', 'GET', '/api/workflows?query={query}');
    $primaryIsolationQuery = wait_for_search_attribute_query($client, $isolationQuery, $workflowId);
    $primaryIsolationWorkflowIds = array_map(
        static fn ($execution): string => $execution->workflowId,
        $primaryIsolationQuery['page']->executions,
    );
    set_runtime_failure_context('workflow.list_by_start_search_attribute:peer', 'GET', '/api/workflows?query={query}');
    $peerQuery = wait_for_search_attribute_query($peerClient, $isolationQuery, $peerWorkflowId);
    $peerWorkflowIds = array_map(
        static fn ($execution): string => $execution->workflowId,
        $peerQuery['page']->executions,
    );

    $pythonToPhp = null;
    $pythonFixtureJson = trim((string) getenv('DW_PHP_SDK_SEARCH_ATTRIBUTES_PYTHON_FIXTURE_JSON'));
    if ($pythonFixtureJson !== '') {
        $pythonFixture = json_decode($pythonFixtureJson, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($pythonFixture)) {
            throw new RuntimeException('Python search-attribute fixture must be a JSON object.');
        }
        $pythonNamespace = (string) ($pythonFixture['namespace'] ?? '');
        $pythonWorkflowId = (string) ($pythonFixture['workflow_id'] ?? '');
        $pythonExpected = is_array($pythonFixture['expected_search_attributes'] ?? null)
            ? $pythonFixture['expected_search_attributes']
            : [];
        if ($pythonNamespace === '' || $pythonWorkflowId === '' || $pythonExpected === []) {
            throw new RuntimeException('Python search-attribute fixture requires namespace, workflow_id, and expected_search_attributes.');
        }
        $pythonClient = $client->withNamespace($pythonNamespace);
        set_runtime_failure_context('workflow.describe:python_writer', 'GET', '/api/workflows/{workflow_id}');
        $pythonDescription = $pythonClient->describeWorkflow($pythonWorkflowId);
        $pythonObserved = is_array($pythonDescription->searchAttributes) ? $pythonDescription->searchAttributes : [];
        $pythonQueryKey = (string) ($pythonFixture['query_key'] ?? array_key_first($pythonExpected));
        $pythonQueryValue = (string) ($pythonFixture['query_value'] ?? ($pythonExpected[$pythonQueryKey] ?? ''));
        $pythonQuery = sprintf('%s = "%s"', $pythonQueryKey, addcslashes($pythonQueryValue, "\\\""));
        set_runtime_failure_context('workflow.list_by_search_attribute:python_writer', 'GET', '/api/workflows?query={query}');
        $pythonPage = wait_for_search_attribute_query($pythonClient, $pythonQuery, $pythonWorkflowId);
        $pythonWorkflowIds = array_map(
            static fn ($execution): string => $execution->workflowId,
            $pythonPage['page']->executions,
        );
        $sdkReaderMatched = search_attribute_maps_match($pythonExpected, $pythonObserved)
            && in_array($pythonWorkflowId, $pythonWorkflowIds, true);
        $cliReaderMatched = ($pythonFixture['cli_reader_verified'] ?? false) === true;
        $pythonToPhp = [
            'status' => $sdkReaderMatched && $cliReaderMatched ? 'pass' : 'not_covered',
            'wire_value_context' => [
                'writer' => 'sdk-python',
                'reader' => 'sdk-php',
                'public_surface' => '/api/workflows/{workflow_id}',
            ],
            'written_attributes' => $pythonExpected,
            'decoded_attributes' => $pythonObserved,
            'reader_verifications' => [
                'sdk-php' => $sdkReaderMatched,
                'cli' => $cliReaderMatched,
            ],
            'namespace' => $pythonNamespace,
            'workflow_id' => $pythonWorkflowId,
            'query' => $pythonQuery,
            'matched_workflow_ids' => array_slice($pythonWorkflowIds, 0, 20),
        ];
    }

    $evidence = [
        'identity' => $identity,
        'cluster_info' => $cluster->raw,
        'schema_definitions' => $listedDefinitions,
        'workflow_id' => $workflowId,
        'run_id' => $handle->selectedRunId,
        'worker_runtime' => 'sdk-php',
        'start_search_attributes' => $startAttributes,
        'upserted_search_attributes' => $upsertedAttributes,
        'expected_search_attributes' => $expectedAttributes,
        'actual_search_attributes' => $observedAttributes,
        'workflow_result' => $workflowResult,
        'typed_values' => array_map('get_debug_type', $observedAttributes),
        'visibility_query_match' => in_array($workflowId, $upsertWorkflowIds, true),
        'query_visibility' => [
            'attribute_source' => $probeValues['visibility_query']['attribute_source'],
            'attribute_name' => $probeValues['visibility_query']['name'],
            'attribute_value' => $probeValues['visibility_query']['value'],
            'query' => $query,
            'matched_workflow_ids' => array_slice($upsertWorkflowIds, 0, 20),
            'elapsed_ms' => $primaryQuery['elapsed_ms'],
        ],
        'namespace_isolation' => php_sdk_search_attribute_namespace_isolation_evidence(
            $namespace,
            $peerNamespace,
            $workflowId,
            $peerWorkflowId,
            $probeValues['namespace_isolation_query'],
            $primaryIsolationWorkflowIds,
            $peerWorkflowIds,
        ) + [
            'primary_query_elapsed_ms' => $primaryIsolationQuery['elapsed_ms'],
            'peer_query_elapsed_ms' => $peerQuery['elapsed_ms'],
        ],
        'codec_round_trips' => [
            'python_to_php' => $pythonToPhp,
            'php_to_python_writer' => [
                'wire_value_context' => [
                    'writer' => 'sdk-php',
                    'public_surface' => '/api/workflows/{workflow_id}',
                ],
                'written_attributes' => $expectedAttributes,
                'namespace' => $namespace,
                'workflow_id' => $workflowId,
                'query' => $query,
            ],
        ],
        'package_ownership' => [
            'standalone_connectivity' => 'durable-workflow/sdk',
            'embedded_engine' => 'durable-workflow/workflow',
            'workflow_standalone_client_or_worker_loaded' => false,
        ],
    ];
    file_put_contents(
        $resultDir.'/php-sdk-search-attributes-evidence.json',
        json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    return $evidence;
}

$identity = [
    'process_id' => getmypid(),
    'host' => gethostname(),
    'php_version' => PHP_VERSION,
    'sdk_version' => InstalledVersions::getPrettyVersion('durable-workflow/sdk')
        ?: InstalledVersions::getVersion('durable-workflow/sdk'),
];

if ($phase === 'search-attributes') {
    emit([
        'phase' => $phase,
        'search_attributes' => run_search_attribute_probe(
            $client,
            $namespace,
            $queue,
            $resultDir,
            $suffix,
            $identity,
        ),
    ]);
}

if ($phase === 'baseline' || $phase === 'namespace') {
    $namespaceProbe = run_namespace_probe($client, $namespace, $queue, $resultDir, $suffix, $identity);
    if ($phase === 'namespace') {
        emit(['phase' => $phase] + $namespaceProbe);
    }

    $searchAttributeName = 'php_sdk_'.str_replace('-', '_', $suffix);
    $searchAttributeValue = 'published-sdk';
    set_runtime_failure_context('search_attribute.create', 'POST', '/api/search-attributes');
    $createdSearchAttribute = $client->createSearchAttribute($searchAttributeName, 'keyword');
    set_runtime_failure_context('search_attribute.list', 'GET', '/api/search-attributes');
    $searchDefinitions = $client->listSearchAttributes();
    $searchWorkflowId = 'php-sdk-search-'.$suffix;
    set_runtime_failure_context('workflow.start:search', 'POST', '/api/workflows', $searchWorkflowId);
    $searchWorkflow = $client->startWorkflow(
        'php.sdk.search',
        $searchWorkflowId,
        $queue,
        [$searchAttributeName, $searchAttributeValue],
    );
    set_runtime_failure_context('workflow.result:search', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $searchWorkflow->workflowId, $searchWorkflow->selectedRunId);
    $searchResult = $searchWorkflow->result(timeoutSeconds: 30, pollIntervalSeconds: 0.2);
    set_runtime_failure_context('workflow.describe:search', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}', $searchWorkflow->workflowId, $searchWorkflow->selectedRunId);
    $searchDescription = $searchWorkflow->describeSelectedRun();
    set_runtime_failure_context('workflow.list_by_search_attribute', 'GET', '/api/workflows?query={query}');
    $searchPage = $client->listWorkflows(query: sprintf('%s = "%s"', $searchAttributeName, $searchAttributeValue));
    set_runtime_failure_context('search_attribute.delete', 'DELETE', '/api/search-attributes/{name}');
    $client->deleteSearchAttribute($searchAttributeName);

    $addressableWorkflowId = 'php-sdk-addressable-'.$suffix;
    set_runtime_failure_context('workflow.start:addressable', 'POST', '/api/workflows', $addressableWorkflowId);
    $addressable = $client->startWorkflow('php.sdk.waiting', $addressableWorkflowId, $queue);
    $addressableStartObservedAt = gmdate('Y-m-d\TH:i:s\Z');
    $addressableStartObservedEpoch = microtime(true);
    set_runtime_failure_context('workflow.history:addressable_started_contract', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', $addressable->workflowId, $addressable->selectedRunId);
    $addressableStartedHistory = $client->workflowHistory(
        $addressable->workflowId,
        (string) $addressable->selectedRunId,
    );
    file_put_contents(
        $resultDir.'/php-sdk-addressable-start-history.json',
        json_encode($addressableStartedHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
    $addressableStartedContract = php_sdk_waiting_started_contract_evidence(
        $addressableStartedHistory,
        $addressable->workflowId,
        (string) $addressable->selectedRunId,
        $addressableStartObservedAt,
        $addressableStartObservedEpoch,
    );
    $addressableStartedContract['client_commands_released_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $addressableStartedContract['client_commands_released_after_snapshot_validation'] = true;
    file_put_contents(
        $resultDir.'/php-sdk-addressable-start-contract.json',
        json_encode($addressableStartedContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );
    set_runtime_failure_context('workflow.signal:increment', 'POST', '/api/workflows/{workflow_id}/signal/increment', $addressable->workflowId, $addressable->selectedRunId);
    $addressable->signal('increment', [3]);
    set_runtime_failure_context('workflow.signal:increment', 'POST', '/api/workflows/{workflow_id}/signal/increment', $addressable->workflowId, $addressable->selectedRunId);
    $addressable->signal('increment', [5]);
    set_runtime_failure_context('workflow.signal:undeclared', 'POST', '/api/workflows/{workflow_id}/signal/undeclared', $addressable->workflowId, $addressable->selectedRunId);
    $unknownSignal = capture_signal_refusal(
        static fn () => $addressable->signal('undeclared', [1]),
        404,
        'unknown_signal',
    );
    set_runtime_failure_context('workflow.signal:increment_invalid_arguments', 'POST', '/api/workflows/{workflow_id}/signal/increment', $addressable->workflowId, $addressable->selectedRunId);
    $invalidSignalArguments = capture_signal_refusal(
        static fn () => $addressable->signal('increment', ['not-an-integer']),
        422,
        'invalid_signal_arguments',
    );
    set_runtime_failure_context('workflow.query:current', 'POST', '/api/workflows/{workflow_id}/query/current', $addressable->workflowId, $addressable->selectedRunId);
    $queryResult = $addressable->query('current');
    set_runtime_failure_context('workflow.history:addressable_signals', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', $addressable->workflowId, $addressable->selectedRunId);
    $addressableSignalHistory = $client->workflowHistory(
        $addressable->workflowId,
        (string) $addressable->selectedRunId,
    );
    $historySignalInputs = history_signal_inputs($addressableSignalHistory, $client, 'increment');
    set_runtime_failure_context('workflow.update:set', 'POST', '/api/workflows/{workflow_id}/update/set', $addressable->workflowId, $addressable->selectedRunId);
    $updateResult = $addressable->update('set', [13], waitTimeoutSeconds: 20, requestId: 'php-sdk-update-'.$suffix);
    set_runtime_failure_context('workflow.cancel', 'POST', '/api/workflows/{workflow_id}/cancel', $addressable->workflowId, $addressable->selectedRunId);
    $addressable->cancel('published SDK cancellation');
    set_runtime_failure_context('workflow.result:cancelled', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $addressable->workflowId, $addressable->selectedRunId);
    $cancelException = capture_expected_terminal_exception(
        static fn (): mixed => $addressable->result(20, 0.2),
        WorkflowCancelled::class,
    );

    $terminatedWorkflowId = 'php-sdk-terminated-'.$suffix;
    set_runtime_failure_context('workflow.start:terminated', 'POST', '/api/workflows', $terminatedWorkflowId);
    $terminated = $client->startWorkflow('php.sdk.waiting', $terminatedWorkflowId, $queue);
    set_runtime_failure_context('workflow.terminate', 'POST', '/api/workflows/{workflow_id}/terminate', $terminated->workflowId, $terminated->selectedRunId);
    $terminated->terminate('published SDK termination');
    set_runtime_failure_context('workflow.result:terminated', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $terminated->workflowId, $terminated->selectedRunId);
    $terminateException = capture_expected_terminal_exception(
        static fn (): mixed => $terminated->result(20, 0.2),
        WorkflowTerminated::class,
    );

    $failedWorkflowId = 'php-sdk-failed-'.$suffix;
    set_runtime_failure_context('workflow.start:failure', 'POST', '/api/workflows', $failedWorkflowId);
    $failed = $client->startWorkflow('php.sdk.failure', $failedWorkflowId, $queue);
    set_runtime_failure_context('workflow.result:failed', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $failed->workflowId, $failed->selectedRunId);
    $failureException = capture_expected_terminal_exception(
        static fn (): mixed => $failed->result(20, 0.2),
        WorkflowFailed::class,
    );

    $scheduleId = 'php-sdk-schedule-'.$suffix;
    set_runtime_failure_context('schedule.create', 'POST', '/api/schedules');
    $schedule = $client->createSchedule(
        new ScheduleSpec(intervals: [['every' => 'PT1H']]),
        new ScheduleAction('php.sdk.simple', $queue, [['scheduled' => true]]),
        scheduleId: $scheduleId,
        paused: true,
    );
    set_runtime_failure_context('schedule.describe', 'GET', '/api/schedules/{schedule_id}');
    $scheduleDescription = $schedule->describe();
    set_runtime_failure_context('schedule.list', 'GET', '/api/schedules');
    $schedulePage = $client->listSchedules();
    set_runtime_failure_context('schedule.pause', 'POST', '/api/schedules/{schedule_id}/pause');
    $schedule->pause('conformance pause');
    set_runtime_failure_context('schedule.resume', 'POST', '/api/schedules/{schedule_id}/resume');
    $schedule->resume('conformance resume');
    set_runtime_failure_context('schedule.delete', 'DELETE', '/api/schedules/{schedule_id}');
    $schedule->delete();

    emit(['phase' => $phase] + $namespaceProbe + [
        'search_attributes' => [
            'name' => $searchAttributeName,
            'value' => $searchAttributeValue,
            'created_name' => $createdSearchAttribute->name,
            'listed_type' => $searchDefinitions->customAttributes[$searchAttributeName] ?? null,
            'workflow_id' => $searchWorkflow->workflowId,
            'run_id' => $searchWorkflow->selectedRunId,
            'result' => $searchResult,
            'described_attributes' => $searchDescription->searchAttributes,
            'query_workflow_ids' => array_map(
                static fn ($execution): string => $execution->workflowId,
                $searchPage->executions,
            ),
            'deleted' => true,
        ],
        'signal_query' => [
            'signals_sent' => 2,
            'accepted_inputs' => [3, 5],
            'expected_total' => 8,
            'query_result' => $queryResult,
            'history_inputs' => $historySignalInputs,
            'history_event_types' => event_types($addressableSignalHistory),
            'unknown_signal' => $unknownSignal,
            'invalid_signal_arguments' => $invalidSignalArguments,
        ],
        'update' => ['request_id' => 'php-sdk-update-'.$suffix, 'result' => $updateResult],
        'worker_operation_responses' => worker_operation_responses($resultDir),
        'workflow_started_command_contract' => $addressableStartedContract,
        'cancellation' => $cancelException + ['expected_type' => WorkflowCancelled::class],
        'termination' => $terminateException + ['expected_type' => WorkflowTerminated::class],
        'failure_envelope' => $failureException + ['expected_type' => WorkflowFailed::class],
        'schedule' => [
            'schedule_id' => $scheduleId,
            'described_id' => $scheduleDescription->scheduleId,
            'listed_ids' => array_map(static fn ($item): string => $item->scheduleId, $schedulePage->schedules),
            'paused_resumed_deleted' => true,
        ],
    ]);
}

if ($phase === 'run-replay-matrix') {
    $workflowId = 'php-sdk-replay-matrix-'.$suffix;
    set_runtime_failure_context('workflow.start:replay_matrix', 'POST', '/api/workflows', $workflowId);
    $handle = $client->startWorkflow('php.sdk.replay-matrix', $workflowId, $queue);
    set_runtime_failure_context('workflow.update:replay_matrix', 'POST', '/api/workflows/{workflow_id}/update/set', $handle->workflowId, $handle->selectedRunId);
    $update = $handle->updateSelectedRun(
        'set',
        [19],
        waitTimeoutSeconds: 30,
        requestId: 'php-sdk-replay-matrix-'.$suffix,
    );
    set_runtime_failure_context('workflow.signal:replay_matrix', 'POST', '/api/workflows/{workflow_id}/signal/release', $handle->workflowId, $handle->selectedRunId);
    $handle->signalSelectedRun('release', [7]);
    set_runtime_failure_context('workflow.result:replay_matrix', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', $handle->workflowId, $handle->selectedRunId);
    $result = $handle->resultOfSelectedRun(timeoutSeconds: 60, pollIntervalSeconds: 0.2);
    set_runtime_failure_context('workflow.history:replay_matrix', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', $handle->workflowId, $handle->selectedRunId);
    $history = $client->workflowHistory($handle->workflowId, (string) $handle->selectedRunId);
    $divergence = [
        'observed_outcome' => 'accepted',
        'workflow_sequence' => null,
        'expected_shape' => null,
        'actual_shape' => null,
        'recorded_event_types' => event_types($history),
        'message' => 'Published PHP SDK replay unexpectedly accepted divergent workflow code.',
    ];
    try {
        (new Replayer($client->payloadCodec()))->replay(
            static function (WorkflowContext $context): array {
                $context->activity('php.sdk.divergent-activity', [['matrix' => 'activity']]);

                return ['unexpected' => 'divergence-accepted'];
            },
            history_events($history),
            [],
            $queue,
            ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId],
        );
    } catch (NonDeterministicWorkflow $exception) {
        $divergence = [
            'observed_outcome' => 'non_determinism_error',
            'exception_type' => $exception::class,
            'workflow_sequence' => $exception->sequence,
            'expected_shape' => $exception->expected,
            'actual_shape' => $exception->actual,
            'recorded_event_types' => event_types($history),
            'message' => $exception->getMessage(),
        ];
    }
    $state = [
        'workflow_id' => $handle->workflowId,
        'run_id' => $handle->selectedRunId,
        'completed_at' => replay_timestamp(),
        'result' => $result,
        'update_result' => $update,
        'history_event_types' => event_types($history),
        'history_event_counts' => event_counts($history),
        'divergence' => $divergence,
        'identity' => $identity,
    ];
    file_put_contents($matrixStateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    emit(['phase' => $phase] + $state);
}

if ($phase === 'start-in-flight') {
    $workflowId = 'php-sdk-in-flight-'.$suffix;
    set_runtime_failure_context('workflow.start:in_flight_signal', 'POST', '/api/workflows', $workflowId);
    $handle = $client->startWorkflow('php.sdk.replay-in-flight-signal', $workflowId, $queue);
    $deadline = microtime(true) + 30;
    $history = [];
    do {
        set_runtime_failure_context('workflow.history:in_flight_checkpoint', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', $handle->workflowId, $handle->selectedRunId);
        $history = $client->workflowHistory($handle->workflowId, (string) $handle->selectedRunId);
        $counts = event_counts($history);
        if (($counts['TimerScheduled'] ?? 0) > ($counts['TimerFired'] ?? 0)) {
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    $counts = event_counts($history);
    if (($counts['TimerScheduled'] ?? 0) <= ($counts['TimerFired'] ?? 0)) {
        throw new RuntimeException('In-flight signal replay did not reach an unresolved timer checkpoint within 30 seconds.');
    }
    $signalSentAt = replay_timestamp();
    set_runtime_failure_context('workflow.signal:in_flight_advance', 'POST', '/api/workflows/{workflow_id}/signal/advance', $handle->workflowId, $handle->selectedRunId);
    $handle->signalSelectedRun('advance', [23]);
    set_runtime_failure_context('workflow.history:in_flight_signal', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', $handle->workflowId, $handle->selectedRunId);
    $historyAfterSignal = $client->workflowHistory($handle->workflowId, (string) $handle->selectedRunId);
    $state = [
        'workflow_id' => $handle->workflowId,
        'run_id' => $handle->selectedRunId,
        'signal_sent_at' => $signalSentAt,
        'signal_inputs' => [23],
        'checkpoint_event_counts' => $counts,
        'history_after_signal_event_counts' => event_counts($historyAfterSignal),
        'identity' => $identity,
    ];
    file_put_contents($inFlightStateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    emit(['phase' => $phase] + $state);
}

if ($phase === 'finish-replay-matrix') {
    $matrix = json_decode((string) file_get_contents($matrixStateFile), true, flags: JSON_THROW_ON_ERROR);
    $inFlight = json_decode((string) file_get_contents($inFlightStateFile), true, flags: JSON_THROW_ON_ERROR);
    $matrixHandle = $client->workflowHandle((string) $matrix['workflow_id'], (string) $matrix['run_id']);
    set_runtime_failure_context('workflow.query:completed_replay_matrix', 'POST', '/api/workflows/{workflow_id}/query/state', (string) $matrix['workflow_id'], (string) $matrix['run_id']);
    $completedQuery = $matrixHandle->querySelectedRun('state');
    $inFlightHandle = $client->workflowHandle((string) $inFlight['workflow_id'], (string) $inFlight['run_id']);
    set_runtime_failure_context('workflow.result:in_flight_signal', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', (string) $inFlight['workflow_id'], (string) $inFlight['run_id']);
    $inFlightResult = $inFlightHandle->resultOfSelectedRun(timeoutSeconds: 60, pollIntervalSeconds: 0.2);
    set_runtime_failure_context('workflow.history:in_flight_complete', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', (string) $inFlight['workflow_id'], (string) $inFlight['run_id']);
    $inFlightHistory = $client->workflowHistory((string) $inFlight['workflow_id'], (string) $inFlight['run_id']);
    $workerTiming = json_decode(
        (string) file_get_contents($resultDir.'/php-sdk-in-flight-worker-timing.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $restartTiming = json_decode(
        (string) file_get_contents($resultDir.'/php-sdk-worker-restart-timing.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    emit([
        'phase' => $phase,
        'identity' => $identity,
        'completed_query' => $completedQuery,
        'matrix_result' => $matrix['result'] ?? null,
        'matrix_history_event_counts' => $matrix['history_event_counts'] ?? [],
        'in_flight' => [
            'workflow_id' => $inFlight['workflow_id'],
            'run_id' => $inFlight['run_id'],
            'signal_sent_at' => $inFlight['signal_sent_at'],
            'worker_restart_at' => $restartTiming['worker_restart_at'] ?? null,
            'history_reloaded_at' => $workerTiming['history_reloaded_at'] ?? null,
            'replayed_next_decision' => $workerTiming['replayed_next_decision'] ?? null,
            'replay_worker_id' => $workerTiming['worker_id'] ?? null,
            'replay_worker_process_id' => $workerTiming['worker_process_id'] ?? null,
            'signal_inputs' => $workerTiming['signal_inputs'] ?? [],
            'result' => $inFlightResult,
            'history_event_counts' => event_counts($inFlightHistory),
            'observed_outcome' => 'same_next_decision_after_replay',
        ],
    ]);
}

if ($phase === 'start-replay') {
    $replayWorkflowId = 'php-sdk-replay-'.$suffix;
    set_runtime_failure_context('workflow.start:replay', 'POST', '/api/workflows', $replayWorkflowId);
    $handle = $client->startWorkflow(
        'php.sdk.replay',
        $replayWorkflowId,
        $queue,
        [['replay' => true]],
    );
    $state = ['workflow_id' => $handle->workflowId, 'run_id' => $handle->selectedRunId];
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    emit(['phase' => $phase, 'identity' => $identity] + $state);
}

if ($phase === 'wait-replay-checkpoint') {
    $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
    $deadline = microtime(true) + 30;
    do {
        set_runtime_failure_context('workflow.history:replay_checkpoint', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', (string) $state['workflow_id'], (string) $state['run_id']);
        $history = $client->workflowHistory((string) $state['workflow_id'], (string) $state['run_id']);
        $types = event_types($history);
        if (in_array('ActivityCompleted', $types, true) && in_array('TimerScheduled', $types, true)) {
            emit([
                'phase' => $phase,
                'identity' => $identity,
                'workflow_id' => $state['workflow_id'],
                'run_id' => $state['run_id'],
                'history_event_types' => $types,
                'history_event_counts' => event_counts($history),
                'activity_completed_before_restart' => true,
                'timer_scheduled_before_restart' => true,
            ]);
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Replay checkpoint did not contain ActivityCompleted and TimerScheduled within 30 seconds.');
}

if ($phase === 'finish-replay') {
    $state = json_decode((string) file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
    $handle = $client->workflowHandle((string) $state['workflow_id'], (string) $state['run_id']);
    set_runtime_failure_context('workflow.result:replay', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/result', (string) $state['workflow_id'], (string) $state['run_id']);
    $result = $handle->resultOfSelectedRun(timeoutSeconds: 40, pollIntervalSeconds: 0.2);
    set_runtime_failure_context('workflow.history:replay', 'GET', '/api/workflows/{workflow_id}/runs/{run_id}/history', (string) $state['workflow_id'], (string) $state['run_id']);
    $history = $client->workflowHistory((string) $state['workflow_id'], (string) $state['run_id']);
    emit([
        'phase' => $phase,
        'identity' => $identity,
        'workflow_id' => $state['workflow_id'],
        'run_id' => $state['run_id'],
        'result' => $result,
        'history_event_types' => event_types($history),
        'history_event_counts' => event_counts($history),
    ]);
}

throw new InvalidArgumentException("Unknown client phase: {$phase}");
PHP

cat > "$project_dir/aggregate.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/started-contract.php';
require __DIR__.'/assertion-failure-evidence.php';
require __DIR__.'/activity-callback-cardinality.php';

if ($argc < 8) {
    fwrite(STDERR, "usage: aggregate.php <result-dir> <sdk-version> <server-version> <server-image> <server-url> <namespace> <started-at>\n");
    exit(2);
}

[$script, $resultDir, $expectedSdkVersion, $serverVersion, $serverImage, $serverUrl, $namespace, $startedAt] = $argv;

function read_json(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($value)) {
        throw new RuntimeException("{$path} did not contain a JSON object.");
    }

    return $value;
}

function package_from_lock(array $lock, string $name): array
{
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
        if (is_array($package) && ($package['name'] ?? null) === $name) {
            return $package;
        }
    }
    throw new RuntimeException("composer.lock does not contain {$name}.");
}

function normalized_version(?string $version): string
{
    return ltrim((string) $version, 'v');
}

/**
 * @param array<string, mixed> $observed
 * @param list<array<string, mixed>> $findings
 * @param array<string, mixed> $diagnostics
 * @return array<string, mixed>
 */
function replay_runtime_scenario(
    string $scenarioId,
    bool $passed,
    string $cellId,
    array $observed,
    array $findings,
    array $diagnostics = [],
): array {
    $scenario = [
        'scenario_id' => $scenarioId,
        'status' => $passed ? 'pass' : 'fail',
        'executed_runtime_cell' => true,
        'runtime_cell' => [
            'cell_id' => $cellId,
            'executed' => true,
            'artifact_source' => 'packagist',
            'server_source' => 'public_image',
        ],
        'observed_outputs' => ['runtime_cell_executed' => true, 'cell_id' => $cellId] + $observed,
        'linked_findings' => $passed ? [] : $findings,
    ];
    if ($diagnostics !== []) {
        $scenario['replay_diagnostics'] = $diagnostics;
    }

    return $scenario;
}

$baseline = read_json($resultDir.'/php-sdk-client-baseline.json');
$replayStart = read_json($resultDir.'/php-sdk-client-start-replay.json');
$checkpoint = read_json($resultDir.'/php-sdk-client-replay-checkpoint.json');
$replayFinish = read_json($resultDir.'/php-sdk-client-finish-replay.json');
$callbacks = read_json($resultDir.'/php-sdk-callback-counts.json');
$activityCallbacks = read_json($resultDir.'/php-sdk-activity-callbacks.json');
$signalReplay = read_json($resultDir.'/php-sdk-waiting-signal-replay.json');
$workerOne = read_json($resultDir.'/php-sdk-worker-php-sdk-worker-1.json');
$workerTwo = read_json($resultDir.'/php-sdk-worker-php-sdk-worker-2.json');
$lock = read_json($resultDir.'/php-sdk-project/composer.lock');
$composerProject = read_json($resultDir.'/php-sdk-project/composer.json');
$sdk = package_from_lock($lock, 'durable-workflow/sdk');
$avro = package_from_lock($lock, 'apache/avro');
$history = $replayFinish['history_event_types'] ?? [];
$clusterVersion = (string) ($baseline['cluster_info']['version'] ?? $baseline['cluster_info']['server_version'] ?? '');
$requiredWaitingContract = php_sdk_waiting_command_contract();
$startedContractEvidence = is_array($baseline['workflow_started_command_contract'] ?? null)
    ? $baseline['workflow_started_command_contract']
    : [];
$startedEvent = is_array($startedContractEvidence['workflow_started_event'] ?? null)
    ? $startedContractEvidence['workflow_started_event']
    : [];
$replayMatrixEnabled = getenv('DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX') === '1';
$matrixStart = $replayMatrixEnabled
    ? read_json($resultDir.'/php-sdk-client-replay-matrix.json')
    : [];
$matrixRestart = $replayMatrixEnabled
    ? read_json($resultDir.'/php-sdk-client-replay-matrix-restart.json')
    : [];
$matrixResult = is_array($matrixStart['result'] ?? null) ? $matrixStart['result'] : [];
$matrixCounts = is_array($matrixStart['history_event_counts'] ?? null) ? $matrixStart['history_event_counts'] : [];
$matrixQuery = is_array($matrixRestart['completed_query'] ?? null) ? $matrixRestart['completed_query'] : [];
$matrixQueryCounts = is_array($matrixQuery['event_counts'] ?? null) ? $matrixQuery['event_counts'] : [];
$divergence = is_array($matrixStart['divergence'] ?? null) ? $matrixStart['divergence'] : [];
$inFlight = is_array($matrixRestart['in_flight'] ?? null) ? $matrixRestart['in_flight'] : [];
$activityHistoryEventCounts = [
    'initial_execution' => [
        'completed' => is_array($baseline['simple_workflow']['history_event_counts'] ?? null)
            ? $baseline['simple_workflow']['history_event_counts']
            : [],
    ],
    'durable_replay' => [
        'before_worker_restart' => is_array($checkpoint['history_event_counts'] ?? null)
            ? $checkpoint['history_event_counts']
            : [],
        'after_worker_restart' => is_array($replayFinish['history_event_counts'] ?? null)
            ? $replayFinish['history_event_counts']
            : [],
    ],
];
if ($replayMatrixEnabled) {
    $activityHistoryEventCounts += [
        'replay_matrix' => [
            'completed' => $matrixCounts,
            'after_worker_restart' => $matrixQueryCounts,
        ],
        'in_flight_replay' => [
            'after_worker_restart' => is_array($inFlight['history_event_counts'] ?? null)
                ? $inFlight['history_event_counts']
                : [],
        ],
    ];
}
$activityCallbackCardinality = php_sdk_activity_callback_cardinality(
    $activityCallbacks,
    $activityHistoryEventCounts,
    $replayMatrixEnabled,
);
$expectedActivityCallbackTotal = array_sum($activityCallbackCardinality['expected_callback_counts_by_phase']);

$assertions = [
    'exact_sdk_version' => normalized_version($sdk['version'] ?? null) === normalized_version($expectedSdkVersion),
    'exact_server_version' => $clusterVersion !== '' && normalized_version($clusterVersion) === normalized_version($serverVersion),
    'sdk_dist_provenance' => isset($sdk['dist']['type'], $sdk['dist']['url']) && $sdk['dist']['type'] !== 'path',
    'official_apache_avro_dependency' => ($avro['name'] ?? null) === 'apache/avro'
        && isset($avro['dist']['type'], $avro['dist']['url'], $avro['source']['url'])
        && str_contains((string) $avro['source']['url'], 'apache/avro'),
    'source_free_composer_project' => ! isset($composerProject['repositories'])
        && array_reduce(
            array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []),
            static fn (bool $valid, array $package): bool => $valid
                && isset($package['dist']['type'], $package['dist']['url'])
                && ($package['dist']['type'] ?? null) !== 'path'
                && filter_var((string) ($package['dist']['url'] ?? ''), FILTER_VALIDATE_URL) !== false,
            true,
        ),
    'distinct_client_worker_processes' => ($baseline['identity']['process_id'] ?? null) !== ($workerOne['process_id'] ?? null),
    'distinct_worker_restart_processes' => ($workerOne['process_id'] ?? null) !== ($workerTwo['process_id'] ?? null),
    'worker_registration' => ($workerOne['worker_id'] ?? null) === 'php-sdk-worker-1'
        && ($workerTwo['worker_id'] ?? null) === 'php-sdk-worker-2'
        && is_array($workerOne['server_visible_registration'] ?? null)
        && is_array($workerTwo['server_visible_registration'] ?? null),
    'worker_heartbeat' => isset($workerOne['server_visible_registration']['last_heartbeat_at'])
        && isset($workerTwo['server_visible_registration']['last_heartbeat_at']),
    'worker_command_contract_readiness' => ($workerOne['readiness']['client_release_after_authoritative_registration'] ?? false)
        && ($workerTwo['readiness']['client_release_after_authoritative_registration'] ?? false)
        && ($workerOne['readiness']['required_workflow_command_contract'] ?? null) === $requiredWaitingContract
        && ($workerTwo['readiness']['required_workflow_command_contract'] ?? null) === $requiredWaitingContract
        && php_sdk_command_contract_matches(
            $workerOne['server_visible_registration']['workflow_command_contracts']['php.sdk.waiting'] ?? null,
            $requiredWaitingContract,
        )
        && php_sdk_command_contract_matches(
            $workerTwo['server_visible_registration']['workflow_command_contracts']['php.sdk.waiting'] ?? null,
            $requiredWaitingContract,
        ),
    'workflow_started_command_contract' => ($startedContractEvidence['command_contract_source'] ?? null) === 'durable_history'
        && ($startedContractEvidence['history_reads'] ?? null) === 1
        && ($startedContractEvidence['validated_before_client_commands'] ?? false)
        && ($startedContractEvidence['client_commands_released_after_snapshot_validation'] ?? false)
        && ($startedContractEvidence['required_workflow_command_contract'] ?? null) === $requiredWaitingContract
        && ($startedEvent['event_type'] ?? $startedEvent['type'] ?? null) === 'WorkflowStarted'
        && php_sdk_started_payload_matches($startedEvent['payload'] ?? null, $requiredWaitingContract),
    'start_result' => ($baseline['simple_workflow']['status'] ?? null) === 'completed' && isset($baseline['simple_workflow']['result']),
    'signal_query' => ($baseline['signal_query']['signals_sent'] ?? null) === 2
        && ($baseline['signal_query']['accepted_inputs'] ?? null) === [3, 5]
        && ($baseline['signal_query']['query_result']['inputs'] ?? null) === [3, 5]
        && ($baseline['signal_query']['query_result']['total'] ?? null) === 8
        && ($baseline['signal_query']['history_inputs'] ?? null) === [3, 5],
    'signal_replay_visibility' => ($signalReplay['signal_name'] ?? null) === 'increment'
        && ($signalReplay['inputs'] ?? null) === [3, 5]
        && ($signalReplay['total'] ?? null) === 8
        && ($signalReplay['observed_during_workflow_replay'] ?? false),
    'signal_negative_contracts' => ($baseline['signal_query']['unknown_signal']['status_code'] ?? null) === 404
        && ($baseline['signal_query']['unknown_signal']['reason'] ?? null) === 'unknown_signal'
        && ($baseline['signal_query']['invalid_signal_arguments']['status_code'] ?? null) === 422
        && ($baseline['signal_query']['invalid_signal_arguments']['reason'] ?? null) === 'invalid_signal_arguments'
        && ($baseline['signal_query']['history_inputs'] ?? null) === [3, 5],
    'update' => ($baseline['update']['result']['accepted'] ?? null) === true && ($baseline['update']['result']['value'] ?? null) === 13,
    'cancellation' => ($baseline['cancellation']['type'] ?? null) === ($baseline['cancellation']['expected_type'] ?? null),
    'termination' => ($baseline['termination']['type'] ?? null) === ($baseline['termination']['expected_type'] ?? null),
    'failure_envelope' => ($baseline['failure_envelope']['type'] ?? null) === ($baseline['failure_envelope']['expected_type'] ?? null),
    'activity_callback_once_for_replay' => ($activityCallbackCardinality['phase_results']['durable_replay']['passed'] ?? false),
    'activity_callback_cardinality_by_phase' => ($activityCallbackCardinality['passed'] ?? false),
    'activity_heartbeat_callback' => (int) ($callbacks['activity_heartbeat'] ?? 0) === $expectedActivityCallbackTotal
        && (int) ($callbacks['activity'] ?? 0) === $expectedActivityCallbackTotal,
    'namespace_lifecycle' => ($baseline['namespace_lifecycle']['created'] ?? false)
        && ($baseline['namespace_lifecycle']['updated'] ?? false)
        && ($baseline['namespace_lifecycle']['deleted'] ?? false),
    'namespace_selection' => ($baseline['namespace_lifecycle']['selected_namespace'] ?? null) !== null
        && ($baseline['namespace_lifecycle']['selected_namespace_workflow_count'] ?? null) === 0,
    'search_attributes' => ($baseline['search_attributes']['created_name'] ?? null) === ($baseline['search_attributes']['name'] ?? null)
        && ($baseline['search_attributes']['listed_type'] ?? null) === 'keyword'
        && ($baseline['search_attributes']['result']['search_attribute'] ?? null) === ($baseline['search_attributes']['name'] ?? null)
        && ($baseline['search_attributes']['described_attributes'][$baseline['search_attributes']['name'] ?? ''] ?? null) === ($baseline['search_attributes']['value'] ?? null)
        && in_array($baseline['search_attributes']['workflow_id'] ?? null, $baseline['search_attributes']['query_workflow_ids'] ?? [], true)
        && ($baseline['search_attributes']['deleted'] ?? false),
    'schedule_lifecycle' => ($baseline['schedule']['paused_resumed_deleted'] ?? false)
        && in_array($baseline['schedule']['schedule_id'] ?? null, $baseline['schedule']['listed_ids'] ?? [], true),
    'replay_checkpoint' => ($checkpoint['activity_completed_before_restart'] ?? false)
        && ($checkpoint['timer_scheduled_before_restart'] ?? false),
    'durable_replay_history' => in_array('ActivityCompleted', $history, true)
        && in_array('TimerScheduled', $history, true)
        && in_array('TimerFired', $history, true),
    'durable_replay_result' => isset($replayFinish['result']['replayed_result']),
    'local_product_source_checkouts_used_false' => true,
];
if ($replayMatrixEnabled) {
    $assertions += [
        'replay_matrix_completed_history' => ($matrixResult['activity']['echo']['matrix'] ?? null) === 'activity'
            && ($matrixResult['signals'] ?? null) === [7]
            && ($matrixResult['updates'] ?? null) === [19]
            && (int) ($matrixResult['wait_cycles'] ?? 0) >= 1
            && ($matrixResult['version_marker'] ?? null) === ['change_id' => 'php-replay-matrix', 'version' => 1]
            && ($matrixResult['compensation']['echo']['matrix'] ?? null) === 'compensation'
            && ($matrixResult['after_signal']['echo']['matrix'] ?? null) === 'after-signal',
        'replay_matrix_activity_history' => (int) ($matrixCounts['ActivityCompleted'] ?? 0) === 3,
        'replay_matrix_signal_update_history' => (int) ($matrixCounts['SignalReceived'] ?? 0) >= 1
            && (int) ($matrixCounts['UpdateAccepted'] ?? 0) >= 1
            && (int) ($matrixCounts['UpdateApplied'] ?? 0) >= 1,
        'replay_matrix_wait_condition_history' => (int) ($matrixCounts['TimerScheduled'] ?? 0) >= 1
            && (int) ($matrixCounts['TimerFired'] ?? 0) >= 1,
        'replay_matrix_version_marker_history' => (int) ($matrixCounts['SideEffectRecorded'] ?? 0) === 1
            && (int) ($callbacks['replay_matrix_version_marker_callbacks'] ?? 0) === 1,
        'replay_matrix_saga_compensation_history' => (int) ($matrixCounts['ActivityFailed'] ?? 0) === 1
            && (int) ($callbacks['replay_matrix_failed_activity'] ?? 0) === 1,
        'replay_matrix_completed_query_after_restart' => ($matrixRestart['matrix_result'] ?? null) === $matrixResult
            && ($matrixRestart['identity']['process_id'] ?? null) !== ($matrixStart['identity']['process_id'] ?? null)
            && ($matrixQuery['query_process_id'] ?? null) === ($workerTwo['process_id'] ?? null)
            && ($matrixQuery['query_process_id'] ?? null) !== ($workerOne['process_id'] ?? null),
        'replay_matrix_activity_state_after_restart' => (int) ($matrixQueryCounts['ActivityCompleted'] ?? 0) === 3
            && ($matrixQuery['activity_types'] ?? null) === [
                'php.sdk.echo',
                'php.sdk.replay-matrix-fail',
                'php.sdk.echo',
                'php.sdk.echo',
            ],
        'replay_matrix_signal_update_state_after_restart' => ($matrixQuery['signals'] ?? null) === [7]
            && ($matrixQuery['updates'] ?? null) === [19],
        'replay_matrix_wait_condition_state_after_restart' => (int) ($matrixQueryCounts['TimerScheduled'] ?? 0) >= 1
            && (int) ($matrixQueryCounts['TimerFired'] ?? 0) >= 1,
        'replay_matrix_version_marker_state_after_restart' => (int) ($matrixQueryCounts['SideEffectRecorded'] ?? 0) === 1
            && (int) ($callbacks['replay_matrix_version_marker_callbacks'] ?? 0) === 1,
        'replay_matrix_saga_compensation_state_after_restart' => (int) ($matrixQueryCounts['ActivityFailed'] ?? 0) === 1
            && (int) ($matrixQueryCounts['ActivityCompleted'] ?? 0) === 3,
        'replay_matrix_code_divergence_refusal' => ($divergence['observed_outcome'] ?? null) === 'non_determinism_error'
            && is_int($divergence['workflow_sequence'] ?? null)
            && is_string($divergence['expected_shape'] ?? null)
            && ($divergence['expected_shape'] ?? '') !== ''
            && is_array($divergence['recorded_event_types'] ?? null)
            && ($divergence['recorded_event_types'] ?? []) !== []
            && is_string($divergence['message'] ?? null)
            && ($divergence['message'] ?? '') !== '',
        'replay_matrix_in_flight_signal_restart' => ($inFlight['observed_outcome'] ?? null) === 'same_next_decision_after_replay'
            && ($inFlight['signal_inputs'] ?? null) === [23]
            && ($inFlight['result']['signals'] ?? null) === [23]
            && ($inFlight['result']['next_decision_result']['echo']['matrix'] ?? null) === 'in-flight-after-signal'
            && ($inFlight['replayed_next_decision'] ?? null) === 'schedule_activity:php.sdk.echo'
            && ($inFlight['replay_worker_id'] ?? null) === 'php-sdk-worker-2'
            && ($inFlight['replay_worker_process_id'] ?? null) === ($workerTwo['process_id'] ?? null)
            && is_string($inFlight['signal_sent_at'] ?? null)
            && is_string($inFlight['worker_restart_at'] ?? null)
            && is_string($inFlight['history_reloaded_at'] ?? null)
            && $inFlight['signal_sent_at'] <= $inFlight['worker_restart_at']
            && $inFlight['worker_restart_at'] <= $inFlight['history_reloaded_at']
            && (int) ($inFlight['history_event_counts']['SignalReceived'] ?? 0) >= 1
            && (int) ($inFlight['history_event_counts']['ActivityCompleted'] ?? 0) >= 1,
    ];
}
$failedAssertions = array_keys(array_filter($assertions, static fn (bool $value): bool => ! $value));
$assertionDomains = [
    'exact_sdk_version' => 'package-publication',
    'sdk_dist_provenance' => 'package-publication',
    'official_apache_avro_dependency' => 'package-publication',
    'source_free_composer_project' => 'package-publication',
    'exact_server_version' => 'server',
    'distinct_client_worker_processes' => 'runner',
    'distinct_worker_restart_processes' => 'runner',
    'worker_registration' => 'sdk',
    'worker_heartbeat' => 'sdk',
    'worker_command_contract_readiness' => 'runner',
    'workflow_started_command_contract' => 'server',
    'start_result' => 'server',
    'signal_query' => 'server',
    'signal_replay_visibility' => 'sdk',
    'signal_negative_contracts' => 'server',
    'update' => php_sdk_update_assertion_domain($baseline),
    'cancellation' => 'sdk',
    'termination' => 'sdk',
    'failure_envelope' => 'sdk',
    'activity_callback_once_for_replay' => 'server',
    'activity_callback_cardinality_by_phase' => 'server',
    'activity_heartbeat_callback' => 'sdk',
    'namespace_lifecycle' => 'server',
    'namespace_selection' => 'sdk',
    'search_attributes' => 'sdk',
    'schedule_lifecycle' => 'server',
    'replay_checkpoint' => 'server',
    'durable_replay_history' => 'server',
    'durable_replay_result' => 'sdk',
    'replay_matrix_completed_history' => 'sdk',
    'replay_matrix_activity_history' => 'sdk',
    'replay_matrix_signal_update_history' => 'sdk',
    'replay_matrix_wait_condition_history' => 'sdk',
    'replay_matrix_version_marker_history' => 'sdk',
    'replay_matrix_saga_compensation_history' => 'sdk',
    'replay_matrix_completed_query_after_restart' => 'sdk',
    'replay_matrix_activity_state_after_restart' => 'sdk',
    'replay_matrix_signal_update_state_after_restart' => 'sdk',
    'replay_matrix_wait_condition_state_after_restart' => 'sdk',
    'replay_matrix_version_marker_state_after_restart' => 'sdk',
    'replay_matrix_saga_compensation_state_after_restart' => 'sdk',
    'replay_matrix_code_divergence_refusal' => 'sdk',
    'replay_matrix_in_flight_signal_restart' => 'sdk',
    'local_product_source_checkouts_used_false' => 'runner',
];
$failedByDomain = [];
foreach ($failedAssertions as $assertion) {
    $domain = $assertionDomains[$assertion] ?? 'sdk';
    $failedByDomain[$domain][] = $assertion;
}
$assertionFailures = php_sdk_assertion_failure_evidence(
    $failedAssertions,
    $assertionDomains,
    $baseline,
    $activityCallbackCardinality,
);
$runnerBlocked = $failedAssertions !== [] && array_keys($failedByDomain) === ['runner'];
$status = $failedAssertions === [] ? 'pass' : ($runnerBlocked ? 'runner_blocked' : 'fail');
$coveredCells = [
    'start_result', 'signal_query', 'update', 'cancellation', 'termination', 'activities',
    'namespaces', 'search_attributes', 'schedules', 'workflow_lifecycle', 'failure_envelopes', 'heartbeat',
    'worker_restart', 'durable_replay',
];
$domainPolicy = [
    'sdk' => ['owner' => 'sdk-php', 'type' => 'product_behavior_gap'],
    'server' => ['owner' => 'server', 'type' => 'product_behavior_gap'],
    'package-publication' => ['owner' => 'sdk-php-release', 'type' => 'package_publication_gap'],
    'runner' => ['owner' => 'conformance_harness', 'type' => 'conformance_runner_blocked'],
];
$findings = [];
foreach ($failedByDomain as $domain => $domainAssertions) {
    $policy = $domainPolicy[$domain];
    $domainAssertionFailures = array_values(array_filter(
        $assertionFailures,
        static fn (array $failure): bool => ($failure['classification'] ?? null) === $domain,
    ));
    $findings[] = [
        'finding_id' => 'php-sdk-published-artifact-'.str_replace('_', '-', $domain).'-failure',
        'finding_type' => $policy['type'],
        'classification' => $domain,
        'owning_surface' => $policy['owner'],
        'failure_stage' => 'runtime_assertions',
        'failed_assertions' => $domainAssertions,
        'summary' => sprintf('Failed %s assertions: %s', $domain, implode(', ', $domainAssertions)),
        'observed_evidence' => ['assertion_failures' => $domainAssertionFailures],
        'next_acceptance_criterion' => 'Correct the named failure surface and rerun the exact Packagist SDK against the exact public server image.',
    ];
}
$replayScenarioResults = [];
if ($replayMatrixEnabled) {
    $completedIdentity = [
        'workflow_id' => $matrixStart['workflow_id'] ?? null,
        'run_id' => $matrixStart['run_id'] ?? null,
        'worker_id' => 'php-sdk-worker-1',
        'worker_process_id' => $workerOne['process_id'] ?? null,
        'evidence_file' => 'php-sdk-client-replay-matrix.json',
    ];
    $restartIdentity = [
        'workflow_id' => $matrixStart['workflow_id'] ?? null,
        'run_id' => $matrixStart['run_id'] ?? null,
        'worker_id' => 'php-sdk-worker-2',
        'worker_process_id' => $workerTwo['process_id'] ?? null,
        'evidence_file' => 'php-sdk-client-replay-matrix-restart.json',
    ];
    $replayScenarioResults['php_completed_history_activity_replay'] = replay_runtime_scenario(
        'php_completed_history_activity_replay',
        $assertions['replay_matrix_completed_history']
            && $assertions['replay_matrix_activity_history']
            && $assertions['activity_callback_once_for_replay']
            && $assertions['activity_callback_cardinality_by_phase'],
        'php-completed-history-replay-matrix',
        $completedIdentity + [
            'activity_result' => $matrixResult['activity'] ?? null,
            'activity_completed_events' => $matrixCounts['ActivityCompleted'] ?? 0,
            'activity_callback_cardinality' => $activityCallbackCardinality,
        ],
        $findings,
    );
    $replayScenarioResults['php_completed_history_signal_update_replay'] = replay_runtime_scenario(
        'php_completed_history_signal_update_replay',
        $assertions['replay_matrix_completed_history'] && $assertions['replay_matrix_signal_update_history'],
        'php-completed-history-replay-matrix',
        $completedIdentity + [
            'signals' => $matrixResult['signals'] ?? null,
            'updates' => $matrixResult['updates'] ?? null,
            'history_event_counts' => $matrixCounts,
        ],
        $findings,
    );
    $replayScenarioResults['php_completed_history_wait_condition_replay'] = replay_runtime_scenario(
        'php_completed_history_wait_condition_replay',
        $assertions['replay_matrix_completed_history'] && $assertions['replay_matrix_wait_condition_history'],
        'php-completed-history-replay-matrix',
        $completedIdentity + [
            'wait_cycles' => $matrixResult['wait_cycles'] ?? null,
            'timer_scheduled_events' => $matrixCounts['TimerScheduled'] ?? 0,
            'timer_fired_events' => $matrixCounts['TimerFired'] ?? 0,
        ],
        $findings,
    );
    $replayScenarioResults['php_completed_history_version_marker_replay'] = replay_runtime_scenario(
        'php_completed_history_version_marker_replay',
        $assertions['replay_matrix_completed_history'] && $assertions['replay_matrix_version_marker_history'],
        'php-completed-history-replay-matrix',
        $completedIdentity + [
            'version_marker' => $matrixResult['version_marker'] ?? null,
            'side_effect_recorded_events' => $matrixCounts['SideEffectRecorded'] ?? 0,
            'version_marker_callback_count' => $callbacks['replay_matrix_version_marker_callbacks'] ?? 0,
        ],
        $findings,
    );
    $replayScenarioResults['php_completed_history_saga_compensation_replay'] = replay_runtime_scenario(
        'php_completed_history_saga_compensation_replay',
        $assertions['replay_matrix_completed_history'] && $assertions['replay_matrix_saga_compensation_history'],
        'php-completed-history-replay-matrix',
        $completedIdentity + [
            'compensation_result' => $matrixResult['compensation'] ?? null,
            'activity_failed_events' => $matrixCounts['ActivityFailed'] ?? 0,
            'failed_activity_callback_count' => $callbacks['replay_matrix_failed_activity'] ?? 0,
        ],
        $findings,
    );
    $replayScenarioResults['php_worker_restart_completed_query'] = replay_runtime_scenario(
        'php_worker_restart_completed_query',
        $assertions['replay_matrix_completed_query_after_restart'],
        'php-completed-query-after-worker-restart',
        $restartIdentity + [
            'original_result' => $matrixResult,
            'completed_query' => $matrixQuery,
            'query_process_matches_restarted_worker' => ($matrixQuery['query_process_id'] ?? null) === ($workerTwo['process_id'] ?? null),
        ],
        $findings,
    );
    $replayScenarioResults['php_worker_restart_activity_state'] = replay_runtime_scenario(
        'php_worker_restart_activity_state',
        $assertions['replay_matrix_activity_state_after_restart']
            && $assertions['activity_callback_once_for_replay']
            && $assertions['activity_callback_cardinality_by_phase'],
        'php-completed-query-after-worker-restart',
        $restartIdentity + [
            'activity_result' => $matrixResult['activity'] ?? null,
            'activity_types_from_reloaded_history' => $matrixQuery['activity_types'] ?? [],
            'activity_completed_events' => $matrixQueryCounts['ActivityCompleted'] ?? 0,
            'activity_callback_cardinality' => $activityCallbackCardinality,
        ],
        $findings,
    );
    $replayScenarioResults['php_worker_restart_signal_update_state'] = replay_runtime_scenario(
        'php_worker_restart_signal_update_state',
        $assertions['replay_matrix_signal_update_state_after_restart'],
        'php-completed-query-after-worker-restart',
        $restartIdentity + [
            'signals_from_reloaded_history' => $matrixQuery['signals'] ?? [],
            'updates_from_reloaded_history' => $matrixQuery['updates'] ?? [],
        ],
        $findings,
    );
    $replayScenarioResults['php_worker_restart_wait_condition_state'] = replay_runtime_scenario(
        'php_worker_restart_wait_condition_state',
        $assertions['replay_matrix_wait_condition_state_after_restart'],
        'php-completed-query-after-worker-restart',
        $restartIdentity + [
            'wait_cycles' => $matrixResult['wait_cycles'] ?? null,
            'timer_scheduled_events' => $matrixQueryCounts['TimerScheduled'] ?? 0,
            'timer_fired_events' => $matrixQueryCounts['TimerFired'] ?? 0,
        ],
        $findings,
    );
    $replayScenarioResults['php_worker_restart_version_marker_state'] = replay_runtime_scenario(
        'php_worker_restart_version_marker_state',
        $assertions['replay_matrix_version_marker_state_after_restart'],
        'php-completed-query-after-worker-restart',
        $restartIdentity + [
            'version_marker' => $matrixResult['version_marker'] ?? null,
            'side_effect_recorded_events' => $matrixQueryCounts['SideEffectRecorded'] ?? 0,
            'version_marker_callback_count' => $callbacks['replay_matrix_version_marker_callbacks'] ?? 0,
        ],
        $findings,
    );
    $replayScenarioResults['php_worker_restart_saga_compensation_state'] = replay_runtime_scenario(
        'php_worker_restart_saga_compensation_state',
        $assertions['replay_matrix_saga_compensation_state_after_restart'],
        'php-completed-query-after-worker-restart',
        $restartIdentity + [
            'compensation_result' => $matrixResult['compensation'] ?? null,
            'activity_failed_events' => $matrixQueryCounts['ActivityFailed'] ?? 0,
            'activity_completed_events' => $matrixQueryCounts['ActivityCompleted'] ?? 0,
        ],
        $findings,
    );
    $replayScenarioResults['php_code_divergence_refusal'] = replay_runtime_scenario(
        'php_code_divergence_refusal',
        $assertions['replay_matrix_code_divergence_refusal'],
        'php-published-sdk-code-divergence',
        $completedIdentity + [
            'observed_outcome' => $divergence['observed_outcome'] ?? null,
            'exception_type' => $divergence['exception_type'] ?? null,
        ],
        $findings,
        $divergence,
    );
    $replayScenarioResults['php_in_flight_signal_restart_timing'] = replay_runtime_scenario(
        'php_in_flight_signal_restart_timing',
        $assertions['replay_matrix_in_flight_signal_restart'],
        'php-in-flight-signal-worker-restart',
        $inFlight,
        $findings,
        [
            'observed_outcome' => $inFlight['observed_outcome'] ?? null,
            'worker_restart_at' => $inFlight['worker_restart_at'] ?? null,
            'signal_sent_at' => $inFlight['signal_sent_at'] ?? null,
            'history_reloaded_at' => $inFlight['history_reloaded_at'] ?? null,
            'replayed_next_decision' => $inFlight['replayed_next_decision'] ?? null,
        ],
    );
}
$provenance = [
    'package' => 'durable-workflow/sdk',
    'version' => $sdk['version'] ?? null,
    'source' => 'packagist',
    'dist' => $sdk['dist'] ?? null,
    'source_reference' => $sdk['source'] ?? null,
    'composer_content_hash' => $lock['content-hash'] ?? null,
    'install_preference' => 'dist',
];
$avroProvenance = [
    'package' => 'apache/avro',
    'version' => $avro['version'] ?? null,
    'dist' => $avro['dist'] ?? null,
    'source_reference' => $avro['source'] ?? null,
];
$observed = [
    'sdk' => 'sdk-php',
    'covered_cells' => $coveredCells,
    'unsupported_cells' => [],
    'typed_errors' => [
        $baseline['signal_query']['unknown_signal'] ?? [],
        $baseline['signal_query']['invalid_signal_arguments'] ?? [],
        $baseline['cancellation'] ?? [],
        $baseline['termination'] ?? [],
        $baseline['failure_envelope'] ?? [],
    ],
    'artifact_version' => $sdk['version'] ?? null,
    'server_version' => $serverVersion,
    'server_image' => $serverImage,
    'server_cluster_info' => $baseline['cluster_info'] ?? [],
    'artifact_source' => 'packagist://durable-workflow/sdk@'.$expectedSdkVersion,
    'composer_package' => 'durable-workflow/sdk',
    'packagist_artifact_verified' => $assertions['exact_sdk_version'] && $assertions['sdk_dist_provenance'],
    'install_provenance' => $provenance,
    'apache_avro_provenance' => $avroProvenance,
    'client_processes' => [
        $baseline['identity'] ?? [],
        $replayStart['identity'] ?? [],
        $checkpoint['identity'] ?? [],
        $replayFinish['identity'] ?? [],
    ],
    'worker_processes' => [$workerOne, $workerTwo],
    'worker_identities' => [$workerOne['worker_id'] ?? null, $workerTwo['worker_id'] ?? null],
    'worker_readiness' => [$workerOne['readiness'] ?? [], $workerTwo['readiness'] ?? []],
    'server_visible_workflow_command_contracts' => [
        'php-sdk-worker-1' => $workerOne['server_visible_registration']['workflow_command_contracts'] ?? [],
        'php-sdk-worker-2' => $workerTwo['server_visible_registration']['workflow_command_contracts'] ?? [],
    ],
    'workflow_started_command_contract' => $startedContractEvidence,
    'callback_counts' => $callbacks,
    'activity_callback_cardinality' => $activityCallbackCardinality,
    'namespace_evidence' => $baseline['namespace_lifecycle'] ?? [],
    'search_attribute_evidence' => $baseline['search_attributes'] ?? [],
    'history_assertions' => [
        'checkpoint_event_types' => $checkpoint['history_event_types'] ?? [],
        'final_event_types' => $history,
        'activity_completed_before_restart' => $assertions['replay_checkpoint'],
        'timer_fired_after_restart' => in_array('TimerFired', $history, true),
        'activity_callback_once_during_durable_replay' => $assertions['activity_callback_once_for_replay'],
        'activity_callback_cardinality_by_phase' => $assertions['activity_callback_cardinality_by_phase'],
        'addressable_workflow_started_contract' => $startedContractEvidence,
        'addressable_signal_inputs' => $baseline['signal_query']['history_inputs'] ?? [],
        'workflow_replay_signal_evidence' => $signalReplay,
    ],
    'scenario_assertions' => $assertions,
    'failure_domains' => $failedByDomain,
    'published_artifact_cell_executed' => true,
    'client_worker_distinct_processes' => $assertions['distinct_client_worker_processes'],
    'worker_restart_distinct_processes' => $assertions['distinct_worker_restart_processes'],
    'local_product_source_checkouts_used' => false,
];
if ($assertionFailures !== []) {
    $failedOperations = array_values(array_unique(array_column($assertionFailures, 'operation')));
    $failedSurfaces = array_values(array_unique(array_column($assertionFailures, 'owning_surface')));
    $observed += [
        'failure_stage' => 'runtime_assertions',
        'failure_owner' => count($failedSurfaces) === 1 ? $failedSurfaces[0] : 'multiple_product_surfaces',
        'failure_summary' => sprintf('Failed lifecycle assertions: %s', implode(', ', $failedAssertions)),
        'operation' => count($failedOperations) === 1 ? $failedOperations[0] : 'multiple_lifecycle_operations',
        'process_state' => [
            'process' => 'php-sdk-aggregate',
            'state' => 'exited',
            'outcome' => 'assertion_failure',
            'alive' => false,
            'exit_code' => 1,
        ],
        'failures' => $failedAssertions,
        'assertion_failures' => $assertionFailures,
    ];
}
$result = [
    'schema' => 'durable-workflow.v2.php-sdk-published-artifact-conformance',
    'version' => 1,
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'started_at' => $startedAt,
    'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'outcome' => $status,
    'runner_blocked' => $runnerBlocked,
    'artifact_versions' => ['sdk-php' => $expectedSdkVersion, 'server' => $serverVersion],
    'executed_distribution_identities' => read_json($resultDir.'/executed-distribution-identities.json'),
    'artifact_sources' => [
        'sdk-php' => 'packagist://durable-workflow/sdk@'.$expectedSdkVersion,
        'server' => 'docker://'.preg_replace('/^docker:\/\//', '', $serverImage),
    ],
    'package_provenance' => $provenance,
    'apache_avro_provenance' => $avroProvenance,
    'server_url' => $serverUrl,
    'namespace' => $namespace,
    'process_boundary' => [
        'client_worker_distinct_processes' => $assertions['distinct_client_worker_processes'],
        'worker_restart_distinct_processes' => $assertions['distinct_worker_restart_processes'],
        'client_processes' => $observed['client_processes'],
        'worker_processes' => $observed['worker_processes'],
    ],
    'worker_readiness' => $observed['worker_readiness'],
    'server_visible_workflow_command_contracts' => $observed['server_visible_workflow_command_contracts'],
    'workflow_started_command_contract' => $observed['workflow_started_command_contract'],
    'callback_counts' => $callbacks,
    'activity_callback_cardinality' => $activityCallbackCardinality,
    'history_assertions' => $observed['history_assertions'],
    'scenario_results' => array_fill_keys($coveredCells, ['status' => $status]),
    'assertions' => $assertions,
    'local_product_source_checkouts_used' => false,
    'failure_domains' => $failedByDomain,
    'findings' => $findings,
];
if ($replayMatrixEnabled) {
    $result['replay_matrix'] = [
        'enabled' => true,
        'executed' => true,
        'runtime_cells' => [
            'php-completed-history-replay-matrix',
            'php-completed-query-after-worker-restart',
            'php-published-sdk-code-divergence',
            'php-in-flight-signal-worker-restart',
        ],
        'evidence_files' => [
            'php-sdk-client-replay-matrix.json',
            'php-sdk-client-replay-matrix-restart.json',
            'php-sdk-client-in-flight-start.json',
            'php-sdk-in-flight-worker-timing.json',
            'php-sdk-worker-restart-timing.json',
        ],
    ];
    $result['replay_scenario_results'] = $replayScenarioResults;
}
$sidecar = [
    'schema' => 'durable-workflow.v2.workflow-lifecycle.php-sdk-sidecar',
    'generated_at' => $result['generated_at'],
    'runner' => 'published-php-sdk-process-boundary-conformance',
    'runner_blocked' => $runnerBlocked,
    'scenario_results' => [
        'php_sdk_lifecycle_surface' => [
            'scenario_id' => 'php_sdk_lifecycle_surface',
            'status' => $status,
            'classification' => $status === 'pass' ? 'passed' : implode('+', array_keys($failedByDomain)),
            'published_artifact_cell_executed' => true,
            'observed_outputs' => $observed,
            'linked_findings' => $findings,
        ],
    ],
];

file_put_contents($resultDir.'/php-sdk-conformance-result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
file_put_contents($resultDir.'/php-sdk-lifecycle-evidence.json', json_encode($sidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
exit($status === 'pass' ? 0 : 1);
PHP

suffix="$(date -u +%s)-$$-${RANDOM}"
queue="php-sdk-conformance-$suffix"

start_worker() {
  local worker_id="${1:?worker id is required}"
  local metadata="$result_dir/php-sdk-worker-${worker_id}.json"
  local readiness_log="$result_dir/php-sdk-worker-${worker_id}.readiness.log"
  local readiness_started_at
  local readiness_started_epoch
  local attempt
  local readiness_status
  worker_start_outcome=""
  worker_start_worker_id="$worker_id"
  worker_start_attempts=0
  worker_start_process_id=""
  worker_start_process_alive=""
  worker_start_process_exit_code=""
  worker_start_observation_file="$result_dir/php-sdk-worker-${worker_id}.readiness-observation.json"
  readiness_started_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  readiness_started_epoch="$("$php_bin" -r 'printf("%.6F", microtime(true));')"
  "$php_bin" "$project_dir/worker.php" \
    "$server_url" "$namespace" "$control_token" "$worker_token" "$queue" "$worker_id" "$result_dir" "$scope" \
    >"$result_dir/${worker_id}.log" 2>&1 &
  worker_pid=$!
  worker_start_process_id="$worker_pid"
  for attempt in $(seq 1 100); do
    worker_start_attempts="$attempt"
    if ! kill -0 "$worker_pid" >/dev/null 2>&1; then
      worker_start_outcome=process_exit
      worker_start_process_alive=false
      if wait "$worker_pid"; then
        worker_start_process_exit_code=0
      else
        worker_start_process_exit_code=$?
      fi
      worker_pid=""
      return 1
    fi
    readiness_status=0
    "$php_bin" "$script_dir/php-sdk-worker-readiness.php" \
      "$project_dir/vendor/autoload.php" "$server_url" "$namespace" "$control_token" \
      "$worker_id" "$worker_pid" "$result_dir" "$scope" "$readiness_started_at" \
      "$readiness_started_epoch" "$attempt" "$metadata" \
      >>"$readiness_log" 2>&1 || readiness_status=$?
    if [[ "$readiness_status" -eq 0 ]]; then
      return 0
    fi
    if [[ "$readiness_status" -ne 1 ]]; then
      if ! kill -0 "$worker_pid" >/dev/null 2>&1; then
        worker_start_outcome=process_exit
        worker_start_process_alive=false
        if wait "$worker_pid"; then
          worker_start_process_exit_code=0
        else
          worker_start_process_exit_code=$?
        fi
        worker_pid=""
        return 1
      fi
      worker_start_outcome=readiness_probe_failure
      worker_start_process_alive=true
      return 1
    fi
    sleep 0.1
  done
  if ! kill -0 "$worker_pid" >/dev/null 2>&1; then
    worker_start_outcome=process_exit
    worker_start_process_alive=false
    if wait "$worker_pid"; then
      worker_start_process_exit_code=0
    else
      worker_start_process_exit_code=$?
    fi
    worker_pid=""
    return 1
  fi
  worker_start_outcome=readiness_timeout
  worker_start_process_alive=true
  return 1
}

write_runtime_failure() {
  local stdout_file="${1:-}"
  local stderr_file="${2:-}"
  local stage="${3:?failure stage is required}"
  local diagnostic_file="${4:?diagnostic file is required}"
  local classification
  classification="$(classify_runtime_failure "$stdout_file" "$stderr_file")"
  capture_runtime_diagnostic "$stdout_file" "$stderr_file" "$diagnostic_file" "$classification"
  local summary
  summary="$(runtime_failure_summary "$classification" "$stage" "$diagnostic_file")"
  write_failure "$classification" "$(failure_owner_for "$classification")" "$stage" "$summary" "$diagnostic_file"
}

capture_client_companion() {
  local client_diagnostic_file="${1:?client diagnostic is required}"
  local worker_id="${worker_start_worker_id:-unknown-worker}"
  local worker_log="$result_dir/${worker_id}.log"
  local companion_file="$result_dir/php-sdk-${worker_id}-companion-failure.json"
  local process_alive=""
  local process_exit_code=""

  failure_companion_file=""
  if [[ -n "$worker_pid" ]] && kill -0 "$worker_pid" >/dev/null 2>&1; then
    process_alive=true
  elif [[ -n "$worker_pid" ]]; then
    process_alive=false
    if wait "$worker_pid"; then
      process_exit_code=0
    else
      process_exit_code=$?
    fi
    worker_pid=""
  fi

  if CLIENT_DIAGNOSTIC_FILE="$client_diagnostic_file" \
    COMPANION_OUTPUT_FILE="$companion_file" \
    COMPANION_WORKER_ID="$worker_id" \
    COMPANION_WORKER_LOG="$worker_log" \
    COMPANION_WORKER_ALIVE="$process_alive" \
    COMPANION_WORKER_EXIT_CODE="$process_exit_code" \
    COMPANION_TASK_QUEUE="$queue" \
    COMPANION_SERVER_LOG="${DW_PHP_SDK_CONFORMANCE_SERVER_LOG:-}" \
    COMPANION_SCHEDULER_LOG="${DW_PHP_SDK_CONFORMANCE_SCHEDULER_LOG:-}" \
    SERVER_URL="$server_url" \
    NAMESPACE="$namespace" \
    CONTROL_TOKEN="$control_token" \
    WORKER_TOKEN="$worker_token" \
    node "$script_dir/php-sdk-companion-failure-evidence.cjs"; then
    failure_companion_file="$companion_file"
  fi
}

write_client_runtime_failure() {
  local stdout_file="${1:-}"
  local stderr_file="${2:-}"
  local stage="${3:?failure stage is required}"
  local diagnostic_file="${4:?diagnostic file is required}"
  local requested_classification="${5:-}"
  local classification
  classification="${requested_classification:-$(classify_runtime_failure "$stdout_file" "$stderr_file")}"
  capture_runtime_diagnostic "$stdout_file" "$stderr_file" "$diagnostic_file" "$classification"
  capture_client_companion "$diagnostic_file"
  local summary
  summary="$(runtime_failure_summary "$classification" "$stage" "$diagnostic_file")"
  write_failure "$classification" "$(failure_owner_for "$classification")" "$stage" "$summary" "$diagnostic_file"
}

write_worker_start_failure() {
  local stdout_file="${1:?worker stdout is required}"
  local stderr_file="${2:?worker readiness log is required}"
  local diagnostic_file="${3:?diagnostic file is required}"

  case "$worker_start_outcome" in
    process_exit)
      capture_runtime_diagnostic "$stdout_file" "$stderr_file" "$diagnostic_file" sdk
      write_failure sdk sdk-php worker_process_exit \
        "$(runtime_failure_summary sdk worker_process_exit "$diagnostic_file")" \
        "$diagnostic_file"
      ;;
    readiness_timeout)
      capture_runtime_diagnostic "$stdout_file" "$stderr_file" "$diagnostic_file" sdk
      write_failure sdk sdk-php worker_readiness_timeout \
        "The released PHP SDK worker remained alive but authoritative command-contract readiness timed out after ${worker_start_attempts} attempts; the last server observation is retained in structured evidence." \
        "$diagnostic_file"
      ;;
    *)
      write_runtime_failure "$stdout_file" "$stderr_file" worker_readiness_probe "$diagnostic_file"
      ;;
  esac
}

run_client_phase() {
  local phase="${1:?phase is required}"
  local output="${2:?output path is required}"
  "$php_bin" "$project_dir/client.php" \
    "$phase" "$server_url" "$namespace" "$control_token" "$queue" "$result_dir" "$suffix" \
    >"$output" 2>"$output.log"
}

if ! start_worker php-sdk-worker-1; then
  write_worker_start_failure \
    "$result_dir/php-sdk-worker-1.log" "$result_dir/php-sdk-worker-php-sdk-worker-1.readiness.log" \
    "$result_dir/php-sdk-worker-1.diagnostic.log"
  exit 0
fi

initial_client_phase=baseline
initial_client_output="$result_dir/php-sdk-client-baseline.json"
initial_client_stage=baseline_client
if [[ "$scope" == namespace ]]; then
  initial_client_phase=namespace
  initial_client_output="$result_dir/php-sdk-client-namespace.json"
  initial_client_stage=namespace_client
elif [[ "$scope" == search-attributes ]]; then
  initial_client_phase=search-attributes
  initial_client_output="$result_dir/php-sdk-client-search-attributes.json"
  initial_client_stage=search_attributes_client
fi
if ! run_client_phase "$initial_client_phase" "$initial_client_output"; then
  write_client_runtime_failure \
    "$initial_client_output" "$initial_client_output.log" "$initial_client_stage" \
    "${initial_client_output%.json}.diagnostic.log"
  exit 0
fi

if [[ "$scope" == namespace ]]; then
  kill -TERM "$worker_pid" >/dev/null 2>&1 || true
  wait "$worker_pid" >/dev/null 2>&1 || true
  worker_pid=""
  write_namespace_result
  exit 0
fi

if [[ "$scope" == search-attributes ]]; then
  kill -TERM "$worker_pid" >/dev/null 2>&1 || true
  wait "$worker_pid" >/dev/null 2>&1 || true
  worker_pid=""
  write_search_attribute_result
  exit 0
fi

if [[ "$replay_matrix_enabled" == "1" ]]; then
  if ! run_client_phase run-replay-matrix "$result_dir/php-sdk-client-replay-matrix.json"; then
    write_client_runtime_failure \
      "$result_dir/php-sdk-client-replay-matrix.json" \
      "$result_dir/php-sdk-client-replay-matrix.json.log" \
      replay_matrix_completed_history \
      "$result_dir/php-sdk-client-replay-matrix.diagnostic.log"
    exit 0
  fi
fi

if ! run_client_phase start-replay "$result_dir/php-sdk-client-start-replay.json"; then
  write_client_runtime_failure \
    "$result_dir/php-sdk-client-start-replay.json" "$result_dir/php-sdk-client-start-replay.json.log" replay_start \
    "$result_dir/php-sdk-client-start-replay.diagnostic.log"
  exit 0
fi
if ! run_client_phase wait-replay-checkpoint "$result_dir/php-sdk-client-replay-checkpoint.json"; then
  replay_checkpoint_diagnostic="$result_dir/php-sdk-client-replay-checkpoint.diagnostic.log"
  write_client_runtime_failure \
    "$result_dir/php-sdk-client-replay-checkpoint.json" \
    "$result_dir/php-sdk-client-replay-checkpoint.json.log" \
    replay_checkpoint \
    "$replay_checkpoint_diagnostic" \
    server
  exit 0
fi

if [[ "$replay_matrix_enabled" == "1" ]]; then
  if ! run_client_phase start-in-flight "$result_dir/php-sdk-client-in-flight-start.json"; then
    write_client_runtime_failure \
      "$result_dir/php-sdk-client-in-flight-start.json" \
      "$result_dir/php-sdk-client-in-flight-start.json.log" \
      replay_matrix_in_flight_start \
      "$result_dir/php-sdk-client-in-flight-start.diagnostic.log"
    exit 0
  fi
fi

kill -TERM "$worker_pid" >/dev/null 2>&1 || true
wait "$worker_pid" >/dev/null 2>&1 || true
worker_pid=""

if [[ "$replay_matrix_enabled" == "1" ]]; then
  RESTART_TIMING_FILE="$result_dir/php-sdk-worker-restart-timing.json" \
    "$php_bin" -r '
$path = getenv("RESTART_TIMING_FILE");
$now = (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("Y-m-d\\TH:i:s.u\\Z");
file_put_contents($path, json_encode(["worker_restart_at" => $now], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
'
fi

if ! start_worker php-sdk-worker-2; then
  write_worker_start_failure \
    "$result_dir/php-sdk-worker-2.log" "$result_dir/php-sdk-worker-php-sdk-worker-2.readiness.log" \
    "$result_dir/php-sdk-worker-2.diagnostic.log"
  exit 0
fi
if ! run_client_phase finish-replay "$result_dir/php-sdk-client-finish-replay.json"; then
  write_client_runtime_failure \
    "$result_dir/php-sdk-client-finish-replay.json" "$result_dir/php-sdk-client-finish-replay.json.log" replay_finish \
    "$result_dir/php-sdk-client-finish-replay.diagnostic.log"
  exit 0
fi

if [[ "$replay_matrix_enabled" == "1" ]]; then
  if ! run_client_phase finish-replay-matrix "$result_dir/php-sdk-client-replay-matrix-restart.json"; then
    write_client_runtime_failure \
      "$result_dir/php-sdk-client-replay-matrix-restart.json" \
      "$result_dir/php-sdk-client-replay-matrix-restart.json.log" \
      replay_matrix_worker_restart \
      "$result_dir/php-sdk-client-replay-matrix-restart.diagnostic.log"
    exit 0
  fi
fi

kill -TERM "$worker_pid" >/dev/null 2>&1 || true
wait "$worker_pid" >/dev/null 2>&1 || true
worker_pid=""

if ! "$php_bin" "$project_dir/aggregate.php" \
  "$result_dir" "$sdk_version" "$server_version" "$server_image" "$server_url" "$namespace" "$started_at" \
  >"$result_dir/php-sdk-aggregate.log" 2>&1; then
  if [[ ! -s "$result_file" || ! -s "$sidecar_file" ]]; then
    write_runtime_failure \
      "$result_dir/php-sdk-aggregate.log" '' aggregate \
      "$result_dir/php-sdk-aggregate.diagnostic.log"
  fi
fi

exit 0
