import fs from 'node:fs';
import path from 'node:path';
import { isExactPythonRelease, isExactSemverRelease } from './version-identities.mjs';

const RESULT_DIR = mustEnv('RESULT_DIR');
const STARTED_AT = mustEnv('STARTED_AT');
const MANIFEST_PATH = mustEnv('MANIFEST_PATH');

const RESULT_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.result';
const RECORD_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.published-artifacts';
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
const REQUIRED_ARTIFACTS = ['server', 'cli', 'workflow', 'sdk-php', 'sdk-python', 'sdk-rust', 'waterline'];
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
const SHARD_DIAGNOSTIC_SCHEMA = 'durable-workflow.v2.workflow-lifecycle.shard-diagnostic';
const SHARD_DIAGNOSTIC_MAX_BYTES = 8192;
const SHARD_DIAGNOSTIC_EXCERPT_BYTES = 2048;
const LIFECYCLE_SHARD_IDS = new Set([
  'php_sdk_lifecycle_surface',
  'python_sdk_lifecycle_surface',
  'rust_sdk_lifecycle_surface',
]);
const FORBIDDEN_FAILURE_FIELD_RE = /(authorization|credential|password|passwd|secret|token|api[_-]?key|std(?:out|err)|process[_-]?output|command[_-]?output|logs?)/i;
const SENSITIVE_DIAGNOSTIC_FIELD_RE = /(authorization|credential|password|passwd|secret|token|api[_-]?key)/i;
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
    description: 'The exact released PHP SDK package drives distinct client and worker processes against the matching public server.',
    required_evidence: ['sdk', 'covered_cells', 'unsupported_cells', 'typed_errors', 'artifact_version', 'server_version', 'install_provenance', 'apache_avro_provenance', 'client_processes', 'worker_processes', 'callback_counts', 'history_assertions', 'local_product_source_checkouts_used'],
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
    .replace(/((?:credential|password|passwd|secret|token|api[_-]?key)["']?\s*[:=]\s*["']?)[^"'\s,;}]+/ig, '$1[REDACTED]')
    .replace(/(https?:\/\/)[^\s/@:]+:[^\s/@]+@/ig, '$1[REDACTED]@');
  return truncateUtf8(text, limit);
}

function serializedBytes(value) {
  return Buffer.byteLength(JSON.stringify(value), 'utf8');
}

function truncateUtf8(value, limit) {
  const source = String(value ?? '');
  if (Buffer.byteLength(source, 'utf8') <= limit) {
    return source;
  }

  const characters = [...source];
  let low = 0;
  let high = characters.length;
  let accepted = '';
  while (low <= high) {
    const middle = Math.floor((low + high) / 2);
    const candidate = characters.slice(0, middle).join('');
    if (Buffer.byteLength(candidate, 'utf8') <= limit) {
      accepted = candidate;
      low = middle + 1;
    } else {
      high = middle - 1;
    }
  }

  return accepted;
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

function diagnosticValue(value, depth = 0, stringLimit = 512) {
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value === 'string') {
    return redactSensitiveText(value, stringLimit);
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return value;
  }
  if (depth >= 10) {
    return '[depth limit reached]';
  }
  if (Array.isArray(value)) {
    return value.slice(0, 24).map((entry) => diagnosticValue(entry, depth + 1, stringLimit));
  }
  if (typeof value !== 'object') {
    return redactSensitiveText(value, stringLimit);
  }

  const bounded = {};
  for (const [key, entry] of Object.entries(value).slice(0, 48)) {
    const safeKey = redactSensitiveText(key, 128);
    bounded[safeKey] = SENSITIVE_DIAGNOSTIC_FIELD_RE.test(safeKey)
      ? '[REDACTED]'
      : diagnosticValue(entry, depth + 1, stringLimit);
  }

  return bounded;
}

