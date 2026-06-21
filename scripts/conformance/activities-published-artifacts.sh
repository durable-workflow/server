#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: activities-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Writes a scenario-level activities conformance result for published artifacts.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  activities-result.json
  activities-record.json
  activities-findings.json

Environment overrides:
  DW_ACTIVITIES_RESULT_DIR              Result directory. Defaults to run root.
  DW_ACTIVITIES_RUN_ROOT                Scratch directory. Defaults to mktemp.
  DW_ACTIVITIES_KEEP_RUN_ROOT=1         Keep scratch directory after success.
  DW_ACTIVITIES_SCENARIO_MANIFEST       Scenario manifest path. Defaults to the server static mirror.
  DW_ACTIVITIES_ARTIFACT_INSTALL_EVIDENCE
                                         JSON proof that each published artifact was downloaded/installed.
                                         Defaults to artifact-install-evidence.json in the result directory.
  DW_ACTIVITIES_EVIDENCE                Optional JSON activity evidence from a real host matrix run.
  DW_ACTIVITIES_EVIDENCE_PATH           Optional path to JSON activity evidence from a real host matrix run.
  DW_SERVER_IMAGE                       Exact server image tag or digest to test.
  DW_SERVER_VERSION                     Exact patch server Docker tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                        Exact CLI release version.
  DW_PYTHON_SDK_VERSION                 Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION               Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION                  Exact Waterline artifact version.
USAGE
}

keep_run_root="${DW_ACTIVITIES_KEEP_RUN_ROOT:-0}"
result_dir="${DW_ACTIVITIES_RESULT_DIR:-}"

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
    --keep-run-root)
      keep_run_root=1
      shift
      ;;
    --keep-run-root=*)
      keep_run_root="${1#--keep-run-root=}"
      if [[ "$keep_run_root" == "true" ]]; then
        keep_run_root=1
      elif [[ "$keep_run_root" != "1" ]]; then
        keep_run_root=0
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

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

require_command() {
  local name="$1"

  command -v "$name" >/dev/null 2>&1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
scenario_manifest="${DW_ACTIVITIES_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/activity-runtime-scenarios.json}"

run_root="${DW_ACTIVITIES_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-activities.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

started_at="$(timestamp)"

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
SCENARIO_MANIFEST="$scenario_manifest" \
node <<'JS'
const fs = require('fs');
const path = require('path');

const RESULT_DIR = process.env.RESULT_DIR;
const STARTED_AT = process.env.STARTED_AT;
const MANIFEST_PATH = process.env.SCENARIO_MANIFEST;

const REQUIRED_SCENARIOS = [
  'published_artifact_install_only',
  'workflow_embedded_activity_result',
  'standalone_activity_result',
  'durable_result_recording_after_worker_restart',
  'retry_attempt_backoff_behavior',
  'timeout_behavior',
  'typed_failure_propagation',
  'heartbeat_and_cancellation_observation',
  'idempotent_completion_handling',
  'php_python_activity_parity',
  'operator_visible_activity_attempt_state',
];

const REQUIRED_INSTALL_ARTIFACTS = [
  'server',
  'cli',
  'sdk-python',
  'workflow-php',
  'waterline',
];

const DEFAULT_EXPECTED_BEHAVIOR = {
  published_artifact_install_only:
    'all artifacts are resolved from published channels and no local product checkout is used as an artifact under test',
  workflow_embedded_activity_result:
    'a workflow-scheduled activity completes through the worker protocol and the workflow observes the exact typed result',
  standalone_activity_result:
    'a top-level activity started through POST /api/activities closes its host run with the activity result',
  durable_result_recording_after_worker_restart:
    'activity result recording survives worker restart and replay does not duplicate completion',
  retry_attempt_backoff_behavior:
    'failed attempts increment attempt state, respect configured backoff, and complete or fail according to retry policy',
  timeout_behavior:
    'start-to-close or schedule-to-close deadline is visible to the worker and enforced as a typed timeout',
  typed_failure_propagation:
    'activity failures preserve type, message, and details through history and the caller runtime',
  heartbeat_and_cancellation_observation:
    'activity heartbeat details are recorded and cancellation is observable by a running worker',
  idempotent_completion_handling:
    'duplicate completion attempts do not create duplicate terminal records and return a deterministic worker-protocol response',
  php_python_activity_parity:
    'PHP and Python activity workers produce compatible payload, failure, retry, timeout, and heartbeat observations where both runtimes support the surface',
  operator_visible_activity_attempt_state:
    'operators can see current and historical activity attempt state through API metrics and Waterline',
};

const SEMVER_RE = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
const SERVER_TAG_RE = /(?::|\/)(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$/;
const PLACEHOLDER_RE = /(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$)|current|head|unresolved|placeholder)/i;
const ALLOWED_STATUSES = new Set(['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked']);
const NON_PASS_CLASSIFICATIONS = new Set([
  'product-gap',
  'coverage-gap',
  'runner-gap',
  'stale-artifact',
  'pipeline-churn',
]);
const FORBIDDEN_INSTALL_SOURCE_TOKENS = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout',
  'source_checkout',
  '/workspace/repos/',
];
const PUBLISHED_SERVER_IMAGE_REPOSITORIES = [
  'durableworkflow/server',
  'docker.io/durableworkflow/server',
  'index.docker.io/durableworkflow/server',
  'registry-1.docker.io/durableworkflow/server',
  'ghcr.io/durable-workflow/server',
];

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function env(name) {
  return (process.env[name] || '').trim();
}

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function readJsonFile(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function loadJsonFromStringOrPath(raw, file) {
  if (raw && raw.trim() !== '') {
    return {
      supplied: true,
      source: 'environment',
      value: JSON.parse(raw),
    };
  }

  if (file && fs.existsSync(file)) {
    return {
      supplied: true,
      source: file,
      value: readJsonFile(file),
    };
  }

  return {
    supplied: false,
    source: file || '',
    value: null,
  };
}

function safeLoadJsonFromStringOrPath(raw, file, fallbackSchema) {
  try {
    return loadJsonFromStringOrPath(raw, file);
  } catch (error) {
    return {
      supplied: true,
      source: raw && raw.trim() !== '' ? 'environment' : file,
      value: {
        schema: fallbackSchema,
        generated_at: now(),
        load_error: String(error && error.message ? error.message : error),
      },
    };
  }
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'string') {
    return value.trim();
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value).trim();
  }
  return '';
}

