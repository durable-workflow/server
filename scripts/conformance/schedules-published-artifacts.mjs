#!/usr/bin/env node
import { execFile as execFileCallback } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import process from 'node:process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import {
  isExactPythonRelease,
  isExactSemverRelease,
  samePythonRelease,
} from './version-identities.mjs';

const RESULT_SCHEMA = 'durable-workflow.v2.schedules-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.schedules-runtime.record';
const PUBLISHED_ARTIFACTS_SCHEMA = 'durable-workflow.v2.schedules-runtime.published-artifacts';
const ARTIFACT_INSTALL_SCHEMA = 'durable-workflow.v2.schedules-runtime.artifact-install-evidence';
const execFile = promisify(execFileCallback);

class PublishedStackInfrastructureError extends Error {
  constructor(message, name = 'PublishedStackInfrastructureError') {
    super(message);
    this.name = name;
  }
}

class PublishedStackStartupError extends PublishedStackInfrastructureError {
  constructor(message) {
    super(message, 'PublishedStackStartupError');
  }
}

class PublishedStackCleanupError extends PublishedStackInfrastructureError {
  constructor(message) {
    super(message, 'PublishedStackCleanupError');
  }
}

class CadenceHistoryTransportError extends PublishedStackInfrastructureError {
  constructor(message) {
    super(message, 'CadenceHistoryTransportError');
  }
}

class CadenceObservationInfrastructureError extends PublishedStackInfrastructureError {
  constructor(message, observations = {}) {
    super(message, 'CadenceObservationInfrastructureError');
    this.observations = observations;
  }
}

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
const pythonLifecycleEvidencePath = process.env.DW_SCHEDULES_PYTHON_LIFECYCLE_EVIDENCE
  ?? path.join(resultDir, 'schedules-python-lifecycle-evidence.json');
const phpSurfaceEvidencePath = process.env.DW_SCHEDULES_PHP_SURFACE_EVIDENCE
  ?? path.join(resultDir, 'schedules-php-surface-evidence.json');
const operatorControlsEvidencePath = process.env.DW_SCHEDULES_OPERATOR_CONTROLS_EVIDENCE
  ?? path.join(resultDir, 'schedules-operator-controls-evidence.json');
const missedRestartEvidencePath = process.env.DW_SCHEDULES_MISSED_RESTART_EVIDENCE
  ?? path.join(resultDir, 'schedules-missed-restart-evidence.json');
const crossLanguageEvidencePath = process.env.DW_SCHEDULES_CROSS_LANGUAGE_EVIDENCE
  ?? path.join(resultDir, 'schedules-cross-language-evidence.json');
const adversarialEvidencePath = process.env.DW_SCHEDULES_ADVERSARIAL_EVIDENCE
  ?? path.join(resultDir, 'schedules-adversarial-evidence.json');
const configuredArtifactInstallEvidencePath = process.env.DW_SCHEDULES_ARTIFACT_INSTALL_EVIDENCE;
const hasConfiguredArtifactInstallEvidencePath =
  configuredArtifactInstallEvidencePath !== undefined
  && configuredArtifactInstallEvidencePath.trim() !== '';
const artifactInstallEvidencePath = hasConfiguredArtifactInstallEvidencePath
  ? configuredArtifactInstallEvidencePath
  : path.join(resultDir, 'schedules-artifact-install-evidence.json');