function boundedDiagnosticObject(value, limit = 3072) {
  if (!value || typeof value !== 'object') {
    return null;
  }

  const sourceOversized = serializedBytes(value) > limit;
  const bounded = diagnosticValue(value);
  if (serializedBytes(bounded) <= limit) {
    const retained = sourceOversized ? { ...bounded, _truncated: true } : bounded;
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  const retained = { _truncated: true };
  const structuredBudget = Math.max(64, Math.floor(limit / 2));
  for (const [key, entry] of Object.entries(bounded)) {
    const candidate = { ...retained, [key]: entry };
    if (serializedBytes(candidate) <= structuredBudget) {
      retained[key] = entry;
    }
  }

  const excerpt = redactSensitiveText(JSON.stringify(bounded), limit);
  const characters = [...excerpt];
  let low = 0;
  let high = characters.length;
  let accepted = retained;
  while (low <= high) {
    const middle = Math.floor((low + high) / 2);
    const candidate = {
      ...retained,
      bounded_json_excerpt: characters.slice(0, middle).join(''),
    };
    if (serializedBytes(candidate) <= limit) {
      accepted = candidate;
      low = middle + 1;
    } else {
      high = middle - 1;
    }
  }

  return accepted;
}

function boundedAssertionFailures(value, limit = 5632) {
  const source = Array.isArray(value)
    ? value
    : (Array.isArray(value?.operations) ? value.operations : []);
  if (source.length === 0) {
    return null;
  }

  const build = (detailLimit, truncated = false) => {
    const retained = {
      count: source.length,
      operations: source.map((entry) => {
        const failure = firstObject(entry);
        return {
          assertion: redactSensitiveText(failure.assertion, 96),
          operation: redactSensitiveText(failure.operation, 160),
          classification: redactSensitiveText(failure.classification, 64),
          owning_surface: redactSensitiveText(failure.owning_surface, 128),
          expected: boundedDiagnosticObject(firstObject(failure.expected), detailLimit),
          observed: boundedDiagnosticObject(firstObject(failure.observed), detailLimit),
        };
      }),
    };
    if (truncated) {
      retained._truncated = true;
    }
    return retained;
  };

  for (const detailLimit of [1024, 768, 512, 384, 256, 128, 64]) {
    const retained = build(detailLimit, detailLimit < 1024 || truthyFlag(value?._truncated));
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  const labelsOnly = {
    count: source.length,
    operations: source.map((entry) => {
      const failure = firstObject(entry);
      return {
        assertion: redactSensitiveText(failure.assertion, 64),
        operation: redactSensitiveText(failure.operation, 96),
        owning_surface: redactSensitiveText(failure.owning_surface, 64),
        expected: { _truncated: true },
        observed: { _truncated: true },
      };
    }),
    _truncated: true,
  };
  if (serializedBytes(labelsOnly) > limit) {
    throw new Error('Unable to retain lifecycle assertion operation labels within the byte limit.');
  }

  return labelsOnly;
}

function selectedDiagnosticObject(value, fields, stringLimit = 256) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }

  return Object.fromEntries(fields
    .filter((field) => Object.prototype.hasOwnProperty.call(value, field))
    .map((field) => [field, diagnosticValue(value[field], 0, stringLimit)]));
}

function selectedDiagnosticList(value, fields, limit) {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.slice(0, limit).map((entry) => selectedDiagnosticObject(entry, fields));
}

function compactTaskQueuePayload(value, sampleLimit, minimal = false) {
  const payload = firstObject(value);
  const statsSource = firstObject(payload.stats);
  const admissionSource = firstObject(payload.admission);
  const stats = selectedDiagnosticObject(statsSource, [
    'approximate_backlog_count',
    'tasks_added_last_minute',
    'tasks_dispatched_last_minute',
  ]);
  const taskStatsFields = minimal
    ? ['ready_count', 'leased_count']
    : ['ready_count', 'leased_count', 'expired_lease_count'];
  for (const field of ['workflow_tasks', 'activity_tasks']) {
    if (Object.prototype.hasOwnProperty.call(statsSource, field)) {
      stats[field] = selectedDiagnosticObject(statsSource[field], taskStatsFields);
    }
  }
  if (Object.prototype.hasOwnProperty.call(statsSource, 'pollers')) {
    stats.pollers = selectedDiagnosticObject(statsSource.pollers, ['active_count', 'stale_count']);
  }
  if (!minimal && Object.prototype.hasOwnProperty.call(statsSource, 'oldest_ready_task')) {
    stats.oldest_ready_task = selectedDiagnosticObject(statsSource.oldest_ready_task, [
      'task_id',
      'task_type',
      'workflow_id',
      'run_id',
      'created_at',
    ]);
  }

  const admissionFields = minimal
    ? ['status']
    : [
      'status',
      'budget_source',
      'active_worker_count',
      'configured_slot_count',
      'leased_count',
      'ready_count',
      'available_slot_count',
      'server_active_lease_count',
      'server_remaining_active_lease_capacity',
      'approximate_pending_count',
      'remaining_pending_capacity',
    ];
  const admission = Object.fromEntries(
    ['workflow_tasks', 'activity_tasks', 'query_tasks']
      .filter((field) => Object.prototype.hasOwnProperty.call(admissionSource, field))
      .map((field) => [field, selectedDiagnosticObject(admissionSource[field], admissionFields)]),
  );

  const retained = {};
  if (Object.prototype.hasOwnProperty.call(payload, 'name')
    || Object.prototype.hasOwnProperty.call(payload, 'task_queue')) {
    retained.name = redactSensitiveText(payload.name ?? payload.task_queue, 128);
  }
  if (Object.prototype.hasOwnProperty.call(payload, 'stats')) {
    retained.stats = stats;
  }
  if (Object.prototype.hasOwnProperty.call(payload, 'pollers')) {
    retained.pollers = selectedDiagnosticList(payload.pollers, [
      'worker_id',
      'status',
      'is_stale',
      'last_heartbeat_at',
      'max_concurrent_workflow_tasks',
      'max_concurrent_activity_tasks',
    ], sampleLimit);
  }
  if (Object.prototype.hasOwnProperty.call(payload, 'current_leases')) {
    retained.current_leases = selectedDiagnosticList(payload.current_leases, [
      'task_id',
      'task_type',
      'workflow_id',
      'run_id',
      'lease_owner',
      'lease_expires_at',
      'is_expired',
      'workflow_task_attempt',
      'activity_attempt_id',
      'attempt_number',
    ], sampleLimit);
  }
  if (Object.prototype.hasOwnProperty.call(payload, 'admission')) {
    retained.admission = admission;
  }
  for (const field of ['reason', 'message']) {
    if (Object.prototype.hasOwnProperty.call(payload, field)) {
      retained[field] = redactSensitiveText(payload[field], 256);
    }
  }

  return retained;
}

