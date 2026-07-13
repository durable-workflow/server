import fs from 'node:fs';
import path from 'node:path';

const RESULT_DIR = mustEnv('RESULT_DIR');
const STARTED_AT = mustEnv('STARTED_AT');
const MANIFEST_PATH = mustEnv('MANIFEST_PATH');

const RESULT_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.result';
const RECORD_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.published-artifacts';
const SEMVER_RE = /^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/;
const SERVER_PATCH_TAG_RE = /^\d+\.\d+\.\d+$/;
const SHA256_DIGEST_RE = /^sha256:[0-9a-fA-F]{64}$/;
const PLACEHOLDER_RE = /(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])(latest|current|head|main|master|unresolved|placeholder)([^a-z0-9]|$))/i;
const ALLOWED_STATUSES = new Set(['pass', 'fail', 'unsupported', 'not_covered', 'runner_blocked']);
const ALLOWED_CLASSIFICATIONS = new Set([
  'product-gap',
  'coverage-gap',
  'runner-gap',
  'stale-artifact',
  'pipeline-churn',
]);
const REQUIRED_ARTIFACTS = ['server', 'cli', 'workflow', 'sdk-python', 'sdk-rust', 'waterline'];
const FORBIDDEN_SOURCE_TOKENS = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'source_checkout',
  'local_checkout',
];
const RUST_SIDECAR_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.rust-sdk-sidecar';
const RUST_SIDECAR_RUNNER = 'published-rust-sdk-lifecycle-surface-probe';
const RUST_SCENARIO_ID = 'rust_sdk_lifecycle_surface';
const STABLE_REASON_RE = /^[a-z0-9][a-z0-9_]{0,95}$/;
const FAILURE_MESSAGE_LIMIT = 512;
const FORBIDDEN_FAILURE_FIELD_RE = /(authorization|credential|password|passwd|secret|token|api[_-]?key|std(?:out|err)|process[_-]?output|command[_-]?output|logs?)/i;
const RUST_RUNNER_REASONS = new Set([
  'rust_executor_unavailable',
  'rust_sdk_probe_launch_failed',
  'rust_sdk_probe_output_contract_invalid',
  'rust_sdk_probe_artifact_mismatch',
  'rust_sdk_runner_command_failed',
  'rust_sdk_runner_setup_failed',
]);
const SCENARIO_REQUIREMENTS = {
  continue_as_new_run_chain_visibility: {
    description: 'Continue-as-new creates a visible run chain under one logical workflow id, with distinct run ids and monotonic run numbers.',
    required_evidence: ['workflow_id', 'initial_run_id', 'continued_run_id', 'run_count', 'current_run_id', 'run_numbers'],
    required_behavior: 'continue_as_new_creates_a_visible_run_chain_under_one_logical_workflow_id',
  },
  continue_as_new_identity_and_history_continuity: {
    description: 'History and run-list surfaces link the predecessor and successor runs without losing logical workflow identity.',
    required_evidence: ['workflow_id', 'history_events', 'predecessor_closed_event', 'successor_started_event', 'history_api_links'],
    required_behavior: 'history_surfaces_link_predecessor_and_successor_runs_without_losing_logical_identity',
  },
  continue_as_new_duplicate_side_effect_prevention: {
    description: 'Replay, restart, and continue-as-new boundaries do not duplicate externally visible side effects.',
    required_evidence: ['workflow_id', 'side_effect_key', 'expected_count', 'observed_count', 'replay_or_restart_window'],
    required_behavior: 'continue_as_new_replay_or_restart_does_not_duplicate_side_effects',
  },
  cancellation_public_surface_terminal_state: {
    description: 'Cancellation requested through public surfaces reaches a documented terminal state and produces typed observable errors for workers and callers.',
    required_evidence: ['workflow_id', 'request_surface', 'cancel_requested_at', 'terminal_status', 'worker_error_type', 'caller_error_type'],
    required_behavior: 'public_cancellation_reaches_cancelled_and_surfaces_typed_worker_and_caller_errors',
  },
  termination_public_surface_terminal_state: {
    description: 'Termination requested through public surfaces reaches a documented terminal state and produces typed observable errors for workers and callers.',
    required_evidence: ['workflow_id', 'request_surface', 'terminate_requested_at', 'terminal_status', 'worker_error_type', 'caller_error_type'],
    required_behavior: 'public_termination_reaches_terminated_and_surfaces_typed_worker_and_caller_errors',
  },
  workflow_id_reuse_duplicate_start_policy: {
    description: 'Workflow id reuse and duplicate start policy are enforced or unsupported shapes are refused with a documented typed reason.',
    required_evidence: ['workflow_id', 'duplicate_policy', 'first_start_outcome', 'first_run_id', 'duplicate_start_outcome', 'http_status_or_error_type', 'run_count_after_duplicate', 'run_ids_after_duplicate'],
    required_behavior: 'duplicate_workflow_id_start_fail_policy_refuses_the_duplicate_and_preserves_only_the_first_run',
  },
  workflow_timeout_terminal_state: {
    description: 'Workflow execution or run timeout records operator-visible deadline timing and terminal state.',
    required_evidence: ['workflow_id', 'timeout_field', 'deadline_at', 'observed_terminal_at', 'terminal_status', 'operator_visible_timing', 'unsupported_timeout_shape_refusals'],
    required_behavior: 'workflow_execution_or_run_timeout_records_deadline_timing_and_terminal_state',
  },
  workflow_retry_backoff_or_refusal: {
    description: 'Workflow-level retry/backoff is proven where supported; unsupported retry cells refuse clearly and match public documentation.',
    required_evidence: ['workflow_id', 'retry_policy_shape', 'attempt_count_or_refusal_reason', 'backoff_observation_or_error_type', 'docs_match'],
    required_behavior: 'workflow_retry_backoff_is_executed_where_supported_or_retry_policy_is_refused_clearly',
  },
  php_sdk_lifecycle_surface: {
    description: 'The published PHP workflow package can exercise supported lifecycle cells, or unsupported cells produce documented typed errors.',
    required_evidence: ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version'],
    required_behavior: 'php_sdk_exercises_supported_lifecycle_cells_or_refuses_unsupported_cells_with_typed_errors',
  },
  python_sdk_lifecycle_surface: {
    description: 'The published Python SDK can exercise supported lifecycle cells, or unsupported cells produce documented typed errors.',
    required_evidence: ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version'],
    required_behavior: 'python_sdk_exercises_supported_lifecycle_cells_or_refuses_unsupported_cells_with_typed_errors',
  },
  rust_sdk_lifecycle_surface: {
    description: 'The exact released Rust SDK crate exercises lifecycle behavior against the matching published server image with official Avro payload provenance.',
    required_evidence: ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version', 'server_version', 'server_cluster_info', 'install_provenance', 'workflow_identities', 'scenario_outcomes', 'stable_reasons', 'payload_contract', 'executor_topology', 'rust_shard_contract_version', 'shard_runner', 'shard_exit_status'],
    required_behavior: 'rust_sdk_exact_crate_exercises_lifecycle_against_the_matching_published_server_image',
  },
  operator_diagnostics_surfaces: {
    description: 'CLI, API, history, and Waterline-visible state expose enough information for operators and agents to diagnose every lifecycle transition.',
    required_evidence: ['workflow_id', 'cli_fields', 'api_fields', 'history_fields', 'waterline_fields', 'diagnostic_transition_matrix'],
    required_behavior: 'cli_api_history_and_waterline_expose_enough_state_to_diagnose_every_lifecycle_transition',
  },
};

function mustEnv(name) {
  const value = env(name);
  if (!value) {
    throw new Error(`${name} is required`);
  }

  return value;
}

function env(name) {
  return (process.env[name] ?? '').trim();
}

function now() {
  return new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
}

