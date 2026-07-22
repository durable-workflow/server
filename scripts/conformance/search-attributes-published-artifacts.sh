#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: search-attributes-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Runs or assembles the published-artifact search-attributes conformance handoff.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  artifact-install-evidence.json
  sdk-php-search-attributes-shard.json
  waterline-search-attributes-shard.json
  codec-round-trip-shard.json
  search-attributes-result.json
  search-attributes-record.json

Environment overrides:
  DW_SEARCH_ATTRIBUTES_RESULT_DIR              Result directory when --result-dir is omitted.
  DW_SEARCH_ATTRIBUTES_RESULT_FILE             Complete JSON result from a host-backed matrix run.
  DW_SEARCH_ATTRIBUTES_RESULT_JSON             Complete JSON result string from a host-backed matrix run.
  DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_FILE PHP SDK shard JSON emitted by php-sdk-published-artifacts.sh.
  DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_JSON PHP SDK shard JSON string.
  DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_FILE    Waterline shard JSON emitted by waterline:search-attributes-conformance.
  DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_JSON    Waterline shard JSON string.
  DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE        PHP/Python codec round-trip shard JSON.
  DW_SEARCH_ATTRIBUTES_CODEC_SHARD_JSON        PHP/Python codec round-trip shard JSON string.
  DW_SEARCH_ATTRIBUTES_BLOCKED_REASON          Reason to record when shard evidence is unavailable.
  DW_SERVER_VERSION                            Published server version under test.
  DW_CLI_VERSION                               Published CLI version under test.
  DW_PYTHON_SDK_VERSION                        Published Python SDK version under test.
  DW_PHP_SDK_VERSION                           Exact durable-workflow/sdk version under test.
  DW_WORKFLOW_PHP_VERSION                      Published PHP workflow version under test.
  DW_WATERLINE_VERSION                         Published Waterline version under test.
USAGE
}

result_dir="${DW_SEARCH_ATTRIBUTES_RESULT_DIR:-}"

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
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-search-attributes.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

started_at="$(timestamp)"

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-unresolved}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-unresolved}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-unresolved}" \
DW_PHP_SDK_VERSION="${DW_PHP_SDK_VERSION:-unresolved}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-unresolved}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-unresolved}" \
DW_SEARCH_ATTRIBUTES_RESULT_FILE="${DW_SEARCH_ATTRIBUTES_RESULT_FILE:-}" \
DW_SEARCH_ATTRIBUTES_RESULT_JSON="${DW_SEARCH_ATTRIBUTES_RESULT_JSON:-}" \
DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_FILE="${DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_FILE:-}" \
DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_JSON="${DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_JSON:-}" \
DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_FILE="${DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_FILE:-}" \
DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_JSON="${DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_JSON:-}" \
DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE="${DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE:-}" \
DW_SEARCH_ATTRIBUTES_CODEC_SHARD_JSON="${DW_SEARCH_ATTRIBUTES_CODEC_SHARD_JSON:-}" \
DW_SEARCH_ATTRIBUTES_BLOCKED_REASON="${DW_SEARCH_ATTRIBUTES_BLOCKED_REASON:-}" \
node - <<'NODE'
const fs = require('node:fs');
const path = require('node:path');

const SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result';
const RUNNER = 'scripts/conformance/search-attributes-published-artifacts.sh';
const RESULT_FILES = [
  'pins.json',
  'run-metadata.json',
  'artifact-install-evidence.json',
  'sdk-php-search-attributes-shard.json',
  'waterline-search-attributes-shard.json',
  'codec-round-trip-shard.json',
  'search-attributes-result.json',
  'search-attributes-record.json',
];
const REQUIRED_SCENARIOS = [
  'published_artifact_install_only',
  'schema_definition_and_reserved_name_refusal',
  'python_worker_start_and_upsert_visibility',
  'php_worker_start_and_upsert_visibility',
  'cli_query_and_error_surface',
  'waterline_operator_visibility',
  'python_to_php_codec_round_trip',
  'php_to_python_codec_round_trip',
  'equality_range_bool_query_behavior',
  'or_not_query_grammar',
  'keyword_list_membership',
  'type_safety_wrong_literal',
  'undefined_key_rejection',
  'indexing_latency_distribution',
  'load_and_bounded_latency',
  'namespace_isolation',
  'query_injection_hardening',
];
const REQUIRED_SCOPES = [
  'published-artifact-install',
  'server-python-search-attribute-smoke',
  'sdk-php-search-attribute-shard',
  'cli-search-attribute-surface-shard',
  'waterline-operator-search-attribute-shard',
  'cross-language-codec-shard',
  'latency-and-load-shard',
  'adversarial-query-shard',
];
const RUNTIME_MATRIX = {
  runtimes: ['sdk-php', 'sdk-python'],
  client_paths: ['cli', 'sdk-php', 'sdk-python'],
  observer_paths: [
    'waterline-workflow-list-filter',
    'waterline-selected-run-detail',
    'waterline-saved-filter',
  ],
  runtime_cells: [
    {
      worker: 'sdk-python',
      clients: ['cli', 'sdk-python'],
      scenario: 'python_worker_start_and_upsert_visibility',
    },
    {
      worker: 'sdk-php',
      clients: ['cli', 'sdk-php'],
      scenario: 'php_worker_start_and_upsert_visibility',
    },
  ],
  cross_language_cells: [
    {
      writer: 'sdk-python',
      readers: ['sdk-php', 'cli'],
      scenario: 'python_to_php_codec_round_trip',
    },
    {
      writer: 'sdk-php',
      readers: ['sdk-python', 'cli'],
      scenario: 'php_to_python_codec_round_trip',
    },
  ],
};
const TOPOLOGY = {
  namespaces: ['sa-test', 'sa-test-b'],
  schema_keys: {
    customer_id: 'string',
    order_total_cents: 'int',
    discount_ratio: 'double',
    priority_tier: 'keyword',
    is_vip: 'bool',
    created_at: 'datetime',
    tags: 'keyword_list',
  },
  reserved_name_refusals: [],
};
const RESULT_GATE_SCHEMA = 'durable-workflow.v2.search-attribute-runtime.result-gate';
const RESULT_GATE_VERSION = 13;
const REQUIRED_QUERIES = {
  equality: 'customer_id = "cust-7"',
  range: 'order_total_cents > 5000 AND order_total_cents <= 10000',
  bool: 'is_vip = true',
  or: 'customer_id = "cust-2" OR customer_id = "cust-8"',
  not: 'priority_tier IN ("gold","platinum") AND NOT is_vip',
  keyword_list: 'tags = "urgent"',
};
const REQUIRED_ARTIFACTS = ['server', 'cli', 'sdk-php', 'workflow-php', 'sdk-python', 'waterline'];
const ALLOWED_STATUSES = ['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked'];
const ALLOWED_OUTCOMES = ['pass', 'non_passing', 'non_passing_runner_blocked', 'non_passing_with_root_cause_finding'];
const REQUIRED_RUN_RECORD_FIELDS = [
  'artifact_versions',
  'run_id',
  'started_at',
  'finished_at',
  'generated_at',
  'outcome',
  'runner_blocked',
  'scenario_results',
  'findings',
  'finding_links',
  'topology',
  'query_verdicts',
  'codec_round_trips',
  'latency_distribution',
  'load_profile',
];
const REQUIRED_RUNTIME_CELLS = RUNTIME_MATRIX.runtime_cells;
const REQUIRED_CROSS_LANGUAGE_CELLS = RUNTIME_MATRIX.cross_language_cells;
const REQUIRED_SECTIONS = {
  topology: ['schema_definition_and_reserved_name_refusal', 'namespace_isolation'],
  query_verdicts: [
    'equality_range_bool_query_behavior',
    'or_not_query_grammar',
    'keyword_list_membership',
  ],
  type_safety_errors: ['type_safety_wrong_literal', 'undefined_key_rejection'],
  latency_distribution: ['indexing_latency_distribution'],
  load_profile: ['load_and_bounded_latency'],
  waterline_operator_visibility: ['waterline_operator_visibility'],
  codec_round_trips: ['python_to_php_codec_round_trip', 'php_to_python_codec_round_trip'],
  adversarial_queries: ['query_injection_hardening'],
};
const FULL_PARITY_SCENARIOS = [
  'php_worker_start_and_upsert_visibility',
  'cli_query_and_error_surface',
  'waterline_operator_visibility',
  'python_to_php_codec_round_trip',
  'php_to_python_codec_round_trip',
  'or_not_query_grammar',
  'indexing_latency_distribution',
  'load_and_bounded_latency',
  'query_injection_hardening',
];

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function defaultRunId(startedAt) {
  return `search-attributes-${startedAt.replace(/[^0-9TZ]/g, '')}`;
}

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function loadJson(fileEnv, jsonEnv) {
  const fileValue = (process.env[fileEnv] || '').trim();
  const jsonValue = (process.env[jsonEnv] || '').trim();
  if (fileValue !== '') {
    return JSON.parse(fs.readFileSync(fileValue, 'utf8'));
  }
  if (jsonValue !== '') {
    return JSON.parse(jsonValue);
  }
  return null;
}

function artifactVersions() {
  return {
    server: process.env.DW_SERVER_VERSION,
    cli: process.env.DW_CLI_VERSION,
    'sdk-php': process.env.DW_PHP_SDK_VERSION,
    'workflow-php': process.env.DW_WORKFLOW_PHP_VERSION,
    workflow: process.env.DW_WORKFLOW_PHP_VERSION,
    'sdk-python': process.env.DW_PYTHON_SDK_VERSION,
    waterline: process.env.DW_WATERLINE_VERSION,
  };
}

function artifactSources() {
  return {
    server: 'published_docker_image',
    cli: 'official_install_script',
    'sdk-php': 'composer_release',
    'workflow-php': 'server_embedded_composer_release',
    workflow: 'server_embedded_composer_release',
    'sdk-python': 'pypi_release',
    waterline: 'published_waterline_release',
  };
}

function scenarioScope(scenarioId) {
  if (scenarioId === 'published_artifact_install_only') {
    return 'published-artifact-install';
  }
  if (scenarioId === 'python_worker_start_and_upsert_visibility') {
    return 'server-python-search-attribute-smoke';
  }
  if (scenarioId === 'php_worker_start_and_upsert_visibility') {
    return 'sdk-php-search-attribute-shard';
  }
  if ([
    'python_to_php_codec_round_trip',
    'php_to_python_codec_round_trip',
  ].includes(scenarioId)) {
    return 'cross-language-codec-shard';
  }
  if (scenarioId === 'cli_query_and_error_surface') {
    return 'cli-search-attribute-surface-shard';
  }
  if (scenarioId === 'waterline_operator_visibility') {
    return 'waterline-operator-search-attribute-shard';
  }
  if (['indexing_latency_distribution', 'load_and_bounded_latency'].includes(scenarioId)) {
    return 'latency-and-load-shard';
  }
  if (scenarioId === 'query_injection_hardening') {
    return 'adversarial-query-shard';
  }
  return 'server-python-search-attribute-smoke';
}

