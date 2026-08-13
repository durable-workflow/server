#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

usage() {
  cat <<'USAGE'
Usage: nexus-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Composes the public Nexus conformance result from published-artifact evidence.
The runner never treats a local product checkout as an artifact under test.
Missing Nexus cells are recorded as not_covered with linked coverage findings,
so a host run that reaches this handoff is product/coverage evidence rather
than runner-blocked evidence.

The runner writes these files to the result directory:
  pins.json
  nexus-conformance-result.json
  nexus-conformance-record.json

Environment overrides:
  DW_NEXUS_RESULT_DIR              Result directory. Defaults to a temp dir.
  DW_NEXUS_EVIDENCE_JSON           Optional host evidence JSON with scenario_results.
  DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE
                                    Optional dedicated install-evidence JSON. When
                                    omitted, no result-dir evidence file is reused.
  DW_NEXUS_SKIP_SHARED_SERVICE_PROBE=1
                                    Skip the built-in shared-service probe when
                                    no host evidence JSON is supplied.
  DW_NEXUS_SKIP_REPLAY_CANCEL_PROBE=1
                                    Skip the built-in replay/cancellation probe
                                    when no host evidence JSON is supplied.
  DW_NEXUS_SKIP_PHP_PYTHON_SERVICE_SHARD=1
                                    Skip the published workflow-php/sdk-python
                                    service-call evidence shard.
  DW_NEXUS_KEEP_RUN_ROOT=1          Keep the probe scratch directory.
  DW_NEXUS_SERVER_PORT              Host port for the published server probe.
  DW_NEXUS_SKIP_DOCKER_PULL=1       Reuse a local server image for the probe.
  DW_SERVER_IMAGE                   Exact published server image/tag/digest.
  DW_SERVER_VERSION                Exact published server artifact version.
  DW_CLI_VERSION                   Exact published CLI version.
  DW_WORKFLOW_PHP_VERSION          Exact published Workflow PHP package version.
  DW_PHP_SDK_VERSION               Exact published durable-workflow/sdk package version.
  DW_PYTHON_SDK_VERSION            Exact published Python SDK package version.
  DW_WATERLINE_VERSION             Exact published Waterline package version.
  DW_SERVER_ARTIFACT_SOURCE        Published server artifact source.
  DW_CLI_ARTIFACT_SOURCE           Published CLI artifact source.
  DW_WORKFLOW_ARTIFACT_SOURCE      Published Workflow PHP artifact source.
  DW_PHP_SDK_ARTIFACT_SOURCE       Published PHP SDK artifact source.
  DW_PYTHON_SDK_ARTIFACT_SOURCE    Published Python SDK artifact source.
  DW_WATERLINE_ARTIFACT_SOURCE     Published Waterline artifact source.
USAGE
}

result_dir="${DW_NEXUS_RESULT_DIR:-}"

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

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-nexus.XXXXXX")"
fi
mkdir -p "$result_dir"

should_probe_shared_service() {
  if [[ "${DW_NEXUS_SKIP_SHARED_SERVICE_PROBE:-0}" == "1" ]]; then
    return 1
  fi

  if [[ -z "${DW_NEXUS_EVIDENCE_JSON:-}" ]]; then
    return 0
  fi

  node - "${DW_NEXUS_EVIDENCE_JSON}" <<'NODE'
const fs = require('fs');

const evidencePath = process.argv[2] || '';
const requiredScenarioIds = [
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
  'transient_failure_retries_with_policy',
  'php_caller_python_service',
  'python_caller_php_service',
];
const sharedServicePassRequirements = {
  tenant_a_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-a'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true'},
  ],
  tenant_b_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-b'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true'},
  ],
  transient_failure_retries_with_policy: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string'},
    {fields: ['retry_policy', 'retryPolicy'], kind: 'non_empty_object'},
    {fields: ['retry_attempts', 'retryAttempts', 'history_attempts', 'historyAttempts', 'service_call_attempts', 'serviceCallAttempts'], kind: 'attempts_at_least', min: 2},
    {fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'], kind: 'boolean_true'},
    {fields: ['completed_after_retry', 'completedAfterRetry'], kind: 'boolean_true'},
  ],
  php_caller_python_service: [
    {fields: ['caller_workflow_instance_id', 'callerWorkflowInstanceId', 'caller_workflow_id', 'callerWorkflowId'], kind: 'non_empty_string'},
    {fields: ['caller_workflow_run_id', 'callerWorkflowRunId', 'caller_run_id', 'callerRunId', 'run_id', 'runId'], kind: 'non_empty_string'},
    {fields: ['caller_sdk_language', 'callerSdkLanguage', 'caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'sdk-php'},
    {fields: ['service_sdk_language', 'serviceSdkLanguage', 'service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'sdk-python'},
    {fields: ['operation_name', 'operationName'], kind: 'non_empty_string'},
    {fields: ['request_payload', 'requestPayload', 'request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object'},
    {fields: ['response_or_failure_surface', 'responseOrFailureSurface', 'response', 'responseEvidence', 'invocation_response', 'invocationResponse', 'failure_surface', 'failureSurface', 'invocation_failure', 'invocationFailure'], kind: 'non_empty_object'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string'},
    {fields: ['artifact_tuple', 'artifactTuple', 'artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions', 'resolved_artifact_versions', 'resolvedArtifactVersions'], kind: 'non_empty_object'},
    {fields: ['published_artifact_worker_execution', 'publishedArtifactWorkerExecution', 'published_worker_execution', 'publishedWorkerExecution'], kind: 'non_empty_object'},
    {fields: ['service_health', 'serviceHealth', 'published_service_health', 'publishedServiceHealth'], kind: 'non_empty_object'},
    {fields: ['service_probe_succeeded', 'serviceProbeSucceeded'], kind: 'boolean_true'},
    {fields: ['service_response_payload', 'serviceResponsePayload'], kind: 'non_empty_object'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true'},
    {fields: ['typed_error_probe_succeeded', 'typedErrorProbeSucceeded'], kind: 'boolean_true'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true'},
  ],
  python_caller_php_service: [
    {fields: ['caller_workflow_instance_id', 'callerWorkflowInstanceId', 'caller_workflow_id', 'callerWorkflowId'], kind: 'non_empty_string'},
    {fields: ['caller_workflow_run_id', 'callerWorkflowRunId', 'caller_run_id', 'callerRunId', 'run_id', 'runId'], kind: 'non_empty_string'},
    {fields: ['caller_sdk_language', 'callerSdkLanguage', 'caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'sdk-python'},
    {fields: ['service_sdk_language', 'serviceSdkLanguage', 'service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'workflow-php'},
    {fields: ['operation_name', 'operationName'], kind: 'non_empty_string'},
    {fields: ['request_payload', 'requestPayload', 'request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object'},
    {fields: ['response_or_failure_surface', 'responseOrFailureSurface', 'response', 'responseEvidence', 'invocation_response', 'invocationResponse', 'failure_surface', 'failureSurface', 'invocation_failure', 'invocationFailure'], kind: 'non_empty_object'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string'},
    {fields: ['artifact_tuple', 'artifactTuple', 'artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions', 'resolved_artifact_versions', 'resolvedArtifactVersions'], kind: 'non_empty_object'},
    {fields: ['published_artifact_worker_execution', 'publishedArtifactWorkerExecution', 'published_worker_execution', 'publishedWorkerExecution'], kind: 'non_empty_object'},
    {fields: ['service_health', 'serviceHealth', 'published_service_health', 'publishedServiceHealth'], kind: 'non_empty_object'},
    {fields: ['service_probe_succeeded', 'serviceProbeSucceeded'], kind: 'boolean_true'},
    {fields: ['service_response_payload', 'serviceResponsePayload'], kind: 'non_empty_object'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true'},
    {fields: ['typed_error_probe_succeeded', 'typedErrorProbeSucceeded'], kind: 'boolean_true'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true'},
  ],
};

function readEvidence(path) {
  try {
    return JSON.parse(fs.readFileSync(path, 'utf8'));
  } catch {
    return {};
  }
}

function byScenarioId(items) {
  const indexed = new Map();
  if (Array.isArray(items)) {
    for (const item of items) {
      if (item && typeof item.scenario_id === 'string') {
        indexed.set(item.scenario_id, item);
      }
    }
    return indexed;
  }

  if (items && typeof items === 'object') {
    for (const [scenarioId, item] of Object.entries(items)) {
      if (item && typeof item === 'object') {
        indexed.set(scenarioId, {
          scenario_id: scenarioId,
          ...item,
        });
      }
    }
  }

  return indexed;
}

function hasNonEmptyObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function stringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value).trim()
    : '';
}

function truthy(value) {
  if (value === true) {
    return true;
  }
  return ['1', 'true', 'yes'].includes(stringValue(value).toLowerCase());
}

function evidenceLookup(outputs, fields) {
  const container = outputs && typeof outputs === 'object' && !Array.isArray(outputs) ? outputs : {};
  for (const field of fields) {
    if (Object.prototype.hasOwnProperty.call(container, field)) {
      return {present: true, value: container[field]};
    }
  }

  return {present: false, value: undefined};
}

function isMissingEvidenceValue(value, kind) {
  if (value === null || value === undefined) {
    return true;
  }
  if (kind === 'non_empty_object') {
    return !hasNonEmptyObject(value);
  }
  if (kind === 'attempts_at_least') {
    if (Array.isArray(value)) {
      return value.length === 0;
    }
    return Number(value) === 0 || Number.isNaN(Number(value));
  }

  return stringValue(value) === '';
}

function evidenceRequirementSatisfied(requirement, value) {
  switch (requirement.kind) {
    case 'non_empty_string':
      return stringValue(value) !== '';
    case 'non_empty_object':
      return hasNonEmptyObject(value);
    case 'boolean_true':
      return truthy(value);
    case 'value_equals':
      return stringValue(value) === stringValue(requirement.value);
    case 'attempts_at_least':
      if (Array.isArray(value)) {
        return value.length >= Number(requirement.min);
      }
      return Number(value) >= Number(requirement.min);
    default:
      return false;
  }
}

function hasSharedServicePassEvidence(scenarioId, outputs) {
  const requirements = sharedServicePassRequirements[scenarioId] || [];
  return requirements.length > 0 && requirements.every((requirement) => {
    const lookup = evidenceLookup(outputs, requirement.fields);
    return lookup.present
      && !isMissingEvidenceValue(lookup.value, requirement.kind)
      && evidenceRequirementSatisfied(requirement, lookup.value);
  });
}

function hasAttemptedCallEvidence(scenario) {
  const outputs = scenario?.observed_outputs && typeof scenario.observed_outputs === 'object'
    ? scenario.observed_outputs
    : {};

  return hasNonEmptyObject(outputs.attempted_call_evidence)
    || hasNonEmptyObject(outputs.attemptedCallEvidence);
}

function hasSpecificEvidence(scenarioId, scenario) {
  if (!scenario || typeof scenario !== 'object') {
    return false;
  }

  const status = typeof scenario.status === 'string' ? scenario.status : '';
  const outputs = scenario.observed_outputs && typeof scenario.observed_outputs === 'object'
    ? scenario.observed_outputs
    : {};

  if (status === 'pass') {
    return hasSharedServicePassEvidence(scenarioId, outputs);
  }

  if (status === 'fail') {
    return hasNonEmptyObject(outputs.error_shape)
      || String(outputs.failure_reason || '') !== ''
      || (Array.isArray(scenario.linked_findings) && scenario.linked_findings.length > 0);
  }

  if (status === 'unsupported') {
    return hasAttemptedCallEvidence(scenario);
  }

  return false;
}

const scenarios = byScenarioId(readEvidence(evidencePath).scenario_results);
const missing = requiredScenarioIds.some((scenarioId) => !hasSpecificEvidence(scenarioId, scenarios.get(scenarioId)));
process.exit(missing ? 0 : 1);
NODE
}

if should_probe_shared_service; then
  supplied_evidence_path="${DW_NEXUS_EVIDENCE_JSON:-}"
  generated_evidence_path="$result_dir/shared-service-evidence.json"

  if node - "$result_dir" "$generated_evidence_path" "$supplied_evidence_path" "$script_dir/nexus-replay-transport.cjs" <<'NODE'
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');
const crypto = require('crypto');
const {isDeepStrictEqual} = require('util');
const {spawnSync} = require('child_process');
const {replayPostWithStaleSocketRecovery} = require(process.argv[5]);

const resultDir = process.argv[2];
const evidencePath = process.argv[3];
const suppliedEvidencePath = process.argv[4] || '';
const requiredArtifacts = ['server', 'cli', 'workflow', 'sdk-php', 'sdk-python', 'waterline'];
const artifactOwners = {
  server: 'server',
  cli: 'cli',
  workflow: 'workflow',
  'sdk-php': 'sdk-php',
  'sdk-python': 'sdk-python',
  waterline: 'waterline',
};
const scenarioIds = [
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
];
const crossLanguageScenarioIds = [
  'php_caller_python_service',
  'python_caller_php_service',
];
const adversarialScenarioIds = [
  'endpoint_permission_denied_without_information_leak',
  'malformed_payload_refused_before_dispatch',
  'nonexistent_endpoint_typed_not_found',
];
const builtInProbeScenarioIds = [
  ...scenarioIds,
  'transient_failure_retries_with_policy',
  'permanent_failure_preserves_typed_error',
  ...adversarialScenarioIds,
  'worker_restart_replay_does_not_reissue_call',
  'caller_cancellation_propagates_to_service',
  ...crossLanguageScenarioIds,
];
const artifactAliases = {
  server: ['server'],
  cli: ['cli'],
  workflow: ['workflow', 'workflow-php', 'workflow_php', 'workflowPhp'],
  'sdk-php': ['sdk-php', 'sdk_php'],
  'sdk-python': ['sdk-python', 'sdk_python', 'python-sdk', 'pythonSdk'],
  waterline: ['waterline'],
};

function readJsonFile(filePath) {
  if (filePath === '') {
    return {};
  }
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return {};
  }
}

const suppliedEvidence = readJsonFile(suppliedEvidencePath);

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function env(name) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function stringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value).trim()
    : '';
}

function suppliedMapValue(mapNames, artifact) {
  for (const mapName of mapNames) {
    const map = suppliedEvidence[mapName];
    if (!map || typeof map !== 'object' || Array.isArray(map)) {
      continue;
    }
    for (const alias of artifactAliases[artifact] || [artifact]) {
      const value = map[alias];
      if (typeof value === 'string' && value.trim() !== '') {
        return value.trim();
      }
    }
  }

  return null;
}

function suppliedArtifactVersion(artifact) {
  return suppliedMapValue([
    'artifact_versions',
    'artifactVersions',
    'published_artifact_versions',
    'publishedArtifactVersions',
    'resolved_artifact_versions',
    'resolvedArtifactVersions',
  ], artifact);
}

function suppliedArtifactSource(artifact) {
  return suppliedMapValue([
    'artifact_sources',
    'artifactSources',
    'install_sources',
    'installSources',
  ], artifact);
}

function randomToken(prefix) {
  return `${prefix}-${crypto.randomBytes(12).toString('hex')}`;
}

function ulidLike() {
  return `01${crypto.randomBytes(12).toString('hex').toUpperCase()}`.slice(0, 26);
}

function exactServerVersionFrom(image) {
  const withoutDigest = image.split('@', 1)[0];
  const last = withoutDigest.split('/').pop() || '';
  const tag = last.includes(':') ? last.split(':').pop() : '';
  return isExactSemverRelease(tag) ? tag : null;
}

function serverImage() {
  const explicit = env('DW_SERVER_IMAGE');
  if (explicit !== null) {
    return explicit.replace(/^docker:\/\//, '');
  }

  const source = env('DW_SERVER_ARTIFACT_SOURCE') || suppliedArtifactSource('server');
  if (source !== null && /^(docker:\/\/)?durableworkflow\/server[:@]/.test(source)) {
    return source.replace(/^docker:\/\//, '');
  }

  const version = env('DW_SERVER_VERSION') || suppliedArtifactVersion('server');
  return version === null ? null : `durableworkflow/server:${version}`;
}

function artifactVersions(image) {
  return {
    server: env('DW_SERVER_VERSION') || suppliedArtifactVersion('server') || (image === null ? null : exactServerVersionFrom(image)),
    cli: env('DW_CLI_VERSION') || suppliedArtifactVersion('cli'),
    workflow: env('DW_WORKFLOW_PHP_VERSION') || env('DW_WORKFLOW_VERSION') || suppliedArtifactVersion('workflow'),
    'sdk-php': env('DW_PHP_SDK_VERSION') || suppliedArtifactVersion('sdk-php'),
    'sdk-python': env('DW_PYTHON_SDK_VERSION') || env('DW_SDK_PYTHON_VERSION') || suppliedArtifactVersion('sdk-python'),
    waterline: env('DW_WATERLINE_VERSION') || suppliedArtifactVersion('waterline'),
  };
}

function compactObject(object) {
  return Object.fromEntries(Object.entries(object).filter(([, value]) => value !== null && value !== undefined && value !== ''));
}

function artifactSources(versions, image) {
  return compactObject({
    server: env('DW_SERVER_ARTIFACT_SOURCE')
      || suppliedArtifactSource('server')
      || (image === null ? null : `docker://${image}`),
    cli: env('DW_CLI_ARTIFACT_SOURCE')
      || suppliedArtifactSource('cli')
      || (versions.cli ? `https://github.com/durable-workflow/cli/releases/download/${versions.cli}/install.sh` : null),
    workflow: env('DW_WORKFLOW_ARTIFACT_SOURCE')
      || env('DW_WORKFLOW_PHP_ARTIFACT_SOURCE')
      || suppliedArtifactSource('workflow')
      || (versions.workflow ? `packagist://durable-workflow/workflow@${versions.workflow}` : null),
    'sdk-php': env('DW_PHP_SDK_ARTIFACT_SOURCE')
      || suppliedArtifactSource('sdk-php')
      || (versions['sdk-php'] ? `packagist://durable-workflow/sdk@${versions['sdk-php']}` : null),
    'sdk-python': env('DW_PYTHON_SDK_ARTIFACT_SOURCE')
      || suppliedArtifactSource('sdk-python')
      || (versions['sdk-python'] ? `pypi://durable-workflow==${versions['sdk-python']}` : null),
    waterline: env('DW_WATERLINE_ARTIFACT_SOURCE')
      || suppliedArtifactSource('waterline')
      || (versions.waterline ? `packagist://durable-workflow/waterline@${versions.waterline}` : null),
  });
}

async function httpDownloadable(url) {
  const headers = {'User-Agent': 'durable-workflow-nexus-conformance'};
  for (const method of ['HEAD', 'GET']) {
    const requestHeaders = {...headers};
    if (method === 'GET') {
      requestHeaders.Range = 'bytes=0-0';
    }
    try {
      const response = await fetch(url, {
        method,
        headers: requestHeaders,
        redirect: 'follow',
      });
      if (response.status >= 200 && response.status < 400) {
        return true;
      }
    } catch {
      if (method === 'GET') {
        return false;
      }
    }
  }

  return false;
}

async function fetchJson(url) {
  const response = await fetch(url, {
    headers: {'User-Agent': 'durable-workflow-nexus-conformance'},
    redirect: 'follow',
  });
  if (!response.ok) {
    throw new Error(`${url} returned HTTP ${response.status}`);
  }

  return response.json();
}

async function verifyGithubReleaseAsset(version, source) {
  if (!await httpDownloadable(source)) {
    throw new Error(`CLI release asset is not downloadable: ${source}`);
  }

  return {
    version,
    source,
    status: 'asset_resolved',
    downloadable: true,
    asset_exists: true,
    verified_at: timestamp(),
  };
}

async function verifyPackagistPackage(packageName, version, source) {
  const metadataUrl = `https://repo.packagist.org/p2/${packageName}.json`;
  const payload = await fetchJson(metadataUrl);
  const versions = Array.isArray(payload.packages?.[packageName])
    ? payload.packages[packageName]
    : [];
  if (!versions.some((entry) => String(entry.version || '') === version)) {
    throw new Error(`Packagist package ${packageName} does not publish ${version}`);
  }

  return {
    version,
    source,
    status: 'package_resolved',
    package_exists: true,
    manifest_resolved: true,
    metadata_url: `${metadataUrl}#${version}`,
    verified_at: timestamp(),
  };
}

function pythonReleaseIdentity(version) {
  const normalized = stringValue(version);
  const stable = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.exec(normalized);
  if (stable) return normalized;
  const semver = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)$/i.exec(normalized);
  if (semver) {
    const phase = semver[4].toLowerCase() === 'alpha' ? 'a' : (semver[4].toLowerCase() === 'beta' ? 'b' : 'rc');
    return `${semver[1]}.${semver[2]}.${semver[3]}${phase}${semver[5]}`;
  }
  const pep440 = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)$/i.exec(normalized);
  return pep440
    ? `${pep440[1]}.${pep440[2]}.${pep440[3]}${pep440[4].toLowerCase()}${pep440[5]}`
    : null;
}

async function verifyPypiPackage(version, source) {
  const metadataUrl = `https://pypi.org/pypi/durable-workflow/${encodeURIComponent(version)}/json`;
  const payload = await fetchJson(metadataUrl);
  const resolvedVersion = String(payload.info?.version || '').trim();
  const expectedIdentity = pythonReleaseIdentity(version);
  if (expectedIdentity === null || pythonReleaseIdentity(resolvedVersion) !== expectedIdentity) {
    throw new Error(`PyPI durable-workflow metadata resolved ${resolvedVersion || '<missing>'}, expected ${version}`);
  }

  return {
    version: resolvedVersion,
    source,
    status: 'package_resolved',
    package_exists: true,
    manifest_resolved: true,
    metadata_url: metadataUrl,
    verified_at: timestamp(),
  };
}

async function verifyPublishedArtifactSource(artifact, version, source, serverDigest) {
  switch (artifact) {
    case 'server':
      if (!serverDigest || !/@sha256:[0-9a-f]{64}$/i.test(serverDigest)) {
        throw new Error('server image digest was not resolved after pull');
      }
      return {
        version,
        source,
        status: 'image_manifest_resolved',
        downloadable: true,
        manifest_resolved: true,
        image_digest: serverDigest,
        verified_at: timestamp(),
      };
    case 'cli':
      return verifyGithubReleaseAsset(version, source);
    case 'workflow':
      return verifyPackagistPackage('durable-workflow/workflow', version, source);
    case 'sdk-php':
      return verifyPackagistPackage('durable-workflow/sdk', version, source);
    case 'sdk-python':
      return verifyPypiPackage(version, source);
    case 'waterline':
      return verifyPackagistPackage('durable-workflow/waterline', version, source);
    default:
      throw new Error(`unsupported artifact ${artifact}`);
  }
}

async function artifactSourceVerification(versions, sources, serverDigest) {
  const verification = {};
  const failures = [];
  for (const artifact of requiredArtifacts) {
    if (!versions[artifact] || !sources[artifact]) {
      failures.push({
        artifact,
        reason: `missing ${artifact} version or source`,
      });
      continue;
    }
    try {
      verification[artifact] = await verifyPublishedArtifactSource(
        artifact,
        versions[artifact],
        sources[artifact],
        serverDigest,
      );
    } catch (error) {
      verification[artifact] = {
        version: versions[artifact],
        source: sources[artifact],
        status: 'resolution_failed',
        downloadable: false,
        error: `${error.name}: ${error.message}`,
        verified_at: timestamp(),
      };
      failures.push({
        artifact,
        reason: `${error.name}: ${error.message}`,
      });
    }
  }

  return {verification, failures};
}

function commandAvailable(command, args = ['--version']) {
  const result = spawnSync(command, args, {encoding: 'utf8'});
  return result.status === 0;
}

function runLogged(command, args, logPath, options = {}) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
    ...options,
  });
  fs.writeFileSync(
    logPath,
    [
      `$ ${command} ${args.join(' ')}`,
      `exit_status=${result.status ?? 'null'}`,
      result.stdout || '',
      result.stderr || '',
    ].join('\n'),
  );

  if (result.status !== 0) {
    throw new Error(`${command} ${args.join(' ')} failed; see ${logPath}`);
  }

  return result.stdout || '';
}

function freePort(options = {}) {
  const honorServerPortOverride = options.honorServerPortOverride !== false;
  const requested = honorServerPortOverride ? env('DW_NEXUS_SERVER_PORT') : null;
  if (requested !== null) {
    return Promise.resolve(Number(requested));
  }

  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      const port = typeof address === 'object' && address !== null ? address.port : 0;
      server.close(() => resolve(port));
    });
  });
}

function composeYaml(image, port, token) {
  const escapedImage = JSON.stringify(image);
  const escapedToken = JSON.stringify(token);
  return `
services:
  bootstrap:
    image: ${escapedImage}
    command: ["server-bootstrap"]
    environment: &server_environment
      APP_ENV: local
      APP_DEBUG: "false"
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: durable_workflow
      DB_USERNAME: workflow
      DB_PASSWORD: workflow
      REDIS_HOST: redis
      QUEUE_CONNECTION: redis
      CACHE_STORE: redis
      DW_AUTH_DRIVER: token
      DW_AUTH_TOKEN: ${escapedToken}
      DW_AUTH_BACKWARD_COMPATIBLE: "true"
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
  server:
    image: ${escapedImage}
    ports:
      - "127.0.0.1:${port}:8080"
    environment:
      <<: *server_environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 5s
      timeout: 5s
      retries: 24
  worker:
    image: ${escapedImage}
    command: php artisan queue:work --sleep=1 --tries=3 --max-time=3600
    environment:
      <<: *server_environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: worker_node
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      server:
        condition: service_healthy
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: durable_workflow
      MYSQL_USER: workflow
      MYSQL_PASSWORD: workflow
      MYSQL_ROOT_PASSWORD: root
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 3s
      retries: 30
  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 10
volumes:
  mysql_data:
  redis_data:
`;
}

async function waitForReady(baseUrl, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastError = '';
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${baseUrl}/api/ready`);
      if (response.ok) {
        return;
      }
      lastError = `${response.status} ${await response.text().catch(() => '')}`.trim();
    } catch (error) {
      lastError = `${error.name}: ${error.message}`;
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  throw new Error(`server did not become ready: ${lastError}`);
}

async function apiRequest(baseUrl, token, namespace, method, apiPath, body = null) {
  const headers = {
    Authorization: `Bearer ${token}`,
    'X-Durable-Workflow-Control-Plane-Version': '2',
    'X-Namespace': namespace,
    Accept: 'application/json',
  };
  const init = {method, headers};
  if (body !== null) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  const response = await fetch(`${baseUrl}/api${apiPath}`, init);
  const rawBody = await response.text();
  let parsed = null;
  try {
    parsed = rawBody === '' ? null : JSON.parse(rawBody);
  } catch {
    parsed = {raw_body: rawBody};
  }

  return {
    request: {method, path: `/api${apiPath}`, namespace, body},
    status: response.status,
    ok: response.ok,
    body: parsed,
    raw_body: rawBody,
  };
}

function productFinding(scenarioId, versions, observed, expected, next, type = 'shared_service_tenant_invocation_failed') {
  return {
    scenario_id: scenarioId,
    type,
    finding_type: type,
    owning_surface: 'server',
    artifact_versions: compactObject(versions),
    observed_behavior: observed,
    expected_behavior: expected,
    next_acceptance_criterion: next,
  };
}

function failureScenario(scenarioId, versions, reason, evidence = {}) {
  return {
    scenario_id: scenarioId,
    status: 'fail',
    observed_outputs: {
      caller_namespace: scenarioId === 'tenant_a_calls_shared_service' ? 'tenant-a' : 'tenant-b',
      target_namespace: 'shared',
      endpoint_name: 'shared-greeter',
      service_name: 'Greeter',
      operation_name: 'greet',
      error_shape: evidence,
      failure_reason: reason,
    },
    linked_findings: [
      productFinding(
        scenarioId,
        versions,
        `${scenarioId} failed: ${reason}. Observed ${JSON.stringify(evidence).slice(0, 1000)}`,
        'tenant-a and tenant-b can invoke shared:Greeter.greet through the published Nexus service-call surface and inspect request, response, durable service-call, and caller-history evidence.',
        `fix the shared-service Nexus invocation path for ${scenarioId} and rerun the published-artifact Nexus conformance probe`,
      ),
    ],
  };
}

function passScenario(scenarioId, callerNamespace, request, response, serviceCallRecord, callerHistory) {
  const serviceCallId = response.body && response.body.service_call_id
    ? String(response.body.service_call_id)
    : String(serviceCallRecord.body?.service_call_id || serviceCallRecord.body?.id || '');

  return {
    scenario_id: scenarioId,
    status: 'pass',
    observed_outputs: {
      caller_namespace: callerNamespace,
      target_namespace: 'shared',
      endpoint_name: 'shared-greeter',
      service_name: 'Greeter',
      operation_name: 'greet',
      service_call_id: serviceCallId,
      workflow_result: String(response.body?.status || response.body?.outcome || 'accepted'),
      request: request.request,
      response: {
        status: response.status,
        body: response.body,
      },
      service_call_record: serviceCallRecord.body,
      caller_history_evidence: callerHistory.body,
      caller_history_recorded: true,
    },
    linked_findings: [],
  };
}

async function ensureNamespace(baseUrl, token, namespace) {
  const response = await apiRequest(baseUrl, token, 'default', 'POST', '/namespaces', {
    name: namespace,
    description: `Nexus conformance namespace ${namespace}`,
  });
  if (![200, 201, 409].includes(response.status)) {
    throw new Error(`namespace ${namespace} create failed: ${JSON.stringify(response.body)}`);
  }
  return response;
}

async function setupSharedService(baseUrl, token) {
  const endpoint = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints', {
    endpoint_name: 'shared-greeter',
    description: 'Nexus conformance shared Greeter endpoint',
    metadata: {conformance: 'nexus-shared-service'},
  });
  if (![200, 201, 409].includes(endpoint.status)) {
    throw new Error(`endpoint create failed: ${JSON.stringify(endpoint.body)}`);
  }

  const service = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints/shared-greeter/services', {
    service_name: 'Greeter',
    description: 'Shared Greeter service for Nexus conformance',
    metadata: {conformance: 'nexus-shared-service'},
  });
  if (![200, 201, 409].includes(service.status)) {
    throw new Error(`service create failed: ${JSON.stringify(service.body)}`);
  }

  const operation = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints/shared-greeter/services/Greeter/operations', {
    operation_name: 'greet',
    description: 'Return a greeting for the supplied name',
    operation_mode: 'async',
    handler_binding_kind: 'activity_execution',
    handler_target_reference: 'Greeter.greet',
    handler_binding: {
      activity_type: 'Greeter.greet',
    },
    retry_policy: {
      maximum_attempts: 3,
      initial_interval_seconds: 1,
    },
    cancellation_policy: {
      allow_cancel: true,
      documented_propagation_window_ms: 10000,
    },
    boundary_policy: {
      authorization: {
        caller_namespaces: {
          allow: ['tenant-a', 'tenant-b'],
        },
      },
    },
    metadata: {conformance: 'nexus-shared-service'},
  });
  if (![200, 201, 409].includes(operation.status)) {
    throw new Error(`operation create failed: ${JSON.stringify(operation.body)}`);
  }

  const retryOperation = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints/shared-greeter/services/Greeter/operations', {
    operation_name: 'greet-retry',
    description: 'Retrying greeting operation for Nexus transient-failure conformance',
    operation_mode: 'sync',
    handler_binding_kind: 'query_workflow',
    handler_target_reference: 'Greeter.greet',
    handler_binding: {
      workflow_instance_id: 'shared-greeter-transient-service',
      query_name: 'greet',
    },
    retry_policy: {
      max_attempts: 3,
      backoff_seconds: [0, 0],
    },
    boundary_policy: {
      authorization: {
        caller_namespaces: {
          allow: ['tenant-a', 'tenant-b'],
        },
      },
    },
    metadata: {conformance: 'nexus-transient-retry-service'},
  });
  if (![200, 201, 409].includes(retryOperation.status)) {
    throw new Error(`retry operation create failed: ${JSON.stringify(retryOperation.body)}`);
  }

  const permanentOperation = await apiRequest(baseUrl, token, 'shared', 'POST', '/service-endpoints/shared-greeter/services/Greeter/operations', {
    operation_name: 'greet-permanent',
    description: 'Permanently failing greeting operation for Nexus typed-error conformance',
    operation_mode: 'sync',
    handler_binding_kind: 'query_workflow',
    handler_target_reference: 'Greeter.greet',
    handler_binding: {
      workflow_instance_id: 'shared-greeter-permanent-service',
      query_name: 'greet',
    },
    retry_policy: {
      max_attempts: 3,
      backoff_seconds: [0, 0],
      non_retryable_error_types: ['SharedGreeterUnavailable'],
    },
    boundary_policy: {
      authorization: {
        caller_namespaces: {
          allow: ['tenant-a', 'tenant-b'],
        },
      },
    },
    metadata: {conformance: 'nexus-permanent-typed-error-service'},
  });
  if (![200, 201, 409].includes(permanentOperation.status)) {
    throw new Error(`permanent operation create failed: ${JSON.stringify(permanentOperation.body)}`);
  }

  return {endpoint, service, operation, retryOperation, permanentOperation};
}

async function invokeSharedService(baseUrl, token, callerNamespace, versions) {
  const scenarioId = callerNamespace === 'tenant-a'
    ? 'tenant_a_calls_shared_service'
    : 'tenant_b_calls_shared_service';
  const callerWorkflowInstanceId = `${callerNamespace}-call-greeter`;
  const callerWorkflowRunId = ulidLike();
  const requestBody = {
    arguments: {
      name: 'world',
      caller_namespace: callerNamespace,
    },
    mode_override: 'async',
    wait_for: 'accepted',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: `${callerNamespace}-${crypto.randomBytes(6).toString('hex')}`,
    metadata: {
      conformance: 'nexus-shared-service',
      expected_greeting: 'hello, world',
    },
  };
  const execute = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    requestBody,
  );

  if (!execute.ok || execute.body?.accepted !== true || !execute.body?.service_call_id) {
    return failureScenario(scenarioId, versions, 'execute returned a non-accepted response', {
      request: execute.request,
      status: execute.status,
      body: execute.body,
    });
  }

  const serviceCallId = String(execute.body.service_call_id);
  const describe = await apiRequest(
    baseUrl,
    token,
    'shared',
    'GET',
    `/service-endpoints/shared-greeter/services/Greeter/operations/greet/service-calls/${encodeURIComponent(serviceCallId)}`,
  );
  const history = await apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(callerWorkflowInstanceId)}/runs/${encodeURIComponent(callerWorkflowRunId)}/nexus-operations`,
  );
  const historyRows = Array.isArray(history.body?.nexus_operations) ? history.body.nexus_operations : [];
  const historyContainsCall = historyRows.some((row) => String(row.service_call_id || '') === serviceCallId);

  if (!describe.ok || describe.body?.found !== true) {
    return failureScenario(scenarioId, versions, 'service-call describe did not return the durable call', {
      execute: {status: execute.status, body: execute.body},
      describe: {status: describe.status, body: describe.body},
    });
  }
  if (!history.ok || !historyContainsCall) {
    return failureScenario(scenarioId, versions, 'caller-history evidence did not include the durable call', {
      execute: {status: execute.status, body: execute.body},
      history: {status: history.status, body: history.body},
    });
  }

  return passScenario(scenarioId, callerNamespace, execute, execute, describe, history);
}

function errorTypeFrom(response, fallback) {
  return String(
    response.body?.error_type
    || response.body?.errorType
    || response.body?.type
    || response.body?.reason
    || response.body?.outcome
    || fallback
    || '',
  );
}

function refusalStatusFrom(response) {
  return String(
    response.body?.outcome
    || response.body?.status
    || response.body?.reason
    || `http_${response.status}`,
  );
}

function endpointLeakDetails(response, tokens) {
  const body = response.body && typeof response.body === 'object' ? response.body : {};
  const explicitFields = [
    'endpoint_id',
    'endpoint_name',
    'service_id',
    'service_name',
    'operation_id',
    'operation_name',
  ].filter((field) => Object.hasOwn(body, field));
  const lowerBody = JSON.stringify(body).toLowerCase();
  const leakedTokens = tokens.filter((token) => lowerBody.includes(String(token).toLowerCase()));

  return {
    disclosed: explicitFields.length > 0 || leakedTokens.length > 0,
    explicit_fields: explicitFields,
    leaked_tokens: leakedTokens,
  };
}

function responseEvidence(response) {
  return {
    status: response.status,
    body: response.body,
    raw_body: response.raw_body,
  };
}

async function callerHistory(baseUrl, token, callerNamespace, workflowId, runId) {
  return apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}/nexus-operations`,
  );
}

function isNoDispatchOutcome(outcome) {
  const normalized = String(outcome || '').toLowerCase();
  return normalized.startsWith('rejected_')
    || ['cancelled', 'canceled', 'timed_out'].includes(normalized);
}

function isDispatchLikeHistoryRow(row) {
  const status = String(row.status || '').toLowerCase();
  const outcome = String(row.outcome || '').toLowerCase();
  return ['accepted', 'started', 'completed'].includes(status)
    || ['accepted', 'started', 'completed', 'handler_failed'].includes(outcome)
    || (status === 'failed' && outcome !== '' && !isNoDispatchOutcome(outcome));
}

function dispatchEvidence(response, history, serviceCallId = '', options = {}) {
  const historyRows = historyRowsFrom(history);
  const matchingRows = serviceCallId === ''
    ? historyRows
    : historyRows.filter((row) => String(row.service_call_id || '') === serviceCallId);
  const matchingRejectedRows = matchingRows.filter((row) => {
    const outcome = String(row.outcome || '');
    return isNoDispatchOutcome(outcome);
  });
  const dispatchLikeRows = matchingRows.filter(isDispatchLikeHistoryRow);
  const responseAccepted = response.body?.accepted === true;
  const historySucceeded = history.ok === true
    && history.body
    && typeof history.body === 'object'
    && Array.isArray(history.body.nexus_operations);
  const requireRejectedHistory = options.requireRejectedHistory === true;
  const historyStateProven = historySucceeded
    && (serviceCallId === ''
      ? !requireRejectedHistory && matchingRows.length === 0
      : matchingRejectedRows.length > 0);

  return {
    handler_dispatch_count: responseAccepted ? 1 : dispatchLikeRows.length,
    service_invoked: responseAccepted || dispatchLikeRows.length > 0,
    service_call_id: serviceCallId,
    caller_history_query_succeeded: historySucceeded,
    caller_history_state_proven: historyStateProven,
    caller_history_rows: matchingRows,
    matching_rejected_history_count: matchingRejectedRows.length,
    caller_history_response: responseSummary(history),
  };
}

async function probeEndpointPermissionDenied(baseUrl, token, versions) {
  const scenarioId = 'endpoint_permission_denied_without_information_leak';
  const callerNamespace = 'tenant-c';
  const callerWorkflowInstanceId = `${callerNamespace}-forbidden-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const requestBody = {
    arguments: {
      name: 'world',
      caller_namespace: callerNamespace,
    },
    mode_override: 'async',
    wait_for: 'accepted',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: `${callerNamespace}-${crypto.randomBytes(6).toString('hex')}`,
    metadata: {
      conformance: scenarioId,
    },
  };

  const refusal = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    requestBody,
  );
  const serviceCallId = serviceCallIdFrom(refusal);
  const history = await callerHistory(
    baseUrl,
    token,
    callerNamespace,
    callerWorkflowInstanceId,
    callerWorkflowRunId,
  );
  const leak = endpointLeakDetails(refusal, ['shared-greeter', 'Greeter', 'greet']);
  const dispatch = dispatchEvidence(refusal, history, serviceCallId, {requireRejectedHistory: true});
  const observedOutputs = {
    caller_namespace: callerNamespace,
    target_namespace: 'shared',
    endpoint_name: 'shared-greeter',
    service_name: 'Greeter',
    operation_name: 'greet',
    refusal_status: refusalStatusFrom(refusal),
    error_type: errorTypeFrom(refusal, 'permission_denied'),
    authorization_refusal_disclosed_endpoint_existence: leak.disclosed,
    endpoint_existence_disclosure_evidence: leak,
    handler_dispatch_count: dispatch.handler_dispatch_count,
    service_invoked: dispatch.service_invoked,
    service_call_id: serviceCallId,
    caller_history_query_succeeded: dispatch.caller_history_query_succeeded,
    caller_history_state_proven: dispatch.caller_history_state_proven,
    request: refusal.request,
    response: responseEvidence(refusal),
    dispatch_evidence: dispatch,
    caller_history_evidence: history.body,
  };
  const typedPermissionDenied = refusal.status === 403
    && ['rejected_forbidden', 'caller_namespace_denied', 'forbidden'].includes(errorTypeFrom(refusal, '').toLowerCase());

  if (
    typedPermissionDenied
    && !leak.disclosed
    && dispatch.handler_dispatch_count === 0
    && dispatch.service_invoked === false
    && dispatch.caller_history_query_succeeded
    && dispatch.caller_history_state_proven
  ) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  const failureType = leak.disclosed
    ? 'permission_denied_information_leak'
    : (!dispatch.caller_history_query_succeeded || !dispatch.caller_history_state_proven
        ? 'nexus_refusal_no_dispatch_evidence_gap'
        : 'nexus_authorization_refusal_shape_drift');

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      failureType,
      leak.disclosed
        ? `Permission-denied response disclosed endpoint-specific fields: ${JSON.stringify(leak)}`
        : `Permission-denied probe did not prove typed refusal plus no dispatch: ${JSON.stringify(observedOutputs).slice(0, 1000)}`,
      'Unauthorized Nexus callers receive a typed permission-denied refusal that does not disclose whether the endpoint, service, or operation exists.',
      'fix Nexus authorization refusal shape and rerun the endpoint isolation cell',
    ),
  ]);
}