function truthy(value) {
  if (value === true || value === 1) {
    return true;
  }
  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'y', 'on'].includes(value.trim().toLowerCase());
  }
  return false;
}

function explicitFalse(value) {
  if (value === false || value === 0) {
    return true;
  }
  if (typeof value === 'string') {
    return ['0', 'false', 'no', 'n', 'off'].includes(value.trim().toLowerCase());
  }
  return false;
}

function normalizeCliVersion(value) {
  return value.startsWith('v') && SEMVER_RE.test(value.slice(1)) ? value.slice(1) : value;
}

function deriveServerVersion(serverImage, explicitVersion) {
  if (explicitVersion) {
    return explicitVersion;
  }
  const match = SERVER_TAG_RE.exec(serverImage);
  return match ? match[1] : '';
}

function isPlaceholder(value) {
  return value !== '' && PLACEHOLDER_RE.test(value);
}

function exactVersionFailures(versions, serverImage) {
  const failures = [];
  const required = {
    server: 'DW_SERVER_VERSION or exact DW_SERVER_IMAGE tag',
    cli: 'DW_CLI_VERSION',
    'sdk-python': 'DW_PYTHON_SDK_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  for (const [key, label] of Object.entries(required)) {
    const version = versions[key] || '';
    if (!version) {
      failures.push(`missing ${label}`);
      continue;
    }
    if (isPlaceholder(version) || !SEMVER_RE.test(version)) {
      failures.push(`${label} must be an exact semver artifact version; got ${JSON.stringify(version)}`);
    }
  }

  if (serverImage) {
    if (isPlaceholder(serverImage)) {
      failures.push(`DW_SERVER_IMAGE must not use a rolling tag or placeholder; got ${JSON.stringify(serverImage)}`);
    }
    const tagMatch = SERVER_TAG_RE.exec(serverImage);
    if (tagMatch && versions.server && tagMatch[1] !== versions.server) {
      failures.push(`DW_SERVER_VERSION ${JSON.stringify(versions.server)} does not match DW_SERVER_IMAGE tag ${JSON.stringify(tagMatch[1])}`);
    }
    if (serverImage.includes('@sha256:') && !versions.server) {
      failures.push('DW_SERVER_VERSION is required when DW_SERVER_IMAGE is digest-pinned');
    }
  }

  return failures;
}

function normalizedStatus(value) {
  const status = stringValue(value).toLowerCase();
  if (['pass', 'passed', 'success', 'ok'].includes(status)) {
    return 'pass';
  }
  if (['fail', 'failed', 'failure'].includes(status)) {
    return 'fail';
  }
  if (['blocked', 'runner_blocked', 'error'].includes(status)) {
    return 'runner_blocked';
  }
  if (['not_covered', 'missing', 'not_exercised'].includes(status)) {
    return 'not_covered';
  }
  if (status === 'unsupported') {
    return 'unsupported';
  }
  return status;
}