function boundedTaskQueueDiagnostic(value, limit = 1280) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  const bounded = diagnosticValue(value);
  if (serializedBytes(bounded) <= limit) {
    return bounded;
  }

  const payload = firstObject(value.payload);
  const build = (sampleLimit, minimal = false) => {
    const retained = {
      payload: compactTaskQueuePayload(payload, sampleLimit, minimal),
      truncated: true,
    };
    if (Object.prototype.hasOwnProperty.call(value, 'http_status')
      || Object.prototype.hasOwnProperty.call(value, 'httpStatus')) {
      retained.http_status = firstInteger(value.http_status, value.httpStatus);
    }
    if (Object.prototype.hasOwnProperty.call(value, 'probe_error')
      || Object.prototype.hasOwnProperty.call(value, 'probeError')) {
      retained.probe_error = diagnosticValue(value.probe_error ?? value.probeError, 0, 256);
    }

    return retained;
  };

  for (const [sampleLimit, minimal] of [[4, false], [2, false], [1, false], [1, true]]) {
    const retained = build(sampleLimit, minimal);
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  throw new Error('Unable to retain structured task-queue diagnostics within the shard byte limit.');
}

function boundedPublicResponse(value, limit = 384) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const bounded = diagnosticValue(value, 0, 192);
  const priorities = [
    'error',
    'reason',
    'code',
    'status',
    'status_code',
    'retryable',
    'non_retryable',
    'operation',
    'http_method',
    'endpoint',
    'task_id',
    'workflow_task_id',
    'activity_task_id',
    'query_task_id',
    'workflow_id',
    'run_id',
    'message',
  ];
  const orderedKeys = [
    ...priorities.filter((field) => Object.prototype.hasOwnProperty.call(bounded, field)),
    ...Object.keys(bounded).filter((field) => !priorities.includes(field)),
  ];
  const retained = {};
  for (const field of orderedKeys) {
    const candidate = {...retained, [field]: bounded[field]};
    if (serializedBytes(candidate) <= limit - 20) {
      retained[field] = bounded[field];
    }
  }
  if (Object.keys(retained).length < Object.keys(bounded).length) {
    retained._truncated = true;
  }

  return serializedBytes(retained) <= limit ? retained : {_truncated: true};
}

function boundedProtocolFailure(value, limit = 1280) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const publicResponse = firstObject(value.public_error_envelope, value.publicErrorEnvelope);
  const reason = value.reason
    ?? publicResponse.reason
    ?? publicResponse.error
    ?? publicResponse.code
    ?? publicResponse.message
    ?? null;
  const retryable = typeof value.retryable === 'boolean'
    ? value.retryable
    : (typeof publicResponse.retryable === 'boolean'
      ? publicResponse.retryable
      : (typeof publicResponse.non_retryable === 'boolean' ? !publicResponse.non_retryable : null));
  const build = (responseLimit, terse = false) => ({
    schema: redactSensitiveText(value.schema, 96),
    classification: redactSensitiveText(value.classification, 32),
    owning_surface: redactSensitiveText(value.owning_surface, 64),
    process: redactSensitiveText(value.process, 48),
    operation: redactSensitiveText(value.operation, terse ? 96 : 160),
    http_method: redactSensitiveText(value.http_method ?? value.httpMethod, 16),
    endpoint_class: redactSensitiveText(value.endpoint_class ?? value.endpointClass, 64),
    endpoint: terse ? null : redactSensitiveText(value.endpoint, 192),
    status_code: firstInteger(value.status_code, value.statusCode),
    reason: redactSensitiveText(reason, terse ? 96 : 192) || null,
    retryable,
    task_id: redactSensitiveText(
      value.task_id
        ?? publicResponse.task_id
        ?? publicResponse.workflow_task_id
        ?? publicResponse.activity_task_id
        ?? publicResponse.query_task_id,
      terse ? 96 : 160,
    ) || null,
    workflow_id: redactSensitiveText(value.workflow_id ?? publicResponse.workflow_id, terse ? 96 : 160) || null,
    run_id: redactSensitiveText(value.run_id ?? publicResponse.run_id, terse ? 96 : 160) || null,
    public_error_envelope: boundedPublicResponse(publicResponse, responseLimit),
    _truncated: truthyFlag(value._truncated) || serializedBytes(value) > limit,
  });

  for (const [responseLimit, terse] of [[512, false], [384, false], [256, false], [160, true], [96, true]]) {
    const retained = build(responseLimit, terse);
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  throw new Error('Unable to retain structured worker-protocol failure diagnostics within the shard byte limit.');
}

function boundedRuntimeException(value, limit = 896) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const build = (messageLimit, terse = false) => ({
    schema: redactSensitiveText(value.schema, 96),
    classification: redactSensitiveText(value.classification, 32),
    owning_surface: redactSensitiveText(value.owning_surface, 64),
    process: redactSensitiveText(value.process, 48),
    operation: redactSensitiveText(value.operation, terse ? 96 : 160),
    exception_type: redactSensitiveText(value.exception_type ?? value.exceptionType, terse ? 96 : 160) || null,
    message: redactSensitiveText(value.message, messageLimit) || null,
    task_id: redactSensitiveText(value.task_id, terse ? 64 : 128) || null,
    workflow_id: redactSensitiveText(value.workflow_id, terse ? 64 : 128) || null,
    run_id: redactSensitiveText(value.run_id, terse ? 64 : 128) || null,
    _truncated: truthyFlag(value._truncated) || serializedBytes(value) > limit,
  });

  for (const [messageLimit, terse] of [[256, false], [160, false], [96, true], [48, true]]) {
    const retained = build(messageLimit, terse);
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  throw new Error('Unable to retain structured worker runtime exception diagnostics within the shard byte limit.');
}

