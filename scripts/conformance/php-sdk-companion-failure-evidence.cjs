'use strict';

const fs = require('node:fs');
const path = require('node:path');
const {
  boundedEvidence,
  diagnosticExcerpt,
  extractRuntimeFailureEvidence,
  serializedBytes,
} = require('./php-sdk-runtime-failure-evidence.cjs');

const SCHEMA = 'durable-workflow.v2.php-sdk-companion-failure';
const MAX_BYTES = 6144;
const UNAVAILABLE_REASON_RE = /(no[_ -]active[_ -]workers?|no[_ -]compatible[_ -]workers?|worker[_ -](?:is[_ -])?unavailable|task[_ -]queue[_ -]unavailable)/i;
const TIMEOUT_RE = /(workflowtimedout|timed?\s*out|timeout)/i;
const TERMINAL_RUN_STATUSES = new Set([
  'cancelled',
  'completed',
  'failed',
  'terminated',
  'timed_out',
]);
const NON_TERMINAL_RUN_STATUSES = new Set(['pending', 'running', 'waiting']);
const TERMINAL_HISTORY_EVENTS = new Set([
  'WorkflowCancelled',
  'WorkflowCompleted',
  'WorkflowContinuedAsNew',
  'WorkflowFailed',
  'WorkflowTerminated',
  'WorkflowTimedOut',
]);

function readText(file) {
  try {
    return file ? fs.readFileSync(file, 'utf8') : '';
  } catch {
    return '';
  }
}

function readTail(file, maxBytes = 65536) {
  if (!file) {
    return '';
  }
  try {
    const size = fs.statSync(file).size;
    const length = Math.min(size, maxBytes);
    const buffer = Buffer.alloc(length);
    const descriptor = fs.openSync(file, 'r');
    try {
      fs.readSync(descriptor, buffer, 0, length, Math.max(0, size - length));
    } finally {
      fs.closeSync(descriptor);
    }
    return buffer.toString('utf8');
  } catch {
    return '';
  }
}

function readJson(file) {
  try {
    const value = JSON.parse(readText(file));
    return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
  } catch {
    return null;
  }
}

function failureKind(runtimeFailure) {
  if (!runtimeFailure) {
    return null;
  }
  const searchable = JSON.stringify([
    runtimeFailure.exception_type,
    runtimeFailure.message,
    runtimeFailure.operation,
    runtimeFailure.public_error_envelope,
  ]);
  if (TIMEOUT_RE.test(searchable)) {
    return 'client_timeout';
  }
  if (UNAVAILABLE_REASON_RE.test(searchable)) {
    return 'worker_unavailable';
  }

  return null;
}

function selectedObject(value, fields) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return null;
  }

  return Object.fromEntries(fields
    .filter((field) => Object.prototype.hasOwnProperty.call(value, field))
    .map((field) => [field, value[field]]));
}

function selectedList(value, fields, limit = 4) {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.slice(0, limit).map((entry) => selectedObject(entry, fields) ?? entry);
}

function selectedTaskQueuePayload(payload) {
  const stats = payload.stats && typeof payload.stats === 'object'
    ? Object.fromEntries(Object.entries({
      approximate_backlog_count: payload.stats.approximate_backlog_count,
      tasks_added_last_minute: payload.stats.tasks_added_last_minute,
      tasks_dispatched_last_minute: payload.stats.tasks_dispatched_last_minute,
      workflow_tasks: selectedObject(payload.stats.workflow_tasks, [
        'ready_count',
        'leased_count',
        'expired_lease_count',
      ]),
      activity_tasks: selectedObject(payload.stats.activity_tasks, [
        'ready_count',
        'leased_count',
        'expired_lease_count',
      ]),
      pollers: selectedObject(payload.stats.pollers, ['active_count', 'stale_count']),
      oldest_ready_task: selectedObject(payload.stats.oldest_ready_task, [
        'task_id',
        'task_type',
        'workflow_id',
        'run_id',
        'created_at',
      ]),
    }).filter(([, value]) => value !== undefined && value !== null))
    : null;
  const admission = payload.admission && typeof payload.admission === 'object'
    ? Object.fromEntries(['workflow_tasks', 'activity_tasks', 'query_tasks']
      .filter((kind) => payload.admission[kind] && typeof payload.admission[kind] === 'object')
      .map((kind) => [kind, selectedObject(payload.admission[kind], [
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
      ])]))
    : null;

  return Object.fromEntries(Object.entries({
    name: payload.name ?? payload.task_queue,
    stats,
    pollers: selectedList(payload.pollers, [
      'worker_id',
      'status',
      'is_stale',
      'last_heartbeat_at',
      'max_concurrent_workflow_tasks',
      'max_concurrent_activity_tasks',
    ]),
    current_leases: selectedList(payload.current_leases, [
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
    ]),
    admission,
    reason: payload.reason,
    message: payload.message,
  }).filter(([, value]) => value !== undefined && value !== null));
}

