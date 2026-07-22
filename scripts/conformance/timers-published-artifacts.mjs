#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { isExactPythonRelease, isExactSemverRelease } from './version-identities.mjs';

const [resultDirArg, startedAtArg, manifestPathArg, repoRootArg] = process.argv.slice(2);

if (!resultDirArg || !startedAtArg || !manifestPathArg || !repoRootArg) {
  console.error('usage: timers-published-artifacts.mjs <result-dir> <started-at> <manifest-path> <repo-root>');
  process.exit(2);
}

const RESULT_DIR = resultDirArg;
const STARTED_AT = startedAtArg;
const MANIFEST_PATH = manifestPathArg;
const REPO_ROOT = repoRootArg;

const RESULT_SCHEMA = 'durable-workflow.v2.timer-runtime.result';
const RECORD_SCHEMA = 'durable-workflow.v2.timer-runtime.published-artifacts';
const RUNNER_PATH = 'scripts/conformance/timers-published-artifacts.sh';
const SCENARIO_MANIFEST = 'static/platform-conformance/timer-runtime-scenarios.json';

const SHA256_DIGEST_RE = /^sha256:[0-9a-fA-F]{64}$/;
const PLACEHOLDER_RE = /(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$))/i;
const PUBLISHED_SERVER_IMAGE_REPOSITORIES = new Set([
  'durableworkflow/server',
  'docker.io/durableworkflow/server',
  'index.docker.io/durableworkflow/server',
  'registry-1.docker.io/durableworkflow/server',
  'ghcr.io/durable-workflow/server',
]);

const FALLBACK_SCENARIOS = [
  {
    id: 'normal_sleep_completion',
    description: 'A workflow sleep/timer completes only after the recorded wake-up deadline.',
    required_evidence: ['workflow_id', 'sleep_requested_at', 'wake_up_at', 'completed_at', 'workflow_result'],
    required_behavior: 'workflow_sleep_completes_after_recorded_wake_up_without_early_resume',
  },
  {
    id: 'worker_restart_while_sleeping',
    description: 'A sleeping workflow survives worker restart without dropped or duplicate timer resume.',
    required_evidence: ['workflow_id', 'sleep_started_at', 'worker_restart_window', 'wake_up_at', 'completed_at', 'timer_fire_count', 'duplicate_resume_count'],
    required_behavior: 'worker_restart_does_not_drop_or_duplicate_a_sleeping_timer',
  },
  {
    id: 'server_restart_while_sleeping',
    description: 'A sleeping workflow survives server restart and recovers waiting timer state.',
    required_evidence: ['workflow_id', 'sleep_started_at', 'server_restart_window', 'wake_up_at', 'completed_at', 'timer_state_recovered', 'timer_fire_count', 'duplicate_resume_count'],
    required_behavior: 'server_restart_recovers_waiting_timer_state_and_completes_after_wake_up',
  },
  {
    id: 'replay_after_timer_fire',
    description: 'Replay after a timer has fired is deterministic and does not schedule duplicate timers.',
    required_evidence: ['workflow_id', 'timer_id', 'fired_at', 'replay_started_at', 'replayed_event_ids', 'replayed_event_types', 'duplicate_timer_commands'],
    required_behavior: 'replay_after_timer_fire_is_deterministic_and_does_not_schedule_duplicate_timers',
  },
  {
    id: 'concurrent_timers_distinct_deadlines',
    description: 'Concurrent timers with distinct wake-up deadlines resume in deadline order.',
    required_evidence: ['wake_up_times', 'observed_resume_order', 'fired_at_times', 'fire_counts'],
    required_behavior: 'resume_order_matches_wake_up_times_no_early_fires_no_duplicate_fires',
  },
  {
    id: 'cancellation_while_waiting',
    description: 'Cancelling while waiting prevents timer fire after cancellation.',
    required_evidence: ['cancellation_requested_at', 'wake_up_at', 'fired_after_cancel', 'workflow_status'],
    required_behavior: 'cancellation_requested_before_recorded_wake_up_and_timer_never_fires_after_cancel',
  },
  {
    id: 'operator_visible_timer_waiting_state',
    description: 'Operators can observe an explicit waiting or timer-waiting state.',
    required_evidence: ['status', 'surface'],
    required_behavior: 'operators_can_observe_an_explicit_waiting_or_timer_waiting_state_on_a_public_surface',
  },
];

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function env(name) {
  return (process.env[name] ?? '').trim();
}