function boundedServerErrorRecord(value, limit = 768) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const build = (excerptLimit) => ({
    schema: redactSensitiveText(value.schema, 96),
    source: redactSensitiveText(value.source, 64),
    matched_by: redactSensitiveText(value.matched_by ?? value.matchedBy, 64),
    timestamp: redactSensitiveText(value.timestamp, 64) || null,
    level: redactSensitiveText(value.level, 32) || null,
    status_code: firstInteger(value.status_code, value.statusCode),
    reason: redactSensitiveText(value.reason, 128) || null,
    exception_type: redactSensitiveText(value.exception_type ?? value.exceptionType, 160) || null,
    task_id: redactSensitiveText(value.task_id, 128) || null,
    workflow_id: redactSensitiveText(value.workflow_id, 128) || null,
    run_id: redactSensitiveText(value.run_id, 128) || null,
    excerpt: redactSensitiveText(value.excerpt, excerptLimit),
    truncated: truthyFlag(value.truncated) || Buffer.byteLength(stringValue(value.excerpt), 'utf8') > excerptLimit,
    max_bytes: firstInteger(value.max_bytes, value.maxBytes),
  });

  for (const excerptLimit of [384, 256, 160, 96, 48]) {
    const retained = build(excerptLimit);
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  const compact = {
    schema: redactSensitiveText(value.schema, 64),
    source: redactSensitiveText(value.source, 48),
    matched_by: redactSensitiveText(value.matched_by ?? value.matchedBy, 48),
    level: redactSensitiveText(value.level, 32) || null,
    status_code: firstInteger(value.status_code, value.statusCode),
    reason: redactSensitiveText(value.reason, 96) || null,
    exception_type: redactSensitiveText(value.exception_type ?? value.exceptionType, 96) || null,
    task_id: redactSensitiveText(value.task_id, 96) || null,
    workflow_id: redactSensitiveText(value.workflow_id, 96) || null,
    run_id: redactSensitiveText(value.run_id, 96) || null,
    excerpt: redactSensitiveText(value.excerpt, 48),
    truncated: true,
  };
  if (serializedBytes(compact) <= limit) {
    return compact;
  }

  const essential = {
    source: redactSensitiveText(value.source, 48),
    matched_by: redactSensitiveText(value.matched_by ?? value.matchedBy, 48),
    level: redactSensitiveText(value.level, 32) || null,
    status_code: firstInteger(value.status_code, value.statusCode),
    reason: redactSensitiveText(value.reason, 64) || null,
    exception_type: redactSensitiveText(value.exception_type ?? value.exceptionType, 64) || null,
    task_id: redactSensitiveText(value.task_id, 64) || null,
    excerpt: redactSensitiveText(value.excerpt, 32),
    truncated: true,
  };
  if (serializedBytes(essential) <= limit) {
    return essential;
  }

  throw new Error('Unable to retain the structured server error record within the shard byte limit.');
}

function boundedCapturedExcerpt(value, limit = 384) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const build = (excerptLimit, includeMetadata = true) => {
    const retained = {
      excerpt: redactSensitiveText(value.excerpt, excerptLimit),
      truncated: truthyFlag(value.truncated)
        || Buffer.byteLength(stringValue(value.excerpt), 'utf8') > excerptLimit,
    };
    if (includeMetadata) {
      retained.schema = redactSensitiveText(value.schema, 96);
      retained.source = redactSensitiveText(value.source, 64);
      retained.max_excerpt_bytes = firstInteger(value.max_excerpt_bytes, value.maxExcerptBytes);
    }

    return retained;
  };

  for (const excerptLimit of [256, 160, 96, 48, 24]) {
    const retained = build(excerptLimit, true);
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }
  const essential = build(Math.max(8, limit - 48), false);
  if (serializedBytes(essential) <= limit) {
    return essential;
  }

  throw new Error('Unable to retain captured process output within the shard byte limit.');
}

