#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const RESULT_SCHEMA = 'durable-workflow.v2.schedules-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.schedules-runtime.record';
const PUBLISHED_ARTIFACTS_SCHEMA = 'durable-workflow.v2.schedules-runtime.published-artifacts';

const modulePath = fileURLToPath(import.meta.url);
const repoRoot = process.env.DW_SCHEDULES_REPO_ROOT
  ?? path.resolve(path.dirname(modulePath), '../..');
const resultDir = process.env.DW_SCHEDULES_RESULT_DIR
  ?? process.env.DW_SCHEDULES_RUN_ROOT
  ?? process.cwd();
const scenarioManifestPath = process.env.DW_SCHEDULES_SCENARIO_MANIFEST
  ?? path.join(repoRoot, 'static/platform-conformance/schedules-runtime-scenarios.json');
const smokeEvidencePath = process.env.DW_SCHEDULES_SMOKE_EVIDENCE
  ?? path.join(resultDir, 'schedules-smoke-evidence.json');

const DEFAULT_REQUIRED_SCENARIOS = [
  'published_artifact_install_only',
  'cron_cadence',
  'fixed_rate_cadence',
  'list_describe_visibility',
  'pause_resume_no_fire_window',
  'delete_stops_future_fires',
  'missed_fire_policy',
  'restart_survival',
  'cli_schedule_surface',
  'python_sdk_schedule_surface',
  'php_schedule_surface',
  'python_created_php_workflow',
  'php_created_python_workflow',
  'invalid_cron_refusal',
  'nonexistent_workflow_type_outcome',
];

const scenarioManifest = readJsonIfExists(scenarioManifestPath) ?? {};
const requiredScenarios = Array.isArray(scenarioManifest.scenarios)
  ? scenarioManifest.scenarios.map((scenario) => scenario.id).filter(Boolean)
  : DEFAULT_REQUIRED_SCENARIOS;
const coverageGapFindings = scenarioManifest.host_runner_contract?.coverage_gap_findings ?? {};

if (isMainModule()) {
  Promise.resolve().then(main).catch((error) => {
    const now = timestamp();
    const reason = error instanceof Error ? error.message : String(error);
    writeResult(blockedResult(reason, now, now, artifactVersionsFromEnv(), artifactSourcesFromEnv()));
    process.exitCode = 0;
  });
}