function writeJson(file, value) {
  fs.writeFileSync(path.join(RESULT_DIR, file), `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

function readJson(file) {
  const decoded = JSON.parse(fs.readFileSync(file, 'utf8'));
  return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : null;
}

function loadManifest() {
  if (!MANIFEST_PATH || !fs.existsSync(MANIFEST_PATH)) {
    return {};
  }

  return readJson(MANIFEST_PATH) ?? {};
}

function loadEvidence() {
  const inline = env('DW_WORKFLOW_LIFECYCLE_EVIDENCE');
  if (inline) {
    const decoded = JSON.parse(inline);
    return {
      source: 'DW_WORKFLOW_LIFECYCLE_EVIDENCE',
      value: decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {},
    };
  }

  const explicitPath = env('DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH');
  const defaultPath = path.join(RESULT_DIR, 'workflow-lifecycle-evidence.json');
  const evidencePath = explicitPath || (fs.existsSync(defaultPath) ? defaultPath : '');
  if (evidencePath) {
    return {
      source: evidencePath,
      value: readJson(evidencePath) ?? {},
    };
  }

  return {
    source: 'not_supplied',
    value: {},
  };
}

function redactSensitiveText(value, limit = FAILURE_MESSAGE_LIMIT) {
  let text = stringValue(value)
    .replace(/[\u0000-\u001f\u007f]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  for (const name of [
    'DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN',
    'DURABLE_WORKFLOW_TOKEN',
    'DW_TOKEN',
    'APP_KEY',
  ]) {
    const secret = stringValue(process.env[name]);
    if (secret) {
      text = text.split(secret).join('[REDACTED]');
    }
  }
  text = text
    .replace(/(authorization\s*[:=]\s*(?:bearer\s+)?)[^\s,;]+/ig, '$1[REDACTED]')
    .replace(/((?:credential|password|passwd|secret|token|api[_-]?key)\s*[:=]\s*)[^\s,;]+/ig, '$1[REDACTED]')
    .replace(/(https?:\/\/)[^\s/@:]+:[^\s/@]+@/ig, '$1[REDACTED]@');
  return text.slice(0, limit);
}

function boundedRustValue(value, depth = 0) {
  if (depth > 6 || value === null || value === undefined) {
    return value ?? null;
  }
  if (typeof value === 'string') {
    return redactSensitiveText(value, 512);
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return value;
  }
  if (Array.isArray(value)) {
    return value.slice(0, 32).map((entry) => boundedRustValue(entry, depth + 1));
  }
  if (typeof value !== 'object') {
    return null;
  }

  const result = {};
  for (const [key, entry] of Object.entries(value).slice(0, 48)) {
    if (!FORBIDDEN_FAILURE_FIELD_RE.test(key)) {
      result[key] = boundedRustValue(entry, depth + 1);
    }
  }
  return result;
}

function rustArtifactMismatch(outputs) {
  const sdkVersion = env('DW_RUST_SDK_VERSION');
  const serverVersion = env('DW_SERVER_VERSION');
  const provenance = outputs.install_provenance;
  return stringValue(outputs.artifact_version) !== sdkVersion
    || stringValue(outputs.server_version) !== serverVersion
    || !provenance
    || typeof provenance !== 'object'
    || Array.isArray(provenance)
    || stringValue(provenance.package) !== 'durable-workflow'
    || stringValue(provenance.requested_version) !== sdkVersion
    || stringValue(provenance.installed_version) !== sdkVersion
    || !stringValue(provenance.registry_source).includes('crates.io')
    || !/^[0-9a-f]{64}$/.test(stringValue(provenance.registry_checksum_sha256));
}

function validatedRustFailureEvidence(outputs, stableReason) {
  const failureMessage = redactSensitiveText(outputs.failure_message);
  const failingCell = stringValue(outputs.failing_lifecycle_cell);
  const scenarioOutcomes = outputs.scenario_outcomes;
  const failingOutcome = scenarioOutcomes
    && typeof scenarioOutcomes === 'object'
    && !Array.isArray(scenarioOutcomes)
    ? scenarioOutcomes[failingCell]
    : null;
  const valid = STABLE_REASON_RE.test(stableReason)
    && STABLE_REASON_RE.test(failingCell)
    && failureMessage !== ''
    && failingOutcome
    && typeof failingOutcome === 'object'
    && !Array.isArray(failingOutcome)
    && failingOutcome.status === 'fail'
    && failingOutcome.stable_reason === stableReason
    && redactSensitiveText(failingOutcome.observed_behavior) !== '';

  return valid ? { failureMessage, failingCell } : null;
}

function invalidRustScenario(stableReason) {
  return {
    scenario_id: RUST_SCENARIO_ID,
    status: 'runner_blocked',
    classification: 'runner-gap',
    published_artifact_cell_executed: false,
    observed_outputs: {
      stable_reason: stableReason,
      published_artifact_cell_executed: false,
    },
  };
}

function normalizeRustRunnerFailure(outputs, stableReason, exitStatus) {
  const boundedOutputs = boundedRustValue(outputs);
  boundedOutputs.stable_reason = stableReason;
  boundedOutputs.published_artifact_cell_executed = false;
  boundedOutputs.shard_exit_status = exitStatus;
  const summary = redactSensitiveText(
    outputs.failure_message || `Rust lifecycle runner stopped with ${stableReason}.`,
  );
  return {
    scenario: {
      scenario_id: RUST_SCENARIO_ID,
      status: 'runner_blocked',
      classification: 'runner-gap',
      published_artifact_cell_executed: false,
      observed_outputs: boundedOutputs,
      linked_findings: [{
        finding_id: `workflow-lifecycle-rust-sdk-lifecycle-surface-${stableReason}`,
        finding_type: 'conformance_runner_blocked',
        classification: 'runner-gap',
        scenario_id: RUST_SCENARIO_ID,
        owning_surface: 'conformance-harness',
        summary,
        next_acceptance_criterion: 'Produce a valid executed Rust lifecycle probe envelope from the exact crate and server artifact tuple.',
      }],
    },
    runnerBlocked: true,
  };
}

function normalizeRustSidecar(sidecar) {
  const scenario = sidecar.scenario_results?.[RUST_SCENARIO_ID]
    ?? sidecar.scenarioResults?.[RUST_SCENARIO_ID];
  const outputs = outputsFrom(scenario);
  const status = normalizeStatus(scenario?.status);
  const exitStatus = sidecar.shard_exit_status;
  const envelopeValid = sidecar.schema === RUST_SIDECAR_SCHEMA
    && sidecar.version === 1
    && sidecar.runner === RUST_SIDECAR_RUNNER
    && scenario
    && stringValue(scenario.scenario_id ?? scenario.scenarioId) === RUST_SCENARIO_ID
    && Number.isInteger(exitStatus)
    && exitStatus >= 0
    && (!Number.isInteger(outputs.shard_exit_status) || outputs.shard_exit_status === exitStatus);

  const stableReason = stringValue(outputs.stable_reason);
  const declaredRunnerFailure = envelopeValid
    && (truthyFlag(sidecar.runner_blocked) || truthyFlag(sidecar.runnerBlocked))
    && status === 'runner_blocked'
    && normalizeClassification(status, scenario.classification) === 'runner-gap'
    && !truthyFlag(scenario.published_artifact_cell_executed)
    && !truthyFlag(outputs.published_artifact_cell_executed)
    && RUST_RUNNER_REASONS.has(stableReason);
  if (declaredRunnerFailure) {
    return normalizeRustRunnerFailure(outputs, stableReason, exitStatus);
  }

  const baseValid = envelopeValid
    && !truthyFlag(sidecar.runner_blocked)
    && !truthyFlag(sidecar.runnerBlocked)
    && scenario.published_artifact_cell_executed === true
    && outputs.sdk === 'sdk-rust'
    && outputs.rust_shard_contract_version === 3
    && outputs.shard_runner === RUST_SIDECAR_RUNNER
    && Number.isInteger(outputs.shard_exit_status)
    && outputs.shard_exit_status === exitStatus;

  if (!baseValid) {
    return { scenario: invalidRustScenario('rust_sdk_sidecar_contract_invalid'), runnerBlocked: true };
  }
  if (rustArtifactMismatch(outputs)) {
    return { scenario: invalidRustScenario('rust_sdk_sidecar_artifact_mismatch'), runnerBlocked: true };
  }
  if (status === 'pass' && exitStatus === 0 && outputs.probe_outcome === 'pass') {
    return { scenario, runnerBlocked: false };
  }

  const failureEvidence = validatedRustFailureEvidence(outputs, stableReason);
  const productFailure = status === 'fail'
    && normalizeClassification(status, scenario.classification) === 'product-gap'
    && exitStatus > 0
    && outputs.probe_outcome === 'fail'
    && outputs.published_artifact_cell_executed === true
    && failureEvidence !== null;
  if (!productFailure) {
    return { scenario: invalidRustScenario('rust_sdk_sidecar_contract_invalid'), runnerBlocked: true };
  }

  const { failureMessage, failingCell } = failureEvidence;
  const boundedOutputs = boundedRustValue(outputs);
  boundedOutputs.stable_reason = stableReason;
  boundedOutputs.failure_message = failureMessage;
  boundedOutputs.shard_exit_status = exitStatus;
  boundedOutputs.published_artifact_cell_executed = true;
  boundedOutputs.failing_lifecycle_cell = failingCell;
  const summary = redactSensitiveText(
    `Rust lifecycle cell ${failingCell} failed against durable-workflow ${outputs.artifact_version} and server ${outputs.server_version}: ${failureMessage}`,
  );
  const normalizedScenario = {
    scenario_id: RUST_SCENARIO_ID,
    status: 'fail',
    classification: 'product-gap',
    published_artifact_cell_executed: true,
    observed_outputs: boundedOutputs,
    linked_findings: [{
      finding_id: 'workflow-lifecycle-rust-sdk-lifecycle-surface-product-gap',
      finding_type: 'product_behavior_gap',
      classification: 'product-gap',
      scenario_id: RUST_SCENARIO_ID,
      owning_surface: 'sdk-rust-and-server',
      summary,
      observed_evidence: boundedOutputs.scenario_outcomes?.[failingCell] || {},
      next_acceptance_criterion: `Make ${failingCell} satisfy the Rust lifecycle contract against the exact crate and server artifact tuple, then rerun workflow-lifecycle conformance.`,
    }],
  };
  return { scenario: normalizedScenario, runnerBlocked: false };
}

function mergeEvidenceSidecars(record) {
  const merged = record.value && typeof record.value === 'object' && !Array.isArray(record.value)
    ? { ...record.value }
    : {};
  const sources = [record.source];

  for (const fileName of ['php-sdk-lifecycle-evidence.json', 'python-sdk-lifecycle-evidence.json', 'rust-sdk-lifecycle-evidence.json']) {
    const sidecarPath = path.join(RESULT_DIR, fileName);
    if (!fs.existsSync(sidecarPath)) {
      continue;
    }

    let sidecar;
    try {
      sidecar = readJson(sidecarPath) ?? {};
    } catch {
      sidecar = {};
    }
    sources.push(sidecarPath);

    if (fileName === 'rust-sdk-lifecycle-evidence.json') {
      const normalized = normalizeRustSidecar(sidecar);
      sidecar.scenario_results = { [RUST_SCENARIO_ID]: normalized.scenario };
      sidecar.runner_blocked = normalized.runnerBlocked;
    }

    const mergedScenarios = {
      ...(merged.scenario_results ?? merged.scenarioResults ?? {}),
      ...(sidecar.scenario_results ?? sidecar.scenarioResults ?? {}),
    };
    if (Object.keys(mergedScenarios).length > 0) {
      merged.scenario_results = mergedScenarios;
    }

    merged.runner_blocked = truthyFlag(merged.runner_blocked)
      || truthyFlag(merged.runnerBlocked)
      || truthyFlag(sidecar.runner_blocked)
      || truthyFlag(sidecar.runnerBlocked);
  }

  const rustSidecarPath = path.join(RESULT_DIR, 'rust-sdk-lifecycle-evidence.json');
  if (!fs.existsSync(rustSidecarPath)) {
    merged.scenario_results = {
      ...(merged.scenario_results ?? merged.scenarioResults ?? {}),
      rust_sdk_lifecycle_surface: {
        scenario_id: 'rust_sdk_lifecycle_surface',
        status: 'not_covered',
        classification: 'coverage-gap',
        published_artifact_cell_executed: false,
        observed_outputs: { stable_reason: 'rust_sdk_shard_missing' },
      },
    };
  }

  return {
    source: sources.join(','),
    value: merged,
  };
}

function stringValue(value) {
  if (typeof value === 'string') {
    return value.trim();
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return '';
}

function truthyFlag(value) {
  if (value === true || value === 1) {
    return true;
  }
  if (typeof value === 'string') {
    return ['1', 'true', 'yes', 'y', 'on'].includes(value.trim().toLowerCase());
  }
  return false;
}

function normalizeCliVersion(value) {
  return value.startsWith('v') && SEMVER_RE.test(value.slice(1)) ? value.slice(1) : value;
}

function serverTagFromImage(image) {
  const withoutDigest = image.split('@', 1)[0];
  const tail = withoutDigest.split('/').pop() ?? withoutDigest;
  return tail.includes(':') ? tail.slice(tail.lastIndexOf(':') + 1) : '';
}

function isDigestPinnedServerImage(image) {
  const normalized = image.replace(/^docker:\/\//, '');
  if (!normalized.includes('@')) {
    return false;
  }

  return SHA256_DIGEST_RE.test(normalized.slice(normalized.lastIndexOf('@') + 1));
}

function isPlaceholder(value) {
  return !value || PLACEHOLDER_RE.test(value);
}

function artifactObject(source) {
  if (!source || typeof source !== 'object' || Array.isArray(source)) {
    return {};
  }

  return {
    server: stringValue(source.server),
    cli: normalizeCliVersion(stringValue(source.cli)),
    workflow: stringValue(source.workflow ?? source['workflow-php']),
    'workflow-php': stringValue(source['workflow-php'] ?? source.workflow),
    'sdk-python': stringValue(source['sdk-python'] ?? source.sdk_python ?? source.python),
    'sdk-rust': stringValue(source['sdk-rust'] ?? source.sdk_rust ?? source.rust),
    waterline: stringValue(source.waterline),
  };
}

function evidenceArtifactVersions(evidence) {
  return artifactObject(
    evidence.artifact_versions
      ?? evidence.artifactVersions
      ?? evidence.published_artifact_versions
      ?? evidence.publishedArtifactVersions
      ?? {},
  );
}

function artifactVersions(evidence) {
  const fromEvidence = evidenceArtifactVersions(evidence);
  const serverImage = env('DW_SERVER_IMAGE');
  const serverFromImage = serverTagFromImage(serverImage);

  return {
    server: env('DW_SERVER_VERSION')
      || fromEvidence.server
      || (SERVER_PATCH_TAG_RE.test(serverFromImage) ? serverFromImage : '')
      || 'unresolved',
    cli: normalizeCliVersion(env('DW_CLI_VERSION') || fromEvidence.cli || 'unresolved'),
    workflow: env('DW_WORKFLOW_PHP_VERSION') || fromEvidence.workflow || 'unresolved',
    'workflow-php': env('DW_WORKFLOW_PHP_VERSION') || fromEvidence['workflow-php'] || fromEvidence.workflow || 'unresolved',
    'sdk-python': env('DW_PYTHON_SDK_VERSION') || fromEvidence['sdk-python'] || 'unresolved',
    'sdk-rust': env('DW_RUST_SDK_VERSION') || fromEvidence['sdk-rust'] || 'unresolved',
    waterline: env('DW_WATERLINE_VERSION') || fromEvidence.waterline || 'unresolved',
  };
}

function evidenceArtifactSources(evidence) {
  const sourcePolicy = evidence.source_policy ?? evidence.sourcePolicy ?? {};
  const source = evidence.artifact_sources
    ?? evidence.artifactSources
    ?? sourcePolicy.artifact_sources
    ?? sourcePolicy.artifactSources
    ?? {};

  return source && typeof source === 'object' && !Array.isArray(source) ? source : {};
}

function artifactSources(versions, evidence) {
  const supplied = evidenceArtifactSources(evidence);
  const serverImage = env('DW_SERVER_IMAGE') || stringValue(supplied.server);

  return {
    server: serverImage || (versions.server !== 'unresolved' ? `docker://durableworkflow/server:${versions.server}` : 'unresolved'),
    cli: stringValue(supplied.cli) || (versions.cli !== 'unresolved' ? `official dw installer ${versions.cli}` : 'unresolved'),
    workflow: stringValue(supplied.workflow ?? supplied['workflow-php'])
      || (versions.workflow !== 'unresolved' ? `packagist://durable-workflow/workflow@${versions.workflow}` : 'unresolved'),
    'workflow-php': stringValue(supplied['workflow-php'] ?? supplied.workflow)
      || (versions['workflow-php'] !== 'unresolved' ? `packagist://durable-workflow/workflow@${versions['workflow-php']}` : 'unresolved'),
    'sdk-python': stringValue(supplied['sdk-python'] ?? supplied.sdk_python ?? supplied.python)
      || (versions['sdk-python'] !== 'unresolved' ? `pypi://durable-workflow==${versions['sdk-python']}` : 'unresolved'),
    'sdk-rust': stringValue(supplied['sdk-rust'] ?? supplied.sdk_rust ?? supplied.rust)
      || (versions['sdk-rust'] !== 'unresolved' ? `crates.io://durable-workflow@${versions['sdk-rust']}` : 'unresolved'),
    waterline: stringValue(supplied.waterline)
      || (versions.waterline !== 'unresolved' ? `packagist://durable-workflow/waterline@${versions.waterline}` : 'unresolved'),
  };
}