function boundedCompanionFailure(value, limit = 4608) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }
  const worker = firstObject(value.worker);
  const server = firstObject(value.server);
  const build = (detailLimit, logLimit, protocolLimit, errorLimit, truncated = false) => {
    const retained = {
      schema: redactSensitiveText(value.schema, 128),
      failure_kind: redactSensitiveText(value.failure_kind, 96),
      operation: redactSensitiveText(value.operation, 160),
      classification: redactSensitiveText(value.classification, 64),
      owning_surface: redactSensitiveText(value.owning_surface, 128),
      classification_basis: redactSensitiveText(value.classification_basis, 128),
      client_failure: boundedDiagnosticObject(value.client_failure, detailLimit),
      worker: {
        worker_id: redactSensitiveText(worker.worker_id, 128),
        process_state: boundedDiagnosticObject(worker.process_state, detailLimit),
        last_protocol_failure: boundedProtocolFailure(
          worker.last_protocol_failure ?? worker.lastProtocolFailure,
          protocolLimit,
        ),
        last_runtime_exception: boundedRuntimeException(
          worker.last_runtime_exception ?? worker.lastRuntimeException,
          Math.min(protocolLimit, 896),
        ),
        structured_stderr: boundedCapturedExcerpt(
          worker.structured_stderr ?? worker.structuredStderr,
          logLimit,
        ),
        server_registration: boundedDiagnosticObject(worker.server_registration, detailLimit),
      },
      server: {
        health: boundedDiagnosticObject(server.health, detailLimit),
        run_state: boundedDiagnosticObject(server.run_state, detailLimit),
        history: boundedDiagnosticObject(server.history, detailLimit),
        task_queue: boundedTaskQueueDiagnostic(server.task_queue, Math.max(1280, detailLimit)),
        error_record_required: server.error_record_required === true,
        error_record: boundedServerErrorRecord(
          server.error_record ?? server.errorRecord,
          errorLimit,
        ),
        process_log: boundedCapturedExcerpt(server.process_log ?? server.processLog, logLimit),
      },
      retained_after_cleanup: value.retained_after_cleanup === true,
      max_bytes: firstInteger(value.max_bytes),
    };
    if (truncated || truthyFlag(value.truncated)) {
      retained.truncated = true;
    }
    return retained;
  };

  for (const [detailLimit, logLimit, protocolLimit, errorLimit] of [
    [640, 640, 1280, 768],
    [512, 512, 1152, 640],
    [384, 384, 1024, 512],
    [256, 320, 896, 448],
    [192, 256, 768, 384],
  ]) {
    const retained = build(detailLimit, logLimit, protocolLimit, errorLimit, detailLimit < 640);
    if (serializedBytes(retained) <= limit) {
      return retained;
    }
  }

  const labelsOnly = build(128, 128, 704, 320, true);
  if (serializedBytes(labelsOnly) > limit) {
    throw new Error('Unable to retain PHP SDK companion diagnostics within the shard byte limit.');
  }
  return labelsOnly;
}

function firstObject(...values) {
  return values.find((value) => value && typeof value === 'object' && !Array.isArray(value)) ?? {};
}

function firstInteger(...values) {
  for (const value of values) {
    if (Number.isInteger(value)) {
      return value;
    }
    if (typeof value === 'string' && /^-?\d+$/.test(value.trim())) {
      return Number(value);
    }
  }

  return null;
}

function diagnosticProcessState(status, outputs, runtimeFailure, workerStartup, companion) {
  const companionWorker = firstObject(companion.worker);
  const supplied = firstObject(
    companionWorker.process_state,
    companionWorker.processState,
    outputs.process_state,
    outputs.processState,
  );
  const exitCode = firstInteger(
    workerStartup.process_exit_code,
    supplied.exit_code,
    supplied.exitCode,
    outputs.shard_exit_status,
  );
  const alive = typeof workerStartup.process_alive_at_failure === 'boolean'
    ? workerStartup.process_alive_at_failure
    : (typeof supplied.alive === 'boolean' ? supplied.alive : null);
  const outcome = stringValue(workerStartup.outcome)
    || stringValue(supplied.outcome)
    || stringValue(outputs.stable_reason)
    || (status === 'runner_blocked' ? 'runner_blocked' : 'failed');
  let state = stringValue(supplied.state);
  if (!state) {
    if (alive === true) {
      state = 'alive';
    } else if (alive === false || exitCode !== null) {
      state = 'exited';
    } else if (status === 'runner_blocked' && !truthyFlag(outputs.published_artifact_cell_executed)) {
      state = 'not_started_or_unknown';
    } else {
      state = 'failed';
    }
  }

  return {
    process: redactSensitiveText(
      stringValue(supplied.process)
        || stringValue(runtimeFailure.process)
        || stringValue(outputs.sdk)
        || 'lifecycle_shard',
      SHARD_DIAGNOSTIC_MAX_BYTES * 2,
    ),
    state: redactSensitiveText(state, 64),
    outcome: redactSensitiveText(outcome, 128),
    alive,
    exit_code: exitCode,
  };
}

function diagnosticHttp(runtimeFailure, lastServerObservation) {
  const status = firstInteger(runtimeFailure.status_code, lastServerObservation.http_status);
  if (status === null) {
    return null;
  }

  const envelope = firstObject(runtimeFailure.public_error_envelope);
  const payload = firstObject(lastServerObservation.payload);
  const reason = envelope.reason
    ?? envelope.error
    ?? envelope.message
    ?? payload.reason
    ?? payload.error
    ?? payload.message
    ?? runtimeFailure.message
    ?? null;

  return {
    status,
    reason: reason === null ? null : redactSensitiveText(reason, 512),
  };
}