function main() {
  fs.mkdirSync(resultDir, { recursive: true });

  const startedAt = process.env.DW_SCHEDULES_STARTED_AT ?? timestamp();
  const finishedAt = timestamp();
  const artifactVersions = artifactVersionsFromEnv();
  const artifactSources = artifactSourcesFromEnv();
  const smokeEvidence = readJsonIfExists(smokeEvidencePath) ?? {};
  const suppliedScenarioResults = scenarioResultsById(smokeEvidence);
  const findingLinks = {};
  const findingsById = new Map();
  const scenarioResults = {};

  for (const scenarioId of requiredScenarios) {
    const supplied = suppliedScenarioResults[scenarioId];
    if (supplied && supplied.status === 'pass') {
      scenarioResults[scenarioId] = normalizeScenarioResult(scenarioId, supplied);
      findingLinks[scenarioId] = scenarioResults[scenarioId].linked_findings;
      continue;
    }

    if (pythonSmokePassesScenario(scenarioId, smokeEvidence)) {
      scenarioResults[scenarioId] = {
        scenario_id: scenarioId,
        status: 'pass',
        observed_outputs: pythonSmokeOutputs(scenarioId, smokeEvidence, artifactVersions),
        linked_findings: [],
      };
      findingLinks[scenarioId] = [];
      continue;
    }

    const finding = focusedCoverageFinding(scenarioId, artifactVersions, smokeEvidence);
    const findingId = stringValue(finding.finding_id) || `schedules-coverage-${scenarioId}`;
    findingsById.set(findingId, finding);
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status: 'not_covered',
      observed_outputs: notCoveredOutputs(scenarioId, smokeEvidence),
      linked_findings: [finding],
    };
    findingLinks[scenarioId] = [finding];
  }

  const findings = Array.from(findingsById.values());
  const topology = {
    namespace: stringValue(smokeEvidence.topology?.namespace) || 'schedules-conformance',
    task_queue: stringValue(smokeEvidence.topology?.task_queue) || 'schedules-shared',
    worker_execution_mode: stringValue(smokeEvidence.topology?.worker_execution_mode) || 'published_artifact_shards_required',
    schedules_created: smokeEvidence.topology?.schedules_created ?? [],
  };
  const runtimeMatrix = {
    runtimes: arrayValue(smokeEvidence.runtime_matrix?.runtimes),
    client_paths: arrayValue(smokeEvidence.runtime_matrix?.client_paths),
    schedule_types: arrayValue(smokeEvidence.runtime_matrix?.schedule_types),
    cross_language_cells: arrayValue(smokeEvidence.runtime_matrix?.cross_language_cells),
    uncovered_required_runtimes: missingTokens(
      scenarioManifest.required_matrix?.runtimes ?? ['workflow-php', 'sdk-python'],
      smokeEvidence.runtime_matrix?.runtimes,
    ),
    uncovered_required_client_paths: missingTokens(
      scenarioManifest.required_matrix?.client_paths ?? ['cli', 'sdk-python', 'workflow-php-sdk'],
      smokeEvidence.runtime_matrix?.client_paths,
    ),
    uncovered_required_schedule_types: missingTokens(
      scenarioManifest.required_matrix?.schedule_types ?? ['cron_expression', 'fixed_rate_interval'],
      smokeEvidence.runtime_matrix?.schedule_types,
    ),
  };

  const result = {
    schema: RESULT_SCHEMA,
    version: 1,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: Object.values(scenarioResults).every((scenario) => scenario.status === 'pass')
      ? 'pass'
      : 'non_passing',
    runner_blocked: false,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: scenarioResults,
    findings,
    finding_links: findingLinks,
    topology,
    runtime_matrix: runtimeMatrix,
    cadence_observations: smokeEvidence.cadence_observations ?? {},
    operator_controls: smokeEvidence.operator_controls ?? {},
    missed_fire_policy: smokeEvidence.missed_fire_policy ?? {},
    restart_survival: smokeEvidence.restart_survival ?? {},
    client_surfaces: smokeEvidence.client_surfaces ?? {},
    cross_language_matrix: smokeEvidence.cross_language_matrix ?? {},
    adversarial_outcomes: smokeEvidence.adversarial_outcomes ?? {},
    current_smoke_evidence: currentSmokeEvidence(smokeEvidence),
  };

  writePublishedArtifacts(artifactVersions, artifactSources, smokeEvidence);
  writeResult(result);
}

function normalizeScenarioResult(scenarioId, supplied) {
  return {
    scenario_id: scenarioId,
    status: supplied.status,
    observed_outputs: supplied.observed_outputs ?? supplied.observedOutputs ?? {},
    linked_findings: arrayValue(supplied.linked_findings ?? supplied.linkedFindings),
  };
}

function pythonSmokePassesScenario(scenarioId, evidence) {
  const smoke = evidence.python_schedule_lifecycle_smoke ?? evidence.pythonScheduleLifecycleSmoke ?? {};
  const passed = smoke.passed === true || evidence.python_schedule_lifecycle_smoke_passed === true;

  if (!passed) {
    return false;
  }

  if (scenarioId === 'python_sdk_schedule_surface') {
    return allTrue(smoke, [
      'create',
      'list',
      'describe',
      'pause',
      'resume',
      'trigger',
      'delete',
    ]);
  }

  if (scenarioId === 'invalid_cron_refusal') {
    return smoke.invalid_cron_refused === true
      && smoke.invalid_cron_typed_error === true
      && smoke.invalid_cron_persisted === false;
  }

  return false;
}