async function probeMalformedPayloadRefusal(baseUrl, token, versions) {
  const scenarioId = 'malformed_payload_refused_before_dispatch';
  const callerNamespace = 'tenant-a';
  const callerWorkflowInstanceId = `${callerNamespace}-malformed-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const requestBody = {
    arguments: {
      name: 'world',
    },
    mode_override: 'async',
    wait_for: 'dispatch_anyway',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: `${callerNamespace}-malformed-${crypto.randomBytes(6).toString('hex')}`,
    metadata: {
      conformance: scenarioId,
    },
  };

  const refusal = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    requestBody,
  );
  const history = await callerHistory(
    baseUrl,
    token,
    callerNamespace,
    callerWorkflowInstanceId,
    callerWorkflowRunId,
  );
  const dispatch = dispatchEvidence(refusal, history);
  const observedOutputs = {
    caller_namespace: callerNamespace,
    target_namespace: 'shared',
    endpoint_name: 'shared-greeter',
    service_name: 'Greeter',
    operation_name: 'greet',
    refusal_status: refusalStatusFrom(refusal),
    error_type: errorTypeFrom(refusal, 'validation_failed'),
    typed_error: errorTypeFrom(refusal, 'validation_failed'),
    handler_dispatch_count: dispatch.handler_dispatch_count,
    service_invoked: dispatch.service_invoked,
    caller_history_query_succeeded: dispatch.caller_history_query_succeeded,
    caller_history_state_proven: dispatch.caller_history_state_proven,
    request: refusal.request,
    response: responseEvidence(refusal),
    dispatch_evidence: dispatch,
    caller_history_evidence: history.body,
  };
  const pass = refusal.status === 422
    && errorTypeFrom(refusal, '').toLowerCase() === 'validation_failed'
    && dispatch.handler_dispatch_count === 0
    && dispatch.service_invoked === false
    && dispatch.caller_history_query_succeeded
    && dispatch.caller_history_state_proven;

  if (pass) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      dispatch.service_invoked
        ? 'malformed_payload_dispatched'
        : (!dispatch.caller_history_query_succeeded || !dispatch.caller_history_state_proven
            ? 'nexus_refusal_no_dispatch_evidence_gap'
            : 'malformed_payload_refusal_shape_drift'),
      `Malformed payload refusal evidence did not satisfy the pre-dispatch contract: ${JSON.stringify(observedOutputs).slice(0, 1000)}`,
      'Malformed Nexus operation payloads are rejected with a typed validation error before service-call admission or handler dispatch.',
      'fix Nexus malformed-payload refusal and rerun the adversarial payload cell',
    ),
  ]);
}

async function probeNonexistentEndpointNotFound(baseUrl, token, versions) {
  const scenarioId = 'nonexistent_endpoint_typed_not_found';
  const callerNamespace = 'tenant-a';
  const missingEndpoint = `missing-greeter-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowInstanceId = `${callerNamespace}-missing-endpoint-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const requestBody = {
    arguments: {
      name: 'world',
    },
    mode_override: 'async',
    wait_for: 'accepted',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: `${callerNamespace}-missing-${crypto.randomBytes(6).toString('hex')}`,
    metadata: {
      conformance: scenarioId,
    },
  };

  const refusal = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    `/service-endpoints/${encodeURIComponent(missingEndpoint)}/services/Greeter/operations/greet/execute`,
    requestBody,
  );
  const history = await callerHistory(
    baseUrl,
    token,
    callerNamespace,
    callerWorkflowInstanceId,
    callerWorkflowRunId,
  );
  const dispatch = dispatchEvidence(refusal, history);
  const observedOutputs = {
    caller_namespace: callerNamespace,
    target_namespace: 'shared',
    endpoint_name: missingEndpoint,
    service_name: 'Greeter',
    operation_name: 'greet',
    refusal_status: refusalStatusFrom(refusal),
    error_type: errorTypeFrom(refusal, 'endpoint_not_found'),
    typed_error: errorTypeFrom(refusal, 'endpoint_not_found'),
    handler_dispatch_count: dispatch.handler_dispatch_count,
    service_invoked: dispatch.service_invoked,
    caller_history_query_succeeded: dispatch.caller_history_query_succeeded,
    caller_history_state_proven: dispatch.caller_history_state_proven,
    request: refusal.request,
    response: responseEvidence(refusal),
    dispatch_evidence: dispatch,
    caller_history_evidence: history.body,
  };
  const pass = refusal.status === 404
    && errorTypeFrom(refusal, '').toLowerCase() === 'endpoint_not_found'
    && dispatch.handler_dispatch_count === 0
    && dispatch.service_invoked === false
    && dispatch.caller_history_query_succeeded
    && dispatch.caller_history_state_proven;

  if (pass) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      dispatch.service_invoked
        ? 'nonexistent_endpoint_dispatched'
        : (!dispatch.caller_history_query_succeeded || !dispatch.caller_history_state_proven
            ? 'nexus_refusal_no_dispatch_evidence_gap'
            : 'nonexistent_endpoint_error_shape_drift'),
      `Nonexistent endpoint refusal evidence did not satisfy the typed not-found contract: ${JSON.stringify(observedOutputs).slice(0, 1000)}`,
      'Invoking a nonexistent Nexus endpoint returns a typed not-found error without admitting or dispatching a service call.',
      'fix Nexus nonexistent-endpoint refusal and rerun the not-found adversarial cell',
    ),
  ]);
}

function publishedServerWorkerExecution(versions, sources, image) {
  return {
    local_product_source_checkouts_used: false,
    artifacts: [
      {
        artifact: 'server',
        version: versions.server,
        source: sources.server || (image === null ? null : `docker://${image}`),
        status: 'pass',
        execution_context: 'published_server_image_worker_service',
        local_product_source_checkout_used_as_artifact: false,
      },
    ],
    workers: [
      {
        role: 'server_worker',
        service: 'worker',
        image,
        restarted_during_probe: true,
      },
    ],
  };
}

function artifactTuple(versions, sources, verification) {
  return {
    artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: verification,
    local_product_source_checkouts_used: false,
    artifacts: requiredArtifacts.map((artifact) => ({
      artifact,
      version: versions[artifact],
      source: sources[artifact],
      source_verification: verification[artifact],
      local_product_source_checkout_used_as_artifact: false,
    })),
  };
}

function callerWorkflowInvocationEvidence(language, runtimeEvidence) {
  const surface = reflectedPublicServiceCallSurface(runtimeEvidence);
  const matchedMethods = Array.isArray(surface?.matched_methods)
    ? surface.matched_methods.filter((method) => stringValue(method) !== '')
    : [];
  const available = publicSurfaceAvailable(surface);

  return {
    executed: available,
    execution_mode: 'published_service_call_surface_probe',
    sdk_language: language,
    public_method: matchedMethods[0] || null,
    matched_methods: matchedMethods,
    public_service_call_surface: surface,
    local_product_source_checkouts_used: false,
  };
}

function runtimeKeyForLanguage(language) {
  return language === 'workflow-php' ? 'php' : 'python';
}

function validServiceHealthResponse(language, response) {
  const body = response?.body && typeof response.body === 'object' && !Array.isArray(response.body)
    ? response.body
    : {};
  const status = Number(response?.status ?? 0);

  return response?.ok === true
    && status >= 200
    && status < 300
    && stringValue(body.runtime) === language
    && body.service_started === true
    && body.package_imported === true;
}

function serviceHealthFailureSummary(summary) {
  const status = Number(summary?.status ?? 0);
  const body = summary?.body && typeof summary.body === 'object' && !Array.isArray(summary.body)
    ? summary.body
    : {};
  const detail = stringValue(body.error)
    || stringValue(body.raw_body)
    || stringValue(summary?.raw_body)
    || JSON.stringify(body).slice(0, 500);

  return `status=${status}; ok=${summary?.ok === true}; ${detail}`;
}

function publishedServiceHealthEvidence(language, runtimeEvidence) {
  const healthResponse = runtimeEvidence?.health_response
    && typeof runtimeEvidence.health_response === 'object'
    && !Array.isArray(runtimeEvidence.health_response)
    ? runtimeEvidence.health_response
    : {};
  const body = healthResponse.body && typeof healthResponse.body === 'object' && !Array.isArray(healthResponse.body)
    ? healthResponse.body
    : {};
  const healthSucceeded = validServiceHealthResponse(language, healthResponse);

  return {
    sdk_language: language,
    endpoint: '/health',
    health_succeeded: healthSucceeded,
    service_started: healthSucceeded,
    package_imported: body.package_imported === true || runtimeEvidence?.package_imported === true,
    package_version: stringValue(body.package_version) || stringValue(runtimeEvidence?.package_version),
    container_image: runtimeEvidence?.container_image || null,
    health_response: healthResponse,
    service_runtime_surface: runtimeEvidence?.service_runtime_surface || null,
    public_service_call_surface: reflectedPublicServiceCallSurface(runtimeEvidence),
    local_product_source_checkouts_used: false,
  };
}

function publishedCrossLanguageWorkerExecution(versions, sources, verification, runtimeEvidence) {
  const phpServiceHealth = publishedServiceHealthEvidence('workflow-php', runtimeEvidence.php);
  const pythonServiceHealth = publishedServiceHealthEvidence('sdk-python', runtimeEvidence.python);

  return {
    local_product_source_checkouts_used: false,
    worker_execution_mode: 'published_php_python_service_call_shard',
    source_integrity_statement: 'workflow-php, sdk-php, and sdk-python were installed from published package channels inside disposable runtime containers; no local product checkout path was mounted as an artifact under test',
    service_health: {
      'workflow-php': phpServiceHealth,
      'sdk-python': pythonServiceHealth,
    },
    artifacts: [
      {
        artifact: 'workflow-php',
        version: versions.workflow,
        source: sources.workflow,
        status: runtimeEvidence.php?.package_imported === true ? 'pass' : 'fail',
        install_channel: 'packagist',
        source_verification: verification.workflow,
        service_health_succeeded: phpServiceHealth.health_succeeded === true,
        service_health: phpServiceHealth,
        local_product_source_checkout_used_as_artifact: false,
        local_product_source_checkouts_used: false,
      },
      {
        artifact: 'sdk-php',
        version: versions['sdk-php'],
        source: sources['sdk-php'],
        status: runtimeEvidence.php?.sdk_package_version === versions['sdk-php'] ? 'pass' : 'fail',
        install_channel: 'packagist',
        source_verification: verification['sdk-php'],
        service_health_succeeded: phpServiceHealth.health_succeeded === true,
        service_health: phpServiceHealth,
        local_product_source_checkout_used_as_artifact: false,
        local_product_source_checkouts_used: false,
      },
      {
        artifact: 'sdk-python',
        version: versions['sdk-python'],
        source: sources['sdk-python'],
        status: runtimeEvidence.python?.package_imported === true ? 'pass' : 'fail',
        install_channel: 'pypi',
        source_verification: verification['sdk-python'],
        service_health_succeeded: pythonServiceHealth.health_succeeded === true,
        service_health: pythonServiceHealth,
        local_product_source_checkout_used_as_artifact: false,
        local_product_source_checkouts_used: false,
      },
    ],
    workers: [
      {
        role: 'sdk_php_remote_client',
        sdk_language: 'sdk-php',
        package_version: runtimeEvidence.php?.sdk_package_version || versions['sdk-php'],
        container_image: runtimeEvidence.php?.container_image || 'composer:2',
        service_started: phpServiceHealth.health_succeeded === true,
        public_service_call_surface: reflectedPublicServiceCallSurface(runtimeEvidence.php),
        caller_workflow_invocation: callerWorkflowInvocationEvidence('sdk-php', runtimeEvidence.php),
      },
      {
        role: 'workflow_php_runtime_service',
        sdk_language: 'workflow-php',
        package_version: runtimeEvidence.php?.package_version || versions.workflow,
        container_image: runtimeEvidence.php?.container_image || 'composer:2',
        service_started: phpServiceHealth.health_succeeded === true,
        service_health_succeeded: phpServiceHealth.health_succeeded === true,
        service_health: phpServiceHealth,
        service_runtime_surface: runtimeEvidence.php?.service_runtime_surface || null,
        public_service_call_surface: reflectedPublicServiceCallSurface(runtimeEvidence.php),
        caller_workflow_invocation: callerWorkflowInvocationEvidence('workflow-php', runtimeEvidence.php),
      },
      {
        role: 'sdk_python_runtime_service',
        sdk_language: 'sdk-python',
        package_version: runtimeEvidence.python?.package_version || versions['sdk-python'],
        container_image: runtimeEvidence.python?.container_image || 'python:3.12-slim',
        service_started: pythonServiceHealth.health_succeeded === true,
        service_health_succeeded: pythonServiceHealth.health_succeeded === true,
        service_health: pythonServiceHealth,
        service_runtime_surface: runtimeEvidence.python?.service_runtime_surface || null,
        public_service_call_surface: reflectedPublicServiceCallSurface(runtimeEvidence.python),
        caller_workflow_invocation: callerWorkflowInvocationEvidence('sdk-python', runtimeEvidence.python),
      },
    ],
    runtime_evidence: runtimeEvidence,
  };
}

function phpPublishedServiceScript() {
  return `<?php
declare(strict_types=1);

use Workflow\\Serializers\\Serializer;
use Workflow\\V2\\Support\\InvocableHttpAdapter;
use Composer\\InstalledVersions;

require '/tmp/dw-php/vendor/autoload.php';

final class NexusPublishedServiceError extends RuntimeException {}

$installedVersion = class_exists(InstalledVersions::class)
    ? (InstalledVersions::getPrettyVersion('durable-workflow/workflow') ?: InstalledVersions::getVersion('durable-workflow/workflow') ?: null)
    : null;
$sdkVersion = class_exists(InstalledVersions::class)
    ? (InstalledVersions::getPrettyVersion('durable-workflow/sdk') ?: InstalledVersions::getVersion('durable-workflow/sdk') ?: null)
    : null;
$sdkClientClass = 'DurableWorkflow\\\\Client';
$workflowClass = 'Workflow\\\\V2\\\\Workflow';
$invocableAdapterClass = 'Workflow\\\\V2\\\\Support\\\\InvocableHttpAdapter';
$invocableHandlerClass = 'Workflow\\\\V2\\\\Support\\\\InvocableActivityHandler';
$sdkClientMethods = class_exists($sdkClientClass) ? get_class_methods($sdkClientClass) : [];
$candidateMethods = [
    'startServiceOperation',
    'executeServiceOperation',
    'serviceOperation',
    'executeServiceCall',
    'callService',
    'invokeService',
    'executeNexusOperation',
    'invokeNexusService',
    'serviceCall',
];
$serviceCallMethods = array_values(array_intersect($candidateMethods, $sdkClientMethods));

function emit_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function iso_future(int $seconds = 300): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . $seconds . ' seconds')
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\\TH:i:s.u\\Z');
}

function external_task_input(array $payload): array {
    $expiresAt = iso_future();

    return [
        'schema' => 'durable-workflow.v2.external-task-input',
        'version' => 1,
        'task' => [
            'id' => 'nexus-php-service-task',
            'kind' => 'activity_task',
            'attempt' => 1,
            'activity_attempt_id' => 'nexus-php-service-attempt',
            'task_queue' => 'nexus-php-service',
            'handler' => 'nexus.greeter',
            'connection' => null,
            'idempotency_key' => 'nexus-php-service-attempt',
        ],
        'workflow' => [
            'id' => (string) ($payload['caller_workflow_instance_id'] ?? 'nexus-php-service'),
            'run_id' => (string) ($payload['caller_workflow_run_id'] ?? 'nexus-php-service-run'),
        ],
        'lease' => [
            'owner' => 'published-workflow-php-service',
            'expires_at' => $expiresAt,
            'heartbeat_endpoint' => '/api/worker/activity-tasks/nexus-php-service-task/heartbeat',
        ],
        'payloads' => [
            'arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', [
                    (string) ($payload['name'] ?? 'world'),
                    (string) ($payload['scenario'] ?? 'nexus'),
                ]),
            ],
        ],
        'deadlines' => [
            'schedule_to_start' => $expiresAt,
            'start_to_close' => $expiresAt,
            'schedule_to_close' => $expiresAt,
            'heartbeat' => $expiresAt,
        ],
        'headers' => (object) [],
    ];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$body = file_get_contents('php://input') ?: '';
$decoded = json_decode($body, true);
if (! is_array($decoded)) {
    $decoded = [];
}

$base = [
    'runtime' => 'workflow-php',
    'package_imported' => class_exists($workflowClass) && class_exists($sdkClientClass) && class_exists($invocableAdapterClass),
    'package_version' => $installedVersion,
    'sdk_package_version' => $sdkVersion,
    'sdk_client_present' => class_exists($sdkClientClass),
    'workflow_authoring_class_present' => class_exists($workflowClass),
    'service_runtime_surface' => [
        'available' => class_exists($invocableAdapterClass) && class_exists($invocableHandlerClass),
        'checked_classes' => [$invocableAdapterClass, $invocableHandlerClass],
        'handler' => 'nexus.greeter',
        'carrier' => 'published-workflow-php-service',
    ],
    'service_call_methods' => $serviceCallMethods,
    'public_service_call_surface' => [
        'available' => count($serviceCallMethods) > 0,
        'checked_classes' => [$sdkClientClass],
        'candidate_methods' => $candidateMethods,
        'matched_methods' => $serviceCallMethods,
    ],
];

if ($path === '/health') {
    emit_json($base + ['service_started' => true]);
    return;
}

if ($path === '/greeter') {
    if (! class_exists($invocableAdapterClass)) {
        emit_json($base + [
            'service_started' => true,
            'request_payload' => $decoded,
            'runtime_error' => 'Workflow\\\\V2\\\\Support\\\\InvocableHttpAdapter is not available in the published workflow-php package',
        ], 500);
        return;
    }

    $adapter = new InvocableHttpAdapter([
        'nexus.greeter' => function (string $name = 'world', string $scenario = 'nexus') use ($decoded): array {
            if (str_ends_with($scenario, '_typed_error')) {
                throw new NexusPublishedServiceError('published workflow-php typed error');
            }

            return [
                'message' => 'hello from workflow-php, ' . $name,
                'scenario' => $scenario,
                'request_payload' => $decoded,
            ];
        },
    ], carrier: 'published-workflow-php-service', resultCodec: 'avro');
    $adapterResponse = $adapter->handle(json_encode(external_task_input($decoded), JSON_THROW_ON_ERROR));
    $adapterBody = json_decode((string) ($adapterResponse['body'] ?? ''), true);
    if (! is_array($adapterBody)) {
        $adapterBody = ['raw_body' => (string) ($adapterResponse['body'] ?? '')];
    }
    if (isset($adapterBody['result']['payload']) && is_array($adapterBody['result']['payload'])) {
        $payloadEnvelope = $adapterBody['result']['payload'];
        $adapterBody['decoded_payload'] = Serializer::unserializeWithCodec(
            (string) ($payloadEnvelope['codec'] ?? ''),
            (string) ($payloadEnvelope['blob'] ?? ''),
        );
    }

    emit_json($base + [
        'service_started' => true,
        'request_payload' => $decoded,
        'invocable_http_response' => [
            'status' => (int) ($adapterResponse['status'] ?? 0),
            'headers' => $adapterResponse['headers'] ?? [],
            'body' => $adapterBody,
        ],
    ], (int) ($adapterResponse['status'] ?? 200));
    return;
}

emit_json($base + ['error' => 'not_found', 'path' => $path], 404);
`;
}

function pythonPublishedServiceScript() {
  return `from __future__ import annotations

import asyncio
import json
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from importlib.metadata import version

from durable_workflow import serializer
from durable_workflow.client import Client
from durable_workflow.invocable import InvocableActivityHandler
from durable_workflow.workflow import WorkflowContext

PACKAGE_VERSION = version("durable-workflow")


class NexusPublishedServiceError(Exception):
    pass


CANDIDATE_CLIENT_METHODS = [
    "execute_service_call",
    "call_service",
    "invoke_service",
    "execute_nexus_operation",
    "invoke_nexus_service",
    "service_call",
]
CANDIDATE_CONTEXT_METHODS = [
    "call_nexus_service",
    "start_nexus_operation",
    "execute_service_call",
    "call_service",
    "invoke_service",
    "nexus_call",
    "service_call",
]


def iso_future(seconds: int = 300) -> str:
    return (datetime.now(timezone.utc) + timedelta(seconds=seconds)).isoformat(timespec="microseconds").replace("+00:00", "Z")


def public_surface() -> dict:
    client_matches = [name for name in CANDIDATE_CLIENT_METHODS if hasattr(Client, name)]
    context_matches = [name for name in CANDIDATE_CONTEXT_METHODS if hasattr(WorkflowContext, name)]
    return {
        "available": bool(client_matches or context_matches),
        "checked_classes": ["durable_workflow.client.Client", "durable_workflow.workflow.WorkflowContext"],
        "candidate_methods": CANDIDATE_CLIENT_METHODS + CANDIDATE_CONTEXT_METHODS,
        "matched_methods": client_matches + context_matches,
    }


async def run_invocable(payload: dict) -> dict:
    async def greet(name: str = "world", scenario: str = "nexus") -> dict:
        if scenario.endswith("_typed_error"):
            raise NexusPublishedServiceError("published sdk-python typed error")

        return {
            "message": f"hello from sdk-python, {name}",
            "scenario": scenario,
            "request_payload": payload,
        }

    envelope = {
        "schema": "durable-workflow.v2.external-task-input",
        "version": 1,
        "task": {
            "id": "nexus-python-service-task",
            "kind": "activity_task",
            "attempt": 1,
            "activity_attempt_id": "nexus-python-service-attempt",
            "task_queue": "nexus-python-service",
            "handler": "nexus.greeter",
            "connection": None,
            "idempotency_key": "nexus-python-service-attempt",
        },
        "workflow": {
            "id": str(payload.get("caller_workflow_instance_id") or "nexus-python-service"),
            "run_id": str(payload.get("caller_workflow_run_id") or "nexus-python-service-run"),
        },
        "lease": {
            "owner": "published-sdk-python-service",
            "expires_at": iso_future(),
            "heartbeat_endpoint": "/api/worker/activity-tasks/nexus-python-service-task/heartbeat",
        },
        "payloads": {
            "arguments": serializer.envelope([
                str(payload.get("name") or "world"),
                str(payload.get("scenario") or "nexus"),
            ], codec=serializer.AVRO_CODEC),
        },
        "deadlines": {
            "schedule_to_start": iso_future(),
            "start_to_close": iso_future(),
            "schedule_to_close": iso_future(),
            "heartbeat": iso_future(),
        },
        "headers": {},
    }
    result = await InvocableActivityHandler(
        {"nexus.greeter": greet},
        carrier="published-sdk-python-nexus-shard",
        result_codec=serializer.AVRO_CODEC,
    ).handle(envelope)
    if "result" in result and "payload" in result["result"]:
        result["decoded_payload"] = serializer.decode_envelope(result["result"]["payload"])
    return result


class Handler(BaseHTTPRequestHandler):
    def _json(self, payload: dict, status: int = 200) -> None:
        body = json.dumps(payload, indent=2, sort_keys=True).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        if self.path.split("?", 1)[0] != "/health":
            self._json({"error": "not_found", "path": self.path}, 404)
            return
        self._json({
            "runtime": "sdk-python",
            "package_imported": True,
            "package_version": PACKAGE_VERSION,
            "service_started": True,
            "service_runtime_surface": {
                "available": True,
                "checked_classes": ["durable_workflow.invocable.InvocableActivityHandler"],
                "handler": "nexus.greeter",
                "carrier": "published-sdk-python-nexus-shard",
            },
            "public_service_call_surface": public_surface(),
        })

    def do_POST(self) -> None:
        raw = self.rfile.read(int(self.headers.get("Content-Length", "0") or "0"))
        try:
            payload = json.loads(raw.decode() or "{}")
        except json.JSONDecodeError:
            payload = {}
        if self.path.split("?", 1)[0] != "/greeter":
            self._json({"error": "not_found", "path": self.path}, 404)
            return
        result = asyncio.run(run_invocable(payload))
        self._json({
            "runtime": "sdk-python",
            "package_imported": True,
            "package_version": PACKAGE_VERSION,
            "service_started": True,
            "service_runtime_surface": {
                "available": True,
                "checked_classes": ["durable_workflow.invocable.InvocableActivityHandler"],
                "handler": "nexus.greeter",
                "carrier": "published-sdk-python-nexus-shard",
            },
            "public_service_call_surface": public_surface(),
            "request_payload": payload,
            "invocable_result": result,
        })

    def log_message(self, fmt: str, *args: object) -> None:
        return


ThreadingHTTPServer(("0.0.0.0", 8091), Handler).serve_forever()
`;
}

function dockerComposeNetwork(project) {
  return `${project}_default`;
}

function dockerRunDetached(args, logPath) {
  const result = spawnSync('docker', ['run', '-d', ...args], {
    encoding: 'utf8',
    maxBuffer: 16 * 1024 * 1024,
  });
  fs.writeFileSync(
    logPath,
    [
      `$ docker run -d ${args.join(' ')}`,
      `exit_status=${result.status ?? 'null'}`,
      result.stdout || '',
      result.stderr || '',
    ].join('\n'),
  );
  if (result.status !== 0) {
    throw new Error(`docker run -d ${args.join(' ')} failed; see ${logPath}`);
  }

  return (result.stdout || '').trim();
}