function minimalTaskQueuePayload(payload) {
  const selected = selectedTaskQueuePayload(payload);
  const admission = selected.admission && typeof selected.admission === 'object'
    ? Object.fromEntries(Object.entries(selected.admission).map(([kind, value]) => [
      kind,
      selectedObject(value, [
        'status',
        'active_worker_count',
        'configured_slot_count',
        'leased_count',
        'ready_count',
        'available_slot_count',
        'approximate_pending_count',
        'remaining_pending_capacity',
      ]) ?? {},
    ]))
    : {};

  return {
    name: selected.name ?? null,
    stats: selected.stats ?? {},
    pollers: Array.isArray(selected.pollers) ? selected.pollers.slice(0, 1) : [],
    current_leases: Array.isArray(selected.current_leases) ? selected.current_leases.slice(0, 1) : [],
    admission,
  };
}

function selectedPayload(kind, payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    return payload ?? null;
  }

  const fields = {
    health: ['status', 'timestamp', 'checks', 'topology', 'reason', 'message'],
    worker: [
      'worker_id',
      'namespace',
      'task_queue',
      'status',
      'last_heartbeat_at',
      'updated_at',
      'task_slots',
      'process_metrics',
      'reason',
      'message',
    ],
    run: [
      'workflow_id',
      'run_id',
      'workflow_type',
      'status',
      'status_bucket',
      'is_terminal',
      'closed_reason',
      'task_queue',
      'compatibility_status',
      'started_at',
      'closed_at',
      'reason',
      'message',
    ],
  }[kind] || [];
  if (kind === 'history') {
    const events = Array.isArray(payload.events) ? payload.events : [];
    return {
      workflow_id: payload.workflow_id ?? null,
      run_id: payload.run_id ?? null,
      event_count: events.length,
      last_event_types: events.slice(-12).map((event) => event?.event_type ?? event?.type ?? null),
      last_events: events.slice(-4).map((event) => ({
        sequence: event?.sequence ?? null,
        event_type: event?.event_type ?? event?.type ?? null,
        timestamp: event?.timestamp ?? null,
        payload: event?.payload ?? null,
      })),
      reason: payload.reason ?? null,
      message: payload.message ?? null,
    };
  }
  if (kind === 'task_queue') {
    return selectedTaskQueuePayload(payload);
  }

  return Object.fromEntries(fields
    .filter((field) => Object.prototype.hasOwnProperty.call(payload, field))
    .map((field) => [field, payload[field]]));
}