function diagnosticReadiness(workerStartup) {
  const observation = firstObject(workerStartup.readiness_observation, workerStartup.readinessObservation);
  const lastServerObservation = firstObject(
    workerStartup.last_server_observation,
    workerStartup.lastServerObservation,
    observation.last_server_observation,
    observation.lastServerObservation,
  );
  const outcome = stringValue(workerStartup.outcome);
  if (!outcome && Object.keys(observation).length === 0 && Object.keys(lastServerObservation).length === 0) {
    return null;
  }

  let mismatch = firstObject(observation.readiness_mismatch, observation.readinessMismatch);
  if (Object.keys(mismatch).length === 0 && outcome === 'readiness_timeout') {
    mismatch = {
      reason: 'authoritative_worker_readiness_not_satisfied',
      required_workflow_command_contract: observation.required_workflow_command_contract ?? null,
      observed_workflow_command_contracts: observation.last_observed_workflow_command_contracts ?? null,
    };
  }

  return {
    outcome: redactSensitiveText(outcome || 'readiness_probe_failed', 128),
    attempts: firstInteger(workerStartup.attempts),
    mismatch: boundedDiagnosticObject(mismatch, 2560),
    last_server_observation: boundedDiagnosticObject(lastServerObservation, 3072),
  };
}

function diagnosticExcerptSource(outputs, findings, fallbackSummary) {
  const captured = firstObject(outputs.failure_diagnostic, outputs.failureDiagnostic);
  const failures = Array.isArray(outputs.failures) ? outputs.failures.join('; ') : '';
  return stringValue(captured.excerpt)
    || stringValue(outputs.failure_message)
    || stringValue(outputs.failure_summary)
    || failures
    || findings.map((finding) => stringValue(finding.summary)).filter(Boolean).join('; ')
    || fallbackSummary;
}

function fitShardDiagnostic(diagnostic) {
  if (serializedBytes(diagnostic) <= SHARD_DIAGNOSTIC_MAX_BYTES) {
    return diagnostic;
  }

  const compact = {
    ...diagnostic,
    excerpt: redactSensitiveText(diagnostic.excerpt, 512),
    assertion_failures: boundedAssertionFailures(diagnostic.assertion_failures, 4608),
    companion_failure: boundedCompanionFailure(diagnostic.companion_failure, 4608),
    readiness: diagnostic.readiness
      ? {
        outcome: diagnostic.readiness.outcome,
        attempts: diagnostic.readiness.attempts,
        mismatch: boundedDiagnosticObject(diagnostic.readiness.mismatch, 1024),
        last_server_observation: boundedDiagnosticObject(
          diagnostic.readiness.last_server_observation,
          1024,
        ),
      }
      : null,
    truncated: true,
  };
  if (serializedBytes(compact) <= SHARD_DIAGNOSTIC_MAX_BYTES) {
    return compact;
  }

  const fallback = {
    schema: SHARD_DIAGNOSTIC_SCHEMA,
    version: 1,
    shard: diagnostic.shard,
    retention: 'inline_result_and_record',
    max_bytes: SHARD_DIAGNOSTIC_MAX_BYTES,
    status: diagnostic.status,
    classification: diagnostic.classification,
    owning_surface: diagnostic.owning_surface,
    operation: diagnostic.operation,
    failure_stage: diagnostic.failure_stage,
    process_state: diagnostic.process_state,
    http: diagnostic.http,
    assertion_failures: boundedAssertionFailures(diagnostic.assertion_failures, 4096),
    companion_failure: boundedCompanionFailure(diagnostic.companion_failure, 4096),
    readiness: diagnostic.readiness
      ? {
        outcome: diagnostic.readiness.outcome,
        attempts: diagnostic.readiness.attempts,
        mismatch: boundedDiagnosticObject(diagnostic.readiness.mismatch, 512),
        last_server_observation: boundedDiagnosticObject(
          diagnostic.readiness.last_server_observation,
          512,
        ),
      }
      : null,
    excerpt: redactSensitiveText(diagnostic.excerpt, 256),
    truncated: true,
  };
  if (serializedBytes(fallback) <= SHARD_DIAGNOSTIC_MAX_BYTES) {
    return fallback;
  }

  const boundedFallback = {
    schema: SHARD_DIAGNOSTIC_SCHEMA,
    version: 1,
    shard: redactSensitiveText(diagnostic.shard, 96),
    retention: 'inline_result_and_record',
    max_bytes: SHARD_DIAGNOSTIC_MAX_BYTES,
    status: redactSensitiveText(diagnostic.status, 64),
    classification: redactSensitiveText(diagnostic.classification, 96),
    owning_surface: redactSensitiveText(diagnostic.owning_surface, 128),
    operation: redactSensitiveText(diagnostic.operation, 128),
    failure_stage: redactSensitiveText(diagnostic.failure_stage, 128),
    process_state: {
      process: redactSensitiveText(diagnostic.process_state?.process, 128),
      state: redactSensitiveText(diagnostic.process_state?.state, 64),
      outcome: redactSensitiveText(diagnostic.process_state?.outcome, 96),
      alive: typeof diagnostic.process_state?.alive === 'boolean'
        ? diagnostic.process_state.alive
        : null,
      exit_code: firstInteger(diagnostic.process_state?.exit_code),
    },
    http: diagnostic.http
      ? {
        status: firstInteger(diagnostic.http.status),
        reason: redactSensitiveText(diagnostic.http.reason, 192),
      }
      : null,
    assertion_failures: boundedAssertionFailures(diagnostic.assertion_failures, 4096),
    companion_failure: boundedCompanionFailure(diagnostic.companion_failure, 3584),
    readiness: diagnostic.readiness
      ? {
        outcome: redactSensitiveText(diagnostic.readiness.outcome, 96),
        attempts: firstInteger(diagnostic.readiness.attempts),
        mismatch: boundedDiagnosticObject(diagnostic.readiness.mismatch, 2048),
        last_server_observation: boundedDiagnosticObject(
          diagnostic.readiness.last_server_observation,
          2048,
        ),
      }
      : null,
    excerpt: redactSensitiveText(diagnostic.excerpt, 192),
    truncated: true,
  };
  if (serializedBytes(boundedFallback) > SHARD_DIAGNOSTIC_MAX_BYTES) {
    throw new Error('Unable to retain a lifecycle shard diagnostic within the byte limit.');
  }

  return boundedFallback;
}