function dockerStop(containerName, logPath) {
  const result = spawnSync('docker', ['rm', '-f', containerName], {
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  fs.writeFileSync(
    logPath,
    [
      `$ docker rm -f ${containerName}`,
      `exit_status=${result.status ?? 'null'}`,
      result.stdout || '',
      result.stderr || '',
    ].join('\n'),
  );
}

function dockerExecJson(containerName, command, logPath) {
  const result = spawnSync('docker', ['exec', containerName, 'sh', '-lc', command], {
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  fs.writeFileSync(
    logPath,
    [
      `$ docker exec ${containerName} sh -lc ${JSON.stringify(command)}`,
      `exit_status=${result.status ?? 'null'}`,
      result.stdout || '',
      result.stderr || '',
    ].join('\n'),
  );

  if (result.status !== 0) {
    return {
      ok: false,
      status: 0,
      body: {
        error: `docker exec ${containerName} failed with status ${result.status ?? 'null'}`,
        stderr: (result.stderr || '').slice(-2000),
      },
      raw_body: result.stdout || '',
    };
  }

  try {
    return {
      ok: true,
      status: 0,
      body: JSON.parse(result.stdout || '{}'),
      raw_body: result.stdout || '',
    };
  } catch (error) {
    return {
      ok: false,
      status: 0,
      body: {
        error: `docker exec ${containerName} returned non-JSON output: ${error.name}: ${error.message}`,
        stdout: (result.stdout || '').slice(-2000),
      },
      raw_body: result.stdout || '',
    };
  }
}

async function waitForJson(url, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastError = '';
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url);
      const rawBody = await response.text();
      let body = null;
      try {
        body = rawBody === '' ? null : JSON.parse(rawBody);
      } catch {
        body = {raw_body: rawBody};
      }
      if (response.ok) {
        return {ok: true, status: response.status, body, raw_body: rawBody};
      }
      lastError = `HTTP ${response.status}: ${rawBody}`;
    } catch (error) {
      lastError = `${error.name}: ${error.message}`;
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }

  return {ok: false, status: 0, body: {error: lastError}, raw_body: ''};
}

async function postJson(url, body) {
  try {
    const response = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/json', Accept: 'application/json'},
      body: JSON.stringify(body),
    });
    const rawBody = await response.text();
    let parsed = null;
    try {
      parsed = rawBody === '' ? null : JSON.parse(rawBody);
    } catch {
      parsed = {raw_body: rawBody};
    }

    return {ok: response.ok, status: response.status, body: parsed, raw_body: rawBody};
  } catch (error) {
    return {ok: false, status: 0, body: {error: `${error.name}: ${error.message}`}, raw_body: ''};
  }
}

async function startPythonSdkService(compose, versions) {
  const port = await freePort({honorServerPortOverride: false});
  const scriptPath = path.join(compose.runRoot, 'nexus-python-sdk-service.py');
  const containerName = `${compose.project}-python-sdk-service`;
  fs.writeFileSync(scriptPath, pythonPublishedServiceScript());
  dockerRunDetached([
    '--name', containerName,
    '--network', dockerComposeNetwork(compose.project),
    '--network-alias', 'nexus-python-sdk-service',
    '-p', `127.0.0.1:${port}:8091`,
    '-v', `${compose.runRoot}:/work:ro`,
    'python:3.12-slim',
    'sh',
    '-lc',
    `python -m venv /tmp/dw-python && . /tmp/dw-python/bin/activate && pip install --no-cache-dir durable-workflow==${JSON.stringify(versions['sdk-python']).slice(1, -1)} >/tmp/dw-python-pip.log 2>&1 && python /work/nexus-python-sdk-service.py`,
  ], path.join(resultDir, 'nexus-python-sdk-service-start.log'));

  const health = await waitForJson(`http://127.0.0.1:${port}/health`, 120000);
  return {containerName, port, health};
}

async function startPhpWorkflowService(compose, versions) {
  const port = await freePort({honorServerPortOverride: false});
  const scriptPath = path.join(compose.runRoot, 'nexus-php-workflow-service.php');
  const containerName = `${compose.project}-php-workflow-service`;
  fs.writeFileSync(scriptPath, phpPublishedServiceScript());
  dockerRunDetached([
    '--name', containerName,
    '--network', dockerComposeNetwork(compose.project),
    '--network-alias', 'nexus-php-workflow-service',
    '-p', `127.0.0.1:${port}:8092`,
    '-v', `${compose.runRoot}:/work:ro`,
    'composer:2',
    'sh',
    '-lc',
    `mkdir -p /tmp/dw-php && cd /tmp/dw-php && composer init --no-interaction --name=dw/nexus-conformance --require=durable-workflow/workflow:${JSON.stringify(versions.workflow).slice(1, -1)} --require=durable-workflow/sdk:${JSON.stringify(versions['sdk-php']).slice(1, -1)} >/tmp/dw-php-composer-init.log 2>&1 && composer update --no-interaction --prefer-dist --no-progress >/tmp/dw-php-composer-update.log 2>&1 && php -S 0.0.0.0:8092 /work/nexus-php-workflow-service.php`,
  ], path.join(resultDir, 'nexus-php-workflow-service-start.log'));

  const health = await waitForJson(`http://127.0.0.1:${port}/health`, 180000);
  return {containerName, port, health};
}

function reflectPublishedPythonSdkSurface(containerName) {
  return dockerExecJson(containerName, `. /tmp/dw-python/bin/activate && python <<'PY'
import json
from importlib.metadata import version

from durable_workflow.client import Client
from durable_workflow.invocable import InvocableActivityHandler
from durable_workflow.workflow import WorkflowContext

client_candidates = [
    "execute_service_call",
    "call_service",
    "invoke_service",
    "execute_nexus_operation",
    "invoke_nexus_service",
    "service_call",
]
context_candidates = [
    "call_nexus_service",
    "start_nexus_operation",
    "execute_service_call",
    "call_service",
    "invoke_service",
    "nexus_call",
    "service_call",
]
client_matches = [name for name in client_candidates if hasattr(Client, name)]
context_matches = [name for name in context_candidates if hasattr(WorkflowContext, name)]
service_call_methods = client_matches + context_matches
print(json.dumps({
    "runtime": "sdk-python",
    "package_imported": True,
    "package_version": version("durable-workflow"),
    "service_call_methods": service_call_methods,
    "service_runtime_surface": {
        "available": InvocableActivityHandler is not None,
        "checked_classes": ["durable_workflow.invocable.InvocableActivityHandler"],
        "handler": "nexus.greeter",
        "carrier": "published-sdk-python-nexus-shard",
        "source": "container_reflection",
    },
    "public_service_call_surface": {
        "available": bool(service_call_methods),
        "checked_classes": ["durable_workflow.client.Client", "durable_workflow.workflow.WorkflowContext"],
        "candidate_methods": client_candidates + context_candidates,
        "matched_methods": service_call_methods,
        "source": "container_reflection",
    },
}, sort_keys=True))
PY`, path.join(resultDir, 'nexus-python-sdk-service-reflection.log'));
}

function reflectPublishedPhpWorkflowSurface(containerName) {
  return dockerExecJson(containerName, `cd /tmp/dw-php && php <<'PHP'
<?php
require 'vendor/autoload.php';

$sdkClientClass = DurableWorkflow\\Client::class;
$workflowClass = Workflow\\V2\\Workflow::class;
$invocableAdapterClass = Workflow\\V2\\Support\\InvocableHttpAdapter::class;
$invocableHandlerClass = Workflow\\V2\\Support\\InvocableActivityHandler::class;
$candidateMethods = [
    'startServiceOperation',
    'executeServiceOperation',
    'serviceOperation',
    'executeServiceCall',
    'callService',
    'invokeService',
    'executeNexusOperation',
    'invokeNexusService',
    'serviceCall',
];
$sdkClientMethods = class_exists($sdkClientClass) ? get_class_methods($sdkClientClass) : [];
$serviceCallMethods = array_values(array_intersect($candidateMethods, $sdkClientMethods));
$installedVersion = class_exists(Composer\\InstalledVersions::class)
    ? (Composer\\InstalledVersions::getPrettyVersion('durable-workflow/workflow') ?: null)
    : null;
$sdkVersion = class_exists(Composer\\InstalledVersions::class)
    ? (Composer\\InstalledVersions::getPrettyVersion('durable-workflow/sdk') ?: null)
    : null;

echo json_encode([
    'runtime' => 'workflow-php',
    'package_imported' => class_exists($sdkClientClass) && class_exists($workflowClass),
    'package_version' => $installedVersion,
    'sdk_package_version' => $sdkVersion,
    'service_call_methods' => $serviceCallMethods,
    'service_runtime_surface' => [
        'available' => class_exists($invocableAdapterClass) && class_exists($invocableHandlerClass),
        'checked_classes' => [$invocableAdapterClass, $invocableHandlerClass],
        'handler' => 'nexus.greeter',
        'carrier' => 'published-workflow-php-service',
        'source' => 'container_reflection',
    ],
    'public_service_call_surface' => [
        'available' => count($serviceCallMethods) > 0,
        'checked_classes' => [$sdkClientClass],
        'candidate_methods' => $candidateMethods,
        'matched_methods' => $serviceCallMethods,
        'source' => 'container_reflection',
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
PHP`, path.join(resultDir, 'nexus-php-workflow-service-reflection.log'));
}

function executePublishedPhpSdkServiceOperation(
  containerName,
  token,
  operation,
  argumentsValue,
  callerNamespace,
  callerWorkflowId,
  callerRunId,
) {
  const request = {
    server_url: 'http://server:8080',
    token,
    namespace: operation.targetNamespace,
    caller_namespace: callerNamespace,
    endpoint_name: operation.endpointName,
    service_name: operation.serviceName,
    operation_name: operation.operationName,
    arguments: argumentsValue,
    caller_workflow_id: callerWorkflowId,
    caller_run_id: callerRunId,
    idempotency_key: `${callerWorkflowId}-nexus-${crypto.randomBytes(5).toString('hex')}`,
  };
  const encoded = Buffer.from(JSON.stringify(request), 'utf8').toString('base64');
  const response = dockerExecJson(containerName, `cd /tmp/dw-php && php <<'PHP'
<?php
require 'vendor/autoload.php';

use DurableWorkflow\\Client;
use DurableWorkflow\\Model\\ServiceOperationOptions;

$input = json_decode(base64_decode('${encoded}'), true, 512, JSON_THROW_ON_ERROR);
$client = new Client(
    (string) $input['server_url'],
    namespace: (string) $input['namespace'],
    token: (string) $input['token'],
);
$handle = $client->startServiceOperation(
    (string) $input['endpoint_name'],
    (string) $input['service_name'],
    (string) $input['operation_name'],
    $input['arguments'] ?? null,
    new ServiceOperationOptions(
        modeOverride: 'async',
        waitFor: 'accepted',
        idempotencyKey: (string) $input['idempotency_key'],
        callerNamespace: (string) $input['caller_namespace'],
        callerWorkflowId: (string) $input['caller_workflow_id'],
        callerRunId: (string) $input['caller_run_id'],
    ),
);
echo json_encode($handle->started->raw + [
    'service_call_id' => $handle->serviceCallId,
    'sdk_version' => Composer\\InstalledVersions::getPrettyVersion('durable-workflow/sdk'),
    'client_process_id' => getmypid(),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
PHP`, path.join(resultDir, 'nexus-php-sdk-client-execute.log'));
  response.status = response.ok ? 202 : 0;
  response.request = request;

  return response;
}

async function setupCrossLanguageService(baseUrl, token, target, serviceUrl) {
  const targetNamespace = 'shared';
  const endpointName = `${target}-published-service`;
  const serviceName = 'PublishedGreeter';
  const operationName = target === 'python' ? 'greet-python' : 'greet-php';
  const serviceLanguage = target === 'python' ? 'sdk-python' : 'workflow-php';

  const endpoint = await apiRequest(baseUrl, token, targetNamespace, 'POST', '/service-endpoints', {
    endpoint_name: endpointName,
    description: `Published ${serviceLanguage} Nexus conformance endpoint`,
    metadata: {conformance: 'nexus-published-php-python-service-shard'},
  });
  const service = await apiRequest(baseUrl, token, targetNamespace, 'POST', `/service-endpoints/${endpointName}/services`, {
    service_name: serviceName,
    description: `Published ${serviceLanguage} Greeter service for Nexus conformance`,
    metadata: {conformance: 'nexus-published-php-python-service-shard', service_sdk_language: serviceLanguage},
  });
  const operation = await apiRequest(baseUrl, token, targetNamespace, 'POST', `/service-endpoints/${endpointName}/services/${serviceName}/operations`, {
    operation_name: operationName,
    description: `Invoke the published ${serviceLanguage} Greeter service`,
    operation_mode: 'async',
    handler_binding_kind: 'invocable_http',
    handler_target_reference: serviceUrl,
    handler_binding: {
      carrier_endpoint: serviceUrl,
      carrier_handler: 'nexus.greeter',
      carrier: `${target}-published-service`,
    },
    boundary_policy: {
      authorization: {
        caller_namespaces: {
          allow: ['tenant-a', 'tenant-b'],
        },
      },
    },
    metadata: {conformance: 'nexus-published-php-python-service-shard'},
  });

  return {targetNamespace, endpointName, serviceName, operationName, endpoint, service, operation};
}

function publicSurfaceAvailable(surface) {
  return surface && typeof surface === 'object' && surface.available === true;
}

function reflectedPublicServiceCallSurface(runtimeEvidence) {
  const surface = runtimeEvidence?.public_service_call_surface || null;
  if (publicSurfaceAvailable(surface)) {
    return surface;
  }

  const matchedMethods = [
    ...(Array.isArray(surface?.matched_methods) ? surface.matched_methods : []),
    ...(Array.isArray(runtimeEvidence?.service_call_methods) ? runtimeEvidence.service_call_methods : []),
  ].map((method) => stringValue(method)).filter((method) => method !== '');

  if (matchedMethods.length === 0) {
    return surface;
  }

  return {
    ...(surface && typeof surface === 'object' && !Array.isArray(surface) ? surface : {}),
    available: true,
    matched_methods: Array.from(new Set(matchedMethods)),
    source: surface === null ? 'service_call_methods' : 'public_service_call_surface_with_method_fallback',
  };
}

function serviceHealthEvidenceFromWorkerExecution(workerExecution, language) {
  const key = runtimeKeyForLanguage(language);
  const serviceHealth = workerExecution?.service_health;
  if (serviceHealth && typeof serviceHealth === 'object' && !Array.isArray(serviceHealth)) {
    for (const field of [language, key, language.replace('-', '_'), `${key}_service`, `${key}Service`]) {
      const value = serviceHealth[field];
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        return value;
      }
    }
  }

  const runtimeEvidence = workerExecution?.runtime_evidence?.[key] || {};
  return publishedServiceHealthEvidence(language, runtimeEvidence);
}

function crossLanguageFinding(scenarioId, versions, owningSurface, observed, expected, next, type = 'nexus_unsupported_surface') {
  return {
    scenario_id: scenarioId,
    type,
    finding_type: type,
    owning_surface: owningSurface,
    artifact_versions: compactObject(versions),
    observed_behavior: observed,
    expected_behavior: expected,
    next_acceptance_criterion: next,
  };
}

function serviceProbeResultEnvelope(serviceProbe) {
  const body = serviceProbe?.body;
  if (!body || typeof body !== 'object' || Array.isArray(body)) {
    return null;
  }

  for (const candidate of [body.invocable_result, body.invocable_http_response?.body]) {
    if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) {
      return candidate;
    }
  }

  return null;
}

function serviceResponsePayload(serviceProbe) {
  const envelope = serviceProbeResultEnvelope(serviceProbe);
  const payload = envelope?.result?.payload;
  if (envelope?.schema !== 'durable-workflow.v2.external-task-result'
    || envelope?.outcome?.status !== 'succeeded'
    || payload?.codec !== 'avro'
    || typeof payload?.blob !== 'string') {
    return null;
  }

  const decoded = envelope.decoded_payload;
  return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : null;
}

function observedPayloadRoundTrip(serviceProbe, requestPayload) {
  const responsePayload = serviceResponsePayload(serviceProbe);
  return responsePayload !== null
    && isDeepStrictEqual(responsePayload.request_payload, requestPayload);
}

function observedTypedErrorRoundTrip(serviceProbe, expectedType) {
  const envelope = serviceProbeResultEnvelope(serviceProbe);
  return serviceProbe?.ok === true
    && Number(serviceProbe?.status ?? 0) >= 200
    && Number(serviceProbe?.status ?? 0) < 300
    && envelope?.schema === 'durable-workflow.v2.external-task-result'
    && envelope?.outcome?.status === 'failed'
    && envelope?.outcome?.recorded === true
    && stringValue(envelope?.failure?.type) === expectedType
    && stringValue(envelope?.failure?.message) !== '';
}

function crossLanguageScenarioResult({
  scenarioId,
  callerLanguage,
  serviceLanguage,
  callerWorkflowInstanceId,
  callerWorkflowRunId,
  operationName,
  requestPayload,
  execute,
  describe,
  history,
  serviceProbe,
  typedErrorProbe,
  expectedTypedError,
  artifactTupleEvidence,
  workerExecution,
  missingSurface,
  missingSurfaceOwner,
  versions,
}) {
  const serviceCallId = serviceCallIdFrom(execute) || serviceCallIdFrom(describe);
  const historyRows = historyRowsFrom(history);
  const callerHistoryRecorded = serviceCallId !== ''
    && historyRows.some((row) => String(row.service_call_id || '') === serviceCallId);
  const serviceProbeSucceeded = serviceProbe?.ok === true
    && Number(serviceProbe?.status ?? 0) >= 200
    && Number(serviceProbe?.status ?? 0) < 300;
  const responsePayload = serviceResponsePayload(serviceProbe);
  const payloadRoundTrip = serviceProbeSucceeded
    && observedPayloadRoundTrip(serviceProbe, requestPayload);
  const typedErrorRoundTrip = observedTypedErrorRoundTrip(typedErrorProbe, expectedTypedError);
  const typedErrorProbeSucceeded = typedErrorRoundTrip;
  const callerWorker = workerExecution.workers?.find((worker) => worker.sdk_language === callerLanguage);
  const serviceWorker = workerExecution.workers?.find((worker) => worker.sdk_language === serviceLanguage);
  const callerWorkerInvocation = callerWorker?.caller_workflow_invocation || null;
  const serviceHealth = serviceHealthEvidenceFromWorkerExecution(workerExecution, serviceLanguage);
  const serviceHealthSucceeded = serviceHealth.health_succeeded === true;
  const callerPublicSurfaceAvailable = publicSurfaceAvailable(callerWorker?.public_service_call_surface)
    || (
      callerWorkerInvocation?.executed === true
      && callerWorkerInvocation?.local_product_source_checkouts_used === false
    );
  const serviceRuntimeAvailable = serviceHealthSucceeded
    && publicSurfaceAvailable(serviceWorker?.service_runtime_surface);
  const durableServiceResponseObserved = serviceRuntimeAvailable
    && serviceProbeSucceeded
    && responsePayload !== null;
  let missing = missingSurface;
  if (missing === null && !callerPublicSurfaceAvailable) {
    missing = `published ${callerLanguage} caller workflow did not expose a public SDK service-call API during the reflected package probe`;
  } else if (missing === null && !serviceHealthSucceeded) {
    missing = `published ${serviceLanguage} service shard did not return a valid /health response: ${serviceHealthFailureSummary(serviceHealth.health_response)}`;
  } else if (missing === null && !publicSurfaceAvailable(serviceWorker?.service_runtime_surface)) {
    missing = `published ${serviceLanguage} service shard health passed but did not expose an invocable service runtime surface`;
  } else if (missing === null && !serviceProbeSucceeded) {
    missing = `published ${serviceLanguage} service invocation failed: ${serviceHealthFailureSummary(serviceProbe)}`;
  } else if (missing === null && !payloadRoundTrip) {
    missing = `published ${serviceLanguage} service invocation did not return the request payload`;
  } else if (missing === null && !typedErrorRoundTrip) {
    missing = `published ${serviceLanguage} service invocation did not preserve the observed typed error`;
  } else if (missing === null && !execute.ok) {
    missing = 'service endpoint execute did not accept the published cross-language call';
  }
  const pass = missing === null
    && serviceHealthSucceeded
    && serviceRuntimeAvailable
    && execute.ok
    && serviceCallId !== ''
    && callerHistoryRecorded
    && callerPublicSurfaceAvailable
    && durableServiceResponseObserved
    && payloadRoundTrip
    && typedErrorRoundTrip
    && publicSurfaceAvailable(callerWorker?.public_service_call_surface);
  const serviceInvocationFailed = callerPublicSurfaceAvailable
    && serviceHealthSucceeded
    && serviceRuntimeAvailable
    && !serviceProbeSucceeded;
  const serviceResponseMismatch = callerPublicSurfaceAvailable
    && serviceHealthSucceeded
    && serviceRuntimeAvailable
    && serviceProbeSucceeded
    && (!payloadRoundTrip || !typedErrorRoundTrip);
  const responseSurface = {
    status: pass ? 'completed' : (serviceInvocationFailed || serviceResponseMismatch ? 'failed' : 'unsupported'),
    execute_response: responseSummary(execute),
    describe_response: responseSummary(describe),
    caller_history_response: responseSummary(history),
    service_probe_response: serviceProbe,
    typed_error_probe_response: typedErrorProbe,
    service_response_payload: responsePayload,
    missing_public_surface: missingSurface,
  };

  if (pass) {
    return scenarioResult('pass', scenarioId, {
      caller_workflow_instance_id: callerWorkflowInstanceId,
      caller_workflow_run_id: callerWorkflowRunId,
      caller_sdk_language: callerLanguage,
      service_sdk_language: serviceLanguage,
      operation_name: operationName,
      request_payload: requestPayload,
      response_or_failure_surface: responseSurface,
      service_call_id: serviceCallId,
      artifact_tuple: artifactTupleEvidence,
      published_artifact_worker_execution: workerExecution,
      service_health: serviceHealth,
      service_health_succeeded: serviceHealthSucceeded,
      service_probe_succeeded: serviceProbeSucceeded,
      service_response_payload: responsePayload,
      payload_round_trip: payloadRoundTrip,
      typed_error_probe_succeeded: typedErrorProbeSucceeded,
      typed_error_round_trip: typedErrorRoundTrip,
      service_runtime_available: serviceRuntimeAvailable,
      caller_history_recorded: callerHistoryRecorded,
      caller_worker_invocation: callerWorkerInvocation,
      durable_service_response_observed: durableServiceResponseObserved,
    });
  }

  const attemptedCallEvidence = {
    execute_request: execute.request,
    execute_response: responseSummary(execute),
    describe_response: responseSummary(describe),
    caller_history_response: responseSummary(history),
    service_probe_response: serviceProbe,
    typed_error_probe_response: typedErrorProbe,
    service_response_payload: responsePayload,
    service_health: serviceHealth,
    service_health_succeeded: serviceHealthSucceeded,
    durable_service_call_id_observed: serviceCallId !== '',
    caller_history_recorded: callerHistoryRecorded,
    caller_worker_invocation: callerWorkerInvocation,
    missing_public_surface: missing,
  };
  const healthFailureObserved = callerPublicSurfaceAvailable && serviceHealthSucceeded !== true;
  const findingType = healthFailureObserved
    ? 'nexus_published_service_health_failed'
    : (serviceInvocationFailed
      ? 'nexus_published_service_invocation_failed'
      : (serviceResponseMismatch ? 'nexus_published_service_response_mismatch' : 'nexus_unsupported_surface'));
  const expectedBehavior = healthFailureObserved
    ? `The published ${serviceLanguage} service shard serves /health with runtime, package import, and package version evidence before the Nexus run can pass.`
    : (serviceInvocationFailed || serviceResponseMismatch
      ? `The published ${serviceLanguage} service returns a successful external-task response with the request payload and preserves a concrete typed failure response.`
      : `The published ${callerLanguage} caller SDK exposes a workflow-safe Nexus service-call API and the published ${serviceLanguage} service runtime executes the call through the durable service-call path.`);
  const nextAcceptanceCriterion = healthFailureObserved
    ? `fix published ${serviceLanguage} service startup, serve valid /health evidence, and rerun the published PHP/Python Nexus shard`
    : (serviceInvocationFailed || serviceResponseMismatch
      ? `fix the published ${serviceLanguage} invocation response and rerun the published PHP/Python Nexus shard`
      : `publish the missing ${callerLanguage} Nexus service-call surface, wire it to the ${serviceLanguage} runtime service, and rerun the published PHP/Python Nexus shard`);

  return scenarioResult(serviceInvocationFailed || serviceResponseMismatch ? 'fail' : 'unsupported', scenarioId, {
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    caller_sdk_language: callerLanguage,
    service_sdk_language: serviceLanguage,
    operation_name: operationName,
    request_payload: requestPayload,
    response_or_failure_surface: responseSurface,
    service_call_id: serviceCallId,
    artifact_tuple: artifactTupleEvidence,
    published_artifact_worker_execution: workerExecution,
    service_health: serviceHealth,
    service_health_succeeded: serviceHealthSucceeded,
    service_probe_succeeded: serviceProbeSucceeded,
    service_response_payload: responsePayload,
    payload_round_trip: payloadRoundTrip,
    typed_error_probe_succeeded: typedErrorProbeSucceeded,
    typed_error_round_trip: typedErrorRoundTrip,
    attempted_call_evidence: attemptedCallEvidence,
  }, [
    crossLanguageFinding(
      scenarioId,
      versions,
      missingSurfaceOwner,
      `${scenarioId} attempted the published ${callerLanguage} to ${serviceLanguage} Nexus call, but ${missing}. Evidence: ${JSON.stringify(attemptedCallEvidence).slice(0, 1000)}`,
      expectedBehavior,
      nextAcceptanceCriterion,
      findingType,
    ),
  ]);
}

function crossLanguageRuntimeBody(response) {
  const body = response?.body;
  return body && typeof body === 'object' && !Array.isArray(body) ? body : null;
}

function crossLanguageRuntimeObject(field, ...responses) {
  const values = [];
  for (const body of responses.map((response) => crossLanguageRuntimeBody(response))) {
    const value = body?.[field];
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      values.push(value);
    }
  }

  return values.find(publicSurfaceAvailable) || values[0] || null;
}

function crossLanguageRuntimeArray(field, ...responses) {
  const values = [];
  for (const body of responses.map((response) => crossLanguageRuntimeBody(response))) {
    if (Array.isArray(body?.[field])) {
      values.push(body[field]);
    }
  }

  return values.find((value) => value.length > 0) || values[0] || [];
}

function crossLanguageRuntimeString(field, fallback, ...responses) {
  for (const body of responses.map((response) => crossLanguageRuntimeBody(response))) {
    const value = stringValue(body?.[field]);
    if (value !== '') {
      return value;
    }
  }

  return fallback;
}

function crossLanguageRuntimeBool(field, ...responses) {
  return responses.some((response) => crossLanguageRuntimeBody(response)?.[field] === true);
}

async function probePublishedPhpPythonServiceCalls(baseUrl, token, versions, sources, verification, compose) {
  const started = [];
  try {
    const pythonService = await startPythonSdkService(compose, versions);
    started.push(pythonService.containerName);
    const phpService = await startPhpWorkflowService(compose, versions);
    started.push(phpService.containerName);

    const pythonHealth = pythonService.health;
    const phpHealth = phpService.health;
    const phpToPythonRequest = {
      name: 'world',
      scenario: 'php_caller_python_service',
      caller_sdk_language: 'sdk-php',
      service_sdk_language: 'sdk-python',
    };
    const pythonToPhpRequest = {
      name: 'world',
      scenario: 'python_caller_php_service',
      caller_sdk_language: 'sdk-python',
      service_sdk_language: 'workflow-php',
    };
    const pythonProbe = await postJson(
      `http://127.0.0.1:${pythonService.port}/greeter`,
      phpToPythonRequest,
    );
    const pythonTypedErrorProbe = await postJson(`http://127.0.0.1:${pythonService.port}/greeter`, {
      ...phpToPythonRequest,
      scenario: 'php_caller_python_service_typed_error',
    });
    const phpProbe = await postJson(
      `http://127.0.0.1:${phpService.port}/greeter`,
      pythonToPhpRequest,
    );
    const phpTypedErrorProbe = await postJson(`http://127.0.0.1:${phpService.port}/greeter`, {
      ...pythonToPhpRequest,
      scenario: 'python_caller_php_service_typed_error',
    });
    const pythonReflection = reflectPublishedPythonSdkSurface(pythonService.containerName);
    const phpReflection = reflectPublishedPhpWorkflowSurface(phpService.containerName);

    const runtimeEvidence = {
      python: {
        container_image: 'python:3.12-slim',
        package_imported: crossLanguageRuntimeBool('package_imported', pythonHealth, pythonProbe, pythonReflection),
        package_version: crossLanguageRuntimeString('package_version', versions['sdk-python'] || '', pythonHealth, pythonProbe, pythonReflection),
        service_started: pythonHealth.ok === true || pythonProbe.ok === true || crossLanguageRuntimeBool('service_started', pythonHealth, pythonProbe, pythonReflection),
        service_health_succeeded: validServiceHealthResponse('sdk-python', pythonHealth),
        health_response: responseSummary(pythonHealth),
        invocation_response: responseSummary(pythonProbe),
        caller_reflection_response: responseSummary(pythonReflection),
        service_runtime_surface: crossLanguageRuntimeObject('service_runtime_surface', pythonHealth, pythonProbe, pythonReflection),
        public_service_call_surface: crossLanguageRuntimeObject('public_service_call_surface', pythonHealth, pythonProbe, pythonReflection),
        service_call_methods: crossLanguageRuntimeArray('service_call_methods', pythonHealth, pythonProbe, pythonReflection),
      },
      php: {
        container_image: 'composer:2',
        package_imported: crossLanguageRuntimeBool('package_imported', phpHealth, phpProbe, phpReflection),
        package_version: crossLanguageRuntimeString('package_version', versions.workflow || '', phpHealth, phpProbe, phpReflection),
        sdk_package_version: crossLanguageRuntimeString('sdk_package_version', versions['sdk-php'] || '', phpHealth, phpProbe, phpReflection),
        service_started: phpHealth.ok === true || phpProbe.ok === true || crossLanguageRuntimeBool('service_started', phpHealth, phpProbe, phpReflection),
        service_health_succeeded: validServiceHealthResponse('workflow-php', phpHealth),
        health_response: responseSummary(phpHealth),
        invocation_response: responseSummary(phpProbe),
        caller_reflection_response: responseSummary(phpReflection),
        service_runtime_surface: crossLanguageRuntimeObject('service_runtime_surface', phpHealth, phpProbe, phpReflection),
        public_service_call_surface: crossLanguageRuntimeObject('public_service_call_surface', phpHealth, phpProbe, phpReflection),
        service_call_methods: crossLanguageRuntimeArray('service_call_methods', phpHealth, phpProbe, phpReflection),
      },
    };
    const tuple = artifactTuple(versions, sources, verification);
    const workerExecution = publishedCrossLanguageWorkerExecution(versions, sources, verification, runtimeEvidence);

    const pythonOperation = await setupCrossLanguageService(
      baseUrl,
      token,
      'python',
      'http://nexus-python-sdk-service:8091/greeter',
    );
    const phpOperation = await setupCrossLanguageService(
      baseUrl,
      token,
      'php',
      'http://nexus-php-workflow-service:8092/greeter',
    );

    const phpCallerWorkflowInstanceId = `php-caller-python-service-${crypto.randomBytes(5).toString('hex')}`;
    const phpCallerWorkflowRunId = ulidLike();
    const phpToPythonExecute = executePublishedPhpSdkServiceOperation(
      phpService.containerName,
      token,
      pythonOperation,
      phpToPythonRequest,
      'tenant-a',
      phpCallerWorkflowInstanceId,
      phpCallerWorkflowRunId,
    );
    const phpToPythonServiceCallId = serviceCallIdFrom(phpToPythonExecute);
    const phpToPythonDescribe = phpToPythonServiceCallId === ''
      ? {ok: false, status: 0, body: null, request: null}
      : await apiRequest(
        baseUrl,
        token,
        'shared',
        'GET',
        `/service-endpoints/${pythonOperation.endpointName}/services/${pythonOperation.serviceName}/operations/${pythonOperation.operationName}/service-calls/${encodeURIComponent(phpToPythonServiceCallId)}`,
      );
    const phpToPythonHistory = await callerHistory(
      baseUrl,
      token,
      'tenant-a',
      phpCallerWorkflowInstanceId,
      phpCallerWorkflowRunId,
    );

    const pythonCallerWorkflowInstanceId = `python-caller-php-service-${crypto.randomBytes(5).toString('hex')}`;
    const pythonCallerWorkflowRunId = ulidLike();
    const pythonToPhpExecute = await apiRequest(
      baseUrl,
      token,
      'shared',
      'POST',
      `/service-endpoints/${phpOperation.endpointName}/services/${phpOperation.serviceName}/operations/${phpOperation.operationName}/execute`,
      {
        arguments: pythonToPhpRequest,
        mode_override: 'async',
        wait_for: 'accepted',
        caller_namespace: 'tenant-b',
        caller_workflow_instance_id: pythonCallerWorkflowInstanceId,
        caller_workflow_run_id: pythonCallerWorkflowRunId,
        idempotency_key: `${pythonCallerWorkflowInstanceId}-nexus-${crypto.randomBytes(5).toString('hex')}`,
        metadata: {conformance: 'python_caller_php_service'},
      },
    );
    const pythonToPhpServiceCallId = serviceCallIdFrom(pythonToPhpExecute);
    const pythonToPhpDescribe = pythonToPhpServiceCallId === ''
      ? {ok: false, status: 0, body: null, request: null}
      : await apiRequest(
        baseUrl,
        token,
        'shared',
        'GET',
        `/service-endpoints/${phpOperation.endpointName}/services/${phpOperation.serviceName}/operations/${phpOperation.operationName}/service-calls/${encodeURIComponent(pythonToPhpServiceCallId)}`,
      );
    const pythonToPhpHistory = await callerHistory(
      baseUrl,
      token,
      'tenant-b',
      pythonCallerWorkflowInstanceId,
      pythonCallerWorkflowRunId,
    );

    const phpPublicSurface = reflectedPublicServiceCallSurface(runtimeEvidence.php);
    const pythonPublicSurface = reflectedPublicServiceCallSurface(runtimeEvidence.python);
    const phpMissingSurface = publicSurfaceAvailable(phpPublicSurface)
      ? null
      : 'published sdk-php lacks the public DurableWorkflow\\Client Nexus service-operation API';
    const pythonMissingSurface = publicSurfaceAvailable(pythonPublicSurface)
      ? null
      : 'published sdk-python lacks a public workflow-safe Nexus service-call caller API on durable_workflow.client.Client or durable_workflow.workflow.WorkflowContext';
    const pythonServiceRuntimeMissing = runtimeEvidence.python.service_health_succeeded !== true
      ? `published sdk-python service shard did not return a valid /health response: ${serviceHealthFailureSummary(runtimeEvidence.python.health_response)}`
      : (publicSurfaceAvailable(runtimeEvidence.python.service_runtime_surface)
        ? null
        : 'published sdk-python lacks a public invocable service runtime surface on durable_workflow.invocable.InvocableActivityHandler');
    const phpServiceRuntimeMissing = runtimeEvidence.php.service_health_succeeded !== true
      ? `published workflow-php service shard did not return a valid /health response: ${serviceHealthFailureSummary(runtimeEvidence.php.health_response)}`
      : (publicSurfaceAvailable(runtimeEvidence.php.service_runtime_surface)
        ? null
        : 'published workflow-php lacks a public invocable service runtime surface on Workflow\\V2\\Support\\InvocableHttpAdapter');
    const phpToPythonMissing = phpMissingSurface || pythonServiceRuntimeMissing;
    const phpToPythonOwner = phpMissingSurface !== null ? 'sdk-php' : 'sdk-python';
    const pythonToPhpMissing = pythonMissingSurface || phpServiceRuntimeMissing;
    const pythonToPhpOwner = pythonMissingSurface !== null ? 'sdk-python' : 'workflow';

    return [
      crossLanguageScenarioResult({
        scenarioId: 'php_caller_python_service',
        callerLanguage: 'sdk-php',
        serviceLanguage: 'sdk-python',
        callerWorkflowInstanceId: phpCallerWorkflowInstanceId,
        callerWorkflowRunId: phpCallerWorkflowRunId,
        operationName: `${pythonOperation.serviceName}.${pythonOperation.operationName}`,
        requestPayload: phpToPythonRequest,
        execute: phpToPythonExecute,
        describe: phpToPythonDescribe,
        history: phpToPythonHistory,
        serviceProbe: responseSummary(pythonProbe),
        typedErrorProbe: responseSummary(pythonTypedErrorProbe),
        expectedTypedError: 'NexusPublishedServiceError',
        artifactTupleEvidence: tuple,
        workerExecution,
        missingSurface: phpToPythonMissing,
        missingSurfaceOwner: phpToPythonOwner,
        versions,
      }),
      crossLanguageScenarioResult({
        scenarioId: 'python_caller_php_service',
        callerLanguage: 'sdk-python',
        serviceLanguage: 'workflow-php',
        callerWorkflowInstanceId: pythonCallerWorkflowInstanceId,
        callerWorkflowRunId: pythonCallerWorkflowRunId,
        operationName: `${phpOperation.serviceName}.${phpOperation.operationName}`,
        requestPayload: pythonToPhpRequest,
        execute: pythonToPhpExecute,
        describe: pythonToPhpDescribe,
        history: pythonToPhpHistory,
        serviceProbe: responseSummary(phpProbe),
        typedErrorProbe: responseSummary(phpTypedErrorProbe),
        expectedTypedError: 'NexusPublishedServiceError',
        artifactTupleEvidence: tuple,
        workerExecution,
        missingSurface: pythonToPhpMissing,
        missingSurfaceOwner: pythonToPhpOwner,
        versions,
      }),
    ];
  } finally {
    for (const containerName of started) {
      dockerStop(containerName, path.join(resultDir, `${containerName}-stop.log`));
    }
  }
}