function exactPinFailures(versions, sources) {
  const failures = [];
  for (const artifact of REQUIRED_ARTIFACTS) {
    const version = versions[artifact] ?? '';
    if (isPlaceholder(version)) {
      failures.push(`${artifact} must be pinned to a concrete published version`);
      continue;
    }
    if (artifact === 'server' && !SERVER_PATCH_TAG_RE.test(version)) {
      failures.push(`server version must be an exact patch tag; got ${JSON.stringify(version)}`);
    }
    if (artifact !== 'server' && !SEMVER_RE.test(version)) {
      failures.push(`${artifact} version must be exact semver; got ${JSON.stringify(version)}`);
    }
  }

  const serverSource = String(sources.server ?? '');
  if (serverSource && !isPlaceholder(serverSource)) {
    const tag = serverTagFromImage(serverSource);
    if (!isDigestPinnedServerImage(serverSource) && (!tag || !SERVER_PATCH_TAG_RE.test(tag))) {
      failures.push(`server source must use an exact patch tag or sha256 digest; got ${JSON.stringify(serverSource)}`);
    } else if (tag && SERVER_PATCH_TAG_RE.test(tag) && versions.server !== 'unresolved' && tag !== versions.server) {
      failures.push(`server version ${JSON.stringify(versions.server)} does not match server source tag ${JSON.stringify(tag)}`);
    }
  }

  return failures;
}

function artifactVersionMismatchFailures(evidence, versions) {
  const supplied = evidenceArtifactVersions(evidence);
  const failures = [];
  for (const artifact of REQUIRED_ARTIFACTS) {
    if (supplied[artifact] && versions[artifact] && supplied[artifact] !== versions[artifact]) {
      failures.push(
        `${artifact} runtime evidence version ${JSON.stringify(supplied[artifact])} does not match pinned artifact version ${JSON.stringify(versions[artifact])}`,
      );
    }
  }

  return failures;
}

function sourcePolicy(evidence, sources) {
  const supplied = evidence.source_policy ?? evidence.sourcePolicy ?? {};
  const sourceStrings = Object.values(sources).map((value) => String(value).toLowerCase());
  const sourceContainsForbiddenToken = sourceStrings.some(
    (value) => FORBIDDEN_SOURCE_TOKENS.some((token) => value.includes(token.toLowerCase())),
  );
  const localUsed = truthyFlag(evidence.local_product_source_checkouts_used)
    || truthyFlag(evidence.localProductSourceCheckoutsUsed)
    || truthyFlag(supplied.local_product_source_checkouts_used)
    || truthyFlag(supplied.localProductSourceCheckoutsUsed)
    || sourceContainsForbiddenToken;
  const localPassEvidence = truthyFlag(evidence.local_product_source_checkout_used_as_pass_evidence)
    || truthyFlag(evidence.localProductSourceCheckoutUsedAsPassEvidence)
    || truthyFlag(supplied.local_product_source_checkout_used_as_pass_evidence)
    || truthyFlag(supplied.localProductSourceCheckoutUsedAsPassEvidence)
    || sourceContainsForbiddenToken;

  return {
    policy_name: 'published_artifacts_only',
    published_artifacts_only: true,
    published_artifact_evidence_only: true,
    pass_evidence_must_come_from_published_artifacts: true,
    artifact_sources: sources,
    forbidden_sources: FORBIDDEN_SOURCE_TOKENS,
    local_product_source_checkouts_used: localUsed,
    local_product_source_checkout_used_as_pass_evidence: localPassEvidence,
  };
}