function pythonSmokeOutputs(scenarioId, evidence, artifactVersions) {
  const smoke = evidence.python_schedule_lifecycle_smoke ?? evidence.pythonScheduleLifecycleSmoke ?? {};

  if (scenarioId === 'invalid_cron_refusal') {
    return {
      refused: true,
      typed_error: true,
      persisted: false,
      smoke_source: 'published_python_sdk_lifecycle_smoke',
      artifact_versions: artifactVersions,
    };
  }

  return {
    create_or_observe: smoke.create === true,
    list_observed: smoke.list === true,
    describe_observed: smoke.describe === true,
    control_observed: ['pause', 'resume', 'trigger', 'delete'].every((key) => smoke[key] === true),
    triggered_workflow_completion_observed: smoke.triggered_workflow_completed === true,
    smoke_source: 'published_python_sdk_lifecycle_smoke',
    artifact_versions: artifactVersions,
  };
}

function notCoveredOutputs(scenarioId, evidence) {
  return {
    coverage_status: 'not_covered',
    scenario_id: scenarioId,
    current_positive_evidence: currentSmokeEvidence(evidence),
    required_follow_up: coverageGapFindings[scenarioId]?.acceptance
      ?? ['execute this scenario with published artifacts and record observed outputs'],
  };
}

function currentSmokeEvidence(evidence) {
  const smoke = evidence.python_schedule_lifecycle_smoke ?? evidence.pythonScheduleLifecycleSmoke ?? {};
  if (smoke.passed === true || evidence.python_schedule_lifecycle_smoke_passed === true) {
    return {
      python_sdk_lifecycle_smoke: 'passed',
      verified_operations: arrayValue(smoke.verified_operations).length > 0
        ? arrayValue(smoke.verified_operations)
        : [
            'create',
            'list',
            'describe',
            'pause',
            'resume',
            'manual_trigger',
            'delete',
            'triggered_workflow_completion',
            'invalid_cron_refusal',
          ],
    };
  }

  return {
    python_sdk_lifecycle_smoke: 'not_supplied_to_runner',
  };
}

function focusedCoverageFinding(scenarioId, artifactVersions, evidence) {
  const configured = coverageGapFindings[scenarioId] ?? {};
  return {
    finding_id: stringValue(configured.id) || `schedules-coverage-${scenarioId}`,
    scenario_id: scenarioId,
    finding_type: 'conformance_runner_coverage_gap',
    owning_surface: stringValue(configured.owner) || 'conformance_harness',
    execution_scope: stringValue(configured.scope) || 'schedules-runtime-shard',
    artifact_versions: artifactVersions,
    observed_behavior: stringValue(configured.current_evidence)
      || 'The published-artifact schedules result did not execute this required scenario.',
    expected_behavior: stringValue(configured.expected_behavior)
      || 'Schedules conformance records published-artifact evidence for every required scenario.',
    next_acceptance_criterion: arrayValue(configured.acceptance).join('; ')
      || 'run the missing schedules scenario with published artifacts and attach observed outputs',
    current_positive_evidence: currentSmokeEvidence(evidence),
  };
}

function blockedResult(reason, startedAt, finishedAt, artifactVersions = {}, artifactSources = {}) {
  const finding = {
    finding_type: 'conformance_runner_blocked',
    owning_surface: 'conformance_harness',
    observed_behavior: reason,
    expected_behavior: 'schedules conformance runner can build a published-artifact result',
    next_acceptance_criterion: 'restore the missing host capability and rerun schedules conformance',
  };

  return {
    schema: RESULT_SCHEMA,
    version: 1,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: Object.fromEntries(requiredScenarios.map((scenarioId) => [
      scenarioId,
      {
        scenario_id: scenarioId,
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [{ ...finding, scenario_id: scenarioId }],
      },
    ])),
    findings: requiredScenarios.map((scenarioId) => ({ ...finding, scenario_id: scenarioId })),
    finding_links: Object.fromEntries(requiredScenarios.map((scenarioId) => [
      scenarioId,
      [{ ...finding, scenario_id: scenarioId }],
    ])),
    topology: {},
    runtime_matrix: {},
    cadence_observations: {},
    operator_controls: {},
    missed_fire_policy: {},
    restart_survival: {},
    cross_language_matrix: {},
    adversarial_outcomes: {},
  };
}

