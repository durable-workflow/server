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
const REQUIRED_ARTIFACTS = ['server', 'cli', 'workflow', 'sdk-python', 'waterline'];
const FORBIDDEN_SOURCE_TOKENS = [
  'local_product_source_checkout',
  'workspace_repo_as_artifact_under_test',
  'source_checkout',
  'local_checkout',
];
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
  return ['php_sdk_lifecycle_surface', 'python_sdk_lifecycle_surface'].includes(scenarioId)
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

  failures.push('workflow retry pass evidence must prove retry attempts; documented refusal must use unsupported status');

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
const evidenceRecord = loadEvidence();
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
  version: 1,
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
  version: 1,
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
