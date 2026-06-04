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

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-cadence-docker-pull.log'),
    );
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'up', '-d', 'server', 'scheduler'],
      path.join(resultDir, 'schedules-cadence-compose-up.log'),
      composeEnv(serverPort, serverImage, token, artifactVersions),
    );
    composeStarted = true;
  }

  try {
    await waitForServerReady(serverUrl, 120);

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
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'up', '-d', 'server'],
      path.join(resultDir, 'schedules-cli-compose-up.log'),
      composeEnv(serverPort, serverImage, token, artifactVersions),
    );
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

async function ensureNamespace(serverUrl, token, namespace) {
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
      name: namespace,
      description: 'Schedules conformance CLI surface namespace',
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

  if (!response.ok) {
    throw new Error(`${method} ${pathAndQuery} returned ${response.status}: ${text.slice(0, 1000)}`);
  }

  return parsed;
}

async function waitForServerReady(serverUrl, timeoutSeconds) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  const readyUrl = `${serverUrl.replace(/\/+$/, '')}/api/ready`;

  while (Date.now() < deadline) {
    try {
      const response = await fetch(readyUrl);
      if (response.ok) {
        return;
      }
    } catch {
    }

    await sleep(1000);
  }

  throw new Error(`published server did not become ready at ${readyUrl}`);
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
