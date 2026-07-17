#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: php-sdk-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--scope lifecycle|namespace]

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
  DW_PHP_SDK_CONFORMANCE_SCOPE        lifecycle (default) or namespace.
  DW_PHP_SDK_CONFORMANCE_WORKER_RUN_DELAY_MS Delay managed registration for readiness regression probes.
USAGE
}

result_dir="${DW_PHP_SDK_CONFORMANCE_RESULT_DIR:-}"
scope="${DW_PHP_SDK_CONFORMANCE_SCOPE:-lifecycle}"
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

if [[ "$scope" != lifecycle && "$scope" != namespace ]]; then
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
  STARTED_AT="$started_at" \
  FAILURE_CLASSIFICATION="$classification" \
  FAILURE_OWNER="$owning_surface" \
  FAILURE_STAGE="$stage" \
  FAILURE_SUMMARY="$summary" \
  FAILURE_DIAGNOSTIC_FILE="$diagnostic_file" \
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
} = require(process.env.FAILURE_EVIDENCE_HELPER);

const resultDir = process.env.RESULT_DIR;
const version = process.env.SDK_VERSION || '';
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
const runnerBlocked = classification === 'runner';
const renderedProcessExitCode = Number.isInteger(processExitCode) ? ` with code ${processExitCode}` : '';
const processExitSummary = [
  `The released PHP SDK worker process exited${renderedProcessExitCode}`,
  `during ${process.env.FAILURE_STAGE || 'worker startup'};`,
  'the bounded crash diagnostic is retained in structured evidence.',
].join(' ');
const summary = failureSummary(
  runtimeFailure,
  process.env.FAILURE_STAGE || 'unknown',
  workerExitedDuringStartup ? processExitSummary : fallbackSummary,
);
const finding = {
  finding_id: `php-sdk-${process.env.FAILURE_STAGE || 'unknown'}-failure`,
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

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'node is required to write typed conformance evidence' >&2
  exit 2
fi
if [[ -z "$sdk_version" || ! "$sdk_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  write_failure package-publication sdk-php-release preflight 'DW_PHP_SDK_VERSION must be an exact published Composer version.'
  exit 0
fi
if [[ -z "$server_version" || ! "$server_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  write_failure runner conformance_harness preflight 'DW_SERVER_VERSION must be an exact published server version.'
  exit 0
fi
if [[ -z "$server_image" || -z "$server_url" ]]; then
  write_failure runner conformance_harness preflight 'DW_SERVER_VERSION, DW_SERVER_IMAGE, and DW_PHP_SDK_CONFORMANCE_SERVER_URL are required.'
  exit 0
fi
if [[ "$server_image" != "durableworkflow/server:${server_version}" \
  && "$server_image" != "docker.io/durableworkflow/server:${server_version}" \
  && ! "$server_image" =~ ^(docker\.io/)?durableworkflow/server(@sha256:[0-9a-fA-F]{64})$ ]]; then
  write_failure runner conformance_harness preflight 'DW_SERVER_IMAGE must be the exact requested durableworkflow/server tag or a digest pin.'
  exit 0
fi
if ! command -v "$php_bin" >/dev/null 2>&1; then
  write_failure runner conformance_harness preflight "PHP binary is unavailable: $php_bin"
  exit 0
fi
if ! command -v "$composer_bin" >/dev/null 2>&1; then
  write_failure runner conformance_harness preflight "Composer binary is unavailable: $composer_bin"
  exit 0
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
  exit 0
fi

if ! python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" sdk-php "$sdk_version" \
  "$result_dir/composer-cache/files/durable-workflow/sdk" '**/*' \
  --artifact-name durable-workflow/sdk; then
  write_failure package-publication sdk-php-release composer_identity \
    "Composer installed durable-workflow/sdk:$sdk_version without retaining its consumed distribution bytes."
  exit 0
fi

cp "$script_dir/php-sdk-runtime-failure.php" "$project_dir/runtime-failure.php"
cp "$script_dir/php-sdk-started-contract.php" "$project_dir/started-contract.php"
cp "$script_dir/php-sdk-assertion-failure-evidence.php" "$project_dir/assertion-failure-evidence.php"

cat > "$project_dir/worker.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/runtime-failure.php';

use DurableWorkflow\Client;
use DurableWorkflow\Worker;
use DurableWorkflow\Worker\ActivityContext;
use DurableWorkflow\Worker\QueryContext;
use DurableWorkflow\Worker\WorkflowContext;

if ($argc < 9) {
    fwrite(STDERR, "usage: worker.php <server> <namespace> <control-token> <worker-token> <queue> <worker-id> <result-dir> <scope>\n");
    exit(2);
}

[$script, $server, $namespace, $controlToken, $workerToken, $queue, $workerId, $resultDir, $scope] = $argv;
install_runtime_failure_handler('worker', $scope, [$controlToken, $workerToken]);
$client = new Client($server, namespace: $namespace, controlToken: $controlToken, workerToken: $workerToken);
$callbackFile = $resultDir.'/php-sdk-callback-counts.json';
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
function decoded_signal_inputs(QueryContext $context, Client $client): array
{
    $inputs = [];
    foreach ($context->events('SignalReceived') as $event) {
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (($payload['signal_name'] ?? null) !== 'increment') {
            continue;
        }
        $raw = $payload['value'] ?? $payload['input'] ?? $payload['arguments'] ?? null;
        $decoded = is_array($raw) || is_string($raw) ? $client->payloadCodec()->decodeEnvelope($raw) : [];
        $arguments = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];
        $inputs[] = (int) ($arguments[0] ?? 0);
    }

    return $inputs;
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
    static function (ActivityContext $context, mixed $value) use ($callbackFile): array {
        increment_callback($callbackFile, 'activity');
        increment_callback($callbackFile, 'activity_heartbeat');
        $context->heartbeat(['phase' => 'activity', 'callback_count' => 1]);

        return ['echo' => $value, 'activity_process_id' => getmypid()];
    },
);
$worker->registerWorkflow(
    'php.sdk.simple',
    static function (WorkflowContext $context, mixed $value) use ($callbackFile): Generator {
        increment_callback($callbackFile, 'simple_workflow_replays');
        $activity = yield $context->activity('php.sdk.echo', [$value]);

        return ['result' => $activity, 'workflow_process_id' => getmypid()];
    },
);
if (! $namespaceScope) {
    $worker->registerWorkflow(
        'php.sdk.replay',
        static function (WorkflowContext $context, mixed $value) use ($callbackFile): Generator {
            increment_callback($callbackFile, 'replay_workflow_replays');
            $activity = yield $context->activity('php.sdk.echo', [$value]);
            yield $context->sleep(10);

            return ['replayed_result' => $activity, 'workflow_process_id' => getmypid()];
        },
    );
    $worker->registerWorkflow(
        'php.sdk.waiting',
        static function (WorkflowContext $context) use ($callbackFile, $signalReplayFile): Generator {
            increment_callback($callbackFile, 'waiting_workflow_replays');
            record_replay_signals($signalReplayFile, $context->signals('increment'));
            $context->throwIfCancellationRequested();
            yield $context->sleep(300);

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
        static function () use ($callbackFile): never {
            increment_callback($callbackFile, 'failure_workflow');
            throw new DomainException('php-sdk-conformance-failure');
        },
    );
    $worker->registerWorkflow(
        'php.sdk.search',
        static function (WorkflowContext $context, string $name, string $value) use ($callbackFile): Generator {
            increment_callback($callbackFile, 'search_workflow_replays');
            yield $context->upsertSearchAttributes([$name => $value]);

            return ['search_attribute' => $name, 'value' => $value, 'workflow_process_id' => getmypid()];
        },
    );
    $worker->registerQuery(
        'php.sdk.waiting',
        'current',
        static function (QueryContext $context) use ($client, $callbackFile, $operationEvidenceFile): array {
            increment_callback($callbackFile, 'query');
            $inputs = decoded_signal_inputs($context, $client);
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

cat > "$project_dir/client.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/runtime-failure.php';
require __DIR__.'/started-contract.php';

use Composer\InstalledVersions;
use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Exception\WorkflowCancelled;
use DurableWorkflow\Exception\WorkflowFailed;
use DurableWorkflow\Exception\WorkflowTerminated;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;

if ($argc < 8) {
    fwrite(STDERR, "usage: client.php <phase> <server> <namespace> <token> <queue> <result-dir> <suffix>\n");
    exit(2);
}

[$script, $phase, $server, $namespace, $token, $queue, $resultDir, $suffix] = $argv;
install_runtime_failure_handler('client', $phase, [$token]);
$client = new Client($server, token: $token, namespace: $namespace);
$stateFile = $resultDir.'/php-sdk-replay-state.json';

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
        ],
    ];
    file_put_contents(
        $resultDir.'/php-sdk-namespace-evidence.json',
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
    ]);
}

throw new InvalidArgumentException("Unknown client phase: {$phase}");
PHP

cat > "$project_dir/aggregate.php" <<'PHP'
<?php

declare(strict_types=1);

require __DIR__.'/started-contract.php';
require __DIR__.'/assertion-failure-evidence.php';

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

$baseline = read_json($resultDir.'/php-sdk-client-baseline.json');
$replayStart = read_json($resultDir.'/php-sdk-client-start-replay.json');
$checkpoint = read_json($resultDir.'/php-sdk-client-replay-checkpoint.json');
$replayFinish = read_json($resultDir.'/php-sdk-client-finish-replay.json');
$callbacks = read_json($resultDir.'/php-sdk-callback-counts.json');
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
    'activity_callback_once_for_replay' => (int) ($callbacks['activity'] ?? 0) === 2,
    'activity_heartbeat_callback' => (int) ($callbacks['activity_heartbeat'] ?? 0) === 2,
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
    'update' => 'sdk',
    'cancellation' => 'sdk',
    'termination' => 'sdk',
    'failure_envelope' => 'sdk',
    'activity_callback_once_for_replay' => 'server',
    'activity_heartbeat_callback' => 'sdk',
    'namespace_lifecycle' => 'server',
    'namespace_selection' => 'sdk',
    'search_attributes' => 'sdk',
    'schedule_lifecycle' => 'server',
    'replay_checkpoint' => 'server',
    'durable_replay_history' => 'server',
    'durable_replay_result' => 'sdk',
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
    'namespace_evidence' => $baseline['namespace_lifecycle'] ?? [],
    'search_attribute_evidence' => $baseline['search_attributes'] ?? [],
    'history_assertions' => [
        'checkpoint_event_types' => $checkpoint['history_event_types'] ?? [],
        'final_event_types' => $history,
        'activity_completed_before_restart' => $assertions['replay_checkpoint'],
        'timer_fired_after_restart' => in_array('TimerFired', $history, true),
        'activity_callbacks_total_expected_two' => $assertions['activity_callback_once_for_replay'],
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
    'history_assertions' => $observed['history_assertions'],
    'scenario_results' => array_fill_keys($coveredCells, ['status' => $status]),
    'assertions' => $assertions,
    'local_product_source_checkouts_used' => false,
    'failure_domains' => $failedByDomain,
    'findings' => $findings,
];
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
fi
if ! run_client_phase "$initial_client_phase" "$initial_client_output"; then
  write_runtime_failure \
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

if ! run_client_phase start-replay "$result_dir/php-sdk-client-start-replay.json"; then
  write_runtime_failure \
    "$result_dir/php-sdk-client-start-replay.json" "$result_dir/php-sdk-client-start-replay.json.log" replay_start \
    "$result_dir/php-sdk-client-start-replay.diagnostic.log"
  exit 0
fi
if ! run_client_phase wait-replay-checkpoint "$result_dir/php-sdk-client-replay-checkpoint.json"; then
  replay_checkpoint_diagnostic="$result_dir/php-sdk-client-replay-checkpoint.diagnostic.log"
  capture_runtime_diagnostic \
    "$result_dir/php-sdk-client-replay-checkpoint.json" \
    "$result_dir/php-sdk-client-replay-checkpoint.json.log" \
    "$replay_checkpoint_diagnostic" \
    server
  write_failure server server replay_checkpoint \
    "$(runtime_failure_summary server replay_checkpoint "$replay_checkpoint_diagnostic")" \
    "$replay_checkpoint_diagnostic"
  exit 0
fi

kill -TERM "$worker_pid" >/dev/null 2>&1 || true
wait "$worker_pid" >/dev/null 2>&1 || true
worker_pid=""

if ! start_worker php-sdk-worker-2; then
  write_worker_start_failure \
    "$result_dir/php-sdk-worker-2.log" "$result_dir/php-sdk-worker-php-sdk-worker-2.readiness.log" \
    "$result_dir/php-sdk-worker-2.diagnostic.log"
  exit 0
fi
if ! run_client_phase finish-replay "$result_dir/php-sdk-client-finish-replay.json"; then
  write_runtime_failure \
    "$result_dir/php-sdk-client-finish-replay.json" "$result_dir/php-sdk-client-finish-replay.json.log" replay_finish \
    "$result_dir/php-sdk-client-finish-replay.diagnostic.log"
  exit 0
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