function writeJson(file, value) {
  fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function loadManifest() {
  if (!fs.existsSync(MANIFEST_PATH)) {
    return {};
  }

  const decoded = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'));
  return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
}

function loadJsonPath(file) {
  if (!file || !fs.existsSync(file)) {
    return null;
  }

  const decoded = JSON.parse(fs.readFileSync(file, 'utf8'));
  return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : null;
}

function loadEvidence() {
  const inline = env('DW_TIMERS_EVIDENCE');
  if (inline) {
    const decoded = JSON.parse(inline);
    return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : null;
  }

  return loadJsonPath(env('DW_TIMERS_EVIDENCE_PATH'))
    ?? loadJsonPath(path.join(RESULT_DIR, 'timer-evidence.json'));
}

function normalizeCliVersion(value) {
  return value.startsWith('v') && SEMVER_RE.test(value.slice(1)) ? value.slice(1) : value;
}

function normalizeDockerImageReference(image) {
  return image.trim().replace(/^docker:\/\//, '');
}

function serverRepositoryFromImage(image) {
  const withoutDigest = normalizeDockerImageReference(image).split('@', 1)[0];
  const tail = withoutDigest.split('/').pop() ?? withoutDigest;
  if (tail.includes(':')) {
    return withoutDigest.slice(0, withoutDigest.lastIndexOf(':'));
  }
  return withoutDigest;
}

function serverTagFromImage(image) {
  const withoutDigest = normalizeDockerImageReference(image).split('@', 1)[0];
  const tail = withoutDigest.split('/').pop() ?? withoutDigest;
  if (!tail.includes(':')) {
    return null;
  }
  return tail.slice(tail.lastIndexOf(':') + 1);
}

function isDigestPinnedServerImage(image) {
  const normalized = normalizeDockerImageReference(image);
  if (!normalized.includes('@')) {
    return false;
  }
  return SHA256_DIGEST_RE.test(normalized.slice(normalized.lastIndexOf('@') + 1));
}

function deriveServerVersion(serverImage, explicitVersion) {
  if (explicitVersion) {
    return explicitVersion;
  }

  const tag = serverTagFromImage(serverImage);
  return tag && isExactSemverRelease(tag) ? tag : '';
}

function isPlaceholder(value) {
  const normalized = value.toLowerCase();
  return Boolean(
    value
      && (PLACEHOLDER_RE.test(normalized)
        || ['latest', 'current', 'head', 'unresolved', 'placeholder'].includes(normalized)),
  );
}

function exactPinFailures(versions, serverImage) {
  const failures = [];
  const required = {
    server: 'DW_SERVER_VERSION or exact DW_SERVER_IMAGE tag',
    cli: 'DW_CLI_VERSION',
    'sdk-python': 'DW_PYTHON_SDK_VERSION',
    workflow: 'DW_WORKFLOW_PHP_VERSION',
    waterline: 'DW_WATERLINE_VERSION',
  };

  if (!serverImage) {
    failures.push('DW_SERVER_IMAGE is required so timer conformance names the pinned published server image');
  } else if (isPlaceholder(serverImage) && !isDigestPinnedServerImage(serverImage)) {
    failures.push('DW_SERVER_IMAGE must not be a placeholder or floating tag');
  }

  for (const [artifact, label] of Object.entries(required)) {
    const version = versions[artifact] ?? '';
    if (!version) {
      failures.push(`${label} is required`);
      continue;
    }
    if (isPlaceholder(version)) {
      failures.push(`${label} must not be a placeholder value (${version})`);
      continue;
    }
    const exactVersion = artifact === 'sdk-python'
      ? isExactPythonRelease(version)
      : isExactSemverRelease(version);
    if (!exactVersion) {
      failures.push(`${label} must be an exact semver release (${version})`);
    }
  }

  return failures;
}

function serverPinRefusalFailures(serverImage, serverVersion) {
  const failures = [];
  if (!serverImage) {
    return ['DW_SERVER_VERSION or DW_SERVER_IMAGE is required so timer conformance can name the pinned published server image'];
  }

  const normalizedImage = normalizeDockerImageReference(serverImage);
  const repository = serverRepositoryFromImage(normalizedImage);
  const tag = serverTagFromImage(normalizedImage);
  const digestPinned = isDigestPinnedServerImage(normalizedImage);

  if (!serverVersion || !isExactSemverRelease(serverVersion) || isPlaceholder(serverVersion)) {
    failures.push(`DW_SERVER_VERSION must be an exact SemVer Docker tag; got ${JSON.stringify(serverVersion)}`);
  }

  if (isPlaceholder(normalizedImage) && !digestPinned) {
    failures.push(`DW_SERVER_IMAGE must not use a rolling tag or placeholder; got ${JSON.stringify(serverImage)}`);
  }

  if (!PUBLISHED_SERVER_IMAGE_REPOSITORIES.has(repository)) {
    failures.push('DW_SERVER_IMAGE is not a durableworkflow/server published image reference');
  }

  if (normalizedImage.includes('@') && !digestPinned) {
    failures.push('DW_SERVER_IMAGE digest must be a sha256 digest-pinned reference');
  }

  if (!digestPinned) {
    if (!tag || !isExactSemverRelease(tag)) {
      failures.push(`DW_SERVER_IMAGE must use an exact SemVer tag or an image digest; got ${JSON.stringify(serverImage)}`);
    } else if (serverVersion && tag !== serverVersion) {
      failures.push(`DW_SERVER_VERSION ${JSON.stringify(serverVersion)} does not match DW_SERVER_IMAGE tag ${JSON.stringify(tag)}`);
    }
  } else if (tag && isExactSemverRelease(tag) && serverVersion && tag !== serverVersion) {
    failures.push(`DW_SERVER_VERSION ${JSON.stringify(serverVersion)} does not match DW_SERVER_IMAGE tag ${JSON.stringify(tag)}`);
  }

  return failures;
}

function scenarioDefs(manifest) {
  return Array.isArray(manifest.scenarios) && manifest.scenarios.length > 0
    ? manifest.scenarios.filter((scenario) => scenario && typeof scenario === 'object' && !Array.isArray(scenario))
    : FALLBACK_SCENARIOS;
}

function slug(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'scenario';
}

function publicSources(artifactVersions, serverImage) {
  const workflowVersion = artifactVersions.workflow;
  return {
    server: serverImage,
    cli: `github-release:durable-workflow/cli:${artifactVersions.cli}`,
    'sdk-python': `pypi:durable-workflow==${artifactVersions['sdk-python']}`,
    workflow: `packagist:durable-workflow/workflow:${workflowVersion}`,
    'workflow-php': `packagist:durable-workflow/workflow:${workflowVersion}`,
    waterline: `packagist:durable-workflow/waterline:${artifactVersions.waterline}`,
  };
}

function findingFor(scenario, artifactVersions, artifactSources, runnerBlocked, reason) {
  const scenarioId = String(scenario.id ?? '');
  const classification = runnerBlocked ? 'runner-gap' : 'coverage-gap';
  const findingId = `timer-${slug(scenarioId)}-${classification}`;
  const observed = runnerBlocked
    ? `timer conformance could not execute from the published-artifact handoff before coverage evidence was collected: ${reason}`
    : 'the published server image exposes the timer handoff, but the first-class timer scenario shard has not yet produced runtime evidence for this cell; the result is recorded as non-passing coverage-gap evidence';
  const nextStep = runnerBlocked
    ? 'provide exact published artifact pins and rerun the timer conformance handoff from the published server image'
    : 'implement this timer scenario shard against published artifacts, then replace this coverage-gap finding with passing runtime evidence or a focused product finding';

  return {
    id: findingId,
    scenario_id: scenarioId,
    finding_type: 'conformance_runner_coverage_gap',
    classification,
    root_cause_classification: classification,
    owning_surface: 'conformance_harness',
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    expected_behavior: String(
      scenario.required_behavior
        ?? scenario.description
        ?? 'timer behavior is proven against published artifacts',
    ),
    observed_behavior: observed,
    user_visible_reproduction_steps: [
      'Run scripts/conformance/timers-published-artifacts.sh --result-dir <result-dir> from the pinned published server image.',
      'Set exact DW_SERVER_IMAGE, DW_SERVER_VERSION, DW_CLI_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.',
      'Inspect timer-runtime-result.json and timer-runtime-record.json for the scenario status and linked finding.',
    ],
    next_acceptance_criterion: nextStep,
    priority: runnerBlocked ? 'P0' : 'P1',
  };
}

function productFindingFor(scenario, scenarioResult, artifactVersions, artifactSources) {
  const scenarioId = String(scenario.id ?? '');
  const observedOutputs = objectValue(scenarioResult.observed_outputs)
    ?? objectValue(scenarioResult.observedOutputs)
    ?? {};
  const observedBehavior = stringValue(scenarioResult.observed_behavior)
    || stringValue(scenarioResult.observedBehavior)
    || stringValue(observedOutputs.failure)
    || `the published-artifact ${scenarioId} shard executed but did not prove the required timer behavior`;

  return {
    id: `timer-${slug(scenarioId)}-product-gap`,
    scenario_id: scenarioId,
    finding_type: 'timer_runtime_product_gap',
    classification: 'product-gap',
    root_cause_classification: 'product-gap',
    owning_surface: stringValue(scenarioResult.owning_surface)
      || stringValue(scenarioResult.owningSurface)
      || 'timer_runtime',
    artifact_versions: artifactVersions,
    artifact_sources: artifactSources,
    expected_behavior: String(
      scenario.required_behavior
        ?? scenario.description
        ?? 'timer behavior is proven against published artifacts',
    ),
    observed_behavior: observedBehavior,
    user_visible_reproduction_steps: [
      'Run scripts/conformance/timers-published-artifacts.sh --result-dir <result-dir> from the pinned published server image.',
      'Set exact DW_SERVER_IMAGE, DW_SERVER_VERSION, DW_CLI_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.',
      `Inspect timer-runtime-result.json for ${scenarioId} observed_outputs.`,
    ],
    next_acceptance_criterion: `fix the timer runtime behavior, then rerun the ${scenarioId} shard against published artifacts and require the scenario status to pass`,
    priority: 'P0',
  };
}

function scenarioResult(scenario, finding, artifactSources, runnerBlocked, reason) {
  const status = runnerBlocked ? 'runner_blocked' : 'not_covered';
  const classification = runnerBlocked ? 'runner-gap' : 'coverage-gap';
  const scenarioId = String(scenario.id ?? '');

  return {
    scenario_id: scenarioId,
    status,
    classification,
    expected_behavior: String(scenario.required_behavior ?? scenario.description ?? ''),
    required_evidence: Array.isArray(scenario.required_evidence) ? scenario.required_evidence : [],
    observed_outputs: {
      evidence_status: status,
      classification,
      coverage_gap_reason: runnerBlocked ? '' : reason,
      blocked_reason: runnerBlocked ? reason : '',
      cell_unproven: true,
      published_artifact_sources: artifactSources,
      no_local_product_source_checkout_pass_evidence: true,
    },
    linked_findings: [
      {
        finding_id: finding.id,
        finding_type: finding.finding_type,
        classification: finding.classification,
      },
    ],
  };
}

function scenarioResultsFromEvidence(evidence) {
  const raw = arrayValue(evidence?.scenario_results)
    ?? arrayValue(evidence?.scenarioResults)
    ?? arrayValue(evidence?.scenarios)
    ?? [];
  const results = new Map();

  for (const entry of raw) {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
      continue;
    }

    const scenarioId = stringValue(entry.scenario_id) || stringValue(entry.scenarioId) || stringValue(entry.id);
    if (scenarioId) {
      results.set(scenarioId, entry);
    }
  }

  return results;
}

function focusedScenarioResult(scenario, supplied, artifactSources, productFinding) {
  const scenarioId = String(scenario.id ?? '');
  const status = normalizeStatus(supplied.status);
  const observedOutputs = objectValue(supplied.observed_outputs)
    ?? objectValue(supplied.observedOutputs)
    ?? {};
  const base = {
    scenario_id: scenarioId,
    status,
    classification: status === 'pass' ? null : 'product-gap',
    expected_behavior: String(scenario.required_behavior ?? scenario.description ?? ''),
    required_evidence: Array.isArray(scenario.required_evidence) ? scenario.required_evidence : [],
    observed_outputs: {
      ...observedOutputs,
      published_artifact_sources: artifactSources,
      evidence_source: stringValue(supplied.evidence_source)
        || stringValue(supplied.evidenceSource)
        || stringValue(observedOutputs.evidence_source)
        || 'focused_published_server_timer_host_probe',
      no_local_product_source_checkout_pass_evidence: true,
    },
  };

  if (status === 'pass') {
    return base;
  }

  return {
    ...base,
    linked_findings: [
      {
        finding_id: productFinding.id,
        finding_type: productFinding.finding_type,
        classification: productFinding.classification,
      },
    ],
  };
}

function objectValue(value) {
  return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
}

function arrayValue(value) {
  return Array.isArray(value) ? value : null;
}

function stringValue(value) {
  return typeof value === 'string' ? value.trim() : '';
}

function normalizeStatus(value) {
  const status = stringValue(value);
  return ['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked'].includes(status)
    ? status
    : 'fail';
}

function main() {
  fs.mkdirSync(RESULT_DIR, { recursive: true });

  const manifest = loadManifest();
  const evidence = loadEvidence();
  const evidenceResults = scenarioResultsFromEvidence(evidence);
  const scenarios = scenarioDefs(manifest);
  const suiteSchema = String(manifest.suite_schema ?? 'durable-workflow.v2.platform-conformance.suite');
  const suiteVersion = manifest.suite_version ?? null;

  let serverImage = env('DW_SERVER_IMAGE');
  const serverVersion = deriveServerVersion(serverImage, env('DW_SERVER_VERSION'));
  if (serverVersion && !serverImage) {
    serverImage = `durableworkflow/server:${serverVersion}`;
  }

  const serverPinFailures = serverPinRefusalFailures(serverImage, serverVersion);
  if (serverPinFailures.length > 0) {
    console.error(JSON.stringify({
      schema: 'durable-workflow.v2.timer-runtime.server-pin-refusal',
      runner: RUNNER_PATH,
      server_image: serverImage,
      server_version: serverVersion,
      failures: serverPinFailures,
    }, null, 2));
    process.exit(2);
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
  const artifactSources = publicSources(artifactVersions, serverImage);
  const pinFailures = exactPinFailures(artifactVersions, serverImage);
  const runnerBlocked = pinFailures.length > 0;
  const reason = runnerBlocked
    ? pinFailures.join('; ')
    : 'first-class timer scenario shards without focused runtime evidence are not yet implemented for the published-artifact handoff';
  const finishedAt = now();
  const generatedAt = now();
  const runnerSource = env('DW_TIMERS_RUNNER_SOURCE') || serverImage;

  const findings = [];
  const scenarioResults = {};
  for (const scenario of scenarios) {
    const scenarioId = String(scenario.id ?? '');
    if (!scenarioId) {
      continue;
    }

    const supplied = evidenceResults.get(scenarioId) ?? null;
    if (supplied && !runnerBlocked) {
      const status = normalizeStatus(supplied.status);
      if (status === 'pass') {
        scenarioResults[scenarioId] = focusedScenarioResult(scenario, supplied, artifactSources, null);
        continue;
      }

      const productFinding = productFindingFor(scenario, supplied, publishedArtifactVersions, artifactSources);
      findings.push(productFinding);
      scenarioResults[scenarioId] = focusedScenarioResult(scenario, supplied, artifactSources, productFinding);
      continue;
    }

    const finding = findingFor(scenario, publishedArtifactVersions, artifactSources, runnerBlocked, reason);
    findings.push(finding);
    scenarioResults[scenarioId] = scenarioResult(scenario, finding, artifactSources, runnerBlocked, reason);
  }

  const findingLinks = Object.fromEntries(findings.map((finding) => [finding.scenario_id, [finding.id]]));
  const unprovenCells = Object.values(scenarioResults)
    .filter((result) => ['not_covered', 'runner_blocked'].includes(result.status))
    .map((result) => result.scenario_id)
    .filter(Boolean);
  const provenCells = Object.values(scenarioResults)
    .filter((result) => ['pass', 'fail'].includes(result.status))
    .map((result) => result.scenario_id)
    .filter(Boolean);
  const allRequiredScenariosPass = Object.values(scenarioResults).length === scenarios.length
    && Object.values(scenarioResults).every((result) => result.status === 'pass');
  const outcome = runnerBlocked
    ? 'runner_blocked'
    : (allRequiredScenariosPass && findings.length === 0 && unprovenCells.length === 0 ? 'pass' : 'non_passing');
  const sourcePolicy = {
    published_artifacts_only: true,
    local_product_source_checkout_used_as_pass_evidence: false,
    no_local_product_source_checkout_pass_evidence: true,
  };
  const resultSummary = {
    status: outcome === 'pass' ? 'pass' : 'non_passing',
    classification: outcome === 'pass'
      ? null
      : (runnerBlocked
        ? 'runner-gap'
        : (findings.some((finding) => finding.classification === 'product-gap') ? 'product-gap' : 'coverage-gap')),
    scenario_count: Object.keys(scenarioResults).length,
    finding_count: findings.length,
  };
  const metadata = {
    schema: 'durable-workflow.v2.timer-runtime.run-metadata',
    version: 1,
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: generatedAt,
    runner: {
      repository: 'server',
      path: RUNNER_PATH,
      command: `${RUNNER_PATH} --result-dir <result-dir>`,
      source: runnerSource,
      repo_root: REPO_ROOT,
      repo_root_contains_git: fs.existsSync(path.join(REPO_ROOT, '.git')),
    },
    scenario_manifest: SCENARIO_MANIFEST,
    scenario_manifest_path: MANIFEST_PATH,
    source_policy: sourcePolicy,
  };

  const result = {
    schema: RESULT_SCHEMA,
    version: Number(manifest.result_version ?? 1),
    suite_schema: suiteSchema,
    suite_version: suiteVersion,
    scenario_manifest: SCENARIO_MANIFEST,
    runner: metadata.runner,
    started_at: STARTED_AT,
    finished_at: finishedAt,
    generated_at: generatedAt,
    outcome,
    runner_blocked: runnerBlocked,
    artifact_versions: publishedArtifactVersions,
    published_artifact_versions: publishedArtifactVersions,
    artifact_sources: artifactSources,
    public_artifact_sources: artifactSources,
    artifact_images: {
      server: serverImage,
    },
    source_policy: sourcePolicy,
    no_local_product_source_checkout_pass_evidence: true,
    unproven_timer_cells: unprovenCells,
    proven_timer_cells: provenCells,
    result_summary: resultSummary,
    scenario_results: scenarioResults,
    findings,
    finding_links: findingLinks,
  };
  const record = {
    schema: RECORD_SCHEMA,
    version: 1,
    result_schema: RESULT_SCHEMA,
    result_file: 'timer-runtime-result.json',
    record_file: 'timer-runtime-record.json',
    outcome,
    runnerBlocked: runnerBlocked,
    runner_blocked: runnerBlocked,
    artifactVersions: publishedArtifactVersions,
    artifact_versions: publishedArtifactVersions,
    artifact_sources: artifactSources,
    artifact_images: {
      server: serverImage,
    },
    public_artifact_sources: artifactSources,
    source_policy: sourcePolicy,
    no_local_product_source_checkout_pass_evidence: true,
    unproven_timer_cells: unprovenCells,
    proven_timer_cells: provenCells,
    result_summary: resultSummary,
    result,
  };

  writeJson(path.join(RESULT_DIR, 'pins.json'), publishedArtifactVersions);
  writeJson(path.join(RESULT_DIR, 'run-metadata.json'), metadata);
  writeJson(path.join(RESULT_DIR, 'timer-runtime-result.json'), result);
  writeJson(path.join(RESULT_DIR, 'timer-runtime-record.json'), record);
  writeJson(path.join(RESULT_DIR, 'timer-runtime-findings.json'), findings);
  writeJson(path.join(RESULT_DIR, 'timers-result.json'), result);
  writeJson(path.join(RESULT_DIR, 'timers-record.json'), record);

  console.log(JSON.stringify({
    result: path.join(RESULT_DIR, 'timer-runtime-result.json'),
    record: path.join(RESULT_DIR, 'timer-runtime-record.json'),
    outcome,
  }));
}

main();