function shardDiagnostic(scenarioId, status, classification, outputs, findings, fallbackSummary) {
  const runtimeFailure = firstObject(outputs.runtime_failure_evidence, outputs.runtimeFailureEvidence);
  const companion = firstObject(
    outputs.companion_failure_evidence,
    outputs.companionFailureEvidence,
  );
  const companionWorker = firstObject(companion.worker);
  const companionProtocolFailure = firstObject(
    companionWorker.last_protocol_failure,
    companionWorker.lastProtocolFailure,
  );
  const companionRuntimeException = firstObject(
    companionWorker.last_runtime_exception,
    companionWorker.lastRuntimeException,
  );
  const decisiveRuntimeFailure = Object.keys(companionProtocolFailure).length > 0
    ? companionProtocolFailure
    : (Object.keys(companionRuntimeException).length > 0 ? companionRuntimeException : runtimeFailure);
  const workerStartup = firstObject(outputs.worker_startup, outputs.workerStartup);
  const readiness = diagnosticReadiness(workerStartup);
  const lastServerObservation = firstObject(
    workerStartup.last_server_observation,
    workerStartup.lastServerObservation,
    readiness?.last_server_observation,
  );
  const failureStage = stringValue(outputs.failure_stage)
    || stringValue(runtimeFailure.failure_stage)
    || stringValue(outputs.failing_lifecycle_cell)
    || scenarioId;
  const workerStartOutcome = stringValue(workerStartup.outcome);
  const operation = stringValue(companion.operation)
    || stringValue(decisiveRuntimeFailure.operation)
    || (workerStartOutcome === 'process_exit' ? failureStage : '')
    || (readiness ? 'worker_registration_readiness' : '')
    || stringValue(outputs.operation)
    || stringValue(outputs.failing_lifecycle_cell)
    || failureStage;
  const owningSurface = redactSensitiveText(
    stringValue(outputs.failure_owner)
      || stringValue(runtimeFailure.owning_surface)
      || stringValue(findings[0]?.owning_surface)
      || owningSurfaceForDiagnostic(scenarioId, classification),
    SHARD_DIAGNOSTIC_MAX_BYTES * 2,
  );
  const captured = firstObject(outputs.failure_diagnostic, outputs.failureDiagnostic);
  const assertionFailures = boundedAssertionFailures(
    outputs.assertion_failures ?? outputs.assertionFailures,
  );
  const rawExcerpt = diagnosticExcerptSource(outputs, findings, fallbackSummary);
  const excerpt = redactSensitiveText(rawExcerpt, SHARD_DIAGNOSTIC_EXCERPT_BYTES);
  const diagnostic = {
    schema: SHARD_DIAGNOSTIC_SCHEMA,
    version: 1,
    shard: scenarioId,
    retention: 'inline_result_and_record',
    max_bytes: SHARD_DIAGNOSTIC_MAX_BYTES,
    status,
    classification,
    owning_surface: owningSurface,
    operation: redactSensitiveText(operation, 160),
    failure_stage: redactSensitiveText(failureStage, 160),
    process_state: diagnosticProcessState(status, outputs, decisiveRuntimeFailure, workerStartup, companion),
    http: diagnosticHttp(decisiveRuntimeFailure, lastServerObservation),
    assertion_failures: assertionFailures,
    companion_failure: boundedCompanionFailure(companion),
    readiness,
    excerpt,
    truncated: truthyFlag(captured.truncated)
      || truthyFlag(assertionFailures?._truncated)
      || Buffer.byteLength(String(rawExcerpt ?? ''), 'utf8') > Buffer.byteLength(excerpt, 'utf8'),
  };

  const bounded = diagnosticValue(
    { ...diagnostic, assertion_failures: null, companion_failure: null },
    0,
    SHARD_DIAGNOSTIC_MAX_BYTES * 2,
  );
  bounded.assertion_failures = assertionFailures;
  bounded.companion_failure = diagnostic.companion_failure;

  return fitShardDiagnostic(bounded);
}