function scenarioDefinitions(manifest) {
  const scenarios = Array.isArray(manifest.scenarios) ? manifest.scenarios : [];
  const defined = scenarios.filter((scenario) => scenario && typeof scenario === 'object' && typeof scenario.id === 'string');
  if (defined.length > 0) {
    return defined.map((scenario) => ({
      ...SCENARIO_REQUIREMENTS[scenario.id],
      ...scenario,
      required_evidence: Array.isArray(scenario.required_evidence)
        ? scenario.required_evidence
        : (SCENARIO_REQUIREMENTS[scenario.id]?.required_evidence ?? []),
    }));
  }

  const required = Array.isArray(manifest.required_scenarios) ? manifest.required_scenarios : Object.keys(SCENARIO_REQUIREMENTS);
  return required
    .map((id) => stringValue(id))
    .filter((id) => id !== '')
    .map((id) => ({
      id,
      ...(SCENARIO_REQUIREMENTS[id] ?? {
        description: `${id} lifecycle cell`,
        required_evidence: [],
        required_behavior: `${id} is exercised against published artifacts`,
      }),
    }));
}

function scenarioInputs(evidence) {
  const raw = evidence.scenario_results
    ?? evidence.scenarioResults
    ?? evidence.lifecycle_cells
    ?? evidence.lifecycleCells
    ?? evidence.per_cell_outcomes
    ?? evidence.perCellOutcomes
    ?? evidence.cells
    ?? {};
  const inputs = {};

  if (Array.isArray(raw)) {
    for (const entry of raw) {
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        continue;
      }
      const id = stringValue(entry.scenario_id ?? entry.scenarioId ?? entry.cell_id ?? entry.id);
      if (id) {
        inputs[id] = entry;
      }
    }

    return inputs;
  }

  if (!raw || typeof raw !== 'object') {
    return inputs;
  }

  for (const [id, entry] of Object.entries(raw)) {
    if (entry && typeof entry === 'object' && !Array.isArray(entry)) {
      inputs[id] = { scenario_id: id, ...entry };
    } else if (typeof entry === 'string') {
      inputs[id] = { scenario_id: id, status: entry };
    }
  }

  return inputs;
}

function normalizeStatus(value) {
  const status = stringValue(value).toLowerCase().replace(/-/g, '_');
  if (['passed', 'ok', 'success'].includes(status)) {
    return 'pass';
  }
  if (['failed', 'failure', 'product_gap'].includes(status)) {
    return 'fail';
  }
  if (['blocked', 'runner_error', 'environment_error'].includes(status)) {
    return 'runner_blocked';
  }
  if (['coverage_gap', 'missing', 'omitted', 'not_exercised'].includes(status)) {
    return 'not_covered';
  }

  return ALLOWED_STATUSES.has(status) ? status : 'not_covered';
}

function normalizeClassification(status, supplied) {
  const classification = stringValue(supplied).toLowerCase().replace(/_/g, '-');
  if (ALLOWED_CLASSIFICATIONS.has(classification)) {
    return classification;
  }
  if (status === 'pass') {
    return 'product-gap';
  }
  if (status === 'runner_blocked') {
    return 'runner-gap';
  }
  if (status === 'not_covered') {
    return 'coverage-gap';
  }
  return 'product-gap';
}

function outputsFrom(entry) {
  const outputs = entry?.observed_outputs
    ?? entry?.observedOutputs
    ?? entry?.outputs
    ?? entry?.evidence
    ?? {};
  return outputs && typeof outputs === 'object' && !Array.isArray(outputs) ? { ...outputs } : {};
}

function requiredEvidenceMissing(scenario, outputs) {
  const required = Array.isArray(scenario.required_evidence) ? scenario.required_evidence : [];
  return required.filter((field) => {
    if (!Object.prototype.hasOwnProperty.call(outputs, field)) {
      return true;
    }
    return requiredEvidenceValueMissing(scenario.id, field, outputs[field]);
  });
}

function typedRefusalEvidence(entry, outputs) {
  const candidate = outputs.typed_refusal
    ?? outputs.typedRefusal
    ?? entry.typed_refusal
    ?? entry.typedRefusal
    ?? {};
  const typed = stringValue(candidate.typed_error)
    || stringValue(candidate.typedError)
    || stringValue(candidate.error_type)
    || stringValue(candidate.errorType)
    || stringValue(candidate.refusal_code)
    || stringValue(candidate.refusalCode)
    || stringValue(outputs.typed_error)
    || stringValue(outputs.error_type)
    || stringValue(outputs.refusal_code)
    || stringValue(outputs.backoff_observation_or_error_type)
    || stringValue(entry.typed_error)
    || stringValue(entry.error_type);
  const reason = stringValue(candidate.refusal_reason)
    || stringValue(candidate.refusalReason)
    || stringValue(candidate.reason)
    || stringValue(outputs.refusal_reason)
    || stringValue(outputs.reason)
    || stringValue(entry.refusal_reason)
    || stringValue(entry.reason);
  const documented = truthyFlag(candidate.documented)
    || truthyFlag(candidate.docs_match)
    || truthyFlag(candidate.docsMatch)
    || truthyFlag(outputs.documented)
    || truthyFlag(outputs.documented_refusal)
    || truthyFlag(outputs.docs_match)
    || truthyFlag(outputs.docsMatch)
    || truthyFlag(entry.documented)
    || truthyFlag(entry.docs_match)
    || truthyFlag(entry.docsMatch);

  return {
    typed_error: typed,
    refusal_reason: reason,
    documented,
    valid: Boolean(typed && reason && documented),
  };
}

function requiredEvidenceValueMissing(scenarioId, field, value) {
  if (value === null || value === undefined) {
    return true;
  }
  if (typeof value === 'string') {
    return value.trim() === '';
  }
  if (Array.isArray(value)) {
    return value.length === 0 && !requiredFieldAllowsEmptyList(scenarioId, field);
  }
  if (typeof value === 'object') {
    return Object.keys(value).length === 0;
  }

  return false;
}

function requiredFieldAllowsEmptyList(scenarioId, field) {
  return ['php_sdk_lifecycle_surface', 'python_sdk_lifecycle_surface', 'rust_sdk_lifecycle_surface'].includes(scenarioId)
    && ['unsupported_cells', 'typed_errors'].includes(field);
}

function normalizedText(value) {
  return stringValue(value).toLowerCase().replace(/[-\s]+/g, '_');
}

function textIncludesAny(value, fragments) {
  const text = normalizedText(value);

  return text !== '' && fragments.some((fragment) => text.includes(fragment));
}

function numberValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === 'string' && value.trim() !== '') {
    const number = Number(value.trim());

    return Number.isFinite(number) ? number : null;
  }

  return null;
}

function timestampMs(value) {
  const timestamp = stringValue(value);
  if (!timestamp) {
    return null;
  }

  const parsed = Date.parse(timestamp);

  return Number.isFinite(parsed) ? parsed : null;
}

function nonEmptyList(value) {
  return Array.isArray(value) && value.length > 0;
}

function nonEmptyObject(value) {
  return value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length > 0;
}

function nonEmptyCollection(value) {
  return nonEmptyList(value) || nonEmptyObject(value);
}

function scalarList(value) {
  return Array.isArray(value) ? value.map((entry) => stringValue(entry)).filter((entry) => entry !== '') : [];
}

function listContainsValue(value, expected) {
  const normalizedExpected = normalizedText(expected);

  return scalarList(value).some((entry) => normalizedText(entry) === normalizedExpected);
}

function semanticEvidenceFailures(scenario, outputs) {
  switch (scenario.id) {
    case 'continue_as_new_run_chain_visibility':
      return validateContinueAsNewRunChain(outputs);
    case 'continue_as_new_identity_and_history_continuity':
      return validateContinueAsNewHistory(outputs);
    case 'continue_as_new_duplicate_side_effect_prevention':
      return validateContinueAsNewSideEffects(outputs);
    case 'cancellation_public_surface_terminal_state':
      return validateTerminalLifecycleSurface(outputs, 'cancelled', ['cancel']);
    case 'termination_public_surface_terminal_state':
      return validateTerminalLifecycleSurface(outputs, 'terminated', ['terminat']);
    case 'workflow_id_reuse_duplicate_start_policy':
      return validateDuplicateStartPolicy(outputs);
    case 'workflow_timeout_terminal_state':
      return validateWorkflowTimeout(outputs);
    case 'workflow_retry_backoff_or_refusal':
      return validateWorkflowRetry(outputs);
    case 'php_sdk_lifecycle_surface':
      return validateSdkLifecycleSurface(outputs, ['php', 'workflow']);
    case 'python_sdk_lifecycle_surface':
      return validateSdkLifecycleSurface(outputs, ['python']);
    case 'rust_sdk_lifecycle_surface':
      return validateRustSdkLifecycleSurface(outputs);
    case 'operator_diagnostics_surfaces':
      return validateOperatorDiagnostics(outputs);
    default:
      return [];
  }
}

function validateContinueAsNewRunChain(outputs) {
  const failures = [];
  const workflowId = stringValue(outputs.workflow_id);
  const initialRunId = stringValue(outputs.initial_run_id);
  const continuedRunId = stringValue(outputs.continued_run_id);
  const currentRunId = stringValue(outputs.current_run_id);
  const runCount = numberValue(outputs.run_count);
  const runNumbers = Array.isArray(outputs.run_numbers)
    ? outputs.run_numbers.map((value) => numberValue(value))
    : [];

  if (!workflowId) {
    failures.push('continue-as-new chain must report one logical workflow_id');
  }
  if (!initialRunId || !continuedRunId || initialRunId === continuedRunId) {
    failures.push('continue-as-new chain must report distinct initial and continued run IDs');
  }
  if (currentRunId !== continuedRunId) {
    failures.push('continue-as-new current_run_id must point at the continued successor run');
  }
  if (runCount === null || runCount < 2) {
    failures.push('continue-as-new run_count must be at least 2');
  }
  if (runNumbers.length < 2 || runNumbers.some((value) => value === null)) {
    failures.push('continue-as-new run_numbers must list at least two numeric runs');
  } else {
    for (let index = 1; index < runNumbers.length; index += 1) {
      if (runNumbers[index] <= runNumbers[index - 1]) {
        failures.push('continue-as-new run_numbers must be strictly increasing');
        break;
      }
    }
  }

  return failures;
}

