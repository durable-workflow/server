#!/usr/bin/env node
import { execFile as execFileCallback } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import process from 'node:process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';

const RESULT_SCHEMA = 'durable-workflow.v2.schedules-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.schedules-runtime.record';
const PUBLISHED_ARTIFACTS_SCHEMA = 'durable-workflow.v2.schedules-runtime.published-artifacts';
const execFile = promisify(execFileCallback);

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
const cliEvidencePath = process.env.DW_SCHEDULES_CLI_EVIDENCE
  ?? path.join(resultDir, 'schedules-cli-evidence.json');
const operatorControlsEvidencePath = process.env.DW_SCHEDULES_OPERATOR_CONTROLS_EVIDENCE
  ?? path.join(resultDir, 'schedules-operator-controls-evidence.json');
const missedRestartEvidencePath = process.env.DW_SCHEDULES_MISSED_RESTART_EVIDENCE
  ?? path.join(resultDir, 'schedules-missed-restart-evidence.json');

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

async function main() {
  fs.mkdirSync(resultDir, { recursive: true });

  const startedAt = process.env.DW_SCHEDULES_STARTED_AT ?? timestamp();
  const artifactVersions = artifactVersionsFromEnv();
  const artifactSources = artifactSourcesFromEnv();
  const evidenceInputs = readEvidenceInputs();
  const cadenceEvidence = await maybeRunCadenceShard(startedAt, artifactVersions, artifactSources);
  if (cadenceEvidence !== null) {
    evidenceInputs.push(cadenceEvidence);
  }
  const operatorControlsEvidence = await maybeRunOperatorControlsShard(startedAt, artifactVersions, artifactSources);
  if (operatorControlsEvidence !== null) {
    evidenceInputs.push(operatorControlsEvidence);
  }
  const missedRestartEvidence = await maybeRunMissedRestartShard(startedAt, artifactVersions, artifactSources);
  if (missedRestartEvidence !== null) {
    evidenceInputs.push(missedRestartEvidence);
  }
  const cliSurfaceEvidence = await maybeRunCliSurfaceShard(startedAt, artifactVersions, artifactSources);
  if (cliSurfaceEvidence !== null) {
    evidenceInputs.push(cliSurfaceEvidence);
  }
  const smokeEvidence = mergeEvidence(...evidenceInputs);
  const finishedAt = timestamp();
  const suppliedScenarioResults = scenarioResultsById(smokeEvidence);
  const findingLinks = {};
  const findingsById = new Map();
  const scenarioResults = {};

  for (const scenarioId of requiredScenarios) {
    const supplied = suppliedScenarioResults[scenarioId];
    if (supplied && allowedScenarioStatus(supplied.status)) {
      const normalized = normalizeScenarioResult(scenarioId, supplied);
      if (normalized.status !== 'pass' && normalized.linked_findings.length === 0) {
        normalized.linked_findings = [focusedCoverageFinding(scenarioId, artifactVersions, smokeEvidence)];
      }
      scenarioResults[scenarioId] = normalized;
      for (const finding of normalized.linked_findings) {
        const findingId = stringValue(finding.finding_id) || `schedules-${scenarioId}-${findingsById.size + 1}`;
        findingsById.set(findingId, finding);
      }
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
    status: stringValue(supplied.status),
    observed_outputs: supplied.observed_outputs ?? supplied.observedOutputs ?? {},
    linked_findings: arrayValue(supplied.linked_findings ?? supplied.linkedFindings),
  };
}

function allowedScenarioStatus(status) {
  return ['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked'].includes(stringValue(status));
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
    'workflow-php': process.env.DW_WORKFLOW_PHP_VERSION ?? '',
    waterline: process.env.DW_WATERLINE_VERSION ?? '',
  };
}

function artifactSourcesFromEnv() {
  return {
    server: process.env.DW_SCHEDULES_SERVER_ARTIFACT_SOURCE ?? process.env.DW_SERVER_ARTIFACT_SOURCE ?? 'not_exercised',
    cli: process.env.DW_SCHEDULES_CLI_ARTIFACT_SOURCE ?? process.env.DW_CLI_ARTIFACT_SOURCE ?? 'not_exercised',
    'sdk-python': process.env.DW_SCHEDULES_PYTHON_SDK_ARTIFACT_SOURCE ?? process.env.DW_PYTHON_SDK_ARTIFACT_SOURCE ?? 'not_exercised',
    workflow: process.env.DW_SCHEDULES_WORKFLOW_PHP_ARTIFACT_SOURCE ?? process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE ?? 'not_exercised',
    'workflow-php': process.env.DW_SCHEDULES_WORKFLOW_PHP_ARTIFACT_SOURCE ?? process.env.DW_WORKFLOW_PHP_ARTIFACT_SOURCE ?? 'not_exercised',
    waterline: process.env.DW_SCHEDULES_WATERLINE_ARTIFACT_SOURCE ?? process.env.DW_WATERLINE_ARTIFACT_SOURCE ?? 'not_exercised',
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

function readEvidenceInputs() {
  const paths = [
    smokeEvidencePath,
    process.env.DW_SCHEDULES_CADENCE_EVIDENCE,
    operatorControlsEvidencePath,
    missedRestartEvidencePath,
    cliEvidencePath,
  ].filter((value, index, values) => stringValue(value) !== '' && values.indexOf(value) === index);

  return paths
    .map((filePath) => readJsonIfExists(filePath))
    .filter((value) => value && typeof value === 'object');
}

function mergeEvidence(...inputs) {
  const merged = {};

  for (const input of inputs) {
    mergeInto(merged, input);
  }

  return merged;
}

function mergeInto(target, source) {
  if (!source || typeof source !== 'object') {
    return target;
  }

  for (const [key, value] of Object.entries(source)) {
    if (key === 'scenarioResults') {
      mergeScenarioResults(target, value);
      continue;
    }

    if (key === 'scenario_results') {
      mergeScenarioResults(target, value);
      continue;
    }

    if (Array.isArray(value)) {
      target[key] = mergeArrays(arrayValue(target[key]), value);
      continue;
    }

    if (value && typeof value === 'object') {
      const existing = target[key];
      target[key] = mergeInto(
        existing && typeof existing === 'object' && !Array.isArray(existing) ? existing : {},
        value,
      );
      continue;
    }

    target[key] = value;
  }

  return target;
}

function mergeScenarioResults(target, raw) {
  const existing = target.scenario_results && typeof target.scenario_results === 'object'
    ? target.scenario_results
    : {};
  target.scenario_results = {
    ...existing,
    ...scenarioResultsById({ scenario_results: raw }),
  };
}

function mergeArrays(left, right) {
  const seen = new Set();
  const result = [];

  for (const value of [...left, ...right]) {
    const key = value && typeof value === 'object'
      ? JSON.stringify(value)
      : String(value);
    if (seen.has(key)) {
      continue;
    }

    seen.add(key);
    result.push(value);
  }

  return result;
}

async function maybeRunCadenceShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_CADENCE_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  const suppliedCadenceEvidencePath = stringValue(process.env.DW_SCHEDULES_CADENCE_EVIDENCE);
  if (suppliedCadenceEvidencePath !== '' && readJsonIfExists(suppliedCadenceEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const serverUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);

  if (serverUrl === '' && (!dockerAvailable || !composeAvailable || serverImage === '')) {
    if (!explicit) {
      return null;
    }

    const missing = [
      !dockerAvailable ? 'docker' : null,
      dockerAvailable && !composeAvailable ? 'docker compose' : null,
      serverImage === '' ? 'DW_SERVER_VERSION or DW_SERVER_IMAGE' : null,
    ].filter(Boolean).join(', ');

    return cadenceFailureEvidence(
      `Cadence shard could not start because ${missing} is unavailable.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runCadenceShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    return cadenceFailureEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runCadenceShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const cadenceStartedAt = timestamp();
  const runId = `schedules-cadence-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance';
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-cadence';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const timeoutSeconds = positiveInt(process.env.DW_SCHEDULES_CADENCE_TIMEOUT_SECONDS, 420);
  const pollSeconds = positiveInt(process.env.DW_SCHEDULES_CADENCE_POLL_SECONDS, 5);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_SCHEDULER_TICK_SECONDS, 5);
  const driftToleranceMs = positiveInt(process.env.DW_SCHEDULES_CADENCE_DRIFT_TOLERANCE_MS, 20000);
  const intervalToleranceMs = positiveInt(process.env.DW_SCHEDULES_CADENCE_INTERVAL_TOLERANCE_MS, 15000);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  const serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const overlayPath = path.join(resultDir, 'schedules-cadence-compose.override.yml');
  const cadenceEvidencePath = path.join(resultDir, 'schedules-cadence-evidence.json');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  let composeStarted = false;

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url');

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-cadence-docker-pull.log'),
    );
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-cadence',
      services: ['server', 'scheduler'],
    });
    composeStarted = true;
  }

  try {
    await waitForServerReady(serverUrl, 120);
    await ensureNamespace(serverUrl, token, namespace);

    const cronScheduleId = `${runId}-cron`;
    const fixedRateScheduleId = `${runId}-fixed-rate`;

    await createCadenceSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: cronScheduleId,
      spec: { cron_expressions: ['* * * * *'], timezone: 'UTC' },
      taskQueue,
    });
    await createCadenceSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: fixedRateScheduleId,
      spec: { intervals: [{ every: 'PT30S' }], timezone: 'UTC' },
      taskQueue,
    });

    const observations = await observeCadence({
      serverUrl,
      token,
      namespace,
      schedules: [
        {
          kind: 'cron',
          scenarioId: 'cron_cadence',
          scheduleId: cronScheduleId,
          minimumObservedFires: 4,
          expectedIntervalMs: 60000,
          cron_expression: '* * * * *',
        },
        {
          kind: 'fixed_rate',
          scenarioId: 'fixed_rate_cadence',
          scheduleId: fixedRateScheduleId,
          minimumObservedFires: 8,
          expectedIntervalMs: 30000,
          interval: 'PT30S',
        },
      ],
      timeoutSeconds,
      pollSeconds,
      driftToleranceMs,
      intervalToleranceMs,
      artifactVersions,
      artifactSources,
    });

    await bestEffortDeleteSchedule(serverUrl, token, namespace, cronScheduleId);
    await bestEffortDeleteSchedule(serverUrl, token, namespace, fixedRateScheduleId);

    const evidence = cadenceEvidenceFromObservations({
      observations,
      startedAt: cadenceStartedAt,
      finishedAt: timestamp(),
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      schedulesCreated: [cronScheduleId, fixedRateScheduleId],
    });
    writeJson(cadenceEvidencePath, evidence);

    return evidence;
  } finally {
    if (composeStarted) {
      await collectComposeLogs(composeProject, composeFiles);
      await execFile('docker', ['compose', '-p', composeProject, ...composeFiles, 'down', '-v'], {
        env: composeEnv(serverPort, serverImage, token, artifactVersions),
        maxBuffer: 1024 * 1024 * 8,
      }).catch(() => {});
    }

    const finishedAt = timestamp();
    writeJson(path.join(resultDir, 'schedules-cadence-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.cadence-run-metadata',
      started_at: startedAt,
      cadence_started_at: cadenceStartedAt,
      finished_at: finishedAt,
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      server_image: serverImage || 'existing-server-url',
      compose_project: existingServerUrl === '' ? composeProject : null,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
    });
  }
}

function writeSchedulerOverlay(filePath, schedulerTickSeconds) {
  writeText(filePath, [
    'services:',
    '  scheduler:',
    '    command: >-',
    `      sh -c 'while true; do php artisan schedule:evaluate --limit=100 --json; sleep ${schedulerTickSeconds}; done'`,
    '',
  ].join('\n'));
}

function composeEnv(serverPort, serverImage, token, artifactVersions) {
  return {
    ...process.env,
    SERVER_PORT: String(serverPort),
    DW_SERVER_IMAGE: serverImage,
    DW_SERVER_TAG: artifactVersions.server || '',
    APP_VERSION: artifactVersions.server || '',
    DW_AUTH_TOKEN: token,
    DW_WORKER_POLL_TIMEOUT: process.env.DW_WORKER_POLL_TIMEOUT ?? '1',
    DW_WORKER_POLL_INTERVAL_MS: process.env.DW_WORKER_POLL_INTERVAL_MS ?? '100',
  };
}