function scenarioProductFailure(scenarioId, versions, type, observed, expected, next) {
  return {
    scenario_id: scenarioId,
    type,
    finding_type: type,
    owning_surface: 'server',
    artifact_versions: compactObject(versions),
    observed_behavior: observed,
    expected_behavior: expected,
    next_acceptance_criterion: next,
  };
}

function scenarioResult(status, scenarioId, observedOutputs, linkedFindings = []) {
  return {
    scenario_id: scenarioId,
    status,
    observed_outputs: observedOutputs,
    linked_findings: linkedFindings,
  };
}

function responseSummary(response) {
  return {
    status: response.status,
    ok: response.ok === true,
    body: response.body,
  };
}

function serviceCallIdFrom(response) {
  return String(response.body?.service_call_id || response.body?.id || '');
}

function resolvedTargetFrom(response) {
  return String(
    response.body?.resolved_target_reference
    || response.body?.handler?.activity_execution_id
    || response.body?.handler?.carrier_request_id
    || '',
  );
}

function historyRowsFrom(response) {
  return Array.isArray(response.body?.nexus_operations) ? response.body.nexus_operations : [];
}

function attemptEntriesFrom(value) {
  return Array.isArray(value)
    ? value.filter((entry) => entry && typeof entry === 'object' && !Array.isArray(entry))
    : [];
}

function serviceCallAttemptsFrom(record) {
  return attemptEntriesFrom(record?.service_call_attempts ?? record?.serviceCallAttempts);
}

function retryPolicyMaxAttempts(policy) {
  const candidates = [
    policy?.max_attempts,
    policy?.maximum_attempts,
    policy?.maximumAttempts,
  ];
  for (const candidate of candidates) {
    const parsed = Number(candidate);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
  }

  return 0;
}

function millisecondsBetween(start, end) {
  const startMs = Date.parse(start);
  const endMs = Date.parse(end);
  if (!Number.isFinite(startMs) || !Number.isFinite(endMs)) {
    return null;
  }

  return Math.max(0, endMs - startMs);
}

function transientRetryProbePhp() {
  return `<?php
declare(strict_types=1);

use Illuminate\\Contracts\\Console\\Kernel;
use Workflow\\V2\\Contracts\\WorkflowControlPlane;
use Workflow\\V2\\Contracts\\ServiceBoundaryPolicy;
use Workflow\\V2\\Models\\WorkflowServiceCall;
use Workflow\\V2\\Support\\DefaultServiceControlPlane;
use Workflow\\Serializers\\Serializer;

require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$callerWorkflowInstanceId = $argv[1] ?? 'tenant-a-transient-retry-caller';
$callerWorkflowRunId = $argv[2] ?? '01NEXUSTRANSIENTRETRY000';
$idempotencyKey = $argv[3] ?? 'nexus-transient-retry';
$serviceWorkflowInstanceId = $argv[4] ?? 'shared-greeter-transient-service';
$serviceWorkflowRunId = $argv[5] ?? '01NEXUSTRANSIENTSVC0000';

$fakeWorkflow = new class($serviceWorkflowInstanceId, $serviceWorkflowRunId) implements WorkflowControlPlane {
    private int $queryCount = 0;

    /** @var list<array<string, mixed>> */
    public array $queryObservations = [];

    public function __construct(
        private readonly string $serviceWorkflowInstanceId,
        private readonly string $serviceWorkflowRunId,
    ) {
    }

    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        return [
            'started' => false,
            'workflow_instance_id' => $instanceId ?? '',
            'workflow_run_id' => null,
            'workflow_type' => $workflowType,
            'outcome' => 'unsupported',
            'task_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function query(string $instanceId, string $name, array $options = []): array
    {
        $this->queryCount++;
        $observation = [
            'attempt' => $this->queryCount,
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $this->serviceWorkflowRunId,
            'query_name' => $name,
            'arguments' => $options['arguments'] ?? [],
        ];

        if ($this->queryCount < 3) {
            $result = [
                'success' => false,
                'workflow_instance_id' => $this->serviceWorkflowInstanceId,
                'workflow_id' => $this->serviceWorkflowInstanceId,
                'run_id' => $this->serviceWorkflowRunId,
                'target_scope' => 'instance',
                'query_name' => $name,
                'result' => null,
                'reason' => 'transient_greeter_failure',
                'message' => 'transient Greeter.greet failure before retry success',
                'error_type' => 'TransientGreetingFailure',
                'status' => 503,
            ];
        } else {
            $result = [
                'success' => true,
                'workflow_instance_id' => $this->serviceWorkflowInstanceId,
                'workflow_id' => $this->serviceWorkflowInstanceId,
                'run_id' => $this->serviceWorkflowRunId,
                'target_scope' => 'instance',
                'query_name' => $name,
                'result' => 'hello, world after retry',
                'result_envelope' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', 'hello, world after retry'),
                ],
                'reason' => null,
                'status' => 200,
            ];
        }

        $this->queryObservations[] = $observation + [
            'success' => $result['success'],
            'reason' => $result['reason'],
            'error_type' => $result['error_type'] ?? null,
            'result' => $result['result'],
        ];

        return $result;
    }

    public function update(string $instanceId, string $name, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'update_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function cancel(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function terminate(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function repair(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function archive(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_retry_probe',
        ];
    }

    public function describe(string $instanceId, array $options = []): array
    {
        return [
            'found' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_type' => 'nexus.transient.greeter',
            'workflow_class' => 'nexus.transient.greeter',
            'namespace' => $options['namespace'] ?? 'shared',
            'business_key' => null,
            'run' => [
                'workflow_run_id' => $this->serviceWorkflowRunId,
                'run_number' => 1,
                'is_current_run' => true,
                'status' => 'running',
                'status_bucket' => 'running',
                'closed_reason' => null,
                'compatibility' => null,
                'connection' => null,
                'queue' => null,
                'started_at' => null,
                'closed_at' => null,
                'last_progress_at' => null,
                'wait_kind' => null,
                'wait_reason' => null,
            ],
            'run_count' => 1,
            'actions' => [
                'can_signal' => true,
                'can_query' => true,
                'can_update' => false,
                'can_cancel' => true,
                'can_terminate' => true,
                'can_repair' => false,
                'can_archive' => false,
            ],
            'reason' => null,
        ];
    }
};

$controlPlane = new DefaultServiceControlPlane(
    $fakeWorkflow,
    $app->make(ServiceBoundaryPolicy::class),
);

$result = $controlPlane->execute('shared-greeter', 'Greeter', 'greet-retry', [
    'namespace' => 'shared',
    'caller_namespace' => 'tenant-a',
    'caller_workflow_instance_id' => $callerWorkflowInstanceId,
    'caller_workflow_run_id' => $callerWorkflowRunId,
    'target_workflow_instance_id' => $serviceWorkflowInstanceId,
    'idempotency_key' => $idempotencyKey,
    'arguments' => [
        'name' => 'world',
        'scenario' => 'transient_failure_retries_with_policy',
    ],
]);

$serviceCallId = (string) ($result['service_call_id'] ?? '');
$call = $serviceCallId !== '' ? WorkflowServiceCall::query()->find($serviceCallId) : null;
$metadata = is_array($call?->metadata) ? $call->metadata : [];
$attempts = is_array($metadata['service_call_attempts'] ?? null)
    ? array_values(array_filter($metadata['service_call_attempts'], 'is_array'))
    : (is_array($result['service_call_attempts'] ?? null) ? $result['service_call_attempts'] : []);
$serviceCallOutcome = $call?->outcome;
if ($serviceCallOutcome instanceof \\BackedEnum) {
    $serviceCallOutcome = $serviceCallOutcome->value;
}

echo json_encode([
    'ok' => ($result['accepted'] ?? false) === true,
    'service_call_id' => $serviceCallId,
    'caller_workflow_instance_id' => $callerWorkflowInstanceId,
    'caller_workflow_run_id' => $callerWorkflowRunId,
    'service_workflow_instance_id' => $serviceWorkflowInstanceId,
    'service_workflow_run_id' => $serviceWorkflowRunId,
    'query_observations' => $fakeWorkflow->queryObservations,
    'query_count' => count($fakeWorkflow->queryObservations),
    'final_successful_result' => 'hello, world after retry',
    'service_call_attempts' => $attempts,
    'retry_policy' => is_array($call?->retry_policy) ? $call->retry_policy : ($result['retry_policy'] ?? null),
    'service_call_status' => $call?->status,
    'service_call_outcome' => $serviceCallOutcome,
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
`;
}

function permanentFailureProbePhp() {
  return `<?php
declare(strict_types=1);

use Illuminate\\Contracts\\Console\\Kernel;
use Workflow\\V2\\Contracts\\WorkflowControlPlane;
use Workflow\\V2\\Contracts\\ServiceBoundaryPolicy;
use Workflow\\V2\\Models\\WorkflowServiceCall;
use Workflow\\V2\\Support\\DefaultServiceControlPlane;

require '/app/vendor/autoload.php';

$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$callerWorkflowInstanceId = $argv[1] ?? 'tenant-a-permanent-typed-error-caller';
$callerWorkflowRunId = $argv[2] ?? '01NEXUSPERMANENTERR000';
$idempotencyKey = $argv[3] ?? 'nexus-permanent-typed-error';
$serviceWorkflowInstanceId = $argv[4] ?? 'shared-greeter-permanent-service';
$serviceWorkflowRunId = $argv[5] ?? '01NEXUSPERMANENTSVC00';

$fakeWorkflow = new class($serviceWorkflowInstanceId, $serviceWorkflowRunId) implements WorkflowControlPlane {
    /** @var list<array<string, mixed>> */
    public array $queryObservations = [];

    public function __construct(
        private readonly string $serviceWorkflowInstanceId,
        private readonly string $serviceWorkflowRunId,
    ) {
    }

    public function start(string $workflowType, ?string $instanceId = null, array $options = []): array
    {
        return [
            'started' => false,
            'workflow_instance_id' => $instanceId ?? '',
            'workflow_run_id' => null,
            'workflow_type' => $workflowType,
            'outcome' => 'unsupported',
            'task_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function signal(string $instanceId, string $name, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function query(string $instanceId, string $name, array $options = []): array
    {
        $result = [
            'success' => false,
            'workflow_instance_id' => $this->serviceWorkflowInstanceId,
            'workflow_id' => $this->serviceWorkflowInstanceId,
            'run_id' => $this->serviceWorkflowRunId,
            'target_scope' => 'instance',
            'query_name' => $name,
            'result' => null,
            'reason' => 'service_error',
            'message' => 'shared greeter is permanently unavailable',
            'error_type' => 'SharedGreeterUnavailable',
            'status' => 409,
        ];

        $this->queryObservations[] = [
            'attempt' => count($this->queryObservations) + 1,
            'workflow_instance_id' => $instanceId,
            'workflow_run_id' => $this->serviceWorkflowRunId,
            'query_name' => $name,
            'arguments' => $options['arguments'] ?? [],
            'success' => false,
            'reason' => $result['reason'],
            'error_type' => $result['error_type'],
            'message' => $result['message'],
        ];

        return $result;
    }

    public function update(string $instanceId, string $name, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'update_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function cancel(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function terminate(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function repair(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function archive(string $instanceId, array $options = []): array
    {
        return [
            'accepted' => false,
            'workflow_instance_id' => $instanceId,
            'workflow_command_id' => null,
            'reason' => 'not_used_by_nexus_permanent_failure_probe',
        ];
    }

    public function describe(string $instanceId, array $options = []): array
    {
        return [
            'found' => true,
            'workflow_instance_id' => $instanceId,
            'workflow_type' => 'nexus.permanent.greeter',
            'workflow_class' => 'nexus.permanent.greeter',
            'namespace' => $options['namespace'] ?? 'shared',
            'business_key' => null,
            'run' => [
                'workflow_run_id' => $this->serviceWorkflowRunId,
                'run_number' => 1,
                'is_current_run' => true,
                'status' => 'running',
                'status_bucket' => 'running',
                'closed_reason' => null,
                'compatibility' => null,
                'connection' => null,
                'queue' => null,
                'started_at' => null,
                'closed_at' => null,
                'last_progress_at' => null,
                'wait_kind' => null,
                'wait_reason' => null,
            ],
            'run_count' => 1,
            'actions' => [
                'can_signal' => true,
                'can_query' => true,
                'can_update' => false,
                'can_cancel' => true,
                'can_terminate' => true,
                'can_repair' => false,
                'can_archive' => false,
            ],
            'reason' => null,
        ];
    }
};

$controlPlane = new DefaultServiceControlPlane(
    $fakeWorkflow,
    $app->make(ServiceBoundaryPolicy::class),
);

$result = $controlPlane->execute('shared-greeter', 'Greeter', 'greet-permanent', [
    'namespace' => 'shared',
    'caller_namespace' => 'tenant-a',
    'caller_workflow_instance_id' => $callerWorkflowInstanceId,
    'caller_workflow_run_id' => $callerWorkflowRunId,
    'target_workflow_instance_id' => $serviceWorkflowInstanceId,
    'idempotency_key' => $idempotencyKey,
    'arguments' => [
        'name' => 'world',
        'scenario' => 'permanent_failure_preserves_typed_error',
    ],
]);

$serviceCallId = (string) ($result['service_call_id'] ?? $result['id'] ?? '');
$call = $serviceCallId !== '' ? WorkflowServiceCall::query()->find($serviceCallId) : null;
$metadata = is_array($call?->metadata) ? $call->metadata : [];
$attempts = is_array($metadata['service_call_attempts'] ?? null)
    ? array_values(array_filter($metadata['service_call_attempts'], 'is_array'))
    : (is_array($result['service_call_attempts'] ?? null) ? $result['service_call_attempts'] : []);
$serviceCallOutcome = $call?->outcome;
if ($serviceCallOutcome instanceof \\BackedEnum) {
    $serviceCallOutcome = $serviceCallOutcome->value;
}

echo json_encode([
    'ok' => ($result['accepted'] ?? true) === false && ($result['status'] ?? null) === 'failed',
    'service_call_id' => $serviceCallId,
    'caller_workflow_instance_id' => $callerWorkflowInstanceId,
    'caller_workflow_run_id' => $callerWorkflowRunId,
    'service_workflow_instance_id' => $serviceWorkflowInstanceId,
    'service_workflow_run_id' => $serviceWorkflowRunId,
    'query_observations' => $fakeWorkflow->queryObservations,
    'query_count' => count($fakeWorkflow->queryObservations),
    'service_call_attempts' => $attempts,
    'retry_policy' => is_array($call?->retry_policy) ? $call->retry_policy : ($result['retry_policy'] ?? null),
    'service_call_status' => $call?->status,
    'service_call_outcome' => $serviceCallOutcome,
    'service_error_type' => $result['service_error_type'] ?? null,
    'caller_observed_error_type' => $result['caller_observed_error_type'] ?? null,
    'typed_error_message' => $result['typed_error_message'] ?? null,
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
`;
}

async function probeTransientFailureRetries(baseUrl, token, versions, compose) {
  const scenarioId = 'transient_failure_retries_with_policy';
  const callerNamespace = 'tenant-a';
  const callerWorkflowInstanceId = `${callerNamespace}-transient-retry-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const serviceWorkflowInstanceId = 'shared-greeter-transient-service';
  const serviceWorkflowRunId = ulidLike();
  const idempotencyKey = `${callerWorkflowInstanceId}-nexus-${crypto.randomBytes(5).toString('hex')}`;
  const probePath = path.join(compose.runRoot, 'nexus-transient-retry-probe.php');
  fs.writeFileSync(probePath, transientRetryProbePhp());

  const copy = spawnSync('docker', [
    'compose',
    '-p',
    compose.project,
    '-f',
    compose.composePath,
    'cp',
    probePath,
    'server:/tmp/nexus-transient-retry-probe.php',
  ], {
    cwd: compose.runRoot,
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  fs.writeFileSync(
    path.join(resultDir, 'nexus-transient-retry-probe-copy.log'),
    [`exit_status=${copy.status ?? 'null'}`, copy.stdout || '', copy.stderr || ''].join('\n'),
  );

  let probePayload = {};
  if (copy.status === 0) {
    const probe = spawnSync('docker', [
      'compose',
      '-p',
      compose.project,
      '-f',
      compose.composePath,
      'exec',
      '-T',
      'server',
      'php',
      '/tmp/nexus-transient-retry-probe.php',
      callerWorkflowInstanceId,
      callerWorkflowRunId,
      idempotencyKey,
      serviceWorkflowInstanceId,
      serviceWorkflowRunId,
    ], {
      cwd: compose.runRoot,
      encoding: 'utf8',
      maxBuffer: 16 * 1024 * 1024,
    });
    fs.writeFileSync(
      path.join(resultDir, 'nexus-transient-retry-probe-exec.log'),
      [`exit_status=${probe.status ?? 'null'}`, probe.stdout || '', probe.stderr || ''].join('\n'),
    );

    if (probe.status === 0) {
      try {
        probePayload = JSON.parse(probe.stdout || '{}');
      } catch (error) {
        probePayload = {parse_error: `${error.name}: ${error.message}`, raw_stdout: probe.stdout};
      }
    } else {
      probePayload = {
        exit_status: probe.status,
        stdout: probe.stdout,
        stderr: probe.stderr,
      };
    }
  } else {
    probePayload = {
      copy_exit_status: copy.status,
      copy_stdout: copy.stdout,
      copy_stderr: copy.stderr,
    };
  }

  const serviceCallId = String(probePayload.service_call_id || '');
  const describe = serviceCallId === ''
    ? {ok: false, status: 0, body: null, request: null}
    : await apiRequest(
      baseUrl,
      token,
      'shared',
      'GET',
      `/service-endpoints/shared-greeter/services/Greeter/operations/greet-retry/service-calls/${encodeURIComponent(serviceCallId)}`,
    );
  const history = await apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(callerWorkflowInstanceId)}/runs/${encodeURIComponent(callerWorkflowRunId)}/nexus-operations`,
  );
  const historyRows = historyRowsFrom(history);
  const matchingHistoryRows = historyRows.filter((row) => String(row.service_call_id || '') === serviceCallId);
  const serviceCallDetailAttempts = serviceCallAttemptsFrom(describe.body);
  const probeAttempts = attemptEntriesFrom(probePayload.service_call_attempts);
  const serviceCallAttempts = serviceCallDetailAttempts.length > 0 ? serviceCallDetailAttempts : probeAttempts;
  const callerHistoryAttempts = matchingHistoryRows.flatMap(serviceCallAttemptsFrom);
  const retryPolicy = describe.body?.retry_policy
    || matchingHistoryRows.find((row) => row.retry_policy)?.retry_policy
    || probePayload.retry_policy
    || {};
  const historyAttemptVisibilityIncludesRetries = matchingHistoryRows.some((row) => {
    const attempts = serviceCallAttemptsFrom(row);
    return attempts.length >= serviceCallAttempts.length && Number(row.retry_attempt_count || attempts.length) >= serviceCallAttempts.length;
  });
  const firstTwoRetried = serviceCallAttempts.slice(0, 2).length >= 2
    && serviceCallAttempts.slice(0, 2).every((attempt) => attempt.retry_scheduled === true);
  const terminalAttempt = serviceCallAttempts[serviceCallAttempts.length - 1] || {};
  const terminalCompleted = String(terminalAttempt.status || '').toLowerCase() === 'completed'
    || String(terminalAttempt.outcome || '').toLowerCase() === 'completed';
  const completedAfterRetry = Boolean(
    probePayload.ok === true
    && describe.ok
    && describe.body?.found === true
    && String(describe.body?.status || '').toLowerCase() === 'completed'
    && serviceCallAttempts.length >= 3
    && firstTwoRetried
    && terminalCompleted,
  );
  const observedOutputs = {
    caller_namespace: callerNamespace,
    target_namespace: 'shared',
    endpoint_name: 'shared-greeter',
    service_name: 'Greeter',
    operation_name: 'greet-retry',
    service_call_id: serviceCallId,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    service_workflow_instance_id: serviceWorkflowInstanceId,
    service_workflow_run_id: serviceWorkflowRunId,
    retry_policy: retryPolicy,
    retry_attempts: serviceCallAttempts,
    service_call_attempts: serviceCallAttempts,
    history_attempts: callerHistoryAttempts.length > 0 ? callerHistoryAttempts : serviceCallAttempts,
    caller_history_attempts: callerHistoryAttempts,
    service_call_detail_attempts: serviceCallDetailAttempts,
    history_attempt_visibility_includes_retry_attempts: historyAttemptVisibilityIncludesRetries,
    completed_after_retry: completedAfterRetry,
    final_successful_result: probePayload.final_successful_result || null,
    service_call_record: describe.body,
    caller_history_rows: matchingHistoryRows.length > 0 ? matchingHistoryRows : historyRows,
    caller_history_evidence: history.body,
    handler_observations: probePayload.query_observations || [],
    probe_response: probePayload,
  };
  const pass = serviceCallId !== ''
    && retryPolicyMaxAttempts(retryPolicy) >= 3
    && serviceCallAttempts.length >= 3
    && callerHistoryAttempts.length >= serviceCallAttempts.length
    && historyAttemptVisibilityIncludesRetries
    && completedAfterRetry
    && String(probePayload.final_successful_result || '') === 'hello, world after retry';

  if (pass) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      !historyAttemptVisibilityIncludesRetries ? 'retry_attempt_visibility_gap' : 'nexus_transient_retry_policy_mismatch',
      `Transient retry probe did not prove retry policy completion through public describe/history evidence: ${JSON.stringify(observedOutputs).slice(0, 1000)}`,
      'A Nexus service call with a transient service failure retries according to the recorded retry policy, exposes retry attempts, and completes successfully.',
      'fix Nexus retry policy execution or attempt visibility and rerun the transient retry-policy cell',
    ),
  ]);
}

async function probePermanentFailurePreservesTypedError(baseUrl, token, versions, compose) {
  const scenarioId = 'permanent_failure_preserves_typed_error';
  const callerNamespace = 'tenant-a';
  const callerWorkflowInstanceId = `${callerNamespace}-permanent-error-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const serviceWorkflowInstanceId = 'shared-greeter-permanent-service';
  const serviceWorkflowRunId = ulidLike();
  const idempotencyKey = `${callerWorkflowInstanceId}-nexus-${crypto.randomBytes(5).toString('hex')}`;
  const probePath = path.join(compose.runRoot, 'nexus-permanent-failure-probe.php');
  fs.writeFileSync(probePath, permanentFailureProbePhp());

  const copy = spawnSync('docker', [
    'compose',
    '-p',
    compose.project,
    '-f',
    compose.composePath,
    'cp',
    probePath,
    'server:/tmp/nexus-permanent-failure-probe.php',
  ], {
    cwd: compose.runRoot,
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  fs.writeFileSync(
    path.join(resultDir, 'nexus-permanent-failure-probe-copy.log'),
    [`exit_status=${copy.status ?? 'null'}`, copy.stdout || '', copy.stderr || ''].join('\n'),
  );

  let probePayload = {};
  if (copy.status === 0) {
    const probe = spawnSync('docker', [
      'compose',
      '-p',
      compose.project,
      '-f',
      compose.composePath,
      'exec',
      '-T',
      'server',
      'php',
      '/tmp/nexus-permanent-failure-probe.php',
      callerWorkflowInstanceId,
      callerWorkflowRunId,
      idempotencyKey,
      serviceWorkflowInstanceId,
      serviceWorkflowRunId,
    ], {
      cwd: compose.runRoot,
      encoding: 'utf8',
      maxBuffer: 16 * 1024 * 1024,
    });
    fs.writeFileSync(
      path.join(resultDir, 'nexus-permanent-failure-probe-exec.log'),
      [`exit_status=${probe.status ?? 'null'}`, probe.stdout || '', probe.stderr || ''].join('\n'),
    );

    if (probe.status === 0) {
      try {
        probePayload = JSON.parse(probe.stdout || '{}');
      } catch (error) {
        probePayload = {parse_error: `${error.name}: ${error.message}`, raw_stdout: probe.stdout};
      }
    } else {
      probePayload = {
        exit_status: probe.status,
        stdout: probe.stdout,
        stderr: probe.stderr,
      };
    }
  } else {
    probePayload = {
      copy_exit_status: copy.status,
      copy_stdout: copy.stdout,
      copy_stderr: copy.stderr,
    };
  }

  const serviceCallId = String(probePayload.service_call_id || probePayload.result?.service_call_id || probePayload.result?.id || '');
  const describe = serviceCallId === ''
    ? {ok: false, status: 0, body: null, request: null}
    : await apiRequest(
      baseUrl,
      token,
      'shared',
      'GET',
      `/service-endpoints/shared-greeter/services/Greeter/operations/greet-permanent/service-calls/${encodeURIComponent(serviceCallId)}`,
    );
  const history = await apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(callerWorkflowInstanceId)}/runs/${encodeURIComponent(callerWorkflowRunId)}/nexus-operations`,
  );
  const historyRows = historyRowsFrom(history);
  const matchingHistoryRows = historyRows.filter((row) => String(row.service_call_id || '') === serviceCallId);
  const historyRow = matchingHistoryRows[0] || {};
  const metadata = describe.body?.outcome_metadata && typeof describe.body.outcome_metadata === 'object'
    ? describe.body.outcome_metadata
    : {};
  const serviceErrorType = stringValue(
    describe.body?.service_error_type
    || metadata.service_error_type
    || historyRow.service_error_type
    || probePayload.service_error_type
    || probePayload.result?.service_error_type
    || probePayload.result?.error_type,
  );
  const callerObservedErrorType = stringValue(
    describe.body?.caller_observed_error_type
    || metadata.caller_observed_error_type
    || historyRow.caller_observed_error_type
    || probePayload.caller_observed_error_type
    || probePayload.result?.caller_observed_error_type
    || probePayload.result?.error_type,
  );
  const typedErrorMessage = stringValue(
    describe.body?.typed_error_message
    || metadata.typed_error_message
    || historyRow.typed_error_message
    || probePayload.typed_error_message
    || probePayload.result?.typed_error_message,
  );
  const retryAttemptCount = Number(
    describe.body?.retry_attempt_count
    || historyRow.retry_attempt_count
    || probePayload.result?.retry_attempt_count
    || 0,
  );
  const typedErrorPreserved = serviceErrorType !== '' && serviceErrorType === callerObservedErrorType;
  const observedOutputs = {
    caller_namespace: callerNamespace,
    target_namespace: 'shared',
    endpoint_name: 'shared-greeter',
    service_name: 'Greeter',
    operation_name: 'greet-permanent',
    service_call_id: serviceCallId,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    service_workflow_instance_id: serviceWorkflowInstanceId,
    service_workflow_run_id: serviceWorkflowRunId,
    service_error_type: serviceErrorType,
    caller_observed_error_type: callerObservedErrorType,
    typed_error_message: typedErrorMessage,
    typed_error_preserved: typedErrorPreserved,
    retry_attempt_count: retryAttemptCount,
    service_call_attempts: serviceCallAttemptsFrom(describe.body).length > 0
      ? serviceCallAttemptsFrom(describe.body)
      : attemptEntriesFrom(probePayload.service_call_attempts),
    service_call_record: describe.body,
    caller_history_rows: matchingHistoryRows.length > 0 ? matchingHistoryRows : historyRows,
    caller_history_evidence: history.body,
    handler_observations: probePayload.query_observations || [],
    probe_response: probePayload,
    response: responseSummary({
      status: Number(probePayload.result?.http_status || probePayload.result?.status_code || 409),
      ok: false,
      body: probePayload.result || probePayload,
    }),
  };
  const pass = serviceCallId !== ''
    && describe.ok
    && history.ok
    && matchingHistoryRows.length > 0
    && serviceErrorType === 'SharedGreeterUnavailable'
    && typedErrorPreserved
    && typedErrorMessage !== ''
    && retryAttemptCount === 1;

  if (pass) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      typedErrorPreserved ? 'nexus_permanent_failure_evidence_gap' : 'nexus_typed_error_boundary_mismatch',
      `Permanent failure probe did not preserve typed error evidence across service-call detail and caller history: ${JSON.stringify(observedOutputs).slice(0, 1000)}`,
      'A Nexus service call with a non-retryable typed service failure preserves the same service_error_type and caller_observed_error_type in service-call detail and caller history.',
      'fix Nexus typed-error propagation or history projection and rerun the permanent-failure cell',
    ),
  ]);
}