function validateContinueAsNewHistory(outputs) {
  const failures = [];
  const events = outputs.history_events;
  const predecessor = stringValue(outputs.predecessor_closed_event);
  const successor = stringValue(outputs.successor_started_event);

  if (!nonEmptyList(events)) {
    failures.push('continue-as-new history must include public history events');
  } else {
    if (!predecessor || !listContainsValue(events, predecessor)) {
      failures.push('continue-as-new history must include the predecessor closed event');
    }
    if (!successor || !listContainsValue(events, successor)) {
      failures.push('continue-as-new history must include the successor started event');
    }
  }
  if (!nonEmptyList(outputs.history_api_links)) {
    failures.push('continue-as-new history must include operator-visible API links');
  }

  return failures;
}

function validateContinueAsNewSideEffects(outputs) {
  const failures = [];
  const expected = numberValue(outputs.expected_count);
  const observed = numberValue(outputs.observed_count);

  if (expected === null || expected < 1) {
    failures.push('side-effect evidence must report a positive expected_count');
  }
  if (observed === null || observed < 0) {
    failures.push('side-effect evidence must report a non-negative observed_count');
  }
  if (expected !== null && observed !== null && observed !== expected) {
    failures.push('continue-as-new side-effect observed_count must equal expected_count');
  }
  if (!stringValue(outputs.side_effect_key)) {
    failures.push('side-effect evidence must name the protected side_effect_key');
  }
  if (!stringValue(outputs.replay_or_restart_window)) {
    failures.push('side-effect evidence must name the replay or restart window exercised');
  }

  return failures;
}

function validateTerminalLifecycleSurface(outputs, terminalStatus, errorFragments) {
  const failures = [];
  if (normalizedText(outputs.terminal_status) !== terminalStatus) {
    failures.push(`terminal_status must be ${terminalStatus}`);
  }
  if (!textIncludesAny(outputs.worker_error_type, errorFragments)) {
    failures.push(`worker_error_type must be a typed ${terminalStatus} error`);
  }
  if (!textIncludesAny(outputs.caller_error_type, errorFragments)) {
    failures.push(`caller_error_type must be a typed ${terminalStatus} error`);
  }

  return failures;
}

function validateDuplicateStartPolicy(outputs) {
  const failures = [];
  const duplicateOutcome = normalizedText(outputs.duplicate_start_outcome);
  const firstRunId = stringValue(outputs.first_run_id);
  const runCountAfterDuplicate = numberValue(outputs.run_count_after_duplicate);
  const runIdsAfterDuplicate = scalarList(outputs.run_ids_after_duplicate);

  if (['accepted', 'started', 'created', 'completed', 'succeeded', 'success', 'ok'].includes(duplicateOutcome)) {
    failures.push('duplicate workflow-id start must not be accepted as a new run');
  }
  if (!textIncludesAny(duplicateOutcome, ['refus', 'reject', 'fail', 'conflict', 'error', 'existing', 'duplicate'])) {
    failures.push('duplicate workflow-id start must prove enforcement or a typed refusal');
  }
  if (!stringValue(outputs.http_status_or_error_type)) {
    failures.push('duplicate workflow-id start must report an HTTP status or typed error');
  }
  if (!firstRunId) {
    failures.push('duplicate workflow-id start must report the first run id');
  }
  if (runCountAfterDuplicate !== 1) {
    failures.push('duplicate workflow-id fail policy must leave exactly one run after the duplicate request');
  }
  if (runIdsAfterDuplicate.length !== 1 || (firstRunId && runIdsAfterDuplicate[0] !== firstRunId)) {
    failures.push('duplicate workflow-id fail policy must preserve only the first run id');
  }

  return failures;
}

function validateWorkflowTimeout(outputs) {
  const failures = [];
  if (normalizedText(outputs.terminal_status) !== 'timed_out') {
    failures.push('workflow timeout terminal_status must be timed_out');
  }

  const deadlineAt = timestampMs(outputs.deadline_at);
  const observedTerminalAt = timestampMs(outputs.observed_terminal_at);
  if (deadlineAt === null || observedTerminalAt === null) {
    failures.push('workflow timeout evidence must report parseable deadline and terminal timestamps');
  } else if (observedTerminalAt < deadlineAt) {
    failures.push('workflow timeout terminal observation must not be earlier than the deadline');
  }
  if (!nonEmptyCollection(outputs.operator_visible_timing)) {
    failures.push('workflow timeout must include operator-visible timing evidence');
  }
  const refusals = Array.isArray(outputs.unsupported_timeout_shape_refusals)
    ? outputs.unsupported_timeout_shape_refusals
    : [];
  if (refusals.length === 0) {
    failures.push('workflow timeout evidence must include typed refusals for unsupported timeout shapes');
  } else {
    for (const refusal of refusals) {
      const status = numberValue(refusal?.http_status);
      const typedError = stringValue(refusal?.typed_error ?? refusal?.error_type ?? refusal?.refusal_code);
      const reason = stringValue(refusal?.refusal_reason ?? refusal?.reason ?? refusal?.message);
      if (status === null || status < 400 || !typedError || !reason || !truthyFlag(refusal?.documented)) {
        failures.push('workflow timeout unsupported shapes must be documented typed refusals');
        break;
      }
    }
  }

  return failures;
}

function validateWorkflowRetry(outputs) {
  const failures = [];
  if (!truthyFlag(outputs.docs_match)) {
    failures.push('workflow retry/backoff evidence must match public docs');
  }

  const attemptCount = numberValue(outputs.attempt_count_or_refusal_reason);
  if (attemptCount !== null) {
    if (attemptCount < 2) {
      failures.push('workflow retry evidence must show at least two attempts');
    }
    if (!stringValue(outputs.backoff_observation_or_error_type)) {
      failures.push('workflow retry evidence must report backoff observation');
    }

    return failures;
  }

  if (typedRefusalEvidence({}, outputs).valid) {
    return failures;
  }

  failures.push('workflow retry pass evidence must prove retry attempts or a documented typed refusal');

  return failures;
}

function validateSdkLifecycleSurface(outputs, expectedSdkFragments) {
  const failures = [];
  if (!textIncludesAny(outputs.sdk, expectedSdkFragments)) {
    failures.push('SDK lifecycle surface evidence must identify the expected SDK');
  }
  if (!nonEmptyList(outputs.covered_cells) && !nonEmptyList(outputs.unsupported_cells)) {
    failures.push('SDK lifecycle surface must cover cells or report unsupported cells');
  }
  if (nonEmptyList(outputs.unsupported_cells) && !nonEmptyList(outputs.typed_errors)) {
    failures.push('SDK unsupported lifecycle cells must include typed errors');
  }
  if (!stringValue(outputs.artifact_version)) {
    failures.push('SDK lifecycle surface must report the published artifact version');
  }

  return failures;
}