async function createCadenceSchedule({ serverUrl, token, namespace, scheduleId, spec, taskQueue }) {
  await apiRequest(serverUrl, token, namespace, 'POST', '/schedules', {
    schedule_id: scheduleId,
    spec,
    action: {
      workflow_type: 'schedules.CadenceProbe',
      task_queue: taskQueue,
      input: [{ schedule_id: scheduleId }],
    },
    overlap_policy: 'allow_all',
    jitter_seconds: 0,
  });
}

async function observeCadence({
  serverUrl,
  token,
  namespace,
  schedules,
  timeoutSeconds,
  pollSeconds,
  driftToleranceMs,
  intervalToleranceMs,
  artifactVersions,
  artifactSources,
}) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  let latest = new Map();

  while (Date.now() < deadline) {
    latest = new Map(await Promise.all(schedules.map(async (schedule) => {
      const history = await scheduleHistory(serverUrl, token, namespace, schedule.scheduleId);
      const observation = buildCadenceObservation({
        ...schedule,
        events: history.events ?? [],
        driftToleranceMs,
        intervalToleranceMs,
        artifactVersions,
        artifactSources,
      });

      return [schedule.scenarioId, observation];
    })));

    if (schedules.every((schedule) => {
      const observation = latest.get(schedule.scenarioId);
      return observation && observation.observed_fire_count >= schedule.minimumObservedFires;
    })) {
      break;
    }

    await sleep(pollSeconds * 1000);
  }

  return Object.fromEntries(latest);
}

function buildCadenceObservation({
  scenarioId,
  kind,
  scheduleId,
  events,
  minimumObservedFires,
  expectedIntervalMs,
  driftToleranceMs,
  intervalToleranceMs,
  artifactVersions,
  artifactSources,
  cron_expression,
  interval,
}) {
  const fires = events
    .filter((event) => stringValue(event.event_type) === 'ScheduleTriggered')
    .map((event) => {
      const nominal = stringValue(event.payload?.occurrence_time);
      const actual = stringValue(event.recorded_at);
      const nominalMs = Date.parse(nominal);
      const actualMs = Date.parse(actual);

      return {
        nominal,
        actual,
        nominal_ms: Number.isFinite(nominalMs) ? nominalMs : null,
        actual_ms: Number.isFinite(actualMs) ? actualMs : null,
      };
    })
    .filter((fire) => fire.nominal !== '' && fire.actual !== '' && fire.nominal_ms !== null && fire.actual_ms !== null)
    .sort((left, right) => left.nominal_ms - right.nominal_ms);

  const nominalFireTimestamps = fires.map((fire) => fire.nominal);
  const actualFireTimestamps = fires.map((fire) => fire.actual);
  const driftMs = fires.map((fire) => fire.actual_ms - fire.nominal_ms);
  const duplicateFireCount = duplicateCount(nominalFireTimestamps);
  const intervalVerdict = cadenceIntervalVerdict(
    fires.map((fire) => fire.nominal_ms),
    expectedIntervalMs,
    intervalToleranceMs,
  );
  const offCadenceDriftCount = driftMs.filter((value) => Math.abs(value) > driftToleranceMs).length;
  const enoughFires = fires.length >= minimumObservedFires;
  const passed = enoughFires
    && duplicateFireCount === 0
    && intervalVerdict.skipped_interval_count === 0
    && intervalVerdict.interval_mismatch_count === 0
    && offCadenceDriftCount === 0;

  return {
    scenario_id: scenarioId,
    kind,
    schedule_id: scheduleId,
    cron_expression,
    interval,
    minimum_observed_fires: minimumObservedFires,
    observed_fire_count: fires.length,
    actual_fire_timestamps: actualFireTimestamps,
    nominal_fire_timestamps: nominalFireTimestamps,
    drift_ms: driftMs,
    expected_interval_ms: expectedIntervalMs,
    drift_tolerance_ms: driftToleranceMs,
    interval_tolerance_ms: intervalToleranceMs,
    duplicate_fire_count: duplicateFireCount,
    skipped_interval_count: intervalVerdict.skipped_interval_count,
    interval_mismatch_count: intervalVerdict.interval_mismatch_count,
    off_cadence_drift_count: offCadenceDriftCount,
    verdict: passed ? 'pass' : 'fail',
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
  };
}

function cadenceIntervalVerdict(nominalMs, expectedIntervalMs, intervalToleranceMs) {
  let skippedIntervalCount = 0;
  let intervalMismatchCount = 0;

  for (let index = 1; index < nominalMs.length; index += 1) {
    const gap = nominalMs[index] - nominalMs[index - 1];
    const missed = Math.max(0, Math.round(gap / expectedIntervalMs) - 1);
    if (gap > expectedIntervalMs + intervalToleranceMs) {
      skippedIntervalCount += missed || 1;
      intervalMismatchCount += 1;
    } else if (Math.abs(gap - expectedIntervalMs) > intervalToleranceMs) {
      intervalMismatchCount += 1;
    }
  }

  return {
    skipped_interval_count: skippedIntervalCount,
    interval_mismatch_count: intervalMismatchCount,
  };
}

function cadenceEvidenceFromObservations({
  observations,
  startedAt,
  finishedAt,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  schedulesCreated,
}) {
  const scenarioResults = {};
  const findings = [];

  for (const [scenarioId, observation] of Object.entries(observations)) {
    const status = observation.verdict === 'pass' ? 'pass' : 'fail';
    const linkedFindings = status === 'pass'
      ? []
      : [cadenceFinding(scenarioId, observation)];
    findings.push(...linkedFindings);
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status,
      observed_outputs: observation,
      linked_findings: linkedFindings,
    };
  }

  return {
    schema: 'durable-workflow.v2.schedules-runtime.cadence-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: scenarioResults,
    findings,
    cadence_observations: {
      cron: observations.cron_cadence ?? {},
      fixed_rate: observations.fixed_rate_cadence ?? {},
    },
    topology: {
      namespace,
      task_queue: taskQueue,
      worker_execution_mode: 'cadence_probe_without_worker_completion',
      schedules_created: schedulesCreated,
    },
    runtime_matrix: {
      schedule_types: ['cron_expression', 'fixed_rate_interval'],
      client_paths: ['server-http-api'],
      runtimes: ['server-scheduler'],
    },
  };
}

function cadenceFailureEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const observations = {
    cron_cadence: failedCadenceObservation('cron_cadence', 'cron', reason, artifactVersions, artifactSources),
    fixed_rate_cadence: failedCadenceObservation('fixed_rate_cadence', 'fixed_rate', reason, artifactVersions, artifactSources),
  };

  return cadenceEvidenceFromObservations({
    observations,
    startedAt,
    finishedAt,
    artifactVersions,
    artifactSources,
    namespace: stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance',
    taskQueue: stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-cadence',
    schedulesCreated: [],
  });
}

function failedCadenceObservation(scenarioId, kind, reason, artifactVersions, artifactSources) {
  return {
    scenario_id: scenarioId,
    kind,
    schedule_id: null,
    minimum_observed_fires: scenarioId === 'fixed_rate_cadence' ? 8 : 4,
    observed_fire_count: 0,
    actual_fire_timestamps: [],
    nominal_fire_timestamps: [],
    drift_ms: [],
    duplicate_fire_count: 0,
    skipped_interval_count: 0,
    interval_mismatch_count: 0,
    off_cadence_drift_count: 0,
    verdict: 'fail',
    failure_reason: reason,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
  };
}

function cadenceFinding(scenarioId, observation) {
  const kindLabel = scenarioId === 'fixed_rate_cadence' ? 'fixed-rate' : 'cron';
  const reasons = [];
  if (observation.failure_reason) {
    reasons.push(observation.failure_reason);
  }
  if (observation.observed_fire_count < observation.minimum_observed_fires) {
    reasons.push(`observed ${observation.observed_fire_count} fires; expected at least ${observation.minimum_observed_fires}`);
  }
  if (observation.duplicate_fire_count > 0) {
    reasons.push(`${observation.duplicate_fire_count} duplicate nominal fire(s)`);
  }
  if (observation.skipped_interval_count > 0) {
    reasons.push(`${observation.skipped_interval_count} skipped interval(s)`);
  }
  if (observation.interval_mismatch_count > 0) {
    reasons.push(`${observation.interval_mismatch_count} interval mismatch(es)`);
  }
  if (observation.off_cadence_drift_count > 0) {
    reasons.push(`${observation.off_cadence_drift_count} fire(s) exceeded drift tolerance`);
  }

  return {
    finding_id: `schedules-${kindLabel.replace(/[^a-z0-9]+/g, '-')}-cadence-finding`,
    scenario_id: scenarioId,
    finding_type: 'schedule_cadence_contract_gap',
    owning_surface: 'server',
    execution_scope: `${kindLabel}-cadence-shard`,
    artifact_versions: observation.artifact_versions ?? {},
    observed_behavior: reasons.join('; ') || `${kindLabel} cadence did not satisfy the published-artifact contract.`,
    expected_behavior: scenarioId === 'fixed_rate_cadence'
      ? 'A PT30S fixed-rate schedule fires at every documented interval without duplicate or skipped intervals.'
      : 'A * * * * * cron schedule fires on documented minute cadence without duplicate or skipped intervals.',
    next_acceptance_criterion: scenarioId === 'fixed_rate_cadence'
      ? 'observe at least eight PT30S fixed-rate fires with nominal timestamps, actual timestamps, and drift milliseconds'
      : 'observe at least four cron fires with nominal timestamps, actual timestamps, and drift milliseconds',
  };
}

async function maybeRunOperatorControlsShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_OPERATOR_CONTROLS_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(operatorControlsEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const serverUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);

  if (serverUrl === '' && (!dockerAvailable || !composeAvailable || serverImage === '')) {
    if (!explicit) {
      return null;
    }

    const missing = [
      !dockerAvailable ? 'docker' : null,
      dockerAvailable && !composeAvailable ? 'docker compose' : null,
      serverImage === '' ? 'DW_SERVER_VERSION or DW_SERVER_IMAGE' : null,
    ].filter(Boolean).join(', ');

    return operatorControlsBlockedEvidence(
      `Operator controls shard could not start because ${missing} is unavailable.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runOperatorControlsShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    return operatorControlsFailureEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runOperatorControlsShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const operatorStartedAt = timestamp();
  const runId = `schedules-operator-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-operator-controls';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  const serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_SCHEDULER_TICK_SECONDS, 5);
  const firstFireTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_OPERATOR_FIRST_FIRE_TIMEOUT_SECONDS, 140);
  const pauseSeconds = Math.max(120, positiveInt(process.env.DW_SCHEDULES_OPERATOR_PAUSE_SECONDS, 125));
  const resumeTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_OPERATOR_RESUME_TIMEOUT_SECONDS, 100);
  const deleteWindowSeconds = positiveInt(process.env.DW_SCHEDULES_OPERATOR_DELETE_WINDOW_SECONDS, 65);
  const pollSeconds = positiveInt(process.env.DW_SCHEDULES_OPERATOR_POLL_SECONDS, 5);
  const overlayPath = path.join(resultDir, 'schedules-operator-controls-compose.override.yml');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  let composeStarted = false;

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url');

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-operator-controls-docker-pull.log'),
    );
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-operator-controls',
      services: ['server', 'scheduler'],
    });
    composeStarted = true;
  }

  try {
    await waitForServerReady(serverUrl, 120);
    await ensureNamespace(serverUrl, token, namespace);

    const cronScheduleId = `${runId}-cron`;
    const fixedRateScheduleId = `${runId}-fixed-rate`;

    await createOperatorSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: cronScheduleId,
      spec: { cron_expressions: ['* * * * *'], timezone: 'UTC' },
      taskQueue,
    });
    await createOperatorSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: fixedRateScheduleId,
      spec: { intervals: [{ every: 'PT30S' }], timezone: 'UTC' },
      taskQueue,
    });

    const [cronFirstFire, fixedRateFirstFire] = await Promise.all([
      waitForScheduleTrigger({
        serverUrl,
        token,
        namespace,
        scheduleId: cronScheduleId,
        afterRecordedMs: 0,
        deadlineMs: Date.now() + firstFireTimeoutSeconds * 1000,
        pollSeconds,
      }),
      waitForScheduleTrigger({
        serverUrl,
        token,
        namespace,
        scheduleId: fixedRateScheduleId,
        afterRecordedMs: 0,
        deadlineMs: Date.now() + firstFireTimeoutSeconds * 1000,
        pollSeconds,
      }),
    ]);
    const firstFires = {
      cron: cronFirstFire,
      fixed_rate: fixedRateFirstFire,
    };

    const httpList = await listSchedules(serverUrl, token, namespace);
    const httpDescriptions = {
      [cronScheduleId]: await describeSchedule(serverUrl, token, namespace, cronScheduleId),
      [fixedRateScheduleId]: await describeSchedule(serverUrl, token, namespace, fixedRateScheduleId),
    };
    const cliVisibility = await probeCliListDescribe({
      serverUrl,
      token,
      namespace,
      scheduleIds: [cronScheduleId, fixedRateScheduleId],
      artifactVersions,
      artifactSources,
    });
    const sdkVisibility = await probePythonSdkListDescribe({
      serverUrl,
      token,
      namespace,
      scheduleIds: [cronScheduleId, fixedRateScheduleId],
      artifactVersions,
      artifactSources,
    });
    const listDescribe = buildListDescribeEvidence({
      scheduleIds: [cronScheduleId, fixedRateScheduleId],
      httpList,
      httpDescriptions,
      cliVisibility,
      sdkVisibility,
      firstFires,
      artifactVersions,
      artifactSources,
    });

    const pauseResume = await observePauseResumeWindow({
      serverUrl,
      token,
      namespace,
      scheduleId: fixedRateScheduleId,
      pauseSeconds,
      resumeTimeoutSeconds,
      pollSeconds,
      artifactVersions,
      artifactSources,
    });

    const deleteEvidence = await observeDeleteWindow({
      serverUrl,
      token,
      namespace,
      scheduleId: fixedRateScheduleId,
      deleteWindowSeconds,
      artifactVersions,
      artifactSources,
    });

    await bestEffortDeleteSchedule(serverUrl, token, namespace, cronScheduleId);

    const evidence = operatorControlsEvidenceFromObservations({
      startedAt: operatorStartedAt,
      finishedAt: timestamp(),
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      schedulesCreated: [cronScheduleId, fixedRateScheduleId],
      listDescribe,
      pauseResume,
      deleteEvidence,
      timing: {
        first_fire_timeout_seconds: firstFireTimeoutSeconds,
        pause_seconds: pauseSeconds,
        resume_timeout_seconds: resumeTimeoutSeconds,
        delete_window_seconds: deleteWindowSeconds,
        scheduler_tick_seconds: schedulerTickSeconds,
      },
    });
    writeJson(operatorControlsEvidencePath, evidence);

    return evidence;
  } finally {
    if (composeStarted) {
      await collectOperatorControlsComposeLogs(composeProject, composeFiles);
      await execFile('docker', ['compose', '-p', composeProject, ...composeFiles, 'down', '-v'], {
        env: composeEnv(serverPort, serverImage, token, artifactVersions),
        maxBuffer: 1024 * 1024 * 8,
      }).catch(() => {});
    }

    writeJson(path.join(resultDir, 'schedules-operator-controls-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.operator-controls-run-metadata',
      started_at: startedAt,
      operator_controls_started_at: operatorStartedAt,
      finished_at: timestamp(),
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      server_image: existingServerUrl === '' ? serverImage : null,
      compose_project: existingServerUrl === '' ? composeProject : null,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
    });
  }
}

async function createOperatorSchedule({ serverUrl, token, namespace, scheduleId, spec, taskQueue }) {
  await apiRequest(serverUrl, token, namespace, 'POST', '/schedules', {
    schedule_id: scheduleId,
    spec,
    action: {
      workflow_type: 'schedules.OperatorControlsProbe',
      task_queue: taskQueue,
      input: [{ schedule_id: scheduleId }],
    },
    overlap_policy: 'allow_all',
    jitter_seconds: 0,
  });
}

async function waitForScheduleTrigger({
  serverUrl,
  token,
  namespace,
  scheduleId,
  afterRecordedMs,
  deadlineMs,
  pollSeconds,
}) {
  let latestHistory = { events: [] };
  while (Date.now() < deadlineMs) {
    latestHistory = await scheduleHistory(serverUrl, token, namespace, scheduleId);
    const triggers = scheduleTriggeredEvents(latestHistory.events ?? [])
      .filter((event) => eventRecordedMs(event) > afterRecordedMs);

    if (triggers.length > 0) {
      return {
        observed: true,
        schedule_id: scheduleId,
        trigger_count: triggers.length,
        first_trigger: normalizeScheduleEvent(triggers[0]),
        latest_trigger: normalizeScheduleEvent(triggers[triggers.length - 1]),
        history: latestHistory,
      };
    }

    await sleep(pollSeconds * 1000);
  }

  return {
    observed: false,
    schedule_id: scheduleId,
    trigger_count: 0,
    first_trigger: null,
    latest_trigger: null,
    history: latestHistory,
  };
}

async function listSchedules(serverUrl, token, namespace) {
  return apiRequest(serverUrl, token, namespace, 'GET', '/schedules');
}

async function describeSchedule(serverUrl, token, namespace, scheduleId) {
  return apiRequest(serverUrl, token, namespace, 'GET', `/schedules/${encodeURIComponent(scheduleId)}`);
}

async function describeScheduleResult(serverUrl, token, namespace, scheduleId) {
  return apiRequestResult(serverUrl, token, namespace, 'GET', `/schedules/${encodeURIComponent(scheduleId)}`);
}

async function probeCliListDescribe({
  serverUrl,
  token,
  namespace,
  scheduleIds,
  artifactVersions,
  artifactSources,
}) {
  try {
    const cliPath = await resolvePublishedCli(artifactVersions, artifactSources);
    const context = { serverUrl, namespace, token };
    const list = await runDwJson(cliPath, ['schedules', 'list', '--json'], context);
    const descriptions = {};

    for (const scheduleId of scheduleIds) {
      descriptions[scheduleId] = await runDwJson(cliPath, ['schedules', 'describe', scheduleId, '--json'], context);
    }

    const failedCommands = [
      list.exit_code !== 0 ? 'list' : null,
      ...Object.entries(descriptions)
        .filter(([, transcript]) => transcript.exit_code !== 0)
        .map(([scheduleId]) => `describe:${scheduleId}`),
    ].filter(Boolean);
    const outputShapeFailures = [];

    if (!list.parsed_json || typeof list.parsed_json !== 'object') {
      outputShapeFailures.push({ operation: 'list', reason: list.json_parse_error || 'stdout was not a JSON object' });
    }

    for (const [scheduleId, transcript] of Object.entries(descriptions)) {
      if (!transcript.parsed_json || typeof transcript.parsed_json !== 'object') {
        outputShapeFailures.push({
          operation: `describe:${scheduleId}`,
          reason: transcript.json_parse_error || 'stdout was not a JSON object',
        });
      }
    }

    const listContainsAll = scheduleIds.every((scheduleId) => scheduleListContains(list.parsed_json, scheduleId));
    const describeContainsAll = scheduleIds.every((scheduleId) => scheduleIdField(descriptions[scheduleId]?.parsed_json) === scheduleId);

    return {
      observed: failedCommands.length === 0 && outputShapeFailures.length === 0 && listContainsAll && describeContainsAll,
      cli_executable: cliPath,
      list_contains_all: listContainsAll,
      describe_contains_all: describeContainsAll,
      failed_commands: failedCommands,
      output_shape_failures: outputShapeFailures,
      list,
      descriptions,
    };
  } catch (error) {
    return {
      observed: false,
      error: error instanceof Error ? error.message : String(error),
      failed_commands: ['list_describe'],
      output_shape_failures: [],
      list: null,
      descriptions: {},
    };
  }
}

async function probePythonSdkListDescribe({
  serverUrl,
  token,
  namespace,
  scheduleIds,
  artifactVersions,
  artifactSources,
}) {
  const pythonVersion = stringValue(artifactVersions['sdk-python']);
  if (pythonVersion === '') {
    return {
      observed: false,
      error: 'DW_PYTHON_SDK_VERSION is required to install the published Python SDK artifact.',
      list_schedule_ids: [],
      descriptions: [],
    };
  }

  const python = stringValue(process.env.DW_SCHEDULES_PYTHON) || stringValue(process.env.PYTHON) || 'python3';
  const venvDir = path.join(resultDir, 'python-sdk-list-describe-venv');
  const venvPython = path.join(venvDir, process.platform === 'win32' ? 'Scripts/python.exe' : 'bin/python');
  const venvPip = path.join(venvDir, process.platform === 'win32' ? 'Scripts/pip.exe' : 'bin/pip');
  const scriptPath = path.join(resultDir, 'python-sdk-list-describe-probe.py');

  try {
    if (!fs.existsSync(venvPython)) {
      await execLogged(
        python,
        ['-m', 'venv', venvDir],
        path.join(resultDir, 'schedules-python-sdk-venv.log'),
      );
    }

    await execLogged(
      venvPip,
      ['install', '--disable-pip-version-check', `durable-workflow==${pythonVersion}`],
      path.join(resultDir, 'schedules-python-sdk-install.log'),
    );
    writeText(scriptPath, pythonSdkListDescribeProbeSource());

    const transcript = await execCommandCapture(venvPython, [scriptPath], {
      env: {
        ...process.env,
        DW_SCHEDULES_SERVER_URL: serverUrl,
        DW_SCHEDULES_AUTH_TOKEN: token,
        DW_SCHEDULES_NAMESPACE: namespace,
        DW_SCHEDULES_PROBE_IDS: JSON.stringify(scheduleIds),
      },
      timeout: 60000,
      maxBuffer: 1024 * 1024 * 4,
    });

    if (transcript.exit_code !== 0) {
      return {
        observed: false,
        error: transcript.stderr || transcript.stdout || 'Python SDK list/describe probe failed.',
        transcript,
        list_schedule_ids: [],
        descriptions: [],
      };
    }

    const parsed = parseJsonOutput(transcript.stdout);
    if (!parsed.value || typeof parsed.value !== 'object') {
      return {
        observed: false,
        error: parsed.error || 'Python SDK probe did not return JSON.',
        transcript,
        list_schedule_ids: [],
        descriptions: [],
      };
    }

    markArtifactSource(artifactSources, 'sdk-python', 'pypi');

    const listScheduleIds = arrayValue(parsed.value.list_schedule_ids).map((value) => stringValue(value)).filter(Boolean);
    const descriptions = arrayValue(parsed.value.descriptions);
    const listContainsAll = scheduleIds.every((scheduleId) => listScheduleIds.includes(scheduleId));
    const describeContainsAll = scheduleIds.every((scheduleId) => descriptions
      .some((description) => scheduleIdField(description) === scheduleId));

    return {
      observed: listContainsAll && describeContainsAll,
      list_contains_all: listContainsAll,
      describe_contains_all: describeContainsAll,
      list_schedule_ids: listScheduleIds,
      descriptions,
      raw: parsed.value,
      transcript,
    };
  } catch (error) {
    return {
      observed: false,
      error: error instanceof Error ? error.message : String(error),
      list_schedule_ids: [],
      descriptions: [],
    };
  }
}

function pythonSdkListDescribeProbeSource() {
  return `import asyncio
import dataclasses
import json
import os

from durable_workflow.client import Client


def as_dict(value):
    try:
        return dataclasses.asdict(value)
    except TypeError:
        return dict(getattr(value, "__dict__", {}))


async def main():
    schedule_ids = json.loads(os.environ["DW_SCHEDULES_PROBE_IDS"])
    async with Client(
        os.environ["DW_SCHEDULES_SERVER_URL"],
        token=os.environ.get("DW_SCHEDULES_AUTH_TOKEN"),
        namespace=os.environ["DW_SCHEDULES_NAMESPACE"],
    ) as client:
        listed = await client.list_schedules()
        schedules = [as_dict(item) for item in listed.schedules]
        descriptions = []
        for schedule_id in schedule_ids:
            descriptions.append(as_dict(await client.describe_schedule(schedule_id)))
    print(json.dumps({
        "schedule_ids": schedule_ids,
        "list_schedule_ids": [item.get("schedule_id") for item in schedules],
        "schedules": schedules,
        "descriptions": descriptions,
    }, default=str))


asyncio.run(main())
`;
}