const artifactInstallEvidenceFallbackPaths = hasConfiguredArtifactInstallEvidencePath
  ? []
  : [path.join(resultDir, 'artifact-install-evidence.json')];

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
const REQUIRED_PUBLISHED_ARTIFACTS = ['server', 'cli', 'sdk-python', 'sdk-php', 'waterline'];
const FORBIDDEN_ARTIFACT_SOURCE_TOKENS = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'local_checkout_artifact',
  'local_checkout',
  'local_source_checkout',
  'workspace_repo',
  'unverified_artifact_source',
];
const PLACEHOLDER_VERSION_PATTERN = /<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/i;
const ROLLING_ARTIFACT_SOURCE_PATTERN = /(^|[/:@=?&#._-])(latest|current|head)(?:$|[/:@?&#._-])/i;
const PUBLISHED_ARTIFACT_SOURCE_LABELS = {
  server: new Set(['published_docker_image', 'existing_published_server_url']),
  cli: new Set(['official_install_script', 'published_cli_release', 'github_release']),
  'sdk-python': new Set(['pypi', 'pypi_release', 'published_pypi_release']),
  'sdk-php': new Set(['composer_packagist', 'composer_release', 'packagist', 'published_packagist_release']),
  waterline: new Set([
    'published_waterline_artifact',
    'published_waterline_release',
    'composer_packagist',
    'composer_release',
    'packagist',
    'published_packagist_release',
  ]),
};
const PUBLISHED_SERVER_IMAGE_REPOSITORIES = [
  'durableworkflow/server',
  'docker.io/durableworkflow/server',
  'index.docker.io/durableworkflow/server',
  'registry-1.docker.io/durableworkflow/server',
  'ghcr.io/durable-workflow/server',
];
const CLI_RELEASE_ASSET_NAMES = new Set([
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

const scenarioManifest = readJsonIfExists(scenarioManifestPath) ?? {};
const requiredScenarios = Array.isArray(scenarioManifest.scenarios)
  ? scenarioManifest.scenarios.map((scenario) => scenario.id).filter(Boolean)
  : DEFAULT_REQUIRED_SCENARIOS;
const coverageGapFindings = scenarioManifest.host_runner_contract?.coverage_gap_findings ?? {};
let publishedCliInstallPromise = null;
let phpSdkVersionResolution = null;
const executedArtifactInstalls = new Map();

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
  const artifactVersions = await resolveArtifactVersions(artifactVersionsFromEnv());
  let artifactSources = artifactSourcesFromEnv(artifactVersions);
  const evidenceInputs = readEvidenceInputs();
  const shardEvidence = await runEvidenceShardTasks([
    { name: 'cadence', run: () => maybeRunCadenceShard(startedAt, artifactVersions, artifactSources) },
    { name: 'operator-controls', run: () => maybeRunOperatorControlsShard(startedAt, artifactVersions, artifactSources) },
    { name: 'missed-restart', run: () => maybeRunMissedRestartShard(startedAt, artifactVersions, artifactSources) },
    { name: 'cli-surface', run: () => maybeRunCliSurfaceShard(startedAt, artifactVersions, artifactSources) },
    { name: 'python-lifecycle', run: () => maybeRunPythonLifecycleShard(startedAt, artifactVersions, artifactSources) },
    { name: 'php-surface', run: () => maybeRunPhpSurfaceShard(startedAt, artifactVersions, artifactSources) },
    { name: 'cross-language', run: () => maybeRunCrossLanguageShard(startedAt, artifactVersions, artifactSources) },
    { name: 'adversarial', run: () => maybeRunAdversarialShard(startedAt, artifactVersions, artifactSources) },
  ]);
  for (const evidence of shardEvidence) {
    if (evidence !== null) {
      evidenceInputs.push(evidence);
    }
  }
  const smokeEvidence = mergeEvidence(...evidenceInputs);
  const artifactInstallEvidence = buildArtifactInstallEvidence(artifactVersions, artifactSources, smokeEvidence);
  artifactSources = artifactInstallEvidence.artifact_sources ?? artifactSources;
  const finishedAt = timestamp();
  const suppliedScenarioResults = scenarioResultsById(smokeEvidence);
  const findingLinks = {};
  const findingsById = new Map();
  const scenarioResults = {};

  for (const scenarioId of requiredScenarios) {
    const supplied = suppliedScenarioResults[scenarioId];
    if (supplied && allowedScenarioStatus(supplied.status)) {
      const normalized = normalizeScenarioResult(scenarioId, supplied);
      if (scenarioId === 'published_artifact_install_only' && normalized.status === 'pass') {
        const installPolicy = publishedArtifactInstallPolicy(
          artifactVersions,
          artifactSources,
          smokeEvidence,
          normalized.observed_outputs,
        );
        if (!installPolicy.passes) {
          const finding = focusedCoverageFinding(scenarioId, artifactVersions, smokeEvidence);
          finding.observed_behavior = `The supplied published-artifact install-only pass is missing required proof: ${installPolicy.failures.join('; ')}.`;
          normalized.status = 'not_covered';
          normalized.observed_outputs = {
            ...normalized.observed_outputs,
            published_artifact_policy_failures: installPolicy.failures,
          };
          normalized.linked_findings = [finding];
        } else {
          normalized.observed_outputs = {
            ...publishedArtifactInstallOutputs(artifactVersions, artifactSources, smokeEvidence, normalized.observed_outputs),
            ...normalized.observed_outputs,
          };
        }
      }
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

    if (scenarioId === 'published_artifact_install_only') {
      const installPolicy = publishedArtifactInstallPolicy(
        artifactVersions,
        artifactSources,
        smokeEvidence,
        { artifact_install_evidence: artifactInstallEvidence },
      );
      const status = installPolicy.passes
        ? 'pass'
        : (artifactInstallEvidence.local_product_source_checkouts_used ? 'fail' : 'not_covered');
      const linkedFindings = status === 'pass'
        ? []
        : [publishedArtifactInstallFinding(artifactInstallEvidence, artifactVersions, smokeEvidence)];
      scenarioResults[scenarioId] = {
        scenario_id: scenarioId,
        status,
        observed_outputs: status === 'pass'
          ? publishedArtifactInstallOutputs(
              artifactVersions,
              artifactSources,
              smokeEvidence,
              { artifact_install_evidence: artifactInstallEvidence },
            )
          : artifactInstallEvidence,
        linked_findings: linkedFindings,
      };
      for (const finding of linkedFindings) {
        const findingId = stringValue(finding.finding_id) || `schedules-${scenarioId}-${findingsById.size + 1}`;
        findingsById.set(findingId, finding);
      }
      findingLinks[scenarioId] = linkedFindings;
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

    if (publishedArtifactInstallPassesScenario(scenarioId, artifactVersions, artifactSources, smokeEvidence)) {
      scenarioResults[scenarioId] = {
        scenario_id: scenarioId,
        status: 'pass',
        observed_outputs: publishedArtifactInstallOutputs(artifactVersions, artifactSources, smokeEvidence),
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
      scenarioManifest.required_matrix?.runtimes ?? ['sdk-php', 'sdk-python'],
      smokeEvidence.runtime_matrix?.runtimes,
    ),
    uncovered_required_client_paths: missingTokens(
      scenarioManifest.required_matrix?.client_paths ?? ['cli', 'sdk-python', 'sdk-php'],
      smokeEvidence.runtime_matrix?.client_paths,
    ),
    uncovered_required_schedule_types: missingTokens(
      scenarioManifest.required_matrix?.schedule_types ?? ['cron_expression', 'fixed_rate_interval'],
      smokeEvidence.runtime_matrix?.schedule_types,
    ),
  };

  const allScenariosPass = Object.values(scenarioResults).every((scenario) => scenario.status === 'pass');
  const runnerBlocked = resultHasRunnerBlockedEvidence(smokeEvidence, scenarioResults);
  const outcome = runnerBlocked
    ? 'non_passing_runner_blocked'
    : (allScenariosPass ? 'pass' : 'non_passing');

  const result = {
    schema: RESULT_SCHEMA,
    version: 1,
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome,
    runner_blocked: runnerBlocked,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: localProductSourceCheckoutsResultValue(smokeEvidence, artifactInstallEvidence),
    artifact_install_evidence: artifactInstallEvidence,
    artifact_version_resolution: {
      'sdk-php': phpSdkVersionResolution,
    },
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

  writePublishedArtifacts(artifactVersions, artifactSources, smokeEvidence, artifactInstallEvidence);
  writeResult(result);
}

async function runEvidenceShardTasks(shards) {
  const workerCount = shardConcurrencyLimit(shards.length);
  const results = new Array(shards.length).fill(null);
  let nextIndex = 0;

  async function runWorker() {
    while (nextIndex < shards.length) {
      const index = nextIndex;
      nextIndex += 1;
      results[index] = await shards[index].run();
    }
  }

  await Promise.all(Array.from({ length: workerCount }, runWorker));

  return results;
}

function shardConcurrencyLimit(shardCount) {
  if (shardCount <= 1) {
    return Math.max(1, shardCount);
  }

  const configured = positiveInt(process.env.DW_SCHEDULES_SHARD_CONCURRENCY, 0);
  if (configured > 0) {
    return Math.min(configured, shardCount);
  }

  const existingServerUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const fixedServerPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0);

  return existingServerUrl === '' || fixedServerPort > 0
    ? 1
    : Math.min(2, shardCount);
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

function resultHasRunnerBlockedEvidence(evidence, scenarioResults) {
  if (truthyEvidenceFlag(evidence?.runner_blocked) || truthyEvidenceFlag(evidence?.runnerBlocked)) {
    return true;
  }

  return Object.values(scenarioResults).some((scenario) => (
    stringValue(scenario?.status) === 'runner_blocked'
    || truthyEvidenceFlag(scenario?.runner_blocked)
    || truthyEvidenceFlag(scenario?.runnerBlocked)
  ));
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
    ]) && smoke.triggered_workflow_completed === true;
  }

  if (scenarioId === 'invalid_cron_refusal') {
    return smoke.invalid_cron_refused === true
      && smoke.invalid_cron_typed_error === true
      && smoke.invalid_cron_persisted === false
      && invalidCronPublicPersistenceChecked(smoke);
  }

  return false;
}

function pythonSmokeOutputs(scenarioId, evidence, artifactVersions) {
  const smoke = evidence.python_schedule_lifecycle_smoke ?? evidence.pythonScheduleLifecycleSmoke ?? {};

  if (scenarioId === 'invalid_cron_refusal') {
    const persistenceEvidence = invalidCronPublicPersistenceEvidence(smoke);
    return {
      refused: true,
      typed_error: true,
      persisted: false,
      public_persistence_checked: true,
      persistence_evidence: persistenceEvidence,
      public_list_checked: persistenceEvidence.public_list_checked === true,
      list_contains_invalid_schedule: persistenceEvidence.list_contains_invalid_schedule ?? null,
      public_describe_checked: persistenceEvidence.public_describe_checked === true,
      describe_found: persistenceEvidence.describe_found ?? null,
      describe_status: persistenceEvidence.describe_status ?? null,
      smoke_source: 'published_python_sdk_lifecycle_smoke',
      artifact_versions: artifactVersions,
    };
  }

  return {
    create_or_observe: smoke.create === true,
    list_observed: smoke.list === true,
    describe_observed: smoke.describe === true,
    control_observed: ['pause', 'resume', 'trigger', 'delete'].every((key) => smoke[key] === true),
    manual_trigger_observed: smoke.trigger === true,
    triggered_workflow_completion_observed: smoke.triggered_workflow_completed === true,
    operations: {
      create: smoke.create === true,
      list: smoke.list === true,
      describe: smoke.describe === true,
      pause: smoke.pause === true,
      resume: smoke.resume === true,
      manual_trigger: smoke.trigger === true,
      delete: smoke.delete === true,
      triggered_workflow_completion: smoke.triggered_workflow_completed === true,
    },
    smoke_source: 'published_python_sdk_lifecycle_smoke',
    artifact_versions: artifactVersions,
  };
}

function invalidCronPublicPersistenceEvidence(smoke) {
  return objectValue(
    smoke.invalid_cron_public_persistence
      ?? smoke.invalidCronPublicPersistence
      ?? smoke.invalid_cron_persistence_evidence
      ?? smoke.invalidCronPersistenceEvidence,
  );
}

function invalidCronPublicPersistenceChecked(smoke) {
  const evidence = invalidCronPublicPersistenceEvidence(smoke);
  const explicitChecked = smoke.invalid_cron_public_persistence_checked === true
    || smoke.invalidCronPublicPersistenceChecked === true;
  const listChecked = evidence.public_list_checked === true
    || evidence.publicListChecked === true
    || Object.hasOwn(evidence, 'list_contains_invalid_schedule')
    || Object.hasOwn(evidence, 'listContainsInvalidSchedule');
  const describeChecked = evidence.public_describe_checked === true
    || evidence.publicDescribeChecked === true
    || Object.hasOwn(evidence, 'describe_found')
    || Object.hasOwn(evidence, 'describeFound')
    || Object.hasOwn(evidence, 'describe_status')
    || Object.hasOwn(evidence, 'describeStatus');
  const listProvesAbsent = evidence.list_contains_invalid_schedule === false
    || evidence.listContainsInvalidSchedule === false;
  const describeProvesAbsent = evidence.describe_found === false
    || evidence.describeFound === false
    || Number.parseInt(String(evidence.describe_status ?? evidence.describeStatus ?? ''), 10) === 404;

  return (explicitChecked && (listProvesAbsent || describeProvesAbsent))
    || (listChecked && listProvesAbsent)
    || (describeChecked && describeProvesAbsent);
}

function publishedArtifactInstallPassesScenario(scenarioId, artifactVersions, artifactSources, evidence) {
  if (scenarioId !== 'published_artifact_install_only') {
    return false;
  }

  return publishedArtifactInstallPolicy(artifactVersions, artifactSources, evidence).passes;
}

function publishedArtifactInstallOutputs(artifactVersions, artifactSources, evidence, outputs = {}) {
  const policy = publishedArtifactInstallPolicy(artifactVersions, artifactSources, evidence, outputs);
  const artifactEvidence = Object.fromEntries(REQUIRED_PUBLISHED_ARTIFACTS.map((artifact) => [
    artifact,
    {
      version: artifactValue(policy.artifactVersions, artifact),
      source: artifactValue(policy.artifactSources, artifact),
      source_verification: artifactObjectValue(policy.artifactSourceVerification, artifact),
    },
  ]));

  return {
    artifact_versions: policy.artifactVersions,
    artifact_sources: policy.artifactSources,
    artifact_source_verification: policy.artifactSourceVerification,
    required_artifacts: REQUIRED_PUBLISHED_ARTIFACTS,
    artifacts: artifactEvidence,
    local_product_source_checkouts_used: policy.localProductSourceCheckoutsUsed,
    install_channels_verified: true,
    published_install_tuple_proven: policy.passes,
    artifact_install_evidence: policy.artifactInstallEvidence,
    ...(policy.failures.length > 0 ? { published_artifact_policy_failures: policy.failures } : {}),
  };
}

function publishedArtifactInstallPolicy(artifactVersions, artifactSources, evidence, outputs = {}) {
  const installArtifactVersions = mergeObjects(
    artifactVersions,
    outputs.artifact_versions,
    outputs.artifactVersions,
    outputs.published_artifact_versions,
    outputs.publishedArtifactVersions,
    outputs.resolved_artifact_versions,
    outputs.resolvedArtifactVersions,
  );
  const installArtifactSources = normalizeArtifactSources(
    mergeObjects(
      artifactSources,
      outputs.artifact_sources,
      outputs.artifactSources,
      outputs.install_sources,
      outputs.installSources,
    ),
    installArtifactVersions,
  );
  const artifactSourceVerification = artifactSourceVerificationFrom(evidence, outputs);
  const localProductSourceUsed = localProductSourceCheckoutsUsed(evidence, outputs);
  const localProductSourceExplicitFalse = localProductSourceCheckoutsExplicitlyFalse(evidence, outputs);
  const artifactInstallEvidence = buildArtifactInstallEvidence(
    installArtifactVersions,
    installArtifactSources,
    mergeObjects(evidence, { artifact_source_verification: artifactSourceVerification }),
    outputs,
  );
  const failures = [];

  if (localProductSourceUsed) {
    failures.push('local_product_source_checkouts_used=true');
  } else if (!localProductSourceExplicitFalse) {
    failures.push('local_product_source_checkouts_used=false missing');
  }

  for (const artifact of REQUIRED_PUBLISHED_ARTIFACTS) {
    const version = artifactValue(installArtifactVersions, artifact);
    const source = artifactValue(installArtifactSources, artifact);
    const sourceVerification = artifactObjectValue(artifactSourceVerification, artifact);

    if (!isConcretePublishedVersion(version, artifact)) {
      failures.push(`${artifact}.artifact_versions missing or not exact published version`);
    }
    if (!isConcretePublishedSource(artifact, version, source, sourceVerification)) {
      failures.push(`${artifact}.artifact_sources missing, local, rolling, unverified, or not published`);
    }
  }

  failures.push(...artifactInstallEvidence.policy_failures);

  return {
    passes: failures.length === 0,
    failures,
    artifactVersions: installArtifactVersions,
    artifactSources: installArtifactSources,
    artifactSourceVerification,
    artifactInstallEvidence,
    localProductSourceCheckoutsUsed: localProductSourceUsed ? true : false,
    localProductSourceCheckoutsExplicitlyFalse: localProductSourceExplicitFalse,
  };
}

function artifactInstallEvidenceFrom(...containers) {
  for (const installEvidencePath of [
    artifactInstallEvidencePath,
    ...artifactInstallEvidenceFallbackPaths,
  ]) {
    const explicit = readJsonIfExists(installEvidencePath);
    if (explicit && typeof explicit === 'object' && !Array.isArray(explicit)) {
      return { ...explicit, source_path: installEvidencePath };
    }
  }

  for (const container of containers) {
    const value = objectValue(container);
    for (const field of [
      'artifact_install_evidence',
      'artifactInstallEvidence',
      'install_evidence',
      'installEvidence',
    ]) {
      const candidate = objectValue(value[field]);
      if (Object.keys(candidate).length > 0) {
        return candidate;
      }
    }

    const publishedArtifacts = objectValue(value.published_artifacts ?? value.publishedArtifacts);
    const nested = objectValue(publishedArtifacts.artifact_install_evidence ?? publishedArtifacts.artifactInstallEvidence);
    if (Object.keys(nested).length > 0) {
      return nested;
    }
  }

  return {};
}

function artifactInstallEntriesByArtifact(installEvidence) {
  const raw = installEvidence?.artifacts;
  if (Array.isArray(raw)) {
    return Object.fromEntries(raw
      .filter((entry) => entry && typeof entry === 'object')
      .map((entry) => [stringValue(entry.artifact ?? entry.name ?? entry.id), entry])
      .filter(([artifact]) => artifact !== ''));
  }

  if (raw && typeof raw === 'object') {
    return Object.fromEntries(Object.entries(raw)
      .filter(([, entry]) => entry && typeof entry === 'object')
      .map(([artifact, entry]) => [stringValue(entry.artifact ?? entry.name ?? entry.id ?? artifact), entry])
      .filter(([artifact]) => artifact !== ''));
  }

  return {};
}

function artifactInstallEntry(entries, artifact) {
  const aliases = {
    'sdk-php': ['sdk-php', 'sdk_php'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python'],
    waterline: ['waterline', 'waterline-ui', 'waterline_ui'],
  };

  for (const key of aliases[artifact] ?? [artifact]) {
    const entry = entries[key];
    if (entry && typeof entry === 'object') {
      return entry;
    }
  }

  return null;
}

function artifactInstallEntrySourceVerification(entry, fallbackVerification) {
  return objectValue(
    entry?.source_verification
      ?? entry?.sourceVerification
      ?? entry?.artifact_source_verification
      ?? entry?.artifactSourceVerification
      ?? entry?.artifact_source_resolution
      ?? entry?.artifactSourceResolution
      ?? fallbackVerification,
  );
}

function artifactValue(values, artifact) {
  if (!values || typeof values !== 'object') {
    return '';
  }

  const aliases = {
    'sdk-php': ['sdk-php', 'sdk_php'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python'],
    waterline: ['waterline', 'waterline-ui', 'waterline_ui'],
  };

  for (const key of aliases[artifact] ?? [artifact]) {
    const value = stringValue(values[key]);
    if (Object.prototype.hasOwnProperty.call(values, key) && value !== '') {
      return value;
    }
  }

  return '';
}

function artifactObjectValue(values, artifact) {
  if (!values || typeof values !== 'object') {
    return {};
  }

  const aliases = {
    'sdk-php': ['sdk-php', 'sdk_php'],
    'sdk-python': ['sdk-python', 'sdk_python', 'python'],
    waterline: ['waterline', 'waterline-ui', 'waterline_ui'],
  };

  for (const key of aliases[artifact] ?? [artifact]) {
    if (Object.prototype.hasOwnProperty.call(values, key)) {
      const value = objectValue(values[key]);
      if (Object.keys(value).length > 0) {
        return value;
      }
    }
  }

  return {};
}

function isConcretePublishedVersion(version, artifact = '') {
  return version !== ''
    && (artifact === 'sdk-python' ? isExactPythonRelease(version) : isExactSemverRelease(version))
    && !PLACEHOLDER_VERSION_PATTERN.test(version.toLowerCase());
}

function samePublishedVersion(artifact, expected, observed) {
  return artifact === 'sdk-python'
    ? samePythonRelease(expected, observed)
    : expected === observed;
}

function isConcretePublishedSource(artifact, version, source, sourceVerification = {}) {
  if (source === '' || source === 'not_exercised') {
    return false;
  }

  if (artifactSourceIsForbidden(source)) {
    return false;
  }

  return matchesPublishedArtifactSource(artifact, version, source)
    || artifactSourceVerificationPasses(version, source, sourceVerification);
}

function matchesPublishedArtifactSource(artifact, version, source) {
  if (version === '') {
    return false;
  }

  if (publishedSourceLabelAllowed(artifact, source)) {
    return true;
  }

  switch (artifact) {
    case 'server':
      return matchesServerArtifactSource(version, source);
    case 'cli':
      return matchesCliArtifactSource(version, source);
    case 'sdk-python':
      return matchesPythonArtifactSource(version, source);
    case 'sdk-php':
      return matchesComposerArtifactSource('durable-workflow/sdk', version, source);
    case 'waterline':
      return matchesComposerArtifactSource('durable-workflow/waterline', version, source);
    default:
      return false;
  }
}

function publishedSourceLabelAllowed(artifact, source) {
  const labels = PUBLISHED_ARTIFACT_SOURCE_LABELS[artifact];
  return labels instanceof Set && labels.has(source);
}

function normalizeArtifactSources(artifactSources, artifactVersions) {
  const normalized = { ...objectValue(artifactSources) };

  for (const artifact of REQUIRED_PUBLISHED_ARTIFACTS) {
    const source = artifactValue(normalized, artifact);
    if (source === '') {
      continue;
    }

    const version = artifactValue(artifactVersions, artifact);
    const normalizedSource = normalizePublishedArtifactSource(artifact, version, source);
    normalized[artifact] = normalizedSource;
  }

  return normalized;
}

function normalizePublishedArtifactSource(artifact, version, source) {
  const sourceValue = stringValue(source);
  if (sourceValue === '' || sourceValue === 'not_exercised') {
    return sourceValue;
  }

  if (!isConcretePublishedVersion(version, artifact) || !publishedSourceLabelAllowed(artifact, sourceValue)) {
    return sourceValue;
  }

  return canonicalPublishedArtifactSource(artifact, version, sourceValue) || sourceValue;
}

function canonicalPublishedArtifactSource(artifact, version, source) {
  switch (artifact) {
    case 'server':
      if (source === 'existing_published_server_url') {
        return serverImageArtifactSource(version) || '';
      }

      return serverImageArtifactSource(version);
    case 'cli':
      return cliReleaseAssetSource(version, 'install.sh');
    case 'sdk-python':
      return pythonPackageArtifactSource(version);
    case 'sdk-php':
      return composerPackageArtifactSource('durable-workflow/sdk', version);
    case 'waterline':
      return composerPackageArtifactSource('durable-workflow/waterline', version);
    default:
      return '';
  }
}

function serverImageArtifactSource(version) {
  const configured = stringValue(process.env.DW_SERVER_IMAGE);
  if (configured !== '') {
    return dockerImageArtifactSource(configured);
  }

  return `docker://durableworkflow/server:${version}`;
}

function dockerImageArtifactSource(image) {
  const imageValue = stringValue(image).replace(/^docker:\/\//i, '');
  if (imageValue === '') {
    return '';
  }

  return `docker://${imageValue}`;
}

function cliReleaseAssetSource(version, assetName) {
  return `https://github.com/durable-workflow/cli/releases/download/${version}/${assetName}`;
}

function pythonPackageArtifactSource(version) {
  return `pypi://durable-workflow==${version}`;
}

function composerPackageArtifactSource(packageName, version) {
  return `packagist://${packageName}@${version}`;
}

function matchesServerArtifactSource(version, source) {
  const image = stringValue(source).replace(/^docker:\/\//i, '');
  if (image === '') {
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

  return prefixes.some((prefix) => (
    source.startsWith(prefix)
    && CLI_RELEASE_ASSET_NAMES.has(source.slice(prefix.length))
  ));
}

function matchesComposerArtifactSource(packageName, version, source) {
  return source === `packagist://${packageName}@${version}`
    || source === `composer://${packageName}:${version}`
    || source === `https://repo.packagist.org/p2/${packageName}.json#${version}`;
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

function artifactSourceVerificationPasses(version, source, verification) {
  const record = objectValue(verification);
  if (Object.keys(record).length === 0) {
    return false;
  }

  const verifiedSource = stringValue(firstDefined(
    record.source,
    record.artifact_source,
    record.artifactSource,
    record.resolved_source,
    record.resolvedSource,
  ));
  const verifiedVersion = stringValue(firstDefined(
    record.version,
    record.artifact_version,
    record.artifactVersion,
    record.resolved_version,
    record.resolvedVersion,
  ));

  return verifiedSource === source
    && verifiedVersion === version
    && verificationConfirmsPublished(record);
}

function verificationConfirmsPublished(verification) {
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
    if (truthyEvidenceFlag(verification[field])) {
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

function artifactSourceIsForbidden(source) {
  const normalized = stringValue(source).toLowerCase();
  const decoded = decodeSourceText(normalized);

  return [normalized, decoded].some((candidate) => (
    FORBIDDEN_ARTIFACT_SOURCE_TOKENS.some((token) => candidate.includes(token.toLowerCase()))
    || ROLLING_ARTIFACT_SOURCE_PATTERN.test(candidate)
    || isLocalArtifactSourcePath(candidate)
  ));
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

function artifactSourceVerificationFrom(...containers) {
  const maps = [];

  for (const container of containers) {
    const value = objectValue(container);
    maps.push(
      value.artifact_source_verification,
      value.artifactSourceVerification,
      value.published_artifact_source_verification,
      value.publishedArtifactSourceVerification,
      value.artifact_source_resolution,
      value.artifactSourceResolution,
      objectValue(value.published_artifacts).artifact_source_verification,
      objectValue(value.publishedArtifacts).artifactSourceVerification,
    );
  }

  return mergeObjects(...maps);
}

function localProductSourceCheckoutsUsed(...containers) {
  return localProductSourceFlagValues(...containers).some((value) => truthyEvidenceFlag(value));
}

function localProductSourceCheckoutsExplicitlyFalse(...containers) {
  return localProductSourceExplicitFalseValues(...containers).some((value) => explicitFalse(value));
}

function localProductSourceCheckoutsResultValue(...containers) {
  if (localProductSourceCheckoutsUsed(...containers)) {
    return true;
  }

  if (localProductSourceCheckoutsExplicitlyFalse(...containers)) {
    return false;
  }

  return null;
}

function localProductSourceFlagValues(...containers) {
  const values = [];

  for (const container of [...containers, localProductSourceEvidenceFromEnv()]) {
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

  for (const entry of Object.values(value)) {
    if (entry && typeof entry === 'object') {
      collectLocalProductSourceFlagValues(entry, values);
    }
  }
}

function localProductSourceExplicitFalseValues(...containers) {
  const values = [];

  for (const container of [...containers, localProductSourceEvidenceFromEnv()]) {
    collectLocalProductSourceExplicitFalseValues(container, values);
  }

  return values;
}

function collectLocalProductSourceExplicitFalseValues(value, values) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return;
  }

  if (Object.hasOwn(value, 'local_product_source_checkouts_used')) {
    values.push(value.local_product_source_checkouts_used);
  }
  if (Object.hasOwn(value, 'localProductSourceCheckoutsUsed')) {
    values.push(value.localProductSourceCheckoutsUsed);
  }

  for (const field of [
    'artifact_install_evidence',
    'artifactInstallEvidence',
    'install_evidence',
    'installEvidence',
    'published_artifacts',
    'publishedArtifacts',
  ]) {
    collectLocalProductSourceExplicitFalseValues(value[field], values);
  }
}

function localProductSourceEvidenceFromEnv() {
  return {
    local_product_source_checkouts_used: firstDefined(
      process.env.DW_SCHEDULES_LOCAL_PRODUCT_SOURCE_CHECKOUTS_USED,
      process.env.DW_LOCAL_PRODUCT_SOURCE_CHECKOUTS_USED,
    ),
  };
}

function truthyEvidenceFlag(value) {
  if (value === true || value === 1) {
    return true;
  }

  return ['true', '1', 'yes'].includes(stringValue(value).toLowerCase());
}

function explicitFalse(value) {
  if (value === false || value === 0) {
    return true;
  }

  return ['false', '0', 'no'].includes(stringValue(value).toLowerCase());
}

function firstDefined(...values) {
  for (const value of values) {
    if (value !== undefined && value !== null) {
      return value;
    }
  }

  return undefined;
}

function mergeObjects(...values) {
  return Object.assign({}, ...values.map(objectValue));
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
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
    server: envString('DW_SERVER_VERSION'),
    cli: envString('DW_CLI_VERSION'),
    'sdk-python': envString('DW_PYTHON_SDK_VERSION'),
    'sdk-php': envString('DW_PHP_SDK_VERSION'),
    waterline: envString('DW_WATERLINE_VERSION'),
  };
}

async function resolveArtifactVersions(configuredVersions) {
  const versions = { ...configuredVersions };
  const configuredPhpSdkVersion = artifactValue(versions, 'sdk-php');

  if (isConcretePublishedVersion(configuredPhpSdkVersion)) {
    phpSdkVersionResolution = {
      status: 'pass',
      package: 'durable-workflow/sdk',
      version: configuredPhpSdkVersion,
      source: composerPackageArtifactSource('durable-workflow/sdk', configuredPhpSdkVersion),
      version_source: 'configured_exact_version',
    };

    return versions;
  }

  phpSdkVersionResolution = await resolveLatestPublishedPhpSdkVersion();
  if (configuredPhpSdkVersion !== '') {
    phpSdkVersionResolution.configured_version = configuredPhpSdkVersion;
    phpSdkVersionResolution.configured_version_rejected = true;
  }
  const resolvedVersion = stringValue(phpSdkVersionResolution?.version);
  if (resolvedVersion !== '') {
    versions['sdk-php'] = resolvedVersion;
    process.env.DW_PHP_SDK_VERSION = resolvedVersion;
  }

  return versions;
}

async function resolveLatestPublishedPhpSdkVersion() {
  const logPath = path.join(resultDir, 'schedules-php-sdk-version-resolve.log');
  const packageName = 'durable-workflow/sdk';
  const command = [
    'run',
    '--rm',
    'composer:2',
    'show',
    '--all',
    '--format=json',
    packageName,
  ];
  const transcript = await execCommandCapture('docker', command, {
    timeout: 180000,
    maxBuffer: 1024 * 1024 * 8,
  });
  writeText(logPath, workerActionTranscriptLog(transcript));

  if (transcript.exit_code !== 0) {
    return {
      status: 'fail',
      package: packageName,
      version: '',
      source: 'https://repo.packagist.org/p2/durable-workflow/sdk.json',
      version_source: 'composer_packagist_metadata',
      failure: 'Composer could not resolve published durable-workflow/sdk versions.',
      log: path.basename(logPath),
    };
  }

  const parsed = parseJsonOutput(transcript.stdout);
  const version = latestStableComposerVersion(parsed.value?.versions);
  if (version === '') {
    return {
      status: 'fail',
      package: packageName,
      version: '',
      source: 'https://repo.packagist.org/p2/durable-workflow/sdk.json',
      version_source: 'composer_packagist_metadata',
      failure: parsed.error === null
        ? 'Composer returned no stable semantic version for durable-workflow/sdk.'
        : `Composer version metadata was not valid JSON: ${parsed.error}`,
      log: path.basename(logPath),
    };
  }

  return {
    status: 'pass',
    package: packageName,
    version,
    source: composerPackageArtifactSource(packageName, version),
    metadata_source: 'https://repo.packagist.org/p2/durable-workflow/sdk.json',
    version_source: 'composer_packagist_metadata',
    log: path.basename(logPath),
  };
}

function latestStableComposerVersion(versions) {
  return arrayValue(versions)
    .map(normalizeComposerVersion)
    .filter((version) => /^\d+\.\d+\.\d+$/.test(version))
    .sort(compareStableVersions)
    .at(-1) ?? '';
}

function normalizeComposerVersion(version) {
  return stringValue(version)
    .replace(/^\*\s*/, '')
    .replace(/^v(?=\d)/i, '');
}

function compareStableVersions(left, right) {
  const leftParts = left.split('.').map((part) => Number.parseInt(part, 10));
  const rightParts = right.split('.').map((part) => Number.parseInt(part, 10));

  for (let index = 0; index < 3; index += 1) {
    const difference = leftParts[index] - rightParts[index];
    if (difference !== 0) {
      return difference;
    }
  }

  return 0;
}

function artifactSourcesFromEnv(artifactVersions = artifactVersionsFromEnv()) {
  return normalizeArtifactSources({
    server: envString('DW_SCHEDULES_SERVER_ARTIFACT_SOURCE', 'DW_SERVER_ARTIFACT_SOURCE') || 'not_exercised',
    cli: envString('DW_SCHEDULES_CLI_ARTIFACT_SOURCE', 'DW_CLI_ARTIFACT_SOURCE') || 'not_exercised',
    'sdk-python': envString('DW_SCHEDULES_PYTHON_SDK_ARTIFACT_SOURCE', 'DW_PYTHON_SDK_ARTIFACT_SOURCE') || 'not_exercised',
    'sdk-php': envString(
      'DW_SCHEDULES_PHP_SDK_ARTIFACT_SOURCE',
      'DW_PHP_SDK_ARTIFACT_SOURCE',
    ) || 'not_exercised',
    waterline: envString('DW_SCHEDULES_WATERLINE_ARTIFACT_SOURCE', 'DW_WATERLINE_ARTIFACT_SOURCE') || 'not_exercised',
  }, artifactVersions);
}

function envString(...names) {
  for (const name of names) {
    if (!Object.prototype.hasOwnProperty.call(process.env, name)) {
      continue;
    }

    const value = stringValue(process.env[name]);
    if (value !== '') {
      return value;
    }
  }

  return '';
}

function buildArtifactInstallEvidence(artifactVersions, artifactSources, evidence = {}, outputs = {}) {
  const suppliedEvidence = artifactInstallEvidenceFrom(evidence, outputs);
  const suppliedEvidenceObjectPresent = Object.keys(suppliedEvidence).length > 0;
  const suppliedEvidenceIsDerived = suppliedEvidenceObjectPresent
    && truthyEvidenceFlag(suppliedEvidence.derived_install_evidence)
    && explicitFalse(suppliedEvidence.supplied_install_evidence);
  const suppliedEvidencePresent = suppliedEvidenceObjectPresent && !suppliedEvidenceIsDerived;
  const artifactSourceVerification = artifactSourceVerificationFrom(evidence, outputs, suppliedEvidence);
  const derivedEvidence = suppliedEvidenceIsDerived
    ? suppliedEvidence
    : (suppliedEvidencePresent
      ? {}
      : derivedArtifactInstallEvidence(
        artifactVersions,
        artifactSources,
        artifactSourceVerification,
        evidence,
        outputs,
      ));
  const derivedEvidencePresent = Object.keys(derivedEvidence).length > 0;
  const supplied = suppliedEvidencePresent ? suppliedEvidence : derivedEvidence;
  const suppliedEntries = {
    ...artifactInstallEntriesByArtifact(supplied),
    ...Object.fromEntries(executedArtifactInstalls),
  };
  const localProductSourceUsed = localProductSourceCheckoutsUsed(evidence, outputs, supplied);
  const installEvidenceLocalProductSourceUsed = truthyEvidenceFlag(supplied.local_product_source_checkouts_used)
    || truthyEvidenceFlag(supplied.localProductSourceCheckoutsUsed);
  const installEvidenceLocalProductSourceExplicitFalse = explicitFalse(supplied.local_product_source_checkouts_used)
    || explicitFalse(supplied.localProductSourceCheckoutsUsed);
  const installLayerLocalProductSourceExplicitFalse = installEvidenceLocalProductSourceExplicitFalse
    || localProductSourceCheckoutsExplicitlyFalse(evidence, outputs);
  const evidenceSupplied = suppliedEvidencePresent
    || derivedEvidencePresent
    || executedArtifactInstalls.size > 0;
  const policyFailures = [];
  const missingArtifactVersions = [];
  const missingArtifactSources = [];
  const rejectedVersions = {};
  const forbiddenSources = {};
  const invalidSources = {};
  const nonPassingArtifacts = {};
  const missingArtifacts = [];

  if (!evidenceSupplied) {
    policyFailures.push('artifact_install_evidence missing');
  }

  if (localProductSourceUsed || installEvidenceLocalProductSourceUsed) {
    policyFailures.push('artifact_install_evidence.local_product_source_checkouts_used=true');
  } else if (!installLayerLocalProductSourceExplicitFalse) {
    policyFailures.push('artifact_install_evidence.local_product_source_checkouts_used=false missing');
  }

  const artifacts = REQUIRED_PUBLISHED_ARTIFACTS.map((artifact) => {
    const entry = artifactInstallEntry(suppliedEntries, artifact);
    const fallbackVersion = artifactValue(artifactVersions, artifact);
    const fallbackSource = artifactValue(artifactSources, artifact);
    const version = stringValue(firstDefined(
      entry?.version,
      entry?.artifact_version,
      entry?.artifactVersion,
      entry?.resolved_version,
      entry?.resolvedVersion,
      fallbackVersion,
    ));
    const source = normalizePublishedArtifactSource(artifact, version, stringValue(firstDefined(
      entry?.source,
      entry?.install_source,
      entry?.installSource,
      entry?.artifact_source,
      entry?.artifactSource,
      entry?.resolved_source,
      entry?.resolvedSource,
      fallbackSource,
    )));
    const sourceVerification = artifactInstallEntrySourceVerification(
      entry,
      artifactObjectValue(artifactSourceVerification, artifact),
    );
    const entryStatus = stringValue(firstDefined(entry?.status, entry?.result, entry?.outcome));
    const entryLocalProductSourceUsed = entry ? localProductSourceCheckoutsUsed(entry) : false;
    const entryLocalProductSourceExplicitFalse = entry
      ? localProductSourceCheckoutsExplicitlyFalse(entry)
      : false;
    const failures = [];

    if (!entry) {
      failures.push('missing_artifact_install_evidence_entry');
      missingArtifacts.push(artifact);
    }

    if (entry && entryStatus !== 'pass') {
      failures.push(`artifact_install_evidence.status=${entryStatus || 'missing'}`);
      nonPassingArtifacts[artifact] = entryStatus || 'missing';
    }

    if (!isConcretePublishedVersion(version, artifact)) {
      failures.push('artifact_install_evidence.version missing or not exact published version');
      if (version === '') {
        missingArtifactVersions.push(artifact);
      } else {
        rejectedVersions[artifact] = version;
      }
    } else if (fallbackVersion !== '' && !samePublishedVersion(artifact, fallbackVersion, version)) {
      failures.push(`artifact_install_evidence.version ${version} does not match resolved artifact version ${fallbackVersion}`);
      rejectedVersions[artifact] = version;
    }

    if (source === '' || source === 'not_exercised') {
      failures.push('artifact_install_evidence.source missing');
      missingArtifactSources.push(artifact);
    } else if (artifactSourceIsForbidden(source)) {
      failures.push('artifact_install_evidence.source is forbidden');
      forbiddenSources[artifact] = source;
    } else if (!isConcretePublishedSource(artifact, version, source, sourceVerification)) {
      failures.push('artifact_install_evidence.source is not a verified published channel');
      invalidSources[artifact] = source;
    }

    if (entryLocalProductSourceUsed) {
      failures.push('artifact_install_evidence.entry.local_product_source_checkouts_used=true');
    }

    const status = failures.length === 0
      ? 'pass'
      : (entryLocalProductSourceUsed || localProductSourceUsed ? 'fail' : 'not_covered');

    policyFailures.push(...failures.map((failure) => `${artifact}.${failure}`));

    return {
      artifact,
      version,
      source,
      status,
      install_channel: installChannelFor(artifact),
      source_verification: sourceVerification,
      local_product_source_checkouts_used: entryLocalProductSourceUsed,
      local_product_source_checkouts_explicitly_false: entryLocalProductSourceExplicitFalse,
      detail: status === 'pass'
        ? 'Per-artifact published install evidence passed source and version policy.'
        : failures.join('; '),
    };
  });
  const reportedArtifactSources = artifactSourcesWithInstallEvidence(artifactSources, artifacts);
  const publishedInstallTupleProven = policyFailures.length === 0
    && artifacts.every((artifact) => artifact.status === 'pass');

  return {
    schema: stringValue(supplied.schema) || ARTIFACT_INSTALL_SCHEMA,
    generated_at: timestamp(),
    supplied_install_evidence: suppliedEvidencePresent,
    derived_install_evidence: derivedEvidencePresent,
    supplied_install_evidence_path: stringValue(supplied.source_path) || null,
    supplied_local_product_source_checkouts_explicit_false: installEvidenceLocalProductSourceExplicitFalse,
    resolved_artifact_versions: artifactVersions,
    artifact_versions: artifactVersions,
    artifact_sources: reportedArtifactSources,
    artifact_source_verification: artifactSourceVerification,
    local_product_source_checkouts_used: localProductSourceUsed
      ? true
      : (installLayerLocalProductSourceExplicitFalse ? false : null),
    local_product_source_checkouts_explicitly_false: installLayerLocalProductSourceExplicitFalse,
    artifacts,
    executed_artifact_installs: Array.from(executedArtifactInstalls.values()),
    missing_artifact_install_evidence: !evidenceSupplied,
    missing_artifact_install_evidence_artifacts: missingArtifacts,
    missing_artifact_versions: missingArtifactVersions,
    missing_artifact_sources: missingArtifactSources,
    rejected_versions: rejectedVersions,
    forbidden_sources: forbiddenSources,
    invalid_sources: invalidSources,
    non_passing_artifacts: nonPassingArtifacts,
    policy_failures: policyFailures,
    published_install_tuple_proven: publishedInstallTupleProven,
  };
}

function derivedArtifactInstallEvidence(
  artifactVersions,
  artifactSources,
  artifactSourceVerification,
  ...installEvidenceContainers
) {
  if (!installLayerLocalProductSourceExplicitlyFalse(...installEvidenceContainers)) {
    return {};
  }

  const artifacts = [];
  for (const artifact of REQUIRED_PUBLISHED_ARTIFACTS) {
    const version = artifactValue(artifactVersions, artifact);
    const source = artifactValue(artifactSources, artifact);
    const sourceVerification = artifactObjectValue(artifactSourceVerification, artifact);

    if (!isConcretePublishedVersion(version, artifact)
      || !isConcretePublishedSource(artifact, version, source, sourceVerification)) {
      return {};
    }

    artifacts.push({
      artifact,
      version,
      source,
      status: 'pass',
      install_channel: installChannelFor(artifact),
      source_verification: sourceVerification,
      local_product_source_checkouts_used: false,
      detail: 'Published artifact source and explicit source-free install evidence satisfied policy.',
    });
  }

  return {
    schema: ARTIFACT_INSTALL_SCHEMA,
    generated_at: timestamp(),
    derived_from: 'published_artifact_source_manifest',
    local_product_source_checkouts_used: false,
    artifacts,
  };
}

function installLayerLocalProductSourceExplicitlyFalse(...containers) {
  return localProductSourceCheckoutsExplicitlyFalse(...containers);
}

function artifactSourcesWithInstallEvidence(artifactSources, artifacts) {
  const merged = { ...artifactSources };
  for (const artifact of artifacts) {
    const name = stringValue(artifact.artifact);
    const source = stringValue(artifact.source);
    if (name === '' || source === '' || source === 'not_exercised') {
      continue;
    }

    merged[name] = source;
  }

  return merged;
}

function installChannelFor(artifact) {
  return {
    server: 'durableworkflow/server docker image',
    cli: 'official dw install script',
    'sdk-python': 'PyPI durable-workflow package',
    'sdk-php': 'Packagist durable-workflow/sdk package',
    waterline: 'Packagist durable-workflow/waterline package',
  }[artifact] ?? 'published release channel';
}

function publishedArtifactInstallFinding(evidence, artifactVersions, smokeEvidence) {
  const configured = coverageGapFindings.published_artifact_install_only ?? {};
  const gaps = [
    ...arrayValue(evidence.policy_failures),
    ...arrayValue(evidence.missing_artifact_versions).map((artifact) => `${artifact}.version=missing`),
    ...arrayValue(evidence.missing_artifact_sources).map((artifact) => `${artifact}.source=missing`),
    ...arrayValue(evidence.missing_artifact_install_evidence_artifacts).map((artifact) => `${artifact}.install_evidence=missing`),
    ...Object.entries(evidence.rejected_versions ?? {}).map(([artifact, version]) => `${artifact}.version=${version}`),
    ...Object.entries(evidence.forbidden_sources ?? {}).map(([artifact, source]) => `${artifact}.source=${source}`),
    ...Object.entries(evidence.invalid_sources ?? {}).map(([artifact, source]) => `${artifact}.source=${source}`),
    ...Object.entries(evidence.non_passing_artifacts ?? {}).map(([artifact, status]) => `${artifact}.status=${status}`),
  ];

  return {
    finding_id: stringValue(configured.id) || 'schedules-published-artifact-install-evidence',
    scenario_id: 'published_artifact_install_only',
    finding_type: 'conformance_runner_coverage_gap',
    owning_surface: stringValue(configured.owner) || 'conformance_harness',
    execution_scope: stringValue(configured.scope) || 'published-artifact-install',
    artifact_versions: artifactVersions,
    observed_behavior: gaps.length > 0
      ? `Published artifact install proof is incomplete: ${gaps.join(', ')}.`
      : 'Published artifact install proof is incomplete.',
    expected_behavior: stringValue(configured.expected_behavior)
      || 'Server image, CLI, Python SDK, PHP SDK, and Waterline are installed from published channels and recorded with concrete versions.',
    next_acceptance_criterion: arrayValue(configured.acceptance).join('; ')
      || 'record passing per-artifact install evidence, published versions, verified public artifact sources, and local_product_source_checkouts_used=false for server, cli, sdk-python, sdk-php, and waterline',
    current_positive_evidence: currentSmokeEvidence(smokeEvidence),
    observed_outputs: evidence,
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
    pythonLifecycleEvidencePath,
    phpSurfaceEvidencePath,
    crossLanguageEvidencePath,
    adversarialEvidencePath,
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

async function maybeRunPythonLifecycleShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_PYTHON_LIFECYCLE_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(pythonLifecycleEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const serverUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);
  const pythonVersion = artifactValue(artifactVersions, 'sdk-python');
  const missing = [];

  if (!await commandSucceeds('python3', ['--version'])) {
    missing.push('python3');
  }
  if (pythonVersion === '') {
    missing.push('DW_PYTHON_SDK_VERSION');
  }
  if (serverUrl === '' && (!dockerAvailable || !composeAvailable || serverImage === '')) {
    if (!dockerAvailable) {
      missing.push('docker');
    }
    if (dockerAvailable && !composeAvailable) {
      missing.push('docker compose');
    }
    if (serverImage === '') {
      missing.push('DW_SERVER_VERSION or DW_SERVER_IMAGE');
    }
  }

  if (missing.length > 0) {
    if (!explicit) {
      return null;
    }

    return pythonLifecycleBlockedEvidence(
      `Python schedules lifecycle shard prerequisites are missing: ${missing.join(', ')}.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runPythonLifecycleShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = failureReasonWithShardLogs(error, 'schedules-python-lifecycle');
    return pythonLifecycleBlockedEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runPythonLifecycleShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const lifecycleStartedAt = timestamp();
  const runId = `schedules-python-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_PYTHON_LIFECYCLE_TASK_QUEUE)
    || `schedules-python-lifecycle-${runId}`;
  const workflowType = stringValue(process.env.DW_SCHEDULES_PYTHON_LIFECYCLE_WORKFLOW_TYPE)
    || 'SchedulesConformancePythonLifecycleWorkflow';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const timeoutSeconds = positiveInt(process.env.DW_SCHEDULES_PYTHON_LIFECYCLE_TIMEOUT_SECONDS, 120);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_PYTHON_LIFECYCLE_SCHEDULER_TICK_SECONDS, 2);
  const interval = stringValue(process.env.DW_SCHEDULES_PYTHON_LIFECYCLE_INTERVAL) || 'PT1H';
  const invalidCron = stringValue(process.env.DW_SCHEDULES_INVALID_CRON_EXPRESSION) || 'not a cron expression';
  const scheduleId = `${runId}-lifecycle`;
  const invalidScheduleId = `${runId}-invalid-cron`;
  const workerId = `${runId}-worker`;
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const overlayPath = path.join(resultDir, 'schedules-python-lifecycle-compose.override.yml');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  const shardRoot = path.join(resultDir, 'schedules-python-lifecycle-shard');
  let composeStarted = false;

  fs.rmSync(shardRoot, { recursive: true, force: true });
  fs.mkdirSync(shardRoot, { recursive: true });
  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url', artifactVersions);

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-python-lifecycle-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-python-lifecycle',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
    await ensureNamespace(serverUrl, token, namespace);

    const python = await installSchedulesPythonLifecycleArtifact(shardRoot, artifactVersions, artifactSources);
    const output = await runSchedulesPythonLifecycleScript(python, {
      action: 'python_lifecycle',
      server_url: serverUrl,
      token,
      namespace,
      task_queue: taskQueue,
      worker_id: workerId,
      workflow_type: workflowType,
      schedule_id: scheduleId,
      invalid_schedule_id: invalidScheduleId,
      interval,
      invalid_cron: invalidCron,
      sdk_version: artifactValue(artifactVersions, 'sdk-python'),
      timeout_seconds: timeoutSeconds,
      timeout_ms: (timeoutSeconds + 60) * 1000,
    });

    const evidence = pythonLifecycleEvidenceFromOutput({
      startedAt: lifecycleStartedAt,
      finishedAt: timestamp(),
      output,
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      workflowType,
      scheduleId,
      invalidScheduleId,
      workerId,
      interval,
      invalidCron,
    });
    writeJson(pythonLifecycleEvidencePath, evidence);

    return evidence;
  } finally {
    await bestEffortDeleteSchedule(serverUrl, token, namespace, scheduleId);
    await bestEffortDeleteSchedule(serverUrl, token, namespace, invalidScheduleId);
    writeJson(path.join(resultDir, 'schedules-python-lifecycle-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.python-lifecycle-run-metadata',
      started_at: startedAt,
      python_lifecycle_started_at: lifecycleStartedAt,
      finished_at: timestamp(),
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      server_image: existingServerUrl === '' ? serverImage : null,
      compose_project: existingServerUrl === '' ? composeProject : null,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
      schedules_created: [scheduleId, invalidScheduleId],
    });

    if (composeStarted) {
      await collectPythonLifecycleComposeLogs(composeProject, composeFiles);
      await removePublishedComposeProject(
        composeProject,
        composeFiles,
        composeEnv(serverPort, serverImage, token, artifactVersions),
      );
    }
  }
}

async function installSchedulesPythonLifecycleArtifact(shardRoot, artifactVersions, artifactSources) {
  const pythonRoot = path.join(shardRoot, 'python');
  const venv = path.join(pythonRoot, 'venv');
  const pythonVersion = artifactValue(artifactVersions, 'sdk-python');
  const scriptPath = path.join(pythonRoot, 'schedules_python_lifecycle.py');
  fs.mkdirSync(pythonRoot, { recursive: true });
  writeText(scriptPath, schedulesPythonLifecycleScript());

  await execLogged('python3', ['-m', 'venv', venv], path.join(resultDir, 'schedules-python-lifecycle-venv.log'));
  const pythonBin = path.join(venv, 'bin', 'python');
  await execLogged(pythonBin, ['-m', 'pip', 'install', '--upgrade', 'pip'], path.join(resultDir, 'schedules-python-lifecycle-pip-upgrade.log'));
  await execLogged(
    pythonBin,
    ['-m', 'pip', 'install', `durable-workflow==${pythonVersion}`],
    path.join(resultDir, 'schedules-python-lifecycle-install.log'),
  );
  markArtifactSource(artifactSources, 'sdk-python', pythonPackageArtifactSource(pythonVersion), artifactVersions);

  return { pythonRoot, pythonBin, scriptPath };
}

async function runSchedulesPythonLifecycleScript(python, input) {
  const { inputPath, outputPath } = writeSchedulesWorkerInput(python.pythonRoot, input);
  const logPath = path.join(resultDir, `schedules-python-lifecycle-${safeLogName(input.schedule_id ?? input.action)}.log`);
  const result = await execCommandCapture(python.pythonBin, [python.scriptPath, inputPath, outputPath], {
    timeout: positiveInt(input.timeout_ms, 180000),
    maxBuffer: 1024 * 1024 * 6,
  });
  writeText(logPath, `${result.stdout}${result.stderr}`);
  if (result.exit_code !== 0) {
    throw new Error(`published Python schedules lifecycle action failed; see ${path.basename(logPath)}`);
  }

  const output = readJsonIfExists(outputPath);
  if (!output || typeof output !== 'object') {
    throw new Error('published Python schedules lifecycle action did not write JSON output');
  }

  return output;
}

function pythonLifecycleEvidenceFromOutput({
  startedAt,
  finishedAt,
  output,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  workflowType,
  scheduleId,
  invalidScheduleId,
  workerId,
  interval,
  invalidCron,
}) {
  const operations = objectValue(output.operations);
  const invalidCronOutcome = objectValue(output.invalid_cron_refusal);
  const completion = objectValue(output.triggered_workflow_completion);
  const lifecycleFailures = [];
  const invalidFailures = [];
  const operationChecks = {
    create: operations.create === true,
    list: operations.list === true,
    describe: operations.describe === true,
    pause: operations.pause === true,
    resume: operations.resume === true,
    manual_trigger: operations.manual_trigger === true,
    delete: operations.delete === true,
    visibility_filtering: operations.visibility_filtering === true,
    visibility_pagination: operations.visibility_pagination === true,
    visibility_typed_refusals: operations.visibility_typed_refusals === true,
    triggered_workflow_completion: completion.workflow_completed === true,
  };

  for (const [operation, passed] of Object.entries(operationChecks)) {
    if (!passed) {
      lifecycleFailures.push(`${operation} was not observed through the published Python SDK lifecycle smoke`);
    }
  }

  if (invalidCronOutcome.refused !== true) {
    invalidFailures.push('invalid cron create was not refused');
  }
  if (invalidCronOutcome.typed_error !== true) {
    invalidFailures.push('invalid cron refusal was not a typed SDK error');
  }
  if (invalidCronOutcome.persisted !== false) {
    invalidFailures.push('invalid cron schedule persistence was not proven false');
  }
  if (!invalidCronPublicPersistenceChecked({
    invalid_cron_public_persistence: invalidCronOutcome.persistence_evidence,
  })) {
    invalidFailures.push('invalid cron non-persistence was not proven with public list or describe evidence');
  }

  const lifecycleOutputs = {
    create_or_observe: operationChecks.create,
    list_observed: operationChecks.list,
    describe_observed: operationChecks.describe,
    control_observed: operationChecks.pause
      && operationChecks.resume
      && operationChecks.manual_trigger
      && operationChecks.delete,
    manual_trigger_observed: operationChecks.manual_trigger,
    triggered_workflow_completion_observed: operationChecks.triggered_workflow_completion,
    operations: operationChecks,
    schedule_id: scheduleId,
    namespace,
    task_queue: taskQueue,
    workflow_type: workflowType,
    worker_id: workerId,
    interval,
    sdk_version: artifactValue(artifactVersions, 'sdk-python'),
    schedule_state: output.schedule_state ?? {},
    trigger_result: output.trigger_result ?? {},
    triggered_workflow_completion: completion,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures: lifecycleFailures,
  };
  const invalidOutputs = {
    refused: invalidCronOutcome.refused === true,
    typed_error: invalidCronOutcome.typed_error === true,
    persisted: invalidCronOutcome.persisted === false ? false : invalidCronOutcome.persisted,
    public_persistence_checked: true,
    persistence_evidence: objectValue(invalidCronOutcome.persistence_evidence),
    public_list_checked: objectValue(invalidCronOutcome.persistence_evidence).public_list_checked === true,
    list_contains_invalid_schedule: objectValue(invalidCronOutcome.persistence_evidence).list_contains_invalid_schedule ?? null,
    public_describe_checked: objectValue(invalidCronOutcome.persistence_evidence).public_describe_checked === true,
    describe_found: objectValue(invalidCronOutcome.persistence_evidence).describe_found ?? null,
    describe_status: objectValue(invalidCronOutcome.persistence_evidence).describe_status ?? null,
    schedule_id: invalidScheduleId,
    invalid_cron: invalidCron,
    error: invalidCronOutcome.error ?? null,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures: invalidFailures,
  };
  const lifecycleStatus = lifecycleFailures.length === 0 ? 'pass' : 'fail';
  const invalidStatus = invalidFailures.length === 0 ? 'pass' : 'fail';
  const lifecycleFindings = lifecycleStatus === 'pass' ? [] : [pythonLifecycleFinding(lifecycleOutputs, artifactVersions)];
  const invalidFindings = invalidStatus === 'pass' ? [] : [pythonInvalidCronFinding(invalidOutputs, artifactVersions)];

  return {
    schema: 'durable-workflow.v2.schedules-runtime.python-lifecycle-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    scenario_results: {
      python_sdk_schedule_surface: {
        scenario_id: 'python_sdk_schedule_surface',
        status: lifecycleStatus,
        observed_outputs: lifecycleOutputs,
        linked_findings: lifecycleFindings,
      },
      invalid_cron_refusal: {
        scenario_id: 'invalid_cron_refusal',
        status: invalidStatus,
        observed_outputs: invalidOutputs,
        linked_findings: invalidFindings,
      },
    },
    findings: [...lifecycleFindings, ...invalidFindings],
    topology: {
      namespace,
      task_queue: taskQueue,
      worker_execution_mode: 'published_python_sdk_lifecycle_smoke',
      worker_ids: { python: workerId },
      schedules_created: [scheduleId],
    },
    runtime_matrix: {
      runtimes: ['sdk-python'],
      client_paths: ['sdk-python'],
      schedule_types: ['fixed_rate_interval', 'cron_expression'],
    },
    client_surfaces: {
      'sdk-python': {
        create_or_observe: lifecycleOutputs.create_or_observe,
        list_observed: lifecycleOutputs.list_observed,
        describe_observed: lifecycleOutputs.describe_observed,
        control_observed: lifecycleOutputs.control_observed,
        manual_trigger_observed: lifecycleOutputs.manual_trigger_observed,
        triggered_workflow_completion_observed: lifecycleOutputs.triggered_workflow_completion_observed,
        operations: operationChecks,
        schedule_state: lifecycleOutputs.schedule_state,
      },
    },
    adversarial_outcomes: {
      invalid_cron_refusal: invalidOutputs,
    },
    python_schedule_lifecycle_smoke: {
      passed: lifecycleStatus === 'pass' && invalidStatus === 'pass',
      create: operationChecks.create,
      list: operationChecks.list,
      describe: operationChecks.describe,
      pause: operationChecks.pause,
      resume: operationChecks.resume,
      trigger: operationChecks.manual_trigger,
      delete: operationChecks.delete,
      visibility_filtering: operationChecks.visibility_filtering,
      visibility_pagination: operationChecks.visibility_pagination,
      visibility_typed_refusals: operationChecks.visibility_typed_refusals,
      visibility_contract: objectValue(lifecycleOutputs.schedule_state).visibility_contract ?? {},
      triggered_workflow_completed: operationChecks.triggered_workflow_completion,
      invalid_cron_refused: invalidOutputs.refused,
      invalid_cron_typed_error: invalidOutputs.typed_error,
      invalid_cron_persisted: invalidOutputs.persisted,
      invalid_cron_public_persistence_checked: true,
      invalid_cron_public_persistence: invalidOutputs.persistence_evidence,
      verified_operations: [
        'create',
        'list',
        'describe',
        'pause',
        'resume',
        'manual_trigger',
        'delete',
        'visibility_filtering',
        'visibility_pagination',
        'visibility_typed_refusals',
        'triggered_workflow_completion',
        'invalid_cron_refusal',
        'invalid_cron_public_persistence_check',
      ],
    },
    raw_python_output: output,
  };
}

function pythonLifecycleFinding(observedOutputs, artifactVersions) {
  const configured = coverageGapFindings.python_sdk_schedule_surface ?? {};
  return {
    finding_id: 'schedules-python-sdk-lifecycle-evidence',
    scenario_id: 'python_sdk_schedule_surface',
    finding_type: 'schedule_python_sdk_lifecycle_gap',
    owning_surface: 'sdk-python',
    execution_scope: stringValue(configured.scope) || 'sdk-python-schedule-surface-shard',
    artifact_versions: artifactVersions,
    observed_behavior: arrayValue(observedOutputs.failures).join('; ')
      || 'Python SDK lifecycle evidence did not satisfy the schedules contract.',
    expected_behavior: stringValue(configured.expected_behavior)
      || 'The Python SDK schedule surface reports create, list, describe, pause, resume, trigger, delete, and triggered workflow completion evidence.',
    next_acceptance_criterion: arrayValue(configured.acceptance).join('; ')
      || 'rerun the Python schedules lifecycle shard and record every lifecycle operation plus triggered workflow completion',
    observed_outputs: observedOutputs,
  };
}

function pythonInvalidCronFinding(observedOutputs, artifactVersions) {
  const configured = coverageGapFindings.invalid_cron_refusal ?? {};
  return {
    finding_id: 'schedules-python-invalid-cron-refusal-evidence',
    scenario_id: 'invalid_cron_refusal',
    finding_type: 'schedule_invalid_cron_refusal_gap',
    owning_surface: 'server',
    execution_scope: stringValue(configured.scope) || 'adversarial-schedule-input-shard',
    artifact_versions: artifactVersions,
    observed_behavior: arrayValue(observedOutputs.failures).join('; ')
      || 'Invalid cron refusal evidence did not satisfy the schedules contract.',
    expected_behavior: stringValue(configured.expected_behavior)
      || 'Invalid cron input is rejected before schedule persistence and the result records the typed error plus public non-persistence proof.',
    next_acceptance_criterion: arrayValue(configured.acceptance).join('; ')
      || 'attempt invalid cron creation through the Python SDK and record refused=true, typed_error=true, and persisted=false from public list or describe evidence',
    observed_outputs: observedOutputs,
  };
}

function pythonLifecycleBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const findings = [
    {
      finding_id: 'schedules-python-lifecycle-runner-blocked',
      scenario_id: 'python_sdk_schedule_surface',
      finding_type: 'conformance_runner_blocked',
      owning_surface: 'conformance_harness',
      execution_scope: 'sdk-python-schedule-surface-shard',
      artifact_versions: artifactVersions,
      observed_behavior: reason,
      expected_behavior: 'The schedules conformance host can install the published Python SDK artifact and run the Python schedule lifecycle shard.',
      next_acceptance_criterion: 'restore the missing host capability and rerun the Python schedules lifecycle shard',
    },
    {
      finding_id: 'schedules-python-invalid-cron-runner-blocked',
      scenario_id: 'invalid_cron_refusal',
      finding_type: 'conformance_runner_blocked',
      owning_surface: 'conformance_harness',
      execution_scope: 'adversarial-schedule-input-shard',
      artifact_versions: artifactVersions,
      observed_behavior: reason,
      expected_behavior: 'The schedules conformance host can attempt invalid cron creation through the published Python SDK and read public persistence state.',
      next_acceptance_criterion: 'restore the missing host capability and rerun the Python invalid-cron shard',
    },
  ];

  return {
    schema: 'durable-workflow.v2.schedules-runtime.python-lifecycle-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: {
      python_sdk_schedule_surface: {
        scenario_id: 'python_sdk_schedule_surface',
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [findings[0]],
      },
      invalid_cron_refusal: {
        scenario_id: 'invalid_cron_refusal',
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [findings[1]],
      },
    },
    findings,
    client_surfaces: {
      'sdk-python': {
        create_or_observe: false,
        list_observed: false,
        control_observed: false,
        blocked_reason: reason,
      },
    },
    adversarial_outcomes: {
      invalid_cron_refusal: { blocked_reason: reason },
    },
  };
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

    return cadenceBlockedEvidence(
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
    const reason = failureReasonWithShardLogs(error, 'schedules-cadence');
    const observations = error instanceof CadenceObservationInfrastructureError
      ? error.observations
      : {};
    return cadenceBlockedEvidence(
      reason,
      startedAt,
      artifactVersions,
      artifactSources,
      observations,
    );
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
  const transportFailureBudget = positiveInt(
    process.env.DW_SCHEDULES_CADENCE_TRANSPORT_FAILURE_BUDGET,
    3,
  );
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
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
  const createdScheduleIds = [];

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url', artifactVersions);

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-cadence-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-cadence',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
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
    createdScheduleIds.push(cronScheduleId);
    await createCadenceSchedule({
      serverUrl,
      token,
      namespace,
      scheduleId: fixedRateScheduleId,
      spec: { intervals: [{ every: 'PT30S' }], timezone: 'UTC' },
      taskQueue,
    });
    createdScheduleIds.push(fixedRateScheduleId);

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
      transportFailureBudget,
      artifactVersions,
      artifactSources,
    });

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
    await Promise.all(createdScheduleIds.map(
      (scheduleId) => bestEffortDeleteSchedule(serverUrl, token, namespace, scheduleId),
    ));

    try {
      if (composeStarted) {
        await collectComposeLogs(composeProject, composeFiles);
        await removePublishedComposeProject(
          composeProject,
          composeFiles,
          composeEnv(serverPort, serverImage, token, artifactVersions),
        );
      }
    } finally {
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
        schedules_created: createdScheduleIds,
      });
    }
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
  transportFailureBudget = 3,
  artifactVersions,
  artifactSources,
  historyReader = scheduleHistory,
  now = Date.now,
  wait = sleep,
}) {
  const deadline = now() + timeoutSeconds * 1000;
  const latest = new Map();
  const readStats = new Map(schedules.map((schedule) => [
    schedule.scenarioId,
    {
      successfulHistoryReads: 0,
      transportFailures: 0,
      consecutiveTransportFailures: 0,
      lastTransportFailure: '',
    },
  ]));

  while (now() < deadline) {
    const reads = await Promise.allSettled(schedules.map(async (schedule) => {
      const history = await historyReader(serverUrl, token, namespace, schedule.scheduleId);
      return buildCadenceObservation({
        ...schedule,
        events: history.events ?? [],
        driftToleranceMs,
        intervalToleranceMs,
        artifactVersions,
        artifactSources,
      });
    }));

    let infrastructureFailure = null;
    for (let index = 0; index < schedules.length; index += 1) {
      const schedule = schedules[index];
      const read = reads[index];
      const stats = readStats.get(schedule.scenarioId);

      if (read.status === 'fulfilled') {
        stats.successfulHistoryReads += 1;
        stats.consecutiveTransportFailures = 0;
        latest.set(schedule.scenarioId, {
          ...read.value,
          successful_history_read_count: stats.successfulHistoryReads,
          transient_transport_failure_count: stats.transportFailures,
        });
        continue;
      }

      if (!isCadenceHistoryTransportFailure(read.reason)) {
        infrastructureFailure = read.reason;
        continue;
      }

      stats.transportFailures += 1;
      stats.consecutiveTransportFailures += 1;
      stats.lastTransportFailure = networkErrorDetail(read.reason);

      const previous = latest.get(schedule.scenarioId);
      if (previous) {
        latest.set(schedule.scenarioId, {
          ...previous,
          transient_transport_failure_count: stats.transportFailures,
        });
      }

      if (stats.consecutiveTransportFailures > transportFailureBudget) {
        infrastructureFailure = new CadenceObservationInfrastructureError(
          `schedule history HTTP execution surface remained unavailable for ${schedule.scheduleId} `
            + `after ${stats.consecutiveTransportFailures} consecutive transport failures `
            + `(retry budget ${transportFailureBudget}): ${stats.lastTransportFailure}`,
          Object.fromEntries(latest),
        );
      }
    }

    if (infrastructureFailure !== null) {
      if (
        infrastructureFailure instanceof CadenceObservationInfrastructureError
        && Object.keys(infrastructureFailure.observations).length > 0
      ) {
        throw infrastructureFailure;
      }
      throw new CadenceObservationInfrastructureError(
        `schedule history observation could not continue: ${
          infrastructureFailure instanceof Error
            ? infrastructureFailure.message
            : String(infrastructureFailure)
        }`,
        Object.fromEntries(latest),
      );
    }

    if (schedules.every((schedule) => {
      const observation = latest.get(schedule.scenarioId);
      return observation && observation.observed_fire_count >= schedule.minimumObservedFires;
    })) {
      break;
    }

    const remainingMs = deadline - now();
    if (remainingMs <= 0) {
      break;
    }
    await wait(Math.min(pollSeconds * 1000, remainingMs));
  }

  const schedulesWithoutHistory = schedules
    .filter((schedule) => !latest.has(schedule.scenarioId))
    .map((schedule) => schedule.scheduleId);
  if (schedulesWithoutHistory.length > 0) {
    throw new CadenceObservationInfrastructureError(
      `cadence polling ended without a successful history read for: ${schedulesWithoutHistory.join(', ')}`,
      Object.fromEntries(latest),
    );
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
    const status = observation.verdict === 'pass'
      ? 'pass'
      : (observation.verdict === 'runner_blocked' ? 'runner_blocked' : 'fail');
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
    local_product_source_checkouts_used: false,
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

function cadenceBlockedEvidence(
  reason,
  startedAt,
  artifactVersions,
  artifactSources,
  partialObservations = {},
) {
  const finishedAt = timestamp();
  const observations = {
    cron_cadence: blockedCadenceObservation(
      'cron_cadence',
      'cron',
      reason,
      artifactVersions,
      artifactSources,
      partialObservations.cron_cadence,
    ),
    fixed_rate_cadence: blockedCadenceObservation(
      'fixed_rate_cadence',
      'fixed_rate',
      reason,
      artifactVersions,
      artifactSources,
      partialObservations.fixed_rate_cadence,
    ),
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

function blockedCadenceObservation(
  scenarioId,
  kind,
  reason,
  artifactVersions,
  artifactSources,
  partialObservation = null,
) {
  return {
    ...failedCadenceObservation(scenarioId, kind, reason, artifactVersions, artifactSources),
    ...(partialObservation ?? {}),
    failure_reason: reason,
    blocked_reason: reason,
    verdict: 'runner_blocked',
  };
}

function cadenceFinding(scenarioId, observation) {
  const kindLabel = scenarioId === 'fixed_rate_cadence' ? 'fixed-rate' : 'cron';
  const runnerBlocked = observation.verdict === 'runner_blocked';
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
    finding_id: runnerBlocked
      ? `schedules-${kindLabel.replace(/[^a-z0-9]+/g, '-')}-cadence-runner-blocked`
      : `schedules-${kindLabel.replace(/[^a-z0-9]+/g, '-')}-cadence-finding`,
    scenario_id: scenarioId,
    finding_type: runnerBlocked ? 'conformance_runner_blocked' : 'schedule_cadence_contract_gap',
    owning_surface: runnerBlocked ? 'conformance_harness' : 'server',
    execution_scope: `${kindLabel}-cadence-shard`,
    artifact_versions: observation.artifact_versions ?? {},
    observed_behavior: reasons.join('; ') || `${kindLabel} cadence did not satisfy the published-artifact contract.`,
    expected_behavior: runnerBlocked
      ? 'The schedules conformance host starts the published server and scheduler before recording cadence behavior.'
      : (scenarioId === 'fixed_rate_cadence'
        ? 'A PT30S fixed-rate schedule fires at every documented interval without duplicate or skipped intervals.'
        : 'A * * * * * cron schedule fires on documented minute cadence without duplicate or skipped intervals.'),
    next_acceptance_criterion: runnerBlocked
      ? 'restore published-stack startup and rerun schedules conformance'
      : (scenarioId === 'fixed_rate_cadence'
        ? 'observe at least eight PT30S fixed-rate fires with nominal timestamps, actual timestamps, and drift milliseconds'
        : 'observe at least four cron fires with nominal timestamps, actual timestamps, and drift milliseconds'),
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
    const reason = failureReasonWithShardLogs(error, 'schedules-operator-controls');
    return error instanceof PublishedStackInfrastructureError
      ? operatorControlsBlockedEvidence(reason, startedAt, artifactVersions, artifactSources)
      : operatorControlsFailureEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runOperatorControlsShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const operatorStartedAt = timestamp();
  const runId = `schedules-operator-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-operator-controls';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
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

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url', artifactVersions);

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-operator-controls-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-operator-controls',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
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
      await removePublishedComposeProject(
        composeProject,
        composeFiles,
        composeEnv(serverPort, serverImage, token, artifactVersions),
      );
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

    markArtifactSource(artifactSources, 'sdk-python', pythonPackageArtifactSource(pythonVersion), artifactVersions);

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
    local_product_source_checkouts_used: false,
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
    const reason = failureReasonWithShardLogs(error, 'schedules-missed-restart');
    return error instanceof PublishedStackInfrastructureError
      ? missedRestartBlockedEvidence(reason, startedAt, artifactVersions, artifactSources)
      : missedRestartFailureEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runMissedRestartShard({ startedAt, artifactVersions, artifactSources, serverImage }) {
  const shardStartedAt = timestamp();
  const runId = `schedules-missed-restart-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-missed-restart';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = `http://127.0.0.1:${serverPort}`;
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

  markArtifactSource(artifactSources, 'server', 'published_docker_image', artifactVersions);

  writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
  await execLogged(
    'docker',
    ['image', 'pull', serverImage],
    path.join(resultDir, 'schedules-missed-restart-docker-pull.log'),
  );
  composeStarted = true;
  await startPublishedComposeServices({
    composeProject,
    composeFiles,
    serverPort,
    serverImage,
    token,
    artifactVersions,
    logPrefix: 'schedules-missed-restart',
  });

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
    await ensureNamespace(serverUrl, token, namespace);

    const schedulerStopRequestedAt = timestamp();
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'stop', 'scheduler'],
      path.join(resultDir, 'schedules-missed-restart-scheduler-outage-stop.log'),
      env,
    );
    const schedulerStoppedAt = await confirmComposeServiceStopped({
      composeProject,
      composeFiles,
      service: 'scheduler',
      logPath: path.join(resultDir, 'schedules-missed-restart-scheduler-outage-state.log'),
      env,
    });

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
    const missedProbeCreatedAt = timestamp();
    const missedCreatedDescription = await describeSchedule(serverUrl, token, namespace, missedScheduleId);
    const missedProbeDescribedAt = timestamp();
    const missedOutageWaitMilliseconds = missedFireOutageWaitMilliseconds({
      storedNextFireTime: scheduleTimeField(
        missedCreatedDescription,
        ['next_fire_at', 'nextFireAt', 'next_fire', 'nextFire'],
      ),
      minimumWaitSeconds: missedDowntimeSeconds,
    });
    await sleep(missedOutageWaitMilliseconds);
    const preResumeHistory = await scheduleHistory(serverUrl, token, namespace, missedScheduleId);
    const schedulerOutageObservedAt = await confirmComposeServiceStopped({
      composeProject,
      composeFiles,
      service: 'scheduler',
      logPath: path.join(resultDir, 'schedules-missed-restart-scheduler-outage-final-state.log'),
      env,
    });
    const schedulerResumeRequestedAt = timestamp();
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'up', '-d', 'scheduler'],
      path.join(resultDir, 'schedules-missed-restart-scheduler-resume.log'),
      env,
    );
    const schedulerResumeConfirmedAt = timestamp();

    const missedFire = await observeMissedFirePolicy({
      serverUrl,
      token,
      namespace,
      scheduleId: missedScheduleId,
      documentedPolicy: documentedMissedFirePolicy(),
      schedulerStopRequestedAt,
      schedulerStopConfirmed: true,
      schedulerStoppedAt,
      probeCreatedAt: missedProbeCreatedAt,
      probeDescribedAt: missedProbeDescribedAt,
      schedulerOutageObservedAt,
      schedulerResumeRequestedAt,
      schedulerResumeConfirmedAt,
      preResumeHistory,
      preResumeDescription: missedCreatedDescription,
      downtimeSeconds: missedDowntimeSeconds,
      outageWaitMilliseconds: missedOutageWaitMilliseconds,
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
    await waitForServerReady(serverUrl, readinessTimeoutSeconds);
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
      await removePublishedComposeProject(composeProject, composeFiles, env);
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

async function confirmComposeServiceStopped({
  composeProject,
  composeFiles,
  service,
  logPath,
  env,
}) {
  const serviceState = await execLogged(
    'docker',
    ['compose', '-p', composeProject, ...composeFiles, 'ps', '--status', 'running', '--services', service],
    logPath,
    env,
  );
  const runningServices = String(serviceState.stdout ?? '')
    .split(/\r?\n/)
    .map((candidate) => candidate.trim())
    .filter(Boolean);

  if (runningServices.includes(service)) {
    throw new PublishedStackInfrastructureError(
      `${service} service remained running during its confirmed outage window`,
    );
  }

  return timestamp();
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

function missedFireOutageWaitMilliseconds({
  storedNextFireTime,
  minimumWaitSeconds,
  nowMs = Date.now(),
}) {
  const minimumWaitMilliseconds = Math.max(0, minimumWaitSeconds * 1000);
  const storedNextFireMs = Date.parse(storedNextFireTime);
  const waitUntilStoredNextFireElapsed = Number.isFinite(storedNextFireMs)
    ? storedNextFireMs + 1000 - nowMs
    : 0;

  return Math.max(minimumWaitMilliseconds, waitUntilStoredNextFireElapsed, 0);
}

async function observeMissedFirePolicy({
  serverUrl,
  token,
  namespace,
  scheduleId,
  documentedPolicy,
  schedulerStopRequestedAt,
  schedulerStopConfirmed,
  schedulerStoppedAt,
  probeCreatedAt,
  probeDescribedAt,
  schedulerOutageObservedAt,
  schedulerResumeRequestedAt,
  schedulerResumeConfirmedAt,
  preResumeHistory,
  preResumeDescription,
  downtimeSeconds,
  outageWaitMilliseconds,
  resumeTimeoutSeconds,
  pollSeconds,
  artifactVersions,
  artifactSources,
}) {
  const stoppedMs = Date.parse(schedulerStoppedAt);
  const resumeRequestedMs = Date.parse(schedulerResumeRequestedAt);
  const outageObservedMs = Date.parse(schedulerOutageObservedAt);
  const probeCreatedMs = Date.parse(probeCreatedAt);
  const probeDescribedMs = Date.parse(probeDescribedAt);
  const probeCreatedDuringConfirmedOutage = schedulerStopConfirmed === true
    && Number.isFinite(probeCreatedMs)
    && probeCreatedMs >= stoppedMs
    && probeCreatedMs < resumeRequestedMs;
  const probeDescribedDuringConfirmedOutage = schedulerStopConfirmed === true
    && Number.isFinite(probeDescribedMs)
    && Number.isFinite(outageObservedMs)
    && probeDescribedMs >= probeCreatedMs
    && probeDescribedMs <= outageObservedMs
    && outageObservedMs <= resumeRequestedMs;
  const storedOverdueOccurrenceTime = scheduleTimeField(
    preResumeDescription,
    ['next_fire_at', 'nextFireAt', 'next_fire', 'nextFire'],
  );
  const storedOverdueOccurrenceMs = Date.parse(storedOverdueOccurrenceTime);
  const storedOverdueOccurrenceElapsedDuringOutage = Number.isFinite(storedOverdueOccurrenceMs)
    && storedOverdueOccurrenceMs >= stoppedMs
    && storedOverdueOccurrenceMs < resumeRequestedMs;
  const firesDuringSchedulerOutage = scheduleTriggeredEvents(preResumeHistory?.events ?? []);
  const outageContinuityProven = schedulerStopConfirmed === true
    && probeCreatedDuringConfirmedOutage
    && probeDescribedDuringConfirmedOutage
    && firesDuringSchedulerOutage.length === 0
    && storedOverdueOccurrenceElapsedDuringOutage;
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
  const observedPolicy = outageContinuityProven
    ? inferMissedFirePolicy(catchupFireCount, postResumeNormalFireObserved)
    : 'not_observed';
  const failures = [];

  if (!schedulerStopConfirmed) {
    failures.push('scheduler stop was not confirmed before the missed-fire outage window');
  }
  if (!probeCreatedDuringConfirmedOutage) {
    failures.push('missed-fire probe was not created inside the confirmed scheduler outage window');
  }
  if (!probeDescribedDuringConfirmedOutage) {
    failures.push('missed-fire probe was not described inside the confirmed scheduler outage window');
  }
  if (firesDuringSchedulerOutage.length > 0) {
    failures.push(
      `observed ${firesDuringSchedulerOutage.length} fire(s) while scheduler evaluation was claimed unavailable`,
    );
  }
  if (!storedOverdueOccurrenceElapsedDuringOutage) {
    failures.push('the stored next fire did not elapse inside the confirmed scheduler outage window');
  }

  if (outageContinuityProven) {
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
  }

  return {
    scenario_id: 'missed_fire_policy',
    schedule_id: scheduleId,
    documented_policy: documentedPolicy,
    observed_policy: observedPolicy,
    catchup_fire_count: catchupFireCount,
    post_resume_normal_fire_observed: postResumeNormalFireObserved,
    scheduler_stop_requested_at: schedulerStopRequestedAt,
    scheduler_stop_confirmed: schedulerStopConfirmed === true,
    scheduler_stopped_at: schedulerStoppedAt,
    probe_created_at: probeCreatedAt,
    probe_described_at: probeDescribedAt,
    probe_created_during_confirmed_outage: probeCreatedDuringConfirmedOutage,
    probe_described_during_confirmed_outage: probeDescribedDuringConfirmedOutage,
    scheduler_outage_observed_at: schedulerOutageObservedAt,
    scheduler_resume_requested_at: schedulerResumeRequestedAt,
    scheduler_resume_confirmed_at: schedulerResumeConfirmedAt,
    downtime_seconds: downtimeSeconds,
    outage_wait_seconds: outageWaitMilliseconds / 1000,
    resume_timeout_seconds: resumeTimeoutSeconds,
    stored_overdue_occurrence_time: storedOverdueOccurrenceTime,
    stored_overdue_occurrence_elapsed_during_outage: storedOverdueOccurrenceElapsedDuringOutage,
    fires_during_scheduler_outage_count: firesDuringSchedulerOutage.length,
    fires_during_scheduler_outage: firesDuringSchedulerOutage.map(normalizeScheduleEvent).filter(Boolean),
    pre_resume_history: preResumeHistory,
    catchup_fires: catchupTriggers.map(normalizeScheduleEvent).filter(Boolean),
    normal_fires_after_resume: normalTriggers.map(normalizeScheduleEvent).filter(Boolean),
    post_resume_trigger_count: postResumeTriggers.length,
    history_after_resume: latestHistory,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: !outageContinuityProven
      ? 'runner_blocked'
      : (failures.length === 0 ? 'pass' : 'fail'),
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
    local_product_source_checkouts_used: false,
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

async function maybeRunAdversarialShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_ADVERSARIAL_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(adversarialEvidencePath) !== null) {
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

    return adversarialBlockedEvidence(
      `Adversarial schedule-input shard could not start because ${missing} is unavailable.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runAdversarialShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = failureReasonWithShardLogs(error, 'schedules-adversarial');
    return error instanceof PublishedStackInfrastructureError
      ? adversarialBlockedEvidence(reason, startedAt, artifactVersions, artifactSources)
      : adversarialFailureEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runAdversarialShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const shardStartedAt = timestamp();
  const runId = `schedules-adversarial-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_ADVERSARIAL_TASK_QUEUE)
    || `schedules-unregistered-${runId}`;
  const workflowType = stringValue(process.env.DW_SCHEDULES_ADVERSARIAL_WORKFLOW_TYPE)
    || `schedules.nonexistent.${runId}`;
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_SCHEDULER_TICK_SECONDS, 5);
  const pollSeconds = positiveInt(process.env.DW_SCHEDULES_ADVERSARIAL_POLL_SECONDS, 3);
  const fireTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_ADVERSARIAL_FIRE_TIMEOUT_SECONDS, 90);
  const pendingObservationSeconds = positiveInt(process.env.DW_SCHEDULES_ADVERSARIAL_PENDING_OBSERVATION_SECONDS, 10);
  const interval = stringValue(process.env.DW_SCHEDULES_ADVERSARIAL_INTERVAL) || 'PT10S';
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const overlayPath = path.join(resultDir, 'schedules-adversarial-compose.override.yml');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  let composeStarted = false;
  const schedulesCreated = [];

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url');

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-adversarial-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-adversarial',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
    await ensureNamespace(serverUrl, token, namespace);

    const scheduleId = `${runId}-missing-type`;
    const createRequest = {
      schedule_id: scheduleId,
      spec: { intervals: [{ every: interval }], timezone: 'UTC' },
      action: {
        workflow_type: workflowType,
        task_queue: taskQueue,
        input: [{ schedule_id: scheduleId, workflow_type: workflowType }],
      },
      overlap_policy: 'allow_all',
      jitter_seconds: 0,
      max_runs: 1,
    };
    const createResponse = await apiRequestResult(
      serverUrl,
      token,
      namespace,
      'POST',
      '/schedules',
      createRequest,
    );

    let observation;
    if (!createResponse.ok) {
      const describeAfterRefusal = await safeApiRequestResult(
        serverUrl,
        token,
        namespace,
        'GET',
        `/schedules/${encodeURIComponent(scheduleId)}`,
      );
      const listAfterRefusal = await safeApiRequestResult(serverUrl, token, namespace, 'GET', '/schedules');
      observation = refusedAtCreateObservation({
        scheduleId,
        workflowType,
        taskQueue,
        namespace,
        interval,
        createRequest,
        createResponse,
        describeAfterRefusal,
        listAfterRefusal,
        artifactVersions,
        artifactSources,
      });
    } else {
      schedulesCreated.push(scheduleId);
      observation = await observeNonexistentWorkflowTypeFire({
        serverUrl,
        token,
        namespace,
        scheduleId,
        workflowType,
        taskQueue,
        interval,
        createRequest,
        createResponse,
        fireTimeoutSeconds,
        pendingObservationSeconds,
        pollSeconds,
        artifactVersions,
        artifactSources,
      });
      await bestEffortDeleteSchedule(serverUrl, token, namespace, scheduleId);
    }

    const evidence = adversarialEvidenceFromObservation({
      startedAt: shardStartedAt,
      finishedAt: timestamp(),
      observation,
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      schedulesCreated,
    });
    writeJson(adversarialEvidencePath, evidence);

    return evidence;
  } finally {
    if (composeStarted) {
      await collectAdversarialComposeLogs(composeProject, composeFiles);
      await removePublishedComposeProject(
        composeProject,
        composeFiles,
        composeEnv(serverPort, serverImage, token, artifactVersions),
      );
    }

    writeJson(path.join(resultDir, 'schedules-adversarial-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.adversarial-run-metadata',
      started_at: startedAt,
      adversarial_started_at: shardStartedAt,
      finished_at: timestamp(),
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      server_image: existingServerUrl === '' ? serverImage : null,
      compose_project: existingServerUrl === '' ? composeProject : null,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
      schedules_created: schedulesCreated,
    });
  }
}

function refusedAtCreateObservation({
  scheduleId,
  workflowType,
  taskQueue,
  namespace,
  interval,
  createRequest,
  createResponse,
  describeAfterRefusal,
  listAfterRefusal,
  artifactVersions,
  artifactSources,
}) {
  const persisted = describeAfterRefusal.ok || scheduleListContains(listAfterRefusal.parsed, scheduleId);
  const operatorSignalVisible = createResponse.status >= 400
    && (Object.keys(objectValue(createResponse.parsed)).length > 0 || stringValue(createResponse.text) !== '');
  const failures = [];

  if (persisted) {
    failures.push('schedule was persisted even though create returned a refusal response');
  }
  if (!operatorSignalVisible) {
    failures.push('create-time refusal did not expose an operator-visible response body');
  }

  return {
    scenario_id: 'nonexistent_workflow_type_outcome',
    behavior: 'refused_at_create',
    allowed_behaviors: ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
    namespace,
    schedule_id: scheduleId,
    workflow_type: workflowType,
    task_queue: taskQueue,
    interval,
    request: createRequest,
    create_response: publicResponseSnapshot(createResponse),
    describe_after_refusal: publicResponseSnapshot(describeAfterRefusal),
    list_after_refusal: publicResponseSnapshot(listAfterRefusal),
    persisted,
    operator_visible_signal: {
      surface: 'POST /api/schedules',
      http_status: createResponse.status,
      body: createResponse.parsed,
    },
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

async function observeNonexistentWorkflowTypeFire({
  serverUrl,
  token,
  namespace,
  scheduleId,
  workflowType,
  taskQueue,
  interval,
  createRequest,
  createResponse,
  fireTimeoutSeconds,
  pendingObservationSeconds,
  pollSeconds,
  artifactVersions,
  artifactSources,
}) {
  const fireWindowStartedAt = timestamp();
  const deadlineMs = Date.now() + fireTimeoutSeconds * 1000;
  let latestHistory = { events: [] };
  let latestDescription = {};
  let latestWorkflowList = {};

  while (Date.now() < deadlineMs) {
    latestHistory = await safeScheduleHistory(serverUrl, token, namespace, scheduleId);
    const skippedEvents = scheduleSkippedEvents(latestHistory.events ?? []);
    if (skippedEvents.length > 0) {
      latestDescription = await safeApiRequestResult(
        serverUrl,
        token,
        namespace,
        'GET',
        `/schedules/${encodeURIComponent(scheduleId)}`,
      );
      return fireTimeSkippedObservation({
        scheduleId,
        workflowType,
        taskQueue,
        namespace,
        interval,
        createRequest,
        createResponse,
        fireWindowStartedAt,
        fireWindowFinishedAt: timestamp(),
        triggerSkippedEvent: skippedEvents[0],
        latestHistory,
        latestDescription,
        artifactVersions,
        artifactSources,
      });
    }

    const triggeredEvents = scheduleTriggeredEvents(latestHistory.events ?? []);
    if (triggeredEvents.length > 0) {
      await sleep(pendingObservationSeconds * 1000);
      latestDescription = await safeApiRequestResult(
        serverUrl,
        token,
        namespace,
        'GET',
        `/schedules/${encodeURIComponent(scheduleId)}`,
      );
      latestWorkflowList = await safeApiRequestResult(
        serverUrl,
        token,
        namespace,
        'GET',
        `/workflows?${new URLSearchParams({ workflow_type: workflowType, page_size: '10' }).toString()}`,
      );
      const workflowRun = await workflowRunForScheduleEvent(
        serverUrl,
        token,
        namespace,
        normalizeScheduleEvent(triggeredEvents[0]),
      );

      return triggeredWorkflowObservation({
        scheduleId,
        workflowType,
        taskQueue,
        namespace,
        interval,
        createRequest,
        createResponse,
        fireWindowStartedAt,
        fireWindowFinishedAt: timestamp(),
        triggerEvent: triggeredEvents[0],
        latestHistory,
        latestDescription,
        latestWorkflowList,
        workflowRun,
        artifactVersions,
        artifactSources,
      });
    }

    await sleep(pollSeconds * 1000);
  }

  latestDescription = await safeApiRequestResult(
    serverUrl,
    token,
    namespace,
    'GET',
    `/schedules/${encodeURIComponent(scheduleId)}`,
  );
  latestWorkflowList = await safeApiRequestResult(
    serverUrl,
    token,
    namespace,
    'GET',
    `/workflows?${new URLSearchParams({ workflow_type: workflowType, page_size: '10' }).toString()}`,
  );

  return ambiguousNonexistentWorkflowObservation({
    scheduleId,
    workflowType,
    taskQueue,
    namespace,
    interval,
    createRequest,
    createResponse,
    fireWindowStartedAt,
    fireWindowFinishedAt: timestamp(),
    latestHistory,
    latestDescription,
    latestWorkflowList,
    reason: `no ScheduleTriggered or ScheduleTriggerSkipped event was visible within ${fireTimeoutSeconds}s`,
    artifactVersions,
    artifactSources,
  });
}

function fireTimeSkippedObservation({
  scheduleId,
  workflowType,
  taskQueue,
  namespace,
  interval,
  createRequest,
  createResponse,
  fireWindowStartedAt,
  fireWindowFinishedAt,
  triggerSkippedEvent,
  latestHistory,
  latestDescription,
  artifactVersions,
  artifactSources,
}) {
  const normalizedEvent = normalizeScheduleEvent(triggerSkippedEvent);
  const reason = stringValue(normalizedEvent?.payload?.reason);
  const failures = reason === '' ? ['ScheduleTriggerSkipped event did not expose a reason'] : [];

  return {
    scenario_id: 'nonexistent_workflow_type_outcome',
    behavior: 'fails_at_fire_time',
    allowed_behaviors: ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
    namespace,
    schedule_id: scheduleId,
    workflow_type: workflowType,
    task_queue: taskQueue,
    interval,
    request: createRequest,
    create_response: publicResponseSnapshot(createResponse),
    fire_window: { started_at: fireWindowStartedAt, finished_at: fireWindowFinishedAt },
    trigger_skipped_event: normalizedEvent,
    latest_history: latestHistory,
    latest_description: publicResponseSnapshot(latestDescription),
    operator_visible_signal: {
      surface: `GET /api/schedules/${scheduleId}/history`,
      event_type: 'ScheduleTriggerSkipped',
      reason,
      event: normalizedEvent,
    },
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

function triggeredWorkflowObservation({
  scheduleId,
  workflowType,
  taskQueue,
  namespace,
  interval,
  createRequest,
  createResponse,
  fireWindowStartedAt,
  fireWindowFinishedAt,
  triggerEvent,
  latestHistory,
  latestDescription,
  latestWorkflowList,
  workflowRun,
  artifactVersions,
  artifactSources,
}) {
  const normalizedEvent = normalizeScheduleEvent(triggerEvent);
  const workflowRunResponse = workflowRun.response;
  const runDetail = objectValue(workflowRunResponse.parsed);
  const failedAtFireTime = workflowRunResponse.ok && workflowRunFailed(runDetail);
  const pendingWorker = workflowRunResponse.ok && workflowRunPending(runDetail);
  const failures = [];
  let behavior = 'silent_or_ambiguous';
  let operatorVisibleSignal = {
    surface: `GET /api/schedules/${scheduleId}/history`,
    event_type: 'ScheduleTriggered',
    event: normalizedEvent,
  };

  if (failedAtFireTime) {
    behavior = 'fails_at_fire_time';
    operatorVisibleSignal = {
      surface: `GET /api/workflows/${workflowRun.workflow_id}/runs/${workflowRun.run_id}`,
      workflow_status: stringValue(runDetail.status),
      error: runDetail.error ?? runDetail.failure ?? runDetail.failures ?? null,
      run: runDetail,
    };
  } else if (pendingWorker) {
    behavior = 'accepted_pending_worker';
    operatorVisibleSignal = {
      surface: `GET /api/workflows/${workflowRun.workflow_id}/runs/${workflowRun.run_id}`,
      workflow_status: stringValue(runDetail.status),
      task_queue: stringValue(runDetail.task_queue),
      wait_kind: stringValue(runDetail.wait_kind),
      wait_reason: stringValue(runDetail.wait_reason),
      compatibility_status: stringValue(runDetail.compatibility_status),
      compatibility_supported_in_fleet: runDetail.compatibility_supported_in_fleet ?? null,
      compatibility_fleet_reason: stringValue(runDetail.compatibility_fleet_reason),
      no_worker_registered_by_probe: true,
      run: runDetail,
    };
  } else if (!workflowRun.workflow_id || !workflowRun.run_id) {
    failures.push('ScheduleTriggered did not include workflow_instance_id and workflow_run_id');
  } else if (!workflowRunResponse.ok) {
    failures.push(`workflow run detail was not visible through public API; status=${workflowRunResponse.status}`);
  } else {
    failures.push('workflow run detail was visible but did not expose failed or pending-worker state');
  }

  return {
    scenario_id: 'nonexistent_workflow_type_outcome',
    behavior,
    allowed_behaviors: ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
    namespace,
    schedule_id: scheduleId,
    workflow_type: workflowType,
    task_queue: taskQueue,
    interval,
    request: createRequest,
    create_response: publicResponseSnapshot(createResponse),
    fire_window: { started_at: fireWindowStartedAt, finished_at: fireWindowFinishedAt },
    trigger_event: normalizedEvent,
    latest_history: latestHistory,
    latest_description: publicResponseSnapshot(latestDescription),
    workflow_list: publicResponseSnapshot(latestWorkflowList),
    workflow_run: {
      workflow_id: workflowRun.workflow_id,
      run_id: workflowRun.run_id,
      response: publicResponseSnapshot(workflowRunResponse),
    },
    operator_visible_signal: operatorVisibleSignal,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures,
    verdict: failures.length === 0 ? 'pass' : 'fail',
  };
}

function ambiguousNonexistentWorkflowObservation({
  scheduleId,
  workflowType,
  taskQueue,
  namespace,
  interval,
  createRequest,
  createResponse,
  fireWindowStartedAt,
  fireWindowFinishedAt,
  latestHistory,
  latestDescription,
  latestWorkflowList,
  reason,
  artifactVersions,
  artifactSources,
}) {
  return {
    scenario_id: 'nonexistent_workflow_type_outcome',
    behavior: 'silent_or_ambiguous',
    allowed_behaviors: ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
    namespace,
    schedule_id: scheduleId,
    workflow_type: workflowType,
    task_queue: taskQueue,
    interval,
    request: createRequest,
    create_response: publicResponseSnapshot(createResponse),
    fire_window: { started_at: fireWindowStartedAt, finished_at: fireWindowFinishedAt },
    latest_history: latestHistory,
    latest_description: publicResponseSnapshot(latestDescription),
    workflow_list: publicResponseSnapshot(latestWorkflowList),
    operator_visible_signal: null,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    failures: [reason],
    failure_reason: reason,
    verdict: 'fail',
  };
}

async function workflowRunForScheduleEvent(serverUrl, token, namespace, event) {
  const workflowId = stringValue(event?.workflow_instance_id ?? event?.payload?.workflow_instance_id);
  const runId = stringValue(event?.workflow_run_id ?? event?.payload?.workflow_run_id);

  if (workflowId === '' || runId === '') {
    return {
      workflow_id: workflowId,
      run_id: runId,
      response: {
        ok: false,
        status: 0,
        parsed: { reason: 'schedule_event_missing_workflow_run_identity' },
        text: 'schedule event did not include workflow identity',
      },
    };
  }

  return {
    workflow_id: workflowId,
    run_id: runId,
    response: await safeApiRequestResult(
      serverUrl,
      token,
      namespace,
      'GET',
      `/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`,
    ),
  };
}

function adversarialEvidenceFromObservation({
  startedAt,
  finishedAt,
  observation,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  schedulesCreated,
}) {
  const status = observation.verdict === 'pass'
    ? 'pass'
    : (observation.verdict === 'runner_blocked' ? 'runner_blocked' : 'fail');
  const linkedFindings = status === 'pass' ? [] : [adversarialFinding(observation)];

  return {
    schema: 'durable-workflow.v2.schedules-runtime.adversarial-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    scenario_results: {
      nonexistent_workflow_type_outcome: {
        scenario_id: 'nonexistent_workflow_type_outcome',
        status,
        observed_outputs: observation,
        linked_findings: linkedFindings,
      },
    },
    findings: linkedFindings,
    adversarial_outcomes: {
      nonexistent_workflow_type_outcome: observation,
    },
    topology: {
      namespace,
      task_queue: taskQueue,
      worker_execution_mode: 'no_worker_registered_for_target_workflow_type',
      schedules_created: schedulesCreated,
    },
    runtime_matrix: {
      runtimes: ['server-scheduler'],
      client_paths: ['server-http-api'],
      schedule_types: ['fixed_rate_interval'],
    },
  };
}

function adversarialFinding(observation) {
  const runnerBlocked = observation.verdict === 'runner_blocked';
  const configured = coverageGapFindings.nonexistent_workflow_type_outcome ?? {};
  const observed = arrayValue(observation.failures).join('; ')
    || stringValue(observation.failure_reason)
    || `Observed behavior ${stringValue(observation.behavior) || '<missing>'} for a schedule targeting an unregistered workflow type.`;

  return {
    finding_id: runnerBlocked
      ? 'schedules-adversarial-runner-blocked-nonexistent-workflow-type'
      : 'schedules-nonexistent-workflow-type-outcome',
    scenario_id: 'nonexistent_workflow_type_outcome',
    finding_type: runnerBlocked
      ? 'conformance_runner_blocked'
      : 'schedule_nonexistent_workflow_type_outcome_gap',
    owning_surface: runnerBlocked ? 'conformance_harness' : 'server',
    execution_scope: stringValue(configured.scope) || 'adversarial-schedule-input-shard',
    artifact_versions: observation.artifact_versions ?? {},
    observed_behavior: observed,
    expected_behavior: runnerBlocked
      ? 'The schedules conformance host can run the adversarial schedule-input shard against published artifacts.'
      : (stringValue(configured.expected_behavior)
        || 'A schedule targeting a non-existent workflow type produces a documented operator-visible create-time, fire-time, or pending-worker outcome.'),
    next_acceptance_criterion: runnerBlocked
      ? 'restore the missing host capability and rerun schedules conformance'
      : (arrayValue(configured.acceptance).join('; ')
        || 'create or attempt to create a schedule targeting an unregistered workflow type and record the operator-visible outcome'),
    request: observation.request ?? null,
    create_response: observation.create_response ?? null,
    schedule_id: observation.schedule_id ?? null,
    workflow_type: observation.workflow_type ?? null,
    task_queue: observation.task_queue ?? null,
    fire_window: observation.fire_window ?? null,
    observed_operator_state: observation.operator_visible_signal ?? {
      latest_history: observation.latest_history ?? null,
      latest_description: observation.latest_description ?? null,
      workflow_list: observation.workflow_list ?? null,
    },
  };
}

function adversarialFailureEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  return adversarialEvidenceFromObservation({
    startedAt,
    finishedAt,
    observation: {
      scenario_id: 'nonexistent_workflow_type_outcome',
      behavior: 'silent_or_ambiguous',
      allowed_behaviors: ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
      artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      failures: [reason],
      failure_reason: reason,
      verdict: 'fail',
    },
    artifactVersions,
    artifactSources,
    namespace: stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance',
    taskQueue: stringValue(process.env.DW_SCHEDULES_ADVERSARIAL_TASK_QUEUE) || 'schedules-unregistered',
    schedulesCreated: [],
  });
}

function adversarialBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  return adversarialEvidenceFromObservation({
    startedAt,
    finishedAt,
    observation: {
      scenario_id: 'nonexistent_workflow_type_outcome',
      behavior: 'runner_blocked',
      allowed_behaviors: ['refused_at_create', 'fails_at_fire_time', 'accepted_pending_worker'],
      artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      failures: [reason],
      blocked_reason: reason,
      verdict: 'runner_blocked',
    },
    artifactVersions,
    artifactSources,
    namespace: stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance',
    taskQueue: stringValue(process.env.DW_SCHEDULES_ADVERSARIAL_TASK_QUEUE) || 'schedules-unregistered',
    schedulesCreated: [],
  });
}

function scheduleSkippedEvents(events) {
  return arrayValue(events)
    .filter((event) => stringValue(event.event_type ?? event.eventType) === 'ScheduleTriggerSkipped')
    .sort((left, right) => eventRecordedMs(left) - eventRecordedMs(right));
}

async function safeScheduleHistory(serverUrl, token, namespace, scheduleId) {
  try {
    return await scheduleHistory(serverUrl, token, namespace, scheduleId);
  } catch (error) {
    return {
      events: [],
      error: error instanceof Error ? error.message : String(error),
    };
  }
}

async function safeApiRequestResult(serverUrl, token, namespace, method, pathAndQuery, body = null) {
  try {
    return await apiRequestResult(serverUrl, token, namespace, method, pathAndQuery, body);
  } catch (error) {
    const reason = error instanceof Error ? error.message : String(error);
    return {
      ok: false,
      status: 0,
      parsed: {
        message: reason,
        reason: 'request_failed',
      },
      text: reason,
    };
  }
}

function publicResponseSnapshot(response) {
  return {
    ok: response.ok === true,
    status: Number.isInteger(response.status) ? response.status : 0,
    body: response.parsed ?? {},
    raw_body: stringValue(response.text).slice(0, 2000),
  };
}

function workflowRunFailed(runDetail) {
  const status = stringValue(runDetail.status).toLowerCase();
  return status === 'failed'
    || (runDetail.is_terminal === true && (runDetail.failure !== undefined || runDetail.error !== undefined));
}

function workflowRunPending(runDetail) {
  const status = stringValue(runDetail.status).toLowerCase();
  if (status === '' || workflowRunFailed(runDetail)) {
    return false;
  }

  return runDetail.is_terminal !== true;
}

async function collectAdversarialComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-adversarial-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
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
    workflow_instance_id: stringValue(
      event.workflow_instance_id
        ?? event.workflowInstanceId
        ?? event.payload?.workflow_instance_id
        ?? event.payload?.workflowInstanceId,
    ),
    workflow_run_id: stringValue(
      event.workflow_run_id
        ?? event.workflowRunId
        ?? event.payload?.workflow_run_id
        ?? event.payload?.workflowRunId,
    ),
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
    const reason = failureReasonWithShardLogs(error, 'schedules-cli');
    return cliSurfaceBlockedEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runCliSurfaceShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const cliStartedAt = timestamp();
  const runId = `schedules-cli-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || 'schedules-cli-surface';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const composeFiles = ['-f', path.join(repoRoot, 'docker-compose.published.yml')];
  let composeStarted = false;
  let cliPath = '';

  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url', artifactVersions);

  if (existingServerUrl === '') {
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-cli-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-cli',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
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
      await removePublishedComposeProject(
        composeProject,
        composeFiles,
        composeEnv(serverPort, serverImage, token, artifactVersions),
      );
    }
  }
}

async function resolvePublishedCli(artifactVersions, artifactSources) {
  const configuredCli = stringValue(process.env.DW_SCHEDULES_CLI_EXECUTABLE ?? process.env.DW_CLI_EXECUTABLE);
  if (configuredCli !== '') {
    fs.accessSync(configuredCli, fs.constants.X_OK);
    markArtifactSource(artifactSources, 'cli', 'official_cli_executable', artifactVersions);
    return configuredCli;
  }

  if (publishedCliInstallPromise === null) {
    publishedCliInstallPromise = installPublishedCliArtifact(artifactVersions, artifactSources);
  }

  return await publishedCliInstallPromise;
}

async function installPublishedCliArtifact(artifactVersions, artifactSources) {
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
      PATH: [installDir, process.env.PATH ?? ''].filter(Boolean).join(path.delimiter),
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
  markArtifactSource(artifactSources, 'cli', installerUrl, artifactVersions);
  writeJson(path.join(resultDir, 'schedules-cli-install.json'), {
    schema: 'durable-workflow.v2.schedules-runtime.cli-install',
    cli_version: cliVersion,
    installer_url: installerUrl,
    install_dir: installDir,
    executable: cliPath,
    source: installerUrl,
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
}) {
  const env = composeEnv(serverPort, serverImage, token, artifactVersions);
  const baseArgs = ['compose', '-p', composeProject, ...composeFiles];
  const waitTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_COMPOSE_WAIT_TIMEOUT_SECONDS, 600);

  try {
    await execLogged(
      'docker',
      [...baseArgs, 'up', '-d', '--wait', '--wait-timeout', String(waitTimeoutSeconds)],
      path.join(resultDir, `${logPrefix}-compose-up.log`),
      env,
    );
  } catch (error) {
    await collectPublishedComposeStartupLogs(composeProject, composeFiles, logPrefix, env);
    await removePublishedComposeProject(composeProject, composeFiles, env);
    throw new PublishedStackStartupError(error instanceof Error ? error.message : String(error));
  }
}

async function collectPublishedComposeStartupLogs(composeProject, composeFiles, logPrefix, env) {
  for (const service of ['server', 'scheduler', 'worker', 'bootstrap', 'mysql', 'redis']) {
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      path.join(resultDir, `${logPrefix}-${service}.log`),
      env,
    ).catch(() => {});
  }
}

async function removePublishedComposeProject(composeProject, composeFiles, env) {
  const args = ['compose', '-p', composeProject, ...composeFiles, 'down', '-v', '--remove-orphans'];
  let lastError = null;

  for (let attempt = 1; attempt <= 2; attempt += 1) {
    try {
      await execFile('docker', args, {
        env,
        maxBuffer: 1024 * 1024 * 8,
      });
      return;
    } catch (error) {
      lastError = error;
    }
  }

  const detail = compactLogText(lastError?.stderr || lastError?.message || lastError);
  throw new PublishedStackCleanupError(
    `docker compose cleanup failed for project ${composeProject} after 2 attempts: ${detail}`,
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
      signal: stringValue(error?.signal),
      timed_out: error?.killed === true || stringValue(error?.signal) === 'SIGTERM',
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
    local_product_source_checkouts_used: false,
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

async function maybeRunCrossLanguageShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_CROSS_LANGUAGE_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(crossLanguageEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const serverUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);
  const pythonVersion = stringValue(artifactVersions['sdk-python']);
  const sdkPhpVersion = stringValue(artifactVersions['sdk-php']);
  const configuredCli = stringValue(process.env.DW_SCHEDULES_CLI_EXECUTABLE ?? process.env.DW_CLI_EXECUTABLE);
  const cliVersion = stringValue(artifactVersions.cli);
  const missing = [];

  if (!await commandSucceeds('python3', ['--version'])) {
    missing.push('python3');
  }
  if (pythonVersion === '') {
    missing.push('DW_PYTHON_SDK_VERSION');
  }
  if (!dockerAvailable) {
    missing.push('docker');
  }
  if (sdkPhpVersion === '') {
    missing.push('DW_PHP_SDK_VERSION');
  }
  if (configuredCli === '' && cliVersion === '') {
    missing.push('DW_CLI_VERSION or DW_SCHEDULES_CLI_EXECUTABLE');
  }
  if (serverUrl === '' && (!dockerAvailable || !composeAvailable || serverImage === '')) {
    if (dockerAvailable && !composeAvailable) {
      missing.push('docker compose');
    }
    if (serverImage === '') {
      missing.push('DW_SERVER_VERSION or DW_SERVER_IMAGE');
    }
  }

  if (missing.length > 0) {
    if (!explicit) {
      return null;
    }

    return crossLanguageBlockedEvidence(
      `Cross-language schedules shard prerequisites are missing: ${missing.join(', ')}.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runCrossLanguageShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = failureReasonWithShardLogs(error, 'schedules-cross-language');
    return crossLanguageBlockedEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runCrossLanguageShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const crossStartedAt = timestamp();
  const runId = `schedules-cross-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_TASK_QUEUE) || `schedules-cross-language-${runId}`;
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const overlayPath = path.join(resultDir, 'schedules-cross-language-compose.override.yml');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  const shardRoot = path.join(resultDir, 'schedules-cross-language-shard');
  const interval = stringValue(process.env.DW_SCHEDULES_CROSS_LANGUAGE_INTERVAL) || 'PT30S';
  const timeoutSeconds = positiveInt(process.env.DW_SCHEDULES_CROSS_LANGUAGE_TIMEOUT_SECONDS, 150);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_CROSS_LANGUAGE_SCHEDULER_TICK_SECONDS, 2);
  const scheduleMaxRuns = Math.max(2, positiveInt(process.env.DW_SCHEDULES_CROSS_LANGUAGE_MAX_RUNS, 2));
  const phpWorkflowType = 'SchedulesConformancePhpWorkflow';
  const pythonWorkflowType = 'SchedulesConformancePythonWorkflow';
  const pythonCreatedPhpScheduleId = `${runId}-python-created-php`;
  const phpCreatedPythonScheduleId = `${runId}-php-created-python`;
  const phpWorkerId = `${runId}-php-worker`;
  const pythonWorkerId = `${runId}-python-worker`;
  const focusedScenarios = crossLanguageFocusedScenarios();
  const runPythonCreatedPhp = focusedScenarios.has('python_created_php_workflow');
  const runPhpCreatedPython = focusedScenarios.has('php_created_python_workflow');
  let composeStarted = false;
  let cliPath = '';

  fs.rmSync(shardRoot, { recursive: true, force: true });
  fs.mkdirSync(shardRoot, { recursive: true });
  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url', artifactVersions);

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-cross-language-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-cross-language',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
    await ensureNamespace(serverUrl, token, namespace);

    cliPath = await resolvePublishedCli(artifactVersions, artifactSources);
    const python = await installSchedulesPythonArtifact(shardRoot, artifactVersions, artifactSources);
    const php = await installSchedulesPhpArtifact(shardRoot, artifactVersions, artifactSources);

    const phpRegistration = runPythonCreatedPhp
      ? await runSchedulesPhpWorker(php, {
          action: 'register',
          server_url: serverUrl,
          token,
          namespace,
          task_queue: taskQueue,
          worker_id: phpWorkerId,
          workflow_type: phpWorkflowType,
          runtime: 'sdk-php',
          sdk_version: artifactValue(artifactVersions, 'sdk-php'),
        })
      : null;
    const pythonRegistration = runPhpCreatedPython
      ? await runSchedulesPythonWorker(python, {
          action: 'register',
          server_url: serverUrl,
          token,
          namespace,
          task_queue: taskQueue,
          worker_id: pythonWorkerId,
          workflow_type: pythonWorkflowType,
          runtime: 'sdk-python',
          sdk_version: artifactValue(artifactVersions, 'sdk-python'),
        })
      : null;

    const pythonCreate = runPythonCreatedPhp
      ? await runSchedulesPythonWorker(python, {
          action: 'create_schedule',
          server_url: serverUrl,
          token,
          namespace,
          task_queue: taskQueue,
          schedule_id: pythonCreatedPhpScheduleId,
          workflow_type: phpWorkflowType,
          interval,
          schedule_max_runs: scheduleMaxRuns,
          input: {
            scenario: 'python_created_php_workflow',
            schedule_creator: 'sdk-python',
            workflow_runtime: 'sdk-php',
          },
        })
      : null;
    const phpCreate = runPhpCreatedPython
      ? await runSchedulesPhpWorker(php, {
          action: 'create_schedule',
          server_url: serverUrl,
          token,
          namespace,
          task_queue: taskQueue,
          schedule_id: phpCreatedPythonScheduleId,
          workflow_type: pythonWorkflowType,
          interval,
          schedule_max_runs: scheduleMaxRuns,
          input: {
            scenario: 'php_created_python_workflow',
            schedule_creator: 'sdk-php',
            workflow_runtime: 'sdk-python',
          },
        })
      : null;

    const context = { serverUrl, namespace, token };
    const cliList = await runDwJson(cliPath, ['schedules', 'list', '--json'], context);
    const completion = await waitForCrossLanguageCompletions({
      serverUrl,
      token,
      namespace,
      taskQueue,
      timeoutSeconds,
      python,
      php,
      phpWorkerId,
      pythonWorkerId,
      phpWorkflowType,
      pythonWorkflowType,
      pythonCreatedPhpScheduleId,
      phpCreatedPythonScheduleId,
      focusedScenarios,
    });

    if (runPythonCreatedPhp) {
      await bestEffortDeleteSchedule(serverUrl, token, namespace, pythonCreatedPhpScheduleId);
    }
    if (runPhpCreatedPython) {
      await bestEffortDeleteSchedule(serverUrl, token, namespace, phpCreatedPythonScheduleId);
    }

    const evidence = crossLanguageEvidenceFromObservations({
      startedAt: crossStartedAt,
      finishedAt: timestamp(),
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      runId,
      cliPath,
      cliList,
      pythonCreate,
      phpCreate,
      phpRegistration,
      pythonRegistration,
      phpCompletion: completion.php,
      pythonCompletion: completion.python,
      schedules: {
        pythonCreatedPhp: pythonCreatedPhpScheduleId,
        phpCreatedPython: phpCreatedPythonScheduleId,
      },
      scheduleMaxRuns,
      workers: {
        php: phpWorkerId,
        python: pythonWorkerId,
      },
      focusedScenarios,
    });
    writeJson(crossLanguageEvidencePath, evidence);

    return evidence;
  } finally {
    writeJson(path.join(resultDir, 'schedules-cross-language-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.cross-language-run-metadata',
      started_at: startedAt,
      cross_language_started_at: crossStartedAt,
      finished_at: timestamp(),
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      schedule_max_runs: scheduleMaxRuns,
      server_image: existingServerUrl === '' ? serverImage : null,
      compose_project: existingServerUrl === '' ? composeProject : null,
      cli_executable: cliPath || null,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
      focused_scenarios: Array.from(focusedScenarios),
    });

    if (composeStarted) {
      await collectCrossLanguageComposeLogs(composeProject, composeFiles);
      await removePublishedComposeProject(
        composeProject,
        composeFiles,
        composeEnv(serverPort, serverImage, token, artifactVersions),
      );
    }
  }
}

function crossLanguageFocusedScenarios() {
  const raw = stringValue(process.env.DW_SCHEDULES_CROSS_LANGUAGE_FOCUS)
    .toLowerCase()
    .replace(/-/g, '_')
    .trim();
  if (raw === '' || raw === 'all' || raw === 'both') {
    return new Set(['python_created_php_workflow', 'php_created_python_workflow']);
  }
  if (raw === 'python_created_php' || raw === 'python_created_php_workflow') {
    return new Set(['python_created_php_workflow']);
  }
  if (raw === 'php_created_python' || raw === 'php_created_python_workflow') {
    return new Set(['php_created_python_workflow']);
  }

  throw new Error(`Unsupported DW_SCHEDULES_CROSS_LANGUAGE_FOCUS value: ${process.env.DW_SCHEDULES_CROSS_LANGUAGE_FOCUS}`);
}

async function installSchedulesPythonArtifact(shardRoot, artifactVersions, artifactSources) {
  const pythonRoot = path.join(shardRoot, 'python');
  const venv = path.join(pythonRoot, 'venv');
  const pythonVersion = artifactValue(artifactVersions, 'sdk-python');
  const scriptPath = path.join(pythonRoot, 'schedules_worker.py');
  fs.mkdirSync(pythonRoot, { recursive: true });
  writeText(scriptPath, schedulesPythonWorkerScript());

  await execLogged('python3', ['-m', 'venv', venv], path.join(resultDir, 'schedules-cross-language-python-venv.log'));
  const pythonBin = path.join(venv, 'bin', 'python');
  await execLogged(pythonBin, ['-m', 'pip', 'install', '--upgrade', 'pip'], path.join(resultDir, 'schedules-cross-language-python-pip-upgrade.log'));
  await execLogged(
    pythonBin,
    ['-m', 'pip', 'install', `durable-workflow==${pythonVersion}`],
    path.join(resultDir, 'schedules-cross-language-python-install.log'),
  );
  markArtifactSource(artifactSources, 'sdk-python', pythonPackageArtifactSource(pythonVersion), artifactVersions);

  return { pythonRoot, pythonBin, scriptPath };
}

async function installSchedulesPhpArtifact(
  shardRoot,
  artifactVersions,
  artifactSources,
  logPrefix = 'schedules-cross-language',
) {
  const phpRoot = path.join(shardRoot, 'php');
  const sdkPhpVersion = artifactValue(artifactVersions, 'sdk-php');
  const scriptPath = path.join(phpRoot, 'schedules_worker.php');
  fs.mkdirSync(phpRoot, { recursive: true });
  writeText(scriptPath, schedulesPhpWorkerScript());
  await execLogged(
    'docker',
    [
      'run',
      '--rm',
      '--network',
      'host',
      '-v',
      `${phpRoot}:/app`,
      '-w',
      '/app',
      'composer:2',
      'require',
      '--no-interaction',
      '--no-progress',
      `durable-workflow/sdk:${sdkPhpVersion}`,
    ],
    path.join(resultDir, `${logPrefix}-php-install.log`),
  );
  const installedVersionResult = await execLogged(
    'docker',
    [
      'run',
      '--rm',
      '--network',
      'host',
      '-v',
      `${phpRoot}:/app`,
      '-w',
      '/app',
      '--entrypoint',
      'php',
      'composer:2',
      '-r',
      "require 'vendor/autoload.php'; echo Composer\\InstalledVersions::getPrettyVersion('durable-workflow/sdk') ?: '';",
    ],
    path.join(resultDir, `${logPrefix}-php-installed-version.log`),
  );
  const installedVersion = normalizeComposerVersion(installedVersionResult.stdout);
  if (installedVersion !== sdkPhpVersion) {
    throw new Error(
      `pinned durable-workflow/sdk version mismatch: expected ${sdkPhpVersion}, got ${installedVersion || 'empty'}`,
    );
  }
  const source = composerPackageArtifactSource('durable-workflow/sdk', installedVersion);
  recordExecutedArtifactInstall({
    artifact: 'sdk-php',
    package: 'durable-workflow/sdk',
    requested_version: sdkPhpVersion,
    installed_version: installedVersion,
    version: installedVersion,
    source,
    status: 'pass',
    install_channel: 'Packagist via Composer',
    local_product_source_checkouts_used: false,
  });
  markArtifactSource(
    artifactSources,
    'sdk-php',
    source,
    artifactVersions,
  );

  return { phpRoot, scriptPath };
}

function recordExecutedArtifactInstall(install) {
  const artifact = stringValue(install?.artifact);
  if (artifact === '') {
    return;
  }

  const previous = objectValue(executedArtifactInstalls.get(artifact));
  executedArtifactInstalls.set(artifact, {
    ...previous,
    ...install,
    executions: positiveInt(previous.executions, 0) + 1,
    verified_at: timestamp(),
  });
}

async function runSchedulesPythonWorker(python, input) {
  const action = await runSchedulesPythonWorkerCapture(python, input);
  if (!action.ok) {
    throw new Error(action.error);
  }

  return action.output;
}

async function runSchedulesPythonWorkerCapture(python, input) {
  const { inputPath, outputPath } = writeSchedulesWorkerInput(python.pythonRoot, input);
  const logPath = path.join(resultDir, `schedules-cross-language-python-${safeLogName(input.worker_id ?? input.action)}-${input.action}.log`);
  const result = await execCommandCapture(python.pythonBin, [python.scriptPath, inputPath, outputPath], {
    timeout: positiveInt(input.timeout_ms, 30000),
    maxBuffer: 1024 * 1024 * 4,
  });
  writeText(logPath, workerActionTranscriptLog(result));
  const output = readJsonIfExists(outputPath);
  if (output && typeof output === 'object') {
    appendWorkerActionJsonLog(logPath, 'worker_output', output);
  }
  if (result.exit_code !== 0) {
    return workerActionFailure({
      runtime: 'sdk-python',
      action: input.action,
      logPath,
      result,
      output,
      runnerBlocked: input.action === 'poll_complete' && workerActionReachedProductBoundary(output) ? false : true,
    });
  }

  if (!output || typeof output !== 'object') {
    return workerActionFailure({
      runtime: 'sdk-python',
      action: input.action,
      logPath,
      result,
      runnerBlocked: true,
      message: `published Python schedules worker action ${input.action} did not write JSON output`,
    });
  }

  if (output.ok === false) {
    return workerActionFailure({
      runtime: 'sdk-python',
      action: input.action,
      logPath,
      result,
      output,
      runnerBlocked: false,
    });
  }

  return {
    ok: true,
    runtime: 'sdk-python',
    action: input.action,
    output,
    log_path: path.basename(logPath),
    transcript: result,
  };
}

async function runSchedulesPhpWorker(php, input) {
  const action = await runSchedulesPhpWorkerCapture(php, input);
  if (!action.ok) {
    throw new Error(action.error);
  }

  return action.output;
}

async function runSchedulesPhpWorkerCapture(php, input) {
  const { inputPath, outputPath } = writeSchedulesWorkerInput(php.phpRoot, input);
  const containerInput = `/app/${path.relative(php.phpRoot, inputPath)}`;
  const containerOutput = `/app/${path.relative(php.phpRoot, outputPath)}`;
  const logPath = path.join(resultDir, `schedules-cross-language-php-${safeLogName(input.worker_id ?? input.action)}-${input.action}.log`);
  const result = await execCommandCapture('docker', [
    'run',
    '--rm',
    '--network',
    'host',
    '-v',
    `${php.phpRoot}:/app`,
    '-w',
    '/app',
    '--entrypoint',
    'php',
    'composer:2',
    '/app/schedules_worker.php',
    containerInput,
    containerOutput,
  ], {
    timeout: positiveInt(input.timeout_ms, 30000),
    maxBuffer: 1024 * 1024 * 4,
  });
  writeText(logPath, workerActionTranscriptLog(result));
  const output = readJsonIfExists(outputPath);
  if (output && typeof output === 'object') {
    appendWorkerActionJsonLog(logPath, 'worker_output', output);
  }
  if (result.exit_code !== 0) {
    return workerActionFailure({
      runtime: 'sdk-php',
      action: input.action,
      logPath,
      result,
      output,
      runnerBlocked: true,
    });
  }

  if (!output || typeof output !== 'object') {
    return workerActionFailure({
      runtime: 'sdk-php',
      action: input.action,
      logPath,
      result,
      runnerBlocked: true,
      message: `published PHP schedules worker action ${input.action} did not write JSON output`,
    });
  }

  if (output.ok === false) {
    return workerActionFailure({
      runtime: 'sdk-php',
      action: input.action,
      logPath,
      result,
      output,
      runnerBlocked: false,
    });
  }

  return {
    ok: true,
    runtime: 'sdk-php',
    action: input.action,
    output,
    log_path: path.basename(logPath),
    transcript: result,
  };
}

function workerActionFailure({
  runtime,
  action,
  logPath,
  result,
  output = null,
  runnerBlocked,
  message = '',
}) {
  const outputError = workerOutputErrorMessage(output);
  const transcriptError = compactLogText(`${result.stderr ?? ''} ${result.stdout ?? ''}`, 500);
  const reason = message
    || outputError
    || `published ${runtime} schedules worker action ${action} failed`;
  const diagnostics = workerActionDiagnostics({ logPath, result, output });
  const diagnosticSummary = workerActionDiagnosticSummary(diagnostics);
  const error = diagnosticSummary === ''
    ? `${reason}; see ${path.basename(logPath)}`
    : `${reason}; see ${path.basename(logPath)}; diagnostics: ${diagnosticSummary}`;

  return {
    ok: false,
    runtime,
    action,
    error,
    diagnostic_summary: diagnosticSummary,
    runner_blocked: runnerBlocked === true,
    log_path: path.basename(logPath),
    output,
    diagnostics,
    transcript: {
      exit_code: result.exit_code,
      signal: result.signal ?? '',
      timed_out: result.timed_out === true,
      stderr_tail: transcriptError,
    },
  };
}

function workerActionDiagnosticSummary(diagnostics) {
  if (!diagnostics || typeof diagnostics !== 'object') {
    return '';
  }

  const parts = [];
  const logPath = stringValue(diagnostics.log_path);
  if (logPath !== '') {
    parts.push(`log=${logPath}`);
  }

  const outputError = workerOutputErrorMessage(diagnostics.output);
  if (outputError !== '') {
    parts.push(`worker_output=${compactLogText(outputError, 260)}`);
  }

  const workerStage = stringValue(diagnostics.output?.diagnostic_stage ?? diagnostics.output?.diagnosticStage);
  if (workerStage !== '') {
    parts.push(`worker_stage=${workerStage}`);
  }
  if (workerActionReachedProductBoundary(diagnostics.output)) {
    parts.push('product_boundary_reached=true');
  }

  const exitCode = diagnostics.exit_code;
  if (exitCode !== null && exitCode !== undefined && String(exitCode) !== '') {
    parts.push(`exit_code=${String(exitCode)}`);
  }

  if (diagnostics.timed_out === true) {
    parts.push('timed_out=true');
  }

  const stderrTail = stringValue(diagnostics.transcript?.stderr_tail);
  const stdoutTail = stringValue(diagnostics.transcript?.stdout_tail);
  const transcriptTail = stderrTail !== '' && stderrTail !== '<empty response body>'
    ? stderrTail
    : (stdoutTail !== '' && stdoutTail !== '<empty response body>' ? stdoutTail : '');
  if (transcriptTail !== '') {
    parts.push(`transcript_tail=${compactLogText(transcriptTail, 320)}`);
  }

  const logTail = stringValue(diagnostics.log_tail);
  if (logTail !== '' && logTail !== '<empty response body>' && logTail !== transcriptTail) {
    parts.push(`log_tail=${compactLogText(logTail, 500)}`);
  }

  return parts.join('; ');
}

function workerActionReachedProductBoundary(output) {
  if (!output || typeof output !== 'object') {
    return false;
  }

  if (output.product_boundary_reached === true || output.worker_protocol_boundary_reached === true) {
    return true;
  }

  const stage = stringValue(output.diagnostic_stage ?? output.diagnosticStage);
  return stage.startsWith('worker_protocol_');
}

function workerActionTranscriptLog(result) {
  const parts = [];
  const stdout = stringValue(result.stdout);
  const stderr = stringValue(result.stderr);
  if (stdout !== '') {
    parts.push('--- stdout ---', stdout.trimEnd());
  }
  if (stderr !== '') {
    parts.push('--- stderr ---', stderr.trimEnd());
  }
  if (parts.length === 0) {
    parts.push('--- process output ---', '<empty>');
  }
  parts.push(`--- exit status ---\nexit_code=${result.exit_code} signal=${stringValue(result.signal)} timed_out=${result.timed_out === true}`);

  return `${parts.join('\n')}\n`;
}

function appendWorkerActionJsonLog(logPath, label, value) {
  fs.appendFileSync(logPath, `--- ${label} ---\n${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function workerActionDiagnostics({ logPath, result, output = null }) {
  return {
    schema: 'durable-workflow.v2.schedules-runtime.worker-action-diagnostics',
    log_path: path.basename(logPath),
    log_tail: tailLogSnippet(logPath, 2000),
    exit_code: result.exit_code,
    signal: result.signal ?? '',
    timed_out: result.timed_out === true,
    output,
    transcript: {
      stdout_tail: compactLogText(result.stdout ?? '', 700),
      stderr_tail: compactLogText(result.stderr ?? '', 700),
    },
  };
}

function workerOutputErrorMessage(output) {
  if (!output || typeof output !== 'object') {
    return '';
  }

  const type = stringValue(output.error_type ?? output.errorType);
  const message = stringValue(output.error_message ?? output.errorMessage ?? output.message);
  if (type !== '' && message !== '') {
    return `${type}: ${message}`;
  }

  return message || type;
}

function writeSchedulesWorkerInput(root, input) {
  const inputRoot = path.join(root, 'inputs');
  const outputRoot = path.join(root, 'outputs');
  fs.mkdirSync(inputRoot, { recursive: true });
  fs.mkdirSync(outputRoot, { recursive: true });
  const basename = `${safeLogName(input.worker_id ?? input.schedule_id ?? input.action)}-${input.action}-${Date.now().toString(36)}.json`;
  const inputPath = path.join(inputRoot, basename);
  const outputPath = path.join(outputRoot, basename);
  writeJson(inputPath, {
    interval: 'PT30S',
    supported_activity_types: [],
    complete_result: {},
    ...input,
  });

  return { inputPath, outputPath };
}

async function waitForCrossLanguageCompletions({
  serverUrl,
  token,
  namespace,
  taskQueue,
  timeoutSeconds,
  python,
  php,
  phpWorkerId,
  pythonWorkerId,
  phpWorkflowType,
  pythonWorkflowType,
  pythonCreatedPhpScheduleId,
  phpCreatedPythonScheduleId,
  focusedScenarios,
}) {
  const deadline = Date.now() + timeoutSeconds * 1000;
  const pollPhpCell = focusedScenarios.has('python_created_php_workflow');
  const pollPythonCell = focusedScenarios.has('php_created_python_workflow');
  let phpCompletion = null;
  let pythonCompletion = null;
  let phpAttempts = 0;
  let pythonAttempts = 0;
  let lastPhpPoll = null;
  let lastPythonPoll = null;
  let phpTerminalFailure = false;
  let pythonTerminalFailure = false;

  while (
    Date.now() < deadline
    && (
      (pollPhpCell && !phpCompletion?.workflow_completed && !phpTerminalFailure)
      || (pollPythonCell && !pythonCompletion?.workflow_completed && !pythonTerminalFailure)
    )
  ) {
    if (pollPhpCell && !phpCompletion?.workflow_completed && !phpTerminalFailure) {
      phpAttempts += 1;
      const phpPollAction = await runSchedulesPhpWorkerCapture(php, {
        action: 'poll_complete',
        server_url: serverUrl,
        token,
        namespace,
        task_queue: taskQueue,
        worker_id: phpWorkerId,
        workflow_type: phpWorkflowType,
        runtime: 'sdk-php',
        schedule_id: pythonCreatedPhpScheduleId,
        timeout_ms: 15000,
        complete_result: {
          scenario: 'python_created_php_workflow',
          schedule_creator: 'sdk-python',
          workflow_runtime: 'sdk-php',
        },
      });
      lastPhpPoll = phpPollAction.output ?? workerPollFailureOutput(phpPollAction);
      phpCompletion = phpPollAction.ok
        ? await workflowCompletionFromPoll({
            poll: lastPhpPoll,
            serverUrl,
            token,
            namespace,
            scheduleId: pythonCreatedPhpScheduleId,
            scheduleCreator: 'sdk-python',
            workflowRuntime: 'sdk-php',
            scenario: 'python_created_php_workflow',
            workerId: phpWorkerId,
            attempts: phpAttempts,
          })
        : workerPollFailureCompletion({
            pollAction: phpPollAction,
            scheduleId: pythonCreatedPhpScheduleId,
            scheduleCreator: 'sdk-python',
            workflowRuntime: 'sdk-php',
            scenario: 'python_created_php_workflow',
            workerId: phpWorkerId,
            attempts: phpAttempts,
          });
      phpTerminalFailure = !phpPollAction.ok;
    }

    if (pollPythonCell && !pythonCompletion?.workflow_completed && !pythonTerminalFailure) {
      pythonAttempts += 1;
      const pythonPollAction = await runSchedulesPythonWorkerCapture(python, {
        action: 'poll_complete',
        server_url: serverUrl,
        token,
        namespace,
        task_queue: taskQueue,
        worker_id: pythonWorkerId,
        workflow_type: pythonWorkflowType,
        runtime: 'sdk-python',
        schedule_id: phpCreatedPythonScheduleId,
        timeout_ms: 15000,
        complete_result: {
          scenario: 'php_created_python_workflow',
          schedule_creator: 'sdk-php',
          workflow_runtime: 'sdk-python',
        },
      });
      lastPythonPoll = pythonPollAction.output ?? workerPollFailureOutput(pythonPollAction);
      pythonCompletion = pythonPollAction.ok
        ? await workflowCompletionFromPoll({
            poll: lastPythonPoll,
            serverUrl,
            token,
            namespace,
            scheduleId: phpCreatedPythonScheduleId,
            scheduleCreator: 'sdk-php',
            workflowRuntime: 'sdk-python',
            scenario: 'php_created_python_workflow',
            workerId: pythonWorkerId,
            attempts: pythonAttempts,
          })
        : workerPollFailureCompletion({
            pollAction: pythonPollAction,
            scheduleId: phpCreatedPythonScheduleId,
            scheduleCreator: 'sdk-php',
            workflowRuntime: 'sdk-python',
            scenario: 'php_created_python_workflow',
            workerId: pythonWorkerId,
            attempts: pythonAttempts,
          });
      pythonTerminalFailure = !pythonPollAction.ok;
    }

    if ((!pollPhpCell || phpCompletion?.workflow_completed) && (!pollPythonCell || pythonCompletion?.workflow_completed)) {
      break;
    }

    await sleep(1500);
  }

  const phpResult = pollPhpCell
    ? (phpCompletion ?? missingCrossLanguageCompletion({
        scenario: 'python_created_php_workflow',
        scheduleId: pythonCreatedPhpScheduleId,
        scheduleCreator: 'sdk-python',
        workflowRuntime: 'sdk-php',
        workerId: phpWorkerId,
        attempts: phpAttempts,
        lastPoll: lastPhpPoll,
      }))
    : null;
  const pythonResult = pollPythonCell
    ? (pythonCompletion ?? missingCrossLanguageCompletion({
        scenario: 'php_created_python_workflow',
        scheduleId: phpCreatedPythonScheduleId,
        scheduleCreator: 'sdk-php',
        workflowRuntime: 'sdk-python',
        workerId: pythonWorkerId,
        attempts: pythonAttempts,
        lastPoll: lastPythonPoll,
      }))
    : null;

  if (phpResult !== null) {
    await attachCrossLanguageDiagnostics(phpResult, {
      serverUrl,
      token,
      namespace,
      scheduleId: pythonCreatedPhpScheduleId,
      taskQueue,
    });
  }
  if (pythonResult !== null) {
    await attachCrossLanguageDiagnostics(pythonResult, {
      serverUrl,
      token,
      namespace,
      scheduleId: phpCreatedPythonScheduleId,
      taskQueue,
    });
  }

  return {
    php: phpResult,
    python: pythonResult,
  };
}

function workerPollFailureOutput(pollAction) {
  return {
    ok: false,
    action: pollAction.action,
    runtime: pollAction.runtime,
    error_message: pollAction.error,
    runner_blocked: pollAction.runner_blocked === true,
    log_path: pollAction.log_path,
    diagnostic_summary: pollAction.diagnostic_summary,
    diagnostics: pollAction.diagnostics,
    transcript: pollAction.transcript,
    output: pollAction.output,
  };
}

function workerPollFailureCompletion({
  pollAction,
  scheduleId,
  scheduleCreator,
  workflowRuntime,
  scenario,
  workerId,
  attempts,
}) {
  return {
    scenario,
    schedule_id: scheduleId,
    schedule_creator: scheduleCreator,
    workflow_runtime: workflowRuntime,
    worker_id: workerId,
    schedule_visible_in_cli: false,
    workflow_completed: false,
    workflow_id: '',
    run_id: '',
    scheduled_fire_observed: false,
    poll_attempts: attempts,
    worker_poll: workerPollFailureOutput(pollAction),
    worker_poll_ok: false,
    worker_poll_diagnostics: pollAction.diagnostics,
    worker_poll_error: {
      runtime: pollAction.runtime,
      action: pollAction.action,
      message: pollAction.error,
      runner_blocked: pollAction.runner_blocked === true,
      log_path: pollAction.log_path,
      diagnostic_summary: pollAction.diagnostic_summary,
      diagnostics: pollAction.diagnostics,
      transcript: pollAction.transcript,
      output: pollAction.output,
    },
    runner_blocked: pollAction.runner_blocked === true,
    workflow_run: {},
  };
}

async function workflowCompletionFromPoll({
  poll,
  serverUrl,
  token,
  namespace,
  scheduleId,
  scheduleCreator,
  workflowRuntime,
  scenario,
  workerId,
  attempts,
}) {
  const task = poll?.task && typeof poll.task === 'object' ? poll.task : null;
  const workflowId = stringValue(task?.workflow_id ?? task?.workflowId);
  const runId = stringValue(task?.run_id ?? task?.runId);
  let workflowRun = {};

  if (workflowId !== '' && runId !== '') {
    for (let attempt = 0; attempt < 10; attempt += 1) {
      workflowRun = await apiRequest(serverUrl, token, namespace, 'GET', `/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`).catch((error) => ({
        error: error instanceof Error ? error.message : String(error),
      }));
      if (workflowStatusIsCompleted(workflowRun)) {
        break;
      }
      await sleep(500);
    }
  }

  return {
    scenario,
    schedule_id: scheduleId,
    schedule_creator: scheduleCreator,
    workflow_runtime: workflowRuntime,
    worker_id: workerId,
    schedule_visible_in_cli: false,
    workflow_completed: workflowStatusIsCompleted(workflowRun),
    workflow_id: workflowId,
    run_id: runId,
    scheduled_fire_observed: task !== null,
    poll_attempts: attempts,
    worker_poll: poll ?? null,
    worker_poll_ok: !(poll?.ok === false),
    workflow_run: workflowRun,
  };
}

function missingCrossLanguageCompletion({
  scenario,
  scheduleId,
  scheduleCreator,
  workflowRuntime,
  workerId,
  attempts,
  lastPoll,
}) {
  return {
    scenario,
    schedule_id: scheduleId,
    schedule_creator: scheduleCreator,
    workflow_runtime: workflowRuntime,
    worker_id: workerId,
    schedule_visible_in_cli: false,
    workflow_completed: false,
    workflow_id: '',
    run_id: '',
    scheduled_fire_observed: false,
    poll_attempts: attempts,
    worker_poll: lastPoll ?? null,
    worker_poll_ok: !(lastPoll?.ok === false),
    workflow_run: {},
  };
}

async function attachCrossLanguageDiagnostics(completion, { serverUrl, token, namespace, scheduleId, taskQueue }) {
  if (completion.workflow_completed === true) {
    return completion;
  }

  const workerId = stringValue(completion.worker_id);
  const encodedTaskQueue = encodeURIComponent(taskQueue);
  const [description, history, taskQueueDetail, workers, worker] = await Promise.all([
    describeScheduleResult(serverUrl, token, namespace, scheduleId).catch((error) => ({
      ok: false,
      error: error instanceof Error ? error.message : String(error),
    })),
    scheduleHistoryResult(serverUrl, token, namespace, scheduleId).catch((error) => ({
      ok: false,
      error: error instanceof Error ? error.message : String(error),
    })),
    safeApiRequestResult(serverUrl, token, namespace, 'GET', `/task-queues/${encodedTaskQueue}`),
    safeApiRequestResult(serverUrl, token, namespace, 'GET', `/workers?task_queue=${encodedTaskQueue}`),
    workerId === ''
      ? Promise.resolve({ ok: false, status: 0, parsed: { reason: 'worker_id_missing' }, text: '' })
      : safeApiRequestResult(serverUrl, token, namespace, 'GET', `/workers/${encodeURIComponent(workerId)}`),
  ]);
  const events = arrayValue(history?.parsed?.events ?? history?.events);
  const triggeredEvents = scheduleTriggeredEvents(events).map(normalizeScheduleEvent);
  const latestTrigger = triggeredEvents[triggeredEvents.length - 1] ?? null;
  if (stringValue(completion.workflow_id) === '' && latestTrigger !== null) {
    completion.workflow_id = stringValue(latestTrigger.workflow_instance_id);
  }
  if (stringValue(completion.run_id) === '' && latestTrigger !== null) {
    completion.run_id = stringValue(latestTrigger.workflow_run_id);
  }
  const workflowId = stringValue(completion.workflow_id);
  const runId = stringValue(completion.run_id);
  const workflowRun = workflowId !== '' && runId !== ''
    ? await safeApiRequestResult(
        serverUrl,
        token,
        namespace,
        'GET',
        `/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`,
      )
    : { ok: false, status: 0, parsed: { reason: 'workflow_run_identity_missing' }, text: '' };
  if (!completion.workflow_run || Object.keys(objectValue(completion.workflow_run)).length === 0) {
    completion.workflow_run = workflowRun.parsed ?? {};
  }

  completion.schedule_diagnostics = {
    describe: description,
    history,
    triggered_event_count: triggeredEvents.length,
    triggered_events: triggeredEvents,
  };
  completion.worker_diagnostics = {
    worker_id: workerId,
    list: publicResponseSnapshot(workers),
    detail: publicResponseSnapshot(worker),
  };
  completion.task_queue_diagnostics = {
    task_queue: taskQueue,
    detail: publicResponseSnapshot(taskQueueDetail),
  };
  completion.workflow_diagnostics = {
    workflow_id: workflowId,
    run_id: runId,
    run: publicResponseSnapshot(workflowRun),
  };

  return completion;
}

function workflowStatusIsCompleted(value) {
  const status = stringValue(value?.run_status ?? value?.status ?? value?.status_bucket ?? value?.statusBucket);
  return status === 'completed';
}

function crossLanguageEvidenceFromObservations({
  startedAt,
  finishedAt,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  runId,
  cliPath,
  cliList,
  pythonCreate,
  phpCreate,
  phpRegistration,
  pythonRegistration,
  phpCompletion,
  pythonCompletion,
  schedules,
  scheduleMaxRuns,
  workers,
  focusedScenarios,
}) {
  const cliSchedules = cliList?.parsed_json?.schedules ?? [];
  const pythonCreatedVisible = scheduleListContains(cliList?.parsed_json, schedules.pythonCreatedPhp);
  const phpCreatedVisible = scheduleListContains(cliList?.parsed_json, schedules.phpCreatedPython);
  const cells = [];
  if (focusedScenarios.has('python_created_php_workflow') && phpCompletion !== null) {
    const pythonCreatedPhp = {
      ...phpCompletion,
      schedule_visible_in_cli: pythonCreatedVisible,
      cli_list_command: cliList,
      schedule_create_response: pythonCreate,
      worker_registration: phpRegistration,
      artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
    };
    pythonCreatedPhp.failure_class = crossLanguageFailureClass(pythonCreatedPhp);
    pythonCreatedPhp.failure_reason = crossLanguageFailureReason(pythonCreatedPhp);
    cells.push(pythonCreatedPhp);
  }
  if (focusedScenarios.has('php_created_python_workflow') && pythonCompletion !== null) {
    const phpCreatedPython = {
      ...pythonCompletion,
      schedule_visible_in_cli: phpCreatedVisible,
      cli_list_command: cliList,
      schedule_create_response: phpCreate,
      worker_registration: pythonRegistration,
      artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
    };
    phpCreatedPython.failure_class = crossLanguageFailureClass(phpCreatedPython);
    phpCreatedPython.failure_reason = crossLanguageFailureReason(phpCreatedPython);
    cells.push(phpCreatedPython);
  }
  const scenarioResults = {};
  const findings = new Map();
  const focusedCellList = crossLanguageCellDescriptors(Array.from(focusedScenarios));

  for (const cell of cells) {
    const status = cell.runner_blocked === true
      ? 'runner_blocked'
      : (cell.schedule_visible_in_cli && cell.workflow_completed ? 'pass' : 'fail');
    const linkedFindings = status === 'pass'
      ? []
      : [
          status === 'runner_blocked'
            ? crossLanguageRunnerBlockedFinding(cell, artifactVersions)
            : crossLanguageFinding(cell, artifactVersions),
        ];
    for (const finding of linkedFindings) {
      findings.set(stringValue(finding.finding_id) || `${cell.scenario}-${findings.size + 1}`, finding);
    }
    scenarioResults[cell.scenario] = {
      scenario_id: cell.scenario,
      status,
      observed_outputs: cell,
      linked_findings: linkedFindings,
    };
  }

  return {
    schema: 'durable-workflow.v2.schedules-runtime.cross-language-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    scenario_results: scenarioResults,
    findings: Array.from(findings.values()),
    topology: {
      namespace,
      task_queue: taskQueue,
      schedule_max_runs: scheduleMaxRuns,
      run_id: runId,
      focused_scenarios: Array.from(focusedScenarios),
      worker_execution_mode: 'published_php_python_worker_protocol_clients',
      worker_ids: workers,
      schedules_created: [
        focusedScenarios.has('python_created_php_workflow') ? schedules.pythonCreatedPhp : null,
        focusedScenarios.has('php_created_python_workflow') ? schedules.phpCreatedPython : null,
      ].filter(Boolean),
      cli_executable: cliPath,
    },
    runtime_matrix: {
      runtimes: ['sdk-php', 'sdk-python'],
      client_paths: ['cli', 'sdk-python', 'sdk-php'],
      schedule_types: ['fixed_rate_interval'],
      cross_language_cells: focusedCellList,
    },
    cross_language_matrix: {
      cross_language_cells: cells,
    },
    client_surfaces: {
      cli: {
        list_observed: cells.every((cell) => cell.schedule_visible_in_cli === true),
        command_outputs: {
          list: cliList,
        },
        observed_schedule_ids: Array.isArray(cliSchedules)
          ? cliSchedules.map((schedule) => scheduleIdField(schedule)).filter(Boolean)
          : [],
      },
      ...(focusedScenarios.has('python_created_php_workflow') ? {
        'sdk-python': {
          create_or_observe: stringValue(pythonCreate?.schedule_id) === schedules.pythonCreatedPhp,
          list_observed: pythonCreatedVisible,
          control_observed: true,
        },
      } : {}),
      ...(focusedScenarios.has('php_created_python_workflow') ? {
        'sdk-php': {
          create_or_observe: stringValue(phpCreate?.schedule_id) === schedules.phpCreatedPython,
          list_observed: phpCreatedVisible,
          control_observed: true,
        },
      } : {}),
    },
  };
}

function crossLanguageCellDescriptors(scenarios) {
  const descriptors = {
    python_created_php_workflow: {
      scenario: 'python_created_php_workflow',
      schedule_creator: 'sdk-python',
      workflow_runtime: 'sdk-php',
    },
    php_created_python_workflow: {
      scenario: 'php_created_python_workflow',
      schedule_creator: 'sdk-php',
      workflow_runtime: 'sdk-python',
    },
  };

  return scenarios.map((scenario) => descriptors[scenario]).filter(Boolean);
}

async function maybeRunPhpSurfaceShard(startedAt, artifactVersions, artifactSources) {
  const mode = stringValue(process.env.DW_SCHEDULES_RUN_PHP_SURFACE_SHARD).toLowerCase();
  if (!['1', 'true', 'yes', 'auto'].includes(mode)) {
    return null;
  }

  if (readJsonIfExists(phpSurfaceEvidencePath) !== null) {
    return null;
  }

  const explicit = mode !== 'auto';
  const serverUrl = stringValue(process.env.DW_SCHEDULES_SERVER_URL);
  const dockerAvailable = await commandSucceeds('docker', ['--version']);
  const composeAvailable = dockerAvailable && await commandSucceeds('docker', ['compose', 'version']);
  const serverImage = resolveServerImage(artifactVersions);
  const sdkPhpVersion = artifactValue(artifactVersions, 'sdk-php');
  const configuredCli = stringValue(process.env.DW_SCHEDULES_CLI_EXECUTABLE ?? process.env.DW_CLI_EXECUTABLE);
  const cliVersion = artifactValue(artifactVersions, 'cli');
  const missing = [];

  if (!dockerAvailable) {
    missing.push('docker');
  }
  if (sdkPhpVersion === '') {
    missing.push('DW_PHP_SDK_VERSION');
  }
  if (configuredCli === '' && cliVersion === '') {
    missing.push('DW_CLI_VERSION or DW_SCHEDULES_CLI_EXECUTABLE');
  }
  if (serverUrl === '' && (!dockerAvailable || !composeAvailable || serverImage === '')) {
    if (dockerAvailable && !composeAvailable) {
      missing.push('docker compose');
    }
    if (serverImage === '') {
      missing.push('DW_SERVER_VERSION or DW_SERVER_IMAGE');
    }
  }

  if (missing.length > 0) {
    if (!explicit) {
      return null;
    }

    return phpSurfaceBlockedEvidence(
      `PHP schedule surface shard prerequisites are missing: ${missing.join(', ')}.`,
      startedAt,
      artifactVersions,
      artifactSources,
    );
  }

  try {
    return await runPhpSurfaceShard({
      startedAt,
      artifactVersions,
      artifactSources,
      serverImage,
      existingServerUrl: serverUrl,
    });
  } catch (error) {
    const reason = failureReasonWithShardLogs(error, 'schedules-php-surface');
    return phpSurfaceBlockedEvidence(reason, startedAt, artifactVersions, artifactSources);
  }
}

async function runPhpSurfaceShard({ startedAt, artifactVersions, artifactSources, serverImage, existingServerUrl }) {
  const phpStartedAt = timestamp();
  const runId = `schedules-php-surface-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
  const namespace = sanitizeDockerName(`${stringValue(process.env.DW_SCHEDULES_NAMESPACE) || 'schedules-conformance'}-${runId}`).slice(0, 96);
  const taskQueue = stringValue(process.env.DW_SCHEDULES_PHP_SURFACE_TASK_QUEUE)
    || `schedules-php-surface-${runId}`;
  const workflowType = stringValue(process.env.DW_SCHEDULES_PHP_SURFACE_WORKFLOW_TYPE)
    || 'SchedulesConformancePhpSurfaceWorkflow';
  const scheduleId = stringValue(process.env.DW_SCHEDULES_PHP_SURFACE_SCHEDULE_ID)
    || `${runId}-sdk-php`;
  const cronExpression = stringValue(process.env.DW_SCHEDULES_PHP_SURFACE_CRON) || '*/5 * * * *';
  const token = stringValue(process.env.DW_SCHEDULES_AUTH_TOKEN) || 'dev-token';
  const readinessTimeoutSeconds = positiveInt(process.env.DW_SCHEDULES_SERVER_READY_TIMEOUT_SECONDS, 120);
  const schedulerTickSeconds = positiveInt(process.env.DW_SCHEDULES_PHP_SURFACE_SCHEDULER_TICK_SECONDS, 2);
  const serverPort = positiveInt(process.env.DW_SCHEDULES_SERVER_PORT, 0) || await freePort();
  let serverUrl = existingServerUrl || `http://127.0.0.1:${serverPort}`;
  const composeProject = sanitizeDockerName(runId);
  const overlayPath = path.join(resultDir, 'schedules-php-surface-compose.override.yml');
  const composeFiles = [
    '-f',
    path.join(repoRoot, 'docker-compose.published.yml'),
    '-f',
    overlayPath,
  ];
  const shardRoot = path.join(resultDir, 'schedules-php-surface-shard');
  let composeStarted = false;
  let cliPath = '';

  fs.rmSync(shardRoot, { recursive: true, force: true });
  fs.mkdirSync(shardRoot, { recursive: true });
  markArtifactSource(artifactSources, 'server', existingServerUrl === '' ? 'published_docker_image' : 'existing_published_server_url', artifactVersions);

  if (existingServerUrl === '') {
    writeSchedulerOverlay(overlayPath, schedulerTickSeconds);
    await execLogged(
      'docker',
      ['image', 'pull', serverImage],
      path.join(resultDir, 'schedules-php-surface-docker-pull.log'),
    );
    composeStarted = true;
    await startPublishedComposeServices({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
      logPrefix: 'schedules-php-surface',
    });
  }

  try {
    serverUrl = await waitForReachableServerUrl({
      preferredUrl: serverUrl,
      timeoutSeconds: readinessTimeoutSeconds,
      composeProject: composeStarted ? composeProject : '',
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    });
    await ensureNamespace(serverUrl, token, namespace);

    cliPath = await resolvePublishedCli(artifactVersions, artifactSources);
    const php = await installSchedulesPhpArtifact(
      shardRoot,
      artifactVersions,
      artifactSources,
      'schedules-php-surface',
    );
    writeText(path.join(php.phpRoot, 'schedules_php_surface.php'), schedulesPhpSurfaceProbeScript());

    const phpReport = await runSchedulesPhpSurfaceProbe(php, {
      action: 'create_observe_controls',
      server_url: serverUrl,
      token,
      namespace,
      task_queue: taskQueue,
      schedule_id: scheduleId,
      workflow_type: workflowType,
      cron: cronExpression,
      run_id: runId,
      timeout_ms: 60000,
    });
    const httpList = await safeApiRequestResult(serverUrl, token, namespace, 'GET', '/schedules');
    const httpDescribe = await safeApiRequestResult(serverUrl, token, namespace, 'GET', `/schedules/${encodeURIComponent(scheduleId)}`);
    const cliContext = { serverUrl, namespace, token };
    const cliList = await runDwJson(cliPath, ['schedules', 'list', '--json'], cliContext);
    const cliDescribe = await runDwJson(cliPath, ['schedules', 'describe', scheduleId, '--json'], cliContext);
    const phpDelete = await runSchedulesPhpSurfaceProbe(php, {
      action: 'delete_schedule',
      server_url: serverUrl,
      token,
      namespace,
      schedule_id: scheduleId,
      timeout_ms: 60000,
    });

    const evidence = phpSurfaceEvidenceFromObservations({
      startedAt: phpStartedAt,
      finishedAt: timestamp(),
      artifactVersions,
      artifactSources,
      namespace,
      taskQueue,
      scheduleId,
      workflowType,
      cronExpression,
      runId,
      cliPath,
      phpReport,
      phpDelete,
      httpList,
      httpDescribe,
      cliList,
      cliDescribe,
    });
    writeJson(phpSurfaceEvidencePath, evidence);

    return evidence;
  } finally {
    await bestEffortDeleteSchedule(serverUrl, token, namespace, scheduleId);

    writeJson(path.join(resultDir, 'schedules-php-surface-run-metadata.json'), {
      schema: 'durable-workflow.v2.schedules-runtime.php-surface-run-metadata',
      started_at: startedAt,
      php_surface_started_at: phpStartedAt,
      finished_at: timestamp(),
      server_url: serverUrl,
      namespace,
      task_queue: taskQueue,
      schedule_id: scheduleId,
      workflow_type: workflowType,
      server_image: existingServerUrl === '' ? serverImage : null,
      compose_project: existingServerUrl === '' ? composeProject : null,
      cli_executable: cliPath || null,
      published_artifact_versions: artifactVersions,
      artifact_sources: artifactSources,
      local_product_source_checkouts_used: false,
    });

    if (composeStarted) {
      await collectPhpSurfaceComposeLogs(composeProject, composeFiles);
      await removePublishedComposeProject(
        composeProject,
        composeFiles,
        composeEnv(serverPort, serverImage, token, artifactVersions),
      );
    }
  }
}

async function runSchedulesPhpSurfaceProbe(php, input) {
  const { inputPath, outputPath } = writeSchedulesWorkerInput(php.phpRoot, input);
  const containerInput = `/app/${path.relative(php.phpRoot, inputPath)}`;
  const containerOutput = `/app/${path.relative(php.phpRoot, outputPath)}`;
  const logPath = path.join(resultDir, `schedules-php-surface-${safeLogName(input.schedule_id ?? input.action)}-${input.action}.log`);
  const result = await execCommandCapture('docker', [
    'run',
    '--rm',
    '--network',
    'host',
    '-v',
    `${php.phpRoot}:/app`,
    '-w',
    '/app',
    '--entrypoint',
    'php',
    'composer:2',
    '/app/schedules_php_surface.php',
    containerInput,
    containerOutput,
  ], {
    timeout: positiveInt(input.timeout_ms, 60000),
    maxBuffer: 1024 * 1024 * 4,
  });
  writeText(logPath, `${result.stdout}${result.stderr}`);
  if (result.exit_code !== 0) {
    throw new Error(`published PHP schedule surface action ${input.action} failed; see ${path.basename(logPath)}`);
  }

  const output = readJsonIfExists(outputPath);
  if (!output || typeof output !== 'object') {
    throw new Error(`published PHP schedule surface action ${input.action} did not write JSON output`);
  }

  return output;
}

function phpSurfaceEvidenceFromObservations({
  startedAt,
  finishedAt,
  artifactVersions,
  artifactSources,
  namespace,
  taskQueue,
  scheduleId,
  workflowType,
  cronExpression,
  runId,
  cliPath,
  phpReport,
  phpDelete,
  httpList,
  httpDescribe,
  cliList,
  cliDescribe,
}) {
  const checks = phpSurfaceChecks({
    scheduleId,
    cronExpression,
    phpReport,
    phpDelete,
    httpList,
    httpDescribe,
    cliList,
    cliDescribe,
  });
  const status = checks.unsupported_controls.length > 0 || checks.unsupported_required_operations.length > 0
    ? 'unsupported'
    : (checks.failures.length === 0 ? 'pass' : 'fail');
  const finding = status === 'pass'
    ? null
    : phpSurfaceFinding(status, checks, artifactVersions);
  const observedOutputs = {
    schedule_id: scheduleId,
    workflow_type: workflowType,
    cron_expression: cronExpression,
    create_or_observe: checks.create_or_observe,
    list_or_describe: checks.list_or_describe,
    control_observed: checks.control_observed,
    claimed_controls: checks.claimed_controls,
    unsupported_controls: checks.unsupported_controls,
    unsupported_required_operations: checks.unsupported_required_operations,
    control_behavior: checks.control_behavior,
    state_comparison: checks.state_comparison,
    php_report: phpReport,
    php_delete_report: phpDelete,
    server_state: checks.server_state,
    cli_state: checks.cli_state,
    failures: checks.failures,
  };

  return {
    schema: 'durable-workflow.v2.schedules-runtime.php-surface-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    scenario_results: {
      php_schedule_surface: {
        scenario_id: 'php_schedule_surface',
        status,
        observed_outputs: observedOutputs,
        linked_findings: finding === null ? [] : [finding],
      },
    },
    findings: finding === null ? [] : [finding],
    topology: {
      namespace,
      task_queue: taskQueue,
      run_id: runId,
      worker_execution_mode: 'published_sdk_php_control_plane_client',
      schedules_created: [scheduleId],
      cli_executable: cliPath,
    },
    runtime_matrix: {
      runtimes: ['sdk-php'],
      client_paths: ['sdk-php', 'server-http-api', 'cli'],
      schedule_types: ['cron_expression'],
    },
    client_surfaces: {
      'sdk-php': {
        create_or_observe: checks.create_or_observe,
        list_or_describe: checks.list_or_describe,
        list_observed: checks.php_list_observed,
        describe_observed: checks.php_describe_observed,
        control_observed: checks.control_observed,
        claimed_controls: checks.claimed_controls,
        unsupported_controls: checks.unsupported_controls,
        state_compared_with_server: checks.state_compared_with_server,
        state_compared_with_cli: checks.state_compared_with_cli,
        schedule_id: scheduleId,
        command_outputs: {
          create_observe_controls: phpReport,
          delete: phpDelete,
        },
      },
    },
  };
}

function phpSurfaceChecks({
  scheduleId,
  cronExpression,
  phpReport,
  phpDelete,
  httpList,
  httpDescribe,
  cliList,
  cliDescribe,
}) {
  const createOperation = objectValue(phpReport.create_or_observe);
  const listOperation = objectValue(phpReport.list_or_describe?.list);
  const describeOperation = objectValue(phpReport.list_or_describe?.describe);
  const deleteOperation = objectValue(phpDelete.delete);
  const listAfterDeleteOperation = objectValue(phpDelete.list_after_delete);
  const claimedControls = objectValue(phpReport.claimed_controls);
  const unsupportedControls = Object.entries(claimedControls)
    .filter(([, claimed]) => claimed === false)
    .map(([control]) => control);
  const unsupportedRequiredOperations = [
    createOperation.supported === false ? 'createSchedule' : null,
    listOperation.supported === false ? 'listSchedules' : null,
    describeOperation.supported === false ? 'describeSchedule' : null,
  ].filter(Boolean);
  const phpListPayload = objectValue(listOperation.response);
  const phpDescribePayload = objectValue(describeOperation.response);
  const phpListedSchedule = findScheduleRecordInList(phpListPayload, scheduleId);
  const phpDescribedSchedule = scheduleRecordFromPayload(phpDescribePayload);
  const serverListPayload = objectValue(httpList.parsed);
  const serverDescribePayload = objectValue(httpDescribe.parsed);
  const serverListedSchedule = findScheduleRecordInList(serverListPayload, scheduleId);
  const serverDescribedSchedule = scheduleRecordFromPayload(serverDescribePayload);
  const cliListPayload = objectValue(cliList.parsed_json);
  const cliDescribePayload = objectValue(cliDescribe.parsed_json);
  const cliListedSchedule = findScheduleRecordInList(cliListPayload, scheduleId);
  const cliDescribedSchedule = scheduleRecordFromPayload(cliDescribePayload);
  const phpDescribeState = scheduleStateSnapshot(phpDescribedSchedule);
  const phpListState = scheduleStateSnapshot(phpListedSchedule);
  const serverDescribeState = scheduleStateSnapshot(serverDescribedSchedule);
  const serverListState = scheduleStateSnapshot(serverListedSchedule);
  const cliDescribeState = scheduleStateSnapshot(cliDescribedSchedule);
  const cliListState = scheduleStateSnapshot(cliListedSchedule);
  const createOrObserve = operationOk(createOperation)
    && (scheduleIdFromOperation(createOperation) === scheduleId
      || phpDescribeState.schedule_id === scheduleId
      || phpListState.schedule_id === scheduleId);
  const phpListObserved = operationOk(listOperation) && phpListState.schedule_id === scheduleId;
  const phpDescribeObserved = operationOk(describeOperation) && phpDescribeState.schedule_id === scheduleId;
  const listOrDescribe = phpListObserved && phpDescribeObserved;
  const controlBehavior = phpControlBehavior({
    scheduleId,
    phpReport,
    phpDelete,
    deleteOperation,
    listAfterDeleteOperation,
  });
  const stateComparison = phpSurfaceStateComparison({
    scheduleId,
    cronExpression,
    phpDescribeState,
    phpListState,
    serverDescribeState,
    serverListState,
    cliDescribeState,
    cliListState,
  });
  const failures = [];

  if (!createOrObserve) {
    failures.push('PHP-facing create_or_observe did not return or observe the requested schedule id');
  }
  if (!phpListObserved) {
    failures.push('PHP-facing list did not include the requested schedule');
  }
  if (!phpDescribeObserved) {
    failures.push('PHP-facing describe did not include the requested schedule');
  }
  if (!stateComparison.server_observed) {
    failures.push('server HTTP list/describe did not expose the PHP-created schedule');
  }
  if (!stateComparison.cli_observed) {
    failures.push('CLI list/describe did not expose the PHP-created schedule');
  }
  failures.push(...controlBehavior.failures);
  failures.push(...stateComparison.failures);

  return {
    create_or_observe: createOrObserve,
    list_or_describe: listOrDescribe,
    php_list_observed: phpListObserved,
    php_describe_observed: phpDescribeObserved,
    control_observed: controlBehavior.passed,
    claimed_controls: claimedControls,
    unsupported_controls: unsupportedControls,
    unsupported_required_operations: unsupportedRequiredOperations,
    control_behavior: controlBehavior,
    state_comparison: stateComparison,
    state_compared_with_server: stateComparison.server_compared,
    state_compared_with_cli: stateComparison.cli_compared,
    server_state: {
      list: publicResponseSnapshot(httpList),
      describe: publicResponseSnapshot(httpDescribe),
      listed_schedule_state: serverListState,
      described_schedule_state: serverDescribeState,
    },
    cli_state: {
      list: cliList,
      describe: cliDescribe,
      listed_schedule_state: cliListState,
      described_schedule_state: cliDescribeState,
    },
    failures,
  };
}

function phpControlBehavior({
  scheduleId,
  phpReport,
  phpDelete,
  deleteOperation,
  listAfterDeleteOperation,
}) {
  const updateOperation = objectValue(phpReport.control_behavior?.update);
  const pauseOperation = objectValue(phpReport.control_behavior?.pause);
  const resumeOperation = objectValue(phpReport.control_behavior?.resume);
  const triggerOperation = objectValue(phpReport.control_behavior?.trigger);
  const backfillOperation = objectValue(phpReport.control_behavior?.backfill);
  const historyOperation = objectValue(phpReport.control_behavior?.history);
  const pauseState = scheduleStateSnapshot(scheduleRecordFromPayload(objectValue(phpReport.control_behavior?.describe_after_pause?.response)));
  const resumeState = scheduleStateSnapshot(scheduleRecordFromPayload(objectValue(phpReport.control_behavior?.describe_after_resume?.response)));
  const triggerScheduleId = scheduleIdFromOperation(triggerOperation);
  const triggerWorkflowId = firstStringValue(
    triggerOperation.response?.workflow_id,
    triggerOperation.response?.workflowId,
    triggerOperation.response?.workflow_run_id,
    triggerOperation.response?.workflowRunId,
    triggerOperation.response?.run_id,
    triggerOperation.response?.runId,
    triggerOperation.response?.result?.workflow_id,
    triggerOperation.response?.result?.workflowId,
    triggerOperation.response?.result?.workflow_run_id,
    triggerOperation.response?.result?.workflowRunId,
    triggerOperation.response?.result?.run_id,
    triggerOperation.response?.result?.runId,
  );
  const listAfterDelete = objectValue(listAfterDeleteOperation.response);
  const failures = [];

  if (!operationOk(updateOperation)) {
    failures.push('PHP-facing update did not complete through the standalone SDK');
  }
  if (!operationOk(pauseOperation) || pauseState.pause_state !== 'paused') {
    failures.push('PHP-facing pause did not produce paused schedule state');
  }
  if (!operationOk(resumeOperation) || resumeState.pause_state !== 'active') {
    failures.push('PHP-facing resume did not produce active schedule state');
  }
  if (!operationOk(triggerOperation) || (triggerScheduleId !== scheduleId && triggerWorkflowId === '')) {
    failures.push('PHP-facing trigger did not identify the requested schedule or a triggered workflow');
  }
  if (!operationOk(backfillOperation)) {
    failures.push('PHP-facing backfill did not complete through the standalone SDK');
  }
  if (!operationOk(historyOperation)) {
    failures.push('PHP-facing history did not complete through the standalone SDK');
  }
  if (!operationOk(deleteOperation) || scheduleListContains(listAfterDelete, scheduleId)) {
    failures.push('PHP-facing delete did not remove the schedule from PHP list output');
  }

  return {
    passed: failures.length === 0,
    failures,
    update: {
      ok: operationOk(updateOperation),
      operation: updateOperation,
    },
    pause: {
      ok: operationOk(pauseOperation),
      state_after_pause: pauseState,
    },
    resume: {
      ok: operationOk(resumeOperation),
      state_after_resume: resumeState,
    },
    trigger: {
      ok: operationOk(triggerOperation),
      schedule_id: triggerScheduleId,
      workflow_id: triggerWorkflowId,
      identified_requested_schedule_or_workflow: triggerScheduleId === scheduleId || triggerWorkflowId !== '',
    },
    backfill: {
      ok: operationOk(backfillOperation),
      operation: backfillOperation,
    },
    history: {
      ok: operationOk(historyOperation),
      operation: historyOperation,
    },
    delete: {
      ok: operationOk(deleteOperation),
      absent_from_php_list: !scheduleListContains(listAfterDelete, scheduleId),
      list_after_delete: listAfterDeleteOperation,
    },
  };
}

function phpSurfaceStateComparison({
  scheduleId,
  cronExpression,
  phpDescribeState,
  phpListState,
  serverDescribeState,
  serverListState,
  cliDescribeState,
  cliListState,
}) {
  const fields = ['schedule_id', 'cadence', 'pause_state', 'last_fire_at', 'next_fire_at'];
  const comparisons = [];
  const failures = [];
  const phpStates = {
    php_describe: phpDescribeState,
    php_list: phpListState,
  };
  const targetStates = {
    server_describe: serverDescribeState,
    server_list: serverListState,
    cli_describe: cliDescribeState,
    cli_list: cliListState,
  };

  for (const [phpSurface, phpState] of Object.entries(phpStates)) {
    for (const [targetSurface, targetState] of Object.entries(targetStates)) {
      for (const field of fields) {
        const phpValue = stringValue(phpState[field]);
        const targetValue = stringValue(targetState[field]);
        if (phpValue === '' || targetValue === '') {
          continue;
        }

        const matches = phpValue === targetValue;
        comparisons.push({
          php_surface: phpSurface,
          target_surface: targetSurface,
          field,
          php_value: phpValue,
          target_value: targetValue,
          matches,
        });
        if (!matches) {
          failures.push(`${field} differs between ${phpSurface} and ${targetSurface}`);
        }
      }
    }
  }

  for (const [surface, state] of Object.entries({ php_describe: phpDescribeState, php_list: phpListState })) {
    if (state.schedule_id !== scheduleId) {
      failures.push(`${surface} schedule_id did not match requested schedule`);
    }
    if (state.cadence !== cronExpression) {
      failures.push(`${surface} cadence did not match requested cron expression`);
    }
  }

  const serverObserved = serverDescribeState.schedule_id === scheduleId || serverListState.schedule_id === scheduleId;
  const cliObserved = cliDescribeState.schedule_id === scheduleId || cliListState.schedule_id === scheduleId;
  const serverCompared = comparisons.some((comparison) => comparison.target_surface.startsWith('server_'));
  const cliCompared = comparisons.some((comparison) => comparison.target_surface.startsWith('cli_'));

  return {
    fields_compared: fields,
    expected: {
      schedule_id: scheduleId,
      cadence: cronExpression,
    },
    php: {
      describe: phpDescribeState,
      list: phpListState,
    },
    server: {
      describe: serverDescribeState,
      list: serverListState,
    },
    cli: {
      describe: cliDescribeState,
      list: cliListState,
    },
    comparisons,
    server_observed: serverObserved,
    cli_observed: cliObserved,
    server_compared: serverCompared,
    cli_compared: cliCompared,
    failures,
  };
}

function phpSurfaceFinding(status, checks, artifactVersions) {
  const unsupported = [
    ...arrayValue(checks.unsupported_required_operations),
    ...arrayValue(checks.unsupported_controls),
  ];

  if (status === 'unsupported') {
    return {
      finding_id: 'schedules-php-surface-unsupported',
      scenario_id: 'php_schedule_surface',
      finding_type: 'unsupported_public_surface',
      owning_surface: 'sdk-php',
      execution_scope: 'sdk-php-schedule-surface-shard',
      artifact_versions: artifactVersions,
      observed_behavior: `The PHP-facing schedule client surface did not expose required operations: ${unsupported.join(', ')}.`,
      expected_behavior: 'The published standalone PHP SDK exposes create/list/describe and records every claimed update, pause, resume, trigger, backfill, history, and delete behavior.',
      next_acceptance_criterion: 'publish the PHP-facing schedule client operation or update the public contract to mark it unsupported, then rerun the PHP schedule surface shard',
      observed_outputs: checks,
    };
  }

  return {
    finding_id: 'schedules-php-surface-behavior',
    scenario_id: 'php_schedule_surface',
    finding_type: 'schedule_php_surface_contract_gap',
    owning_surface: 'sdk-php',
    execution_scope: 'sdk-php-schedule-surface-shard',
    artifact_versions: artifactVersions,
    observed_behavior: checks.failures.join('; ') || 'The PHP-facing schedule client surface did not satisfy the schedule contract.',
    expected_behavior: 'The standalone PHP SDK creates or observes schedules, lists/describes them, records claimed control behavior, and matches server and CLI state for exposed fields.',
    next_acceptance_criterion: 'rerun schedules conformance and record passing PHP create_or_observe, list_or_describe, control behavior, and server/CLI state comparison',
    observed_outputs: checks,
  };
}

function phpSurfaceBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const finding = {
    finding_id: 'schedules-php-surface-runner-blocked',
    scenario_id: 'php_schedule_surface',
    finding_type: 'conformance_runner_blocked',
    owning_surface: 'conformance_harness',
    execution_scope: 'sdk-php-schedule-surface-shard',
    artifact_versions: artifactVersions,
    observed_behavior: reason,
    expected_behavior: 'The schedules conformance host can install the published standalone PHP SDK and execute its schedule client surface against published artifacts.',
    next_acceptance_criterion: 'restore the missing host capability and rerun schedules conformance',
  };

  return {
    schema: 'durable-workflow.v2.schedules-runtime.php-surface-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    scenario_results: {
      php_schedule_surface: {
        scenario_id: 'php_schedule_surface',
        status: 'runner_blocked',
        observed_outputs: { blocked_reason: reason },
        linked_findings: [finding],
      },
    },
    findings: [finding],
    client_surfaces: {
      'sdk-php': {
        create_or_observe: false,
        list_or_describe: false,
        control_observed: false,
        blocked_reason: reason,
      },
    },
  };
}

async function collectPhpSurfaceComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-php-surface-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

function crossLanguageFinding(cell, artifactVersions) {
  const reasons = [];
  if (!cell.schedule_visible_in_cli) {
    reasons.push('schedule was not visible through dw schedules list');
  }
  if (cell.worker_poll_error) {
    reasons.push(`worker poll failed: ${stringValue(cell.worker_poll_error.message)}`);
  }
  if (!cell.scheduled_fire_observed) {
    reasons.push('target worker did not receive a scheduled workflow task');
  }
  if (cell.scheduled_fire_observed && !cell.workflow_completed) {
    reasons.push('target worker received a scheduled workflow task but the workflow did not reach completed status');
  }

  return {
    finding_id: `schedules-${cell.scenario}-cross-language-dispatch`,
    scenario_id: cell.scenario,
    finding_type: 'schedule_cross_language_dispatch_gap',
    owning_surface: crossLanguageFindingOwner(cell),
    execution_scope: 'cross-language-schedule-worker-shard',
    artifact_versions: artifactVersions,
    observed_behavior: reasons.join('; ') || 'Cross-language schedule dispatch did not satisfy the published-artifact contract.',
    expected_behavior: 'Schedules created by Python and PHP-facing clients are visible through the CLI and dispatch scheduled fires to the opposite runtime worker on the shared task queue.',
    next_acceptance_criterion: 'rerun schedules conformance and record schedule_visible_in_cli=true plus workflow_completed=true for both Python-created/PHP-worker and PHP-created/Python-worker cells',
    schedule_creator: cell.schedule_creator,
    workflow_runtime: cell.workflow_runtime,
    schedule_id: cell.schedule_id,
    workflow_id: cell.workflow_id,
    run_id: cell.run_id,
    failure_class: cell.failure_class,
    schedule_diagnostics: cell.schedule_diagnostics,
    worker_diagnostics: cell.worker_diagnostics,
    task_queue_diagnostics: cell.task_queue_diagnostics,
    workflow_diagnostics: cell.workflow_diagnostics,
    worker_poll_error: cell.worker_poll_error,
  };
}

function crossLanguageFindingOwner(cell) {
  if (cell.failure_class === 'public_client_schedule_visibility_mismatch') {
    return 'cli';
  }
  if (cell.failure_class === 'public_client_worker_poll_error') {
    return cell.workflow_runtime || 'published_worker_client';
  }

  return 'server';
}

function crossLanguageRunnerBlockedFinding(cell, artifactVersions) {
  return {
    finding_id: 'schedules-cross-language-worker-poll-runner-blocked',
    scenario_id: cell.scenario,
    finding_type: 'conformance_runner_blocked',
    owning_surface: 'conformance_harness',
    execution_scope: 'cross-language-schedule-worker-shard',
    artifact_versions: artifactVersions,
    observed_behavior: cell.failure_reason || stringValue(cell.worker_poll_error?.message) || 'The cross-language schedules shard could not complete worker polling.',
    expected_behavior: 'The schedules conformance host can run the published PHP and Python worker-protocol clients long enough to observe scheduled workflow dispatch.',
    next_acceptance_criterion: 'restore the host worker-poll capability and rerun the focused cross-language schedules shard',
    schedule_creator: cell.schedule_creator,
    workflow_runtime: cell.workflow_runtime,
    schedule_id: cell.schedule_id,
    schedule_visible_in_cli: cell.schedule_visible_in_cli,
    schedule_diagnostics: cell.schedule_diagnostics,
    worker_diagnostics: cell.worker_diagnostics,
    task_queue_diagnostics: cell.task_queue_diagnostics,
    workflow_diagnostics: cell.workflow_diagnostics,
    worker_poll_error: cell.worker_poll_error,
    worker_poll_diagnostics: cell.worker_poll_diagnostics ?? cell.worker_poll_error?.diagnostics ?? null,
  };
}

function crossLanguageFailureClass(cell) {
  if (cell.workflow_completed === true && cell.schedule_visible_in_cli === true) {
    return 'none';
  }
  if (cell.runner_blocked === true || cell.worker_poll_error?.runner_blocked === true) {
    return 'runner_worker_poll_unavailable';
  }
  if (cell.worker_poll_error) {
    return 'public_client_worker_poll_error';
  }
  if (!cell.schedule_visible_in_cli) {
    return 'public_client_schedule_visibility_mismatch';
  }
  const triggeredCount = Number(cell.schedule_diagnostics?.triggered_event_count ?? 0);
  if (triggeredCount <= 0 && !cell.scheduled_fire_observed) {
    return 'server_schedule_fire_not_observed';
  }
  if (triggeredCount > 0 && !cell.scheduled_fire_observed) {
    return 'server_worker_selection_or_dispatch_mismatch';
  }
  if (cell.scheduled_fire_observed && !cell.workflow_completed) {
    return 'worker_completion_mismatch';
  }

  return 'cross_language_schedule_dispatch_gap';
}

function crossLanguageFailureReason(cell) {
  const failureClass = crossLanguageFailureClass(cell);
  if (failureClass === 'none') {
    return '';
  }
  if (failureClass === 'runner_worker_poll_unavailable') {
    return stringValue(cell.worker_poll_error?.message) || 'worker poll action did not produce a JSON result';
  }
  if (failureClass === 'public_client_worker_poll_error') {
    return stringValue(cell.worker_poll_error?.message) || 'published client worker poll failed';
  }
  if (failureClass === 'public_client_schedule_visibility_mismatch') {
    return 'created schedule was not visible in the official CLI schedule list output';
  }
  if (failureClass === 'server_schedule_fire_not_observed') {
    return 'schedule remained visible but no ScheduleTriggered history event or worker task was observed before the deadline';
  }
  if (failureClass === 'server_worker_selection_or_dispatch_mismatch') {
    return 'schedule history shows a trigger but the opposite-runtime worker did not receive the workflow task';
  }
  if (failureClass === 'worker_completion_mismatch') {
    return 'opposite-runtime worker received a scheduled workflow task but the run did not reach completed status';
  }

  return 'cross-language schedule dispatch did not satisfy the published-artifact contract';
}

function crossLanguageBlockedEvidence(reason, startedAt, artifactVersions, artifactSources) {
  const finishedAt = timestamp();
  const findings = [
    {
      finding_id: 'schedules-python-created-php-workflow-runner-blocked',
      scenario_id: 'python_created_php_workflow',
      finding_type: 'conformance_runner_blocked',
      owning_surface: 'conformance_harness',
      execution_scope: 'cross-language-schedule-worker-shard',
      artifact_versions: artifactVersions,
      observed_behavior: reason,
      expected_behavior: 'The schedules conformance host can install published Python and PHP workflow artifacts and execute Python-created/PHP-worker schedule dispatch.',
      next_acceptance_criterion: 'restore the missing host capability and rerun schedules conformance',
    },
    {
      finding_id: 'schedules-php-created-python-workflow-runner-blocked',
      scenario_id: 'php_created_python_workflow',
      finding_type: 'conformance_runner_blocked',
      owning_surface: 'conformance_harness',
      execution_scope: 'cross-language-schedule-worker-shard',
      artifact_versions: artifactVersions,
      observed_behavior: reason,
      expected_behavior: 'The schedules conformance host can install published PHP workflow and Python SDK artifacts and execute PHP-created/Python-worker schedule dispatch.',
      next_acceptance_criterion: 'restore the missing host capability and rerun schedules conformance',
    },
  ];

  return {
    schema: 'durable-workflow.v2.schedules-runtime.cross-language-evidence',
    started_at: startedAt,
    finished_at: finishedAt,
    generated_at: finishedAt,
    outcome: 'non_passing_runner_blocked',
    runner_blocked: true,
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    local_product_source_checkouts_used: false,
    scenario_results: {
      python_created_php_workflow: {
        scenario_id: 'python_created_php_workflow',
        status: 'runner_blocked',
        observed_outputs: {
          scenario: 'python_created_php_workflow',
          blocked_reason: reason,
          schedule_creator: 'sdk-python',
          workflow_runtime: 'sdk-php',
          schedule_visible_in_cli: false,
          workflow_completed: false,
        },
        linked_findings: [findings[0]],
      },
      php_created_python_workflow: {
        scenario_id: 'php_created_python_workflow',
        status: 'runner_blocked',
        observed_outputs: {
          scenario: 'php_created_python_workflow',
          blocked_reason: reason,
          schedule_creator: 'sdk-php',
          workflow_runtime: 'sdk-python',
          schedule_visible_in_cli: false,
          workflow_completed: false,
        },
        linked_findings: [findings[1]],
      },
    },
    findings,
    runtime_matrix: {
      runtimes: ['sdk-php', 'sdk-python'],
      client_paths: ['cli', 'sdk-python', 'sdk-php'],
      schedule_types: ['fixed_rate_interval'],
      cross_language_cells: [
        {
          scenario: 'python_created_php_workflow',
          schedule_creator: 'sdk-python',
          workflow_runtime: 'sdk-php',
        },
        {
          scenario: 'php_created_python_workflow',
          schedule_creator: 'sdk-php',
          workflow_runtime: 'sdk-python',
        },
      ],
    },
    cross_language_matrix: {
      cross_language_cells: [
        {
          scenario: 'python_created_php_workflow',
          schedule_creator: 'sdk-python',
          workflow_runtime: 'sdk-php',
          schedule_visible_in_cli: false,
          workflow_completed: false,
          blocked_reason: reason,
        },
        {
          scenario: 'php_created_python_workflow',
          schedule_creator: 'sdk-php',
          workflow_runtime: 'sdk-python',
          schedule_visible_in_cli: false,
          workflow_completed: false,
          blocked_reason: reason,
        },
      ],
    },
  };
}

async function collectCrossLanguageComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-cross-language-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

async function collectPythonLifecycleComposeLogs(composeProject, composeFiles) {
  for (const service of ['server', 'scheduler', 'bootstrap', 'mysql', 'redis']) {
    const logPath = path.join(resultDir, `schedules-python-lifecycle-${service}.log`);
    await execLogged(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'logs', service],
      logPath,
    ).catch(() => {});
  }
}

function safeLogName(value) {
  return stringValue(value).replace(/[^A-Za-z0-9_.-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 96) || 'action';
}

function schedulesPythonLifecycleScript() {
  return String.raw`import asyncio
import dataclasses
import json
import os
import sys
import time
from typing import Any

from durable_workflow import Client, ScheduleAction, ScheduleSpec, serializer
from durable_workflow.client import PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST
from durable_workflow.errors import InvalidArgument, ScheduleListError, ScheduleNotFound, ServerError


def as_dict(value: Any) -> dict[str, Any]:
    if value is None:
        return {}
    try:
        converted = dataclasses.asdict(value)
        return converted if isinstance(converted, dict) else {"value": converted}
    except TypeError:
        raw = getattr(value, "__dict__", None)
        if isinstance(raw, dict):
            return dict(raw)
    if isinstance(value, dict):
        return dict(value)
    return {"value": str(value)}


def process_metrics() -> dict[str, Any]:
    return {
        "process_id": os.getpid(),
        "host": "schedules-python-lifecycle-shard",
        "process_started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "process_uptime_seconds": 1,
    }


def schedule_ids(schedule_list: Any) -> list[str]:
    return [
        str(getattr(item, "schedule_id", "") or "")
        for item in getattr(schedule_list, "schedules", [])
        if str(getattr(item, "schedule_id", "") or "") != ""
    ]


def schedule_status(value: Any) -> str:
    return str(getattr(value, "status", "") or "").lower()


def exception_record(error: BaseException) -> dict[str, Any]:
    record: dict[str, Any] = {
        "type": error.__class__.__name__,
        "message": str(error),
    }
    if isinstance(error, ScheduleListError):
        record["status"] = error.status
        record["body"] = error.body
        record["reason"] = error.reason()
        record["field"] = error.field
        record["errors"] = error.errors
        record["last_safe_cursor"] = error.last_safe_cursor
        record["typed_error"] = True
    elif isinstance(error, InvalidArgument):
        record["typed_error"] = True
        record["errors"] = error.errors
    elif isinstance(error, ServerError):
        record["status"] = error.status
        record["body"] = error.body
        record["reason"] = error.reason()
        record["typed_error"] = error.status == 422
    else:
        record["typed_error"] = False
    return record


async def schedule_list_refusal(client: Client, **filters: Any) -> dict[str, Any]:
    try:
        page = await client.list_schedules(**filters)
        return {
            "refused": False,
            "response": {
                "schedule_ids": schedule_ids(page),
                "next_page_token": getattr(page, "next_page_token", None),
            },
        }
    except ScheduleListError as error:
        return {"refused": True, "error": exception_record(error)}
    except Exception as error:
        return {"refused": True, "error": exception_record(error)}


async def schedule_visibility_probe(
    client: Client,
    *,
    prefix: str,
    task_queue: str,
    server_url: str,
    token: str,
    namespace: str,
) -> dict[str, Any]:
    for name, attribute_type in [
        ("ScheduleFilterRun", "keyword"),
        ("Region", "keyword"),
        ("Priority", "int"),
    ]:
        await client.create_search_attribute(name, attribute_type)

    workflow_type_a = f"{prefix}.orders"
    workflow_type_b = f"{prefix}.reports"
    definitions = [
        (f"{prefix}-active-orders-eu", "active", workflow_type_a, "eu", 1),
        (f"{prefix}-paused-orders-eu", "paused", workflow_type_a, "eu", 2),
        (f"{prefix}-active-orders-us", "active", workflow_type_a, "us", 2),
        (f"{prefix}-paused-reports-us", "paused", workflow_type_b, "us", 3),
        (f"{prefix}-active-reports-eu", "active", workflow_type_b, "eu", 2),
        (f"{prefix}-deleted-orders-eu", "deleted", workflow_type_a, "eu", 2),
    ]
    created_ids: list[str] = []

    try:
        for schedule_id, desired_status, workflow_type, region, priority in definitions:
            handle = await client.create_schedule(
                schedule_id=schedule_id,
                spec=ScheduleSpec(intervals=[{"every": "PT24H"}], timezone="UTC"),
                action=ScheduleAction(
                    workflow_type=workflow_type,
                    task_queue=task_queue,
                    input=[{"scenario": "schedule_visibility_filtering", "schedule_id": schedule_id}],
                ),
                search_attributes={
                    "ScheduleFilterRun": prefix,
                    "Region": region,
                    "Priority": priority,
                },
                paused=desired_status == "paused",
            )
            created_ids.append(handle.schedule_id)
            if desired_status == "deleted":
                await client.delete_schedule(schedule_id)

        marker_query = f'ScheduleFilterRun = "{prefix}"'
        all_expected = sorted(schedule_id for schedule_id, status, *_ in definitions if status != "deleted")
        paused_expected = sorted(schedule_id for schedule_id, status, *_ in definitions if status == "paused")
        type_expected = sorted(
            schedule_id
            for schedule_id, status, workflow_type, *_ in definitions
            if status != "deleted" and workflow_type == workflow_type_a
        )
        query_expected = sorted([
            f"{prefix}-paused-orders-eu",
            f"{prefix}-active-reports-eu",
        ])
        combined_expected = [f"{prefix}-paused-orders-eu"]

        all_page = await client.list_schedules(query=marker_query, page_size=200)
        paused_page = await client.list_schedules(status="paused", query=marker_query, page_size=200)
        type_page = await client.list_schedules(
            workflow_type=workflow_type_a,
            query=marker_query,
            page_size=200,
        )
        query_page = await client.list_schedules(
            query=f'{marker_query} AND Region = "eu" AND Priority = 2',
            page_size=200,
        )
        combined_page = await client.list_schedules(
            status="paused",
            workflow_type=workflow_type_a,
            query=f'{marker_query} AND Region = "eu"',
            page_size=200,
        )

        traversed: list[str] = []
        page_tokens: list[str | None] = []
        next_page_token: str | None = None
        while True:
            page = await client.list_schedules(
                query=marker_query,
                page_size=2,
                next_page_token=next_page_token,
            )
            traversed.extend(schedule_ids(page))
            next_page_token = getattr(page, "next_page_token", None)
            page_tokens.append(next_page_token)
            if next_page_token is None:
                break
            if len(page_tokens) > 3:
                raise RuntimeError("schedule visibility pagination did not terminate")

        cursor_page = await client.list_schedules(query=marker_query, page_size=2)
        cursor_token = getattr(cursor_page, "next_page_token", None)
        cursor_anchor_ids = schedule_ids(cursor_page)
        if not cursor_token or not cursor_anchor_ids:
            raise RuntimeError("schedule visibility probe did not receive a continuation cursor")

        malformed = await schedule_list_refusal(client, next_page_token="not-an-opaque-token")
        invalid_query = await schedule_list_refusal(
            client,
            query='Region STARTS_WITH "e"',
            next_page_token=cursor_token,
        )
        invalid_page_size = await schedule_list_refusal(client, page_size=0)
        filter_mismatch = await schedule_list_refusal(
            client,
            status="paused",
            query=marker_query,
            next_page_token=cursor_token,
        )

        other_namespace = f"{namespace}-cursor-other"
        await client.create_namespace(other_namespace, description="Schedule cursor isolation probe")
        async with Client(server_url, token=token, namespace=other_namespace, timeout=8.0) as other_client:
            namespace_mismatch = await schedule_list_refusal(
                other_client,
                query=marker_query,
                next_page_token=cursor_token,
            )

        cursor_anchor = cursor_anchor_ids[-1]
        await client.delete_schedule(cursor_anchor)
        stale = await schedule_list_refusal(
            client,
            query=marker_query,
            next_page_token=cursor_token,
        )

        observed = {
            "all": sorted(schedule_ids(all_page)),
            "status": sorted(schedule_ids(paused_page)),
            "workflow_type": sorted(schedule_ids(type_page)),
            "query": sorted(schedule_ids(query_page)),
            "combined": sorted(schedule_ids(combined_page)),
        }
        expected = {
            "all": all_expected,
            "status": paused_expected,
            "workflow_type": type_expected,
            "query": query_expected,
            "combined": combined_expected,
        }
        refusals = {
            "malformed": malformed,
            "invalid_query": invalid_query,
            "invalid_page_size": invalid_page_size,
            "filter_mismatch": filter_mismatch,
            "namespace_mismatch": namespace_mismatch,
            "stale": stale,
        }
        expected_reasons = {
            "malformed": (400, "malformed_schedule_page_token", "next_page_token", False),
            "invalid_query": (422, "unsupported_schedule_visibility_predicate", "query", True),
            "invalid_page_size": (422, "validation_failed", "page_size", False),
            "filter_mismatch": (409, "schedule_page_token_filter_mismatch", "next_page_token", True),
            "namespace_mismatch": (403, "schedule_page_token_namespace_mismatch", "next_page_token", True),
            "stale": (409, "stale_schedule_page_token", "next_page_token", True),
        }
        refusal_checks = {}
        for name, (status, reason, field, cursor_required) in expected_reasons.items():
            error = refusals[name].get("error") or {}
            refusal_checks[name] = (
                refusals[name].get("refused") is True
                and error.get("typed_error") is True
                and error.get("status") == status
                and error.get("reason") == reason
                and error.get("field") == field
                and isinstance(error.get("errors"), dict)
                and (
                    not cursor_required
                    or isinstance(error.get("last_safe_cursor"), dict)
                )
            )

        return {
            "filters_passed": observed == expected,
            "pagination_passed": (
                sorted(traversed) == all_expected
                and len(traversed) == len(set(traversed))
                and len(page_tokens) == 3
                and page_tokens[-1] is None
            ),
            "typed_refusals_passed": all(refusal_checks.values()),
            "namespace_and_deleted_isolation_passed": (
                f"{prefix}-deleted-orders-eu" not in observed["all"]
                and len(observed["all"]) == 5
            ),
            "expected": expected,
            "observed": observed,
            "pagination": {
                "page_size": 2,
                "page_count": len(page_tokens),
                "schedule_ids": traversed,
                "terminal_token": page_tokens[-1],
            },
            "refusals": refusals,
            "refusal_checks": refusal_checks,
        }
    finally:
        for schedule_id in created_ids:
            try:
                await client.delete_schedule(schedule_id)
            except Exception:
                pass


async def poll_and_complete_workflow(
    client: Client,
    *,
    worker_id: str,
    task_queue: str,
    workflow_type: str,
    trigger: Any,
    timeout_seconds: int,
) -> dict[str, Any]:
    deadline = time.monotonic() + timeout_seconds
    trigger_workflow_id = getattr(trigger, "workflow_id", None)
    trigger_run_id = getattr(trigger, "run_id", None)
    attempts = 0
    last_task = None
    complete_response = None

    while time.monotonic() < deadline:
        attempts += 1
        task = await client.poll_workflow_task(
            worker_id=worker_id,
            task_queue=task_queue,
            timeout=3.0,
        )
        if not task:
            await asyncio.sleep(0.2)
            continue

        last_task = task
        complete_response = await client.complete_workflow_task(
            task_id=task["task_id"],
            lease_owner=task["lease_owner"],
            workflow_task_attempt=int(task.get("workflow_task_attempt") or 1),
            commands=[
                {
                    "type": "complete_workflow",
                    "result": serializer.envelope({
                        "scenario": "python_sdk_schedule_surface",
                        "worker_id": worker_id,
                        "workflow_type": workflow_type,
                        "runtime": "sdk-python",
                    }),
                }
            ],
        )
        trigger_workflow_id = trigger_workflow_id or task.get("workflow_id")
        trigger_run_id = trigger_run_id or task.get("run_id")
        break

    workflow_run = {}
    workflow_completed = False
    if trigger_workflow_id:
        for _ in range(20):
            described = await client.describe_workflow(str(trigger_workflow_id))
            workflow_run = as_dict(described)
            workflow_completed = schedule_status(described) == "completed"
            if workflow_completed:
                break
            await asyncio.sleep(0.5)

    return {
        "workflow_completed": workflow_completed,
        "workflow_id": trigger_workflow_id,
        "run_id": trigger_run_id,
        "poll_attempts": attempts,
        "last_task": last_task,
        "complete_response": complete_response,
        "workflow_run": workflow_run,
    }


async def invalid_cron_probe(
    client: Client,
    *,
    schedule_id: str,
    invalid_cron: str,
    workflow_type: str,
    task_queue: str,
) -> dict[str, Any]:
    refused = False
    error_record: dict[str, Any] | None = None
    create_response = None
    try:
        handle = await client.create_schedule(
            schedule_id=schedule_id,
            spec=ScheduleSpec(cron_expressions=[invalid_cron], timezone="UTC"),
            action=ScheduleAction(
                workflow_type=workflow_type,
                task_queue=task_queue,
                input=[{"scenario": "invalid_cron_refusal"}],
            ),
        )
        create_response = {"schedule_id": handle.schedule_id}
    except Exception as error:
        refused = True
        error_record = exception_record(error)

    listed = await client.list_schedules()
    listed_ids = schedule_ids(listed)
    describe_found = False
    describe_status: int | str = "not_checked"
    describe_error: dict[str, Any] | None = None
    described = {}
    try:
        description = await client.describe_schedule(schedule_id)
        describe_found = True
        describe_status = 200
        described = as_dict(description)
    except ScheduleNotFound as error:
        describe_found = False
        describe_status = 404
        describe_error = exception_record(error)
    except ServerError as error:
        describe_found = False
        describe_status = error.status
        describe_error = exception_record(error)
    except Exception as error:
        describe_found = False
        describe_status = "error"
        describe_error = exception_record(error)

    list_contains = schedule_id in listed_ids
    persisted = list_contains or describe_found
    if not refused and persisted:
        await client.delete_schedule(schedule_id)

    return {
        "refused": refused,
        "typed_error": bool(error_record and error_record.get("typed_error") is True),
        "persisted": persisted,
        "create_response": create_response,
        "error": error_record,
        "persistence_evidence": {
            "surface": "python-sdk-public-list-describe",
            "public_list_checked": True,
            "list_contains_invalid_schedule": list_contains,
            "list_schedule_ids": listed_ids,
            "public_describe_checked": True,
            "describe_found": describe_found,
            "describe_status": describe_status,
            "describe_error": describe_error,
            "described_schedule": described,
        },
    }


async def main() -> None:
    with open(sys.argv[1], "r", encoding="utf-8") as handle:
        payload = json.load(handle)
    output_path = sys.argv[2]
    timeout_seconds = int(payload.get("timeout_seconds") or 120)
    schedule_id = payload["schedule_id"]
    invalid_schedule_id = payload["invalid_schedule_id"]
    workflow_type = payload["workflow_type"]
    task_queue = payload["task_queue"]
    worker_id = payload["worker_id"]

    async with Client(
        payload["server_url"],
        token=payload["token"],
        namespace=payload["namespace"],
        timeout=8.0,
    ) as client:
        registration = await client.register_worker(
            worker_id=worker_id,
            task_queue=task_queue,
            supported_workflow_types=[workflow_type],
            workflow_definition_fingerprints={
                workflow_type: f"schedules-conformance:{workflow_type}:python-lifecycle"
            },
            supported_activity_types=[],
            capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST,
            max_concurrent_workflow_tasks=10,
            max_concurrent_activity_tasks=10,
            runtime="python",
            sdk_version=payload.get("sdk_version") or "published",
            task_slots={"workflow_available": 10, "activity_available": 10},
            process_metrics=process_metrics(),
        )

        handle = await client.create_schedule(
            schedule_id=schedule_id,
            spec=ScheduleSpec(
                intervals=[{"every": payload.get("interval") or "PT1H"}],
                timezone="UTC",
            ),
            action=ScheduleAction(
                workflow_type=workflow_type,
                task_queue=task_queue,
                input=[{"scenario": "python_sdk_schedule_surface", "schedule_id": schedule_id}],
            ),
            overlap_policy="allow_all",
            jitter_seconds=0,
        )
        visibility_contract = await schedule_visibility_probe(
            client,
            prefix=f"{schedule_id}-visibility",
            task_queue=task_queue,
            server_url=payload["server_url"],
            token=payload["token"],
            namespace=payload["namespace"],
        )
        list_after_create = await client.list_schedules()
        describe_after_create = await client.describe_schedule(schedule_id)

        await client.pause_schedule(schedule_id, note="python schedules lifecycle pause")
        describe_after_pause = await client.describe_schedule(schedule_id)
        await client.resume_schedule(schedule_id, note="python schedules lifecycle resume")
        describe_after_resume = await client.describe_schedule(schedule_id)

        trigger = await client.trigger_schedule(schedule_id, overlap_policy="allow_all")
        completion = await poll_and_complete_workflow(
            client,
            worker_id=worker_id,
            task_queue=task_queue,
            workflow_type=workflow_type,
            trigger=trigger,
            timeout_seconds=timeout_seconds,
        )

        await client.delete_schedule(schedule_id)
        list_after_delete = await client.list_schedules()
        describe_after_delete_found = False
        describe_after_delete_status: int | str = "not_checked"
        try:
            await client.describe_schedule(schedule_id)
            describe_after_delete_found = True
            describe_after_delete_status = 200
        except ScheduleNotFound:
            describe_after_delete_status = 404
        except ServerError as error:
            describe_after_delete_status = error.status

        invalid = await invalid_cron_probe(
            client,
            schedule_id=invalid_schedule_id,
            invalid_cron=payload["invalid_cron"],
            workflow_type=workflow_type,
            task_queue=task_queue,
        )

    output = {
        "schema": "durable-workflow.v2.schedules-runtime.python-lifecycle-worker-output",
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "registration": registration,
        "operations": {
            "create": getattr(handle, "schedule_id", "") == schedule_id,
            "list": schedule_id in schedule_ids(list_after_create),
            "describe": getattr(describe_after_create, "schedule_id", "") == schedule_id,
            "pause": schedule_status(describe_after_pause) == "paused"
                or bool(getattr(describe_after_pause, "paused_at", None)),
            "resume": schedule_status(describe_after_resume) == "active"
                or not bool(getattr(describe_after_resume, "paused_at", None)),
            "manual_trigger": getattr(trigger, "outcome", "") == "triggered"
                and bool(getattr(trigger, "workflow_id", None)),
            "delete": schedule_id not in schedule_ids(list_after_delete)
                and describe_after_delete_found is False,
            "visibility_filtering": visibility_contract["filters_passed"]
                and visibility_contract["namespace_and_deleted_isolation_passed"],
            "visibility_pagination": visibility_contract["pagination_passed"],
            "visibility_typed_refusals": visibility_contract["typed_refusals_passed"],
        },
        "schedule_state": {
            "list_after_create": [as_dict(item) for item in getattr(list_after_create, "schedules", [])],
            "describe_after_create": as_dict(describe_after_create),
            "describe_after_pause": as_dict(describe_after_pause),
            "describe_after_resume": as_dict(describe_after_resume),
            "list_after_delete": [as_dict(item) for item in getattr(list_after_delete, "schedules", [])],
            "describe_after_delete_found": describe_after_delete_found,
            "describe_after_delete_status": describe_after_delete_status,
            "visibility_contract": visibility_contract,
        },
        "trigger_result": as_dict(trigger),
        "triggered_workflow_completion": completion,
        "invalid_cron_refusal": invalid,
    }
    with open(output_path, "w", encoding="utf-8") as handle:
        json.dump(output, handle, indent=2, default=str)
        handle.write("\n")


asyncio.run(main())
`;
}

function schedulesPythonWorkerScript() {
  return String.raw`import asyncio
import json
import os
import sys
import time

from durable_workflow import Client, ScheduleAction, ScheduleSpec, serializer
from durable_workflow.client import PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST


def process_metrics():
    return {
        "process_id": os.getpid(),
        "host": "schedules-cross-language-python-shard",
        "process_started_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "process_uptime_seconds": 1,
    }


def write_progress(output_path, payload, stage, extra=None):
    record = {
        "ok": False,
        "action": payload.get("action"),
        "diagnostic_stage": stage,
        "product_boundary_reached": stage.startswith("worker_protocol_"),
        "worker_id": payload.get("worker_id"),
        "task_queue": payload.get("task_queue"),
        "workflow_type": payload.get("workflow_type"),
        "schedule_id": payload.get("schedule_id"),
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    if isinstance(extra, dict):
        record.update(extra)
    with open(output_path, "w", encoding="utf-8") as handle:
        json.dump(record, handle, indent=2, default=str)
        handle.write("\n")
        handle.flush()


async def run_action(client, payload, output_path):
    if payload["action"] == "register":
        response = await client.register_worker(
            worker_id=payload["worker_id"],
            task_queue=payload["task_queue"],
            supported_workflow_types=[payload["workflow_type"]],
            workflow_definition_fingerprints={
                payload["workflow_type"]: f"schedules-conformance:{payload['workflow_type']}:python"
            },
            supported_activity_types=payload.get("supported_activity_types") or [],
            capability_manifest=PORTABLE_WORKER_AFFINITY_CAPABILITY_MANIFEST,
            max_concurrent_workflow_tasks=10,
            max_concurrent_activity_tasks=10,
            runtime="python",
            sdk_version=payload.get("sdk_version") or "published",
            task_slots={"workflow_available": 10, "activity_available": 10},
            process_metrics=process_metrics(),
        )
        return {"action": "register", "response": response, "task": None}
    if payload["action"] == "create_schedule":
        handle = await client.create_schedule(
            schedule_id=payload["schedule_id"],
            spec=ScheduleSpec(intervals=[{"every": payload.get("interval") or "PT30S"}], timezone="UTC"),
            action=ScheduleAction(
                workflow_type=payload["workflow_type"],
                task_queue=payload["task_queue"],
                input=[payload.get("input") or {}],
            ),
            overlap_policy="allow_all",
            jitter_seconds=0,
            max_runs=max(2, int(payload.get("schedule_max_runs") or 2)),
        )
        return {"action": "create_schedule", "schedule_id": handle.schedule_id}
    if payload["action"] == "poll_complete":
        # This protocol helper runs one action per process, so it must perform
        # the liveness tick that a long-running SDK Worker sends in background.
        write_progress(output_path, payload, "worker_protocol_heartbeat_started")
        heartbeat_response = await client.heartbeat_worker(
            worker_id=payload["worker_id"],
            task_slots={"workflow_available": 10, "activity_available": 10},
            process_metrics=process_metrics(),
        )
        write_progress(output_path, payload, "worker_protocol_heartbeat_returned", {
            "heartbeat_response": heartbeat_response,
        })
        write_progress(output_path, payload, "worker_protocol_poll_started", {
            "poll_timeout_seconds": 3.0,
        })
        task = await client.poll_workflow_task(
            worker_id=payload["worker_id"],
            task_queue=payload["task_queue"],
            timeout=3.0,
        )
        write_progress(output_path, payload, "worker_protocol_poll_returned", {
            "task": task,
            "task_received": bool(task),
        })
        complete_response = None
        if task:
            write_progress(output_path, payload, "worker_protocol_complete_started", {
                "task": task,
                "workflow_id": task.get("workflow_id"),
                "run_id": task.get("run_id"),
            })
            complete_response = await client.complete_workflow_task(
                task_id=task["task_id"],
                lease_owner=task["lease_owner"],
                workflow_task_attempt=int(task.get("workflow_task_attempt") or 1),
                commands=[
                    {
                        "type": "complete_workflow",
                        "result": serializer.envelope({
                            **(payload.get("complete_result") or {}),
                            "worker_id": payload["worker_id"],
                            "workflow_type": payload["workflow_type"],
                            "runtime": "sdk-python",
                        }),
                    }
                ],
            )
            write_progress(output_path, payload, "worker_protocol_complete_returned", {
                "task": task,
                "complete_response": complete_response,
                "workflow_id": task.get("workflow_id"),
                "run_id": task.get("run_id"),
            })
        return {
            "action": "poll_complete",
            "heartbeat_response": heartbeat_response,
            "task": task,
            "complete_response": complete_response,
        }
    raise RuntimeError(f"unknown action: {payload['action']}")


async def main():
    payload = {}
    output_path = sys.argv[2]
    try:
        with open(sys.argv[1], "r", encoding="utf-8") as handle:
            payload = json.load(handle)
        write_progress(output_path, payload, "input_loaded")

        async with Client(
            payload["server_url"],
            token=payload["token"],
            namespace=payload["namespace"],
            timeout=8.0,
        ) as client:
            result = await run_action(client, payload, output_path)
        result["ok"] = True
    except Exception as exc:
        result = {
            "ok": False,
            "action": payload.get("action"),
            "error_type": type(exc).__name__,
            "error_message": str(exc),
        }

    with open(output_path, "w", encoding="utf-8") as handle:
        json.dump(result, handle, indent=2, default=str)
        handle.write("\n")


asyncio.run(main())
`;
}

function schedulesPhpWorkerScript() {
  return String.raw`<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$outputPath = $argv[2];
$client = new Client(
    (string) $payload['server_url'],
    namespace: (string) $payload['namespace'],
    token: (string) $payload['token'],
);

try {
if ($payload['action'] === 'create_schedule') {
    $handle = $client->createSchedule(
        new ScheduleSpec(
            intervals: [['every' => (string) ($payload['interval'] ?? 'PT30S')]],
            timezone: 'UTC',
        ),
        new ScheduleAction(
            (string) $payload['workflow_type'],
            (string) $payload['task_queue'],
            [$payload['input'] ?? []],
        ),
        scheduleId: (string) $payload['schedule_id'],
        overlapPolicy: 'allow_all',
        jitterSeconds: 0,
        maxRuns: max(2, (int) ($payload['schedule_max_runs'] ?? 2)),
    );
    $result = ['action' => 'create_schedule', 'schedule_id' => $handle->scheduleId, 'response' => ['schedule_id' => $handle->scheduleId]];
} else {
    if ($payload['action'] === 'register') {
        $response = $client->registerWorker(
            (string) $payload['worker_id'],
            (string) $payload['task_queue'],
            [(string) $payload['workflow_type']],
            $payload['supported_activity_types'] ?? [],
            ['query_tasks', 'workflow_updates', 'durable_history_replay'],
            maxConcurrentWorkflowTasks: 10,
            maxConcurrentActivityTasks: 10,
        );
        $result = ['action' => 'register', 'response' => $response, 'task' => null];
    } elseif ($payload['action'] === 'poll_complete') {
        // This protocol helper runs one action per process, so it must perform
        // the liveness tick that a long-running SDK worker sends in background.
        $heartbeatResponse = $client->heartbeatWorker(
            (string) $payload['worker_id'],
            ['workflow_available' => 10, 'activity_available' => 10],
        );
        $task = $client->pollWorkflowTask(
            (string) $payload['worker_id'],
            (string) $payload['task_queue'],
            3,
        );
        $completeResponse = null;

        if (is_array($task)) {
            $completeResult = array_merge(
                is_array($payload['complete_result'] ?? null) ? $payload['complete_result'] : [],
                [
                    'worker_id' => (string) $payload['worker_id'],
                    'workflow_type' => (string) $payload['workflow_type'],
                    'runtime' => 'sdk-php',
                ],
            );
            $completeResponse = $client->completeWorkflowTask(
                (string) $task['task_id'],
                (string) ($task['lease_owner'] ?? $payload['worker_id']),
                (int) ($task['workflow_task_attempt'] ?? 1),
                [[
                    'type' => 'complete_workflow',
                    'result' => $client->payloadCodec()->envelope($completeResult),
                ]],
            );
        }

        $result = [
            'action' => 'poll_complete',
            'heartbeat_response' => $heartbeatResponse,
            'task' => $task,
            'complete_response' => $completeResponse,
        ];
    } else {
        throw new RuntimeException('unknown action: '.(string) $payload['action']);
    }
}

if (($result['ok'] ?? null) !== false) {
    $result['ok'] = true;
}
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'action' => $payload['action'] ?? null,
        'error_type' => $exception::class,
        'error_message' => $exception->getMessage(),
    ];
}

file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
`;
}

function schedulesPhpSurfaceProbeScript() {
  return String.raw`<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use DurableWorkflow\Client;
use DurableWorkflow\Exception\ServerException;
use DurableWorkflow\Model\ScheduleAction;
use DurableWorkflow\Model\ScheduleSpec;

$payload = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$outputPath = $argv[2];
$client = new Client(
    (string) $payload['server_url'],
    namespace: (string) $payload['namespace'],
    token: isset($payload['token']) && $payload['token'] !== '' ? (string) $payload['token'] : null,
);

function dw_schedule_public(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('dw_schedule_public', $value);
    }
    if (is_object($value)) {
        return array_map('dw_schedule_public', get_object_vars($value));
    }

    return $value;
}

function dw_schedule_error(Throwable $exception): array
{
    $error = [
        'class' => $exception::class,
        'message' => $exception->getMessage(),
    ];

    if ($exception instanceof ServerException) {
        $error['status'] = $exception->status;
        $error['reason'] = $exception->reason;
        $error['body'] = $exception->details;
    }

    return $error;
}

function dw_schedule_operation(Client $client, string $operation, string $method, array $arguments): array
{
    if (! method_exists($client, $method)) {
        return [
            'operation' => $operation,
            'method' => $method,
            'ok' => false,
            'supported' => false,
            'error' => ['message' => sprintf('%s is not exposed by DurableWorkflow\\Client', $method)],
        ];
    }

    try {
        return [
            'operation' => $operation,
            'method' => $method,
            'ok' => true,
            'supported' => true,
            'response' => dw_schedule_public($client->{$method}(...$arguments)),
        ];
    } catch (Throwable $exception) {
        return [
            'operation' => $operation,
            'method' => $method,
            'ok' => false,
            'supported' => true,
            'error' => dw_schedule_error($exception),
        ];
    }
}

function dw_schedule_create_or_observe(Client $client, string $scheduleId, array $spec, array $action): array
{
    if (! method_exists($client, 'createSchedule')) {
        return [
            'operation' => 'create_or_observe',
            'method' => 'createSchedule',
            'ok' => false,
            'supported' => false,
            'error' => ['message' => 'createSchedule is not exposed by DurableWorkflow\\Client'],
        ];
    }

    try {
        $handle = $client->createSchedule(
            new ScheduleSpec(
                cronExpressions: $spec['cron_expressions'] ?? [],
                intervals: $spec['intervals'] ?? [],
                timezone: $spec['timezone'] ?? null,
            ),
            new ScheduleAction(
                (string) $action['workflow_type'],
                isset($action['task_queue']) ? (string) $action['task_queue'] : null,
                is_array($action['input'] ?? null) ? $action['input'] : [],
            ),
            scheduleId: $scheduleId,
            overlapPolicy: 'allow_all',
            jitterSeconds: 0,
            memo: ['conformance' => 'php_schedule_surface'],
            searchAttributes: ['ScheduleSurface' => 'sdk-php'],
        );
        $response = ['schedule_id' => $handle->scheduleId];
        $response['observed_via'] = 'create';

        return [
            'operation' => 'create_or_observe',
            'method' => 'createSchedule',
            'ok' => true,
            'supported' => true,
            'response' => $response,
        ];
    } catch (ServerException $exception) {
        if (! in_array($exception->status, [409, 422], true) || ! method_exists($client, 'describeSchedule')) {
            return [
                'operation' => 'create_or_observe',
                'method' => 'createSchedule',
                'ok' => false,
                'supported' => true,
                'error' => dw_schedule_error($exception),
            ];
        }

        try {
            $response = dw_schedule_public($client->describeSchedule($scheduleId));
            $response['observed_via'] = 'describe_existing_after_create_rejection';
            $response['create_rejection'] = dw_schedule_error($exception);

            return [
                'operation' => 'create_or_observe',
                'method' => 'createSchedule',
                'ok' => true,
                'supported' => true,
                'response' => $response,
            ];
        } catch (Throwable $observeException) {
            return [
                'operation' => 'create_or_observe',
                'method' => 'createSchedule',
                'ok' => false,
                'supported' => true,
                'error' => dw_schedule_error($observeException),
                'create_rejection' => dw_schedule_error($exception),
            ];
        }
    } catch (Throwable $exception) {
        return [
            'operation' => 'create_or_observe',
            'method' => 'createSchedule',
            'ok' => false,
            'supported' => true,
            'error' => dw_schedule_error($exception),
        ];
    }
}

$scheduleId = (string) $payload['schedule_id'];
$action = (string) $payload['action'];

if ($action === 'delete_schedule') {
    $result = [
        'action' => 'delete_schedule',
        'schedule_id' => $scheduleId,
        'claimed_controls' => [
            'delete' => method_exists($client, 'deleteSchedule'),
        ],
        'delete' => dw_schedule_operation($client, 'delete', 'deleteSchedule', [$scheduleId]),
        'list_after_delete' => dw_schedule_operation($client, 'list_after_delete', 'listSchedules', [null, null, null, 100]),
    ];
} else {
    $spec = [
        'cron_expressions' => [(string) ($payload['cron'] ?? '*/5 * * * *')],
        'timezone' => 'UTC',
    ];
    $workflowAction = [
        'workflow_type' => (string) $payload['workflow_type'],
        'task_queue' => (string) $payload['task_queue'],
        'input' => [[
            'source' => 'sdk-php-schedule-surface',
            'run_id' => (string) ($payload['run_id'] ?? ''),
        ]],
    ];

    $result = [
        'action' => 'create_observe_controls',
        'schedule_id' => $scheduleId,
        'spec' => $spec,
        'workflow_action' => $workflowAction,
        'claimed_controls' => [
            'update' => method_exists($client, 'updateSchedule'),
            'pause' => method_exists($client, 'pauseSchedule'),
            'resume' => method_exists($client, 'resumeSchedule'),
            'trigger' => method_exists($client, 'triggerSchedule'),
            'backfill' => method_exists($client, 'backfillSchedule'),
            'history' => method_exists($client, 'scheduleHistory'),
            'delete' => method_exists($client, 'deleteSchedule'),
        ],
        'create_or_observe' => dw_schedule_create_or_observe($client, $scheduleId, $spec, $workflowAction),
        'list_or_describe' => [
            'list' => dw_schedule_operation($client, 'list', 'listSchedules', [null, null, null, 100]),
            'describe' => dw_schedule_operation($client, 'describe', 'describeSchedule', [$scheduleId]),
        ],
        'control_behavior' => [
            'update' => dw_schedule_operation($client, 'update', 'updateSchedule', [$scheduleId, ['note' => 'php schedule surface conformance update']]),
            'describe_after_update' => dw_schedule_operation($client, 'describe_after_update', 'describeSchedule', [$scheduleId]),
            'pause' => dw_schedule_operation($client, 'pause', 'pauseSchedule', [$scheduleId, 'php schedule surface conformance pause']),
            'describe_after_pause' => dw_schedule_operation($client, 'describe_after_pause', 'describeSchedule', [$scheduleId]),
            'resume' => dw_schedule_operation($client, 'resume', 'resumeSchedule', [$scheduleId, 'php schedule surface conformance resume']),
            'describe_after_resume' => dw_schedule_operation($client, 'describe_after_resume', 'describeSchedule', [$scheduleId]),
            'trigger' => dw_schedule_operation($client, 'trigger', 'triggerSchedule', [$scheduleId, 'allow_all']),
            'backfill' => dw_schedule_operation($client, 'backfill', 'backfillSchedule', [
                $scheduleId,
                '2000-01-01T00:01:01Z',
                '2000-01-01T00:01:02Z',
                'allow_all',
            ]),
            'history' => dw_schedule_operation($client, 'history', 'scheduleHistory', [$scheduleId, 100]),
        ],
    ];
}

file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
`;
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

  return stringValue(value.schedule_id ?? value.scheduleId ?? value.id);
}

function firstStringValue(...values) {
  for (const value of values) {
    const normalized = stringValue(value);
    if (normalized !== '') {
      return normalized;
    }
  }

  return '';
}

function operationOk(operation) {
  return operation && typeof operation === 'object'
    && operation.ok === true
    && operation.supported !== false;
}

function scheduleIdFromOperation(operation) {
  if (!operation || typeof operation !== 'object') {
    return '';
  }

  const response = objectValue(operation.response);
  const record = scheduleRecordFromPayload(response);
  return firstStringValue(
    scheduleIdField(record),
    response.schedule_id,
    response.scheduleId,
    response.id,
    response.result?.schedule_id,
    response.result?.scheduleId,
    response.schedule?.schedule_id,
    response.schedule?.scheduleId,
  );
}

function scheduleRecordFromPayload(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  for (const key of ['schedule_id', 'scheduleId', 'id', 'spec', 'cadence', 'cron', 'cron_expression', 'cronExpression', 'status', 'paused']) {
    if (Object.hasOwn(value, key)) {
      return value;
    }
  }

  for (const key of ['schedule', 'record', 'result', 'data']) {
    const nested = value[key];
    if (nested && typeof nested === 'object' && !Array.isArray(nested)) {
      return scheduleRecordFromPayload(nested) ?? nested;
    }
  }

  return value;
}

function findScheduleRecordInList(value, scheduleId) {
  if (!value || typeof value !== 'object') {
    return null;
  }

  const schedules = arrayValue(value.schedules).length > 0
    ? arrayValue(value.schedules)
    : arrayValue(value.data);

  for (const schedule of schedules) {
    const record = scheduleRecordFromPayload(schedule);
    if (record && scheduleIdField(record) === scheduleId) {
      return record;
    }
  }

  return null;
}

function scheduleStateSnapshot(record) {
  const normalized = scheduleRecordFromPayload(record);
  return {
    schedule_id: scheduleIdField(normalized),
    cadence: scheduleCadenceField(normalized),
    pause_state: schedulePauseStateField(normalized),
    last_fire_at: scheduleTimestampField(normalized, ['last_fire_at', 'lastFireAt', 'last_fired_at', 'lastFiredAt', 'last_fire', 'lastFire']),
    next_fire_at: scheduleTimestampField(normalized, ['next_fire_at', 'nextFireAt', 'next_fire', 'nextFire']),
  };
}

function scheduleCadenceField(record) {
  if (!record || typeof record !== 'object') {
    return '';
  }

  const direct = firstStringValue(record.cadence, record.cron, record.cron_expression, record.cronExpression, record.interval);
  if (direct !== '') {
    return direct;
  }

  const spec = record.spec && typeof record.spec === 'object' ? record.spec : record;
  const cronExpressions = arrayValue(spec.cron_expressions ?? spec.cronExpressions);
  for (const expression of cronExpressions) {
    const normalized = stringValue(expression);
    if (normalized !== '') {
      return normalized;
    }
  }

  for (const interval of arrayValue(spec.intervals)) {
    if (interval && typeof interval === 'object') {
      const every = stringValue(interval.every);
      if (every !== '') {
        return every;
      }
    }
  }

  return '';
}

function schedulePauseStateField(record) {
  if (!record || typeof record !== 'object') {
    return '';
  }

  if (typeof record.paused === 'boolean') {
    return record.paused ? 'paused' : 'active';
  }

  const state = record.state && typeof record.state === 'object' ? record.state : {};
  if (typeof state.paused === 'boolean') {
    return state.paused ? 'paused' : 'active';
  }

  return firstStringValue(record.status, state.status).toLowerCase();
}

function scheduleTimestampField(record, names) {
  const direct = scheduleTimeField(record, names);
  if (direct !== '') {
    return direct;
  }

  const info = record && typeof record === 'object' && record.info && typeof record.info === 'object'
    ? record.info
    : {};
  return scheduleTimeField(info, names);
}

function scheduleListContains(value, scheduleId) {
  if (!value || typeof value !== 'object') {
    return false;
  }

  return findScheduleRecordInList(value, scheduleId) !== null;
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

function markArtifactSource(artifactSources, artifact, source, artifactVersions = artifactVersionsFromEnv()) {
  const current = stringValue(artifactSources[artifact]);
  if (current === '' || current === 'not_exercised') {
    const version = artifactValue(artifactVersions, artifact);
    const normalizedSource = normalizePublishedArtifactSource(artifact, version, source);
    artifactSources[artifact] = normalizedSource;
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
  const pathAndQuery = `/schedules/${encodeURIComponent(scheduleId)}/history?limit=100`;
  let result;
  try {
    result = await apiRequestResult(serverUrl, token, namespace, 'GET', pathAndQuery);
  } catch (error) {
    throw new CadenceHistoryTransportError(
      `GET ${pathAndQuery} transport failed: ${networkErrorDetail(error)}`,
    );
  }

  if (!result.ok) {
    const detail = compactLogText(result.text);
    if (result.status === 408 || result.status === 425 || result.status === 429 || result.status >= 500) {
      throw new CadenceHistoryTransportError(
        `GET ${pathAndQuery} returned transient HTTP ${result.status}: ${detail}`,
      );
    }

    throw new CadenceObservationInfrastructureError(
      `GET ${pathAndQuery} could not provide cadence history (HTTP ${result.status}): ${detail}`,
    );
  }

  return result.parsed;
}

async function scheduleHistoryResult(serverUrl, token, namespace, scheduleId) {
  return apiRequestResult(serverUrl, token, namespace, 'GET', `/schedules/${encodeURIComponent(scheduleId)}/history?limit=100`);
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

async function waitForReachableServerUrl({
  preferredUrl,
  timeoutSeconds,
  composeProject = '',
  composeFiles = [],
  serverPort = 0,
  serverImage = '',
  token = '',
  artifactVersions = {},
  startupPhase = true,
}) {
  const candidates = await serverUrlCandidates({
    preferredUrl,
    composeProject,
    composeFiles,
    serverPort,
    serverImage,
    token,
    artifactVersions,
  });
  const deadline = Date.now() + timeoutSeconds * 1000;
  const observations = new Map();

  while (Date.now() < deadline) {
    for (const candidate of candidates) {
      const observation = await probeServerReady(candidate);
      observations.set(candidate, observation.detail);
      if (observation.ready) {
        return candidate;
      }
    }

    await sleep(1000);
  }

  const details = candidates
    .map((candidate) => `${candidate}/api/ready => ${observations.get(candidate) ?? 'not probed'}`)
    .join('; ');

  const reason = `published server did not become ready; tried ${details}`;
  if (startupPhase) {
    throw new PublishedStackStartupError(reason);
  }

  throw new Error(reason);
}

async function waitForServerReady(serverUrl, timeoutSeconds) {
  await waitForReachableServerUrl({
    preferredUrl: serverUrl,
    timeoutSeconds,
    startupPhase: false,
  });
}

async function serverUrlCandidates({
  preferredUrl,
  composeProject,
  composeFiles,
  serverPort,
  serverImage,
  token,
  artifactVersions,
}) {
  const urls = [];
  const addUrl = (value) => {
    const normalized = normalizeServerUrl(value);
    if (normalized !== '' && !urls.includes(normalized)) {
      urls.push(normalized);
    }
  };

  addUrl(preferredUrl);

  if (composeProject === '') {
    return urls;
  }

  const composePort = composeProject !== ''
    ? await publishedComposeServerPort({
      composeProject,
      composeFiles,
      serverPort,
      serverImage,
      token,
      artifactVersions,
    })
    : null;
  const publishedPort = composePort?.port || serverPort;
  const explicitHost = stringValue(process.env.DW_SCHEDULES_SERVER_HOST);

  if (explicitHost !== '' && publishedPort > 0) {
    addUrl(serverUrlForHostPort(explicitHost, publishedPort));
  }

  if (composePort && composePort.host !== '' && !isWildcardHost(composePort.host)) {
    addUrl(serverUrlForHostPort(composePort.host, composePort.port));
  }

  for (const host of dockerHostGatewayCandidates()) {
    if (publishedPort > 0) {
      addUrl(serverUrlForHostPort(host, publishedPort));
    }
  }

  return urls;
}

async function publishedComposeServerPort({
  composeProject,
  composeFiles,
  serverPort,
  serverImage,
  token,
  artifactVersions,
}) {
  try {
    const result = await execFile(
      'docker',
      ['compose', '-p', composeProject, ...composeFiles, 'port', 'server', '8080'],
      {
        env: composeEnv(serverPort, serverImage, token, artifactVersions),
        maxBuffer: 1024 * 1024,
      },
    );
    return parseHostPort(String(result.stdout ?? '').trim().split(/\r?\n/)[0] ?? '');
  } catch {
    return null;
  }
}

function dockerHostGatewayCandidates() {
  const candidates = [
    '127.0.0.1',
    'localhost',
    stringValue(process.env.DW_SCHEDULES_DOCKER_HOST_GATEWAY),
    stringValue(process.env.DOCKER_HOST_GATEWAY),
    stringValue(process.env.HOST_DOCKER_INTERNAL),
    'host.docker.internal',
    'gateway.docker.internal',
    defaultRouteGateway(),
  ];

  return candidates.filter((value, index, values) => value !== '' && values.indexOf(value) === index);
}

function parseHostPort(value) {
  const normalized = stringValue(value).trim();
  if (normalized === '') {
    return null;
  }

  const bracketed = normalized.match(/^\[([^\]]+)]:(\d+)$/);
  if (bracketed) {
    return { host: bracketed[1], port: Number.parseInt(bracketed[2], 10) };
  }

  const lastColon = normalized.lastIndexOf(':');
  if (lastColon <= 0) {
    return null;
  }

  const host = normalized.slice(0, lastColon);
  const port = Number.parseInt(normalized.slice(lastColon + 1), 10);
  if (!Number.isFinite(port) || port <= 0) {
    return null;
  }

  return { host, port };
}

function isWildcardHost(host) {
  return ['0.0.0.0', '::', '[::]', '*'].includes(stringValue(host).trim());
}

function serverUrlForHostPort(host, port) {
  const normalizedHost = stringValue(host).trim();
  if (normalizedHost === '' || !Number.isFinite(port) || port <= 0) {
    return '';
  }

  const safeHost = normalizedHost.includes(':') && !normalizedHost.startsWith('[')
    ? `[${normalizedHost}]`
    : normalizedHost;

  return `http://${safeHost}:${port}`;
}

function normalizeServerUrl(value) {
  return stringValue(value).replace(/\/+$/, '');
}

function defaultRouteGateway() {
  try {
    const routes = fs.readFileSync('/proc/net/route', 'utf8').trim().split(/\r?\n/).slice(1);
    for (const route of routes) {
      const fields = route.trim().split(/\s+/);
      if (fields[1] !== '00000000') {
        continue;
      }

      const gateway = fields[2];
      if (!/^[0-9A-Fa-f]{8}$/.test(gateway)) {
        continue;
      }

      return [6, 4, 2, 0]
        .map((index) => Number.parseInt(gateway.slice(index, index + 2), 16))
        .join('.');
    }
  } catch {
    return '';
  }

  return '';
}

async function probeServerReady(serverUrl) {
  const readyUrl = `${serverUrl.replace(/\/+$/, '')}/api/ready`;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 1500);

  try {
    const response = await fetch(readyUrl, { signal: controller.signal });
    if (response.ok) {
      return { ready: true, detail: 'HTTP 200' };
    }
    const text = await response.text().catch(() => '');
    return { ready: false, detail: `HTTP ${response.status}: ${compactLogText(text)}` };
  } catch (error) {
    return { ready: false, detail: networkErrorDetail(error) };
  } finally {
    clearTimeout(timeout);
  }
}

function networkErrorDetail(error) {
  const parts = [error instanceof Error ? error.message : String(error)];
  const cause = error && typeof error === 'object' ? error.cause : null;
  if (cause && typeof cause === 'object') {
    for (const key of ['code', 'errno', 'syscall', 'address', 'port']) {
      if (Object.prototype.hasOwnProperty.call(cause, key)) {
        parts.push(`${key}=${String(cause[key])}`);
      }
    }
  }

  return parts.filter(Boolean).join(' ');
}

function isCadenceHistoryTransportFailure(error) {
  if (error instanceof CadenceHistoryTransportError) {
    return true;
  }

  const message = error instanceof Error ? error.message : String(error);
  const cause = error && typeof error === 'object' ? error.cause : null;
  const code = stringValue(cause?.code ?? error?.code).toUpperCase();
  return /fetch failed|network error|socket|connection (?:closed|reset|refused)|timed? ?out/i.test(message)
    || [
      'ECONNABORTED',
      'ECONNREFUSED',
      'ECONNRESET',
      'EHOSTUNREACH',
      'ENETDOWN',
      'ENETUNREACH',
      'ETIMEDOUT',
      'UND_ERR_CONNECT_TIMEOUT',
      'UND_ERR_HEADERS_TIMEOUT',
      'UND_ERR_SOCKET',
    ].includes(code);
}

function compactLogText(value, limit = 1000) {
  const normalized = String(value ?? '').replace(/\s+/g, ' ').trim();
  if (normalized === '') {
    return '<empty response body>';
  }

  return normalized.length > limit ? `${normalized.slice(0, limit)}...` : normalized;
}

function failureReasonWithShardLogs(error, logPrefix) {
  const reason = error instanceof Error ? error.message : String(error);
  const diagnostics = composeLogDiagnostics(logPrefix);

  return diagnostics === '' ? reason : `${reason}; compose diagnostics: ${diagnostics}`;
}

function composeLogDiagnostics(logPrefix) {
  const summaries = [];
  for (const logName of [
    'compose-up',
    'server',
    'bootstrap',
    'scheduler',
    'mysql',
    'redis',
  ]) {
    const fileName = `${logPrefix}-${logName}.log`;
    const snippet = tailLogSnippet(path.join(resultDir, fileName));
    if (snippet !== '') {
      summaries.push(`${fileName}: ${snippet}`);
    }
  }

  return summaries.slice(0, 4).join(' | ');
}

function tailLogSnippet(filePath, limit = 700) {
  let text = '';
  try {
    text = fs.readFileSync(filePath, 'utf8');
  } catch (error) {
    if (error && error.code === 'ENOENT') {
      return '';
    }
    return `unable to read ${path.basename(filePath)}: ${error instanceof Error ? error.message : String(error)}`;
  }

  const lines = text
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line !== '');

  return compactLogText(lines.slice(-12).join(' '), limit);
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

function writePublishedArtifacts(artifactVersions, artifactSources, smokeEvidence, artifactInstallEvidence = null) {
  writeJson(path.join(resultDir, 'published-artifacts.json'), {
    schema: PUBLISHED_ARTIFACTS_SCHEMA,
    generated_at: timestamp(),
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    artifact_install_evidence: artifactInstallEvidence,
    artifact_version_resolution: {
      'sdk-php': phpSdkVersionResolution,
    },
    local_product_source_checkouts_used: localProductSourceCheckoutsResultValue(smokeEvidence, artifactInstallEvidence),
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
    artifactSources: result.artifact_sources ?? {},
    localProductSourceCheckoutsUsed: result.local_product_source_checkouts_used === true,
    artifactInstallEvidence: result.artifact_install_evidence ?? null,
    artifactVersionResolution: result.artifact_version_resolution ?? {},
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
  return ['string', 'number', 'boolean'].includes(typeof value) ? String(value).trim() : '';
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

export {
  CadenceObservationInfrastructureError,
  cadenceBlockedEvidence,
  cadenceEvidenceFromObservations,
  latestStableComposerVersion,
  missedFireOutageWaitMilliseconds,
  observeCadence,
  observeMissedFirePolicy,
  schedulesPhpSurfaceProbeScript,
  schedulesPhpWorkerScript,
};