function slug(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function findingFor(scenarioId, reason, versions) {
  return {
    id: `runner-blocked-${slug(scenarioId)}`,
    scenario_id: scenarioId,
    finding_type: 'runner_gap',
    owning_surface: 'conformance_harness',
    required_execution_scope: scenarioScope(scenarioId),
    artifact_versions: versions,
    observed_behavior: `search-attributes conformance runner did not produce product evidence for ${scenarioId}: ${reason}`,
    expected_behavior: 'published artifacts are installed and the required search-attribute runtime, CLI, Waterline, codec, latency, and adversarial-query shards run',
    next_acceptance_criterion: 'provide host-backed shard evidence or a complete search-attributes result and rerun the published-artifact handoff',
    priority: 'P0',
  };
}

function scenarioResult(scenarioId, reason, versions) {
  const finding = findingFor(scenarioId, reason, versions);
  return {
    scenario_id: scenarioId,
    status: 'runner_blocked',
    observed_outputs: {
      blocked_reason: reason,
      required_execution_scope: scenarioScope(scenarioId),
      published_artifacts_only: true,
    },
    linked_findings: [finding],
  };
}

function scenarioResultsById(result, duplicateScenarioCounts = {}) {
  const raw = result.scenario_results || result.scenarioResults || {};
  const results = {};
  const seen = new Set();
  const record = (scenarioId, item) => {
    if (seen.has(scenarioId)) {
      duplicateScenarioCounts[scenarioId] = (duplicateScenarioCounts[scenarioId] || 1) + 1;
    } else {
      seen.add(scenarioId);
    }
    results[scenarioId] = { ...item, scenario_id: scenarioId };
  };

  if (Array.isArray(raw)) {
    for (const item of raw) {
      if (!isObject(item) || (!item.scenario_id && !item.id)) {
        continue;
      }
      record(String(item.scenario_id || item.id), item);
    }
    return results;
  }
  if (!isObject(raw)) {
    return {};
  }

  for (const [key, value] of Object.entries(raw)) {
    if (isObject(value)) {
      record(key, value);
    }
  }

  return results;
}

function stringValue(value) {
  if (value === null || value === undefined) {
    return '';
  }
  if (typeof value === 'string') {
    return value.trim();
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return '';
}

function arrayValue(value, field) {
  if (!isObject(value) && !Array.isArray(value)) {
    return null;
  }
  const fieldValue = value[field];
  if (isObject(fieldValue) || Array.isArray(fieldValue)) {
    return fieldValue;
  }
  return null;
}

function firstArrayField(value, fields) {
  for (const field of fields) {
    const fieldValue = arrayValue(value, field);
    if (fieldValue !== null) {
      return fieldValue;
    }
  }
  return null;
}

function firstFieldValue(value, fields) {
  if (!isObject(value)) {
    return undefined;
  }
  for (const field of fields) {
    if (Object.prototype.hasOwnProperty.call(value, field)) {
      return value[field];
    }
  }
  return undefined;
}

function stringList(value) {
  if (!Array.isArray(value)) {
    return [];
  }
  return value.map((item) => stringValue(item)).filter((item) => item !== '');
}

function nonEmptyValue(value) {
  if (value === null || value === undefined) {
    return false;
  }
  if (Array.isArray(value)) {
    return value.length > 0;
  }
  if (isObject(value)) {
    return Object.keys(value).length > 0;
  }
  return stringValue(value) !== '';
}

function hasNonEmptyField(value, fields) {
  if (!isObject(value)) {
    return false;
  }
  return fields.some((field) => Object.prototype.hasOwnProperty.call(value, field) && nonEmptyValue(value[field]));
}

function hasAnyField(value, fields) {
  if (!isObject(value)) {
    return false;
  }
  return fields.some((field) => Object.prototype.hasOwnProperty.call(value, field));
}

function hasNonPlaceholderField(value, fields) {
  if (!isObject(value)) {
    return false;
  }
  return fields.some((field) => (
    Object.prototype.hasOwnProperty.call(value, field)
    && nonEmptyValue(value[field])
    && !(typeof value[field] === 'string' && isPlaceholderEvidence(value[field]))
  ));
}

function hasTruthyField(value, fields) {
  if (!isObject(value)) {
    return false;
  }
  return fields.some((field) => {
    const fieldValue = value[field];
    return fieldValue === true || fieldValue === 1 || fieldValue === '1' || fieldValue === 'true';
  });
}

function boolField(value, fields) {
  if (!isObject(value)) {
    return null;
  }
  for (const field of fields) {
    if (!Object.prototype.hasOwnProperty.call(value, field)) {
      continue;
    }
    const fieldValue = value[field];
    if (fieldValue === true || fieldValue === 1 || fieldValue === '1' || fieldValue === 'true') {
      return true;
    }
    if (fieldValue === false || fieldValue === 0 || fieldValue === '0' || fieldValue === 'false') {
      return false;
    }
  }
  return null;
}

function numericField(value, fields) {
  if (!isObject(value)) {
    return null;
  }
  for (const field of fields) {
    if (!Object.prototype.hasOwnProperty.call(value, field)) {
      continue;
    }
    const fieldValue = value[field];
    if (typeof fieldValue === 'number' && Number.isFinite(fieldValue)) {
      return fieldValue;
    }
    if (typeof fieldValue === 'string' && fieldValue.trim() !== '' && Number.isFinite(Number(fieldValue))) {
      return Number(fieldValue);
    }
  }
  return null;
}

function hasNumericField(value, fields) {
  return numericField(value, fields) !== null;
}

function stringArrayField(value, fields) {
  const fieldValue = firstArrayField(value, fields);
  if (fieldValue === null) {
    return null;
  }
  const values = Array.isArray(fieldValue) ? fieldValue : Object.values(fieldValue);
  return values.map((item) => stringValue(item)).filter((item) => item !== '');
}

function sameStringSet(expected, actual) {
  return JSON.stringify([...expected].sort()) === JSON.stringify([...actual].sort());
}

function normalizeComparableValue(value) {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeComparableValue(item));
  }
  if (!isObject(value)) {
    return value;
  }
  return Object.fromEntries(
    Object.keys(value)
      .sort()
      .map((key) => [key, normalizeComparableValue(value[key])])
  );
}

function waterlineValuesMatch(actual, expected) {
  return JSON.stringify(normalizeComparableValue(actual)) === JSON.stringify(normalizeComparableValue(expected));
}

function camelize(value) {
  return value.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
}

function normalizeEvidenceKey(value) {
  return stringValue(value).toLowerCase().replace(/\s+/g, ' ').replace(/-/g, '_').trim();
}