function artifactVersionsFromEnv() {
  return {
    server: process.env.DW_SERVER_VERSION ?? '',
    cli: process.env.DW_CLI_VERSION ?? '',
    'sdk-python': process.env.DW_PYTHON_SDK_VERSION ?? '',
    workflow: process.env.DW_WORKFLOW_PHP_VERSION ?? '',
    waterline: process.env.DW_WATERLINE_VERSION ?? '',
  };
}

function artifactSourcesFromEnv() {
  return {
    server: process.env.DW_SCHEDULES_SERVER_ARTIFACT_SOURCE ?? 'not_exercised',
    cli: process.env.DW_SCHEDULES_CLI_ARTIFACT_SOURCE ?? 'not_exercised',
    'sdk-python': process.env.DW_SCHEDULES_PYTHON_SDK_ARTIFACT_SOURCE ?? 'not_exercised',
    workflow: process.env.DW_SCHEDULES_WORKFLOW_PHP_ARTIFACT_SOURCE ?? 'not_exercised',
    waterline: process.env.DW_SCHEDULES_WATERLINE_ARTIFACT_SOURCE ?? 'not_exercised',
  };
}

function scenarioResultsById(evidence) {
  const raw = evidence?.scenario_results ?? evidence?.scenarioResults ?? {};
  if (Array.isArray(raw)) {
    return Object.fromEntries(raw
      .filter((entry) => entry && typeof entry === 'object' && stringValue(entry.scenario_id ?? entry.id))
      .map((entry) => [stringValue(entry.scenario_id ?? entry.id), entry]));
  }

  if (raw && typeof raw === 'object') {
    return Object.fromEntries(Object.entries(raw)
      .filter(([, value]) => value && typeof value === 'object')
      .map(([key, value]) => [stringValue(value.scenario_id ?? value.id ?? key), value]));
  }

  return {};
}

function writePublishedArtifacts(artifactVersions, artifactSources, smokeEvidence) {
  writeJson(path.join(resultDir, 'published-artifacts.json'), {
    schema: PUBLISHED_ARTIFACTS_SCHEMA,
    generated_at: timestamp(),
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    smoke_evidence_supplied: Object.keys(smokeEvidence).length > 0,
  });
}

function writeResult(result) {
  fs.mkdirSync(resultDir, { recursive: true });
  const resultPath = path.join(resultDir, 'schedules-runtime-result.json');
  writeJson(resultPath, result);
  writeJson(path.join(resultDir, 'schedules-runtime-record.json'), {
    schema: RECORD_SCHEMA,
    experiment: 'schedules',
    outcome: result.outcome,
    runnerBlocked: result.runner_blocked === true,
    artifactVersions: result.artifact_versions ?? {},
    resultPath,
    generated_at: result.generated_at ?? timestamp(),
    findings: result.findings ?? [],
  });
}

function writeJson(filePath, value) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function readJsonIfExists(filePath) {
  try {
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch (error) {
    if (error && error.code === 'ENOENT') {
      return null;
    }
    throw error;
  }
}

function missingTokens(required, reported) {
  const normalizedReported = new Set(arrayValue(reported).map((value) => normalizeToken(value)));
  return arrayValue(required).filter((value) => !normalizedReported.has(normalizeToken(value)));
}

function allTrue(object, keys) {
  return keys.every((key) => object[key] === true);
}

function arrayValue(value) {
  return Array.isArray(value) ? value : [];
}

function stringValue(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function normalizeToken(value) {
  return stringValue(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

function timestamp() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function isMainModule() {
  return process.argv[1] && path.resolve(process.argv[1]) === modulePath;
}