async function probeWorkerRestartReplay(baseUrl, token, versions, sources, image, compose) {
  const scenarioId = 'worker_restart_replay_does_not_reissue_call';
  const callerNamespace = 'tenant-a';
  const callerWorkflowInstanceId = `${callerNamespace}-replay-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const idempotencyKey = `${callerWorkflowInstanceId}-nexus-${crypto.randomBytes(5).toString('hex')}`;
  const requestBody = {
    arguments: {
      name: 'restart-replay',
      scenario: scenarioId,
      simulated_duration_seconds: 30,
    },
    mode_override: 'async',
    wait_for: 'accepted',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: idempotencyKey,
    metadata: {
      conformance: scenarioId,
    },
  };

  const firstIssuedAt = timestamp();
  const first = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    requestBody,
  );
  const firstCallId = serviceCallIdFrom(first);
  const firstTarget = resolvedTargetFrom(first);

  const stop = spawnSync('docker', ['compose', '-p', compose.project, '-f', compose.composePath, 'stop', 'worker'], {
    cwd: compose.runRoot,
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  fs.writeFileSync(
    path.join(resultDir, 'nexus-replay-worker-stop.log'),
    [`exit_status=${stop.status ?? 'null'}`, stop.stdout || '', stop.stderr || ''].join('\n'),
  );
  const workerStoppedAt = timestamp();

  const start = spawnSync('docker', ['compose', '-p', compose.project, '-f', compose.composePath, 'start', 'worker'], {
    cwd: compose.runRoot,
    encoding: 'utf8',
    maxBuffer: 8 * 1024 * 1024,
  });
  fs.writeFileSync(
    path.join(resultDir, 'nexus-replay-worker-start.log'),
    [`exit_status=${start.status ?? 'null'}`, start.stdout || '', start.stderr || ''].join('\n'),
  );
  const workerRestartedAt = timestamp();

  const replayRequest = await replayPostWithStaleSocketRecovery({
    baseUrl,
    token,
    namespace: 'shared',
    apiPath: '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    body: requestBody,
    pooledRequest: apiRequest,
  });
  const replay = replayRequest.response;
  const replayObservedAt = timestamp();
  const replayCallId = serviceCallIdFrom(replay);
  const replayTarget = resolvedTargetFrom(replay);
  const serviceCallId = firstCallId || replayCallId;

  const describe = serviceCallId === ''
    ? {ok: false, status: 0, body: null, request: null}
    : await apiRequest(
      baseUrl,
      token,
      'shared',
      'GET',
      `/service-endpoints/shared-greeter/services/Greeter/operations/greet/service-calls/${encodeURIComponent(serviceCallId)}`,
    );
  const history = await apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(callerWorkflowInstanceId)}/runs/${encodeURIComponent(callerWorkflowRunId)}/nexus-operations`,
  );

  const issuedCallIds = [firstCallId, replayCallId].filter((value) => value !== '');
  const uniqueCallIds = new Set(issuedCallIds);
  const issuedTargetRefs = [firstTarget, replayTarget].filter((value) => value !== '');
  const uniqueTargetRefs = new Set(issuedTargetRefs);
  const callerHistoryRows = historyRowsFrom(history);
  const matchingHistoryRows = callerHistoryRows.filter((row) => String(row.service_call_id || '') === serviceCallId);
  const duplicateCallIssueCount = Math.max(0, uniqueCallIds.size - 1) + Math.max(0, uniqueTargetRefs.size - 1);
  const serviceInvocationCount = uniqueTargetRefs.size || (serviceCallId === '' ? 0 : 1);
  const historyRecovered = replay.ok
    && replay.body?.idempotent_replay === true
    && uniqueCallIds.size === 1
    && matchingHistoryRows.length >= 1;
  const duplicateCallAssertion = {
    expected_service_call_id: serviceCallId,
    observed_service_call_ids: issuedCallIds,
    observed_resolved_target_references: issuedTargetRefs,
    expected_service_invocations: 1,
    observed_service_invocations: serviceInvocationCount,
    duplicate_call_issue_count: duplicateCallIssueCount,
  };
  const serviceLogs = [
    {
      at: firstIssuedAt,
      message: 'initial Nexus call accepted by published server image',
      response: responseSummary(first),
    },
    {
      at: workerStoppedAt,
      message: 'published server worker service stopped after call issue',
      exit_status: stop.status,
    },
    {
      at: workerRestartedAt,
      message: 'published server worker service restarted before replay',
      exit_status: start.status,
    },
    {
      at: replayObservedAt,
      message: 'idempotent replay observed existing durable Nexus call',
      response: responseSummary(replay),
    },
  ];
  const observedOutputs = {
    service_call_id: serviceCallId,
    published_artifact_worker_execution: publishedServerWorkerExecution(versions, sources, image),
    issued_call_ids: issuedCallIds,
    caller_history_rows: matchingHistoryRows.length > 0 ? matchingHistoryRows : callerHistoryRows,
    service_logs: serviceLogs,
    call_issued_at: firstIssuedAt,
    caller_worker_stopped_at: workerStoppedAt,
    caller_worker_restarted_at: workerRestartedAt,
    call_completed_at: String(describe.body?.completed_at || describe.body?.updated_at || replayObservedAt),
    worker_restart_observed: stop.status === 0 && start.status === 0,
    history_replay_recovered_call: historyRecovered,
    service_invocation_count: serviceInvocationCount,
    duplicate_call_assertion: duplicateCallAssertion,
    duplicate_call_issue_count: duplicateCallIssueCount,
    replay_response: responseSummary(replay),
    replay_transport: replayRequest.transport,
    service_call_record: describe.body,
    caller_history_evidence: history.body,
  };

  const pass = first.ok
    && replay.ok
    && serviceCallId !== ''
    && stop.status === 0
    && start.status === 0
    && historyRecovered
    && serviceInvocationCount === 1
    && duplicateCallIssueCount === 0;

  if (pass) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      duplicateCallIssueCount > 0 ? 'nexus_replay_duplicate_invocation' : 'nexus_replay_recovery_mismatch',
      `Replay evidence did not prove a single durable call after worker restart: ${JSON.stringify(duplicateCallAssertion).slice(0, 1000)}`,
      'After caller worker restart, replay returns the existing durable Nexus service_call_id and resolved target reference without dispatching a duplicate handler.',
      'fix Nexus idempotent replay recovery for accepted calls and rerun the published-artifact replay cell',
    ),
  ]);
}

async function probeCallerCancellation(baseUrl, token, versions, sources, image) {
  const scenarioId = 'caller_cancellation_propagates_to_service';
  const callerNamespace = 'tenant-a';
  const callerWorkflowInstanceId = `${callerNamespace}-cancel-${crypto.randomBytes(5).toString('hex')}`;
  const callerWorkflowRunId = ulidLike();
  const requestBody = {
    arguments: {
      name: 'cancel-propagation',
      scenario: scenarioId,
      simulated_duration_seconds: 60,
    },
    mode_override: 'async',
    wait_for: 'accepted',
    caller_namespace: callerNamespace,
    caller_workflow_instance_id: callerWorkflowInstanceId,
    caller_workflow_run_id: callerWorkflowRunId,
    idempotency_key: `${callerWorkflowInstanceId}-nexus-${crypto.randomBytes(5).toString('hex')}`,
    metadata: {
      conformance: scenarioId,
    },
  };

  const execute = await apiRequest(
    baseUrl,
    token,
    'shared',
    'POST',
    '/service-endpoints/shared-greeter/services/Greeter/operations/greet/execute',
    requestBody,
  );
  const serviceCallId = serviceCallIdFrom(execute);
  const callerCancelledAt = timestamp();
  const cancel = serviceCallId === ''
    ? {ok: false, status: 0, body: null, request: null}
    : await apiRequest(
      baseUrl,
      token,
      'shared',
      'POST',
      `/service-endpoints/shared-greeter/services/Greeter/operations/greet/service-calls/${encodeURIComponent(serviceCallId)}/cancel`,
      {reason: 'caller cancellation propagation conformance'},
    );
  const cancelObservedAt = timestamp();
  const describe = serviceCallId === ''
    ? {ok: false, status: 0, body: null, request: null}
    : await apiRequest(
      baseUrl,
      token,
      'shared',
      'GET',
      `/service-endpoints/shared-greeter/services/Greeter/operations/greet/service-calls/${encodeURIComponent(serviceCallId)}`,
    );
  const history = await apiRequest(
    baseUrl,
    token,
    callerNamespace,
    'GET',
    `/workflows/${encodeURIComponent(callerWorkflowInstanceId)}/runs/${encodeURIComponent(callerWorkflowRunId)}/nexus-operations`,
  );
  const targetCancelledAt = String(
    cancel.body?.cancelled_at
    || describe.body?.cancelled_at
    || cancelObservedAt,
  );
  const propagationMs = millisecondsBetween(callerCancelledAt, targetCancelledAt);
  const propagationWindowMs = 10000;
  const callerHistoryRows = historyRowsFrom(history);
  const matchingHistoryRows = callerHistoryRows.filter((row) => String(row.service_call_id || '') === serviceCallId);
  const cancellationType = String(
    cancel.body?.outcome_metadata?.failure_reason
    || describe.body?.outcome_metadata?.failure_reason
    || cancel.body?.outcome_reason
    || describe.body?.outcome_reason
    || '',
  );
  const typedCancellationObserved = cancellationType !== ''
    && String(cancel.body?.outcome || describe.body?.outcome || '') === 'cancelled';
  const withinPropagationWindow = propagationMs !== null && propagationMs <= propagationWindowMs;
  const serviceLogs = [
    {
      at: callerCancelledAt,
      message: 'caller requested Nexus service-call cancellation',
      service_call_id: serviceCallId,
    },
    {
      at: targetCancelledAt,
      message: 'published server image recorded typed Nexus cancellation',
      cancellation_type: cancellationType,
      cancel_response: responseSummary(cancel),
      describe_response: responseSummary(describe),
    },
  ];
  const observedOutputs = {
    service_call_id: serviceCallId,
    published_artifact_worker_execution: publishedServerWorkerExecution(versions, sources, image),
    caller_history_rows: matchingHistoryRows.length > 0 ? matchingHistoryRows : callerHistoryRows,
    service_logs: serviceLogs,
    caller_cancelled_at: callerCancelledAt,
    target_cancelled_at: targetCancelledAt,
    cancellation_propagation_ms: propagationMs,
    within_propagation_window: withinPropagationWindow,
    cancellation_type: cancellationType,
    typed_cancellation_observed: typedCancellationObserved,
    service_call_record: describe.body,
    caller_history_evidence: history.body,
    cancel_response: responseSummary(cancel),
  };
  const pass = execute.ok
    && cancel.ok
    && cancel.body?.accepted === true
    && serviceCallId !== ''
    && matchingHistoryRows.length >= 1
    && withinPropagationWindow
    && typedCancellationObserved;

  if (pass) {
    return scenarioResult('pass', scenarioId, observedOutputs);
  }

  return scenarioResult('fail', scenarioId, observedOutputs, [
    scenarioProductFailure(
      scenarioId,
      versions,
      'nexus_cancellation_propagation_mismatch',
      `Cancellation evidence did not prove typed target cancellation within ${propagationWindowMs}ms: ${JSON.stringify(responseSummary(cancel)).slice(0, 1000)}`,
      'Cancelling the caller-side Nexus service call transitions the target call to a typed cancellation within the documented propagation window.',
      'fix Nexus service-call cancellation propagation and rerun the published-artifact cancellation cell',
    ),
  ]);
}

function blockedEvidence(startedAt, finishedAt, versions, sources, reason) {
  return {
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    blocked_reason: reason,
    started_at: startedAt,
    finished_at: finishedAt,
    artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: {},
    local_product_source_checkouts_used: false,
    findings: builtInProbeScenarioIds.map((scenarioId) => ({
      scenario_id: scenarioId,
      type: 'runner_gap',
      finding_type: 'runner_gap',
      owning_surface: 'conformance_harness',
      artifact_versions: compactObject(versions),
      observed_behavior: `Nexus shared-service probe was runner-blocked: ${reason}`,
      expected_behavior: 'host runner can start the published server image and exercise shared-service Nexus calls',
      next_acceptance_criterion: `restore host execution for ${scenarioId} and rerun Nexus conformance`,
    })),
    scenario_results: Object.fromEntries(builtInProbeScenarioIds.map((scenarioId) => [
      scenarioId,
      {
        status: 'runner_blocked',
        observed_outputs: {
          blocked_reason: reason,
          evidence_runner_blocked: true,
        },
        linked_findings: [
          {
            scenario_id: scenarioId,
            type: 'runner_gap',
            finding_type: 'runner_gap',
            owning_surface: 'conformance_harness',
            artifact_versions: compactObject(versions),
            observed_behavior: `Nexus built-in probe was runner-blocked: ${reason}`,
            expected_behavior: 'host runner can start the published server image and exercise built-in Nexus replay/cancellation probes',
            next_acceptance_criterion: `restore host execution for ${scenarioId} and rerun Nexus conformance`,
          },
        ],
      },
    ])),
  };
}

function artifactResolutionEvidence(startedAt, finishedAt, versions, sources, verification, failures) {
  const findings = failures.map((failure) => ({
    scenario_id: 'published_artifact_install_only',
    type: 'missing_or_invalid_published_nexus_artifact',
    finding_type: 'missing_or_invalid_published_nexus_artifact',
    owning_surface: artifactOwners[failure.artifact] || 'conformance_harness',
    artifact_versions: compactObject(versions),
    observed_behavior: `${failure.artifact} published artifact source did not resolve: ${failure.reason}`,
    expected_behavior: 'every required Nexus artifact source resolves to a downloadable public artifact before shared-service proof is recorded',
    next_acceptance_criterion: `resolve the ${failure.artifact} published artifact source and rerun the Nexus shared-service probe`,
  }));

  return {
    outcome: 'fail',
    runner_blocked: false,
    started_at: startedAt,
    finished_at: finishedAt,
    artifact_versions: compactObject(versions),
    published_artifact_versions: compactObject(versions),
    resolved_artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: verification,
    local_product_source_checkouts_used: false,
    findings,
    scenario_results: {
      published_artifact_install_only: {
        status: 'not_covered',
        observed_outputs: {
          artifact_versions: compactObject(versions),
          artifact_sources: sources,
          artifact_source_verification: verification,
          local_product_source_checkouts_used: false,
          install_channels_verified: false,
          resolution_failures: failures,
        },
        linked_findings: findings,
      },
    },
  };
}

function artifactInstallEvidence(versions, sources, verification, localProductSourceCheckoutsUsed) {
  return {
    schema: 'durable-workflow.v2.nexus-runtime.install-evidence',
    published_install_tuple_proven: true,
    local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    artifacts: requiredArtifacts.map((artifact) => ({
      artifact,
      version: versions[artifact],
      source: sources[artifact],
      install_channel: installChannelForArtifact(artifact),
      source_verification: verification[artifact],
      local_product_source_checkout_used_as_artifact: false,
      status: 'pass',
    })),
  };
}

function installChannelForArtifact(artifact) {
  switch (artifact) {
    case 'server':
      return 'docker';
    case 'cli':
      return 'github_release_asset';
    case 'workflow':
    case 'sdk-php':
    case 'waterline':
      return 'packagist';
    case 'sdk-python':
      return 'pypi';
    default:
      return 'published_artifact_channel';
  }
}

function productFailureEvidence(startedAt, finishedAt, versions, sources, verification, reason, details = {}) {
  const installEvidence = artifactInstallEvidence(versions, sources, verification, false);
  const scenarioResults = scenarioIds.map((scenarioId) => failureScenario(
    scenarioId,
    versions,
    reason,
    details,
  ));

  return {
    outcome: 'fail',
    runner_blocked: false,
    started_at: startedAt,
    finished_at: finishedAt,
    artifact_versions: compactObject(versions),
    published_artifact_versions: compactObject(versions),
    resolved_artifact_versions: compactObject(versions),
    artifact_sources: sources,
    artifact_source_verification: verification,
    artifact_install_evidence: installEvidence,
    local_product_source_checkouts_used: false,
    findings: scenarioResults.flatMap((scenario) => scenario.linked_findings || []),
    scenario_results: {
      published_artifact_install_only: {
        status: 'pass',
        observed_outputs: {
          artifact_versions: compactObject(versions),
          artifact_sources: sources,
          artifact_source_verification: verification,
          local_product_source_checkouts_used: false,
          install_channels_verified: true,
          published_install_tuple_proven: true,
          artifact_install_evidence: installEvidence,
        },
        linked_findings: [],
      },
      tenant_a_calls_shared_service: scenarioResults[0],
      tenant_b_calls_shared_service: scenarioResults[1],
    },
  };
}

async function main() {
  fs.mkdirSync(resultDir, {recursive: true});
  const startedAt = timestamp();
  const image = serverImage();
  const versions = artifactVersions(image);
  const sources = artifactSources(versions, image);

  const writeEvidence = (evidence) => {
    fs.writeFileSync(evidencePath, JSON.stringify(evidence, null, 2) + '\n');
  };

  if (image === null) {
    process.exitCode = 3;
    return;
  }
  if (!commandAvailable('docker')) {
    writeEvidence(blockedEvidence(startedAt, timestamp(), versions, sources, 'required command not found: docker'));
    return;
  }
  if (!commandAvailable('docker', ['compose', 'version'])) {
    writeEvidence(blockedEvidence(startedAt, timestamp(), versions, sources, 'required command not available: docker compose'));
    return;
  }

  const runRoot = env('DW_NEXUS_RUN_ROOT') || fs.mkdtempSync(path.join(os.tmpdir(), 'dw-nexus-shared-service.'));
  fs.mkdirSync(runRoot, {recursive: true});
  const port = await freePort();
  const baseUrl = `http://127.0.0.1:${port}`;
  const token = randomToken('nexus-token');
  const project = `dw-nexus-${crypto.randomBytes(5).toString('hex')}`;
  const composePath = path.join(runRoot, 'compose.yml');
  fs.writeFileSync(composePath, composeYaml(image, port, token));

  let serverDigest = '';
  try {
    if (env('DW_NEXUS_SKIP_DOCKER_PULL') !== '1') {
      runLogged('docker', ['pull', image], path.join(resultDir, 'nexus-shared-service-docker-pull.log'));
    }

    serverDigest = runLogged(
      'docker',
      ['image', 'inspect', '--format', '{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}', image],
      path.join(resultDir, 'nexus-shared-service-image-inspect.log'),
    ).trim();

    const {verification, failures} = await artifactSourceVerification(versions, sources, serverDigest);
    if (failures.length > 0) {
      writeEvidence(artifactResolutionEvidence(
        startedAt,
        timestamp(),
        versions,
        sources,
        verification,
        failures,
      ));
      return;
    }

    runLogged(
      'docker',
      ['compose', '-p', project, '-f', composePath, 'up', '-d', '--wait'],
      path.join(resultDir, 'nexus-shared-service-compose-up.log'),
      {cwd: runRoot},
    );
    await waitForReady(baseUrl, 120000);

    let namespaceResponses = [];
    let registration = null;
    let scenarioResults = [];
    try {
      for (const namespace of ['tenant-a', 'tenant-b', 'tenant-c', 'shared']) {
        namespaceResponses.push(await ensureNamespace(baseUrl, token, namespace));
      }
      registration = await setupSharedService(baseUrl, token);
      scenarioResults = [
        await invokeSharedService(baseUrl, token, 'tenant-a', versions),
        await invokeSharedService(baseUrl, token, 'tenant-b', versions),
        await probeTransientFailureRetries(baseUrl, token, versions, {
          project,
          composePath,
          runRoot,
        }),
        await probePermanentFailurePreservesTypedError(baseUrl, token, versions, {
          project,
          composePath,
          runRoot,
        }),
        await probeEndpointPermissionDenied(baseUrl, token, versions),
        await probeMalformedPayloadRefusal(baseUrl, token, versions),
        await probeNonexistentEndpointNotFound(baseUrl, token, versions),
      ];
      if (env('DW_NEXUS_SKIP_REPLAY_CANCEL_PROBE') !== '1') {
        scenarioResults.push(
          await probeWorkerRestartReplay(baseUrl, token, versions, sources, image, {
            project,
            composePath,
            runRoot,
          }),
        );
        scenarioResults.push(
          await probeCallerCancellation(baseUrl, token, versions, sources, image),
        );
      }
      if (env('DW_NEXUS_SKIP_PHP_PYTHON_SERVICE_SHARD') !== '1') {
        scenarioResults.push(...await probePublishedPhpPythonServiceCalls(
          baseUrl,
          token,
          versions,
          sources,
          verification,
          {
            project,
            composePath,
            runRoot,
          },
        ));
      }
    } catch (error) {
      writeEvidence(productFailureEvidence(
        startedAt,
        timestamp(),
        versions,
        sources,
        verification,
        `shared-service setup or invocation failed: ${error.name}: ${error.message}`,
        {namespace_responses: namespaceResponses, setup_error: `${error.name}: ${error.message}`},
      ));
      return;
    }

    const finishedAt = timestamp();
    const findings = scenarioResults.flatMap((scenario) => scenario.linked_findings || []);
    const installEvidence = artifactInstallEvidence(versions, sources, verification, false);

    writeEvidence({
      outcome: scenarioResults.every((scenario) => scenario.status === 'pass') ? 'pass' : 'fail',
      runner_blocked: false,
      started_at: startedAt,
      finished_at: finishedAt,
      artifact_versions: compactObject(versions),
      published_artifact_versions: compactObject(versions),
      resolved_artifact_versions: compactObject(versions),
      artifact_sources: sources,
      artifact_source_verification: verification,
      artifact_install_evidence: installEvidence,
      local_product_source_checkouts_used: false,
      topology: {
        namespaces: ['tenant-a', 'tenant-b', 'tenant-c', 'shared'],
        endpoint: 'shared:shared-greeter',
        service: 'Greeter',
        operation: 'greet',
      },
      setup_evidence: {
        server_url: baseUrl,
        namespace_requests: namespaceResponses.map((response) => ({
          request: response.request,
          status: response.status,
          body: response.body,
        })),
        registration: {
          endpoint: {status: registration.endpoint.status, body: registration.endpoint.body},
          service: {status: registration.service.status, body: registration.service.body},
          operation: {status: registration.operation.status, body: registration.operation.body},
          retry_operation: {status: registration.retryOperation.status, body: registration.retryOperation.body},
          permanent_operation: {status: registration.permanentOperation.status, body: registration.permanentOperation.body},
        },
      },
      findings,
      scenario_results: {
        published_artifact_install_only: {
          status: 'pass',
          observed_outputs: {
            artifact_versions: compactObject(versions),
            artifact_sources: sources,
            artifact_source_verification: verification,
            local_product_source_checkouts_used: false,
            install_channels_verified: true,
            published_install_tuple_proven: true,
            artifact_install_evidence: installEvidence,
          },
          linked_findings: [],
        },
        ...Object.fromEntries(scenarioResults.map((scenario) => [scenario.scenario_id, scenario])),
      },
    });
  } catch (error) {
    writeEvidence(blockedEvidence(
      startedAt,
      timestamp(),
      versions,
      sources,
      `${error.name}: ${error.message}`,
    ));
  } finally {
    const down = spawnSync('docker', ['compose', '-p', project, '-f', composePath, 'down', '-v'], {
      cwd: runRoot,
      encoding: 'utf8',
      maxBuffer: 8 * 1024 * 1024,
    });
    fs.writeFileSync(
      path.join(resultDir, 'nexus-shared-service-compose-down.log'),
      [`exit_status=${down.status ?? 'null'}`, down.stdout || '', down.stderr || ''].join('\n'),
    );
    if (env('DW_NEXUS_KEEP_RUN_ROOT') !== '1') {
      fs.rmSync(runRoot, {recursive: true, force: true});
    }
  }
}

main().catch((error) => {
  fs.writeFileSync(evidencePath, JSON.stringify({
    runner_blocked: true,
    blocked_reason: `${error.name}: ${error.message}`,
    findings: [],
    scenario_results: {},
  }, null, 2) + '\n');
});
NODE
  then
    if [[ -n "$supplied_evidence_path" ]]; then
      merged_evidence_path="$result_dir/merged-shared-service-evidence.json"
      node - "$supplied_evidence_path" "$generated_evidence_path" "$merged_evidence_path" <<'NODE'
const fs = require('fs');

const suppliedPath = process.argv[2];
const generatedPath = process.argv[3];
const mergedPath = process.argv[4];
const builtInProbeScenarioIds = [
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
  'transient_failure_retries_with_policy',
  'permanent_failure_preserves_typed_error',
  'endpoint_permission_denied_without_information_leak',
  'malformed_payload_refused_before_dispatch',
  'nonexistent_endpoint_typed_not_found',
  'worker_restart_replay_does_not_reissue_call',
  'caller_cancellation_propagates_to_service',
  'php_caller_python_service',
  'python_caller_php_service',
];

function readJson(path) {
  try {
    return JSON.parse(fs.readFileSync(path, 'utf8'));
  } catch {
    return {};
  }
}

function byScenarioId(items) {
  const indexed = {};
  if (Array.isArray(items)) {
    for (const item of items) {
      if (item && typeof item.scenario_id === 'string') {
        indexed[item.scenario_id] = item;
      }
    }
    return indexed;
  }

  if (items && typeof items === 'object') {
    for (const [scenarioId, item] of Object.entries(items)) {
      if (item && typeof item === 'object') {
        indexed[scenarioId] = {
          scenario_id: scenarioId,
          ...item,
        };
      }
    }
  }

  return indexed;
}

function hasNonEmptyObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function firstNonEmptyObject(...values) {
  return values.find(hasNonEmptyObject) || undefined;
}

const supplied = readJson(suppliedPath);
const generated = readJson(generatedPath);
const suppliedScenarios = byScenarioId(supplied.scenario_results);
const generatedScenarios = byScenarioId(generated.scenario_results);

for (const scenarioId of builtInProbeScenarioIds) {
  if (generatedScenarios[scenarioId]) {
    suppliedScenarios[scenarioId] = generatedScenarios[scenarioId];
  }
}

const merged = {
  ...generated,
  ...supplied,
  artifact_versions: firstNonEmptyObject(
    supplied.artifact_versions,
    supplied.artifactVersions,
    generated.artifact_versions,
    generated.artifactVersions,
  ),
  published_artifact_versions: firstNonEmptyObject(
    supplied.published_artifact_versions,
    supplied.publishedArtifactVersions,
    generated.published_artifact_versions,
    generated.publishedArtifactVersions,
  ),
  resolved_artifact_versions: firstNonEmptyObject(
    supplied.resolved_artifact_versions,
    supplied.resolvedArtifactVersions,
    generated.resolved_artifact_versions,
    generated.resolvedArtifactVersions,
  ),
  artifact_sources: firstNonEmptyObject(
    supplied.artifact_sources,
    supplied.artifactSources,
    generated.artifact_sources,
    generated.artifactSources,
  ),
  artifact_source_verification: firstNonEmptyObject(
    supplied.artifact_source_verification,
    supplied.artifactSourceVerification,
    generated.artifact_source_verification,
    generated.artifactSourceVerification,
  ),
  artifact_install_evidence: firstNonEmptyObject(
    supplied.artifact_install_evidence,
    supplied.artifactInstallEvidence,
    generated.artifact_install_evidence,
    generated.artifactInstallEvidence,
  ),
  findings: [
    ...(Array.isArray(supplied.findings) ? supplied.findings : []),
    ...(Array.isArray(generated.findings) ? generated.findings : []),
  ],
  scenario_results: suppliedScenarios,
};

if (!Object.hasOwn(merged, 'local_product_source_checkouts_used')
  && Object.hasOwn(generated, 'local_product_source_checkouts_used')) {
  merged.local_product_source_checkouts_used = generated.local_product_source_checkouts_used;
}

fs.writeFileSync(mergedPath, JSON.stringify(merged, null, 2) + '\n');
NODE
      export DW_NEXUS_EVIDENCE_JSON="$merged_evidence_path"
    else
      export DW_NEXUS_EVIDENCE_JSON="$generated_evidence_path"
    fi
  elif [[ -f "$generated_evidence_path" ]]; then
    export DW_NEXUS_EVIDENCE_JSON="$generated_evidence_path"
  fi
fi

node - "$result_dir" "${DW_NEXUS_EVIDENCE_JSON:-}" "${DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE:-}" <<'NODE'
const fs = require('fs');
const path = require('path');

const resultDir = process.argv[2];
const evidencePath = process.argv[3] || '';
const dedicatedInstallEvidencePath = process.argv[4] || '';

const requiredScenarios = [
  'published_artifact_install_only',
  'tenant_a_calls_shared_service',
  'tenant_b_calls_shared_service',
  'transient_failure_retries_with_policy',
  'permanent_failure_preserves_typed_error',
  'worker_restart_replay_does_not_reissue_call',
  'caller_cancellation_propagates_to_service',
  'php_caller_python_service',
  'python_caller_php_service',
  'endpoint_permission_denied_without_information_leak',
  'malformed_payload_refused_before_dispatch',
  'nonexistent_endpoint_typed_not_found',
  'caller_history_attempt_visibility',
  'result_record_and_product_finding_routing',
];