function compactProbe(kind, probe, secrets, maxBytes = null) {
  if (!probe || typeof probe !== 'object' || Array.isArray(probe)) {
    return {http_status: null, probe_error: 'probe_not_recorded'};
  }
  const numericStatus = Number(probe.http_status ?? probe.httpStatus);
  const value = {
    http_status: Number.isInteger(numericStatus) ? numericStatus : null,
    payload: selectedPayload(kind, probe.payload),
    probe_error: probe.probe_error ?? probe.probeError ?? null,
  };

  const limit = maxBytes ?? ({history: 1280, task_queue: 1536}[kind] || 896);
  const bounded = boundedEvidence(value, secrets, limit) ?? value;
  if (kind !== 'task_queue' || bounded.payload) {
    return bounded;
  }

  // The generic evidence compactor falls back to a JSON excerpt when an
  // object exceeds its limit. Queue state must remain structurally queryable,
  // so retain the real endpoint keys with smaller per-entry samples instead.
  const payload = minimalTaskQueuePayload(probe.payload ?? {});
  const fallback = {
    http_status: value.http_status,
    payload,
    probe_error: value.probe_error,
    truncated: true,
  };
  const boundedFallback = boundedEvidence(fallback, secrets, limit) ?? fallback;
  if (boundedFallback.payload) {
    return boundedFallback;
  }

  const labelsOnly = {
    http_status: value.http_status,
    payload: {
      name: payload.name,
      stats: {
        approximate_backlog_count: payload.stats.approximate_backlog_count ?? null,
        workflow_tasks: selectedObject(payload.stats.workflow_tasks, ['ready_count', 'leased_count']) ?? {},
        activity_tasks: selectedObject(payload.stats.activity_tasks, ['ready_count', 'leased_count']) ?? {},
        pollers: selectedObject(payload.stats.pollers, ['active_count', 'stale_count']) ?? {},
      },
      pollers: selectedList(payload.pollers, ['worker_id', 'status', 'is_stale'], 1),
      current_leases: selectedList(payload.current_leases, [
        'task_id',
        'task_type',
        'workflow_id',
        'lease_owner',
        'is_expired',
      ], 1),
      admission: Object.fromEntries(Object.entries(payload.admission).map(([kind, value]) => [
        kind,
        selectedObject(value, ['status']) ?? {},
      ])),
    },
    truncated: true,
  };

  return boundedEvidence(labelsOnly, secrets, limit) ?? labelsOnly;
}

function successfulProbe(probe) {
  const status = Number(probe?.http_status ?? probe?.httpStatus);
  return Number.isInteger(status) && status >= 200 && status < 300;
}

function terminalRunObservation(probes) {
  if (!successfulProbe(probes.run)) {
    return {terminal: null, run_status: null, terminal_event: null};
  }

  const runPayload = probes.run?.payload;
  const status = typeof runPayload?.status === 'string' ? runPayload.status.toLowerCase() : null;
  const historyEvents = successfulProbe(probes.history) && Array.isArray(probes.history?.payload?.events)
    ? probes.history.payload.events
    : [];
  const terminalEvent = historyEvents
    .map((event) => event?.event_type ?? event?.type ?? null)
    .find((eventType) => TERMINAL_HISTORY_EVENTS.has(eventType)) ?? null;
  const terminal = runPayload?.is_terminal === true
    || (status !== null && TERMINAL_RUN_STATUSES.has(status))
    || terminalEvent !== null;
  if (terminal) {
    return {terminal: true, run_status: status, terminal_event: terminalEvent};
  }
  if (runPayload?.is_terminal === false
      || (status !== null && NON_TERMINAL_RUN_STATUSES.has(status))) {
    return {terminal: false, run_status: status, terminal_event: null};
  }

  return {terminal: null, run_status: status, terminal_event: null};
}

function classify(clientFailure, workerFailure, processState, probes) {
  if (workerFailure) {
    return {
      classification: workerFailure.classification,
      owning_surface: workerFailure.owning_surface,
      classification_basis: workerFailure.classification === 'server'
        ? 'worker_protocol_server_failure'
        : 'worker_process_exception',
    };
  }
  const runObservation = terminalRunObservation(probes);
  if (processState.alive === true && runObservation.terminal === true) {
    return {
      classification: clientFailure.classification,
      owning_surface: clientFailure.owning_surface,
      classification_basis: 'worker_alive_run_terminal_client_failure',
    };
  }
  if (processState.alive === true && runObservation.terminal === false) {
    return {
      classification: 'server',
      owning_surface: 'server',
      classification_basis: 'worker_alive_run_not_terminal',
    };
  }
  const healthStatus = Number(probes.health?.http_status ?? probes.health?.httpStatus);
  if (Number.isInteger(healthStatus) && healthStatus >= 400) {
    return {
      classification: 'server',
      owning_surface: 'server',
      classification_basis: 'server_health_failure',
    };
  }
  if (!Number.isInteger(healthStatus) && (probes.health?.probe_error || probes.health?.probeError)) {
    return {
      classification: 'server',
      owning_surface: 'server',
      classification_basis: 'server_health_unavailable',
    };
  }
  if (processState.alive === false) {
    return {
      classification: 'sdk',
      owning_surface: 'sdk-php',
      classification_basis: 'worker_exited_without_structured_protocol_failure',
    };
  }

  return {
    classification: clientFailure.classification,
    owning_surface: clientFailure.owning_surface,
    classification_basis: 'client_failure_with_unknown_companion_state',
  };
}