function artifactVersionFor(versions, artifact) {
  const aliases = {
    'workflow-php': ['workflow-php', 'workflow'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python'],
  };
  for (const key of aliases[artifact] || [artifact]) {
    const value = versions[key] || '';
    if (value) {
      return value;
    }
  }
  return '';
}

function entrySource(entry) {
  for (const key of [
    'source',
    'install_source',
    'installSource',
    'artifact_source',
    'artifactSource',
    'resolved_source',
    'resolvedSource',
  ]) {
    const value = stringValue(entry[key]);
    if (value) {
      return value;
    }
  }
  return '';
}

function normalizeArtifactInstallEvidence(evidenceLoad, artifactVersions) {
  const evidence = evidenceLoad.value && typeof evidenceLoad.value === 'object' ? evidenceLoad.value : {};
  const rawArtifacts = Array.isArray(evidence.artifacts) ? evidence.artifacts : [];
  const byArtifact = new Map();
  for (const item of rawArtifacts) {
    if (!item || typeof item !== 'object') {
      continue;
    }
    const artifact = stringValue(item.artifact || item.name);
    if (artifact) {
      byArtifact.set(artifact, item);
    }
  }

  const artifacts = REQUIRED_INSTALL_ARTIFACTS.map((artifact) => {
    const item = byArtifact.get(artifact) || {};
    const rawVersion = stringValue(
      item.version
      || item.artifact_version
      || item.artifactVersion
      || item.resolved_version
      || item.resolvedVersion,
    );
    const rawSource = entrySource(item);
    return {
      artifact,
      version: rawVersion || artifactVersionFor(artifactVersions, artifact),
      version_provided: rawVersion !== '',
      source: rawSource || 'not_exercised',
      source_provided: rawSource !== '',
      status: normalizedStatus(item.status || item.result || item.outcome),
      local_product_source_checkouts_used: truthy(
        item.local_product_source_checkouts_used || item.localProductSourceCheckoutsUsed,
      ),
      detail: stringValue(item.detail || item.observed_behavior),
      command: item.command || null,
      output_sample: item.output_sample || item.outputSample || '',
    };
  });

  const topLocal = truthy(evidence.local_product_source_checkouts_used || evidence.localProductSourceCheckoutsUsed);
  const topExplicitFalse = explicitFalse(evidence.local_product_source_checkouts_used)
    || explicitFalse(evidence.localProductSourceCheckoutsUsed);

  return {
    schema: stringValue(evidence.schema) || 'durable-workflow.v2.activity-runtime.artifact-install-evidence',
    generated_at: stringValue(evidence.generated_at) || now(),
    supplied: evidenceLoad.supplied,
    source: evidenceLoad.source,
    load_error: stringValue(evidence.load_error),
    local_product_source_checkouts_used: topLocal
      || artifacts.some((artifact) => artifact.local_product_source_checkouts_used),
    local_product_source_checkouts_used_explicit_false: topExplicitFalse,
    artifacts,
  };
}

function installSourceIsForbidden(source) {
  const normalized = source.toLowerCase();
  return FORBIDDEN_INSTALL_SOURCE_TOKENS.some((token) => normalized.includes(token));
}

function installSourceMatchesArtifact(artifact, version, source) {
  if (!source || source === 'not_exercised' || isPlaceholder(source) || installSourceIsForbidden(source)) {
    return false;
  }
  if (!version || isPlaceholder(version)) {
    return false;
  }

  switch (artifact) {
    case 'server':
      return matchesServerArtifactSource(version, source);
    case 'cli':
      return matchesCliArtifactSource(version, source);
    case 'sdk-python':
      return matchesPythonArtifactSource(version, source);
    case 'workflow-php':
      return matchesComposerArtifactSource('durable-workflow/workflow', version, source);
    case 'waterline':
      return matchesComposerArtifactSource('durable-workflow/waterline', version, source);
    default:
      return false;
  }
}

function matchesServerArtifactSource(version, source) {
  const image = source.replace(/^docker:\/\//i, '');
  if (!image) {
    return false;
  }

  return PUBLISHED_SERVER_IMAGE_REPOSITORIES.some((repository) => {
    const escapedRepository = escapeRegExp(repository);
    const escapedVersion = escapeRegExp(version);

    return image.toLowerCase() === `${repository}:${version}`.toLowerCase()
      || new RegExp(`^${escapedRepository}@sha256:[0-9a-f]{64}$`, 'i').test(image)
      || new RegExp(`^${escapedRepository}:${escapedVersion}@sha256:[0-9a-f]{64}$`, 'i').test(image);
  });
}

function matchesCliArtifactSource(version, source) {
  const prefixes = [
    `https://github.com/durable-workflow/cli/releases/download/${version}/`,
    `https://github.com/durable-workflow/cli/releases/download/v${version}/`,
  ];

  return prefixes.some((prefix) => source.startsWith(prefix) && source.slice(prefix.length) !== '');
}

function matchesPythonArtifactSource(version, source) {
  return source === `pypi://durable-workflow==${version}`
    || source === `https://pypi.org/project/durable-workflow/${version}/`
    || (
      (source.startsWith('https://files.pythonhosted.org/') || source.startsWith('https://pypi.io/packages/'))
      && (
        source.includes(`/durable_workflow-${version}`)
        || source.includes(`/durable-workflow-${version}`)
      )
    );
}

function matchesComposerArtifactSource(packageName, version, source) {
  return source === `packagist://${packageName}@${version}`
    || source === `composer://${packageName}:${version}`
    || source === `https://repo.packagist.org/p2/${packageName}.json#${version}`
    || source === `https://packagist.org/packages/${packageName}#${version}`;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function artifactInstallEvidenceFailures(evidence, artifactVersions) {
  const failures = [];
  if (!evidence.supplied) {
    failures.push('artifact_install_evidence missing');
  }
  if (evidence.load_error) {
    failures.push(`artifact_install_evidence load failed: ${evidence.load_error}`);
  }
  if (evidence.local_product_source_checkouts_used) {
    failures.push('artifact_install_evidence.local_product_source_checkouts_used=true');
  }
  if (evidence.supplied && !evidence.local_product_source_checkouts_used_explicit_false) {
    failures.push('artifact_install_evidence.local_product_source_checkouts_used=false missing');
  }

  for (const entry of evidence.artifacts) {
    const expectedVersion = artifactVersionFor(artifactVersions, entry.artifact);
    if (entry.status !== 'pass') {
      failures.push(`${entry.artifact}.status=${entry.status || 'missing'}`);
    }
    if (!entry.version_provided) {
      failures.push(`${entry.artifact}.version=missing`);
    } else if (!entry.version || !SEMVER_RE.test(entry.version) || isPlaceholder(entry.version)) {
      failures.push(`${entry.artifact}.version=${entry.version || 'missing'}`);
    } else if (expectedVersion && entry.version !== expectedVersion) {
      failures.push(`${entry.artifact}.version=${entry.version} does not match resolved artifact version ${expectedVersion}`);
    }
    if (!entry.source_provided) {
      failures.push(`${entry.artifact}.source=missing`);
    } else if (!installSourceMatchesArtifact(entry.artifact, entry.version, entry.source)) {
      failures.push(`${entry.artifact}.source=${entry.source}`);
    }
    if (entry.local_product_source_checkouts_used) {
      failures.push(`${entry.artifact}.local_product_source_checkouts_used=true`);
    }
  }

  return failures;
}

function artifactSourcesFromInstallEvidence(evidence) {
  const sources = {};
  for (const entry of evidence.artifacts) {
    sources[entry.artifact] = entry.source || 'not_exercised';
  }
  sources.workflow = sources['workflow-php'] || 'not_exercised';
  return sources;
}

function loadManifest() {
  if (!MANIFEST_PATH || !fs.existsSync(MANIFEST_PATH)) {
    return {};
  }
  return readJsonFile(MANIFEST_PATH);
}

function scenarioDefs(manifest) {
  if (Array.isArray(manifest.scenarios) && manifest.scenarios.length > 0) {
    return manifest.scenarios.filter((item) => item && typeof item === 'object');
  }
  return REQUIRED_SCENARIOS.map((id) => ({
    id,
    expected_behavior: DEFAULT_EXPECTED_BEHAVIOR[id],
  }));
}

function requiredMatrix(manifest) {
  if (manifest.required_matrix && typeof manifest.required_matrix === 'object') {
    return manifest.required_matrix;
  }
  return {
    execution_modes: ['workflow-embedded', 'standalone'],
    runtimes: ['workflow-php', 'sdk-python'],
    activity_cells: [
      { mode: 'workflow-embedded', runtime: 'workflow-php', scenario: 'workflow_embedded_activity_result' },
      { mode: 'workflow-embedded', runtime: 'sdk-python', scenario: 'workflow_embedded_activity_result' },
      { mode: 'standalone', runtime: 'workflow-php', scenario: 'standalone_activity_result' },
      { mode: 'standalone', runtime: 'sdk-python', scenario: 'standalone_activity_result' },
    ],
    behavior_cells: REQUIRED_SCENARIOS.filter((id) => ![
      'published_artifact_install_only',
      'workflow_embedded_activity_result',
      'standalone_activity_result',
    ].includes(id)),
  };
}

function scenarioEvidenceById(evidence) {
  const byId = new Map();
  if (!evidence || typeof evidence !== 'object') {
    return byId;
  }

  const rawResults = evidence.scenario_results || evidence.scenarioResults || evidence.scenarios || [];
  if (Array.isArray(rawResults)) {
    for (const item of rawResults) {
      if (!item || typeof item !== 'object') {
        continue;
      }
      const id = stringValue(item.scenario_id || item.scenarioId || item.id);
      if (id) {
        byId.set(id, item);
      }
    }
  } else if (rawResults && typeof rawResults === 'object') {
    for (const [id, item] of Object.entries(rawResults)) {
      if (item && typeof item === 'object') {
        byId.set(id, { scenario_id: id, ...item });
      }
    }
  }

  return byId;
}

function observedOutputsFor(item) {
  if (!item || typeof item !== 'object') {
    return {};
  }
  for (const key of ['observed_outputs', 'observedOutputs', 'activity_evidence', 'activityEvidence', 'evidence']) {
    if (item[key] && typeof item[key] === 'object' && !Array.isArray(item[key])) {
      return item[key];
    }
  }
  return {};
}

function nonEmptyObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function normalizeClassification(value, fallback) {
  const classification = stringValue(value);
  if (NON_PASS_CLASSIFICATIONS.has(classification)) {
    return classification;
  }
  return fallback;
}

function finding(scenarioId, expectedBehavior, artifactVersions, options) {
  const runnerBlocked = options.runnerBlocked || false;
  const classification = options.classification || (runnerBlocked ? 'runner-gap' : 'coverage-gap');
  const findingType = options.findingType
    || (classification === 'coverage-gap'
      ? 'conformance_runner_coverage_gap'
      : classification.replace('-', '_'));
  const reason = options.reason || '';
  let observed = options.observedBehavior || '';
  if (!observed) {
    if (runnerBlocked) {
      observed = `activities conformance could not execute before product evidence was collected: ${reason}`;
    } else if (classification === 'coverage-gap') {
      observed = 'activities published-artifact evidence did not execute this required scenario; the result is routed as a coverage gap instead of being counted as passing incidental coverage';
      if (reason) {
        observed += `: ${reason}`;
      }
    } else {
      observed = reason || 'activities conformance recorded a non-passing product cell';
    }
  }

  return {
    scenario_id: scenarioId,
    finding_type: findingType,
    classification,
    root_cause_classification: classification,
    owning_surface: options.owner || (classification === 'coverage-gap' || classification === 'runner-gap'
      ? 'conformance_harness'
      : 'activity_runtime'),
    artifact_versions: artifactVersions,
    expected_behavior: expectedBehavior,
    observed_behavior: observed,
    user_visible_reproduction_steps: [
      'Set exact DW_SERVER_VERSION, DW_CLI_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.',
      'Run scripts/conformance/activities-published-artifacts.sh --result-dir <result-dir> with a host-produced activity evidence document.',
      'Inspect activities-result.json for the scenario status, classification, and linked finding.',
    ],
    next_acceptance_criterion: options.nextAcceptanceCriterion
      || (classification === 'coverage-gap'
        ? 'extend the activities host runner to execute this scenario against published artifacts, or replace this coverage-gap finding with a focused product finding from the observed runtime mismatch'
        : 'fix the routed activity conformance root cause and rerun the published-artifact activities experiment'),
    priority: options.priority || (runnerBlocked ? 'P0' : 'P1'),
  };
}

function withCellStatus(cells, status) {
  if (!Array.isArray(cells)) {
    return [];
  }
  return cells
    .filter((cell) => cell && typeof cell === 'object')
    .map((cell) => ({ ...cell, status }));
}

function evidenceStatusSections(status, reason) {
  const section = (extra = {}) => ({
    status,
    reason,
    ...extra,
  });

  return {
    durable_result_recording: section({
      required_behavior: 'activity result survives worker restart and replay without duplicate completion',
    }),
    retry_backoff: section({
      required_behavior: 'attempt count and backoff timing are recorded',
    }),
    timeout_behavior: section({
      required_behavior: 'start-to-close or schedule-to-close timeout is enforced and typed',
    }),
    typed_failure_propagation: section({
      required_behavior: 'failure type, message, and details propagate through history and caller runtime',
    }),
    heartbeat_cancellation: section({
      required_behavior: 'heartbeat details and cancel_requested observation are recorded',
    }),
    idempotent_completion: section({
      required_behavior: 'duplicate completion attempts are deterministic and do not duplicate terminal records',
    }),
    operator_visibility: section({
      required_behavior: 'activity attempt state is visible through API metrics, history, and Waterline',
    }),
  };
}

function sectionFromEvidence(evidence, key, fallback) {
  if (evidence && typeof evidence === 'object' && evidence[key] && typeof evidence[key] === 'object') {
    return evidence[key];
  }
  return fallback;
}

function main() {
  const manifest = loadManifest();
  const scenarios = scenarioDefs(manifest);
  const matrix = requiredMatrix(manifest);
  const suiteVersion = Number.isInteger(manifest.suite_version) ? manifest.suite_version : null;
  let serverImage = env('DW_SERVER_IMAGE');
  const serverVersion = deriveServerVersion(serverImage, env('DW_SERVER_VERSION'));
  if (serverVersion && !serverImage) {
    serverImage = `durableworkflow/server:${serverVersion}`;
  }

  const workflowVersion = env('DW_WORKFLOW_PHP_VERSION');
  const artifactVersions = {
    server: serverVersion,
    cli: normalizeCliVersion(env('DW_CLI_VERSION')),
    'sdk-python': env('DW_PYTHON_SDK_VERSION'),
    workflow: workflowVersion,
    'workflow-php': workflowVersion,
    waterline: env('DW_WATERLINE_VERSION'),
  };
  const publishedArtifactVersions = {
    server: artifactVersions.server,
    cli: artifactVersions.cli,
    'sdk-python': artifactVersions['sdk-python'],
    workflow: artifactVersions.workflow,
    waterline: artifactVersions.waterline,
  };

  const installEvidencePath = env('DW_ACTIVITIES_ARTIFACT_INSTALL_EVIDENCE')
    || path.join(RESULT_DIR, 'artifact-install-evidence.json');
  const installEvidenceLoad = safeLoadJsonFromStringOrPath(
    '',
    installEvidencePath,
    'durable-workflow.v2.activity-runtime.artifact-install-evidence',
  );
  const artifactInstallEvidence = normalizeArtifactInstallEvidence(installEvidenceLoad, artifactVersions);
  const artifactSources = artifactSourcesFromInstallEvidence(artifactInstallEvidence);
  const pinFailures = exactVersionFailures(artifactVersions, serverImage);
  const installFailures = artifactInstallEvidenceFailures(artifactInstallEvidence, artifactVersions);
  const installEvidencePass = pinFailures.length === 0 && installFailures.length === 0;

  const evidencePath = env('DW_ACTIVITIES_EVIDENCE_PATH') || path.join(RESULT_DIR, 'activity-evidence.json');
  const activityEvidenceLoad = safeLoadJsonFromStringOrPath(
    env('DW_ACTIVITIES_EVIDENCE'),
    evidencePath,
    'durable-workflow.v2.activity-runtime.host-evidence',
  );
  const activityEvidence = activityEvidenceLoad.value && typeof activityEvidenceLoad.value === 'object'
    ? activityEvidenceLoad.value
    : {};
  const activityEvidenceById = scenarioEvidenceById(activityEvidence);
  const evidenceLoadFailure = stringValue(activityEvidence.load_error);

  const runnerBlocked = pinFailures.length > 0;
  const blockedReason = pinFailures.join('; ');
  const missingEvidenceReason = activityEvidenceLoad.supplied
    ? evidenceLoadFailure
    : 'activity host evidence missing';
  const defaultNonPassStatus = runnerBlocked ? 'runner_blocked' : 'not_covered';
  const defaultClassification = runnerBlocked ? 'runner-gap' : 'coverage-gap';
  const defaultReason = runnerBlocked ? blockedReason : missingEvidenceReason;
  const findings = [];
  const scenarioResults = [];

  for (const scenario of scenarios) {
    const scenarioId = stringValue(scenario.id);
    if (!scenarioId || !REQUIRED_SCENARIOS.includes(scenarioId)) {
      continue;
    }
    const expectedBehavior = stringValue(scenario.expected_behavior)
      || stringValue(scenario.expectedBehavior)
      || DEFAULT_EXPECTED_BEHAVIOR[scenarioId]
      || 'required activity conformance behavior is observed';
    const supplied = activityEvidenceById.get(scenarioId);

    if (scenarioId === 'published_artifact_install_only') {
      if (!runnerBlocked && installEvidencePass) {
        scenarioResults.push({
          scenario_id: scenarioId,
          status: 'pass',
          expected_behavior: expectedBehavior,
          classification: null,
          observed_outputs: {
            server_image: serverImage,
            cli_release: artifactVersions.cli,
            workflow_php_package: `durable-workflow/workflow:${artifactVersions.workflow}`,
            sdk_python_package: `durable-workflow==${artifactVersions['sdk-python']}`,
            waterline_artifact: `durable-workflow/waterline:${artifactVersions.waterline}`,
            artifact_sources: artifactSources,
            artifact_install_evidence: artifactInstallEvidence,
            artifact_install_evidence_path: installEvidencePath,
          },
          scenario_evidence: {
            artifact_install_evidence: artifactInstallEvidence,
          },
        });
        continue;
      }

      const scenarioReason = runnerBlocked
        ? blockedReason
        : `published artifact install evidence did not pass: ${installFailures.join('; ')}`;
      const status = runnerBlocked ? 'runner_blocked' : 'not_covered';
      const classification = runnerBlocked ? 'runner-gap' : 'coverage-gap';
      const scenarioFinding = finding(scenarioId, expectedBehavior, publishedArtifactVersions, {
        runnerBlocked: status === 'runner_blocked',
        classification,
        reason: scenarioReason,
      });
      findings.push(scenarioFinding);
      scenarioResults.push({
        scenario_id: scenarioId,
        status,
        expected_behavior: expectedBehavior,
        classification,
        observed_outputs: {
          coverage_status: status,
          observed_behavior: scenarioFinding.observed_behavior,
          next_acceptance_criterion: scenarioFinding.next_acceptance_criterion,
          artifact_install_evidence: artifactInstallEvidence,
          artifact_install_evidence_path: installEvidencePath,
          artifact_install_failures: installFailures,
        },
        linked_findings: [scenarioFinding],
      });
      continue;
    }

    if (!runnerBlocked && supplied) {
      let status = normalizedStatus(supplied.status || supplied.outcome || supplied.verdict);
      if (!ALLOWED_STATUSES.has(status)) {
        status = 'fail';
      }
      const observedOutputs = observedOutputsFor(supplied);
      if (status === 'pass' && !nonEmptyObject(observedOutputs)) {
        status = 'fail';
      }

      if (status === 'pass') {
        scenarioResults.push({
          scenario_id: scenarioId,
          status,
          expected_behavior: expectedBehavior,
          classification: null,
          observed_outputs: observedOutputs,
          scenario_evidence: nonEmptyObject(supplied.scenario_evidence || supplied.scenarioEvidence)
            ? (supplied.scenario_evidence || supplied.scenarioEvidence)
            : observedOutputs,
        });
        continue;
      }

      const classification = normalizeClassification(
        supplied.classification || supplied.root_cause_classification || supplied.rootCauseClassification,
        status === 'runner_blocked' ? 'runner-gap' : 'product-gap',
      );
      const scenarioFinding = finding(scenarioId, expectedBehavior, publishedArtifactVersions, {
        runnerBlocked: status === 'runner_blocked',
        classification,
        findingType: supplied.finding_type || supplied.findingType,
        owner: supplied.owning_surface || supplied.owner,
        reason: stringValue(supplied.reason || supplied.observed_behavior || supplied.observedBehavior),
        observedBehavior: stringValue(supplied.observed_behavior || supplied.observedBehavior),
      });
      findings.push(scenarioFinding);
      scenarioResults.push({
        scenario_id: scenarioId,
        status,
        expected_behavior: expectedBehavior,
        classification,
        observed_outputs: nonEmptyObject(observedOutputs)
          ? observedOutputs
          : {
            coverage_status: status,
            observed_behavior: scenarioFinding.observed_behavior,
            next_acceptance_criterion: scenarioFinding.next_acceptance_criterion,
          },
        linked_findings: [scenarioFinding],
      });
      continue;
    }

    let scenarioReason = defaultReason;
    let status = defaultNonPassStatus;
    let classification = defaultClassification;
    const scenarioFinding = finding(scenarioId, expectedBehavior, publishedArtifactVersions, {
      runnerBlocked: status === 'runner_blocked',
      classification,
      reason: scenarioReason,
    });
    findings.push(scenarioFinding);
    scenarioResults.push({
      scenario_id: scenarioId,
      status,
      expected_behavior: expectedBehavior,
      classification,
      observed_outputs: {
        coverage_status: status,
        observed_behavior: scenarioFinding.observed_behavior,
        next_acceptance_criterion: scenarioFinding.next_acceptance_criterion,
        ...(scenarioId === 'published_artifact_install_only'
          ? {
            artifact_install_evidence: artifactInstallEvidence,
            artifact_install_evidence_path: installEvidencePath,
            artifact_install_failures: installFailures,
          }
          : {}),
      },
      linked_findings: [scenarioFinding],
    });
  }

  const nonPassScenarios = scenarioResults.filter((result) => result.status !== 'pass');
  const allRequiredReported = REQUIRED_SCENARIOS.every((id) => scenarioResults.some((result) => result.scenario_id === id));
  const outcome = !runnerBlocked
    && allRequiredReported
    && nonPassScenarios.length === 0
    && installEvidencePass
    && activityEvidenceLoad.supplied
    ? 'pass'
    : (runnerBlocked ? 'non_passing_runner_blocked' : 'non_passing');
  const recordOutcome = outcome === 'pass' ? 'pass' : (runnerBlocked ? 'error' : 'fail');
  const finishedAt = now();
  const sectionStatus = runnerBlocked ? 'runner_blocked' : 'not_covered';
  const sections = evidenceStatusSections(sectionStatus, defaultReason);
  const runtimeMatrix = {
    execution_modes: Array.isArray(matrix.execution_modes) ? matrix.execution_modes : ['workflow-embedded', 'standalone'],
    runtimes: Array.isArray(matrix.runtimes) ? matrix.runtimes : ['workflow-php', 'sdk-python'],
    activity_cells: withCellStatus(matrix.activity_cells, sectionStatus),
    behavior_cells: Array.isArray(matrix.behavior_cells)
      ? matrix.behavior_cells.map((scenario) => ({ scenario, status: sectionStatus }))
      : [],
  };

  const publishedArtifactInstall = {
    status: installEvidencePass ? 'pass' : (runnerBlocked ? 'runner_blocked' : 'not_covered'),
    server_image: serverImage,
    cli_release: artifactVersions.cli,
    workflow_php_package: artifactVersions.workflow
      ? `durable-workflow/workflow:${artifactVersions.workflow}`
      : '',
    sdk_python_package: artifactVersions['sdk-python']
      ? `durable-workflow==${artifactVersions['sdk-python']}`
      : '',
    waterline_artifact: artifactVersions.waterline
      ? `durable-workflow/waterline:${artifactVersions.waterline}`
      : '',
    artifact_sources: artifactSources,
    artifact_install_evidence: artifactInstallEvidence,
    artifact_install_evidence_path: installEvidencePath,
    pin_failures: pinFailures,
    install_failures: installFailures,
  };

  const result = {
    schema: 'durable-workflow.v2.activity-runtime.result',
    schema_version: 1,
    suite_schema: 'durable-workflow.v2.platform-conformance.suite',
    suite_version: suiteVersion,
    category: 'activity_runtime_contract',
    outcome,
    runner_blocked: runnerBlocked,
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    published_artifact_versions: publishedArtifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: artifactInstallEvidence.local_product_source_checkouts_used,
    artifact_install_evidence: artifactInstallEvidence,
    activity_evidence_source: activityEvidenceLoad.source,
    activity_evidence_supplied: activityEvidenceLoad.supplied,
    published_artifact_install: {
      ...sectionFromEvidence(activityEvidence, 'published_artifact_install', {}),
      ...publishedArtifactInstall,
    },
    runtime_matrix: sectionFromEvidence(activityEvidence, 'runtime_matrix', runtimeMatrix),
    topology: {
      namespace: 'activities-conformance',
      task_queue: 'activities-shared',
      required_workers: ['workflow-php', 'sdk-python'],
      execution_modes: ['workflow-embedded', 'standalone'],
    },
    durable_result_recording: sectionFromEvidence(activityEvidence, 'durable_result_recording', sections.durable_result_recording),
    retry_backoff: sectionFromEvidence(activityEvidence, 'retry_backoff', sections.retry_backoff),
    timeout_behavior: sectionFromEvidence(activityEvidence, 'timeout_behavior', sections.timeout_behavior),
    typed_failure_propagation: sectionFromEvidence(activityEvidence, 'typed_failure_propagation', sections.typed_failure_propagation),
    heartbeat_cancellation: sectionFromEvidence(activityEvidence, 'heartbeat_cancellation', sections.heartbeat_cancellation),
    idempotent_completion: sectionFromEvidence(activityEvidence, 'idempotent_completion', sections.idempotent_completion),
    operator_visibility: sectionFromEvidence(activityEvidence, 'operator_visibility', sections.operator_visibility),
    scenario_results: scenarioResults,
    findings,
    finding_links: Object.fromEntries(findings.map((item) => [item.scenario_id, [item]])),
  };

  const metadata = {
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    published_artifact_versions: publishedArtifactVersions,
    artifact_sources: artifactSources,
    artifact_install_evidence_path: installEvidencePath,
    artifact_install_evidence_supplied: artifactInstallEvidence.supplied,
    activity_evidence_source: activityEvidenceLoad.source,
    activity_evidence_supplied: activityEvidenceLoad.supplied,
    scenario_manifest: MANIFEST_PATH,
  };

  const record = {
    experiment: 'activities',
    outcome: recordOutcome,
    runnerBlocked: runnerBlocked,
    artifactVersions: publishedArtifactVersions,
    findings: findings.map((item) => `${item.scenario_id}: ${item.observed_behavior}`),
    resultPath: path.join(RESULT_DIR, 'activities-result.json'),
  };

  fs.mkdirSync(RESULT_DIR, { recursive: true });
  writeJson(path.join(RESULT_DIR, 'pins.json'), artifactVersions);
  writeJson(path.join(RESULT_DIR, 'run-metadata.json'), metadata);
  writeJson(path.join(RESULT_DIR, 'activities-result.json'), result);
  writeJson(path.join(RESULT_DIR, 'activities-record.json'), record);
  writeJson(path.join(RESULT_DIR, 'activities-findings.json'), findings);
  console.log(JSON.stringify(result, null, 2));

  return outcome === 'pass' ? 0 : 1;
}

process.exitCode = main();
JS