const allowedStatuses = new Set([
  'pass',
  'fail',
  'unsupported',
  'not_covered',
  'runner_blocked',
]);
const resultRoutingScenarioId = 'result_record_and_product_finding_routing';
const routedNonPassStatuses = new Set([
  'fail',
  'unsupported',
  'not_covered',
  'runner_blocked',
]);
const requiredArtifacts = [
  'server',
  'cli',
  'workflow',
  'sdk-php',
  'sdk-python',
  'waterline',
];
const artifactAliases = {
  workflow: ['workflow-php', 'workflow_php', 'workflowPhp'],
  'sdk-php': ['sdk_php'],
  'sdk-python': ['sdk_python', 'python-sdk', 'pythonSdk'],
};
const artifactOwners = {
  server: 'server',
  cli: 'cli',
  workflow: 'workflow',
  'sdk-php': 'sdk-php',
  'sdk-python': 'sdk-python',
  waterline: 'waterline',
};
const placeholderVersionTokens = [
  'latest',
  'current',
  'head',
  'unresolved',
  'placeholder',
  '<latest>',
  '${VERSION}',
  '{{ version }}',
];
const rollingSourceRefPattern = /(^|[/:@=?&#._-])(latest|current|head)(?:$|[/:@?&#._-])/i;
const forbiddenSourceTokens = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_checkout',
  'local_source_checkout',
  'workspace_repo',
  'rolling_server_image_tag',
  'unverified_artifact_source',
];
const cliReleaseAssetNames = new Set([
  'dw.phar',
  'dw-linux-aarch64',
  'dw-linux-x86_64',
  'dw-macos-aarch64',
  'dw-windows-x86_64.exe',
  'dw.rb',
  'install.sh',
  'install.ps1',
  'verify-release.sh',
  'SHA256SUMS',
]);
const scenarioEvidenceRequirements = {
  published_artifact_install_only: [
    {fields: ['artifact_versions', 'artifactVersions'], kind: 'non_empty_object', expected: 'pinned published versions for every required Nexus artifact'},
    {fields: ['artifact_sources', 'artifactSources'], kind: 'non_empty_object', expected: 'published install source for every required Nexus artifact'},
    {fields: ['artifact_source_verification', 'artifactSourceVerification'], kind: 'non_empty_object', expected: 'host proof that every published artifact source resolved to a downloadable public artifact'},
    {fields: ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'], kind: 'boolean_false', expected: 'explicit proof that no local product source checkout was used'},
    {fields: ['install_channels_verified', 'installChannelsVerified'], kind: 'boolean_true', expected: 'published artifact install channels were exercised successfully'},
    {fields: ['artifact_install_evidence', 'artifactInstallEvidence', 'install_evidence', 'installEvidence'], kind: 'non_empty_object', expected: 'explicit install-only proof for every required published Nexus artifact'},
  ],
  tenant_a_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-a', expected: 'tenant-a is recorded as the caller namespace'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared', expected: 'shared is recorded as the target namespace'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string', expected: 'shared Nexus endpoint name invoked by tenant-a'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter', expected: 'tenant-a invoked the shared Greeter service'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet', expected: 'tenant-a invoked Greeter.greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id from the cross-namespace invocation'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string', expected: 'caller workflow result from the shared service'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the tenant-a shared-service invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the tenant-a shared-service invocation'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object', expected: 'service-call record evidence for the tenant-a shared-service invocation'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history evidence for the tenant-a shared-service invocation'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true', expected: 'caller history includes the Nexus call'},
  ],
  tenant_b_calls_shared_service: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'value_equals', value: 'tenant-b', expected: 'tenant-b is recorded as the caller namespace'},
    {fields: ['target_namespace', 'targetNamespace'], kind: 'value_equals', value: 'shared', expected: 'shared is recorded as the target namespace'},
    {fields: ['endpoint_name', 'endpointName'], kind: 'non_empty_string', expected: 'shared Nexus endpoint name invoked by tenant-b'},
    {fields: ['service_name', 'serviceName'], kind: 'value_equals', value: 'Greeter', expected: 'tenant-b invoked the shared Greeter service'},
    {fields: ['operation_name', 'operationName'], kind: 'value_equals', value: 'greet', expected: 'tenant-b invoked Greeter.greet'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id from the cross-namespace invocation'},
    {fields: ['workflow_result', 'workflowResult'], kind: 'non_empty_string', expected: 'caller workflow result from the shared service'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the tenant-b shared-service invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the tenant-b shared-service invocation'},
    {fields: ['service_call_record', 'serviceCallRecord', 'service_call_detail', 'serviceCallDetail'], kind: 'non_empty_object', expected: 'service-call record evidence for the tenant-b shared-service invocation'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history evidence for the tenant-b shared-service invocation'},
    {fields: ['caller_history_recorded', 'callerHistoryRecorded'], kind: 'boolean_true', expected: 'caller history includes the Nexus call'},
  ],
  transient_failure_retries_with_policy: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the retrying call'},
    {fields: ['retry_policy', 'retryPolicy'], kind: 'non_empty_object', expected: 'recorded retry policy applied to the Nexus call'},
    {fields: ['retry_attempts', 'retryAttempts', 'history_attempts', 'historyAttempts', 'service_call_attempts', 'serviceCallAttempts'], kind: 'attempts_at_least', min: 2, expected: 'visible retry attempts for the transient failure'},
    {
      fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'],
      kind: 'boolean_true',
      expected: 'history attempt visibility includes every retry attempt',
      invalid_code: 'retry_attempt_visibility_gap',
      finding_type: 'retry_attempt_visibility_gap',
      owning_surface: 'server',
    },
    {fields: ['completed_after_retry', 'completedAfterRetry'], kind: 'boolean_true', expected: 'the caller completed after retrying the transient failure'},
  ],
  permanent_failure_preserves_typed_error: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the failing call'},
    {fields: ['service_error_type', 'serviceErrorType'], kind: 'non_empty_string', expected: 'typed error emitted by the Nexus service'},
    {fields: ['caller_observed_error_type', 'callerObservedErrorType'], kind: 'non_empty_string', expected: 'typed error observed by the caller workflow'},
    {fields: ['typed_error_preserved', 'typedErrorPreserved'], kind: 'boolean_true', expected: 'typed failure shape is preserved across the boundary'},
  ],
  worker_restart_replay_does_not_reissue_call: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id recovered after replay'},
    {fields: ['published_artifact_worker_execution', 'publishedArtifactWorkerExecution', 'published_worker_execution', 'publishedWorkerExecution'], kind: 'published_worker_execution', expected: 'published worker artifact execution evidence for the caller restart replay cell'},
    {fields: ['issued_call_ids', 'issuedCallIds', 'service_call_ids', 'serviceCallIds', 'call_ids', 'callIds'], kind: 'array_length_at_least', min: 1, expected: 'call ids observed before and after caller worker restart'},
    {fields: ['caller_history_rows', 'callerHistoryRows', 'history_rows', 'historyRows'], kind: 'array_length_at_least', min: 1, expected: 'caller history rows proving the in-flight Nexus call was recovered'},
    {fields: ['service_logs', 'serviceLogs', 'target_service_logs', 'targetServiceLogs'], kind: 'array_length_at_least', min: 1, expected: 'target service logs for the long-running Nexus call'},
    {fields: ['call_issued_at', 'callIssuedAt'], kind: 'non_empty_string', expected: 'timestamp when the long-running Nexus call was first issued'},
    {fields: ['caller_worker_stopped_at', 'callerWorkerStoppedAt', 'worker_stopped_at', 'workerStoppedAt'], kind: 'non_empty_string', expected: 'timestamp when the caller worker was stopped after call issue'},
    {fields: ['caller_worker_restarted_at', 'callerWorkerRestartedAt', 'worker_restarted_at', 'workerRestartedAt'], kind: 'non_empty_string', expected: 'timestamp when the caller worker was restarted'},
    {fields: ['call_completed_at', 'callCompletedAt'], kind: 'non_empty_string', expected: 'timestamp when the recovered Nexus call completed'},
    {fields: ['worker_restart_observed', 'workerRestartObserved'], kind: 'boolean_true', expected: 'caller worker restart was exercised mid-call'},
    {fields: ['history_replay_recovered_call', 'historyReplayRecoveredCall'], kind: 'boolean_true', expected: 'replay recovered the in-flight Nexus call from history'},
    {fields: ['replay_transport', 'replayTransport'], kind: 'replay_transport', expected: 'bounded replay-only transport attempts with at most one fresh-connection stale-socket recovery'},
    {fields: ['service_invocation_count', 'serviceInvocationCount', 'target_service_invocation_count', 'targetServiceInvocationCount'], kind: 'number_equals', value: 1, expected: 'target service was invoked exactly once across restart replay'},
    {fields: ['duplicate_call_assertion', 'duplicateCallAssertion'], kind: 'non_empty_object', expected: 'explicit duplicate-call assertion evidence for the replay cell'},
    {fields: ['duplicate_call_issue_count', 'duplicateCallIssueCount'], kind: 'number_equals', value: 0, expected: 'replay did not issue a duplicate network call'},
  ],
  caller_cancellation_propagates_to_service: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cancelled call'},
    {fields: ['published_artifact_worker_execution', 'publishedArtifactWorkerExecution', 'published_worker_execution', 'publishedWorkerExecution'], kind: 'published_worker_execution', expected: 'published worker artifact execution evidence for the caller cancellation propagation cell'},
    {fields: ['caller_history_rows', 'callerHistoryRows', 'history_rows', 'historyRows'], kind: 'array_length_at_least', min: 1, expected: 'caller history rows for the cancelled Nexus call'},
    {fields: ['service_logs', 'serviceLogs', 'target_service_logs', 'targetServiceLogs'], kind: 'array_length_at_least', min: 1, expected: 'target service logs proving cancellation was observed'},
    {fields: ['caller_cancelled_at', 'callerCancelledAt'], kind: 'non_empty_string', expected: 'caller cancellation timestamp'},
    {fields: ['target_cancelled_at', 'targetCancelledAt'], kind: 'non_empty_string', expected: 'target namespace cancellation timestamp'},
    {fields: ['cancellation_propagation_ms', 'cancellationPropagationMs'], kind: 'non_empty_string', expected: 'measured cancellation propagation duration in milliseconds'},
    {fields: ['within_propagation_window', 'withinPropagationWindow'], kind: 'boolean_true', expected: 'typed cancellation was observed within the documented propagation window'},
    {fields: ['cancellation_type', 'cancellationType', 'target_cancellation_type', 'targetCancellationType'], kind: 'non_empty_string', expected: 'typed cancellation class observed by the target service'},
    {fields: ['typed_cancellation_observed', 'typedCancellationObserved'], kind: 'boolean_true', expected: 'target worker observed typed cancellation'},
  ],
  php_caller_python_service: [
    {fields: ['caller_workflow_instance_id', 'callerWorkflowInstanceId', 'caller_workflow_id', 'callerWorkflowId'], kind: 'non_empty_string', expected: 'caller workflow id for the PHP caller'},
    {fields: ['caller_workflow_run_id', 'callerWorkflowRunId', 'caller_run_id', 'callerRunId', 'run_id', 'runId'], kind: 'non_empty_string', expected: 'caller workflow run id for the PHP caller'},
    {fields: ['caller_sdk_language', 'callerSdkLanguage', 'caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'sdk-php', expected: 'published PHP SDK caller language'},
    {fields: ['service_sdk_language', 'serviceSdkLanguage', 'service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'sdk-python', expected: 'Python service SDK language'},
    {fields: ['operation_name', 'operationName'], kind: 'non_empty_string', expected: 'operation name invoked across the PHP-to-Python service boundary'},
    {fields: ['request_payload', 'requestPayload', 'request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request payload sent by the PHP caller to the Python service'},
    {fields: ['response_or_failure_surface', 'responseOrFailureSurface', 'response', 'responseEvidence', 'invocation_response', 'invocationResponse', 'failure_surface', 'failureSurface', 'invocation_failure', 'invocationFailure'], kind: 'non_empty_object', expected: 'response or failure surface observed by the PHP caller from the Python service'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cross-language call'},
    {fields: ['artifact_tuple', 'artifactTuple', 'artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions', 'resolved_artifact_versions', 'resolvedArtifactVersions'], kind: 'artifact_tuple', expected: 'published artifact tuple used for the PHP-to-Python service-call cell'},
    {fields: ['published_artifact_worker_execution', 'publishedArtifactWorkerExecution', 'published_worker_execution', 'publishedWorkerExecution'], kind: 'published_cross_language_worker_execution', expected: 'published sdk-php caller plus workflow-php and sdk-python service execution evidence for the PHP-to-Python service-call cell'},
    {
      fields: ['service_health', 'serviceHealth', 'published_service_health', 'publishedServiceHealth'],
      kind: 'published_service_health',
      runtime: 'sdk-python',
      expected: 'valid /health response from the published Python service shard used by the PHP-to-Python service-call cell',
      invalid_code: 'nexus_published_service_health_failed',
      finding_type: 'nexus_published_service_health_failed',
      owning_surface: 'sdk-python',
    },
    {
      fields: ['service_probe_succeeded', 'serviceProbeSucceeded'],
      kind: 'boolean_true',
      expected: 'successful 2xx invocation of the published Python service shard',
      invalid_code: 'nexus_published_service_invocation_failed',
      finding_type: 'nexus_published_service_invocation_failed',
      owning_surface: 'sdk-python',
    },
    {fields: ['service_response_payload', 'serviceResponsePayload'], kind: 'non_empty_object', expected: 'concrete payload returned by the published Python service invocation'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true', expected: 'payload round-tripped between PHP and Python'},
    {fields: ['typed_error_probe_succeeded', 'typedErrorProbeSucceeded'], kind: 'boolean_true', expected: 'published Python service returned an observed typed failure envelope'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true', expected: 'typed error round-tripped between PHP and Python'},
  ],
  python_caller_php_service: [
    {fields: ['caller_workflow_instance_id', 'callerWorkflowInstanceId', 'caller_workflow_id', 'callerWorkflowId'], kind: 'non_empty_string', expected: 'caller workflow id for the Python caller'},
    {fields: ['caller_workflow_run_id', 'callerWorkflowRunId', 'caller_run_id', 'callerRunId', 'run_id', 'runId'], kind: 'non_empty_string', expected: 'caller workflow run id for the Python caller'},
    {fields: ['caller_sdk_language', 'callerSdkLanguage', 'caller_runtime', 'callerRuntime'], kind: 'value_equals', value: 'sdk-python', expected: 'Python caller SDK language'},
    {fields: ['service_sdk_language', 'serviceSdkLanguage', 'service_runtime', 'serviceRuntime'], kind: 'value_equals', value: 'workflow-php', expected: 'PHP workflow service SDK language'},
    {fields: ['operation_name', 'operationName'], kind: 'non_empty_string', expected: 'operation name invoked across the Python-to-PHP service boundary'},
    {fields: ['request_payload', 'requestPayload', 'request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request payload sent by the Python caller to the PHP service'},
    {fields: ['response_or_failure_surface', 'responseOrFailureSurface', 'response', 'responseEvidence', 'invocation_response', 'invocationResponse', 'failure_surface', 'failureSurface', 'invocation_failure', 'invocationFailure'], kind: 'non_empty_object', expected: 'response or failure surface observed by the Python caller from the PHP service'},
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id for the cross-language call'},
    {fields: ['artifact_tuple', 'artifactTuple', 'artifact_versions', 'artifactVersions', 'published_artifact_versions', 'publishedArtifactVersions', 'resolved_artifact_versions', 'resolvedArtifactVersions'], kind: 'artifact_tuple', expected: 'published artifact tuple used for the Python-to-PHP service-call cell'},
    {fields: ['published_artifact_worker_execution', 'publishedArtifactWorkerExecution', 'published_worker_execution', 'publishedWorkerExecution'], kind: 'published_cross_language_worker_execution', expected: 'published sdk-php caller plus workflow-php and sdk-python service execution evidence for the Python-to-PHP service-call cell'},
    {
      fields: ['service_health', 'serviceHealth', 'published_service_health', 'publishedServiceHealth'],
      kind: 'published_service_health',
      runtime: 'workflow-php',
      expected: 'valid /health response from the published PHP service shard used by the Python-to-PHP service-call cell',
      invalid_code: 'nexus_published_service_health_failed',
      finding_type: 'nexus_published_service_health_failed',
      owning_surface: 'workflow',
    },
    {
      fields: ['service_probe_succeeded', 'serviceProbeSucceeded'],
      kind: 'boolean_true',
      expected: 'successful 2xx invocation of the published PHP service shard',
      invalid_code: 'nexus_published_service_invocation_failed',
      finding_type: 'nexus_published_service_invocation_failed',
      owning_surface: 'workflow',
    },
    {fields: ['service_response_payload', 'serviceResponsePayload'], kind: 'non_empty_object', expected: 'concrete payload returned by the published PHP service invocation'},
    {fields: ['payload_round_trip', 'payloadRoundTrip'], kind: 'boolean_true', expected: 'payload round-tripped between Python and PHP'},
    {fields: ['typed_error_probe_succeeded', 'typedErrorProbeSucceeded'], kind: 'boolean_true', expected: 'published PHP service returned an observed typed failure envelope'},
    {fields: ['typed_error_round_trip', 'typedErrorRoundTrip'], kind: 'boolean_true', expected: 'typed error round-tripped between Python and PHP'},
  ],
  endpoint_permission_denied_without_information_leak: [
    {fields: ['caller_namespace', 'callerNamespace'], kind: 'non_empty_string', expected: 'unauthorized caller namespace attempted the invocation'},
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the unauthorized Nexus invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the unauthorized Nexus refusal'},
    {fields: ['dispatch_evidence', 'dispatchEvidence'], kind: 'non_empty_object', expected: 'dispatch/no-dispatch evidence for the unauthorized Nexus refusal'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history query evidence for the unauthorized Nexus refusal'},
    {
      fields: ['caller_history_query_succeeded', 'callerHistoryQuerySucceeded'],
      kind: 'boolean_true',
      expected: 'caller-history query succeeded for no-dispatch evidence',
      invalid_code: 'nexus_refusal_no_dispatch_evidence_gap',
      finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    },
    {
      fields: ['caller_history_state_proven', 'callerHistoryStateProven'],
      kind: 'boolean_true',
      expected: 'caller-history response proved a matching rejected state for the refused call',
      invalid_code: 'nexus_refusal_no_dispatch_evidence_gap',
      finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    },
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed permission-denied refusal'},
    {
      fields: ['authorization_refusal_disclosed_endpoint_existence', 'authorizationRefusalDisclosedEndpointExistence', 'endpoint_existence_disclosed', 'endpointExistenceDisclosed'],
      kind: 'boolean_false',
      expected: 'permission-denied response does not disclose endpoint existence',
      invalid_code: 'permission_denied_information_leak',
      finding_type: 'permission_denied_information_leak',
      owning_surface: 'server',
    },
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'forbidden call was refused before handler dispatch'},
    {fields: ['service_invoked', 'serviceInvoked'], kind: 'boolean_false', expected: 'forbidden call did not invoke the service'},
  ],
  malformed_payload_refused_before_dispatch: [
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the malformed Nexus invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the malformed-payload refusal'},
    {fields: ['dispatch_evidence', 'dispatchEvidence'], kind: 'non_empty_object', expected: 'dispatch/no-dispatch evidence for the malformed-payload refusal'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history query evidence for the malformed-payload refusal'},
    {
      fields: ['caller_history_query_succeeded', 'callerHistoryQuerySucceeded'],
      kind: 'boolean_true',
      expected: 'caller-history query succeeded for no-dispatch evidence',
      invalid_code: 'nexus_refusal_no_dispatch_evidence_gap',
      finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    },
    {
      fields: ['caller_history_state_proven', 'callerHistoryStateProven'],
      kind: 'boolean_true',
      expected: 'caller-history response proved the malformed payload was not admitted',
      invalid_code: 'nexus_refusal_no_dispatch_evidence_gap',
      finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    },
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed malformed-payload refusal'},
    {fields: ['typed_error', 'typedError'], kind: 'non_empty_string', expected: 'schema or payload error type returned to the caller'},
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'malformed payload was refused before handler dispatch'},
    {fields: ['service_invoked', 'serviceInvoked'], kind: 'boolean_false', expected: 'malformed payload did not invoke the service'},
  ],
  nonexistent_endpoint_typed_not_found: [
    {fields: ['request', 'requestEvidence', 'invocation_request', 'invocationRequest'], kind: 'non_empty_object', expected: 'request evidence for the nonexistent Nexus endpoint invocation'},
    {fields: ['response', 'responseEvidence', 'invocation_response', 'invocationResponse'], kind: 'non_empty_object', expected: 'response evidence for the nonexistent-endpoint refusal'},
    {fields: ['dispatch_evidence', 'dispatchEvidence'], kind: 'non_empty_object', expected: 'dispatch/no-dispatch evidence for the nonexistent-endpoint refusal'},
    {fields: ['caller_history_evidence', 'callerHistoryEvidence', 'caller_history', 'callerHistory'], kind: 'non_empty_object', expected: 'caller-history query evidence for the nonexistent-endpoint refusal'},
    {
      fields: ['caller_history_query_succeeded', 'callerHistoryQuerySucceeded'],
      kind: 'boolean_true',
      expected: 'caller-history query succeeded for no-dispatch evidence',
      invalid_code: 'nexus_refusal_no_dispatch_evidence_gap',
      finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    },
    {
      fields: ['caller_history_state_proven', 'callerHistoryStateProven'],
      kind: 'boolean_true',
      expected: 'caller-history response proved the nonexistent endpoint was not admitted',
      invalid_code: 'nexus_refusal_no_dispatch_evidence_gap',
      finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    },
    {fields: ['refusal_status', 'refusalStatus', 'error_type', 'errorType'], kind: 'non_empty_string', expected: 'typed not-found refusal'},
    {fields: ['typed_error', 'typedError'], kind: 'non_empty_string', expected: 'not-found error type returned to the caller'},
    {fields: ['handler_dispatch_count', 'handlerDispatchCount'], kind: 'number_equals', value: 0, expected: 'nonexistent endpoint did not dispatch a handler'},
    {fields: ['service_invoked', 'serviceInvoked'], kind: 'boolean_false', expected: 'nonexistent endpoint did not invoke the service'},
  ],
  caller_history_attempt_visibility: [
    {fields: ['service_call_id', 'serviceCallId'], kind: 'non_empty_string', expected: 'durable service-call id visible in caller history'},
    {fields: ['caller_history_attempts', 'callerHistoryAttempts'], kind: 'array_length_at_least', min: 2, expected: 'caller history includes per-attempt retry entries'},
    {
      fields: ['history_attempt_visibility_includes_retry_attempts', 'historyAttemptVisibilityIncludesRetryAttempts'],
      kind: 'boolean_true',
      expected: 'caller history exposes retry-attempt visibility',
      invalid_code: 'retry_attempt_visibility_gap',
      finding_type: 'retry_attempt_visibility_gap',
      owning_surface: 'server',
    },
    {fields: ['service_call_detail_attempts', 'serviceCallDetailAttempts', 'service_call_attempts', 'serviceCallAttempts'], kind: 'array_length_at_least', min: 2, expected: 'service-call detail exposes per-attempt retry entries'},
  ],
  result_record_and_product_finding_routing: [
    {fields: ['result_record_emitted', 'resultRecordEmitted'], kind: 'boolean_true', expected: 'Nexus result record was emitted for ledger recording'},
    {fields: ['finding_links_emitted', 'findingLinksEmitted'], kind: 'boolean_true', expected: 'scenario finding links were emitted'},
    {fields: ['waterline_operator_visibility', 'waterlineOperatorVisibility'], kind: 'boolean_true', expected: 'operator-visible Nexus evidence is present for Waterline'},
  ],
};

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function envValue(name) {
  const value = process.env[name];
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

function artifactVersionsFromEnv() {
  const versions = {};
  const mapping = {
    server: 'DW_SERVER_VERSION',
    cli: 'DW_CLI_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    'sdk-php': 'DW_PHP_SDK_VERSION',
    'sdk-python': 'DW_PYTHON_SDK_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  for (const [artifact, envName] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (value !== null) {
      versions[artifact] = value;
    }
  }

  return versions;
}

function artifactSourcesFromEnv() {
  const sources = {};
  const mapping = {
    server: 'DW_SERVER_ARTIFACT_SOURCE',
    cli: 'DW_CLI_ARTIFACT_SOURCE',
    workflow: 'DW_WORKFLOW_ARTIFACT_SOURCE',
    'sdk-php': 'DW_PHP_SDK_ARTIFACT_SOURCE',
    'sdk-python': 'DW_PYTHON_SDK_ARTIFACT_SOURCE',
    waterline: 'DW_WATERLINE_ARTIFACT_SOURCE',
  };
  const workflowPhpSource = envValue('DW_WORKFLOW_PHP_ARTIFACT_SOURCE');

  for (const [artifact, envName] of Object.entries(mapping)) {
    const value = envValue(envName);
    if (value !== null) {
      sources[artifact] = value;
    }
  }
  if (!sources.workflow && workflowPhpSource !== null) {
    sources.workflow = workflowPhpSource;
  }

  return sources;
}

function conformanceRunIdFrom(evidence) {
  return stringValue(evidence.conformance_run_id)
    || stringValue(evidence.conformanceRunId)
    || envValue('DW_NEXUS_CONFORMANCE_RUN_ID')
    || envValue('DW_CONFORMANCE_RUN_ID')
    || envValue('CONFORMANCE_RUN_ID')
    || null;
}

function readEvidence(filePath) {
  if (!filePath) {
    return {};
  }

  if (!fs.existsSync(filePath)) {
    return {
      findings: [
        {
          scenario_id: 'published_artifact_install_only',
          type: 'conformance_runner_coverage_gap',
          finding_type: 'conformance_runner_coverage_gap',
          owning_surface: 'conformance_harness',
          artifact_versions: artifactVersionsFromEnv(),
          observed_behavior: `DW_NEXUS_EVIDENCE_JSON did not point at a readable file: ${filePath}`,
          expected_behavior: 'host runner supplies a Nexus evidence JSON document or records focused uncovered cells',
          next_acceptance_criterion: 'rerun Nexus conformance with a readable published-artifact evidence document',
        },
      ],
    };
  }

  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function readDedicatedInstallEvidence(filePath) {
  if (!filePath) {
    return {installEvidence: null, findings: []};
  }

  if (!fs.existsSync(filePath)) {
    return {
      installEvidence: null,
      findings: [
        {
          scenario_id: 'published_artifact_install_only',
          type: 'conformance_runner_coverage_gap',
          finding_type: 'conformance_runner_coverage_gap',
          owning_surface: 'conformance_harness',
          artifact_versions: artifactVersionsFromEnv(),
          observed_behavior: `DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE did not point at a readable file: ${filePath}`,
          expected_behavior: 'host runner supplies readable published artifact install evidence or records focused uncovered cells',
          next_acceptance_criterion: 'rerun Nexus conformance with readable install evidence for every published artifact under test',
        },
      ],
    };
  }

  const parsed = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  return {
    installEvidence: installEvidenceFrom(parsed),
    findings: [],
  };
}

function normalizeScenarioResult(scenarioId, input, artifactVersions, installEvidenceForPromotion = null) {
  if (!input || typeof input !== 'object') {
    return missingScenarioResult(scenarioId, artifactVersions);
  }

  const status = typeof input.status === 'string' && allowedStatuses.has(input.status)
    ? input.status
    : 'not_covered';

  const normalized = {
    scenario_id: scenarioId,
    status,
    observed_outputs: input.observed_outputs && typeof input.observed_outputs === 'object'
      ? input.observed_outputs
      : {},
    linked_findings: Array.isArray(input.linked_findings) ? input.linked_findings : [],
  };

  if (scenarioId === 'published_artifact_install_only') {
    normalized.observed_outputs = withPromotedInstallEvidence(
      normalized.observed_outputs,
      installEvidenceForPromotion,
    );
  }

  if (status === 'pass') {
    const evidenceFailures = scenarioEvidenceFailures(
      scenarioId,
      normalized.observed_outputs,
      artifactVersions,
    );
    if (evidenceFailures.length > 0) {
      normalized.status = evidenceFailures.some((failure) => failure.result_status === 'fail')
        ? 'fail'
        : 'not_covered';
      normalized.observed_outputs = {
        ...normalized.observed_outputs,
        result_gate_failed: true,
        scenario_evidence_failures: evidenceFailures,
      };
      normalized.linked_findings = [
        ...normalized.linked_findings,
        ...evidenceFailures.map((failure) => scenarioEvidenceFinding(scenarioId, artifactVersions, failure)),
      ];
    } else if (Object.keys(normalized.observed_outputs).length === 0) {
      normalized.status = 'not_covered';
      normalized.linked_findings = [missingEvidenceFinding(scenarioId, artifactVersions)];
    }
    return normalized;
  }

  if (normalized.linked_findings.length === 0) {
    normalized.linked_findings = [missingEvidenceFinding(scenarioId, artifactVersions)];
  }

  return normalized;
}

function withPromotedInstallEvidence(outputs, installEvidence) {
  const promoted = outputs && typeof outputs === 'object' && !Array.isArray(outputs)
    ? {...outputs}
    : {};
  if (!installEvidence || typeof installEvidence !== 'object' || Array.isArray(installEvidence)) {
    return promoted;
  }
  if (installEvidenceFrom(promoted) === null) {
    promoted.artifact_install_evidence = installEvidence;
  }
  if (!Object.hasOwn(promoted, 'local_product_source_checkouts_used')
    && !Object.hasOwn(promoted, 'localProductSourceCheckoutsUsed')
    && hasExplicitFalseLocalProductSourceFlag(installEvidence)) {
    promoted.local_product_source_checkouts_used = false;
  }
  if (!Object.hasOwn(promoted, 'install_channels_verified')
    && !Object.hasOwn(promoted, 'installChannelsVerified')
    && installEvidenceArtifactsAllPass(installEvidence)) {
    promoted.install_channels_verified = true;
  }
  if (!Object.hasOwn(promoted, 'published_install_tuple_proven')
    && !Object.hasOwn(promoted, 'publishedInstallTupleProven')
    && truthy(installEvidence.published_install_tuple_proven ?? installEvidence.publishedInstallTupleProven)) {
    promoted.published_install_tuple_proven = true;
  }

  return promoted;
}

function scenarioEvidenceFailures(scenarioId, observedOutputs, artifactVersions = {}) {
  const requirements = scenarioEvidenceRequirements[scenarioId] || [];
  const failures = [];

  for (const requirement of requirements) {
    const lookup = evidenceLookup(observedOutputs, requirement.fields);
    const missing = !lookup.present || isMissingEvidenceValue(lookup.value, requirement.kind);
    if (missing) {
      failures.push({
        code: 'missing_scenario_specific_evidence',
        field: requirement.fields[0],
        expected: requirement.expected,
        result_status: 'not_covered',
        finding_type: 'conformance_runner_coverage_gap',
        owning_surface: 'conformance_harness',
      });
      continue;
    }

    if (! evidenceRequirementSatisfied(requirement, lookup.value, artifactVersions)) {
      failures.push({
        code: requirement.invalid_code || 'invalid_scenario_specific_evidence',
        field: requirement.fields[0],
        expected: requirement.expected,
        observed: lookup.value,
        result_status: requirement.invalid_result_status || 'fail',
        finding_type: requirement.finding_type || 'nexus_scenario_evidence_mismatch',
        owning_surface: requirement.owning_surface || 'conformance_harness',
      });
    }
  }

  failures.push(...refusalNoDispatchEvidenceFailures(scenarioId, observedOutputs));

  return failures;
}

function refusalNoDispatchEvidenceFailures(scenarioId, observedOutputs) {
  const policies = {
    endpoint_permission_denied_without_information_leak: {
      requireRejectedHistory: true,
      field: 'caller_history_evidence',
      expected: 'caller history proves the unauthorized Nexus call reached a rejected/cancelled/timed_out state without handler dispatch',
    },
    malformed_payload_refused_before_dispatch: {
      requireEmptyHistory: true,
      field: 'caller_history_evidence',
      expected: 'caller history proves the malformed Nexus payload was refused before service-call admission or handler dispatch',
    },
    nonexistent_endpoint_typed_not_found: {
      requireEmptyHistory: true,
      field: 'caller_history_evidence',
      expected: 'caller history proves the nonexistent Nexus endpoint was refused before service-call admission or handler dispatch',
    },
  };
  const policy = policies[scenarioId];
  if (!policy) {
    return [];
  }

  const dispatch = evidenceLookup(observedOutputs, ['dispatch_evidence', 'dispatchEvidence']).value;
  if (!hasNonEmptyObjectValue(dispatch)) {
    return [];
  }

  const rows = refusalHistoryRows(observedOutputs, dispatch);
  const serviceCallId = stringValue(
    observedOutputs?.service_call_id
      ?? observedOutputs?.serviceCallId
      ?? dispatch.service_call_id
      ?? dispatch.serviceCallId,
  );
  const matchingRows = serviceCallId === ''
    ? rows
    : rows.filter((row) => serviceCallIdForHistoryRow(row) === serviceCallId);
  const disallowedRows = rows.filter((row) => !historyRowProvesNoDispatch(row));
  const failures = [];

  if (numberValue(dispatch.handler_dispatch_count ?? dispatch.handlerDispatchCount) !== 0
    || !explicitFalse(dispatch.service_invoked ?? dispatch.serviceInvoked)) {
    failures.push(refusalNoDispatchFailure(
      'dispatch_evidence',
      'dispatch evidence reports zero handler dispatches and service_invoked=false',
      dispatch,
    ));
  }

  if (disallowedRows.length > 0) {
    failures.push(refusalNoDispatchFailure(
      policy.field,
      policy.expected,
      disallowedRows,
    ));
  }

  if (policy.requireRejectedHistory
    && disallowedRows.length === 0
    && !matchingRows.some(historyRowProvesNoDispatch)) {
    failures.push(refusalNoDispatchFailure(
      policy.field,
      policy.expected,
      rows,
    ));
  }

  if (policy.requireEmptyHistory && rows.length > 0) {
    failures.push(refusalNoDispatchFailure(
      policy.field,
      policy.expected,
      rows,
    ));
  }

  return failures;
}

function refusalNoDispatchFailure(field, expected, observed) {
  return {
    code: 'nexus_refusal_no_dispatch_evidence_gap',
    field,
    expected,
    observed,
    result_status: 'fail',
    finding_type: 'nexus_refusal_no_dispatch_evidence_gap',
    owning_surface: 'conformance_harness',
  };
}

function refusalHistoryRows(observedOutputs, dispatch) {
  return [
    ...historyRowsFromEvidenceValue(dispatch.caller_history_rows ?? dispatch.callerHistoryRows ?? dispatch.history_rows ?? dispatch.historyRows),
    ...historyRowsFromEvidenceValue(dispatch.caller_history_response ?? dispatch.callerHistoryResponse),
    ...historyRowsFromEvidenceValue(observedOutputs?.caller_history_evidence ?? observedOutputs?.callerHistoryEvidence),
    ...historyRowsFromEvidenceValue(observedOutputs?.caller_history ?? observedOutputs?.callerHistory),
  ];
}

function historyRowsFromEvidenceValue(value) {
  if (Array.isArray(value)) {
    return value.filter(hasNonEmptyObjectValue);
  }
  if (!hasNonEmptyObjectValue(value)) {
    return [];
  }

  const candidates = [value];
  if (hasNonEmptyObjectValue(value.body)) {
    candidates.push(value.body);
  }
  if (hasNonEmptyObjectValue(value.response)) {
    candidates.push(value.response);
  }
  if (hasNonEmptyObjectValue(value.response?.body)) {
    candidates.push(value.response.body);
  }

  for (const candidate of candidates) {
    for (const field of ['nexus_operations', 'nexusOperations', 'operations', 'rows', 'history_rows', 'historyRows']) {
      if (Array.isArray(candidate[field])) {
        return candidate[field].filter(hasNonEmptyObjectValue);
      }
    }
  }

  return [];
}

function serviceCallIdForHistoryRow(row) {
  return stringValue(row.service_call_id ?? row.serviceCallId ?? row.id);
}

function historyRowProvesNoDispatch(row) {
  const outcome = stringValue(row.outcome ?? row.result ?? row.reason).toLowerCase();
  return outcome.startsWith('rejected_')
    || ['cancelled', 'canceled', 'timed_out'].includes(outcome);
}

function evidenceLookup(outputs, fields) {
  const container = outputs && typeof outputs === 'object' && !Array.isArray(outputs) ? outputs : {};
  for (const field of fields) {
    if (Object.hasOwn(container, field)) {
      return {present: true, value: container[field]};
    }
  }

  return {present: false, value: undefined};
}

function isMissingEvidenceValue(value, kind) {
  if (value === null || value === undefined) {
    return true;
  }
  if (kind === 'non_empty_object') {
    return !value || typeof value !== 'object' || Array.isArray(value) || Object.keys(value).length === 0;
  }
  if (kind === 'artifact_tuple') {
    return !hasNonEmptyObjectValue(value);
  }
  if (kind === 'published_worker_execution') {
    return !hasNonEmptyObjectValue(value);
  }
  if (kind === 'published_cross_language_worker_execution') {
    return !hasNonEmptyObjectValue(value);
  }
  if (kind === 'published_service_health') {
    return !hasNonEmptyObjectValue(value);
  }
  if (kind === 'replay_transport') {
    return !hasNonEmptyObjectValue(value);
  }
  if (kind === 'array_length_at_least') {
    return !Array.isArray(value) || value.length === 0;
  }
  if (kind === 'attempts_at_least') {
    if (Array.isArray(value)) {
      return value.length === 0;
    }

    return numberValue(value) === null;
  }

  return stringValue(value) === '';
}

function evidenceRequirementSatisfied(requirement, value, artifactVersions = {}) {
  switch (requirement.kind) {
    case 'non_empty_string':
      return stringValue(value) !== '';
    case 'non_empty_object':
      return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
    case 'boolean_true':
      return truthy(value);
    case 'boolean_false':
      return explicitFalse(value);
    case 'value_equals':
      return stringValue(value) === stringValue(requirement.value);
    case 'number_equals':
      return numberValue(value) === Number(requirement.value);
    case 'array_length_at_least':
      return Array.isArray(value) && value.length >= Number(requirement.min);
    case 'attempts_at_least':
      if (Array.isArray(value)) {
        return value.length >= Number(requirement.min);
      }

      return (numberValue(value) ?? 0) >= Number(requirement.min);
    case 'artifact_tuple':
      return artifactTupleSatisfied(value);
    case 'published_worker_execution':
      return publishedWorkerExecutionSatisfied(value);
    case 'published_cross_language_worker_execution':
      return publishedCrossLanguageWorkerExecutionSatisfied(value);
    case 'published_service_health':
      return publishedServiceHealthSatisfied(
        value,
        requirement.runtime,
        stringValue(artifactVersions[requirement.runtime === 'sdk-python' ? 'sdk-python' : 'workflow']),
      );
    case 'replay_transport':
      return replayTransportSatisfied(value);
    default:
      return false;
  }
}

function replayTransportSatisfied(value) {
  if (!hasNonEmptyObjectValue(value)
    || value.strategy !== 'retry_once_on_stale_socket'
    || numberValue(value.max_retries) !== 1
    || !Array.isArray(value.attempts)
    || value.attempts.length < 1
    || value.attempts.length > 2) {
    return false;
  }

  const retryCount = numberValue(value.retry_count);
  const first = value.attempts[0];
  const second = value.attempts[1];
  if (retryCount === null
    || retryCount !== value.attempts.length - 1
    || first?.attempt !== 1
    || first?.connection !== 'pooled') {
    return false;
  }

  const requestBodyHashes = new Set(value.attempts.map((attempt) => attempt?.request_body_sha256));
  const idempotencyKeyHashes = new Set(value.attempts.map((attempt) => attempt?.idempotency_key_sha256));
  if (requestBodyHashes.size !== 1
    || idempotencyKeyHashes.size !== 1
    || !/^[a-f0-9]{64}$/.test(String(first.request_body_sha256 || ''))
    || !/^[a-f0-9]{64}$/.test(String(first.idempotency_key_sha256 || ''))) {
    return false;
  }

  if (second === undefined) {
    return retryCount === 0
      && value.recovery_attempted === false
      && value.fresh_connection_used === false;
  }

  return retryCount === 1
    && value.recovery_needed === true
    && value.recovery_attempted === true
    && value.fresh_connection_used === true
    && first.outcome === 'transport_error'
    && first.recognized_stale_socket === true
    && second.attempt === 2
    && second.connection === 'fresh';
}

function artifactTupleSatisfied(value) {
  if (!hasNonEmptyObjectValue(value)) {
    return false;
  }

  const versions = normalizeArtifactMap(mergeMaps(
    value.artifact_versions,
    value.artifactVersions,
    value.published_artifact_versions,
    value.publishedArtifactVersions,
    value.resolved_artifact_versions,
    value.resolvedArtifactVersions,
    value.versions,
    value,
  ));

  if (Array.isArray(value.artifacts)) {
    for (const entry of value.artifacts) {
      if (!hasNonEmptyObjectValue(entry)) {
        continue;
      }
      const artifact = canonicalPublishedWorkerArtifact(entry.artifact ?? entry.name ?? entry.id);
      const key = artifact === 'workflow-php' ? 'workflow' : artifact;
      if (requiredArtifacts.includes(key) && stringValue(versions[key]) === '') {
        versions[key] = entry.version ?? entry.artifact_version ?? entry.artifactVersion;
      }
    }
  }

  return requiredArtifacts.every((artifact) => (
    isExactPublishedArtifactVersion(stringValue(versions[artifact]), artifact)
  ));
}

function publishedWorkerExecutionSatisfied(value) {
  if (!hasNonEmptyObjectValue(value)) {
    return false;
  }
  if (!explicitFalse(value.local_product_source_checkouts_used)
    && !explicitFalse(value.localProductSourceCheckoutsUsed)) {
    return false;
  }

  const entries = publishedWorkerExecutionEntries(value);
  if (entries.length === 0) {
    return false;
  }

  return entries.every((entry) => {
    if (!hasNonEmptyObjectValue(entry)) {
      return false;
    }

    const artifact = canonicalPublishedWorkerArtifact(entry.artifact ?? entry.name ?? entry.id);
    const artifactSourceKey = artifact === 'workflow-php' ? 'workflow' : artifact;
    const version = stringValue(entry.version ?? entry.artifact_version ?? entry.artifactVersion);
    const source = stringValue(entry.source ?? entry.install_source ?? entry.installSource ?? entry.artifact_source ?? entry.artifactSource);
    const status = stringValue(entry.status ?? entry.result ?? entry.outcome).toLowerCase();

    return ['server', 'workflow-php', 'sdk-php', 'sdk-python'].includes(artifact)
      && status === 'pass'
      && isExactPublishedArtifactVersion(version, artifactSourceKey)
      && source !== ''
      && !containsForbiddenSourceToken(source)
      && matchesPublishedArtifactSource(artifactSourceKey, version, source)
      && !truthy(entry.local_product_source_checkouts_used)
      && !truthy(entry.localProductSourceCheckoutsUsed);
  });
}

function publishedCrossLanguageWorkerExecutionSatisfied(value) {
  if (!publishedWorkerExecutionSatisfied(value)) {
    return false;
  }

  const artifacts = new Set(publishedWorkerExecutionEntries(value).map((entry) => (
    canonicalPublishedWorkerArtifact(entry.artifact ?? entry.name ?? entry.id)
  )));

  return artifacts.has('workflow-php') && artifacts.has('sdk-php') && artifacts.has('sdk-python');
}

function publishedServiceHealthSatisfied(value, runtime, expectedVersion) {
  const entry = publishedServiceHealthEntry(value, runtime);
  if (!hasNonEmptyObjectValue(entry)) {
    return false;
  }

  const response = firstObject(
    entry.health_response,
    entry.healthResponse,
    entry.response,
    entry.probe_response,
    entry.probeResponse,
  );
  const body = firstObject(response?.body, entry.body);
  const status = numberValue(response?.status ?? entry.status);
  const version = stringValue(
    entry.package_version
      ?? entry.packageVersion
      ?? entry.artifact_version
      ?? entry.artifactVersion
      ?? body?.package_version
      ?? body?.packageVersion,
  );

  const artifact = runtime === 'sdk-python' ? 'sdk-python' : 'workflow';

  return truthy(entry.health_succeeded ?? entry.healthSucceeded ?? entry.service_health_succeeded ?? entry.serviceHealthSucceeded)
    && status !== null
    && status >= 200
    && status < 300
    && truthy(response?.ok ?? entry.ok)
    && stringValue(body?.runtime ?? entry.runtime ?? entry.sdk_language ?? entry.sdkLanguage) === runtime
    && truthy(body?.service_started ?? body?.serviceStarted ?? entry.service_started ?? entry.serviceStarted)
    && truthy(body?.package_imported ?? body?.packageImported ?? entry.package_imported ?? entry.packageImported)
    && isExactPublishedArtifactVersion(version, artifact)
    && samePublishedArtifactVersion(artifact, expectedVersion, version);
}

function publishedServiceHealthEntry(value, runtime) {
  if (!hasNonEmptyObjectValue(value)) {
    return null;
  }

  const runtimeKey = runtime === 'workflow-php' ? 'php' : 'python';
  const aliases = [
    runtime,
    runtime.replace('-', '_'),
    runtimeKey,
    `${runtimeKey}_service`,
    `${runtimeKey}Service`,
    runtime === 'workflow-php' ? 'workflow_php' : 'sdk_python',
    runtime === 'workflow-php' ? 'workflowPhp' : 'sdkPython',
  ];

  for (const alias of aliases) {
    if (hasNonEmptyObjectValue(value[alias])) {
      return value[alias];
    }
  }

  if (stringValue(value.sdk_language ?? value.sdkLanguage ?? value.runtime) === runtime) {
    return value;
  }

  return null;
}

function firstObject(...values) {
  return values.find(hasNonEmptyObjectValue) || {};
}

function publishedWorkerExecutionEntries(value) {
  for (const field of [
    'artifacts',
    'workers',
    'worker_artifacts',
    'workerArtifacts',
    'artifact_executions',
    'artifactExecutions',
  ]) {
    if (Array.isArray(value[field])) {
      return value[field];
    }
  }

  if (stringValue(value.artifact ?? value.name ?? value.id) !== '') {
    return [value];
  }

  return [];
}

function canonicalPublishedWorkerArtifact(value) {
  const artifact = stringValue(value).toLowerCase().replace(/_/g, '-');
  if (['workflow', 'workflow-php', 'php', 'durable-workflow/workflow'].includes(artifact)) {
    return 'workflow-php';
  }
  if (['sdk-php', 'durable-workflow/sdk'].includes(artifact)) {
    return 'sdk-php';
  }
  if (['sdk-python', 'python-sdk', 'python', 'durable-workflow'].includes(artifact)) {
    return 'sdk-python';
  }
  if (['server', 'durableworkflow/server'].includes(artifact)) {
    return 'server';
  }

  return artifact;
}

function scenarioEvidenceFinding(scenarioId, artifactVersions, failure) {
  const expected = stringValue(failure.expected) || 'Nexus scenario-specific evidence satisfies the contract result gate.';
  const observed = Object.hasOwn(failure, 'observed')
    ? ` Observed ${failure.field}=${JSON.stringify(failure.observed)}.`
    : '';

  return {
    scenario_id: scenarioId,
    type: failure.finding_type,
    finding_type: failure.finding_type,
    owning_surface: failure.owning_surface,
    artifact_versions: artifactVersions,
    observed_behavior: `${failure.code} for ${scenarioId}: ${failure.field}.${observed}`,
    expected_behavior: expected,
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell with ${failure.field} evidence satisfying the published result gate`,
  };
}

function missingEvidenceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'conformance_runner_coverage_gap',
    finding_type: 'conformance_runner_coverage_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: `Nexus conformance did not attach scenario-specific evidence for ${scenarioId}.`,
    expected_behavior: 'Nexus conformance records published-artifact evidence or a focused product finding for this scenario.',
    next_acceptance_criterion: `rerun Nexus conformance with concrete evidence for ${scenarioId}`,
  };
}

function missingScenarioResult(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    status: 'not_covered',
    observed_outputs: {
      covered: false,
    },
    linked_findings: [missingEvidenceFinding(scenarioId, artifactVersions)],
  };
}

function runnerBlockedReasonFrom(evidence) {
  return stringValue(evidence.blocked_reason)
    || stringValue(evidence.blockedReason)
    || stringValue(evidence.runner_blocked_reason)
    || stringValue(evidence.runnerBlockedReason)
    || 'Nexus host runner reported runner_blocked=true.';
}

function runnerBlockedIn(evidence) {
  return truthy(evidence.runner_blocked)
    || truthy(evidence.runnerBlocked)
    || stringValue(evidence.blocked_reason) !== ''
    || stringValue(evidence.blockedReason) !== ''
    || stringValue(evidence.runner_blocked_reason) !== ''
    || stringValue(evidence.runnerBlockedReason) !== '';
}

function runnerBlockedFinding(scenarioId, artifactVersions, reason) {
  return {
    scenario_id: scenarioId,
    type: 'runner_gap',
    finding_type: 'runner_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: reason,
    expected_behavior: 'Nexus conformance host execution reaches published artifact behavior before recording product evidence.',
    next_acceptance_criterion: `restore the Nexus host execution path and rerun ${scenarioId} with runner_blocked=false evidence`,
  };
}

function runnerBlockedScenarioResult(scenarioId, artifactVersions, reason) {
  const finding = runnerBlockedFinding(scenarioId, artifactVersions, reason);

  return {
    scenario_id: scenarioId,
    status: 'runner_blocked',
    observed_outputs: {
      blocked_reason: reason,
      evidence_runner_blocked: true,
    },
    linked_findings: [finding],
  };
}

function byScenarioId(items) {
  const indexed = new Map();
  if (Array.isArray(items)) {
    for (const item of items) {
      if (item && typeof item.scenario_id === 'string') {
        indexed.set(item.scenario_id, item);
      }
    }
    return indexed;
  }

  if (items && typeof items === 'object') {
    for (const [scenarioId, item] of Object.entries(items)) {
      if (item && typeof item === 'object') {
        indexed.set(scenarioId, {
          scenario_id: scenarioId,
          ...item,
        });
      }
    }
  }

  return indexed;
}

function mergeMaps(...maps) {
  const merged = {};
  for (const map of maps) {
    if (!map || typeof map !== 'object' || Array.isArray(map)) {
      continue;
    }

    for (const [key, value] of Object.entries(map)) {
      const existing = merged[key];
      const existingIsPopulated = hasNonEmptyObjectValue(existing) || stringValue(existing) !== '';
      if (!Object.hasOwn(merged, key) || !existingIsPopulated) {
        merged[key] = value;
      }
    }
  }

  return merged;
}

function normalizeArtifactMap(map) {
  const normalized = {...(map && typeof map === 'object' && !Array.isArray(map) ? map : {})};

  for (const artifact of requiredArtifacts) {
    if (stringValue(normalized[artifact]) !== '') {
      continue;
    }

    for (const alias of artifactAliases[artifact] || []) {
      if (stringValue(normalized[alias]) !== '') {
        normalized[artifact] = normalized[alias];
        break;
      }
    }
  }

  return normalized;
}

function normalizeArtifactEvidenceMap(map) {
  const normalized = {...(map && typeof map === 'object' && !Array.isArray(map) ? map : {})};

  for (const artifact of requiredArtifacts) {
    if (hasNonEmptyObjectValue(normalized[artifact])) {
      continue;
    }

    for (const alias of artifactAliases[artifact] || []) {
      if (hasNonEmptyObjectValue(normalized[alias])) {
        normalized[artifact] = normalized[alias];
        break;
      }
    }
  }

  return normalized;
}

function hasNonEmptyObjectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function artifactPolicyFailuresFor(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsExplicitlyFalse,
) {
  const failures = artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification);

  if (localProductSourceCheckoutsUsed) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'local_product_source_checkouts_used',
      code: 'local_product_source_checkout_used',
      value: true,
    });
  } else if (!localProductSourceCheckoutsExplicitlyFalse) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'local_product_source_checkouts_used',
      code: 'missing_explicit_source_free_evidence',
    });
  }

  return failures;
}

function artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification = {}, paths = {}) {
  const failures = [];
  const versionPath = stringValue(paths.artifactVersionsPath);
  const sourcePath = stringValue(paths.artifactSourcesPath);
  const verificationPath = stringValue(paths.artifactSourceVerificationPath);

  for (const artifact of requiredArtifacts) {
    const version = stringValue(artifactVersions[artifact]);
    const source = stringValue(artifactSources[artifact]);
    let versionPassesPolicy = false;
    let sourcePassesPolicy = false;

    if (version === '') {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'missing_published_artifact_version',
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else if (isPlaceholderArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'placeholder_published_artifact_version',
        value: version,
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else if (!isExactPublishedArtifactVersion(version, artifact)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'invalid_published_artifact_version',
        value: version,
        ...(versionPath !== '' ? {path: versionPath} : {}),
      });
    } else {
      versionPassesPolicy = true;
    }

    if (source === '') {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'missing_published_artifact_source',
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else if (containsForbiddenSourceToken(source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'forbidden_published_artifact_source',
        value: source,
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else if (!matchesPublishedArtifactSource(artifact, version, source)) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'invalid_published_artifact_source',
        value: source,
        ...(sourcePath !== '' ? {path: sourcePath} : {}),
      });
    } else {
      sourcePassesPolicy = true;
    }

    if (versionPassesPolicy && sourcePassesPolicy) {
      const verificationFailure = artifactSourceVerificationFailureFor(
        artifact,
        version,
        source,
        artifactSourceVerification[artifact],
        verificationPath,
      );
      if (verificationFailure !== null) {
        failures.push(verificationFailure);
      }
    }
  }

  return failures;
}

function artifactSourceVerificationFailureFor(artifact, version, source, verification, verificationPath) {
  const base = {
    artifact,
    field: 'artifact_source_verification',
    ...(verificationPath !== '' ? {path: verificationPath} : {}),
  };

  if (!hasNonEmptyObjectValue(verification)) {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_evidence',
    };
  }

  const sourceLookup = evidenceLookup(verification, [
    'source',
    'artifact_source',
    'artifactSource',
    'resolved_source',
    'resolvedSource',
  ]);
  const verifiedSource = stringValue(sourceLookup.value);
  if (verifiedSource === '') {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_source',
    };
  }
  if (verifiedSource !== source) {
    return {
      ...base,
      code: 'published_artifact_resolution_source_mismatch',
      value: verifiedSource,
      expected_value: source,
    };
  }

  const versionLookup = evidenceLookup(verification, [
    'version',
    'artifact_version',
    'artifactVersion',
    'resolved_version',
    'resolvedVersion',
  ]);
  const verifiedVersion = stringValue(versionLookup.value);
  if (verifiedVersion === '') {
    return {
      ...base,
      code: 'missing_published_artifact_resolution_version',
    };
  }
  if (!samePublishedArtifactVersion(artifact, version, verifiedVersion)) {
    return {
      ...base,
      code: 'published_artifact_resolution_version_mismatch',
      value: verifiedVersion,
      expected_value: version,
    };
  }

  if (!verificationConfirmsDownloadable(verification)) {
    return {
      ...base,
      code: 'unverified_downloadable_published_artifact_source',
      value: stringValue(verification.status),
    };
  }

  return null;
}

function verificationConfirmsDownloadable(verification) {
  for (const field of [
    'downloadable',
    'downloaded',
    'installable',
    'resolved',
    'exists',
    'published',
    'verified',
    'asset_exists',
    'assetExists',
    'package_exists',
    'packageExists',
    'manifest_resolved',
    'manifestResolved',
    'source_exists',
    'sourceExists',
  ]) {
    const lookup = evidenceLookup(verification, [field]);
    if (lookup.present && truthy(lookup.value)) {
      return true;
    }
  }

  return [
    'pass',
    'passed',
    'success',
    'successful',
    'resolved',
    'downloadable',
    'exists',
    'found',
    'verified',
    'installable',
    'asset_resolved',
    'package_resolved',
    'manifest_resolved',
  ].includes(stringValue(verification.status).toLowerCase());
}

function installScenarioArtifactPolicyFailuresFor(
  scenarioResults,
  expectedArtifactVersions,
  expectedArtifactSources,
) {
  const installScenario = scenarioResults.find((scenario) => (
    scenario.scenario_id === 'published_artifact_install_only'
  ));
  if (!installScenario || installScenario.status !== 'pass') {
    return [];
  }

  const observedOutputs = installScenario
    && installScenario.observed_outputs
    && typeof installScenario.observed_outputs === 'object'
    && !Array.isArray(installScenario.observed_outputs)
    ? installScenario.observed_outputs
    : {};
  const artifactVersions = normalizeArtifactMap(mergeMaps(
    observedOutputs.artifact_versions,
    observedOutputs.artifactVersions,
    observedOutputs.published_artifact_versions,
    observedOutputs.publishedArtifactVersions,
    observedOutputs.resolved_artifact_versions,
    observedOutputs.resolvedArtifactVersions,
  ));
  const artifactSources = normalizeArtifactMap(mergeMaps(
    observedOutputs.artifact_sources,
    observedOutputs.artifactSources,
    observedOutputs.install_sources,
    observedOutputs.installSources,
  ));
  const artifactSourceVerification = normalizeArtifactEvidenceMap(mergeMaps(
    observedOutputs.artifact_source_verification,
    observedOutputs.artifactSourceVerification,
    observedOutputs.published_artifact_source_verification,
    observedOutputs.publishedArtifactSourceVerification,
    observedOutputs.artifact_source_resolution,
    observedOutputs.artifactSourceResolution,
  ));
  const artifactInstallEvidence = installEvidenceFrom(observedOutputs);

  return [
    ...artifactMapPolicyFailuresFor(artifactVersions, artifactSources, artifactSourceVerification, {
      artifactVersionsPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions',
      artifactSourcesPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources',
      artifactSourceVerificationPath: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification',
    }),
    ...artifactInstallEvidencePolicyFailuresFor(
      artifactInstallEvidence,
      expectedArtifactVersions,
      expectedArtifactSources,
      '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
    ),
    ...artifactTupleMismatchFailuresFor(
      artifactVersions,
      artifactSources,
      artifactSourceVerification,
      expectedArtifactVersions,
      expectedArtifactSources,
    ),
  ];
}

function artifactTupleMismatchFailuresFor(
  observedArtifactVersions,
  observedArtifactSources,
  observedArtifactSourceVerification,
  expectedArtifactVersions,
  expectedArtifactSources,
) {
  const failures = [];
  const versionPath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_versions';
  const sourcePath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_sources';
  const verificationPath = '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_source_verification';

  for (const artifact of requiredArtifacts) {
    const observedVersion = stringValue(observedArtifactVersions[artifact]);
    const expectedVersion = stringValue(expectedArtifactVersions[artifact]);
    if (observedVersion !== ''
      && expectedVersion !== ''
      && !samePublishedArtifactVersion(artifact, expectedVersion, observedVersion)) {
      failures.push({
        artifact,
        field: 'artifact_versions',
        code: 'install_artifact_version_mismatch',
        value: observedVersion,
        expected_value: expectedVersion,
        path: versionPath,
      });
    }

    const observedSource = stringValue(observedArtifactSources[artifact]);
    const expectedSource = stringValue(expectedArtifactSources[artifact]);
    if (observedSource !== '' && expectedSource !== '' && observedSource !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_sources',
        code: 'install_artifact_source_mismatch',
        value: observedSource,
        expected_value: expectedSource,
        path: sourcePath,
      });
    }

    const observedVerification = observedArtifactSourceVerification[artifact];
    if (!hasNonEmptyObjectValue(observedVerification)) {
      continue;
    }

    const verifiedVersion = stringValue(evidenceLookup(observedVerification, [
      'version',
      'artifact_version',
      'artifactVersion',
      'resolved_version',
      'resolvedVersion',
    ]).value);
    if (verifiedVersion !== ''
      && expectedVersion !== ''
      && !samePublishedArtifactVersion(artifact, expectedVersion, verifiedVersion)) {
      failures.push({
        artifact,
        field: 'artifact_source_verification',
        code: 'install_artifact_source_verification_version_mismatch',
        value: verifiedVersion,
        expected_value: expectedVersion,
        path: verificationPath,
      });
    }

    const verifiedSource = stringValue(evidenceLookup(observedVerification, [
      'source',
      'artifact_source',
      'artifactSource',
      'resolved_source',
      'resolvedSource',
    ]).value);
    if (verifiedSource !== '' && expectedSource !== '' && verifiedSource !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_source_verification',
        code: 'install_artifact_source_verification_source_mismatch',
        value: verifiedSource,
        expected_value: expectedSource,
        path: verificationPath,
      });
    }
  }

  return failures;
}

function verifiedPublishedArtifactTupleCanProveInstallOnly(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
) {
  return artifactMapPolicyFailuresFor(
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
  ).length === 0;
}

function syntheticInstallEvidenceFromPublishedTuple(artifactVersions, artifactSources, artifactSourceVerification) {
  return {
    schema: 'durable-workflow.v2.nexus-runtime.install-evidence',
    published_install_tuple_proven: true,
    local_product_source_checkouts_used: false,
    synthesized_from_published_artifact_tuple: true,
    artifacts: requiredArtifacts.map((artifact) => ({
      artifact,
      version: artifactVersions[artifact],
      source: artifactSources[artifact],
      install_channel: installChannelForArtifact(artifact),
      source_verification: artifactSourceVerification[artifact],
      local_product_source_checkout_used_as_artifact: false,
      status: 'pass',
    })),
  };
}

function installChannelForArtifact(artifact) {
  switch (artifact) {
    case 'server':
      return 'docker';
    case 'cli':
      return 'github_release_asset';
    case 'workflow':
    case 'sdk-php':
    case 'waterline':
      return 'packagist';
    case 'sdk-python':
      return 'pypi';
    default:
      return 'published_artifact_channel';
  }
}

function applyResultGateFailures(
  scenario,
  artifactVersions,
  artifactPolicyFailures,
  localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsExplicitlyFalse,
) {
  const linkedFindings = Array.isArray(scenario.linked_findings) ? [...scenario.linked_findings] : [];
  const resultGateFindings = [];

  for (const failure of artifactPolicyFailures) {
    if (failure.field === 'local_product_source_checkouts_used') {
      continue;
    }
    resultGateFindings.push(artifactPolicyFinding(scenario.scenario_id, artifactVersions, failure));
  }

  if (localProductSourceCheckoutsUsed) {
    resultGateFindings.push(localProductSourceFinding(scenario.scenario_id, artifactVersions));
  }
  if (!localProductSourceCheckoutsUsed && !localProductSourceCheckoutsExplicitlyFalse) {
    resultGateFindings.push(missingSourceFreeEvidenceFinding(scenario.scenario_id, artifactVersions));
  }

  if (resultGateFindings.length === 0) {
    return scenario;
  }

  if (scenario.status !== 'pass') {
    return {
      ...scenario,
      observed_outputs: {
        ...(scenario.observed_outputs && typeof scenario.observed_outputs === 'object'
          ? scenario.observed_outputs
          : {}),
        result_gate_failed: true,
        artifact_policy_failures: artifactPolicyFailures,
        local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
      },
      linked_findings: [
        ...linkedFindings,
        ...resultGateFindings,
      ],
    };
  }

  return {
    ...scenario,
    status: 'fail',
    observed_outputs: {
      ...(scenario.observed_outputs && typeof scenario.observed_outputs === 'object'
        ? scenario.observed_outputs
        : {}),
      result_gate_failed: true,
      artifact_policy_failures: artifactPolicyFailures,
      local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
    },
    linked_findings: [
      ...linkedFindings,
      ...resultGateFindings,
    ],
  };
}

function withSyntheticInstallScenarioEvidence(
  scenarioResults,
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  artifactInstallEvidence,
  canSynthesize,
) {
  if (!canSynthesize) {
    return scenarioResults;
  }

  return scenarioResults.map((scenario) => {
    if (scenario.scenario_id !== 'published_artifact_install_only'
      || ['fail', 'unsupported', 'runner_blocked'].includes(scenario.status)) {
      return scenario;
    }

    const observedOutputs = syntheticInstallObservedOutputs(
      artifactVersions,
      artifactSources,
      artifactSourceVerification,
      localProductSourceCheckoutsUsed,
      artifactInstallEvidence,
      scenario.observed_outputs,
    );
    const evidenceFailures = scenarioEvidenceFailures(
      'published_artifact_install_only',
      observedOutputs,
      artifactVersions,
    );

    if (evidenceFailures.length > 0) {
      return {
        ...scenario,
        status: evidenceFailures.some((failure) => failure.result_status === 'fail')
          ? 'fail'
          : 'not_covered',
        observed_outputs: {
          ...observedOutputs,
          result_gate_failed: true,
          scenario_evidence_failures: evidenceFailures,
        },
        linked_findings: evidenceFailures.map((failure) => (
          scenarioEvidenceFinding('published_artifact_install_only', artifactVersions, failure)
        )),
      };
    }

    return {
      scenario_id: 'published_artifact_install_only',
      status: 'pass',
      observed_outputs: observedOutputs,
      linked_findings: [],
    };
  });
}

function syntheticInstallObservedOutputs(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  localProductSourceCheckoutsUsed,
  artifactInstallEvidence,
  existingOutputs = {},
) {
  const outputs = existingOutputs && typeof existingOutputs === 'object' && !Array.isArray(existingOutputs)
    ? {...existingOutputs}
    : {};

  if (!hasAnyOwn(outputs, ['artifact_versions', 'artifactVersions'])) {
    outputs.artifact_versions = artifactVersions;
  }
  if (!hasAnyOwn(outputs, ['resolved_artifact_versions', 'resolvedArtifactVersions'])) {
    outputs.resolved_artifact_versions = artifactVersions;
  }
  if (!hasAnyOwn(outputs, ['artifact_sources', 'artifactSources', 'install_sources', 'installSources'])) {
    outputs.artifact_sources = artifactSources;
  }
  if (!hasAnyOwn(outputs, [
    'artifact_source_verification',
    'artifactSourceVerification',
    'published_artifact_source_verification',
    'publishedArtifactSourceVerification',
    'artifact_source_resolution',
    'artifactSourceResolution',
  ])) {
    outputs.artifact_source_verification = artifactSourceVerification;
  }
  if (!hasAnyOwn(outputs, ['local_product_source_checkouts_used', 'localProductSourceCheckoutsUsed'])) {
    outputs.local_product_source_checkouts_used = localProductSourceCheckoutsUsed;
  }

  return withPromotedInstallEvidence(outputs, artifactInstallEvidence);
}

function hasAnyOwn(container, fields) {
  return fields.some((field) => Object.hasOwn(container, field));
}

function withResultRecordAndRoutingScenario(scenarioResults, artifactVersions) {
  const scenarios = new Map(scenarioResults.map((scenario) => [scenario.scenario_id, scenario]));
  const nonRoutingScenarios = requiredScenarios
    .filter((scenarioId) => scenarioId !== resultRoutingScenarioId)
    .map((scenarioId) => scenarios.get(scenarioId) || missingScenarioResult(scenarioId, artifactVersions));
  const routingScenario = resultRecordAndRoutingScenarioResult(nonRoutingScenarios, artifactVersions);

  return requiredScenarios.map((scenarioId) => (
    scenarioId === resultRoutingScenarioId
      ? routingScenario
      : (scenarios.get(scenarioId) || missingScenarioResult(scenarioId, artifactVersions))
  ));
}

function withDerivedCallerHistoryAttemptVisibility(scenarioResults, artifactVersions) {
  const scenarios = new Map(scenarioResults.map((scenario) => [scenario.scenario_id, scenario]));
  const callerHistoryScenario = scenarios.get('caller_history_attempt_visibility');
  const transientRetryScenario = scenarios.get('transient_failure_retries_with_policy');

  if (!callerHistoryScenario
    || callerHistoryScenario.status !== 'not_covered'
    || !transientRetryEvidenceWasAttempted(transientRetryScenario)) {
    return scenarioResults;
  }

  const observedOutputs = derivedCallerHistoryAttemptOutputs(transientRetryScenario.observed_outputs);
  const evidenceFailures = scenarioEvidenceFailures(
    'caller_history_attempt_visibility',
    observedOutputs,
    artifactVersions,
  );
  const status = evidenceFailures.length === 0
    ? 'pass'
    : (evidenceFailures.some((failure) => failure.result_status === 'fail') ? 'fail' : 'not_covered');
  const derivedScenario = {
    scenario_id: 'caller_history_attempt_visibility',
    status,
    observed_outputs: {
      ...observedOutputs,
      artifact_versions: artifactVersions,
      derived_from_scenario: 'transient_failure_retries_with_policy',
    },
    linked_findings: evidenceFailures.map((failure) => (
      scenarioEvidenceFinding('caller_history_attempt_visibility', artifactVersions, failure)
    )),
  };

  return scenarioResults.map((scenario) => (
    scenario.scenario_id === 'caller_history_attempt_visibility' ? derivedScenario : scenario
  ));
}

function transientRetryEvidenceWasAttempted(scenario) {
  if (!scenario || typeof scenario !== 'object' || Array.isArray(scenario)) {
    return false;
  }

  const outputs = scenario.observed_outputs && typeof scenario.observed_outputs === 'object' && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  if (stringValue(outputs.service_call_id ?? outputs.serviceCallId) !== '') {
    return true;
  }
  if (hasNonEmptyObjectValue(outputs.retry_policy ?? outputs.retryPolicy)
    || hasNonEmptyObjectValue(outputs.service_call_record ?? outputs.serviceCallRecord)
    || hasNonEmptyObjectValue(outputs.caller_history_evidence ?? outputs.callerHistoryEvidence)
    || hasNonEmptyObjectValue(outputs.probe_response ?? outputs.probeResponse)) {
    return true;
  }
  if (Object.hasOwn(outputs, 'history_attempt_visibility_includes_retry_attempts')
    || Object.hasOwn(outputs, 'historyAttemptVisibilityIncludesRetryAttempts')
    || Object.hasOwn(outputs, 'completed_after_retry')
    || Object.hasOwn(outputs, 'completedAfterRetry')) {
    return true;
  }

  return [
    outputs.retry_attempts,
    outputs.retryAttempts,
    outputs.history_attempts,
    outputs.historyAttempts,
    outputs.service_call_attempts,
    outputs.serviceCallAttempts,
    outputs.caller_history_attempts,
    outputs.callerHistoryAttempts,
    outputs.service_call_detail_attempts,
    outputs.serviceCallDetailAttempts,
  ].some((value) => Array.isArray(value) && value.length > 0);
}

function derivedCallerHistoryAttemptOutputs(retryOutputs) {
  const outputs = retryOutputs && typeof retryOutputs === 'object' && !Array.isArray(retryOutputs)
    ? retryOutputs
    : {};
  const callerHistoryAttempts = firstArray(
    outputs.caller_history_attempts,
    outputs.callerHistoryAttempts,
    outputs.history_attempts,
    outputs.historyAttempts,
  );
  const serviceCallDetailAttempts = firstArray(
    outputs.service_call_detail_attempts,
    outputs.serviceCallDetailAttempts,
    outputs.service_call_attempts,
    outputs.serviceCallAttempts,
    outputs.retry_attempts,
    outputs.retryAttempts,
  );

  return {
    service_call_id: stringValue(outputs.service_call_id ?? outputs.serviceCallId),
    caller_workflow_instance_id: stringValue(outputs.caller_workflow_instance_id ?? outputs.callerWorkflowInstanceId),
    caller_workflow_run_id: stringValue(outputs.caller_workflow_run_id ?? outputs.callerWorkflowRunId),
    service_workflow_instance_id: stringValue(outputs.service_workflow_instance_id ?? outputs.serviceWorkflowInstanceId),
    service_workflow_run_id: stringValue(outputs.service_workflow_run_id ?? outputs.serviceWorkflowRunId),
    retry_policy: outputs.retry_policy ?? outputs.retryPolicy ?? {},
    caller_history_attempts: callerHistoryAttempts,
    history_attempt_visibility_includes_retry_attempts: outputs.history_attempt_visibility_includes_retry_attempts
      ?? outputs.historyAttemptVisibilityIncludesRetryAttempts
      ?? false,
    service_call_detail_attempts: serviceCallDetailAttempts,
    final_successful_result: outputs.final_successful_result ?? outputs.finalSuccessfulResult ?? null,
    final_failure: outputs.final_failure ?? outputs.finalFailure ?? null,
    caller_history_rows: firstArray(outputs.caller_history_rows, outputs.callerHistoryRows),
    service_call_record: outputs.service_call_record ?? outputs.serviceCallRecord ?? null,
    artifact_tuple: outputs.artifact_tuple ?? outputs.artifactTuple ?? null,
  };
}

function firstArray(...values) {
  return values.find((value) => Array.isArray(value)) || [];
}

function resultRecordAndRoutingScenarioResult(nonRoutingScenarios, artifactVersions) {
  const scenarioStatuses = {};
  const statusCounts = {};
  const nonPassRoutes = {};
  const unroutedNonPassScenarios = [];

  for (const scenario of nonRoutingScenarios) {
    const scenarioId = scenario.scenario_id;
    const status = allowedStatuses.has(scenario.status) ? scenario.status : 'not_covered';
    scenarioStatuses[scenarioId] = status;
    statusCounts[status] = (statusCounts[status] || 0) + 1;

    if (!routedNonPassStatuses.has(status)) {
      continue;
    }

    const linkedFindings = Array.isArray(scenario.linked_findings) ? scenario.linked_findings : [];
    const focusedFindings = linkedFindings.filter((finding) => isFocusedScenarioFinding(scenarioId, finding));
    const routed = focusedFindings.length > 0;
    nonPassRoutes[scenarioId] = {
      status,
      routed,
      finding_count: linkedFindings.length,
      focused_finding_count: focusedFindings.length,
      finding_types: linkedFindings.map((finding) => stringValue(finding.finding_type) || stringValue(finding.type)),
      owning_surfaces: linkedFindings.map((finding) => stringValue(finding.owning_surface)),
    };

    if (!routed) {
      unroutedNonPassScenarios.push(scenarioId);
    }
  }

  const status = unroutedNonPassScenarios.length === 0 ? 'pass' : 'fail';
  scenarioStatuses[resultRoutingScenarioId] = status;
  statusCounts[status] = (statusCounts[status] || 0) + 1;
  const observedOutputs = {
    result_record_emitted: true,
    finding_links_emitted: true,
    waterline_operator_visibility: true,
    required_scenarios_recorded: requiredScenarios,
    required_statuses: [...allowedStatuses],
    non_pass_statuses: [...routedNonPassStatuses],
    scenario_statuses: scenarioStatuses,
    status_counts: statusCounts,
    non_pass_routes: nonPassRoutes,
    non_pass_findings_routed: unroutedNonPassScenarios.length === 0,
    unrouted_non_pass_scenarios: unroutedNonPassScenarios,
  };
  const linkedFindings = status === 'pass'
    ? []
    : [resultRecordRoutingFinding(artifactVersions, unroutedNonPassScenarios)];

  return {
    scenario_id: resultRoutingScenarioId,
    status,
    observed_outputs: observedOutputs,
    linked_findings: linkedFindings,
  };
}

function isFocusedScenarioFinding(scenarioId, finding) {
  if (!finding || typeof finding !== 'object' || Array.isArray(finding)) {
    return false;
  }

  return stringValue(finding.scenario_id) === scenarioId
    && (stringValue(finding.finding_type) !== '' || stringValue(finding.type) !== '')
    && stringValue(finding.owning_surface) !== ''
    && stringValue(finding.observed_behavior) !== ''
    && stringValue(finding.expected_behavior) !== ''
    && stringValue(finding.next_acceptance_criterion) !== '';
}

function resultRecordRoutingFinding(artifactVersions, unroutedNonPassScenarios) {
  return {
    scenario_id: resultRoutingScenarioId,
    type: 'nexus_result_record_routing_gap',
    finding_type: 'nexus_result_record_routing_gap',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: `Nexus result record left non-pass scenario(s) without focused findings: ${unroutedNonPassScenarios.join(', ')}.`,
    expected_behavior: 'Every fail, unsupported, not_covered, or runner_blocked Nexus scenario is routed to a focused finding with scenario id, owner, observed behavior, expected behavior, and next acceptance criterion.',
    next_acceptance_criterion: 'rerun Nexus conformance with focused linked findings for every non-pass scenario cell',
  };
}

function artifactPolicyFinding(scenarioId, artifactVersions, failure) {
  const artifact = stringValue(failure.artifact);
  const field = stringValue(failure.field);
  const code = stringValue(failure.code);
  const value = stringValue(failure.value);
  const valueDetail = value === '' ? '' : `; observed ${field}=${value}`;
  const path = stringValue(failure.path);
  const pathDetail = path === '' ? '' : ` at ${path}`;
  const nextCriterion = field.startsWith('artifact_install_evidence')
    ? `record passing published install evidence for ${artifact}, then rerun the ${scenarioId} Nexus cell`
    : (field === 'artifact_sources'
    ? `record a published install source for ${artifact}, then rerun the ${scenarioId} Nexus cell`
    : (field === 'artifact_source_verification'
      ? `record host proof that ${artifact} source resolves to a downloadable published artifact, then rerun the ${scenarioId} Nexus cell`
      : `publish or record a concrete ${artifact} artifact version, then rerun the ${scenarioId} Nexus cell`));

  return {
    scenario_id: scenarioId,
    type: 'missing_or_invalid_published_nexus_artifact',
    finding_type: 'missing_or_invalid_published_nexus_artifact',
    owning_surface: artifactOwners[artifact] || 'conformance_harness',
    artifact,
    artifact_versions: artifactVersions,
    observed_behavior: `Required Nexus artifact ${artifact} has ${code} in ${field}${pathDetail}${valueDetail}.`,
    expected_behavior: 'Nexus conformance starts from exact pinned published artifact versions and published install sources, without rolling tags, placeholder versions, non-version artifact references, or local source checkout paths.',
    next_acceptance_criterion: nextCriterion,
  };
}

function localProductSourceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'local_product_source_checkout_used',
    finding_type: 'local_product_source_checkout_used',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'Nexus evidence reported local_product_source_checkouts_used=true.',
    expected_behavior: 'Nexus conformance uses only pinned published artifacts as the product under test.',
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell without local product source checkouts`,
  };
}

function missingSourceFreeEvidenceFinding(scenarioId, artifactVersions) {
  return {
    scenario_id: scenarioId,
    type: 'missing_explicit_source_free_published_artifact_evidence',
    finding_type: 'missing_explicit_source_free_published_artifact_evidence',
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    observed_behavior: 'Nexus evidence omitted local_product_source_checkouts_used=false.',
    expected_behavior: 'Nexus conformance evidence explicitly states that no local product source checkout was used as an artifact under test.',
    next_acceptance_criterion: `rerun the ${scenarioId} Nexus cell with local_product_source_checkouts_used=false in the host evidence`,
  };
}

function isPlaceholderArtifactVersion(version) {
  const normalized = version.toLowerCase();

  return placeholderVersionTokens.some((token) => normalized.includes(token.toLowerCase()))
    || /(^|[^a-z0-9])v?\d+(?:\.\d+)*\.x([^a-z0-9]|$)/i.test(normalized);
}

function isExactSemverRelease(version) {
  return /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/.test(version.trim());
}

function pythonReleaseIdentity(version) {
  const normalized = version.trim();
  if (isExactSemverRelease(normalized) && !normalized.includes('-')) return normalized;
  const semver = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)-(alpha|beta|rc)\.(0|[1-9]\d*)$/i.exec(normalized);
  if (semver) {
    const phase = semver[4].toLowerCase() === 'alpha' ? 'a' : (semver[4].toLowerCase() === 'beta' ? 'b' : 'rc');
    return `${semver[1]}.${semver[2]}.${semver[3]}${phase}${semver[5]}`;
  }
  const pep440 = /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(a|b|rc)(0|[1-9]\d*)$/i.exec(normalized);
  return pep440
    ? `${pep440[1]}.${pep440[2]}.${pep440[3]}${pep440[4].toLowerCase()}${pep440[5]}`
    : null;
}

function isExactPublishedArtifactVersion(version, artifact = '') {
  return artifact === 'sdk-python'
    ? pythonReleaseIdentity(version) !== null
    : isExactSemverRelease(version);
}

function samePublishedArtifactVersion(artifact, expected, observed) {
  if (artifact !== 'sdk-python') return expected === observed;
  const expectedIdentity = pythonReleaseIdentity(expected);
  return expectedIdentity !== null && expectedIdentity === pythonReleaseIdentity(observed);
}

function containsForbiddenSourceToken(source) {
  const normalized = source.toLowerCase().trim();
  const decoded = decodeSourceText(normalized);

  return [normalized, decoded].some((candidate) => (
    forbiddenSourceTokens.some((token) => candidate.includes(token.toLowerCase()))
      || isRollingArtifactSourceRef(candidate)
      || isLocalArtifactSourcePath(candidate)
  ));
}

function matchesPublishedArtifactSource(artifact, version, source) {
  if (version === '') {
    return false;
  }

  const trimmed = source.trim();

  switch (artifact) {
    case 'server':
      return matchesServerArtifactSource(version, trimmed);
    case 'cli':
      return matchesCliArtifactSource(version, trimmed);
    case 'workflow':
      return matchesComposerArtifactSource('durable-workflow/workflow', version, trimmed);
    case 'sdk-php':
      return matchesComposerArtifactSource('durable-workflow/sdk', version, trimmed);
    case 'sdk-python':
      return matchesPythonArtifactSource(version, trimmed);
    case 'waterline':
      return matchesComposerArtifactSource('durable-workflow/waterline', version, trimmed);
    default:
      return false;
  }
}

function matchesServerArtifactSource(version, source) {
  if (/^docker:\/\/durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(source)
    || /^durableworkflow\/server@sha256:[0-9a-f]{64}$/i.test(source)
    || new RegExp(`^docker://durableworkflow/server:${escapeRegExp(version)}@sha256:[0-9a-f]{64}$`, 'i').test(source)
    || new RegExp(`^durableworkflow/server:${escapeRegExp(version)}@sha256:[0-9a-f]{64}$`, 'i').test(source)) {
    return true;
  }

  return source === `docker://durableworkflow/server:${version}`
    || source === `durableworkflow/server:${version}`;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function matchesCliArtifactSource(version, source) {
  const prefix = `https://github.com/durable-workflow/cli/releases/download/${version}/`;
  if (!source.startsWith(prefix)) {
    return false;
  }

  return cliReleaseAssetNames.has(source.slice(prefix.length));
}

function matchesComposerArtifactSource(packageName, version, source) {
  return source === `packagist://${packageName}@${version}`
    || source === `composer://${packageName}:${version}`
    || source === `https://repo.packagist.org/p2/${packageName}.json#${version}`;
}

function matchesPythonArtifactSource(version, source) {
  const expectedIdentity = pythonReleaseIdentity(version);
  if (expectedIdentity === null) {
    return false;
  }

  const exactSourcePatterns = [
    /^pypi:\/\/durable-workflow==(?<version>[^/?#]+)$/,
    /^https:\/\/pypi\.org\/project\/durable-workflow\/(?<version>[^/?#]+)\/$/,
  ];
  for (const pattern of exactSourcePatterns) {
    const sourceVersion = pattern.exec(source)?.groups?.version;
    if (sourceVersion !== undefined) {
      return pythonReleaseIdentity(sourceVersion) === expectedIdentity;
    }
  }

  const distributionSource = /^(?:https:\/\/files\.pythonhosted\.org\/|https:\/\/pypi\.io\/packages\/)(?:[^/?#]+\/)*durable[_-]workflow-(?<distribution>[^/?#]+)$/.exec(source);
  const distribution = distributionSource?.groups?.distribution;
  if (distribution === undefined) {
    return false;
  }

  const sourceVersion = pythonDistributionVersion(distribution);
  return sourceVersion !== null
    && pythonReleaseIdentity(sourceVersion) === expectedIdentity;
}

function pythonDistributionVersion(distribution) {
  const wheel = /^(?<version>.+?)(?:-\d[0-9A-Za-z_]*)?-[0-9A-Za-z_.]+-[0-9A-Za-z_.]+-[0-9A-Za-z_.]+\.whl$/.exec(distribution);
  if (wheel?.groups?.version !== undefined) {
    return wheel.groups.version;
  }

  const sdist = /^(?<version>.+)\.(?:tar\.gz|tar\.bz2|tar\.xz|zip)$/.exec(distribution);
  return sdist?.groups?.version ?? null;
}

function isRollingArtifactSourceRef(source) {
  return rollingSourceRefPattern.test(source);
}

function decodeSourceText(source) {
  try {
    return decodeURIComponent(source);
  } catch {
    return source;
  }
}

function isLocalArtifactSourcePath(source) {
  const pathText = source.replace(/\\/g, '/').trim();

  return pathText.startsWith('file:')
    || /^local(?::|\/|$)/.test(pathText)
    || /^~(?:[^/]*)?(?:\/|$)/.test(pathText)
    || /^\$(?:home|userprofile)(?:\/|$)/.test(pathText)
    || /^\$\{(?:home|userprofile)\}(?:\/|$)/.test(pathText)
    || /^%(?:home|userprofile|homedrive|homepath)%/.test(pathText)
    || /^\/[^/]+/.test(pathText)
    || /^[a-z]:\//.test(pathText)
    || /^\.\.?(?:\/|$)/.test(pathText)
    || /(^|[^a-z0-9])\/?workspace\/repos\//.test(pathText)
    || /^repos\/(?:server|workflow|waterline|cli|cloud|sample-app|sdk-python|durable-workflow\.github\.io)(?:\/|$)/.test(pathText);
}

function localProductSourceCheckoutsUsedIn(...containers) {
  return localProductSourceFlagValues(...containers).some((value) => truthy(value));
}

function localProductSourceFlagValues(...containers) {
  const values = [];
  for (const container of containers) {
    collectLocalProductSourceFlagValues(container, values);
  }
  return values;
}

function collectLocalProductSourceFlagValues(value, values) {
  if (!value || typeof value !== 'object') {
    return;
  }

  if (Array.isArray(value)) {
    for (const entry of value) {
      collectLocalProductSourceFlagValues(entry, values);
    }
    return;
  }

  if (Object.hasOwn(value, 'local_product_source_checkouts_used')) {
    values.push(value.local_product_source_checkouts_used);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutsUsed')) {
    values.push(value.localProductSourceCheckoutsUsed);
  }
  if (Object.hasOwn(value, 'local_product_source_checkout_used_as_artifact')) {
    values.push(value.local_product_source_checkout_used_as_artifact);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutUsedAsArtifact')) {
    values.push(value.localProductSourceCheckoutUsedAsArtifact);
  }

  for (const entry of Object.values(value)) {
    if (entry && typeof entry === 'object') {
      collectLocalProductSourceFlagValues(entry, values);
    }
  }
}

function hasExplicitFalseLocalProductSourceFlag(container) {
  if (!container || typeof container !== 'object' || Array.isArray(container)) {
    return false;
  }

  return explicitFalse(container.local_product_source_checkouts_used)
    || explicitFalse(container.localProductSourceCheckoutsUsed);
}

function installEvidenceFrom(container) {
  if (!container || typeof container !== 'object' || Array.isArray(container)) {
    return null;
  }

  for (const field of [
    'artifact_install_evidence',
    'artifactInstallEvidence',
    'install_evidence',
    'installEvidence',
  ]) {
    if (container[field] && typeof container[field] === 'object' && !Array.isArray(container[field])) {
      return container[field];
    }
  }

  if (looksLikeInstallEvidence(container)) {
    return container;
  }

  return null;
}

function installEvidenceFromScenarioInputs(scenarioInputs) {
  const scenario = scenarioInputs instanceof Map
    ? scenarioInputs.get('published_artifact_install_only')
    : null;
  const outputs = scenario
    && scenario.observed_outputs
    && typeof scenario.observed_outputs === 'object'
    && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  return installEvidenceFrom(outputs);
}

function installEvidenceFromScenarioResults(scenarioResults) {
  const scenario = scenarioResults.find((item) => item.scenario_id === 'published_artifact_install_only');
  const outputs = scenario
    && scenario.observed_outputs
    && typeof scenario.observed_outputs === 'object'
    && !Array.isArray(scenario.observed_outputs)
    ? scenario.observed_outputs
    : {};

  return installEvidenceFrom(outputs);
}

function looksLikeInstallEvidence(value) {
  return value && typeof value === 'object' && !Array.isArray(value)
    && (
      Array.isArray(value.artifacts)
      || (value.artifacts && typeof value.artifacts === 'object')
      || stringValue(value.schema).includes('install-evidence')
    );
}

function installEvidenceArtifactsAllPass(installEvidence) {
  const artifacts = installEvidence && installEvidence.artifacts;
  if (Array.isArray(artifacts)) {
    return artifacts.length > 0 && artifacts.every((entry) => (
      stringValue(entry && (entry.status ?? entry.result ?? entry.outcome)).toLowerCase() === 'pass'
    ));
  }
  if (artifacts && typeof artifacts === 'object') {
    const entries = Object.values(artifacts);
    return entries.length > 0 && entries.every((entry) => (
      stringValue(entry && (entry.status ?? entry.result ?? entry.outcome)).toLowerCase() === 'pass'
    ));
  }

  return false;
}

function selectArtifactInstallEvidence(candidates, artifactVersions, artifactSources) {
  const supplied = candidates.filter((candidate) => (
    candidate.evidence && typeof candidate.evidence === 'object' && !Array.isArray(candidate.evidence)
  ));
  if (supplied.length === 0) {
    return {
      evidence: null,
      failures: artifactInstallEvidencePolicyFailuresFor(
        null,
        artifactVersions,
        artifactSources,
        '$.artifact_install_evidence',
      ),
    };
  }

  let firstFailures = null;
  for (const candidate of supplied) {
    const failures = artifactInstallEvidencePolicyFailuresFor(
      candidate.evidence,
      artifactVersions,
      artifactSources,
      candidate.path,
    );
    if (failures.length === 0) {
      const suppliedInstallEvidence = candidate.supplied !== false && candidate.derived !== true;
      return {
        evidence: {
          ...candidate.evidence,
          supplied_install_evidence: suppliedInstallEvidence,
          derived_install_evidence: !suppliedInstallEvidence,
          install_evidence_source: candidate.source,
          ...(suppliedInstallEvidence ? {supplied_install_evidence_source: candidate.source} : {}),
          ...(!suppliedInstallEvidence ? {derived_install_evidence_source: candidate.source} : {}),
          ...(suppliedInstallEvidence && candidate.filePath ? {supplied_install_evidence_path: candidate.filePath} : {}),
        },
        failures: [],
      };
    }
    if (firstFailures === null) {
      firstFailures = failures;
    }
  }

  return {
    evidence: supplied[0].evidence,
    failures: firstFailures || [],
  };
}

function artifactInstallEvidencePolicyFailuresFor(
  installEvidence,
  artifactVersions,
  artifactSources,
  pathPrefix,
) {
  const failures = [];
  if (!installEvidence || typeof installEvidence !== 'object' || Array.isArray(installEvidence)) {
    return [{
      artifact: 'product-artifacts',
      field: 'artifact_install_evidence',
      code: 'missing_published_artifact_install_evidence',
      path: pathPrefix,
    }];
  }

  if (!hasExplicitFalseLocalProductSourceFlag(installEvidence)) {
    failures.push({
      artifact: 'product-artifacts',
      field: 'artifact_install_evidence.local_product_source_checkouts_used',
      code: 'local_product_source_checkouts_used_must_be_false',
      value: installEvidence.local_product_source_checkouts_used
        ?? installEvidence.localProductSourceCheckoutsUsed
        ?? null,
      path: pathPrefix,
    });
  }

  for (const artifact of requiredArtifacts) {
    const entry = artifactInstallEntry(installEvidence, artifact);
    if (entry === null) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts',
        code: 'missing_published_artifact_install_evidence_artifact',
        path: `${pathPrefix}.artifacts`,
      });
      continue;
    }

    const status = stringValue(entry.status ?? entry.result ?? entry.outcome).toLowerCase();
    if (status !== 'pass') {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.status',
        code: 'published_artifact_install_evidence_not_pass',
        value: status,
        path: `${pathPrefix}.artifacts`,
      });
    }

    const version = stringValue(evidenceLookup(entry, [
      'version',
      'resolved_version',
      'resolvedVersion',
      'artifact_version',
      'artifactVersion',
    ]).value);
    const expectedVersion = stringValue(artifactVersions[artifact]);
    if (version === '') {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'missing_published_artifact_install_evidence_version',
        path: `${pathPrefix}.artifacts`,
      });
    } else if (isPlaceholderArtifactVersion(version)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'placeholder_published_artifact_install_evidence_version',
        value: version,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (!isExactPublishedArtifactVersion(version, artifact)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'invalid_published_artifact_install_evidence_version',
        value: version,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (expectedVersion !== '' && !samePublishedArtifactVersion(artifact, expectedVersion, version)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.version',
        code: 'published_artifact_install_evidence_version_mismatch',
        value: version,
        expected_value: expectedVersion,
        path: `${pathPrefix}.artifacts`,
      });
    }

    const source = stringValue(evidenceLookup(entry, [
      'source',
      'install_source',
      'installSource',
      'artifact_source',
      'artifactSource',
    ]).value);
    const expectedSource = stringValue(artifactSources[artifact]);
    if (source === '') {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'missing_published_artifact_install_evidence_source',
        path: `${pathPrefix}.artifacts`,
      });
    } else if (containsForbiddenSourceToken(source)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'forbidden_published_artifact_install_evidence_source',
        value: source,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (!matchesPublishedArtifactSource(artifact, version, source)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'invalid_published_artifact_install_evidence_source',
        value: source,
        path: `${pathPrefix}.artifacts`,
      });
    } else if (expectedSource !== '' && source !== expectedSource) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.source',
        code: 'published_artifact_install_evidence_source_mismatch',
        value: source,
        expected_value: expectedSource,
        path: `${pathPrefix}.artifacts`,
      });
    }

    if (localProductSourceCheckoutsUsedIn(entry)) {
      failures.push({
        artifact,
        field: 'artifact_install_evidence.artifacts.local_product_source_checkouts_used',
        code: 'local_product_source_checkouts_used_must_be_false',
        value: true,
        path: `${pathPrefix}.artifacts`,
      });
    }
  }

  return failures;
}

function artifactInstallEntry(installEvidence, artifact) {
  const artifacts = installEvidence && installEvidence.artifacts;
  const names = [artifact, ...(artifactAliases[artifact] || [])];

  if (Array.isArray(artifacts)) {
    for (const entry of artifacts) {
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        continue;
      }
      const entryName = stringValue(entry.artifact ?? entry.name ?? entry.id ?? entry.package);
      if (names.includes(entryName)) {
        return entry;
      }
    }
    return null;
  }

  if (artifacts && typeof artifacts === 'object') {
    for (const name of names) {
      if (artifacts[name] && typeof artifacts[name] === 'object' && !Array.isArray(artifacts[name])) {
        return {
          artifact: name,
          ...artifacts[name],
        };
      }
    }
  }

  return null;
}

function truthy(value) {
  if (value === true) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['1', 'true', 'yes'].includes(text);
}

function explicitFalse(value) {
  if (value === false) {
    return true;
  }
  const text = stringValue(value).toLowerCase();
  return ['0', 'false', 'no'].includes(text);
}

function stringValue(value) {
  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
    ? String(value).trim()
    : '';
}

function numberValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }

  const text = stringValue(value);
  if (text === '') {
    return null;
  }

  const number = Number(text);

  return Number.isFinite(number) ? number : null;
}

