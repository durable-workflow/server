'use strict';

const MARKER = 'DW_PHP_SDK_RUNTIME_FAILURE=';
const TEXT_LIMIT = 512;
const ENVELOPE_LIMIT = 2048;
const SENSITIVE_KEY = /(authorization|credential|password|passwd|secret|token|api[_-]?key)/i;

function serializedBytes(value) {
  return Buffer.byteLength(JSON.stringify(value), 'utf8');
}

function text(value, secrets = [], limit = TEXT_LIMIT) {
  let result = String(value ?? '')
    .replace(/[\u0000-\u001f\u007f]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  for (const secret of secrets) {
    if (secret) {
      result = result.split(String(secret)).join('[REDACTED]');
    }
  }
  result = result
    .replace(/(authorization\s*[:=]\s*(?:bearer\s+)?)[^\s,;]+/ig, '$1[REDACTED]')
    .replace(/((?:credential|password|passwd|secret|token|api[_-]?key)\s*[:=]\s*)[^\s,;]+/ig, '$1[REDACTED]')
    .replace(/(https?:\/\/)[^\s/@:]+:[^\s/@]+@/ig, '$1[REDACTED]@');

  return result.slice(0, limit);
}

function boundedValue(value, secrets, depth = 0) {
  if (value === null || value === undefined) {
    return null;
  }
  if (typeof value === 'string') {
    return text(value, secrets);
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return value;
  }
  if (depth >= 5) {
    return '[depth limit reached]';
  }
  if (Array.isArray(value)) {
    return value.slice(0, 16).map((entry) => boundedValue(entry, secrets, depth + 1));
  }
  if (typeof value !== 'object') {
    return text(value, secrets);
  }

  const result = {};
  for (const [key, entry] of Object.entries(value).slice(0, 24)) {
    const safeKey = text(key, secrets, 128);
    result[safeKey] = SENSITIVE_KEY.test(safeKey)
      ? '[REDACTED]'
      : boundedValue(entry, secrets, depth + 1);
  }

  return result;
}

function boundedEnvelope(value, secrets) {
  if (!value || typeof value !== 'object') {
    return null;
  }

  const bounded = boundedValue(value, secrets);
  const serialized = JSON.stringify(bounded);
  if (Buffer.byteLength(serialized, 'utf8') <= ENVELOPE_LIMIT) {
    return bounded;
  }

  let summary = {_truncated: true};
  for (const key of ['error', 'message', 'reason', 'code', 'status', 'status_code', 'workflow_id', 'run_id']) {
    if (!Object.prototype.hasOwnProperty.call(bounded, key)) {
      continue;
    }

    const entry = bounded[key];
    if (typeof entry === 'number' || typeof entry === 'boolean' || entry === null) {
      const candidate = {...summary, [key]: entry};
      if (serializedBytes(candidate) <= ENVELOPE_LIMIT) {
        summary = candidate;
      }
      continue;
    }

    summary = addBoundedText(
      summary,
      key,
      typeof entry === 'string' ? entry : JSON.stringify(entry),
      secrets,
      192,
    );
  }
  summary = addBoundedText(summary, '_bounded_json_excerpt', serialized, secrets, 512);

  return serializedBytes(summary) <= ENVELOPE_LIMIT ? summary : {_truncated: true};
}

function addBoundedText(target, key, value, secrets, limit) {
  const characters = [...text(value, secrets, limit)];
  let low = 0;
  let high = characters.length;
  let accepted = target;
  while (low <= high) {
    const middle = Math.floor((low + high) / 2);
    const candidate = {...target, [key]: characters.slice(0, middle).join('')};
    if (serializedBytes(candidate) <= ENVELOPE_LIMIT) {
      accepted = candidate;
      low = middle + 1;
    } else {
      high = middle - 1;
    }
  }

  return accepted;
}

function lastMarkerPayload(source) {
  let payload = null;
  for (const line of String(source ?? '').split(/\r?\n/)) {
    const markerAt = line.indexOf(MARKER);
    if (markerAt < 0) {
      continue;
    }
    try {
      const candidate = JSON.parse(line.slice(markerAt + MARKER.length));
      if (candidate && typeof candidate === 'object' && !Array.isArray(candidate)) {
        payload = candidate;
      }
    } catch {
      // A malformed or truncated marker is not durable product evidence.
    }
  }

  return payload;
}

function extractRuntimeFailureEvidence(source, options = {}) {
  const payload = lastMarkerPayload(source);
  if (!payload) {
    return null;
  }

  const secrets = Array.isArray(options.secrets) ? options.secrets.filter(Boolean) : [];
  const numericStatus = Number(payload.status_code);
  const statusCode = Number.isInteger(numericStatus) && numericStatus >= 400 && numericStatus <= 599
    ? numericStatus
    : null;
  const envelope = boundedEnvelope(payload.public_error_envelope, secrets);
  const workflowId = text(payload.workflow_id ?? envelope?.workflow_id, secrets, 256) || null;
  const runId = text(payload.run_id ?? envelope?.run_id, secrets, 256) || null;
  const classification = statusCode !== null
    ? 'server'
    : (['server', 'sdk', 'runner'].includes(payload.classification) ? payload.classification : 'sdk');
  const owningSurface = classification === 'server'
    ? 'server'
    : (text(payload.owning_surface, secrets, 128)
      || ({sdk: 'sdk-php', runner: 'conformance_harness'}[classification]));

  return {
    schema: 'durable-workflow.v2.php-sdk-runtime-failure',
    classification,
    owning_surface: owningSurface,
    process: text(payload.process, secrets, 64) || 'unknown',
    operation: text(payload.operation, secrets, 160) || 'unknown',
    http_method: text(payload.http_method, secrets, 16) || null,
    endpoint: text(payload.endpoint, secrets, 512) || null,
    status_code: statusCode,
    public_error_envelope: envelope,
    workflow_id: workflowId,
    run_id: runId,
    exception_type: text(payload.exception_type, secrets, 256) || null,
    message: text(payload.message, secrets) || null,
  };
}

function isCompleteHttpFailureEvidence(evidence) {
  return Boolean(
    evidence
      && evidence.classification === 'server'
      && Number.isInteger(evidence.status_code)
      && evidence.status_code >= 400
      && evidence.status_code <= 599
      && evidence.public_error_envelope
      && typeof evidence.public_error_envelope === 'object'
      && !Array.isArray(evidence.public_error_envelope)
      && Object.keys(evidence.public_error_envelope).length > 0
      && serializedBytes(evidence.public_error_envelope) <= ENVELOPE_LIMIT
      && typeof evidence.operation === 'string'
      && evidence.operation !== ''
      && evidence.operation !== 'unknown'
      && typeof evidence.owning_surface === 'string'
      && evidence.owning_surface !== ''
  );
}

function assertCompleteHttpFailureEvidence(evidence, classification) {
  if (classification !== 'server' && evidence?.classification !== 'server') {
    return;
  }
  if (!isCompleteHttpFailureEvidence(evidence)) {
    throw new Error(
      'Server-classified PHP SDK failure is missing a valid status, public envelope, operation, owning surface, or byte bound.',
    );
  }
}

function failureSummary(evidence, stage, fallback) {
  if (!evidence || evidence.status_code === null) {
    return fallback;
  }

  const identity = evidence.workflow_id
    ? ` for workflow ${evidence.workflow_id}${evidence.run_id ? ` run ${evidence.run_id}` : ''}`
    : '';
  const envelope = evidence.public_error_envelope || {};
  const publicReason = text(envelope.reason ?? envelope.error ?? envelope.message ?? evidence.message, [], 180);
  const reason = publicReason ? ` Public reason: ${publicReason}.` : '';

  return `The released PHP SDK operation ${evidence.operation} received HTTP ${evidence.status_code} during ${stage}${identity}; owning surface: ${evidence.owning_surface}.${reason}`;
}

module.exports = {
  ENVELOPE_LIMIT,
  MARKER,
  assertCompleteHttpFailureEvidence,
  extractRuntimeFailureEvidence,
  failureSummary,
  isCompleteHttpFailureEvidence,
  serializedBytes,
};