function validateRustSdkLifecycleSurface(outputs) {
  const failures = validateSdkLifecycleSurface(outputs, ['rust']);
  const requiredCells = [
    'instance_cancel', 'instance_terminate', 'selected_run_guard', 'stale_run_rejection',
    'typed_failed', 'typed_cancelled', 'typed_terminated', 'typed_timed_out',
    'cancellation_heartbeat', 'late_activity_completion_refused',
    'worker_restart_during_cancellation', 'continue_as_new_replay_boundary',
  ];
  for (const cell of requiredCells) {
    if (!listContainsValue(outputs.covered_cells, cell)) failures.push(`covered_cells must include ${cell}`);
  }
  const scenarioOutcomes = nonEmptyObject(outputs.scenario_outcomes) ? outputs.scenario_outcomes : {};
  for (const cell of requiredCells) {
    if (!nonEmptyObject(scenarioOutcomes[cell]) || normalizeStatus(scenarioOutcomes[cell].status) !== 'pass') {
      failures.push(`scenario_outcomes.${cell} must report pass`);
    }
  }
  const exactOutcome = (cell, field, expected) => {
    if (normalizedText(scenarioOutcomes[cell]?.[field]) !== normalizedText(expected)) {
      failures.push(`scenario_outcomes.${cell}.${field} must be ${expected}`);
    }
  };
  exactOutcome('instance_cancel', 'command_status', 'accepted');
  exactOutcome('instance_cancel', 'target_scope', 'instance');
  exactOutcome('instance_cancel', 'typed_outcome', 'WorkflowCancelled');
  exactOutcome('instance_terminate', 'command_status', 'accepted');
  exactOutcome('instance_terminate', 'target_scope', 'instance');
  exactOutcome('instance_terminate', 'typed_outcome', 'WorkflowTerminated');
  exactOutcome('selected_run_guard', 'command_status', 'accepted');
  exactOutcome('selected_run_guard', 'target_scope', 'run');
  if (!stringValue(scenarioOutcomes.selected_run_guard?.workflow_id)
      || !stringValue(scenarioOutcomes.selected_run_guard?.run_id)) {
    failures.push('selected_run_guard must retain workflow and selected run identity');
  }
  exactOutcome('stale_run_rejection', 'typed_error', 'WorkflowCommandRejected');
  exactOutcome('stale_run_rejection', 'reason', 'historical_run_command_rejected');
  exactOutcome('stale_run_rejection', 'target_scope', 'run');
  if (numberValue(scenarioOutcomes.stale_run_rejection?.http_status) !== 409) {
    failures.push('stale_run_rejection.http_status must be 409');
  }
  const staleOutcome = nonEmptyObject(scenarioOutcomes.stale_run_rejection)
    ? scenarioOutcomes.stale_run_rejection
    : {};
  const staleWorkflowId = stringValue(staleOutcome.workflow_id);
  const staleRunId = stringValue(staleOutcome.run_id);
  const priorRunId = stringValue(staleOutcome.prior_run_id);
  const successorRunId = stringValue(staleOutcome.successor_run_id);
  const successorWorkflowId = stringValue(staleOutcome.successor_workflow_id);
  if (!staleWorkflowId || !staleRunId || !priorRunId || !successorRunId
      || successorWorkflowId !== staleWorkflowId
      || staleRunId !== priorRunId
      || successorRunId === priorRunId) {
    failures.push('stale_run_rejection must retain the rejected prior run and a distinct successor current run for the same workflow');
  }
  exactOutcome('typed_failed', 'typed_outcome', 'WorkflowFailed');
  exactOutcome('typed_cancelled', 'typed_outcome', 'WorkflowCancelled');
  exactOutcome('typed_terminated', 'typed_outcome', 'WorkflowTerminated');
  exactOutcome('typed_timed_out', 'typed_outcome', 'WorkflowTimedOut');
  exactOutcome('typed_timed_out', 'reason', 'run_timeout');
  exactOutcome('typed_timed_out', 'observation_source', 'WorkflowHandle::result');
  exactOutcome('typed_timed_out', 'server_closed_reason', 'timed_out');
  if (!truthyFlag(scenarioOutcomes.typed_timed_out?.server_terminal)
      || normalizedText(scenarioOutcomes.typed_timed_out?.failure_category) === 'client_timeout') {
    failures.push('typed_timed_out must prove an SDK-observed server-terminal timeout, not a client wait deadline');
  }
  if (!truthyFlag(scenarioOutcomes.cancellation_heartbeat?.cancel_requested)
      || !truthyFlag(scenarioOutcomes.cancellation_heartbeat?.should_stop)
      || normalizedText(scenarioOutcomes.cancellation_heartbeat?.run_closed_reason) !== 'cancelled') {
    failures.push('cancellation_heartbeat must prove cancellation was observed and the activity was told to stop');
  }
  exactOutcome('late_activity_completion_refused', 'typed_error', 'ActivityTaskRejected');
  if (numberValue(scenarioOutcomes.late_activity_completion_refused?.http_status) !== 409
      || normalizedText(scenarioOutcomes.late_activity_completion_refused?.reason) !== 'run_cancelled') {
    failures.push('late_activity_completion_refused must report the stable 409 run_cancelled refusal');
  }
  exactOutcome('worker_restart_during_cancellation', 'restart_phase', 'cancellation_pending');
  const restartOutcome = nonEmptyObject(scenarioOutcomes.worker_restart_during_cancellation)
    ? scenarioOutcomes.worker_restart_during_cancellation
    : {};
  const replacementPollStartedAt = numberValue(restartOutcome.replacement_poll_started_elapsed_ns);
  const settlementReleasedAt = numberValue(restartOutcome.settlement_released_elapsed_ns);
  const originalSettlementObservedAt = numberValue(restartOutcome.original_settlement_observed_elapsed_ns);
  const observedOrdering = replacementPollStartedAt !== null
    && settlementReleasedAt !== null
    && originalSettlementObservedAt !== null
    && replacementPollStartedAt < settlementReleasedAt
    && settlementReleasedAt <= originalSettlementObservedAt;
  if (!truthyFlag(restartOutcome.replacement_registered)
      || !truthyFlag(restartOutcome.replacement_poll_start_observed)
      || !truthyFlag(restartOutcome.original_activity_unsettled_when_replacement_poll_started)
      || !truthyFlag(restartOutcome.replacement_started_before_original_settled)
      || !truthyFlag(restartOutcome.settlement_released_after_replacement_started)
      || !truthyFlag(restartOutcome.original_settled_after_restart)
      || !observedOrdering) {
    failures.push('worker_restart_during_cancellation must observe the replacement poll before releasing original activity settlement');
  }
  const continueOutcome = nonEmptyObject(scenarioOutcomes.continue_as_new_replay_boundary)
    ? scenarioOutcomes.continue_as_new_replay_boundary
    : {};
  const workflowId = stringValue(continueOutcome.workflow_id);
  const predecessorRunId = stringValue(continueOutcome.predecessor_run_id);
  const continuedRunId = stringValue(continueOutcome.successor_run_id);
  const runChain = nonEmptyObject(continueOutcome.run_chain) ? continueOutcome.run_chain : {};
  const runIds = Array.isArray(runChain.runs)
    ? runChain.runs.map((run) => stringValue(run?.run_id)).filter(Boolean)
    : [];
  const runNumbers = Array.isArray(runChain.runs)
    ? runChain.runs.map((run) => numberValue(run?.run_number))
    : [];
  if (!workflowId || !predecessorRunId || !continuedRunId || predecessorRunId === continuedRunId
      || stringValue(continueOutcome.current_run_id) !== continuedRunId
      || stringValue(continueOutcome.selected_historical_run_id) !== predecessorRunId
      || normalizedText(continueOutcome.selected_historical_closed_reason) !== 'continued'
      || stringValue(runChain.workflow_id) !== workflowId
      || numberValue(runChain.run_count) !== 2
      || JSON.stringify(runIds) !== JSON.stringify([predecessorRunId, continuedRunId])
      || JSON.stringify(runNumbers) !== JSON.stringify([1, 2])
      || numberValue(continueOutcome.successor_count) !== 1) {
    failures.push('continue_as_new_replay_boundary must retain one workflow identity, exactly two distinct ordered runs, historical selection, and current successor routing');
  }
  const predecessorProcess = nonEmptyObject(continueOutcome.predecessor_worker_process)
    ? continueOutcome.predecessor_worker_process
    : {};
  const successorProcess = nonEmptyObject(continueOutcome.successor_worker_process)
    ? continueOutcome.successor_worker_process
    : {};
  const predecessorCompletion = nonEmptyObject(predecessorProcess.completion)
    ? predecessorProcess.completion
    : {};
  const successorCompletion = nonEmptyObject(successorProcess.completion)
    ? successorProcess.completion
    : {};
  if (numberValue(predecessorProcess.process_id) === null
      || numberValue(successorProcess.process_id) === null
      || numberValue(predecessorProcess.process_id) === numberValue(successorProcess.process_id)
      || !stringValue(predecessorProcess.worker_id)
      || !stringValue(successorProcess.worker_id)
      || stringValue(predecessorProcess.worker_id) === stringValue(successorProcess.worker_id)
      || numberValue(predecessorProcess.handled_tasks) !== 1
      || numberValue(successorProcess.handled_tasks) !== 1) {
    failures.push('continue_as_new_replay_boundary must execute predecessor and successor tasks in distinct worker processes and worker identities');
  }
  if (numberValue(predecessorCompletion.completion_delivery_count) !== 2
      || numberValue(predecessorCompletion.first_response_status) !== 200
      || !truthyFlag(predecessorCompletion.first_response?.recorded)
      || numberValue(predecessorCompletion.retry_response_status) !== 409
      || !stringValue(predecessorCompletion.retry_response?.reason)
      || JSON.stringify(predecessorCompletion.command_types) !== JSON.stringify([
        'record_side_effect', 'record_version_marker', 'continue_as_new',
      ])
      || !nonEmptyList(predecessorCompletion.commands)) {
    failures.push('continue_as_new_replay_boundary must retry the exact committed predecessor completion and retain its rejected redelivery response');
  }
  if (numberValue(successorCompletion.completion_delivery_count) !== 1
      || numberValue(successorCompletion.first_response_status) !== 200
      || !truthyFlag(successorCompletion.first_response?.recorded)
      || JSON.stringify(successorCompletion.command_types) !== JSON.stringify([
        'record_side_effect', 'record_version_marker', 'complete_workflow',
      ])
      || !nonEmptyList(successorCompletion.commands)) {
    failures.push('continue_as_new_replay_boundary successor must record its own new-run side effect and version marker before final completion');
  }
  const predecessorHistory = nonEmptyObject(continueOutcome.predecessor_history)
    ? continueOutcome.predecessor_history
    : {};
  const successorHistory = nonEmptyObject(continueOutcome.successor_history)
    ? continueOutcome.successor_history
    : {};
  const historyCount = (history, eventType) => Array.isArray(history.events)
    ? history.events.filter((event) => event?.event_type === eventType).length
    : 0;
  const predecessorCounts = nonEmptyObject(continueOutcome.predecessor_history_event_counts)
    ? continueOutcome.predecessor_history_event_counts
    : {};
  const successorCounts = nonEmptyObject(continueOutcome.successor_history_event_counts)
    ? continueOutcome.successor_history_event_counts
    : {};
  if (stringValue(predecessorHistory.workflow_id) !== workflowId
      || stringValue(predecessorHistory.run_id) !== predecessorRunId
      || stringValue(successorHistory.workflow_id) !== workflowId
      || stringValue(successorHistory.run_id) !== continuedRunId
      || historyCount(predecessorHistory, 'SideEffectRecorded') !== 1
      || historyCount(predecessorHistory, 'VersionMarkerRecorded') !== 1
      || historyCount(predecessorHistory, 'WorkflowContinuedAsNew') !== 1
      || historyCount(successorHistory, 'SideEffectRecorded') !== 1
      || historyCount(successorHistory, 'VersionMarkerRecorded') !== 1
      || historyCount(successorHistory, 'WorkflowContinuedAsNew') !== 0
      || numberValue(predecessorCounts.SideEffectRecorded) !== 1
      || numberValue(predecessorCounts.VersionMarkerRecorded) !== 1
      || numberValue(predecessorCounts.WorkflowContinuedAsNew) !== 1
      || numberValue(successorCounts.SideEffectRecorded) !== 1
      || numberValue(successorCounts.VersionMarkerRecorded) !== 1
      || numberValue(successorCounts.WorkflowContinuedAsNew) !== 0) {
    failures.push('continue_as_new_replay_boundary histories must keep predecessor decisions immutable and count successor decisions only in the new run');
  }
  if (stringValue(continueOutcome.predecessor_transition_link?.continued_to_run_id) !== continuedRunId
      || stringValue(continueOutcome.successor_transition_link?.continued_from_run_id) !== predecessorRunId) {
    failures.push('continue_as_new_replay_boundary histories must link predecessor and successor run identities in both directions');
  }
  const finalResult = nonEmptyObject(continueOutcome.final_result) ? continueOutcome.final_result : {};
  if (numberValue(predecessorProcess.callback_calls) !== 1
      || numberValue(successorProcess.callback_calls) !== 1
      || !truthyFlag(continueOutcome.predecessor_decisions_immutable)
      || !truthyFlag(continueOutcome.successor_decisions_are_new_run_decisions)
      || normalizedText(continueOutcome.final_result_observation_source) !== 'workflowhandle::result'
      || normalizedText(continueOutcome.current_run_observation_source) !== 'workflowhandle::describe'
      || normalizedText(continueOutcome.selected_run_observation_source) !== 'workflowhandle::describe_selected_run'
      || normalizedText(finalResult.status) !== 'completed'
      || stringValue(finalResult.workflow_id) !== workflowId
      || stringValue(finalResult.run_id) !== continuedRunId
      || numberValue(finalResult.successor_version) !== 3) {
    failures.push('continue_as_new_replay_boundary must invoke each run callback once and route current, selected historical, and final result reads through the chain');
  }
  const provenance = nonEmptyObject(outputs.install_provenance) ? outputs.install_provenance : {};
  if (provenance.package !== 'durable-workflow'
      || provenance.requested_version !== outputs.artifact_version
      || provenance.installed_version !== outputs.artifact_version
      || !normalizedText(provenance.registry_source).includes('crates.io')
      || !/^[0-9a-f]{64}$/.test(stringValue(provenance.registry_checksum_sha256))) {
    failures.push('install_provenance must prove the exact crates.io durable-workflow package and checksum');
  }
  const payload = nonEmptyObject(outputs.payload_contract) ? outputs.payload_contract : {};
  if (payload.codec !== 'avro'
      || payload.envelope_contract !== 'durable-workflow-published-envelope'
      || payload.apache_avro_package !== 'apache-avro'
      || !truthyFlag(payload.official_crates_io_provenance)
      || !normalizedText(payload.apache_avro_registry_source).includes('crates.io')
      || !/^[0-9a-f]{64}$/.test(stringValue(payload.apache_avro_registry_checksum_sha256))) {
    failures.push('payload_contract must prove the official apache-avro crate and published Avro envelope');
  }
  if (!nonEmptyList(outputs.workflow_identities)) failures.push('workflow_identities must be non-empty');
  if (!nonEmptyObject(outputs.scenario_outcomes)) failures.push('scenario_outcomes must be non-empty');
  if (!nonEmptyList(outputs.stable_reasons)) failures.push('stable_reasons must be non-empty');
  for (const reason of ['run_cancelled', 'run_terminated', 'historical_run_command_rejected', 'run_timeout', 'workflow_task_completion_redelivery_rejected']) {
    if (!listContainsValue(outputs.stable_reasons, reason)) failures.push(`stable_reasons must include ${reason}`);
  }
  const requiredIdentityScenarios = ['instance_cancel', 'instance_terminate', 'selected_run_guard', 'typed_failed', 'typed_timed_out', 'continue_as_new_replay_boundary_predecessor', 'continue_as_new_replay_boundary_successor'];
  for (const scenario of requiredIdentityScenarios) {
    const identity = Array.isArray(outputs.workflow_identities)
      ? outputs.workflow_identities.find((entry) => normalizedText(entry?.scenario) === scenario)
      : null;
    if (!identity || !stringValue(identity.workflow_id) || !stringValue(identity.run_id)) {
      failures.push(`workflow_identities must retain workflow_id and run_id for ${scenario}`);
    }
  }
  const topology = nonEmptyObject(outputs.executor_topology) ? outputs.executor_topology : {};
  if (outputs.rust_shard_contract_version !== 3
      || outputs.shard_runner !== 'published-rust-sdk-lifecycle-surface-probe'
      || numberValue(outputs.shard_exit_status) !== 0
      || topology.server_http_process !== 'exact_published_image'
      || topology.scheduler_process !== 'exact_published_image'
      || topology.rust_executor !== 'host_rust_container'
      || !truthyFlag(topology.rust_executor_outside_server_image)) {
    failures.push('executor_topology must prove exact-image HTTP and scheduler processes plus the external Rust executor');
  }
  if (stringValue(outputs.server_version) !== stringValue(artifactVersions({}).server)) {
    failures.push('server_version must match the pinned published server version');
  }
  if (!JSON.stringify(outputs.server_cluster_info ?? {}).includes(stringValue(outputs.server_version))) {
    failures.push('server_cluster_info must report the pinned published server version');
  }
  if (stringValue(outputs.artifact_version) !== stringValue(artifactVersions({})['sdk-rust'])) {
    failures.push('artifact_version must match the pinned published Rust SDK version');
  }
  return failures;
}