function buildListDescribeEvidence({
  scheduleIds,
  httpList,
  httpDescriptions,
  cliVisibility,
  sdkVisibility,
  firstFires,
  artifactVersions,
  artifactSources,
}) {
  const listedSchedules = arrayValue(httpList?.schedules);
  const httpListContainsAll = scheduleIds.every((scheduleId) => listedSchedules
    .some((schedule) => scheduleIdField(schedule) === scheduleId));
  const httpDescribeContainsAll = scheduleIds.every((scheduleId) => scheduleIdField(httpDescriptions[scheduleId]) === scheduleId);
  const allPublicScheduleRecords = [
    ...listedSchedules.filter((schedule) => scheduleIds.includes(scheduleIdField(schedule))),
    ...Object.values(httpDescriptions).filter(Boolean),
  ];
  const cronOrIntervalObserved = scheduleIds.every((scheduleId) => allPublicScheduleRecords
    .filter((schedule) => scheduleIdField(schedule) === scheduleId)
    .some((schedule) => hasCronOrIntervalDefinition(schedule)));
  const lastFireAtObserved = scheduleIds.every((scheduleId) => allPublicScheduleRecords
    .filter((schedule) => scheduleIdField(schedule) === scheduleId)
    .some((schedule) => scheduleTimeField(schedule, ['last_fire_at', 'lastFireAt', 'last_fired_at', 'lastFiredAt']) !== ''));
  const nextFireAtObserved = scheduleIds.every((scheduleId) => allPublicScheduleRecords
    .filter((schedule) => scheduleIdField(schedule) === scheduleId)
    .some((schedule) => scheduleTimeField(schedule, ['next_fire_at', 'nextFireAt']) !== ''));
  const pauseStateObserved = scheduleIds.every((scheduleId) => allPublicScheduleRecords
    .filter((schedule) => scheduleIdField(schedule) === scheduleId)
    .some((schedule) => hasPauseState(schedule)));
  const failures = [];

  if (!httpListContainsAll) {
    failures.push('public HTTP list did not include both active schedules');
  }
  if (!httpDescribeContainsAll) {
    failures.push('public HTTP describe did not return both active schedules');
  }
  if (!cliVisibility.observed) {
    failures.push(`CLI list/describe did not observe both active schedules${cliVisibility.error ? `: ${cliVisibility.error}` : ''}`);
  }
  if (!sdkVisibility.observed) {
    failures.push(`Python SDK list/describe did not observe both active schedules${sdkVisibility.error ? `: ${sdkVisibility.error}` : ''}`);
  }
  if (!cronOrIntervalObserved) {
    failures.push('cron or interval definition was missing from list/describe output');
  }
  if (!lastFireAtObserved) {
    failures.push('last_fire_at/last_fired_at was missing after observed fire windows');
  }
  if (!nextFireAtObserved) {
    failures.push('next_fire_at was missing from list/describe output');
  }
  if (!pauseStateObserved) {
    failures.push('paused/status state was missing from list/describe output');
  }

  return {
    scenario_id: 'list_describe_visibility',
    schedule_ids: scheduleIds,
    public_api_list_observed: httpListContainsAll,
    public_api_describe_observed: httpDescribeContainsAll,
    cli_list_observed: cliVisibility.observed === true,
    sdk_list_observed: sdkVisibility.observed === true,
    cron_or_interval_observed: cronOrIntervalObserved,
    last_fire_at_observed: lastFireAtObserved,
    next_fire_at_observed: nextFireAtObserved,
    pause_state_observed: pauseStateObserved,
    first_fire_observations: firstFires,
    http: {
      list_contains_all: httpListContainsAll,
      describe_contains_all: httpDescribeContainsAll,
      list: httpList,
      descriptions: httpDescriptions,
    },
    cli: cliVisibility,
    'sdk-python': sdkVisibility,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

async function observePauseResumeWindow({
  serverUrl,
  token,
  namespace,
  scheduleId,
  pauseSeconds,
  resumeTimeoutSeconds,
  pollSeconds,
  artifactVersions,
  artifactSources,
}) {
  const pauseRequestedAt = timestamp();
  const pauseResponse = await apiRequest(serverUrl, token, namespace, 'POST', `/schedules/${encodeURIComponent(scheduleId)}/pause`, {
    note: 'schedules conformance pause window',
  });
  const pauseConfirmedAt = timestamp();
  const pauseConfirmedMs = Date.parse(pauseConfirmedAt);
  const pausedDescription = await describeSchedule(serverUrl, token, namespace, scheduleId);

  await sleep(pauseSeconds * 1000);

  const beforeResumeAt = timestamp();
  const beforeResumeHistory = await scheduleHistory(serverUrl, token, namespace, scheduleId);
  const firesDuringPause = scheduleTriggeredEvents(beforeResumeHistory.events ?? [])
    .filter((event) => isEventRecordedBetween(event, pauseConfirmedMs, Date.parse(beforeResumeAt)))
    .map(normalizeScheduleEvent);

  const resumeRequestedAt = timestamp();
  const resumeResponse = await apiRequest(serverUrl, token, namespace, 'POST', `/schedules/${encodeURIComponent(scheduleId)}/resume`, {
    note: 'schedules conformance resume window',
  });
  const resumeConfirmedAt = timestamp();
  const resumeConfirmedMs = Date.parse(resumeConfirmedAt);
  const resumedDescription = await describeSchedule(serverUrl, token, namespace, scheduleId);
  const postResume = await waitForScheduleTrigger({
    serverUrl,
    token,
    namespace,
    scheduleId,
    afterRecordedMs: resumeConfirmedMs,
    deadlineMs: Date.now() + resumeTimeoutSeconds * 1000,
    pollSeconds,
  });
  const postResumeTriggers = scheduleTriggeredEvents(postResume.history?.events ?? [])
    .filter((event) => eventRecordedMs(event) > resumeConfirmedMs);
  const catchupAfterResume = postResumeTriggers
    .filter((event) => {
      const occurrenceMs = eventOccurrenceMs(event);
      return occurrenceMs !== null && occurrenceMs < resumeConfirmedMs;
    })
    .map(normalizeScheduleEvent);
  const failures = [];
  const resumedAfterPause = isScheduleActive(resumedDescription);
  const postResumeFireObserved = postResume.observed === true;
  const postResumeNormalFireObserved = postResumeTriggers.some((event) => {
    const occurrenceMs = eventOccurrenceMs(event);
    return occurrenceMs !== null && occurrenceMs >= resumeConfirmedMs;
  });

  if (firesDuringPause.length > 0) {
    failures.push(`observed ${firesDuringPause.length} fire(s) during the paused window`);
  }
  if (!resumedAfterPause) {
    failures.push('schedule did not return to active state after resume');
  }
  if (!postResumeFireObserved) {
    failures.push('no normal fire was observed after resume');
  }
  if (catchupAfterResume.length > 0) {
    failures.push(`observed ${catchupAfterResume.length} catch-up fire(s) for pause-window occurrence times after resume`);
  }

  return {
    scenario_id: 'pause_resume_no_fire_window',
    schedule_id: scheduleId,
    surface: 'public_http_api',
    pause_requested_at: pauseRequestedAt,
    pause_confirmed_at: pauseConfirmedAt,
    before_resume_at: beforeResumeAt,
    resume_requested_at: resumeRequestedAt,
    resume_confirmed_at: resumeConfirmedAt,
    pause_window_seconds: pauseSeconds,
    fires_during_pause_count: firesDuringPause.length,
    fires_during_pause: firesDuringPause,
    resumed_after_pause: resumedAfterPause,
    post_resume_fire_observed: postResumeFireObserved,
    post_resume_normal_fire_observed: postResumeNormalFireObserved,
    catchup_after_resume_count: catchupAfterResume.length,
    catchup_after_resume: catchupAfterResume,
    first_post_resume_fire: normalizeScheduleEvent(postResumeTriggers[0] ?? null),
    pause_response: pauseResponse,
    resume_response: resumeResponse,
    paused_description: pausedDescription,
    resumed_description: resumedDescription,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

async function observeDeleteWindow({
  serverUrl,
  token,
  namespace,
  scheduleId,
  deleteWindowSeconds,
  artifactVersions,
  artifactSources,
}) {
  const deleteRequestedAt = timestamp();
  const deleteResponse = await apiRequest(serverUrl, token, namespace, 'DELETE', `/schedules/${encodeURIComponent(scheduleId)}`);
  const deleteConfirmedAt = timestamp();
  const deleteConfirmedMs = Date.parse(deleteConfirmedAt);
  const listAfterDelete = await listSchedules(serverUrl, token, namespace);
  const describeAfterDelete = await describeScheduleResult(serverUrl, token, namespace, scheduleId);

  await sleep(deleteWindowSeconds * 1000);

  const historyAfterDelete = await scheduleHistory(serverUrl, token, namespace, scheduleId).catch((error) => ({
    error: error instanceof Error ? error.message : String(error),
    events: [],
  }));
  const historyAvailable = stringValue(historyAfterDelete.error) === '';
  const firesAfterDelete = scheduleTriggeredEvents(historyAfterDelete.events ?? [])
    .filter((event) => eventRecordedMs(event) >= deleteConfirmedMs)
    .map(normalizeScheduleEvent);
  const absentFromList = !scheduleListContains(listAfterDelete, scheduleId);
  const absentFromDescribe = describeAfterDelete.status === 404
    || stringValue(describeAfterDelete.parsed?.reason) === 'schedule_not_found';
  const noFiresAfterDelete = historyAvailable && firesAfterDelete.length === 0;
  const failures = [];

  if (!absentFromList) {
    failures.push('deleted schedule was still present in public list output');
  }
  if (!absentFromDescribe) {
    failures.push(`deleted schedule describe returned ${describeAfterDelete.status} instead of not found`);
  }
  if (!noFiresAfterDelete) {
    failures.push(historyAvailable
      ? `observed ${firesAfterDelete.length} fire(s) after delete`
      : `could not read public schedule history after delete: ${historyAfterDelete.error}`);
  }

  return {
    scenario_id: 'delete_stops_future_fires',
    schedule_id: scheduleId,
    surface: 'public_http_api',
    delete_requested_at: deleteRequestedAt,
    delete_confirmed_at: deleteConfirmedAt,
    observation_window_seconds: deleteWindowSeconds,
    absent_from_list_after_delete: absentFromList,
    absent_from_describe_after_delete: absentFromDescribe,
    describe_after_delete_status: describeAfterDelete.status,
    fires_after_delete_count: firesAfterDelete.length,
    history_available_after_delete: historyAvailable,
    no_fires_after_delete: noFiresAfterDelete,
    fires_after_delete: firesAfterDelete,
    list_after_delete: listAfterDelete,
    describe_after_delete: describeAfterDelete,
    history_after_delete: historyAfterDelete,
    delete_response: deleteResponse,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

function operatorControlsEvidenceFromObservations({
  startedAt,
  finishedAt,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  schedulesCreated,
  listDescribe,
  pauseResume,
  deleteEvidence,
  timing,
}) {
  const observations = {
    list_describe_visibility: listDescribe,
    pause_resume_no_fire_window: pauseResume,
    delete_stops_future_fires: deleteEvidence,
  };
  const scenarioResults = {};
  const findings = [];

  for (const [scenarioId, observation] of Object.entries(observations)) {
    const status = observation.verdict === 'pass' ? 'pass' : 'fail';
    const linkedFindings = status === 'pass' ? [] : [operatorControlsFinding(scenarioId, observation)];
    findings.push(...linkedFindings);
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status,
      observed_outputs: observation,
      linked_findings: linkedFindings,
    };
  }

  return {
    schema: 'durable-workflow.v2.schedules-runtime.operator-controls-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: scenarioResults,
    findings,
    operator_controls: {
      list_describe: listDescribe,
      pause_resume: pauseResume,
      delete: deleteEvidence,
    },
    client_surfaces: {
      'server-http-api': {
        list_observed: listDescribe.public_api_list_observed,
        describe_observed: listDescribe.public_api_describe_observed,
        control_observed: pauseResume.verdict === 'pass' && deleteEvidence.verdict === 'pass',
      },
      cli: {
        list_observed: listDescribe.cli_list_observed,
        describe_observed: listDescribe.cli_list_observed,
      },
      'sdk-python': {
        list_observed: listDescribe.sdk_list_observed,
        describe_observed: listDescribe.sdk_list_observed,
      },
    },
    runtime_matrix: {
      runtimes: ['server-scheduler'],
      client_paths: ['server-http-api', 'cli', 'sdk-python'],
      schedule_types: ['cron_expression', 'fixed_rate_interval'],
    },
    topology: {
      namespace,
      task_queue: taskQueue,
      worker_execution_mode: 'operator_controls_schedule_history_probe',
      schedules_created: schedulesCreated,
    },
    timing,
  };
}

function operatorControlsFinding(scenarioId, observation) {
  const configured = coverageGapFindings[scenarioId] ?? {};
  const observed = arrayValue(observation.failures).join('; ')
    || 'Operator-controls evidence did not satisfy the schedules contract.';
  let owner = 'server';
  if (scenarioId === 'list_describe_visibility') {
    if (observation.public_api_list_observed === true && observation.public_api_describe_observed === true) {
      if (observation.cli_list_observed !== true) {
        owner = 'cli';
      } else if (observation.sdk_list_observed !== true) {
        owner = 'sdk-python';
      } else {
        owner = 'conformance_harness';
      }
    }
  }

  return {
    finding_id: `${stringValue(configured.id) || `schedules-${scenarioId}`}-runtime-finding`,
    scenario_id: scenarioId,
    finding_type: 'schedule_operator_controls_contract_gap',
    owning_surface: owner,
    execution_scope: stringValue(configured.scope) || 'operator-controls-shard',
    artifact_versions: observation.artifact_versions ?? {},
    observed_behavior: observed,
    expected_behavior: stringValue(configured.expected_behavior)
      || 'Schedules list, pause/resume, and delete controls satisfy the published runtime contract.',
    next_acceptance_criterion: arrayValue(configured.acceptance).join('; ')
      || 'rerun the operator-controls shard and observe passing list/describe, pause/resume, and delete evidence',
    observed_outputs: observation,
  };
}

function operatorControlsFailureEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const observations = {
    list_describe_visibility: failedOperatorObservation('list_describe_visibility', reason, artifactVersions, artifactSources),
    pause_resume_no_fire_window: failedOperatorObservation('pause_resume_no_fire_window', reason, artifactVersions, artifactSources),
    delete_stops_future_fires: failedOperatorObservation('delete_stops_future_fires', reason, artifactVersions, artifactSources),
  };

  return operatorControlsEvidenceFromObservations({
    startedAt,
    finishedAt,
    artifactVersions,
    artifactSources,
    namespace: stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance',
    taskQueue: stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-operator-controls',
    schedulesCreated: [],
    listDescribe: observations.list_describe_visibility,
    pauseResume: observations.pause_resume_no_fire_window,
    deleteEvidence: observations.delete_stops_future_fires,
    timing: {},
  });
}

function failedOperatorObservation(scenarioId, reason, artifactVersions, artifactSources) {
  const common = {
    scenario_id: scenarioId,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures: [reason],
    failure_reason: reason,
    verdict: 'fail',
  };

  if (scenarioId === 'pause_resume_no_fire_window') {
    return {
      ...common,
      fires_during_pause_count: -1,
      resumed_after_pause: false,
    };
  }

  if (scenarioId === 'delete_stops_future_fires') {
    return {
      ...common,
      absent_from_list_after_delete: false,
      no_fires_after_delete: false,
    };
  }

  return {
    ...common,
    public_api_list_observed: false,
    public_api_describe_observed: false,
    cli_list_observed: false,
    sdk_list_observed: false,
    last_fire_at_observed: false,
    next_fire_at_observed: false,
    pause_state_observed: false,
  };
}

function operatorControlsBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const scenarios = [
    'list_describe_visibility',
    'pause_resume_no_fire_window',
    'delete_stops_future_fires',
  ];
  const findings = Object.fromEntries(scenarios.map((scenarioId) => [scenarioId, {
    finding_id: `schedules-operator-controls-runner-blocked-${scenarioId}`,
    scenario_id: scenarioId,
    finding_type: 'conformance_runner_blocked',
    owning_surface: 'conformance_harness',
    execution_scope: 'operator-controls-shard',
    artifact_versions: artifactVersions,
    observed_behavior: reason,
    expected_behavior: 'The schedules conformance host can run the operator-controls shard against published artifacts.',
    next_acceptance_criterion: 'restore the missing host capability and rerun schedules conformance',
  }]));

  return {
    schema: 'durable-workflow.v2.schedules-runtime.operator-controls-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: Object.fromEntries(scenarios.map((scenarioId) => [
      scenarioId,
      {
        scenario_id: scenarioId,
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [findings[scenarioId]],
      },
    ])),
    findings: Object.values(findings),
    operator_controls: {
      list_describe: { blocked_reason: reason },
      pause_resume: { blocked_reason: reason },
      delete: { blocked_reason: reason },
    },
  };
}