function createCompanionFailureEvidence(options) {
  const secrets = Array.isArray(options.secrets) ? options.secrets.filter(Boolean) : [];
  const clientFailure = extractRuntimeFailureEvidence(options.clientDiagnostic, {secrets});
  const kind = failureKind(clientFailure);
  if (!kind) {
    return null;
  }

  const workerFailure = extractRuntimeFailureEvidence(options.workerDiagnostic, {secrets});
  const processState = {
    process: 'php-sdk-worker',
    state: options.processAlive === true ? 'alive' : (options.processAlive === false ? 'exited' : 'unknown'),
    alive: typeof options.processAlive === 'boolean' ? options.processAlive : null,
    exit_code: Number.isInteger(options.processExitCode) ? options.processExitCode : null,
  };
  const probes = options.probes && typeof options.probes === 'object' ? options.probes : {};
  const ownership = classify(clientFailure, workerFailure, processState, probes);
  const evidence = {
    schema: SCHEMA,
    version: 1,
    failure_kind: kind,
    operation: workerFailure?.operation || clientFailure.operation,
    classification: ownership.classification,
    owning_surface: ownership.owning_surface,
    classification_basis: ownership.classification_basis,
    client_failure: boundedEvidence(clientFailure, secrets, 1280),
    worker: {
      worker_id: options.workerId || null,
      process_state: processState,
      last_protocol_failure: boundedEvidence(workerFailure, secrets, 1280),
      structured_stderr: diagnosticExcerpt(
        options.workerDiagnostic || 'The companion worker produced no structured stderr before the client failure.',
        secrets,
        768,
      ),
      server_registration: compactProbe('worker', probes.worker, secrets),
    },
    server: {
      health: compactProbe('health', probes.health, secrets),
      run_state: compactProbe('run', probes.run, secrets),
      history: compactProbe('history', probes.history, secrets),
      task_queue: compactProbe('task_queue', probes.task_queue, secrets),
      process_log: diagnosticExcerpt(
        options.serverLog || 'The server process log stream contained no entries before the client failure.',
        secrets,
        768,
      ),
    },
    retained_after_cleanup: true,
    max_bytes: MAX_BYTES,
  };

  if (serializedBytes(evidence) <= MAX_BYTES) {
    return evidence;
  }

  evidence.worker.structured_stderr = diagnosticExcerpt(options.workerDiagnostic, secrets, 384);
  evidence.server.process_log = diagnosticExcerpt(options.serverLog, secrets, 384);
  evidence.server.history = compactProbe('history', probes.history, secrets, 640);
  evidence.server.task_queue = compactProbe('task_queue', probes.task_queue, secrets, 1024);
  evidence.truncated = true;
  if (serializedBytes(evidence) > MAX_BYTES) {
    evidence.server.history = {
      http_status: evidence.server.history.http_status,
      payload: {
        event_count: evidence.server.history.payload?.event_count ?? null,
        last_event_types: evidence.server.history.payload?.last_event_types ?? [],
      },
      truncated: true,
    };
  }
  if (serializedBytes(evidence) > MAX_BYTES) {
    evidence.worker.structured_stderr = diagnosticExcerpt(options.workerDiagnostic, secrets, 256);
    evidence.server.process_log = diagnosticExcerpt(options.serverLog, secrets, 256);
  }
  if (serializedBytes(evidence) > MAX_BYTES) {
    throw new Error('Unable to retain PHP SDK companion evidence within its byte limit.');
  }

  return evidence;
}