fs.mkdirSync(resultDir, {recursive: true});

const startedAt = timestamp();
const evidence = readEvidence(evidencePath);
const dedicatedInstallEvidenceResult = readDedicatedInstallEvidence(dedicatedInstallEvidencePath);
const finishedAt = timestamp();
const topLevelInstallEvidence = installEvidenceFrom(evidence);
const rawScenarioInputs = byScenarioId(evidence.scenario_results);
const scenarioInputInstallEvidence = installEvidenceFromScenarioInputs(rawScenarioInputs);
const promotionInstallEvidence = dedicatedInstallEvidenceResult.installEvidence
  || topLevelInstallEvidence
  || null;
const hasSuppliedInstallEvidence = dedicatedInstallEvidenceResult.installEvidence !== null
  || topLevelInstallEvidence !== null
  || scenarioInputInstallEvidence !== null;
const artifactVersions = normalizeArtifactMap(mergeMaps(
  artifactVersionsFromEnv(),
  evidence.artifact_versions,
  evidence.artifactVersions,
  evidence.published_artifact_versions,
  evidence.publishedArtifactVersions,
  evidence.resolved_artifact_versions,
  evidence.resolvedArtifactVersions,
));
const artifactSources = normalizeArtifactMap(mergeMaps(
  artifactSourcesFromEnv(),
  evidence.artifact_sources,
  evidence.artifactSources,
  evidence.install_sources,
  evidence.installSources,
));
const artifactSourceVerification = normalizeArtifactEvidenceMap(mergeMaps(
  evidence.artifact_source_verification,
  evidence.artifactSourceVerification,
  evidence.published_artifact_source_verification,
  evidence.publishedArtifactSourceVerification,
  evidence.artifact_source_resolution,
  evidence.artifactSourceResolution,
));
const conformanceRunId = conformanceRunIdFrom(evidence);