async function maybeRunMissedRestartShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_MISSED_RESTART_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(missedRestartEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);

  if (!dockerAvailable || !composeAvailable || serverImage === '') {
    if (!explicit) {
      return null;
    }

    const missing = [
      !dockerAvailable ? 'docker' : null,
      dockerAvailable && !composeAvailable ? 'docker compose' : null,
      serverImage === '' ? 'DW_SERVER_VERSION or DW_SERVER_IMAGE' : null,
    ].filter(Boolean).join(', ');

    return missedRestartBlockedEvidence(
      `Missed-fire/restart shard could not start because ${missing} is unavailable.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runMissedRestartShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
    });
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    return missedRestartFailureEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runMissedRestartShard({ startedAt, artifactVersions, artifactSources, serverImage }) {
  const shardStartedAt = timestamp();
  const runId = `schedules-missed-restart-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-missed-restart';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  const serverUrl = `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_SCHEDULER_TICK_SECONDS, 5);
  const missedDowntimeSeconds = Math.max(120, positiveInt(process.env.DW_SCHEDULES_MISSED_FIRE_DOWNTIME_SECONDS, 125));
  const missedResumeTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_MISSED_FIRE_RESUME_TIMEOUT_SECONDS, 170);
  const restartFireDeadlineSeconds = Math.max(90, positiveInt(process.env.DW_SCHEDULES_RESTART_FIRE_DEADLINE_SECONDS, 90));
  const pollSeconds = positiveInt(process.env.DW_SCHEDULES_MISSED_RESTART_POLL_SECONDS, 5);
  const overlayPath = path.join(resultDir, 'schedules-missed-restart-compose.override.yml');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  const env = composeEnv(serverPort, serverImage, token, artifactVersions);
  let composeStarted = false;
  let schedulesCreated = [];

  markArtifactSource(artifactSources, 'server', 'published_docker_image');

  writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
  await execLogged(
    'docker',
    ['image', 'pull', serverImage],
    path.join(resultDir, 'schedules-missed-restart-docker-pull.log'),
  );
  await execLogged(
    'docker',
    ['compose', '-p', composeProject, ...composeFiles, 'up', '-d', 'server'],
    path.join(resultDir, 'schedules-missed-restart-compose-up.log'),
    env,
  );
  composeStarted = true;

  try {
    await waitForServerReady(serverUrl, 120);
    await ensureNamespace(serverUrl, token, namespace);

    const missedScheduleId = `${runId}-missed`;
    schedulesCreated.push(missedScheduleId);
    await createMissedRestartSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: missedScheduleId,
      taskQueue,
      probeName: 'MissedFireProbe',
    });

    const missedCreatedDescription = await describeSchedule(serverUrl, token, namespace, missedScheduleId);
    const schedulerStoppedAt = timestamp();
    await sleep(missedDowntimeSeconds * 1000);
    const schedulerResumeRequestedAt = timestamp();
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'up', '-d', 'scheduler'],
      path.join(resultDir, 'schedules-missed-restart-scheduler-resume.log'),
      env,
    );

    const missedFire = await observeMissedFirePolicy({
      serverUrl,
      token,
      namespace,
      scheduleId: missedScheduleId,
      documentedPolicy: documentedMissedFirePolicy(),
      schedulerStoppedAt,
      schedulerResumeRequestedAt,
      preResumeDescription: missedCreatedDescription,
      downtimeSeconds: missedDowntimeSeconds,
      resumeTimeoutSeconds: missedResumeTimeoutSeconds,
      pollSeconds,
      artifactVersions,
      artifactSources,
    });

    await bestEffortDeleteSchedule(serverUrl, token, namespace, missedScheduleId);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'stop', 'scheduler'],
      path.join(resultDir, 'schedules-missed-restart-scheduler-stop.log'),
      env,
    ).catch(() => {});

    const restartScheduleId = `${runId}-restart`;
    schedulesCreated.push(restartScheduleId);
    await createMissedRestartSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: restartScheduleId,
      taskQueue,
      probeName: 'RestartSurvivalProbe',
    });
    const preRestartList = await listSchedules(serverUrl, token, namespace);
    const preRestartDescription = await describeSchedule(serverUrl, token, namespace, restartScheduleId);
    const serverRestartRequestedAt = timestamp();
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'restart', 'server'],
      path.join(resultDir, 'schedules-missed-restart-server-restart.log'),
      env,
    );
    await waitForServerReady(serverUrl, 120);
    const serverRestartReadyAt = timestamp();
    const postRestartList = await listSchedules(serverUrl, token, namespace);
    const postRestartDescription = await describeSchedule(serverUrl, token, namespace, restartScheduleId);

    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'up', '-d', 'scheduler'],
      path.join(resultDir, 'schedules-missed-restart-scheduler-after-restart.log'),
      env,
    );

    const restartSurvival = await observeRestartSurvival({
      serverUrl,
      token,
      namespace,
      scheduleId: restartScheduleId,
      preRestartList,
      preRestartDescription,
      postRestartList,
      postRestartDescription,
      serverRestartRequestedAt,
      serverRestartReadyAt,
      restartFireDeadlineSeconds,
      pollSeconds,
      artifactVersions,
      artifactSources,
    });

    await bestEffortDeleteSchedule(serverUrl, token, namespace, restartScheduleId);

    const evidence = missedRestartEvidenceFromObservations({
      startedAt: shardStartedAt,
      finishedAt: timestamp(),
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      schedulesCreated,
      missedFire,
      restartSurvival,
      timing: {
        scheduler_tick_seconds: schedulerTickSeconds,
        missed_fire_downtime_seconds: missedDowntimeSeconds,
        missed_fire_resume_timeout_seconds: missedResumeTimeoutSeconds,
        restart_fire_deadline_seconds: restartFireDeadlineSeconds,
      },
    });
    writeJson(missedRestartEvidencePath, evidence);

    return evidence;
  } finally {
    if (composeStarted) {
      await collectMissedRestartComposeLogs(composeProject, composeFiles);
      await execFile('docker', ['compose', '-p', composeProject, ...composeFiles, 'down', '-v'], {
        env,
        maxBuffer: 1024 * 1024 * 8,
      }).catch(() => {});
    }

    writeJson(path.join(resultDir, 'schedules-missed-restart-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.missed-restart-run-metadata',
      started_at: startedAt,
      missed_restart_started_at: shardStartedAt,
      finished_at: timestamp(),
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      server_image: serverImage,
      compose_project: composeProject,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
      schedules_created: schedulesCreated,
    });
  }
}

async function createMissedRestartSchedule({
  serverUrl,
  token,
  namespace,
  scheduleId,
  taskQueue,
  probeName,
}) {
  await apiRequest(serverUrl, token, namespace, 'POST', '/schedules', {
    schedule_id: scheduleId,
    spec: { cron_expressions: ['* * * * *'], timezone: 'UTC' },
    action: {
      workflow_type: `schedules.${probeName}`,
      task_queue: taskQueue,
      input: [{ schedule_id: scheduleId }],
    },
    overlap_policy: 'allow_all',
    jitter_seconds: 0,
  });
}