function owningSurfaceForDiagnostic(scenarioId, classification) {
  if (classification === 'runner-gap') {
    return 'conformance_harness';
  }
  return {
    php_sdk_lifecycle_surface: 'sdk-php',
    python_sdk_lifecycle_surface: 'sdk-python',
    rust_sdk_lifecycle_surface: 'sdk-rust-and-server',
  }[scenarioId] ?? 'conformance_harness';
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
    'sdk-php': stringValue(source['sdk-php']),
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
      || (isExactSemverRelease(serverFromImage) ? serverFromImage : '')
      || 'unresolved',
    cli: normalizeCliVersion(env('DW_CLI_VERSION') || fromEvidence.cli || 'unresolved'),
    workflow: env('DW_WORKFLOW_PHP_VERSION') || fromEvidence.workflow || 'unresolved',
    'workflow-php': env('DW_WORKFLOW_PHP_VERSION') || fromEvidence['workflow-php'] || fromEvidence.workflow || 'unresolved',
    'sdk-php': env('DW_PHP_SDK_VERSION') || fromEvidence['sdk-php'] || 'unresolved',
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
    'sdk-php': stringValue(supplied['sdk-php'])
      || (versions['sdk-php'] !== 'unresolved' ? `packagist://durable-workflow/sdk@${versions['sdk-php']}` : 'unresolved'),
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
    if (artifact === 'server' && !isExactSemverRelease(version)) {
      failures.push(`server version must be an exact SemVer tag; got ${JSON.stringify(version)}`);
    }
    const exactArtifactVersion = artifact === 'sdk-python'
      ? isExactPythonRelease(version)
      : isExactSemverRelease(version);
    if (artifact !== 'server' && !exactArtifactVersion) {
      failures.push(`${artifact} version must be exact semver; got ${JSON.stringify(version)}`);
    }
  }

  const serverSource = String(sources.server ?? '');
  if (serverSource && !isPlaceholder(serverSource)) {
    const tag = serverTagFromImage(serverSource);
    if (!isDigestPinnedServerImage(serverSource) && (!tag || !isExactSemverRelease(tag))) {
      failures.push(`server source must use an exact SemVer tag or sha256 digest; got ${JSON.stringify(serverSource)}`);
    } else if (tag && isExactSemverRelease(tag) && versions.server !== 'unresolved' && tag !== versions.server) {
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

  return text !== '' && fragments.some((fragment) => text.includes(normalizedText(fragment)));
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
      return validateSdkLifecycleSurface(outputs, ['sdk-php']);
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
    return 'sdk-php';
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
    || stringValue(supplied.summary)
    || (status === 'pass'
      ? `${scenario.id} passed against the published artifact tuple.`
      : `${scenario.id} did not pass against the published artifact tuple.`);
  const suppliedFindings = status === 'pass'
    ? []
    : normalizeSuppliedFindings(supplied, scenario, status, classification, defaultSummary);
  let linkedFindings = status === 'pass'
    ? []
    : (suppliedFindings.length > 0 ? suppliedFindings : [generatedFinding(scenario, status, classification, defaultSummary)]);

  if (status !== 'pass' && LIFECYCLE_SHARD_IDS.has(scenario.id)) {
    const diagnostic = shardDiagnostic(
      scenario.id,
      status,
      classification,
      outputs,
      linkedFindings,
      defaultSummary,
    );
    outputs.shard_diagnostic = diagnostic;
    for (const field of [
      'failure_owner',
      'failureOwner',
      'failure_stage',
      'failureStage',
      'failure_message',
      'failureMessage',
      'failure_summary',
      'failureSummary',
      'runner_blocked_reason',
      'runnerBlockedReason',
      'process_state',
      'processState',
      'readiness_observation',
      'readinessObservation',
      'last_server_observation',
      'lastServerObservation',
    ]) {
      if (Object.prototype.hasOwnProperty.call(outputs, field)) {
        outputs[field] = diagnosticValue(outputs[field]);
      }
    }
    if (Array.isArray(outputs.failures)) {
      outputs.failures = diagnosticValue(outputs.failures);
    }
    for (const field of ['runtime_failure_evidence', 'runtimeFailureEvidence']) {
      if (nonEmptyObject(outputs[field])) {
        outputs[field] = boundedDiagnosticObject(outputs[field], 4096);
      }
    }
    for (const field of ['worker_startup', 'workerStartup']) {
      if (nonEmptyObject(outputs[field])) {
        outputs[field] = boundedDiagnosticObject(outputs[field], 4096);
      }
    }
    for (const field of ['assertion_failures', 'assertionFailures']) {
      if (Array.isArray(outputs[field]) || nonEmptyObject(outputs[field])) {
        outputs[field] = boundedAssertionFailures(outputs[field]);
      }
    }
    for (const field of ['companion_failure_evidence', 'companionFailureEvidence']) {
      if (nonEmptyObject(outputs[field])) {
        outputs[field] = boundedCompanionFailure(outputs[field], 6144);
      }
    }
    delete outputs.failure_diagnostic;
    delete outputs.failureDiagnostic;
    linkedFindings = linkedFindings.map((finding) => {
      const boundedFinding = diagnosticValue(finding);
      return {
        ...boundedFinding,
        observed_evidence: {
          ...diagnosticValue(firstObject(finding.observed_evidence, finding.observedEvidence)),
          shard_diagnostic: diagnostic,
        },
      };
    });
  }

  return {
    scenario_id: scenario.id,
    status,
    classification: status === 'pass' ? 'passed' : classification,
    published_artifact_cell_executed: executed,
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
const shardDiagnostics = Object.fromEntries(
  Object.entries(scenarioResults)
    .filter(([scenarioId, scenario]) => LIFECYCLE_SHARD_IDS.has(scenarioId) && scenario.status !== 'pass')
    .map(([scenarioId, scenario]) => [scenarioId, scenario.observed_outputs.shard_diagnostic]),
);
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
  shard_diagnostics: shardDiagnostics,
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
  shardDiagnostics,
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