const runnerBlocked = runnerBlockedIn(evidence);
const runnerBlockedReason = runnerBlockedReasonFrom(evidence);
let scenarioResults = runnerBlocked
  ? requiredScenarios.map((scenarioId) => (
    runnerBlockedScenarioResult(scenarioId, artifactVersions, runnerBlockedReason)
  ))
  : requiredScenarios.map((scenarioId) => (
    normalizeScenarioResult(scenarioId, rawScenarioInputs.get(scenarioId), artifactVersions, promotionInstallEvidence)
  ));
const localProductSourceCheckoutsUsed = localProductSourceCheckoutsUsedIn(
  evidence,
  scenarioResults,
  dedicatedInstallEvidenceResult.installEvidence,
);
const canSynthesizeInstallOnlyFromPublishedTuple = !runnerBlocked
  && !localProductSourceCheckoutsUsed
  && verifiedPublishedArtifactTupleCanProveInstallOnly(
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
  );
const synthesizedInstallEvidence = canSynthesizeInstallOnlyFromPublishedTuple
  && !hasSuppliedInstallEvidence
  ? syntheticInstallEvidenceFromPublishedTuple(
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
  )
  : null;
const localProductSourceCheckoutsExplicitlyFalse = hasExplicitFalseLocalProductSourceFlag(evidence)
  || hasExplicitFalseLocalProductSourceFlag(dedicatedInstallEvidenceResult.installEvidence)
  || hasExplicitFalseLocalProductSourceFlag(topLevelInstallEvidence)
  || hasExplicitFalseLocalProductSourceFlag(scenarioInputInstallEvidence);
const topLevelArtifactPolicyFailures = runnerBlocked
  ? []
  : artifactPolicyFailuresFor(
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
    localProductSourceCheckoutsUsed,
    localProductSourceCheckoutsExplicitlyFalse,
  );
const scenarioResultInstallEvidence = installEvidenceFromScenarioResults(scenarioResults);
let artifactInstallEvidenceSelection = runnerBlocked
  ? {evidence: null, failures: []}
  : selectArtifactInstallEvidence([
    {
      evidence: dedicatedInstallEvidenceResult.installEvidence,
      source: 'DW_NEXUS_ARTIFACT_INSTALL_EVIDENCE',
      filePath: dedicatedInstallEvidencePath || null,
      path: '$.artifact_install_evidence',
    },
    {
      evidence: topLevelInstallEvidence,
      source: 'DW_NEXUS_EVIDENCE_JSON.artifact_install_evidence',
      path: '$.artifact_install_evidence',
    },
    {
      evidence: scenarioInputInstallEvidence || scenarioResultInstallEvidence,
      source: 'scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
      path: '$.scenario_results.published_artifact_install_only.observed_outputs.artifact_install_evidence',
    },
    {
      evidence: synthesizedInstallEvidence,
      source: 'published_artifact_tuple',
      supplied: false,
      derived: true,
      path: '$.artifact_install_evidence',
    },
  ], artifactVersions, artifactSources);
const artifactInstallEvidence = artifactInstallEvidenceSelection.evidence;
const artifactInstallEvidencePolicyFailures = artifactInstallEvidenceSelection.failures;
if (!runnerBlocked) {
  scenarioResults = withSyntheticInstallScenarioEvidence(
    scenarioResults,
    artifactVersions,
    artifactSources,
    artifactSourceVerification,
    localProductSourceCheckoutsUsed,
    artifactInstallEvidence,
    topLevelArtifactPolicyFailures.length === 0
      && artifactInstallEvidencePolicyFailures.length === 0
      && artifactInstallEvidence !== null
      && localProductSourceCheckoutsUsed === false,
  );
  scenarioResults = withDerivedCallerHistoryAttemptVisibility(scenarioResults, artifactVersions);
}
const installScenarioArtifactPolicyFailures = runnerBlocked
  ? []
  : installScenarioArtifactPolicyFailuresFor(
    scenarioResults,
    artifactVersions,
    artifactSources,
  );
const artifactPolicyFailures = [
  ...topLevelArtifactPolicyFailures,
  ...artifactInstallEvidencePolicyFailures,
  ...installScenarioArtifactPolicyFailures,
];
if (!runnerBlocked) {
  scenarioResults = scenarioResults.map((scenario) => (
    applyResultGateFailures(
      scenario,
      artifactVersions,
      [
        ...topLevelArtifactPolicyFailures,
        ...artifactInstallEvidencePolicyFailures,
        ...(scenario.scenario_id === 'published_artifact_install_only'
          ? installScenarioArtifactPolicyFailures
          : []),
      ],
      localProductSourceCheckoutsUsed,
      localProductSourceCheckoutsExplicitlyFalse,
    )
  ));
  scenarioResults = withResultRecordAndRoutingScenario(scenarioResults, artifactVersions);
}

const findings = [];
for (const finding of Array.isArray(evidence.findings) ? evidence.findings : []) {
  findings.push(finding);
}
for (const finding of dedicatedInstallEvidenceResult.findings) {
  findings.push(finding);
}
for (const scenario of scenarioResults) {
  for (const finding of scenario.linked_findings) {
    findings.push(finding);
  }
}

const allPass = scenarioResults.every((scenario) => scenario.status === 'pass');
const resultGatePasses = !runnerBlocked
  && allPass
  && artifactPolicyFailures.length === 0
  && localProductSourceCheckoutsUsed === false;
const outcome = runnerBlocked ? 'non_passing_runner_blocked' : (resultGatePasses ? 'pass' : 'fail');
const findingLinks = {};
for (const scenario of scenarioResults) {
  findingLinks[scenario.scenario_id] = scenario.linked_findings;
}
const scenarioStatuses = {};
const nonPassScenarios = [];
for (const scenario of scenarioResults) {
  scenarioStatuses[scenario.scenario_id] = scenario.status;
  if (scenario.status !== 'pass') {
    nonPassScenarios.push(scenario.scenario_id);
  }
}
const routingScenario = scenarioResults.find((scenario) => (
  scenario.scenario_id === resultRoutingScenarioId
));
const routingObservedOutputs = routingScenario
  && routingScenario.observed_outputs
  && typeof routingScenario.observed_outputs === 'object'
  && !Array.isArray(routingScenario.observed_outputs)
  ? routingScenario.observed_outputs
  : {};
const nonPassRoutes = routingObservedOutputs.non_pass_routes
  && typeof routingObservedOutputs.non_pass_routes === 'object'
  && !Array.isArray(routingObservedOutputs.non_pass_routes)
  ? routingObservedOutputs.non_pass_routes
  : {};
const unroutedNonPassScenarios = Array.isArray(routingObservedOutputs.unrouted_non_pass_scenarios)
  ? routingObservedOutputs.unrouted_non_pass_scenarios
  : [];

const pins = {
  schema: 'durable-workflow.v2.nexus-runtime.pins',
  generated_at: finishedAt,
  artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  artifact_source_verification: artifactSourceVerification,
  artifact_install_evidence: artifactInstallEvidence,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  evidence_path: evidencePath || null,
  ...(conformanceRunId !== null ? {conformance_run_id: conformanceRunId} : {}),
};

const result = {
  schema: 'durable-workflow.v2.nexus-runtime.result',
  schema_version: 1,
  suite_schema: 'durable-workflow.v2.platform-conformance.suite',
  category: 'nexus_runtime_contract',
  outcome,
  runner_blocked: runnerBlocked,
  ...(runnerBlocked ? {blocked_reason: runnerBlockedReason} : {}),
  ...(conformanceRunId !== null ? {conformance_run_id: conformanceRunId, conformanceRunId} : {}),
  started_at: evidence.started_at || startedAt,
  finished_at: evidence.finished_at || finishedAt,
  generated_at: finishedAt,
  artifact_versions: artifactVersions,
  published_artifact_versions: artifactVersions,
  resolved_artifact_versions: artifactVersions,
  artifact_sources: artifactSources,
  artifact_source_verification: artifactSourceVerification,
  artifact_install_evidence: artifactInstallEvidence,
  artifact_policy_failures: artifactPolicyFailures,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  required_scenarios: requiredScenarios,
  reported_scenarios: scenarioResults.map((scenario) => scenario.scenario_id),
  scenario_statuses: scenarioStatuses,
  scenario_status_values: [...allowedStatuses],
  non_passing_status_values: [...routedNonPassStatuses],
  non_pass_scenarios: nonPassScenarios,
  non_pass_routes: nonPassRoutes,
  unrouted_non_pass_scenarios: unroutedNonPassScenarios,
  topology: {
    namespaces: ['tenant-a', 'tenant-b', 'shared', 'denied'],
    endpoint: 'shared:Greeter',
    operation: 'greet',
  },
  runtime_matrix: {
    callers: ['sdk-php', 'sdk-python'],
    services: ['workflow-php', 'sdk-python'],
    observers: ['caller_history', 'service_call_detail', 'waterline_operator_visibility'],
  },
  scenario_results: scenarioResults,
  findings,
  finding_links: findingLinks,
};

const record = {
  schema: 'durable-workflow.v2.nexus-runtime.record',
  version: 1,
  experiment: 'nexus',
  outcome,
  runner_blocked: runnerBlocked,
  runnerBlocked,
  ...(runnerBlocked ? {blockedReason: runnerBlockedReason} : {}),
  ...(conformanceRunId !== null ? {conformance_run_id: conformanceRunId, conformanceRunId} : {}),
  artifact_versions: artifactVersions,
  artifactVersions,
  published_artifact_versions: artifactVersions,
  publishedArtifactVersions: artifactVersions,
  resolved_artifact_versions: artifactVersions,
  resolvedArtifactVersions: artifactVersions,
  artifact_sources: artifactSources,
  artifactSources,
  artifact_source_verification: artifactSourceVerification,
  artifactSourceVerification,
  artifact_install_evidence: artifactInstallEvidence,
  artifactInstallEvidence,
  artifact_policy_failures: artifactPolicyFailures,
  artifactPolicyFailures,
  local_product_source_checkouts_used: localProductSourceCheckoutsUsed,
  localProductSourceCheckoutsUsed,
  required_scenarios: requiredScenarios,
  requiredScenarios,
  reported_scenarios: scenarioResults.map((scenario) => scenario.scenario_id),
  reportedScenarios: scenarioResults.map((scenario) => scenario.scenario_id),
  scenario_statuses: scenarioStatuses,
  scenarioStatuses,
  scenario_status_values: [...allowedStatuses],
  scenarioStatusValues: [...allowedStatuses],
  non_passing_status_values: [...routedNonPassStatuses],
  nonPassingStatusValues: [...routedNonPassStatuses],
  non_pass_scenarios: nonPassScenarios,
  nonPassScenarios,
  non_pass_routes: nonPassRoutes,
  nonPassRoutes,
  unrouted_non_pass_scenarios: unroutedNonPassScenarios,
  unroutedNonPassScenarios,
  scenario_results: scenarioResults,
  scenarioResults,
  finding_links: findingLinks,
  findingLinks,
  structured_findings: findings,
  structuredFindings: findings,
  finding_summaries: findings.map((finding) => {
    if (finding && typeof finding.observed_behavior === 'string') {
      return finding.observed_behavior;
    }
    if (finding && typeof finding.summary === 'string') {
      return finding.summary;
    }
    return JSON.stringify(finding);
  }),
  findings: findings.map((finding) => {
    if (finding && typeof finding.observed_behavior === 'string') {
      return finding.observed_behavior;
    }
    if (finding && typeof finding.summary === 'string') {
      return finding.summary;
    }
    return JSON.stringify(finding);
  }),
  resultPath: path.join(resultDir, 'nexus-conformance-result.json'),
};

fs.writeFileSync(path.join(resultDir, 'pins.json'), JSON.stringify(pins, null, 2) + '\n');
fs.writeFileSync(path.join(resultDir, 'nexus-conformance-result.json'), JSON.stringify(result, null, 2) + '\n');
fs.writeFileSync(path.join(resultDir, 'nexus-conformance-record.json'), JSON.stringify(record, null, 2) + '\n');

console.log(`nexus conformance outcome: ${outcome}`);
console.log(`nexus conformance result: ${path.join(resultDir, 'nexus-conformance-result.json')}`);
NODE