async function observeMissedFirePolicy({
  serverUrl,
  token,
  namespace,
  scheduleId,
  documentedPolicy,
  schedulerStoppedAt,
  schedulerResumeRequestedAt,
  preResumeDescription,
  downtimeSeconds,
  resumeTimeoutSeconds,
  pollSeconds,
  artifactVersions,
  artifactSources,
}) {
  const resumeRequestedMs = Date.parse(schedulerResumeRequestedAt);
  const deadlineMs = Date.now() + resumeTimeoutSeconds * 1000;
  let latestHistory = { events: [] };
  let postResumeTriggers = [];
  let catchupTriggers = [];
  let normalTriggers = [];

  while (Date.now() < deadlineMs) {
    latestHistory = await scheduleHistory(serverUrl, token, namespace, scheduleId);
    postResumeTriggers = scheduleTriggeredEvents(latestHistory.events ?? [])
      .filter((event) => eventRecordedMs(event) >= resumeRequestedMs);
    catchupTriggers = postResumeTriggers.filter((event) => {
      const occurrenceMs = eventOccurrenceMs(event);
      return occurrenceMs !== null && occurrenceMs < resumeRequestedMs;
    });
    normalTriggers = postResumeTriggers.filter((event) => {
      const occurrenceMs = eventOccurrenceMs(event);
      return occurrenceMs !== null && occurrenceMs >= resumeRequestedMs;
    });

    if (catchupTriggers.length > 0 && normalTriggers.length > 0) {
      break;
    }

    await sleep(pollSeconds * 1000);
  }

  const catchupFireCount = catchupTriggers.length;
  const postResumeNormalFireObserved = normalTriggers.length > 0;
  const observedPolicy = inferMissedFirePolicy(catchupFireCount, postResumeNormalFireObserved);
  const failures = [];

  if (documentedPolicy !== 'fire_once_on_resume_then_skip_remaining_missed') {
    failures.push(`documented policy was ${documentedPolicy || '<missing>'}`);
  }
  if (observedPolicy !== 'fire_once_on_resume_then_skip_remaining_missed') {
    failures.push(`observed policy was ${observedPolicy}`);
  }
  if (catchupFireCount !== 1) {
    failures.push(`observed ${catchupFireCount} catch-up fire(s); expected exactly 1`);
  }
  if (!postResumeNormalFireObserved) {
    failures.push('no later normal fire was observed after scheduler evaluation resumed');
  }

  return {
    scenario_id: 'missed_fire_policy',
    schedule_id: scheduleId,
    documented_policy: documentedPolicy,
    observed_policy: observedPolicy,
    catchup_fire_count: catchupFireCount,
    post_resume_normal_fire_observed: postResumeNormalFireObserved,
    scheduler_stopped_at: schedulerStoppedAt,
    scheduler_resume_requested_at: schedulerResumeRequestedAt,
    downtime_seconds: downtimeSeconds,
    resume_timeout_seconds: resumeTimeoutSeconds,
    stored_overdue_occurrence_time: scheduleTimeField(preResumeDescription, ['next_fire_at', 'nextFireAt', 'next_fire', 'nextFire']),
    catchup_fires: catchupTriggers.map(normalizeScheduleEvent).filter(Boolean),
    normal_fires_after_resume: normalTriggers.map(normalizeScheduleEvent).filter(Boolean),
    post_resume_trigger_count: postResumeTriggers.length,
    history_after_resume: latestHistory,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

async function observeRestartSurvival({
  serverUrl,
  token,
  namespace,
  scheduleId,
  preRestartList,
  preRestartDescription,
  postRestartList,
  postRestartDescription,
  serverRestartRequestedAt,
  serverRestartReadyAt,
  restartFireDeadlineSeconds,
  pollSeconds,
  artifactVersions,
  artifactSources,
}) {
  const restartRequestedMs = Date.parse(serverRestartRequestedAt);
  const readyMs = Date.parse(serverRestartReadyAt);
  const listedBeforeRestart = scheduleListContains(preRestartList, scheduleId)
    || scheduleIdField(preRestartDescription) === scheduleId;
  const listedAfterRestart = scheduleListContains(postRestartList, scheduleId)
    || scheduleIdField(postRestartDescription) === scheduleId;
  const trigger = await waitForScheduleTrigger({
    serverUrl,
    token,
    namespace,
    scheduleId,
    afterRecordedMs: restartRequestedMs,
    deadlineMs: Date.now() + restartFireDeadlineSeconds * 1000,
    pollSeconds,
  });
  const fireRecordedMs = eventRecordedMs(trigger.first_trigger);
  const firedAfterRestart = trigger.observed === true && fireRecordedMs >= readyMs;
  const fireWithinRestartDeadline = firedAfterRestart
    && fireRecordedMs <= readyMs + restartFireDeadlineSeconds * 1000;
  const failures = [];

  if (!listedBeforeRestart) {
    failures.push('schedule was not listed before restart');
  }
  if (!listedAfterRestart) {
    failures.push('schedule was not listed after restart with durable storage preserved');
  }
  if (!firedAfterRestart) {
    failures.push('no schedule fire was observed after server restart');
  } else if (!fireWithinRestartDeadline) {
    failures.push(`schedule fired after the ${restartFireDeadlineSeconds}s restart deadline`);
  }

  return {
    scenario_id: 'restart_survival',
    schedule_id: scheduleId,
    schedule_listed_before_restart: listedBeforeRestart,
    schedule_listed_after_restart: listedAfterRestart,
    fired_after_restart: firedAfterRestart,
    fire_within_restart_deadline: fireWithinRestartDeadline,
    restart_deadline_seconds: restartFireDeadlineSeconds,
    server_restart_requested_at: serverRestartRequestedAt,
    server_restart_ready_at: serverRestartReadyAt,
    first_fire_after_restart: trigger.first_trigger,
    trigger_after_restart: trigger,
    pre_restart_list: preRestartList,
    pre_restart_description: preRestartDescription,
    post_restart_list: postRestartList,
    post_restart_description: postRestartDescription,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

function missedRestartEvidenceFromObservations({
  startedAt,
  finishedAt,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  schedulesCreated,
  missedFire,
  restartSurvival,
  timing,
}) {
  const observations = {
    missed_fire_policy: missedFire,
    restart_survival: restartSurvival,
  };
  const scenarioResults = {};
  const findings = [];

  for (const [scenarioId, observation] of Object.entries(observations)) {
    const status = observation.verdict === 'pass'
      ? 'pass'
      : (observation.verdict === 'runner_blocked' ? 'runner_blocked' : 'fail');
    const linkedFindings = status === 'pass' ? [] : [missedRestartFinding(scenarioId, observation)];
    findings.push(...linkedFindings);
    scenarioResults[scenarioId] = {
      scenario_id: scenarioId,
      status,
      observed_outputs: observation,
      linked_findings: linkedFindings,
    };
  }

  return {
    schema: 'durable-workflow.v2.schedules-runtime.missed-restart-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: scenarioResults,
    findings,
    missed_fire_policy: missedFire,
    restart_survival: restartSurvival,
    topology: {
      namespace,
      task_queue: taskQueue,
      worker_execution_mode: 'missed_fire_restart_schedule_history_probe',
      schedules_created: schedulesCreated,
    },
    runtime_matrix: {
      runtimes: ['server-scheduler'],
      client_paths: ['server-http-api'],
      schedule_types: ['cron_expression'],
    },
    timing,
  };
}

function missedRestartFinding(scenarioId, observation) {
  const configured = coverageGapFindings[scenarioId] ?? {};
  const observed = arrayValue(observation.failures).join('; ')
    || stringValue(observation.failure_reason)
    || 'Missed-fire/restart evidence did not satisfy the schedules contract.';
  const runnerBlocked = observation.verdict === 'runner_blocked';
  const productFindingType = scenarioId === 'missed_fire_policy'
    ? 'schedule_missed_fire_policy_contract_gap'
    : 'schedule_restart_survival_contract_gap';
  const expectedBehavior = stringValue(configured.expected_behavior)
    || 'Schedules survive scheduler/server restart boundaries and resume firing according to policy.';
  const nextAcceptance = arrayValue(configured.acceptance).join('; ')
    || 'rerun the missed-fire/restart shard and observe passing evidence';

  return {
    finding_id: runnerBlocked
      ? `schedules-missed-restart-runner-blocked-${scenarioId}`
      : `${stringValue(configured.id) || `schedules-${scenarioId}`}-runtime-finding`,
    scenario_id: scenarioId,
    finding_type: runnerBlocked ? 'conformance_runner_blocked' : productFindingType,
    owning_surface: runnerBlocked ? 'conformance_harness' : 'server',
    execution_scope: stringValue(configured.scope) || 'missed-fire-restart-shard',
    artifact_versions: observation.artifact_versions ?? {},
    observed_behavior: observed,
    expected_behavior: runnerBlocked
      ? 'The schedules conformance host can run the missed-fire/restart shard against published artifacts.'
      : expectedBehavior,
    next_acceptance_criterion: runnerBlocked
      ? 'restore the missing host capability and rerun schedules conformance'
      : nextAcceptance,
    observed_outputs: observation,
  };
}

function missedRestartFailureEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  return missedRestartEvidenceFromObservations({
    startedAt,
    finishedAt,
    artifactVersions,
    artifactSources,
    namespace: stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance',
    taskQueue: stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-missed-restart',
    schedulesCreated: [],
    missedFire: failedMissedRestartObservation('missed_fire_policy', reason, artifactVersions, artifactSources),
    restartSurvival: failedMissedRestartObservation('restart_survival', reason, artifactVersions, artifactSources),
    timing: {},
  });
}

function missedRestartBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  return missedRestartEvidenceFromObservations({
    startedAt,
    finishedAt,
    artifactVersions,
    artifactSources,
    namespace: stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance',
    taskQueue: stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-missed-restart',
    schedulesCreated: [],
    missedFire: blockedMissedRestartObservation('missed_fire_policy', reason, artifactVersions, artifactSources),
    restartSurvival: blockedMissedRestartObservation('restart_survival', reason, artifactVersions, artifactSources),
    timing: {},
  });
}

function failedMissedRestartObservation(scenarioId, reason, artifactVersions, artifactSources) {
  const common = {
    scenario_id: scenarioId,
    schedule_id: null,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures: [reason],
    failure_reason: reason,
    verdict: 'fail',
  };

  if (scenarioId === 'missed_fire_policy') {
    return {
      ...common,
      documented_policy: documentedMissedFirePolicy(),
      observed_policy: 'not_observed',
      catchup_fire_count: -1,
      post_resume_normal_fire_observed: false,
    };
  }

  return {
    ...common,
    schedule_listed_after_restart: false,
    fired_after_restart: false,
    fire_within_restart_deadline: false,
  };
}

function blockedMissedRestartObservation(scenarioId, reason, artifactVersions, artifactSources) {
  return {
    ...failedMissedRestartObservation(scenarioId, reason, artifactVersions, artifactSources),
    failures: [reason],
    blocked_reason: reason,
    verdict: 'runner_blocked',
  };
}

function inferMissedFirePolicy(catchupFireCount, postResumeNormalFireObserved) {
  if (catchupFireCount === 1 && postResumeNormalFireObserved) {
    return 'fire_once_on_resume_then_skip_remaining_missed';
  }

  if (catchupFireCount === 0 && postResumeNormalFireObserved) {
    return 'skip_missed';
  }

  if (catchupFireCount > 1) {
    return 'fire_all_missed';
  }

  if (catchupFireCount === 1) {
    return 'fire_once_on_resume_without_later_normal_fire';
  }

  return 'not_observed';
}

function documentedMissedFirePolicy() {
  return stringValue(scenarioManifest.schedule_policy?.missed_fire_policy)
    || 'fire_once_on_resume_then_skip_remaining_missed';
}

async function collectMissedRestartComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-missed-restart-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

function hasCronOrIntervalDefinition(schedule) {
  const spec = schedule && typeof schedule === 'object' && schedule.spec && typeof schedule.spec === 'object'
    ? schedule.spec
    : {};
  return arrayValue(spec.cron_expressions ?? spec.cronExpressions).length > 0
    || arrayValue(spec.intervals).length > 0
    || stringValue(schedule?.cron ?? schedule?.cron_expression ?? schedule?.cronExpression) !== ''
    || stringValue(schedule?.interval) !== '';
}

function hasPauseState(schedule) {
  if (!schedule || typeof schedule !== 'object') {
    return false;
  }

  if (typeof schedule.paused === 'boolean') {
    return true;
  }

  return ['active', 'paused'].includes(stringValue(schedule.status).toLowerCase());
}

function isScheduleActive(schedule) {
  if (!schedule || typeof schedule !== 'object') {
    return false;
  }

  if (typeof schedule.paused === 'boolean') {
    return schedule.paused === false;
  }

  return stringValue(schedule.status).toLowerCase() === 'active';
}

function scheduleTimeField(schedule, names) {
  if (!schedule || typeof schedule !== 'object') {
    return '';
  }

  for (const name of names) {
    const value = stringValue(schedule[name]);
    if (value !== '') {
      return value;
    }
  }

  return '';
}

function scheduleTriggeredEvents(events) {
  return arrayValue(events)
    .filter((event) => stringValue(event.event_type ?? event.eventType) === 'ScheduleTriggered')
    .sort((left, right) => eventRecordedMs(left) - eventRecordedMs(right));
}

function eventRecordedMs(event) {
  const parsed = Date.parse(stringValue(event?.recorded_at ?? event?.recordedAt));
  return Number.isFinite(parsed) ? parsed : 0;
}

function eventOccurrenceMs(event) {
  const raw = stringValue(event?.payload?.occurrence_time ?? event?.payload?.occurrenceTime);
  const parsed = Date.parse(raw);
  return Number.isFinite(parsed) ? parsed : null;
}

function isEventRecordedBetween(event, startMs, endMs) {
  const recordedMs = eventRecordedMs(event);
  return recordedMs >= startMs && recordedMs <= endMs;
}

function normalizeScheduleEvent(event) {
  if (!event || typeof event !== 'object') {
    return null;
  }

  return {
    sequence: event.sequence ?? null,
    event_type: stringValue(event.event_type ?? event.eventType),
    recorded_at: stringValue(event.recorded_at ?? event.recordedAt),
    occurrence_time: stringValue(event.payload?.occurrence_time ?? event.payload?.occurrenceTime),
    workflow_instance_id: stringValue(event.workflow_instance_id ?? event.workflowInstanceId),
    workflow_run_id: stringValue(event.workflow_run_id ?? event.workflowRunId),
    payload: event.payload ?? {},
  };
}

async function collectOperatorControlsComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-operator-controls-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

async function maybeRunCliSurfaceShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_CLI_SURFACE_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(cliEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const serverUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);
  const configuredCli = stringValue(process.env.DW_SCHEDULES_CLI_EXECUTABLE ?? process.env.DW_CLI_EXECUTABLE);
  const cliVersion = stringValue(artifactVersions.cli);

  if (configuredCli === '' && cliVersion === '') {
    if (!explicit) {
      return null;
    }

    return cliSurfaceBlockedEvidence(
      'CLI surface shard could not run because DW_CLI_VERSION or DW_SCHEDULES_CLI_EXECUTABLE is unavailable.',
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  if (serverUrl === '' && (!dockerAvailable || !composeAvailable || serverImage === '')) {
    if (!explicit) {
      return null;
    }

    const missing = [
      !dockerAvailable ? 'docker' : null,
      dockerAvailable && !composeAvailable ? 'docker compose' : null,
      serverImage === '' ? 'DW_SERVER_VERSION or DW_SERVER_IMAGE' : null,
    ].filter(Boolean).join(', ');

    return cliSurfaceBlockedEvidence(
      `CLI surface shard could not start because ${missing} is unavailable.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runCliSurfaceShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    return cliSurfaceBlockedEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runCliSurfaceShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const cliStartedAt = timestamp();
  const runId = `schedules-cli-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-cli-surface';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  const serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const composeFiles = ['-f', path.join(repoRoot, 'docker-compose.published.yml')];
  let composeStarted = false;
  let cliPath = '';

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url');

  if (existingServerUrl === '') {
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-cli-docker-pull.log'),
    );
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-cli',
      services: ['server'],
    });
    composeStarted = true;
  }

  try {
    await waitForServerReady(serverUrl, 120);
    await ensureNamespace(serverUrl, token, namespace);
    cliPath = await resolvePublishedCli(artifactVersions, artifactSources);

    const scheduleId = `${runId}-surface`;
    const context = { serverUrl, namespace, token };
    const operations = {};

    operations.create = await runDwJson(cliPath, [
      'schedules',
      'create',
      `--schedule-id=${scheduleId}`,
      '--workflow-type=schedules.CliSurfaceProbe',
      '--interval=PT1H',
      `--task-queue=${taskQueue}`,
      '--paused',
      '--json',
    ], context);
    operations.describe = await runDwJson(cliPath, ['schedules', 'describe', scheduleId, '--json'], context);
    operations.list = await runDwJson(cliPath, ['schedules', 'list', '--json'], context);
    operations.resume = await runDwJson(cliPath, ['schedules', 'resume', scheduleId, '--note=schedules conformance CLI resume', '--json'], context);
    operations.trigger = await runDwJson(cliPath, ['schedules', 'trigger', scheduleId, '--json'], context);
    operations.pause = await runDwJson(cliPath, ['schedules', 'pause', scheduleId, '--note=schedules conformance CLI pause', '--json'], context);
    operations.delete = await runDwJson(cliPath, ['schedules', 'delete', scheduleId, '--json'], context);

    await bestEffortDeleteSchedule(serverUrl, token, namespace, scheduleId);

    const evidence = cliSurfaceEvidenceFromOperations({
      operations,
      startedAt: cliStartedAt,
      finishedAt: timestamp(),
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      scheduleId,
      cliPath,
    });
    writeJson(cliEvidencePath, evidence);

    return evidence;
  } finally {
    if (cliPath !== '') {
      writeJson(path.join(resultDir, 'schedules-cli-run-metadata.json'), {
        schema: 'durable-workflow.v2.schedules-runtime.cli-run-metadata',
        started_at: startedAt,
        cli_started_at: cliStartedAt,
        finished_at: timestamp(),
        server_url: serverUrl,
        namespace,
        task_queue: taskQueue,
        server_image: existingServerUrl === '' ? serverImage : null,
        compose_project: existingServerUrl === '' ? composeProject : null,
        cli_executable: cliPath,
        published_artifact_versions: artifactVersions,
        artifact_sources: artifactSources,
        local_product_source_checkouts_used: false,
      });
    }

    if (composeStarted) {
      await collectCliComposeLogs(composeProject, composeFiles);
      await execFile('docker', ['compose', '-p', composeProject, ...composeFiles, 'down', '-v'], {
        env: composeEnv(serverPort, serverImage, token, artifactVersions),
        maxBuffer: 1024 * 1024 * 8,
      }).catch(() => {});
    }
  }
}

async function resolvePublishedCli(artifactVersions, artifactSources) {
  const configuredCli = stringValue(process.env.DW_SCHEDULES_CLI_EXECUTABLE ?? process.env.DW_CLI_EXECUTABLE);
  if (configuredCli !== '') {
    fs.accessSync(configuredCli, fs.constants.X_OK);
    markArtifactSource(artifactSources, 'cli', 'official_cli_executable');
    return configuredCli;
  }

  const cliVersion = stringValue(artifactVersions.cli);
  if (cliVersion === '') {
    throw new Error('DW_CLI_VERSION is required to install the official CLI artifact.');
  }

  const installDir = path.join(resultDir, 'cli', 'bin');
  const installerPath = path.join(resultDir, 'cli', 'install.sh');
  fs.mkdirSync(installDir, { recursive: true });
  fs.mkdirSync(path.dirname(installerPath), { recursive: true });

  const installerUrl = await downloadCliInstaller(cliVersion, installerPath);
  const installLogPath = path.join(resultDir, 'schedules-cli-install.log');
  const install = await execCommandCapture('sh', [installerPath], {
    env: {
      ...process.env,
      VERSION: cliVersion,
      DURABLE_WORKFLOW_INSTALL_DIR: installDir,
      DURABLE_WORKFLOW_INSTALL_VERIFY_ATTESTATIONS: '0',
    },
    timeout: 120000,
  });
  writeText(installLogPath, `${install.stdout}${install.stderr}`);
  if (install.exit_code !== 0) {
    throw new Error(`official CLI installer failed for release ${cliVersion}; see ${path.basename(installLogPath)}`);
  }

  const cliPath = path.join(installDir, 'dw');
  fs.accessSync(cliPath, fs.constants.X_OK);
  markArtifactSource(artifactSources, 'cli', 'official_install_script');
  writeJson(path.join(resultDir, 'schedules-cli-install.json'), {
    schema: 'durable-workflow.v2.schedules-runtime.cli-install',
    cli_version: cliVersion,
    installer_url: installerUrl,
    install_dir: installDir,
    executable: cliPath,
    source: 'official_install_script',
  });

  return cliPath;
}

async function downloadCliInstaller(cliVersion, installerPath) {
  const explicit = stringValue(process.env.DW_SCHEDULES_CLI_INSTALLER_URL ?? process.env.DW_CLI_INSTALLER_URL);
  const normalized = cliVersion.replace(/^v/, '');
  const candidates = [
    explicit,
    `https://github.com/durable-workflow/cli/releases/download/${normalized}/install.sh`,
    `https://github.com/durable-workflow/cli/releases/download/v${normalized}/install.sh`,
  ].filter((value, index, values) => value !== '' && values.indexOf(value) === index);

  const errors = [];
  for (const url of candidates) {
    try {
      await downloadUrlToFile(url, installerPath);
      return url;
    } catch (error) {
      errors.push(`${url}: ${error instanceof Error ? error.message : String(error)}`);
    }
  }

  throw new Error(`official CLI installer is not downloadable for release ${cliVersion}; ${errors.join('; ')}`);
}

async function downloadUrlToFile(url, filePath) {
  if (typeof fetch === 'function') {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const body = Buffer.from(await response.arrayBuffer());
    if (body.length === 0) {
      throw new Error('downloaded file is empty');
    }
    writeText(filePath, body.toString('utf8'));
    return;
  }

  await execLogged('curl', ['-fsSL', '--retry', '3', '-o', filePath, url], `${filePath}.download.log`);
}

async function startPublishedComposeServices({
  composeProject,
  composeFiles,
  serverPort,
  serverImage,
  token,
  artifactVersions,
  logPrefix,
  services,
}) {
  const env = composeEnv(serverPort, serverImage, token, artifactVersions);
  const baseArgs = ['compose', '-p', composeProject, ...composeFiles];

  await execLogged(
    'docker',
    [...baseArgs, 'up', '-d', 'mysql', 'redis'],
    path.join(resultDir, `${logPrefix}-compose-dependencies-up.log`),
    env,
  );
  await execLogged(
    'docker',
    [...baseArgs, 'run', '--rm', 'bootstrap'],
    path.join(resultDir, `${logPrefix}-bootstrap.log`),
    env,
  );
  await execLogged(
    'docker',
    [...baseArgs, 'up', '-d', '--no-deps', ...services],
    path.join(resultDir, `${logPrefix}-compose-up.log`),
    env,
  );
}

async function ensureNamespace(serverUrl, token, namespace) {
  const normalized = stringValue(namespace).toLowerCase();
  if (normalized === '') {
    return;
  }

  const base = serverUrl.replace(/\/+$/, '');
  const response = await fetch(`${base}/api/namespaces`, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      'X-Durable-Workflow-Control-Plane-Version': '2',
    },
    body: JSON.stringify({
      name: normalized,
      description: 'Schedules conformance namespace',
      retention_days: 1,
    }),
  });

  if (response.status === 201 || response.status === 409) {
    return;
  }

  const text = await response.text();
  throw new Error(`POST /api/namespaces returned ${response.status}: ${text.slice(0, 1000)}`);
}