function sameRuntime(reported, required) {
  const aliases = {
    'sdk-php': ['sdk-php', 'sdk_php', 'php', 'php_worker'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python', 'python_worker'],
  };
  return (aliases[required] || [required]).includes(stringValue(reported));
}

function artifactVersionValue(versions, artifact) {
  const aliases = {
    'sdk-php': ['sdk-php', 'sdk_php'],
    'workflow-php': ['workflow-php', 'workflow_php', 'workflow'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python'],
    waterline: ['waterline', 'waterline-ui', 'waterline_ui'],
  };
  for (const key of aliases[artifact] || [artifact]) {
    if (Object.prototype.hasOwnProperty.call(versions, key) && stringValue(versions[key]) !== '') {
      return stringValue(versions[key]);
    }
  }
  return '';
}

function resultArtifactVersions(result) {
  return firstArrayField(result, [
    'artifact_versions',
    'artifactVersions',
    'published_artifact_versions',
    'publishedArtifactVersions',
  ]) || {};
}

function resultArtifactSources(result) {
  return firstArrayField(result, ['artifact_sources', 'artifactSources']) || {};
}

function isPlaceholderVersion(version) {
  const normalized = stringValue(version).toLowerCase();
  if (normalized === '') {
    return false;
  }
  if (/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/.test(normalized)) {
    return true;
  }
  return /(^|[^a-z0-9])latest([^a-z0-9]|$)/.test(normalized)
    || ['latest', 'current', 'head', 'unresolved', 'placeholder'].includes(normalized);
}

function isExactSemverRelease(version) {
  return /^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$/.test(stringValue(version));
}

function isPlaceholderEvidence(value) {
  const normalized = stringValue(value).toLowerCase();
  if (normalized === '') {
    return true;
  }
  if (/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/.test(normalized)) {
    return true;
  }
  return ['1', 'true', 'ok', 'pass', 'passed', 'recorded', 'placeholder', 'todo', 'tbd', 'n/a', 'none']
    .includes(normalized);
}

function scenarioOutputs(scenarioResult) {
  return firstArrayField(scenarioResult, ['observed_outputs', 'observedOutputs']) || {};
}

function sectionValue(result, section) {
  return firstArrayField(result, [section, camelize(section)]);
}

function loadQueryLatencyProfiles(section) {
  for (const field of [
    'query_latencies',
    'queryLatencies',
    'query_latency_by_filter',
    'queryLatencyByFilter',
    'filter_latencies',
    'filterLatencies',
    'queries',
  ]) {
    const profiles = arrayValue(section, field);
    if (profiles !== null) {
      return profiles;
    }
  }

  return section;
}

function scenarioEvidence(result, scenarioResult, section) {
  const topLevel = sectionValue(result, section);
  if (topLevel !== null && nonEmptyValue(topLevel)) {
    return topLevel;
  }
  const outputs = scenarioOutputs(scenarioResult);
  const scenarioSection = firstArrayField(scenarioResult, [section, camelize(section)])
    || firstArrayField(outputs, [section, camelize(section)]);
  return scenarioSection !== null && nonEmptyValue(scenarioSection) ? scenarioSection : outputs;
}

function hasObservedOutputs(scenarioResult) {
  return [
    'observed_outputs',
    'observedOutputs',
    'runtime_matrix',
    'runtimeMatrix',
    'query_verdicts',
    'queryVerdicts',
    'latency_distribution',
    'latencyDistribution',
    'waterline_operator_visibility',
    'waterlineOperatorVisibility',
    'codec_round_trips',
    'codecRoundTrips',
    'adversarial_queries',
    'adversarialQueries',
    'load_profile',
    'loadProfile',
  ].some((field) => {
    const value = arrayValue(scenarioResult, field);
    return value !== null && nonEmptyValue(value);
  });
}

function hasLinkedFindings(scenarioResult, result) {
  for (const field of ['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks']) {
    const value = arrayValue(scenarioResult, field);
    if (value !== null && nonEmptyValue(value)) {
      return true;
    }
  }

  const scenarioId = stringValue(scenarioResult.scenario_id);
  for (const field of ['finding_links', 'findingLinks', 'findings']) {
    const links = arrayValue(result, field);
    if (links === null) {
      continue;
    }
    if (Object.prototype.hasOwnProperty.call(links, scenarioId) && nonEmptyValue(links[scenarioId])) {
      return true;
    }
    for (const link of Object.values(links)) {
      if (isObject(link) && stringValue(link.scenario_id || link.scenario) === scenarioId) {
        return true;
      }
    }
  }
  return false;
}

function hasRunRecordField(result, field) {
  if (field === 'artifact_versions') {
    return nonEmptyValue(resultArtifactVersions(result));
  }
  if (field === 'scenario_results') {
    return firstArrayField(result, ['scenario_results', 'scenarioResults']) !== null;
  }
  if (field === 'findings') {
    return firstArrayField(result, ['findings']) !== null;
  }
  if (field === 'finding_links') {
    return firstArrayField(result, ['finding_links', 'findingLinks']) !== null;
  }
  if (['started_at', 'finished_at', 'generated_at'].includes(field)) {
    return stringValue(result[field] || result[camelize(field)]) !== '';
  }
  if (field === 'outcome') {
    return stringValue(result.outcome || result.status || result.verdict) !== '';
  }
  if (field === 'runner_blocked') {
    return runnerBlockedValue(result) !== null;
  }
  if (Object.prototype.hasOwnProperty.call(result, field)) {
    const value = result[field];
    return isObject(value) || Array.isArray(value) || nonEmptyValue(value);
  }
  const camelized = camelize(field);
  if (Object.prototype.hasOwnProperty.call(result, camelized)) {
    const value = result[camelized];
    return isObject(value) || Array.isArray(value) || nonEmptyValue(value);
  }
  return false;
}

function runnerBlockedValue(result) {
  if (!isObject(result)) {
    return null;
  }
  for (const field of ['runner_blocked', 'runnerBlocked']) {
    if (!Object.prototype.hasOwnProperty.call(result, field)) {
      continue;
    }
    if (typeof result[field] === 'boolean') {
      return result[field];
    }
    return null;
  }
  return null;
}

function matrixHasRuntime(matrix, runtime) {
  for (const field of ['runtimes', 'workers', 'worker_runtimes', 'workerRuntimes']) {
    for (const reported of stringList(matrix[field] || [])) {
      if (sameRuntime(reported, runtime)) {
        return true;
      }
    }
  }
  return false;
}

function matrixHasCell(matrix, cellGroup, requiredCell) {
  const reportedCells = [];
  for (const field of [cellGroup, 'cells', 'runtime_cells', 'runtimeCells']) {
    const cells = arrayValue(matrix, field);
    if (Array.isArray(cells)) {
      reportedCells.push(...cells);
    }
  }
  for (const reported of reportedCells) {
    if (!isObject(reported)) {
      continue;
    }
    if (stringValue(reported.scenario || reported.scenario_id) !== stringValue(requiredCell.scenario)) {
      continue;
    }
    const reportedRuntime = stringValue(reported.worker || reported.writer || reported.runtime);
    const requiredRuntime = stringValue(requiredCell.worker || requiredCell.writer);
    if (!sameRuntime(reportedRuntime, requiredRuntime)) {
      continue;
    }
    const reportedClients = stringList(reported.clients || reported.readers || []);
    const requiredClients = stringList(requiredCell.clients || requiredCell.readers || []);
    if (requiredClients.every((client) => reportedClients.includes(client))) {
      return true;
    }
  }
  return false;
}

function schemaDefinitionsIncludeType(definitions, type) {
  for (const [key, definition] of Object.entries(definitions || {})) {
    if (typeof key === 'string' && stringValue(definition) === type) {
      return true;
    }
    if (isObject(definition) && stringValue(definition.type || definition.value_type) === type) {
      return true;
    }
    if (stringValue(definition) === type) {
      return true;
    }
  }
  return false;
}

function reservedRefusalsIncludeName(refusals, name) {
  for (const [key, refusal] of Object.entries(refusals || {})) {
    if (typeof key === 'string' && key === name && nonEmptyValue(refusal)) {
      return true;
    }
    if (stringValue(refusal) === name) {
      return true;
    }
    if (!isObject(refusal)) {
      continue;
    }
    const refusedName = stringValue(refusal.name || refusal.key || refusal.reserved_name || refusal.reservedName);
    if (refusedName === name && !hasTruthyField(refusal, ['accepted', 'acceptedReservedName'])) {
      return true;
    }
  }
  return false;
}

function cliEntryContainsProbe(entry, probe) {
  const wanted = probe.replace(/\s+/g, ' ').trim();
  for (const field of ['query', 'input', 'probe', 'rejected_input', 'rejectedInput']) {
    const value = entry[field];
    const values = Array.isArray(value) ? value : [value];
    if (values.some((item) => stringValue(item).replace(/\s+/g, ' ').trim() === wanted)) {
      return true;
    }
  }
  for (const field of ['arguments', 'args', 'argv']) {
    const value = entry[field];
    const values = Array.isArray(value) ? value : [value];
    if (values.some((item) => stringValue(item).replace(/\s+/g, ' ').trim() === wanted)) {
      return true;
    }
  }
  return false;
}

function cliEntryForKey(entries, key, probe = null) {
  if (!isObject(entries) && !Array.isArray(entries)) {
    return null;
  }
  for (const entryKey of [key, camelize(key)]) {
    const entry = entries[entryKey];
    if (isObject(entry) && (probe === null || cliEntryContainsProbe(entry, probe))) {
      return entry;
    }
  }
  const wanted = normalizeEvidenceKey(key);
  for (const [entryKey, entry] of Object.entries(entries)) {
    if (!isObject(entry)) {
      continue;
    }
    if (typeof entryKey === 'string'
      && normalizeEvidenceKey(entryKey) === wanted
      && (probe === null || cliEntryContainsProbe(entry, probe))) {
      return entry;
    }
    for (const field of ['query_class', 'queryClass', 'class', 'kind', 'operation', 'name', 'diagnostic', 'case']) {
      if (normalizeEvidenceKey(entry[field]) === wanted && (probe === null || cliEntryContainsProbe(entry, probe))) {
        return entry;
      }
    }
    if (probe !== null && /^\d+$/.test(entryKey) && cliEntryContainsProbe(entry, probe)) {
      return entry;
    }
  }
  return null;
}

function cliTranscriptFailures(entry, entryId, entryType) {
  const failures = [];
  for (const [field, aliases] of Object.entries({ command: ['command'], arguments: ['arguments', 'args', 'argv'] })) {
    if (!hasNonEmptyField(entry, aliases)) {
      failures.push({ code: 'missing_cli_transcript_field', scenario_id: 'cli_query_and_error_surface', entry_type: entryType, entry_id: entryId, field });
    }
  }
  for (const [field, aliases] of Object.entries({ stdout: ['stdout'], stderr: ['stderr'] })) {
    if (!hasAnyField(entry, aliases)) {
      failures.push({ code: 'missing_cli_transcript_field', scenario_id: 'cli_query_and_error_surface', entry_type: entryType, entry_id: entryId, field });
    }
  }
  if (!hasNumericField(entry, ['exit_code', 'exitCode', 'exit_status', 'exitStatus'])) {
    failures.push({ code: 'missing_cli_transcript_field', scenario_id: 'cli_query_and_error_surface', entry_type: entryType, entry_id: entryId, field: 'exit_code' });
  }
  return failures;
}

function queryCountFailures(entry, queryClass, prefix = 'cli_') {
  const failures = [];
  const expected = numericField(entry, ['expected_count', 'expectedCount']);
  const actual = numericField(entry, ['actual_count', 'actualCount']);
  if (expected === null) {
    failures.push({ code: `${prefix}missing_query_count`, query_class: queryClass, field: 'expected_count' });
  }
  if (actual === null) {
    failures.push({ code: `${prefix}missing_query_count`, query_class: queryClass, field: 'actual_count' });
  }
  if (expected !== null && actual !== null && expected !== actual) {
    failures.push({ code: `${prefix}query_count_mismatch`, query_class: queryClass, expected_count: expected, actual_count: actual });
  }
  return failures;
}

function validTypedErrorEvidence(entry) {
  if (isObject(entry)) {
    return hasNonEmptyField(entry, ['error_code', 'errorCode', 'code'])
      && hasNonEmptyField(entry, ['message', 'error_message', 'errorMessage']);
  }
  return stringValue(entry) !== '' && !isPlaceholderEvidence(entry);
}

function probeEvidenceMatches(evidence, requiredProbe) {
  const normalizedEvidence = stringValue(evidence).toLowerCase().replace(/\s+/g, ' ').trim();
  const normalizedProbe = stringValue(requiredProbe).toLowerCase().replace(/\s+/g, ' ').trim();
  if (normalizedEvidence === '') {
    return false;
  }
  if (normalizedEvidence === normalizedProbe || normalizedEvidence.includes(normalizedProbe)) {
    return true;
  }
  if (normalizedProbe === 'embedded sql comment') {
    return normalizedEvidence.includes('--') || normalizedEvidence.includes('/*') || normalizedEvidence.includes('*/');
  }
  if (normalizedProbe === 'shell metacharacters') {
    return /[;|&`]|\$\(/.test(normalizedEvidence);
  }
  return false;
}

function exactProbeEvidenceMatches(evidence, requiredProbe) {
  const normalizedEvidence = stringValue(evidence).replace(/\s+/g, ' ').trim();
  const normalizedProbe = stringValue(requiredProbe).replace(/\s+/g, ' ').trim();
  return normalizedEvidence !== '' && normalizedEvidence === normalizedProbe;
}

function injectionRejectionForProbe(rejections, probe) {
  for (const [key, rejection] of Object.entries(rejections || {})) {
    const keyMatches = probeEvidenceMatches(key, probe);
    if (isObject(rejection)) {
      if (keyMatches) {
        return rejection;
      }
      for (const field of ['probe', 'probe_name', 'probeName', 'case', 'class', 'kind', 'input', 'query', 'rejected_input', 'rejectedInput']) {
        if (probeEvidenceMatches(rejection[field], probe)) {
          return rejection;
        }
      }
      continue;
    }

    if (keyMatches || probeEvidenceMatches(rejection, probe)) {
      return { rejected_input: typeof key === 'string' ? key : stringValue(rejection) };
    }
  }
  return null;
}

function injectionRejectionDiagnosticFailures(rejection, probe) {
  const failures = [];
  const statusCode = numericField(rejection, [
    'status_code',
    'statusCode',
    'http_status',
    'httpStatus',
    'response_status',
    'responseStatus',
  ]);
  if (statusCode === null) {
    failures.push({ code: 'missing_injection_rejection_field', probe, field: 'status_code' });
  } else if (statusCode >= 200 && statusCode < 300) {
    failures.push({ code: 'injection_rejection_status_succeeded', probe, status_code: statusCode });
  }
  if (!hasNonPlaceholderField(rejection, [
    'response_body',
    'responseBody',
    'body',
    'response',
    'error_body',
    'errorBody',
  ])) {
    failures.push({ code: 'missing_injection_rejection_field', probe, field: 'response_body' });
  }
  return failures;
}

function queryVerdictText(verdict) {
  for (const field of ['query', 'query_string', 'queryString', 'input', 'probe']) {
    const value = stringValue(verdict[field]);
    if (value !== '') {
      return value;
    }
  }
  return '';
}

function coverageGapFindingFor(scenarioId, reason, versions) {
  return {
    id: `coverage-gap-${slug(scenarioId)}`,
    scenario_id: scenarioId,
    finding_type: 'conformance_runner_coverage_gap',
    owning_surface: 'conformance_harness',
    required_execution_scope: scenarioScope(scenarioId),
    artifact_versions: versions,
    observed_behavior: `supplied search-attributes result did not include gate-complete evidence for ${scenarioId}: ${reason}`,
    expected_behavior: 'the published-artifact handoff emits gate-complete evidence for every required search-attribute scenario',
    next_acceptance_criterion: 'supply complete scenario evidence and concrete published artifact versions before declaring a passing result',
    priority: 'P0',
  };
}

function notCoveredScenarioResult(scenarioId, reason, versions) {
  const finding = coverageGapFindingFor(scenarioId, reason, versions);
  return {
    scenario_id: scenarioId,
    status: 'not_covered',
    observed_outputs: {
      coverage_gap_reason: reason,
      required_execution_scope: scenarioScope(scenarioId),
      published_artifacts_only: true,
    },
    linked_findings: [finding],
  };
}

function mergeFinding(result, finding) {
  if (!Array.isArray(result.findings)) {
    result.findings = [];
  }
  if (!result.findings.some((item) => isObject(item) && item.id === finding.id)) {
    result.findings.push(finding);
  }
  if (!isObject(result.finding_links)) {
    result.finding_links = {};
  }
  const links = Array.isArray(result.finding_links[finding.scenario_id])
    ? result.finding_links[finding.scenario_id]
    : [];
  if (!links.some((item) => isObject(item) && item.id === finding.id)) {
    links.push(finding);
  }
  result.finding_links[finding.scenario_id] = links;
}

function clearFindingsForScenario(result, scenarioId) {
  if (Array.isArray(result.findings)) {
    result.findings = result.findings.filter((finding) => !isObject(finding) || stringValue(finding.scenario_id || finding.scenario) !== scenarioId);
  }
  if (isObject(result.finding_links)) {
    delete result.finding_links[scenarioId];
  }
}

function ensureMissingScenarioResults(result, missingScenarios, reason, versions) {
  if (missingScenarios.length === 0) {
    return;
  }
  const existing = scenarioResultsById(result);
  for (const scenarioId of missingScenarios) {
    const scenario = notCoveredScenarioResult(scenarioId, reason, versions);
    existing[scenarioId] = scenario;
    mergeFinding(result, scenario.linked_findings[0]);
  }
  result.scenario_results = existing;
}

function publishedArtifactInstallEvidenceFailures(result, scenarioResult) {
  const outputs = scenarioOutputs(scenarioResult);
  const sources = resultArtifactSources(result) || firstArrayField(outputs, [
    'artifact_sources',
    'artifactSources',
    'install_sources',
    'installSources',
  ]) || {};
  const failures = [];
  for (const artifact of REQUIRED_ARTIFACTS) {
    const source = artifactVersionValue(sources, artifact);
    if (source === '') {
      failures.push({ code: 'missing_published_artifact_install_source', scenario_id: 'published_artifact_install_only', artifact });
      continue;
    }
    if (isPlaceholderEvidence(source)) {
      failures.push({ code: 'placeholder_published_artifact_install_source', scenario_id: 'published_artifact_install_only', artifact, source });
    }
    if (source === 'local_product_source_checkout' || source === 'workspace_repo_as_artifact_under_test') {
      failures.push({ code: 'forbidden_published_artifact_install_source', scenario_id: 'published_artifact_install_only', artifact, source });
    }
  }
  return failures;
}

function schemaDefinitionEvidenceFailures(result, scenarioResult) {
  const outputs = scenarioOutputs(scenarioResult);
  const topology = sectionValue(result, 'topology') || {};
  const definitions = firstArrayField(outputs, ['schema_definitions', 'schemaDefinitions', 'schema_keys', 'schemaKeys'])
    || firstArrayField(topology, ['schema_definitions', 'schemaDefinitions', 'schema_keys', 'schemaKeys'])
    || {};
  const refusals = firstArrayField(outputs, ['reserved_name_refusals', 'reservedNameRefusals'])
    || firstArrayField(topology, ['reserved_name_refusals', 'reservedNameRefusals'])
    || {};
  const failures = [];
  for (const type of ['string', 'int', 'double', 'bool', 'datetime', 'keyword', 'keyword_list']) {
    if (!schemaDefinitionsIncludeType(definitions, type)) {
      failures.push({ code: 'missing_schema_type_evidence', scenario_id: 'schema_definition_and_reserved_name_refusal', type });
    }
  }
  for (const name of ['wf_id', '__internal']) {
    if (!reservedRefusalsIncludeName(refusals, name)) {
      failures.push({ code: 'missing_reserved_name_refusal_evidence', scenario_id: 'schema_definition_and_reserved_name_refusal', name });
    }
  }
  return failures;
}

function workerVisibilityEvidenceFailures(scenarioId, scenarioResult, expectedRuntime) {
  const outputs = scenarioOutputs(scenarioResult);
  const failures = [];
  for (const [field, aliases] of Object.entries({
    workflow_id: ['workflow_id', 'workflowId'],
    start_search_attributes: ['start_search_attributes', 'startSearchAttributes'],
    upserted_search_attributes: ['upserted_search_attributes', 'upsertedSearchAttributes'],
  })) {
    if (!hasNonEmptyField(outputs, aliases)) {
      failures.push({ code: 'missing_worker_visibility_evidence', scenario_id: scenarioId, field });
    }
  }
  const runtime = stringValue(outputs.worker || outputs.worker_runtime || outputs.workerRuntime || outputs.runtime);
  if (!sameRuntime(runtime, expectedRuntime)) {
    failures.push({ code: 'missing_worker_visibility_evidence', scenario_id: scenarioId, field: 'worker_runtime', expected_runtime: expectedRuntime });
  }
  if (!hasTruthyField(outputs, ['visibility_query_match', 'visibilityQueryMatch'])) {
    failures.push({ code: 'missing_worker_visibility_evidence', scenario_id: scenarioId, field: 'visibility_query_match' });
  }
  return failures;
}

function cliSurfaceEvidenceFailures(section) {
  const failures = [];
  const queries = firstArrayField(section, ['workflow_list_queries', 'workflowListQueries', 'queries', 'workflow_list_query', 'workflowListQuery']) || {};
  for (const [queryClass, query] of Object.entries(REQUIRED_QUERIES)) {
    const entry = cliEntryForKey(queries, queryClass, query);
    if (entry === null) {
      failures.push({ code: 'missing_cli_query_evidence', scenario_id: 'cli_query_and_error_surface', query_class: queryClass, query });
      continue;
    }
    failures.push(...cliTranscriptFailures(entry, queryClass, 'query'));
    failures.push(...queryCountFailures(entry, queryClass));
  }

  const commands = firstArrayField(section, ['search_attribute_commands', 'searchAttributeCommands', 'definition_commands', 'definitionCommands']) || {};
  for (const operation of ['list', 'create', 'delete']) {
    let entry = cliEntryForKey(commands, operation);
    if (entry === null) {
      const legacy = firstFieldValue(section, [`search_attribute_${operation}`, `searchAttribute${operation[0].toUpperCase()}${operation.slice(1)}`]);
      entry = isObject(legacy) ? legacy : null;
    }
    if (entry === null) {
      failures.push({ code: 'missing_cli_definition_command_evidence', scenario_id: 'cli_query_and_error_surface', operation });
      continue;
    }
    failures.push(...cliTranscriptFailures(entry, operation, 'definition_command'));
  }

  const diagnostics = firstArrayField(section, ['diagnostics', 'typed_errors', 'typedErrors', 'errors']) || {};
  const requiredDiagnostics = {
    wrong_literal: 'order_total_cents = "not-a-number"',
    injection: 'customer_id = "x" OR 1=1',
  };
  for (const [diagnostic, probe] of Object.entries(requiredDiagnostics)) {
    const entry = cliEntryForKey(diagnostics, diagnostic, probe);
    if (entry === null) {
      failures.push({ code: 'missing_cli_diagnostic_evidence', scenario_id: 'cli_query_and_error_surface', diagnostic, probe });
      continue;
    }
    failures.push(...cliTranscriptFailures(entry, diagnostic, 'diagnostic'));
    for (const [field, aliases] of Object.entries({
      error_code: ['error_code', 'errorCode', 'code', 'type', 'reason', 'rejection_reason', 'rejectionReason'],
      message: ['message', 'error', 'error_message', 'errorMessage'],
    })) {
      if (!hasNonEmptyField(entry, aliases)) {
        failures.push({ code: 'missing_cli_diagnostic_field', scenario_id: 'cli_query_and_error_surface', diagnostic, field });
      }
    }
    const exitCode = numericField(entry, ['exit_code', 'exitCode', 'exit_status', 'exitStatus']);
    if (exitCode === 0) {
      failures.push({ code: 'cli_diagnostic_command_succeeded', scenario_id: 'cli_query_and_error_surface', diagnostic });
    }
    const failureKind = stringValue(entry.failure_kind || entry.failureKind || entry.error_kind || entry.errorKind || entry.type || entry.reason || entry.error_code || entry.errorCode).toLowerCase();
    if (failureKind.includes('transport') || failureKind.includes('network')) {
      failures.push({ code: 'cli_diagnostic_collapsed_to_transport_failure', scenario_id: 'cli_query_and_error_surface', diagnostic });
    }
  }
  return failures;
}

function codecExpectedAttributes(entry) {
  const direct = firstArrayField(entry, [
    'written_attributes',
    'writtenAttributes',
    'expected_attributes',
    'expectedAttributes',
    'source_attributes',
    'sourceAttributes',
    'writer_attributes',
    'writerAttributes',
    'input_attributes',
    'inputAttributes',
  ]);
  if (direct !== null && nonEmptyValue(direct)) {
    return direct;
  }
  const wireContext = firstArrayField(entry, ['wire_value_context', 'wireValueContext', 'wire_context', 'wireContext']) || {};
  const wireValues = firstArrayField(wireContext, ['wire_values', 'wireValues', 'attributes'])
    || firstArrayField(entry, ['wire_values', 'wireValues'])
    || {};
  const attributes = {};
  for (const [attribute, wireValue] of Object.entries(wireValues)) {
    if (!isObject(wireValue)) {
      attributes[attribute] = wireValue;
      continue;
    }
    for (const field of ['value_string', 'value_keyword', 'value_int', 'value_double', 'value_float', 'value_bool', 'value_datetime', 'value_keyword_list']) {
      if (Object.prototype.hasOwnProperty.call(wireValue, field)) {
        attributes[attribute] = wireValue[field];
        break;
      }
    }
  }
  return attributes;
}

function decodedAttributeMatchesType(value, type) {
  if (type === 'string' || type === 'keyword' || type === 'datetime') {
    return typeof value === 'string';
  }
  if (type === 'int') {
    return Number.isInteger(value);
  }
  if (type === 'double') {
    return typeof value === 'number' && Number.isFinite(value);
  }
  if (type === 'bool') {
    return typeof value === 'boolean';
  }
  if (type === 'keyword_list') {
    return Array.isArray(value) && value.every((item) => typeof item === 'string');
  }
  return nonEmptyValue(value);
}

function codecValuesMatch(actual, expected, type) {
  if (type === 'keyword_list') {
    return Array.isArray(actual)
      && Array.isArray(expected)
      && actual.length === expected.length
      && actual.every((item, index) => item === expected[index]);
  }
  return actual === expected;
}

function codecEntryHasReaderEvidence(entry, reader) {
  const readers = firstArrayField(entry, ['reader_verifications', 'readerVerifications', 'readers', 'verified_readers', 'verifiedReaders']) || {};
  if (Object.prototype.hasOwnProperty.call(readers, reader) && nonEmptyValue(readers[reader])) {
    return true;
  }
  return Object.values(readers).some((value) => stringValue(value) === reader);
}

function codecRoundTripEvidenceFailures(result, scenarioResult, scenarioId, direction) {
  const section = sectionValue(result, 'codec_round_trips') || {};
  const outputs = scenarioOutputs(scenarioResult);
  const entry = arrayValue(section, direction)
    || arrayValue(section, camelize(direction))
    || arrayValue(outputs, direction)
    || arrayValue(outputs, camelize(direction))
    || outputs;
  const failures = [];
  if (!hasNonEmptyField(entry, [
    'encoded_payload',
    'encodedPayload',
    'codec_payload',
    'codecPayload',
    'wire_value_context',
    'wireValueContext',
    'wire_values',
    'wireValues',
    'wire_value',
    'wireValue',
    'wire_context',
    'wireContext',
  ])) {
    failures.push({ code: 'missing_codec_round_trip_field', scenario_id: scenarioId, field: 'encoded_payload_or_wire_value_context' });
  }
  const decoded = firstArrayField(entry, ['decoded_attributes', 'decodedAttributes', 'attributes']);
  const expected = codecExpectedAttributes(entry);
  const schemaTypes = TOPOLOGY.schema_keys;
  if (decoded === null || !nonEmptyValue(decoded)) {
    failures.push({ code: 'missing_codec_round_trip_field', scenario_id: scenarioId, field: 'decoded_attributes' });
  } else {
    for (const [attribute, type] of Object.entries(schemaTypes)) {
      if (!Object.prototype.hasOwnProperty.call(decoded, attribute)) {
        failures.push({ code: 'missing_codec_decoded_attribute', scenario_id: scenarioId, attribute });
        continue;
      }
      if (!decodedAttributeMatchesType(decoded[attribute], type)) {
        failures.push({ code: 'codec_decoded_attribute_type_mismatch', scenario_id: scenarioId, attribute, expected_type: type });
      }
      if (!Object.prototype.hasOwnProperty.call(expected, attribute)) {
        failures.push({ code: 'missing_codec_expected_attribute', scenario_id: scenarioId, attribute });
        continue;
      }
      if (!codecValuesMatch(decoded[attribute], expected[attribute], type)) {
        failures.push({ code: 'codec_decoded_attribute_value_mismatch', scenario_id: scenarioId, attribute, expected_type: type });
      }
    }
  }
  const requiredReaders = scenarioId === 'python_to_php_codec_round_trip'
    ? ['sdk-php', 'cli']
    : ['sdk-python', 'cli'];
  for (const reader of requiredReaders) {
    if (!codecEntryHasReaderEvidence(entry, reader)) {
      failures.push({ code: 'missing_codec_reader_evidence', scenario_id: scenarioId, reader });
    }
  }
  return failures;
}

function queryVerdictFailures(section) {
  const queries = arrayValue(section, 'queries') || section;
  const failures = [];
  for (const [queryClass, requiredQuery] of Object.entries(REQUIRED_QUERIES)) {
    const verdict = arrayValue(queries, queryClass) || {};
    if (!nonEmptyValue(verdict)) {
      failures.push({ code: 'missing_query_verdict', query_class: queryClass });
      continue;
    }
    const queryText = queryVerdictText(verdict);
    if (queryText === '') {
      failures.push({ code: 'missing_query_verdict_query', query_class: queryClass, query: requiredQuery });
    } else if (!exactProbeEvidenceMatches(queryText, requiredQuery)) {
      failures.push({
        code: 'query_verdict_query_mismatch',
        query_class: queryClass,
        expected_query: requiredQuery,
        actual_query: queryText,
      });
    }
    failures.push(...queryCountFailures(verdict, queryClass, ''));
    if (['or', 'not'].includes(queryClass)) {
      failures.push(...queryPublicSurfaceFailures(verdict, queryClass));
    }
  }
  return failures;
}

function queryPublicSurfaceFailures(verdict, queryClass) {
  const failures = [];
  if (!hasNonPlaceholderField(verdict, [
    'public_surface',
    'publicSurface',
    'surface',
    'observed_surface',
    'observedSurface',
  ])) {
    failures.push({ code: 'missing_query_public_surface', query_class: queryClass, field: 'public_surface' });
  }
  if (!hasNonEmptyField(verdict, ['arguments', 'args', 'argv'])) {
    failures.push({ code: 'missing_query_public_surface', query_class: queryClass, field: 'arguments' });
  }
  return failures;
}

function latencyAndLoadContractEvidenceFailures(section, scenarioId, codePrefix, requiredObservedBoundFields) {
  const failures = [];

  if (!hasNonPlaceholderField(section, [
    'consistency_contract',
    'consistencyContract',
    'user_visible_consistency_contract',
    'userVisibleConsistencyContract',
  ])) {
    failures.push({ code: `missing_${codePrefix}_consistency_contract`, scenario_id: scenarioId });
  }

  const observedBounds = firstArrayField(section, ['observed_bounds', 'observedBounds']);
  if (observedBounds === null || !nonEmptyValue(observedBounds)) {
    failures.push({ code: `missing_${codePrefix}_observed_bounds`, scenario_id: scenarioId });
  } else {
    for (const field of requiredObservedBoundFields) {
      if (!hasNumericField(observedBounds, [field, camelize(field)])) {
        failures.push({ code: `missing_${codePrefix}_observed_bound_field`, scenario_id: scenarioId, field });
      }
    }
  }

  const surfaces = (stringArrayField(section, [
    'public_observation_surfaces',
    'publicObservationSurfaces',
    'observation_surfaces',
    'observationSurfaces',
  ]) || []).filter((surface) => !isPlaceholderEvidence(surface));
  if (surfaces.length === 0) {
    failures.push({ code: `missing_${codePrefix}_public_observation_surfaces`, scenario_id: scenarioId });
  }

  return failures;
}

function waterlineEvidenceSection(section) {
  if (!isObject(section)) {
    return {};
  }
  for (const field of [
    'waterline_operator_visibility',
    'waterlineOperatorVisibility',
    'waterline_search_attribute_visibility',
    'waterlineSearchAttributeVisibility',
    'observed_outputs',
    'observedOutputs',
  ]) {
    const nested = arrayValue(section, field);
    if (nested !== null && nonEmptyValue(nested)) {
      return waterlineEvidenceSection(nested);
    }
  }
  return section;
}

function addMissingWaterlineFields(failures, fields) {
  for (const field of fields) {
    failures.push({ code: 'missing_waterline_operator_visibility_field', field });
  }
}

function waterlineEvidenceFailures(section) {
  section = waterlineEvidenceSection(section);
  const failures = [];

  const workflowList = firstArrayField(section, ['workflow_list_filter', 'workflowListFilter']);
  if (workflowList === null) {
    addMissingWaterlineFields(failures, [
      'workflow_list_filter.expected_count',
      'workflow_list_filter.actual_count',
    ]);
  } else {
    const expectedCount = numericField(workflowList, ['expected_count', 'expectedCount']);
    const actualCount = numericField(workflowList, ['actual_count', 'actualCount']);
    if (expectedCount === null) {
      addMissingWaterlineFields(failures, ['workflow_list_filter.expected_count']);
    }
    if (actualCount === null) {
      addMissingWaterlineFields(failures, ['workflow_list_filter.actual_count']);
    }
    if (expectedCount !== null && expectedCount <= 0) {
      failures.push({
        code: 'waterline_workflow_list_filter_empty',
        field: 'workflow_list_filter.expected_count',
        expected_count: expectedCount,
      });
    }
    if (expectedCount !== null && actualCount !== null && Number(expectedCount) !== Number(actualCount)) {
      failures.push({
        code: 'waterline_workflow_list_filter_count_mismatch',
        field: 'workflow_list_filter',
        expected_count: expectedCount,
        actual_count: actualCount,
      });
    }

    const expectedRunIds = stringArrayField(workflowList, ['expected_run_ids', 'expectedRunIds']);
    const actualRunIds = stringArrayField(workflowList, ['actual_run_ids', 'actualRunIds']);
    if (expectedRunIds !== null && actualRunIds !== null && !sameStringSet(expectedRunIds, actualRunIds)) {
      failures.push({
        code: 'waterline_workflow_list_filter_run_id_mismatch',
        field: 'workflow_list_filter.actual_run_ids',
        expected_run_ids: expectedRunIds,
        actual_run_ids: actualRunIds,
      });
    }
  }

  const selectedRun = firstArrayField(section, ['selected_run_detail', 'selectedRunDetail']);
  if (selectedRun === null) {
    addMissingWaterlineFields(failures, [
      'selected_run_detail.expected_search_attributes',
      'selected_run_detail.actual_search_attributes',
    ]);
  } else {
    const expectedAttributes = firstArrayField(selectedRun, ['expected_search_attributes', 'expectedSearchAttributes']);
    const actualAttributes = firstArrayField(selectedRun, ['actual_search_attributes', 'actualSearchAttributes']);
    if (expectedAttributes === null || !nonEmptyValue(expectedAttributes)) {
      addMissingWaterlineFields(failures, ['selected_run_detail.expected_search_attributes']);
    }
    if (actualAttributes === null || !nonEmptyValue(actualAttributes)) {
      addMissingWaterlineFields(failures, ['selected_run_detail.actual_search_attributes']);
    }
    if (expectedAttributes !== null && nonEmptyValue(expectedAttributes) && actualAttributes !== null) {
      for (const [attribute, expectedValue] of Object.entries(expectedAttributes)) {
        if (!Object.prototype.hasOwnProperty.call(actualAttributes, attribute)) {
          failures.push({
            code: 'missing_waterline_selected_run_attribute',
            field: 'selected_run_detail.actual_search_attributes',
            attribute,
          });
          continue;
        }
        if (!waterlineValuesMatch(actualAttributes[attribute], expectedValue)) {
          failures.push({
            code: 'waterline_selected_run_attribute_mismatch',
            field: 'selected_run_detail.actual_search_attributes',
            attribute,
          });
        }
      }
    }

    if (boolField(selectedRun, ['expected_attributes_visible', 'expectedAttributesVisible']) === false) {
      failures.push({
        code: 'waterline_selected_run_attributes_not_visible',
        field: 'selected_run_detail.expected_attributes_visible',
      });
    }
  }

  const savedFilter = firstArrayField(section, ['saved_filter_state', 'savedFilterState']);
  if (savedFilter === null) {
    addMissingWaterlineFields(failures, [
      'saved_filter_state.stored_filters',
      'saved_filter_state.retrieved_filters',
    ]);
  } else {
    const storedFilters = firstArrayField(savedFilter, ['stored_filters', 'storedFilters']);
    const retrievedFilters = firstArrayField(savedFilter, ['retrieved_filters', 'retrievedFilters']);
    if (storedFilters === null || !nonEmptyValue(storedFilters)) {
      addMissingWaterlineFields(failures, ['saved_filter_state.stored_filters']);
    }
    if (retrievedFilters === null || !nonEmptyValue(retrievedFilters)) {
      addMissingWaterlineFields(failures, ['saved_filter_state.retrieved_filters']);
    }
    if (storedFilters !== null && nonEmptyValue(storedFilters) && retrievedFilters !== null
      && !waterlineValuesMatch(retrievedFilters, storedFilters)) {
      failures.push({
        code: 'waterline_saved_filter_round_trip_mismatch',
        field: 'saved_filter_state.retrieved_filters',
      });
    }

    for (const [field, aliases] of Object.entries({
      filter_preserved_on_retrieval: ['filter_preserved_on_retrieval', 'filterPreservedOnRetrieval'],
      filter_preserved_on_list_retrieval: ['filter_preserved_on_list_retrieval', 'filterPreservedOnListRetrieval'],
      applied_filter_matched: ['applied_filter_matched', 'appliedFilterMatched'],
    })) {
      if (boolField(savedFilter, aliases) === false) {
        failures.push({ code: 'waterline_saved_filter_round_trip_mismatch', field: `saved_filter_state.${field}` });
      }
    }
  }

  const namespaceIsolation = firstArrayField(section, ['namespace_isolation', 'namespaceIsolation']);
  if (namespaceIsolation === null) {
    addMissingWaterlineFields(failures, [
      'namespace_isolation.tenant_a_filter_actual_run_ids',
      'namespace_isolation.tenant_b_filter_actual_run_ids',
    ]);
  } else {
    const tenantAActual = stringArrayField(namespaceIsolation, [
      'tenant_a_filter_actual_run_ids',
      'tenantAFilterActualRunIds',
    ]);
    const tenantBActual = stringArrayField(namespaceIsolation, [
      'tenant_b_filter_actual_run_ids',
      'tenantBFilterActualRunIds',
    ]);

    if (tenantAActual === null) {
      addMissingWaterlineFields(failures, ['namespace_isolation.tenant_a_filter_actual_run_ids']);
    } else if (tenantAActual.length === 0) {
      failures.push({
        code: 'waterline_namespace_isolation_empty_run_ids',
        field: 'namespace_isolation.tenant_a_filter_actual_run_ids',
      });
    }

    if (tenantBActual === null) {
      addMissingWaterlineFields(failures, ['namespace_isolation.tenant_b_filter_actual_run_ids']);
    } else if (tenantBActual.length === 0) {
      failures.push({
        code: 'waterline_namespace_isolation_empty_run_ids',
        field: 'namespace_isolation.tenant_b_filter_actual_run_ids',
      });
    }

    if (tenantAActual !== null && tenantBActual !== null
      && tenantAActual.some((runId) => tenantBActual.includes(runId))) {
      failures.push({
        code: 'waterline_namespace_isolation_run_id_overlap',
        field: 'namespace_isolation',
        tenant_a_filter_actual_run_ids: tenantAActual,
        tenant_b_filter_actual_run_ids: tenantBActual,
      });
    }

    for (const [field, aliases] of Object.entries({
      tenant_a_filter_expected_run_ids: {
        expected: ['tenant_a_filter_expected_run_ids', 'tenantAFilterExpectedRunIds'],
        actual: ['tenant_a_filter_actual_run_ids', 'tenantAFilterActualRunIds'],
      },
      tenant_b_filter_expected_run_ids: {
        expected: ['tenant_b_filter_expected_run_ids', 'tenantBFilterExpectedRunIds'],
        actual: ['tenant_b_filter_actual_run_ids', 'tenantBFilterActualRunIds'],
      },
    })) {
      const expectedIds = stringArrayField(namespaceIsolation, aliases.expected);
      const actualIds = stringArrayField(namespaceIsolation, aliases.actual);
      if (expectedIds !== null && actualIds !== null && !sameStringSet(expectedIds, actualIds)) {
        failures.push({
          code: 'waterline_namespace_isolation_run_id_mismatch',
          field: `namespace_isolation.${field.replace('_expected_', '_actual_')}`,
          expected_run_ids: expectedIds,
          actual_run_ids: actualIds,
        });
      }
    }

    for (const [field, aliases] of Object.entries({
      tenant_a_excludes_tenant_b: ['tenant_a_excludes_tenant_b', 'tenantAExcludesTenantB'],
      tenant_b_excludes_tenant_a: ['tenant_b_excludes_tenant_a', 'tenantBExcludesTenantA'],
      tenant_b_filter_matched: ['tenant_b_filter_matched', 'tenantBFilterMatched'],
    })) {
      if (boolField(namespaceIsolation, aliases) === false) {
        failures.push({ code: 'waterline_namespace_isolation_failed', field: `namespace_isolation.${field}` });
      }
    }
  }

  const captures = firstArrayField(section, ['api_captures', 'apiCaptures']);
  if (captures === null || !nonEmptyValue(captures)) {
    addMissingWaterlineFields(failures, ['api_captures']);
  } else {
    for (const capture of [
      'workflow_list_customer_filter',
      'workflow_list_keyword_list_filter',
      'selected_run_detail',
      'saved_view_show',
      'saved_view_list',
      'saved_view_applied_workflow_list',
      'foreign_namespace_workflow_list',
    ]) {
      const captureEvidence = firstArrayField(captures, [capture, camelize(capture)]);
      if (captureEvidence === null) {
        failures.push({ code: 'missing_waterline_api_capture', field: `api_captures.${capture}` });
        continue;
      }

      const status = numericField(captureEvidence, ['status', 'status_code', 'statusCode']);
      if (status === null) {
        addMissingWaterlineFields(failures, [`api_captures.${capture}.status`]);
      } else if (Number(status) !== 200) {
        failures.push({
          code: 'waterline_api_capture_status_mismatch',
          field: `api_captures.${capture}.status`,
          status,
        });
      }
    }
  }

  const surfaceMatrix = firstArrayField(section, ['operator_surface_matrix', 'operatorSurfaceMatrix']);
  if (surfaceMatrix === null) {
    failures.push({ code: 'missing_waterline_operator_surface_matrix', field: 'operator_surface_matrix' });
  } else {
    for (const surface of [
      'workflow_list_search_attribute_filter',
      'keyword_list_search_attribute_filter',
      'selected_run_search_attributes',
      'saved_filter_round_trip',
      'namespace_scoped_visibility',
    ]) {
      if (boolField(surfaceMatrix, [surface, camelize(surface)]) !== true) {
        failures.push({ code: 'waterline_operator_surface_not_proved', field: `operator_surface_matrix.${surface}` });
      }
    }
  }

  return failures;
}

function evaluateResultGate(result) {
  const failures = [];
  const duplicateScenarioCounts = {};
  const scenarioResults = scenarioResultsById(result, duplicateScenarioCounts);
  const scenarioStatuses = {};
  const missingScenarios = [];
  const nonPassScenarios = [];

  for (const [scenarioId, count] of Object.entries(duplicateScenarioCounts)) {
    failures.push({ code: 'duplicate_scenario_result', scenario_id: scenarioId, count });
  }

  for (const scenarioId of REQUIRED_SCENARIOS) {
    if (!Object.prototype.hasOwnProperty.call(scenarioResults, scenarioId)) {
      missingScenarios.push(scenarioId);
      failures.push({ code: 'missing_required_scenario', scenario_id: scenarioId });
      continue;
    }

    const scenarioResult = scenarioResults[scenarioId];
    const status = stringValue(scenarioResult.status);
    scenarioStatuses[scenarioId] = status;
    if (!ALLOWED_STATUSES.includes(status)) {
      failures.push({ code: 'invalid_scenario_status', scenario_id: scenarioId, status, allowed_statuses: ALLOWED_STATUSES });
      continue;
    }

    if (status === 'pass') {
      if (!hasObservedOutputs(scenarioResult)) {
        failures.push({ code: 'missing_pass_observed_outputs', scenario_id: scenarioId });
      }
    } else {
      nonPassScenarios.push(scenarioId);
      if (!hasLinkedFindings(scenarioResult, result)) {
        failures.push({ code: 'missing_non_pass_finding', scenario_id: scenarioId, status });
      }
    }
  }

  const unknownScenarios = Object.keys(scenarioResults).filter((scenarioId) => !REQUIRED_SCENARIOS.includes(scenarioId));
  for (const scenarioId of unknownScenarios) {
    const status = stringValue(scenarioResults[scenarioId].status);
    if (!ALLOWED_STATUSES.includes(status)) {
      failures.push({ code: 'invalid_extra_scenario_status', scenario_id: scenarioId, status, allowed_statuses: ALLOWED_STATUSES });
    }
  }

  for (const field of REQUIRED_RUN_RECORD_FIELDS) {
    if (!hasRunRecordField(result, field)) {
      failures.push({ code: 'missing_run_record_field', field });
    }
  }

  const declaredOutcomes = {};
  for (const field of ['outcome', 'status', 'verdict']) {
    const value = stringValue(result[field]);
    if (value !== '') {
      declaredOutcomes[field] = value;
      if (!ALLOWED_OUTCOMES.includes(value)) {
        failures.push({ code: 'invalid_declared_outcome', field, outcome: value, allowed_outcomes: ALLOWED_OUTCOMES });
      }
    }
  }
  const runnerBlocked = runnerBlockedValue(result);
  if (runnerBlocked !== null && runnerBlocked !== false) {
    failures.push({ code: 'runner_blocked_result_is_not_product_evidence' });
  }

  const versions = resultArtifactVersions(result);
  for (const artifact of REQUIRED_ARTIFACTS) {
    const version = artifactVersionValue(versions, artifact);
    if (version === '') {
      failures.push({ code: 'missing_artifact_version', artifact });
      continue;
    }
    if (isPlaceholderVersion(version)) {
      failures.push({ code: 'placeholder_artifact_version', artifact, version });
    } else if (artifact === 'server' && !isExactSemverRelease(version)) {
      failures.push({ code: 'invalid_server_artifact_version', artifact, version });
    }
  }

  for (const [artifact, source] of Object.entries(resultArtifactSources(result))) {
    if (['local_product_source_checkout', 'workspace_repo_as_artifact_under_test'].includes(stringValue(source))) {
      failures.push({ code: 'forbidden_artifact_source', artifact, source: stringValue(source) });
    }
  }

  const matrix = sectionValue(result, 'runtime_matrix') || {};
  for (const runtime of ['sdk-php', 'sdk-python']) {
    if (!matrixHasRuntime(matrix, runtime)) {
      failures.push({ code: 'missing_required_runtime', runtime });
    }
  }
  for (const cell of REQUIRED_RUNTIME_CELLS) {
    if (!matrixHasCell(matrix, 'runtime_cells', cell)) {
      failures.push({ code: 'missing_required_matrix_cell', cell_group: 'runtime_cells', scenario: cell.scenario, worker: cell.worker, clients: cell.clients });
    }
  }
  for (const cell of REQUIRED_CROSS_LANGUAGE_CELLS) {
    if (!matrixHasCell(matrix, 'cross_language_cells', cell)) {
      failures.push({ code: 'missing_required_matrix_cell', cell_group: 'cross_language_cells', scenario: cell.scenario, worker: cell.writer, clients: cell.readers });
    }
  }

  for (const [section, scenarios] of Object.entries(REQUIRED_SECTIONS)) {
    const value = sectionValue(result, section);
    if (value !== null && nonEmptyValue(value)) {
      continue;
    }
    const coveredByScenarioOutputs = scenarios.every((scenarioId) => {
      return Object.prototype.hasOwnProperty.call(scenarioResults, scenarioId)
        && hasObservedOutputs(scenarioResults[scenarioId]);
    });
    if (!coveredByScenarioOutputs) {
      failures.push({ code: 'missing_required_evidence_section', section, scenarios });
    }
  }

  if (scenarioStatuses.published_artifact_install_only === 'pass') {
    failures.push(...publishedArtifactInstallEvidenceFailures(result, scenarioResults.published_artifact_install_only));
  }
  if (scenarioStatuses.schema_definition_and_reserved_name_refusal === 'pass') {
    failures.push(...schemaDefinitionEvidenceFailures(result, scenarioResults.schema_definition_and_reserved_name_refusal));
  }
  for (const [scenarioId, runtime] of Object.entries({
    python_worker_start_and_upsert_visibility: 'sdk-python',
    php_worker_start_and_upsert_visibility: 'sdk-php',
  })) {
    if (scenarioStatuses[scenarioId] === 'pass') {
      failures.push(...workerVisibilityEvidenceFailures(scenarioId, scenarioResults[scenarioId], runtime));
    }
  }
  if (scenarioStatuses.cli_query_and_error_surface === 'pass') {
    failures.push(...cliSurfaceEvidenceFailures(scenarioEvidence(result, scenarioResults.cli_query_and_error_surface, 'cli_surface')));
  }
  for (const [scenarioId, direction] of Object.entries({
    python_to_php_codec_round_trip: 'python_to_php',
    php_to_python_codec_round_trip: 'php_to_python',
  })) {
    if (scenarioStatuses[scenarioId] === 'pass') {
      failures.push(...codecRoundTripEvidenceFailures(result, scenarioResults[scenarioId], scenarioId, direction));
    }
  }
  if (scenarioStatuses.indexing_latency_distribution === 'pass') {
    const section = sectionValue(result, 'latency_distribution') || {};
    if ((numericField(section, ['sample_count', 'sampleCount', 'samples']) || 0) < 20) {
      failures.push({ code: 'latency_sample_count_below_required', scenario_id: 'indexing_latency_distribution', required: 20 });
    }
    for (const field of ['min_ms', 'p50_ms', 'p95_ms', 'max_ms']) {
      if (!hasNumericField(section, [field, camelize(field)])) {
        failures.push({ code: 'missing_latency_distribution_field', scenario_id: 'indexing_latency_distribution', field });
      }
    }
    if (!hasNumericField(section, ['documented_bound_ms', 'documentedBoundMs'])) {
      failures.push({ code: 'missing_latency_documented_bound', scenario_id: 'indexing_latency_distribution' });
    }
    const documentedBoundMs = numericField(section, ['documented_bound_ms', 'documentedBoundMs']);
    if (documentedBoundMs !== null) {
      for (const field of ['p95_ms', 'max_ms']) {
        const latencyMs = numericField(section, [field, camelize(field)]);
        if (latencyMs !== null && latencyMs > documentedBoundMs) {
          failures.push({
            code: 'latency_distribution_exceeds_documented_bound',
            scenario_id: 'indexing_latency_distribution',
            field,
            actual_ms: latencyMs,
            documented_bound_ms: documentedBoundMs,
          });
        }
      }
    }
    failures.push(...latencyAndLoadContractEvidenceFailures(
      section,
      'indexing_latency_distribution',
      'latency',
      ['documented_bound_ms', 'p95_ms', 'max_ms'],
    ));
  }
  if (scenarioStatuses.load_and_bounded_latency === 'pass') {
    const section = sectionValue(result, 'load_profile') || {};
    if ((numericField(section, ['workflow_count', 'workflowCount', 'runs']) || 0) < 1000) {
      failures.push({ code: 'load_workflow_count_below_required', required: 1000 });
    }
    for (const field of ['p50_ms', 'p95_ms', 'max_ms']) {
      if (!hasNumericField(section, [field, camelize(field)])) {
        failures.push({ code: 'missing_load_latency_field', field });
      }
    }
    const queryLatencies = loadQueryLatencyProfiles(section);
    for (const queryClass of ['equality', 'range', 'bool', 'keyword_list']) {
      const profile = arrayValue(queryLatencies, queryClass) || {};
      if (Object.keys(profile).length === 0) {
        failures.push({ code: 'missing_load_query_latency_class', query_class: queryClass });
        continue;
      }
      for (const field of ['p50_ms', 'p95_ms', 'max_ms']) {
        if (!hasNumericField(profile, [field, camelize(field)])) {
          failures.push({ code: 'missing_load_query_latency_field', query_class: queryClass, field });
        }
      }
    }
    failures.push(...latencyAndLoadContractEvidenceFailures(
      section,
      'load_and_bounded_latency',
      'load',
      ['workflow_count', 'p50_ms', 'p95_ms', 'max_ms'],
    ));
  }
  if (['equality_range_bool_query_behavior', 'or_not_query_grammar', 'keyword_list_membership']
    .some((scenarioId) => scenarioStatuses[scenarioId] === 'pass')) {
    failures.push(...queryVerdictFailures(sectionValue(result, 'query_verdicts') || {}));
  }
  if (scenarioStatuses.query_injection_hardening === 'pass') {
    const section = sectionValue(result, 'adversarial_queries') || {};
    if (!hasTruthyField(section, ['injection_rejected', 'injectionRejected'])) {
      failures.push({ code: 'missing_injection_rejection_evidence' });
    }
    const rejections = firstArrayField(section, ['rejections', 'rejected_inputs', 'rejectedInputs']) || {};
    if (!nonEmptyValue(rejections)) {
      failures.push({ code: 'missing_injection_rejection_inputs' });
    }
    for (const probe of ['OR 1=1', 'embedded SQL comment', 'shell metacharacters']) {
      const rejection = injectionRejectionForProbe(rejections, probe);
      if (rejection === null) {
        failures.push({ code: 'missing_required_injection_rejection_probe', probe });
        continue;
      }
      failures.push(...injectionRejectionDiagnosticFailures(rejection, probe));
    }
    const partialExecution = boolField(section, ['partial_execution_observed', 'partialExecutionObserved']);
    if (partialExecution === null) {
      failures.push({ code: 'missing_partial_execution_evidence' });
    } else if (partialExecution) {
      failures.push({ code: 'query_injection_partially_executed' });
    }
  }
  if (scenarioStatuses.waterline_operator_visibility === 'pass') {
    failures.push(
      ...waterlineEvidenceFailures(
        scenarioEvidence(result, scenarioResults.waterline_operator_visibility, 'waterline_operator_visibility')
      )
    );
  }
  for (const [scenarioId, config] of Object.entries({
    type_safety_wrong_literal: { field: 'wrong_literal', aliases: ['wrong_literal', 'wrongLiteral'] },
    undefined_key_rejection: { field: 'undefined_key', aliases: ['undefined_key', 'undefinedKey'] },
  })) {
    if (scenarioStatuses[scenarioId] !== 'pass') {
      continue;
    }
    const section = sectionValue(result, 'type_safety_errors') || {};
    const outputs = scenarioOutputs(scenarioResults[scenarioId]);
    const entry = firstFieldValue(section, config.aliases)
      || firstFieldValue(outputs, config.aliases)
      || firstFieldValue(outputs, ['typed_error', 'typedError', 'error', 'rejection'])
      || (nonEmptyValue(outputs) ? outputs : null);
    if (entry === null || !validTypedErrorEvidence(entry)) {
      failures.push({ code: 'missing_type_safety_error_evidence', scenario_id: scenarioId, field: config.field });
      continue;
    }
    if (isObject(entry) && hasTruthyField(entry, ['accepted', 'coerced', 'coercion_observed', 'coercionObserved'])) {
      failures.push({ code: 'type_safety_probe_was_accepted', scenario_id: scenarioId, field: config.field });
    }
  }
  if (scenarioStatuses.namespace_isolation === 'pass') {
    const section = scenarioEvidence(result, scenarioResults.namespace_isolation, 'namespace_isolation');
    for (const [field, aliases] of Object.entries({
      primary_namespace: ['primary_namespace', 'primaryNamespace'],
      peer_namespace: ['peer_namespace', 'peerNamespace'],
    })) {
      if (!hasNonEmptyField(section, aliases)) {
        failures.push({ code: 'missing_namespace_isolation_field', scenario_id: 'namespace_isolation', field });
      }
    }
    for (const [field, aliases] of Object.entries({
      primary_query_count: ['primary_query_count', 'primaryQueryCount'],
      peer_query_count: ['peer_query_count', 'peerQueryCount'],
    })) {
      if (!hasNumericField(section, aliases)) {
        failures.push({ code: 'missing_namespace_isolation_field', scenario_id: 'namespace_isolation', field });
      }
    }
    const leakDetected = boolField(section, ['cross_namespace_leak_detected', 'crossNamespaceLeakDetected']);
    if (leakDetected === null) {
      failures.push({ code: 'missing_namespace_isolation_field', scenario_id: 'namespace_isolation', field: 'cross_namespace_leak_detected' });
    } else if (leakDetected) {
      failures.push({ code: 'namespace_isolation_leak_detected', scenario_id: 'namespace_isolation' });
    }
  }

  const coveredPassScenarios = Object.entries(scenarioStatuses)
    .filter(([, status]) => status === 'pass')
    .map(([scenarioId]) => scenarioId);
  const smokeSubsetDetected = Object.keys(scenarioStatuses).length < REQUIRED_SCENARIOS.length
    && coveredPassScenarios.length > 0
    && coveredPassScenarios.every((scenarioId) => !FULL_PARITY_SCENARIOS.includes(scenarioId));
  if (smokeSubsetDetected) {
    failures.push({ code: 'smoke_subset_cannot_pass', reason: 'Python/server smoke coverage is not a complete search-attributes conformance result.' });
  }

  const evidencePasses = failures.length === 0
    && missingScenarios.length === 0
    && nonPassScenarios.length === 0
    && Object.keys(scenarioStatuses).length >= REQUIRED_SCENARIOS.length;
  const evaluatedStatus = evidencePasses ? 'pass' : 'non_passing';
  for (const [field, outcome] of Object.entries(declaredOutcomes)) {
    if (!ALLOWED_OUTCOMES.includes(outcome)) {
      continue;
    }
    const declaredStatus = outcome === 'pass' ? 'pass' : 'non_passing';
    if (declaredStatus !== evaluatedStatus) {
      failures.push({
        code: 'declared_outcome_status_mismatch',
        field,
        outcome,
        declared_status: declaredStatus,
        evaluated_status: evaluatedStatus,
      });
    }
  }

  return {
    schema: RESULT_GATE_SCHEMA,
    version: RESULT_GATE_VERSION,
    status: evaluatedStatus === 'pass' && failures.length === 0 ? 'pass' : 'non_passing',
    required_scenarios: REQUIRED_SCENARIOS,
    reported_scenarios: Object.keys(scenarioResults),
    missing_scenarios: missingScenarios,
    non_pass_scenarios: nonPassScenarios,
    unknown_scenarios: unknownScenarios,
    duplicate_scenarios: duplicateScenarioCounts,
    scenario_statuses: scenarioStatuses,
    smoke_subset_detected: smokeSubsetDetected,
    gate_failures: failures,
  };
}

function applyGateEvaluation(result, versions) {
  let evaluation = evaluateResultGate(result);
  if (evaluation.status === 'pass') {
    result.result_gate = evaluation;
    return evaluation;
  }

  const suppliedPass = stringValue(result.outcome || result.status || result.verdict) === 'pass';
  let reevaluate = false;
  if (suppliedPass) {
    result.outcome = 'non_passing';
    reevaluate = true;
  }

  if (evaluation.missing_scenarios.length > 0) {
    const reason = 'supplied result omitted required scenario_results entries';
    ensureMissingScenarioResults(result, evaluation.missing_scenarios, reason, versions);
    reevaluate = true;
  }

  if (reevaluate) {
    evaluation = evaluateResultGate(result);
  }

  result.result_gate = evaluation;
  return evaluation;
}

function normalizeResult(result, startedAt, finishedAt, versions) {
  const normalized = { ...result };
  normalized.schema = normalized.schema || SCHEMA;
  normalized.run_id = normalized.run_id || normalized.runId || defaultRunId(startedAt);
  normalized.started_at = normalized.started_at || startedAt;
  normalized.finished_at = normalized.finished_at || finishedAt;
  normalized.generated_at = normalized.generated_at || finishedAt;
  normalized.outcome = normalized.outcome || 'non_passing';
  normalized.runner_blocked = Boolean(normalized.runner_blocked || normalized.runnerBlocked || false);
  normalized.artifactVersions = normalized.artifactVersions || versions;
  normalized.published_artifact_versions = normalized.published_artifact_versions || versions;
  normalized.artifact_sources = normalized.artifact_sources || artifactSources();
  normalized.runtime_matrix = normalized.runtime_matrix || RUNTIME_MATRIX;
  normalized.topology = normalized.topology || TOPOLOGY;
  normalized.query_verdicts = normalized.query_verdicts || {};
  normalized.codec_round_trips = normalized.codec_round_trips || {};
  normalized.latency_distribution = normalized.latency_distribution || {};
  normalized.load_profile = normalized.load_profile || {};
  normalized.type_safety_errors = normalized.type_safety_errors || {};
  normalized.adversarial_queries = normalized.adversarial_queries || {};
  normalized.namespace_isolation = normalized.namespace_isolation || {};
  normalized.waterline_operator_visibility = normalized.waterline_operator_visibility || {};
  normalized.cli_surface = normalized.cli_surface || {};
  normalized.findings = Array.isArray(normalized.findings) ? normalized.findings : [];
  normalized.finding_links = normalized.finding_links || {};
  return normalized;
}

function blockedResult(reason, startedAt, finishedAt, versions) {
  const findings = REQUIRED_SCENARIOS.map((scenarioId) => findingFor(scenarioId, reason, versions));
  return {
    schema: SCHEMA,
    run_id: defaultRunId(startedAt),
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifactVersions: versions,
    published_artifact_versions: versions,
    artifact_sources: artifactSources(),
    runtime_matrix: RUNTIME_MATRIX,
    topology: TOPOLOGY,
    query_verdicts: {},
    type_safety_errors: {},
    latency_distribution: {},
    load_profile: {},
    waterline_operator_visibility: {
      status: 'runner_blocked',
      blocked_reason: reason,
    },
    cli_surface: {},
    codec_round_trips: {},
    namespace_isolation: {},
    adversarial_queries: {},
    scenario_results: REQUIRED_SCENARIOS.map((scenarioId) => scenarioResult(scenarioId, reason, versions)),
    findings,
    finding_links: Object.fromEntries(findings.map((finding) => [finding.scenario_id, [finding]])),
  };
}

function partialCoverageResult(reason, startedAt, finishedAt, versions) {
  const findings = REQUIRED_SCENARIOS.map((scenarioId) => coverageGapFindingFor(scenarioId, reason, versions));
  return {
    schema: SCHEMA,
    run_id: defaultRunId(startedAt),
    outcome: 'non_passing',
    runner_blocked: false,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifactVersions: versions,
    published_artifact_versions: versions,
    artifact_sources: artifactSources(),
    runtime_matrix: RUNTIME_MATRIX,
    topology: TOPOLOGY,
    query_verdicts: {},
    type_safety_errors: {},
    latency_distribution: {},
    load_profile: {},
    waterline_operator_visibility: {},
    cli_surface: {},
    codec_round_trips: {},
    namespace_isolation: {},
    adversarial_queries: {},
    scenario_results: REQUIRED_SCENARIOS.map((scenarioId) => notCoveredScenarioResult(scenarioId, reason, versions)),
    findings,
    finding_links: Object.fromEntries(findings.map((finding) => [finding.scenario_id, [finding]])),
  };
}

function statusForShardEntry(entry, shard) {
  const status = stringValue(entry.status || entry.outcome || entry.verdict || shard.status || shard.outcome || shard.verdict);
  if (ALLOWED_STATUSES.includes(status)) {
    return status;
  }
  if (status === 'non_passing' || status === 'non_passing_with_root_cause_finding') {
    return 'fail';
  }
  if (status === 'non_passing_runner_blocked') {
    return 'runner_blocked';
  }
  return 'pass';
}

function codecEntryFromShard(shard, direction, scenarioId) {
  const section = firstArrayField(shard, ['codec_round_trips', 'codecRoundTrips', 'round_trips', 'roundTrips']) || shard;
  const direct = arrayValue(section, direction)
    || arrayValue(section, camelize(direction))
    || arrayValue(shard, direction)
    || arrayValue(shard, camelize(direction));
  if (direct !== null) {
    return direct;
  }
  const shardScenarioId = stringValue(shard.scenario_id || shard.scenarioId || shard.id);
  if (shardScenarioId === scenarioId) {
    return section;
  }
  return null;
}

function shardLinkedFindings(entry, shard, scenarioId, status, versions) {
  const supplied = firstArrayField(entry, ['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'])
    || firstArrayField(shard, ['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks']);
  if (supplied !== null && nonEmptyValue(supplied)) {
    return Array.isArray(supplied) ? supplied : Object.values(supplied);
  }
  if (status === 'pass') {
    return [];
  }
  return [coverageGapFindingFor(
    scenarioId,
    'codec round-trip shard reported a non-pass status without a linked root-cause finding',
    versions
  )];
}

function sdkPhpUnsupportedPublicSurfaceFinding(reason, versions) {
  return {
    id: 'unsupported-public-surface-php-worker-start-and-upsert-visibility',
    scenario_id: 'php_worker_start_and_upsert_visibility',
    finding_type: 'unsupported_public_surface',
    owner: 'sdk-php',
    owning_surface: 'sdk-php',
    required_execution_scope: 'sdk-php-search-attribute-shard',
    artifact_versions: versions,
    observed_behavior: `published durable-workflow/sdk search-attribute evidence was unavailable: ${reason}`,
    expected_behavior: 'the exact Packagist PHP SDK runs the published search-attribute scenario and emits sdk-php client and worker evidence',
    next_acceptance_criterion: 'run the published PHP SDK conformance cell and attach its search-attribute shard without using local product source',
    priority: 'P1',
    diagnostic: {
      step: 'sdk_conformance_runner_missing',
      command: 'scripts/conformance/php-sdk-published-artifacts.sh',
    },
  };
}

function sdkPhpScenarioFromShard(shard) {
  const scenarioId = 'php_worker_start_and_upsert_visibility';
  const scenarioResults = scenarioResultsById({ scenario_results: shard.scenario_results || shard.scenarioResults || {} });
  if (isObject(scenarioResults[scenarioId])) {
    return scenarioResults[scenarioId];
  }
  if (stringValue(shard.scenario_id || shard.scenarioId || shard.id) === scenarioId) {
    return shard;
  }
  return null;
}

function sdkPhpCommandMissing(reason) {
  const value = stringValue(reason).toLowerCase();
  return value.includes('php-sdk-published-artifacts')
    || value.includes('sdk_conformance_runner_missing')
    || value.includes('sdk conformance runner missing');
}

function sdkPhpLinkedFindings(entry, shard, status, versions, reason) {
  const supplied = firstArrayField(entry, ['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks'])
    || firstArrayField(shard, ['linked_findings', 'linkedFindings', 'finding_links', 'findingLinks']);
  if (supplied !== null && nonEmptyValue(supplied)) {
    return Array.isArray(supplied) ? supplied : Object.values(supplied);
  }
  if (status === 'pass') {
    return [];
  }
  if (status === 'unsupported' || sdkPhpCommandMissing(reason)) {
    return [sdkPhpUnsupportedPublicSurfaceFinding(reason, versions)];
  }
  return [coverageGapFindingFor(
    'php_worker_start_and_upsert_visibility',
    'PHP SDK shard reported a non-pass status without a linked root-cause finding',
    versions
  )];
}

function mergeSdkPhpShard(result, shard, versions, reason) {
  const scenarioId = 'php_worker_start_and_upsert_visibility';
  const entry = sdkPhpScenarioFromShard(shard);
  if (!isObject(entry)) {
    return;
  }
  const status = statusForShardEntry(entry, shard);
  const observedOutputs = firstArrayField(entry, ['observed_outputs', 'observedOutputs'])
    || firstArrayField(shard, ['observed_outputs', 'observedOutputs'])
    || {};
  const linkedFindings = sdkPhpLinkedFindings(entry, shard, status, versions, reason);
  const existing = scenarioResultsById(result);
  clearFindingsForScenario(result, scenarioId);
  existing[scenarioId] = {
    scenario_id: scenarioId,
    status,
    observed_outputs: observedOutputs,
    linked_findings: linkedFindings,
  };
  result.scenario_results = existing;
  for (const finding of linkedFindings) {
    if (isObject(finding)) {
      mergeFinding(result, finding);
    }
  }
}

function mergeCodecShard(result, shard, versions) {
  if (!isObject(result.codec_round_trips)) {
    result.codec_round_trips = {};
  }
  const existing = scenarioResultsById(result);
  for (const [direction, scenarioId] of Object.entries({
    python_to_php: 'python_to_php_codec_round_trip',
    php_to_python: 'php_to_python_codec_round_trip',
  })) {
    const entry = codecEntryFromShard(shard, direction, scenarioId);
    if (!isObject(entry)) {
      continue;
    }
    const status = statusForShardEntry(entry, shard);
    const linkedFindings = shardLinkedFindings(entry, shard, scenarioId, status, versions);
    clearFindingsForScenario(result, scenarioId);
    result.codec_round_trips[direction] = entry;
    existing[scenarioId] = {
      scenario_id: scenarioId,
      status,
      observed_outputs: {
        [direction]: entry,
      },
      linked_findings: linkedFindings,
    };
    for (const finding of linkedFindings) {
      if (isObject(finding)) {
        mergeFinding(result, finding);
      }
    }
  }
  result.scenario_results = existing;
}

function sdkPhpUnsupportedShard(reason, versions) {
  const finding = sdkPhpUnsupportedPublicSurfaceFinding(reason, versions);
  return {
    schema: 'durable-workflow.v2.search-attribute-runtime.sdk-php-shard',
    scenario_id: 'php_worker_start_and_upsert_visibility',
    status: 'unsupported',
    runner_blocked: false,
    artifact_versions: versions,
    observed_outputs: {
      shard_command: 'scripts/conformance/php-sdk-published-artifacts.sh',
      sdk_conformance_runner_missing: true,
      required_execution_scope: 'sdk-php-search-attribute-shard',
      published_artifacts_only: true,
    },
    scenario_results: {
      php_worker_start_and_upsert_visibility: {
        scenario_id: 'php_worker_start_and_upsert_visibility',
        status: 'unsupported',
        observed_outputs: {
          shard_command: 'scripts/conformance/php-sdk-published-artifacts.sh',
          sdk_conformance_runner_missing: true,
          missing_reason: reason,
          required_execution_scope: 'sdk-php-search-attribute-shard',
          published_artifacts_only: true,
        },
        linked_findings: [finding],
      },
    },
    linked_findings: [finding],
    findings: [finding],
  };
}

function sdkPhpShardFor(result, supplied, reason, versions) {
  if (!isObject(supplied) && sdkPhpCommandMissing(reason)) {
    return sdkPhpUnsupportedShard(reason, versions);
  }

  const source = isObject(supplied) ? { ...supplied } : {};
  const scenarioId = 'php_worker_start_and_upsert_visibility';
  const scenario = isObject(supplied)
    ? (sdkPhpScenarioFromShard(supplied) || scenarioResultsById(result)[scenarioId] || {})
    : (scenarioResultsById(result)[scenarioId] || {});
  const status = statusForShardEntry(scenario, source);
  const observedOutputs = firstArrayField(scenario, ['observed_outputs', 'observedOutputs'])
    || firstArrayField(source, ['observed_outputs', 'observedOutputs'])
    || {};
  const linkedFindings = sdkPhpLinkedFindings(scenario, source, status, versions, reason);

  return {
    ...source,
    schema: source.schema || 'durable-workflow.v2.search-attribute-runtime.sdk-php-shard',
    scenario_id: source.scenario_id || source.scenarioId || scenarioId,
    status,
    runner_blocked: Boolean(source.runner_blocked || source.runnerBlocked || status === 'runner_blocked'),
    artifact_versions: source.artifact_versions || source.artifactVersions || result.artifactVersions || versions,
    observed_outputs: observedOutputs,
    scenario_results: {
      ...(isObject(source.scenario_results) ? source.scenario_results : {}),
      [scenarioId]: {
        scenario_id: scenarioId,
        status,
        observed_outputs: observedOutputs,
        linked_findings: linkedFindings,
      },
    },
    linked_findings: linkedFindings,
    findings: Array.isArray(source.findings) && source.findings.length > 0 ? source.findings : linkedFindings,
  };
}

function waterlineShardFor(result, supplied, reason, versions) {
  let shard;
  if (isObject(supplied)) {
    shard = { ...supplied };
  } else {
    const waterlineScenario = scenarioResultsById(result).waterline_operator_visibility || {};
    shard = {
      schema: 'durable-workflow.v2.search-attribute-runtime.waterline-shard',
      scenario_id: 'waterline_operator_visibility',
      status: waterlineScenario.status || 'runner_blocked',
      runner_blocked: Boolean(result.runner_blocked || result.runnerBlocked || false),
      observed_outputs: waterlineScenario.observed_outputs
        || result.waterline_operator_visibility
        || result.waterline_search_attribute_visibility
        || {},
      linked_findings: waterlineScenario.linked_findings || [],
    };
  }
  shard.schema = shard.schema || 'durable-workflow.v2.search-attribute-runtime.waterline-shard';
  shard.scenario_id = shard.scenario_id || 'waterline_operator_visibility';
  shard.status = shard.status || 'runner_blocked';
  shard.runner_blocked = Boolean(shard.runner_blocked || shard.status === 'runner_blocked');
  if (shard.status === 'runner_blocked' && (!Array.isArray(shard.linked_findings) || shard.linked_findings.length === 0)) {
    shard.linked_findings = [findingFor('waterline_operator_visibility', reason, versions)];
  }
  return shard;
}

function codecShardStatusFromResult(result, fallback = 'not_covered') {
  const scenarioResults = scenarioResultsById(result);
  const statuses = [
    scenarioResults.python_to_php_codec_round_trip?.status,
    scenarioResults.php_to_python_codec_round_trip?.status,
  ].filter((status) => typeof status === 'string' && status !== '');
  if (statuses.length === 2 && statuses.every((status) => status === 'pass')) {
    return 'pass';
  }
  if (statuses.includes('fail')) {
    return 'fail';
  }
  if (statuses.includes('runner_blocked')) {
    return 'runner_blocked';
  }
  if (statuses.includes('unsupported')) {
    return 'unsupported';
  }
  if (statuses.length > 0) {
    return 'not_covered';
  }
  return fallback;
}

function codecShardFor(result, supplied) {
  if (isObject(supplied)) {
    return {
      schema: supplied.schema || 'durable-workflow.v2.search-attribute-runtime.codec-shard',
      status: codecShardStatusFromResult(result, stringValue(supplied.status || supplied.outcome || 'not_covered')),
      artifact_versions: supplied.artifact_versions || supplied.artifactVersions || result.artifactVersions,
      codec_round_trips: supplied.codec_round_trips
        || supplied.codecRoundTrips
        || {
          python_to_php: supplied.python_to_php || supplied.pythonToPhp,
          php_to_python: supplied.php_to_python || supplied.phpToPython,
        },
      scenario_results: supplied.scenario_results || supplied.scenarioResults || {},
      observed_outputs: supplied.observed_outputs || supplied.observedOutputs || {},
      linked_findings: supplied.linked_findings || supplied.linkedFindings || [],
    };
  }

  const scenarioResults = scenarioResultsById(result);
  return {
    schema: 'durable-workflow.v2.search-attribute-runtime.codec-shard',
    status: codecShardStatusFromResult(result),
    artifact_versions: result.artifactVersions,
    codec_round_trips: result.codec_round_trips || {},
    scenario_results: {
      python_to_php_codec_round_trip: scenarioResults.python_to_php_codec_round_trip || null,
      php_to_python_codec_round_trip: scenarioResults.php_to_python_codec_round_trip || null,
    },
  };
}

const resultDir = process.env.RESULT_DIR;
const startedAt = process.env.STARTED_AT;
const finishedAt = now();
const versions = artifactVersions();
const reason = (process.env.DW_SEARCH_ATTRIBUTES_BLOCKED_REASON || '').trim()
  || 'search-attributes host-backed shard evidence was not supplied';

let result = loadJson('DW_SEARCH_ATTRIBUTES_RESULT_FILE', 'DW_SEARCH_ATTRIBUTES_RESULT_JSON');
let sdkPhpShard = loadJson(
  'DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_FILE',
  'DW_SEARCH_ATTRIBUTES_SDK_PHP_SHARD_JSON'
);
let waterlineShard = loadJson(
  'DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_FILE',
  'DW_SEARCH_ATTRIBUTES_WATERLINE_SHARD_JSON'
);
let codecShard = loadJson(
  'DW_SEARCH_ATTRIBUTES_CODEC_SHARD_FILE',
  'DW_SEARCH_ATTRIBUTES_CODEC_SHARD_JSON'
);

if (isObject(result)) {
  result = normalizeResult(result, startedAt, finishedAt, versions);
} else if (isObject(sdkPhpShard) || isObject(codecShard)) {
  result = partialCoverageResult(reason, startedAt, finishedAt, versions);
} else {
  result = blockedResult(reason, startedAt, finishedAt, versions);
}

if (isObject(waterlineShard)) {
  result.waterline_operator_visibility = waterlineShard.observed_outputs
    || waterlineShard.waterline_operator_visibility
    || waterlineShard.waterline_search_attribute_visibility
    || waterlineShard;
}

if (isObject(sdkPhpShard)) {
  mergeSdkPhpShard(result, sdkPhpShard, versions, reason);
  mergeCodecShard(result, sdkPhpShard, versions);
}

if (isObject(codecShard)) {
  mergeCodecShard(result, codecShard, versions);
}

const gateEvaluation = applyGateEvaluation(result, versions);
sdkPhpShard = sdkPhpShardFor(result, sdkPhpShard, reason, versions);
waterlineShard = waterlineShardFor(result, waterlineShard, reason, versions);
codecShard = codecShardFor(result, codecShard);
const runnerBlocked = Boolean(result.runner_blocked || result.runnerBlocked || false);
const outcome = String(result.outcome || result.status || 'non_passing');
const findings = Array.isArray(result.findings) ? result.findings : [];

const pins = {
  artifact_versions: versions,
  artifact_sources: artifactSources(),
  published_artifacts_only: true,
};
const installEvidence = {
  artifact_versions: versions,
  artifact_sources: artifactSources(),
  local_product_source_checkouts_used: false,
  published_artifacts_only: true,
  runner_blocked: runnerBlocked,
};
const metadata = {
  started_at: startedAt,
  finished_at: finishedAt,
  generated_at: finishedAt,
  runner: RUNNER,
  result_dir: resultDir,
  result_files: RESULT_FILES,
  required_execution_scopes: REQUIRED_SCOPES,
  runner_blocked: runnerBlocked,
};
const record = {
  experiment: 'search-attributes',
  outcome,
  runnerBlocked,
  runId: result.run_id || result.runId,
  artifactVersions: result.artifactVersions || versions,
  findings,
  resultGate: gateEvaluation,
  resultPath: path.join(resultDir, 'search-attributes-result.json'),
  resultFiles: RESULT_FILES,
};

writeJson(path.join(resultDir, 'pins.json'), pins);
writeJson(path.join(resultDir, 'run-metadata.json'), metadata);
writeJson(path.join(resultDir, 'artifact-install-evidence.json'), installEvidence);
writeJson(path.join(resultDir, 'sdk-php-search-attributes-shard.json'), sdkPhpShard);
writeJson(path.join(resultDir, 'waterline-search-attributes-shard.json'), waterlineShard);
writeJson(path.join(resultDir, 'codec-round-trip-shard.json'), codecShard);
writeJson(path.join(resultDir, 'search-attributes-result.json'), result);
writeJson(path.join(resultDir, 'search-attributes-record.json'), record);

process.exit(outcome === 'pass' && !runnerBlocked && gateEvaluation.status === 'pass' ? 0 : 1);
NODE