function validateOperatorDiagnostics(outputs) {
  const failures = [];
  for (const field of ['cli_fields', 'api_fields', 'history_fields', 'waterline_fields']) {
    if (!nonEmptyList(outputs[field])) {
      failures.push(`operator diagnostics must include ${field}`);
    }
  }
  if (!nonEmptyCollection(outputs.diagnostic_transition_matrix)) {
    failures.push('operator diagnostics must include a transition matrix');
  }

  return failures;
}

function owningSurface(scenarioId, classification) {
  if (classification === 'runner-gap') {
    return 'conformance_harness';
  }
  if (classification === 'stale-artifact') {
    return 'release-artifacts';
  }
  if (classification === 'pipeline-churn') {
    return 'pipeline';
  }
  if (scenarioId.startsWith('continue_as_new')) {
    return 'workflow-runtime-and-server';
  }
  if (scenarioId.startsWith('cancellation') || scenarioId.startsWith('termination')) {
    return 'server-cli-and-sdks';
  }
  if (scenarioId.includes('duplicate_start') || scenarioId.includes('timeout')) {
    return 'server';
  }
  if (scenarioId.includes('retry')) {
    return 'server-sdk-and-docs';
  }
  if (scenarioId.startsWith('php')) {
    return 'workflow-php';
  }
  if (scenarioId.startsWith('python')) {
    return 'sdk-python';
  }
  if (scenarioId.startsWith('rust')) {
    return 'sdk-rust-and-server';
  }
  if (scenarioId.includes('operator')) {
    return 'cli-api-history-waterline';
  }
  return classification === 'coverage-gap' ? 'conformance_harness' : 'server';
}

function findingTypeFor(classification, status) {
  if (status === 'unsupported') {
    return 'unsupported_public_surface';
  }
  return {
    'product-gap': 'product_behavior_gap',
    'coverage-gap': 'conformance_runner_coverage_gap',
    'runner-gap': 'conformance_runner_blocked',
    'stale-artifact': 'stale_or_unpinned_artifact',
    'pipeline-churn': 'pipeline_churn',
  }[classification] ?? 'product_behavior_gap';
}

function nextAcceptance(scenario, status) {
  if (status === 'unsupported') {
    return `Publish documented typed refusal evidence for ${scenario.id}, or implement the lifecycle surface and rerun the cell against published artifacts.`;
  }

  const behavior = scenario.required_behavior || 'the required lifecycle behavior is exercised';
  return `Run ${scenario.id} against the exact published artifact tuple and attach evidence that ${behavior}.`;
}

function normalizeSuppliedFindings(entry, scenario, status, classification, fallbackSummary) {
  const supplied = entry.linked_findings ?? entry.linkedFindings ?? entry.findings ?? [];
  if (!Array.isArray(supplied) || supplied.length === 0) {
    return [];
  }

  return supplied
    .filter((finding) => finding && typeof finding === 'object' && !Array.isArray(finding))
    .map((finding, index) => ({
      finding_id: stringValue(finding.finding_id ?? finding.findingId)
        || `workflow-lifecycle-${scenario.id.replace(/_/g, '-')}-${classification}-${index + 1}`,
      finding_type: stringValue(finding.finding_type ?? finding.findingType)
        || findingTypeFor(classification, status),
      classification: stringValue(finding.classification) || classification,
      scenario_id: stringValue(finding.scenario_id ?? finding.scenarioId) || scenario.id,
      owning_surface: stringValue(finding.owning_surface ?? finding.owningSurface)
        || owningSurface(scenario.id, classification),
      summary: stringValue(finding.summary) || fallbackSummary,
      observed_evidence: nonEmptyObject(finding.observed_evidence ?? finding.observedEvidence)
        ? (finding.observed_evidence ?? finding.observedEvidence)
        : {},
      next_acceptance_criterion: stringValue(finding.next_acceptance_criterion ?? finding.nextAcceptanceCriterion)
        || nextAcceptance(scenario, status),
    }));
}

function generatedFinding(scenario, status, classification, summary) {
  return {
    finding_id: `workflow-lifecycle-${scenario.id.replace(/_/g, '-')}-${classification.replace(/-/g, '-')}`,
    finding_type: findingTypeFor(classification, status),
    classification,
    scenario_id: scenario.id,
    owning_surface: owningSurface(scenario.id, classification),
    summary,
    next_acceptance_criterion: nextAcceptance(scenario, status),
  };
}