async function runDwJson(cliPath, args, context) {
  const fullArgs = [
    ...args,
    `--server=${context.serverUrl}`,
    `--namespace=${context.namespace}`,
  ];
  if (context.token !== '') {
    fullArgs.push(`--token=${context.token}`);
  }

  const transcript = await execCommandCapture(cliPath, fullArgs, {
    env: {
      ...process.env,
      DURABLE_WORKFLOW_SERVER_URL: context.serverUrl,
      DURABLE_WORKFLOW_NAMESPACE: context.namespace,
    },
    timeout: 45000,
  });
  const parsed = parseJsonOutput(transcript.stdout);

  return {
    command: ['dw', ...fullArgs.map(redactCliArg)],
    exit_code: transcript.exit_code,
    stdout: transcript.stdout,
    stderr: transcript.stderr,
    parsed_json: parsed.value,
    json_parse_error: parsed.error,
  };
}

async function execCommandCapture(command, args, options = {}) {
  try {
    const result = await execFile(command, args, {
      env: options.env ?? process.env,
      timeout: options.timeout ?? 30000,
      maxBuffer: options.maxBuffer ?? 1024 * 1024 * 4,
    });

    return {
      exit_code: 0,
      stdout: String(result.stdout ?? ''),
      stderr: String(result.stderr ?? ''),
    };
  } catch (error) {
    return {
      exit_code: Number.isInteger(error?.code) ? error.code : 1,
      stdout: String(error?.stdout ?? ''),
      stderr: String(error?.stderr ?? error?.message ?? ''),
    };
  }
}