async function requestProbe(url, headers) {
  if (!url) {
    return {http_status: null, probe_error: 'probe_target_unavailable'};
  }
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 3000);
  try {
    const response = await fetch(url, {headers, signal: controller.signal});
    const text = await response.text();
    let payload = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      payload = {message: text};
    }
    return {http_status: response.status, payload};
  } catch (error) {
    return {http_status: null, probe_error: error?.name || 'request_failed'};
  } finally {
    clearTimeout(timeout);
  }
}

async function collectProbes(clientFailure) {
  const fixture = readJson(process.env.COMPANION_PROBE_FIXTURE || '');
  if (fixture) {
    return fixture;
  }

  const server = String(process.env.SERVER_URL || '').replace(/\/$/, '');
  const namespace = process.env.NAMESPACE || '';
  const token = process.env.CONTROL_TOKEN || '';
  const headers = {
    Accept: 'application/json',
    Authorization: `Bearer ${token}`,
    'X-Namespace': namespace,
    'X-Durable-Workflow-Control-Plane-Version': '2',
  };
  const workerId = process.env.COMPANION_WORKER_ID || '';
  const queue = process.env.COMPANION_TASK_QUEUE || '';
  const workflowId = clientFailure?.workflow_id || '';
  const runId = clientFailure?.run_id || '';
  const runBase = workflowId && runId
    ? `${server}/api/workflows/${encodeURIComponent(workflowId)}/runs/${encodeURIComponent(runId)}`
    : '';

  const [health, worker, run, history, taskQueue] = await Promise.all([
    requestProbe(`${server}/api/health`, {Accept: 'application/json'}),
    requestProbe(workerId ? `${server}/api/workers/${encodeURIComponent(workerId)}` : '', headers),
    requestProbe(runBase, headers),
    requestProbe(runBase ? `${runBase}/history?page_size=100` : '', headers),
    requestProbe(queue ? `${server}/api/task-queues/${encodeURIComponent(queue)}` : '', headers),
  ]);

  return {health, worker, run, history, task_queue: taskQueue};
}

async function main() {
  const secrets = [process.env.CONTROL_TOKEN, process.env.WORKER_TOKEN].filter(Boolean);
  const clientDiagnostic = readText(process.env.CLIENT_DIAGNOSTIC_FILE || '');
  const clientFailure = extractRuntimeFailureEvidence(clientDiagnostic, {secrets});
  if (!failureKind(clientFailure)) {
    process.exit(3);
  }
  const probes = await collectProbes(clientFailure);
  const processExitCode = /^-?\d+$/.test(process.env.COMPANION_WORKER_EXIT_CODE || '')
    ? Number(process.env.COMPANION_WORKER_EXIT_CODE)
    : null;
  const processAlive = process.env.COMPANION_WORKER_ALIVE === 'true'
    ? true
    : (process.env.COMPANION_WORKER_ALIVE === 'false' ? false : null);
  const evidence = createCompanionFailureEvidence({
    clientDiagnostic,
    workerDiagnostic: readTail(process.env.COMPANION_WORKER_LOG || ''),
    serverLog: [
      readTail(process.env.COMPANION_SERVER_LOG || ''),
      readTail(process.env.COMPANION_SCHEDULER_LOG || ''),
    ].filter(Boolean).join('\n'),
    workerId: process.env.COMPANION_WORKER_ID || null,
    processAlive,
    processExitCode,
    probes,
    secrets,
  });
  if (!evidence) {
    process.exit(3);
  }
  const output = process.env.COMPANION_OUTPUT_FILE || '';
  if (!output) {
    throw new Error('COMPANION_OUTPUT_FILE is required.');
  }
  fs.writeFileSync(path.resolve(output), `${JSON.stringify(evidence, null, 2)}\n`);
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(`${error?.stack || error}\n`);
    process.exit(1);
  });
}

module.exports = {
  MAX_BYTES,
  SCHEMA,
  createCompanionFailureEvidence,
  failureKind,
};