function normalizeScenario(scenario, entry, policy) {
  const supplied = entry ?? {};
  let status = normalizeStatus(supplied.status ?? supplied.outcome ?? supplied.verdict);
  let classification = normalizeClassification(status, supplied.classification ?? supplied.root_cause ?? supplied.rootCause);
  const outputs = outputsFrom(supplied);
  const missingEvidence = requiredEvidenceMissing(scenario, outputs);
  const executed = truthyFlag(supplied.published_artifact_cell_executed)
    || truthyFlag(supplied.publishedArtifactCellExecuted)
    || truthyFlag(outputs.published_artifact_cell_executed)
    || truthyFlag(outputs.publishedArtifactCellExecuted);
  const summaries = [];

  if (!entry) {
    summaries.push(`No host runtime evidence was supplied for ${scenario.id}.`);
  }

  if (status === 'pass' && !executed) {
    status = 'not_covered';
    classification = 'coverage-gap';
    summaries.push(`The host evidence for ${scenario.id} claimed pass without proving the published-artifact cell executed.`);
  }

  if (status === 'pass' && policy.local_product_source_checkout_used_as_pass_evidence) {
    status = 'not_covered';
    classification = 'coverage-gap';
    summaries.push(`The host evidence for ${scenario.id} used a local product source checkout as pass evidence.`);
  }

  if (status === 'pass' && missingEvidence.length > 0) {
    status = 'not_covered';
    classification = 'coverage-gap';
    summaries.push(`The host evidence for ${scenario.id} is missing required field(s): ${missingEvidence.join(', ')}.`);
  }

  const semanticFailures = status === 'pass' ? semanticEvidenceFailures(scenario, outputs) : [];
  if (semanticFailures.length > 0) {
    status = 'fail';
    classification = 'product-gap';
    outputs.semantic_validation_failures = semanticFailures;
    summaries.push(`The host evidence for ${scenario.id} contradicts the lifecycle contract: ${semanticFailures.join('; ')}.`);
  }

  const refusal = typedRefusalEvidence(supplied, outputs);
  if (status === 'unsupported') {
    outputs.typed_refusal = {
      typed_error: refusal.typed_error || null,
      refusal_reason: refusal.refusal_reason || null,
      documented: refusal.documented,
    };

    if (!refusal.valid) {
      status = 'not_covered';
      classification = 'coverage-gap';
      summaries.push(`The unsupported ${scenario.id} cell did not include documented typed refusal evidence.`);
    } else if (
      scenario.id === 'workflow_retry_backoff_or_refusal'
      && executed
      && missingEvidence.length === 0
      && truthyFlag(outputs.docs_match)
      && !policy.local_product_source_checkout_used_as_pass_evidence
    ) {
      status = 'pass';
      classification = 'passed';
    }
  }

  if (!ALLOWED_STATUSES.has(status)) {
    status = 'not_covered';
    classification = 'coverage-gap';
  }

  outputs.required_behavior ??= scenario.required_behavior ?? null;
  outputs.required_evidence ??= scenario.required_evidence ?? [];
  outputs.published_artifact_cell_executed = executed;
  outputs.local_product_source_checkout_used_as_pass_evidence = policy.local_product_source_checkout_used_as_pass_evidence;

  const defaultSummary = summaries[0]
    ?? stringValue(supplied.summary)
    ?? (status === 'pass'
      ? `${scenario.id} passed against the published artifact tuple.`
      : `${scenario.id} did not pass against the published artifact tuple.`);
  const suppliedFindings = status === 'pass'
    ? []
    : normalizeSuppliedFindings(supplied, scenario, status, classification, defaultSummary);
  const linkedFindings = status === 'pass'
    ? []
    : (suppliedFindings.length > 0 ? suppliedFindings : [generatedFinding(scenario, status, classification, defaultSummary)]);

  return {
    scenario_id: scenario.id,
    status,
    classification: status === 'pass' ? 'passed' : classification,
    observed_outputs: outputs,
    missing_required_evidence: missingEvidence,
    linked_findings: linkedFindings,
  };
}

function findingForPolicy(index, classification, summary) {
  return {
    finding_id: `workflow-lifecycle-${classification.replace(/-/g, '-')}-${index + 1}`,
    finding_type: findingTypeFor(classification, classification === 'runner-gap' ? 'runner_blocked' : 'fail'),
    classification,
    scenario_id: 'artifact_policy',
    owning_surface: owningSurface('artifact_policy', classification),
    summary,
    next_acceptance_criterion: 'Resolve the lifecycle runner policy failure and rerun against a concrete published artifact tuple.',
  };
}

function cellOutcomes(results) {
  const outcomes = {};
  for (const [scenarioId, result] of Object.entries(results)) {
    outcomes[scenarioId] = {
      status: result.status,
      classification: result.classification,
      finding_ids: result.linked_findings.map((finding) => finding.finding_id),
    };
  }

  return outcomes;
}

const manifest = loadManifest();
const evidenceRecord = mergeEvidenceSidecars(loadEvidence());
const evidence = evidenceRecord.value;
const scenarios = scenarioDefinitions(manifest);
const versions = artifactVersions(evidence);
const sources = artifactSources(versions, evidence);
const policy = sourcePolicy(evidence, sources);
const pinFailures = exactPinFailures(versions, sources);
const mismatchFailures = artifactVersionMismatchFailures(evidence, versions);
const sourcePolicyFailures = [];
if (policy.local_product_source_checkout_used_as_pass_evidence) {
  sourcePolicyFailures.push('local product source checkouts were used as pass evidence');
}

const inputs = scenarioInputs(evidence);
const scenarioResults = {};
const findings = [];
const findingLinks = {};

for (const scenario of scenarios) {
  const result = normalizeScenario(scenario, inputs[scenario.id], policy);
  scenarioResults[scenario.id] = result;
  if (result.linked_findings.length > 0) {
    findings.push(...result.linked_findings);
    findingLinks[scenario.id] = result.linked_findings.map((finding) => finding.finding_id);
  }
}

let policyFindingIndex = 0;
for (const failure of pinFailures) {
  const finding = findingForPolicy(policyFindingIndex++, 'stale-artifact', failure);
  findings.push(finding);
  findingLinks.artifact_policy = [...(findingLinks.artifact_policy ?? []), finding.finding_id];
}
for (const failure of mismatchFailures) {
  const finding = findingForPolicy(policyFindingIndex++, 'stale-artifact', failure);
  findings.push(finding);
  findingLinks.artifact_policy = [...(findingLinks.artifact_policy ?? []), finding.finding_id];
}
for (const failure of sourcePolicyFailures) {
  const finding = findingForPolicy(policyFindingIndex++, 'coverage-gap', failure);
  findings.push(finding);
  findingLinks.source_policy = [...(findingLinks.source_policy ?? []), finding.finding_id];
}

const provenLifecycleCells = Object.entries(scenarioResults)
  .filter(([, result]) => result.status === 'pass')
  .map(([scenarioId]) => scenarioId);
const unprovenLifecycleCells = Object.entries(scenarioResults)
  .filter(([, result]) => result.status !== 'pass')
  .map(([scenarioId]) => scenarioId);
const runnerBlocked = truthyFlag(evidence.runner_blocked)
  || truthyFlag(evidence.runnerBlocked)
  || Object.values(scenarioResults).some((result) => result.status === 'runner_blocked');
const hasPolicyFailures = pinFailures.length > 0 || mismatchFailures.length > 0 || sourcePolicyFailures.length > 0;
const allRequiredPassed = scenarios.length > 0
  && provenLifecycleCells.length === scenarios.length
  && unprovenLifecycleCells.length === 0
  && !hasPolicyFailures
  && !runnerBlocked;
const finishedAt = now();
const outcome = allRequiredPassed ? 'pass' : 'non_passing';
const lifecycleCellOutcomes = cellOutcomes(scenarioResults);
const evidenceSource = evidenceRecord.source;

const result = {
  schema: RESULT_SCHEMA,
  version: 2,
  started_at: STARTED_AT,
  finished_at: finishedAt,
  generated_at: finishedAt,
  outcome,
  runner_blocked: runnerBlocked,
  artifact_versions: versions,
  published_artifact_versions: versions,
  artifact_sources: sources,
  scenario_manifest: {
    source_path: MANIFEST_PATH,
    category: manifest.category || 'workflow_lifecycle_contract',
  },
  source_policy: policy,
  no_local_product_source_checkout_pass_evidence: !policy.local_product_source_checkout_used_as_pass_evidence,
  local_product_source_checkouts_used: policy.local_product_source_checkouts_used,
  evidence_source: evidenceSource,
  evidence_schema: stringValue(evidence.schema) || null,
  proven_lifecycle_cells: provenLifecycleCells,
  unproven_lifecycle_cells: unprovenLifecycleCells,
  lifecycle_cell_outcomes: lifecycleCellOutcomes,
  per_cell_outcomes: lifecycleCellOutcomes,
  scenario_results: scenarioResults,
  findings,
  finding_links: findingLinks,
  public_docs_statement: 'Passing workflow lifecycle conformance requires every required lifecycle cell to pass against pinned published artifacts. Unsupported cells are non-passing unless the product later defines them as supported behavior.',
};

const record = {
  schema: RECORD_SCHEMA,
  version: 2,
  experiment: 'workflow-lifecycle',
  outcome,
  runnerBlocked,
  artifactVersions: versions,
  artifactSources: sources,
  sourcePolicy: policy,
  localProductSourceCheckoutsUsed: policy.local_product_source_checkouts_used,
  startedAt: STARTED_AT,
  finishedAt,
  generatedAt: finishedAt,
  scenarioResults,
  lifecycleCellOutcomes,
  findings,
  findingLinks,
  result,
};

writeJson('pins.json', {
  schema: 'durable-workflow.v2.workflow-lifecycle.published-artifact-pins',
  generated_at: finishedAt,
  artifact_versions: versions,
  artifact_sources: sources,
  source_policy: policy,
});
writeJson('run-metadata.json', {
  schema: 'durable-workflow.v2.workflow-lifecycle.run-metadata',
  started_at: STARTED_AT,
  finished_at: finishedAt,
  result_schema: RESULT_SCHEMA,
  outcome,
  runner_blocked: runnerBlocked,
  evidence_source: evidenceSource,
  local_product_source_checkouts_used: policy.local_product_source_checkouts_used,
});
writeJson('workflow-lifecycle-result.json', result);
writeJson('workflow-lifecycle-record.json', record);
writeJson('workflow-lifecycle-findings.json', findings);
writeJson('lifecycle-result.json', result);
writeJson('lifecycle-record.json', record);