function cliSurfaceEvidenceFromOperations({
  operations,
  startedAt,
  finishedAt,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  scheduleId,
  cliPath,
}) {
  const checks = cliSurfaceChecks(operations, scheduleId);
  const observedOutputs = {
    create_or_observe: checks.createObserved,
    list_observed: checks.listObserved && checks.describeObserved,
    describe_observed: checks.describeObserved,
    control_observed: checks.controlObserved,
    schedule_id: scheduleId,
    namespace,
    task_queue: taskQueue,
    cli_executable: cliPath,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    command_outputs: operations,
    failed_commands: checks.failedCommands,
    unsupported_commands: checks.unsupportedCommands,
    output_shape_failures: checks.outputShapeFailures,
  };
  const status = checks.passed
    ? 'pass'
    : (checks.unsupportedCommands.length > 0 ? 'unsupported' : 'fail');
  const linkedFindings = status === 'pass'
    ? []
    : [cliSurfaceFinding(status, checks, observedOutputs, artifactVersions)];

  return {
    schema: 'durable-workflow.v2.schedules-runtime.cli-surface-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: {
      cli_schedule_surface: {
        scenario_id: 'cli_schedule_surface',
        status,
        observed_outputs: observedOutputs,
        linked_findings: linkedFindings,
      },
    },
    findings: linkedFindings,
    client_surfaces: {
      cli: observedOutputs,
    },
    runtime_matrix: {
      client_paths: ['cli'],
    },
    topology: {
      namespace,
      task_queue: taskQueue,
      worker_execution_mode: 'official_cli_schedule_lifecycle_surface',
      schedules_created: [scheduleId],
    },
  };
}

function cliSurfaceChecks(operations, scheduleId) {
  const failedCommands = [];
  const unsupportedCommands = [];
  const outputShapeFailures = [];

  for (const [operation, transcript] of Object.entries(operations)) {
    if (transcript.exit_code !== 0) {
      failedCommands.push(operation);
      if (isUnsupportedCliCommand(transcript)) {
        unsupportedCommands.push(operation);
      }
      continue;
    }

    if (!transcript.parsed_json || typeof transcript.parsed_json !== 'object') {
      outputShapeFailures.push({ operation, reason: transcript.json_parse_error || 'stdout was not a JSON object' });
    }
  }

  const createObserved = scheduleIdField(operations.create?.parsed_json) === scheduleId;
  const describeObserved = scheduleIdField(operations.describe?.parsed_json) === scheduleId;
  const listObserved = scheduleListContains(operations.list?.parsed_json, scheduleId);
  const pauseObserved = scheduleIdField(operations.pause?.parsed_json) === scheduleId;
  const resumeObserved = scheduleIdField(operations.resume?.parsed_json) === scheduleId;
  const triggerObserved = scheduleIdField(operations.trigger?.parsed_json) === scheduleId
    && Object.prototype.hasOwnProperty.call(operations.trigger?.parsed_json ?? {}, 'outcome');
  const deleteObserved = scheduleIdField(operations.delete?.parsed_json) === scheduleId;

  if (!createObserved) {
    outputShapeFailures.push({ operation: 'create', reason: 'JSON response did not include the created schedule_id' });
  }
  if (!describeObserved) {
    outputShapeFailures.push({ operation: 'describe', reason: 'JSON response did not include the described schedule_id' });
  }
  if (!listObserved) {
    outputShapeFailures.push({ operation: 'list', reason: 'JSON response did not include the schedule in schedules[]' });
  }
  for (const [operation, observed] of Object.entries({
    pause: pauseObserved,
    resume: resumeObserved,
    trigger: triggerObserved,
    delete: deleteObserved,
  })) {
    if (!observed) {
      outputShapeFailures.push({ operation, reason: 'JSON response did not confirm the target schedule lifecycle operation' });
    }
  }

  const controlObserved = pauseObserved && resumeObserved && triggerObserved && deleteObserved;
  const passed = failedCommands.length === 0
    && outputShapeFailures.length === 0
    && createObserved
    && describeObserved
    && listObserved
    && controlObserved;

  return {
    passed,
    createObserved,
    describeObserved,
    listObserved,
    controlObserved,
    failedCommands,
    unsupportedCommands,
    outputShapeFailures,
  };
}

function cliSurfaceFinding(status, checks, observedOutputs, artifactVersions) {
  const reasons = [];
  if (checks.unsupportedCommands.length > 0) {
    reasons.push(`unsupported dw schedules command(s): ${checks.unsupportedCommands.join(', ')}`);
  }
  if (checks.failedCommands.length > 0) {
    reasons.push(`failed dw schedules command(s): ${checks.failedCommands.join(', ')}`);
  }
  for (const failure of checks.outputShapeFailures) {
    reasons.push(`${failure.operation} output shape: ${failure.reason}`);
  }

  return {
    finding_id: status === 'unsupported'
      ? 'schedules-cli-surface-unsupported-command'
      : 'schedules-cli-surface-command-output',
    scenario_id: 'cli_schedule_surface',
    finding_type: status === 'unsupported'
      ? 'cli_schedule_command_unsupported'
      : 'cli_schedule_surface_gap',
    owning_surface: 'cli',
    execution_scope: 'cli-schedule-surface-shard',
    artifact_versions: artifactVersions,
    observed_behavior: reasons.join('; ') || 'The official CLI schedule lifecycle surface did not satisfy the JSON evidence contract.',
    expected_behavior: 'The official dw schedules surface creates or observes a schedule and exposes list, describe, pause, resume, trigger, and delete as machine-readable JSON.',
    next_acceptance_criterion: 'rerun schedules conformance with dw schedules lifecycle commands returning parseable JSON and confirming the target schedule',
    command_outputs: observedOutputs.command_outputs,
    failed_commands: observedOutputs.failed_commands,
    unsupported_commands: observedOutputs.unsupported_commands,
    output_shape_failures: observedOutputs.output_shape_failures,
  };
}

function cliSurfaceBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const finding = {
    finding_id: 'schedules-cli-surface-runner-blocked',
    scenario_id: 'cli_schedule_surface',
    finding_type: 'conformance_runner_blocked',
    owning_surface: 'conformance_harness',
    execution_scope: 'cli-schedule-surface-shard',
    artifact_versions: artifactVersions,
    observed_behavior: reason,
    expected_behavior: 'The schedules conformance host can install the official CLI and run its schedule lifecycle surface against published artifacts.',
    next_acceptance_criterion: 'restore the missing host capability and rerun schedules conformance',
  };

  return {
    schema: 'durable-workflow.v2.schedules-runtime.cli-surface-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: {
      cli_schedule_surface: {
        scenario_id: 'cli_schedule_surface',
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [finding],
      },
    },
    findings: [finding],
    client_surfaces: {
      cli: {
        create_or_observe: false,
        list_observed: false,
        control_observed: false,
        blocked_reason: reason,
      },
    },
  };
}

function parseJsonOutput(stdout) {
  const trimmed = String(stdout ?? '').trim();
  if (trimmed === '') {
    return { value: null, error: 'stdout was empty' };
  }

  try {
    return { value: JSON.parse(trimmed), error: null };
  } catch (error) {
    return { value: null, error: error instanceof Error ? error.message : String(error) };
  }
}

function scheduleIdField(value) {
  if (!value || typeof value !== 'object') {
    return '';
  }

  return stringValue(value.schedule_id ?? value.scheduleId);
}

function scheduleListContains(value, scheduleId) {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const schedules = arrayValue(value.schedules);
  return schedules.some((schedule) => scheduleIdField(schedule) === scheduleId);
}

function isUnsupportedCliCommand(transcript) {
  const text = `${transcript.stdout ?? ''}\n${transcript.stderr ?? ''}`.toLowerCase();
  return /command .* not defined|no commands defined|unknown command|does not exist|not enough arguments/.test(text);
}

function redactCliArg(arg) {
  if (String(arg).startsWith('--token=')) {
    return '--token=<redacted>';
  }

  return arg;
}

function markArtifactSource(artifactSources, artifact, source) {
  const current = stringValue(artifactSources[artifact]);
  if (current === '' || current === 'not_exercised') {
    artifactSources[artifact] = source;
  }
}

async function collectCliComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-cli-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

async function scheduleHistory(serverUrl, token, namespace, scheduleId) {
  return apiRequest(serverUrl, token, namespace, 'GET', `/schedules/${encodeURIComponent(scheduleId)}/history?limit=100`);
}

async function bestEffortDeleteSchedule(serverUrl, token, namespace, scheduleId) {
  await apiRequest(serverUrl, token, namespace, 'DELETE', `/schedules/${encodeURIComponent(scheduleId)}`).catch(() => {});
}

async function apiRequest(serverUrl, token, namespace, method, pathAndQuery, body = null) {
  const result = await apiRequestResult(serverUrl, token, namespace, method, pathAndQuery, body);
  if (!result.ok) {
    throw new Error(`${method} ${pathAndQuery} returned ${result.status}: ${result.text.slice(0, 1000)}`);
  }

  return result.parsed;
}

async function apiRequestResult(serverUrl, token, namespace, method, pathAndQuery, body = null) {
  const base = serverUrl.replace(/\/+$/, '');
  const response = await fetch(`${base}/api${pathAndQuery}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: `Bearer ${token}`,
      'X-Namespace': namespace,
      'X-Durable-Workflow-Control-Plane-Version': '2',
    },
    body: body === null ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  let parsed = {};
  if (text.trim() !== '') {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = { raw_body: text };
    }
  }

  return {
    ok: response.ok,
    status: response.status,
    parsed,
    text,
  };
}

async function waitForServerReady(serverUrl, timeoutSeconds) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  const readyUrl = `${serverUrl.replace(/\/+$/, '')}/api/ready`;
  let lastObservation = 'no readiness probe completed';

  while (Date.now() < deadline) {
    try {
      const response = await fetch(readyUrl);
      if (response.ok) {
        return;
      }
      const text = await response.text().catch(() => '');
      lastObservation = `HTTP ${response.status}: ${compactLogText(text)}`;
    } catch (error) {
      lastObservation = error instanceof Error ? error.message : String(error);
    }

    await sleep(1000);
  }

  throw new Error(`published server did not become ready at ${readyUrl}; last observation: ${lastObservation}`);
}

function compactLogText(value, limit = 1000) {
  const normalized = String(value ?? '').replace(/\s+/g, ' ').trim();
  if (normalized === '') {
    return '<empty response body>';
  }

  return normalized.length > limit ? `${normalized.slice(0, limit)}...` : normalized;
}

async function collectComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-cadence-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

async function execLogged(command, args, logPath, env = process.env) {
  try {
    const result = await execFile(command, args, {
      env,
      maxBuffer: 1024 * 1024 * 16,
    });
    writeText(logPath, `${result.stdout ?? ''}${result.stderr ?? ''}`);
    return result;
  } catch (error) {
    writeText(logPath, `${error.stdout ?? ''}${error.stderr ?? ''}`);
    throw new Error(`${command} ${args.join(' ')} failed; see ${path.basename(logPath)}`);
  }
}

async function commandSucceeds(command, args) {
  try {
    await execFile(command, args, { maxBuffer: 1024 * 1024 });
    return true;
  } catch {
    return false;
  }
}

function resolveServerImage(artifactVersions) {
  const configured = stringValue(process.env.DW_SERVER_IMAGE);
  if (configured !== '') {
    return configured;
  }

  const version = stringValue(artifactVersions.server);
  return version === '' ? '' : `durableworkflow/server:${version}`;
}

function positiveInt(value, fallback) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function duplicateCount(values) {
  const seen = new Set();
  let duplicates = 0;

  for (const value of values) {
    if (seen.has(value)) {
      duplicates += 1;
    } else {
      seen.add(value);
    }
  }

  return duplicates;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function freePort() {
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

function sanitizeDockerName(value) {
  return stringValue(value).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48)
    || `dw-schedules-${Date.now().toString(36)}`;
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

function writeText(filePath, value) {
  fs.mkdirSync(path.dirname(filePath), { recursive: true });
  fs.writeFileSync(filePath, value, 'utf8');
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
