import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const resultDir = path.resolve(process.env.RESULT_DIR ?? '');
const statePath = path.resolve(process.env.STATE_FILE ?? '');
const startedAt = process.env.STARTED_AT ?? new Date().toISOString();
const maximumSeconds = Number.parseInt(process.env.MAXIMUM_SECONDS ?? '540', 10);
const cellTimeoutSeconds = Number.parseInt(process.env.CELL_TIMEOUT_SECONDS ?? '450', 10);
const rustPreparationTimeoutSeconds = Number.parseInt(
  process.env.RUST_PREPARATION_TIMEOUT_SECONDS ?? '360',
  10,
);
const waveOrchestrationReserveSeconds = Number.parseInt(
  process.env.WAVE_ORCHESTRATION_RESERVE_SECONDS ?? '90',
  10,
);
const rustExecutionReserveSeconds = Number.parseInt(
  process.env.RUST_EXECUTION_RESERVE_SECONDS ?? '90',
  10,
);

function read(relativePath) {
  const file = path.join(resultDir, relativePath);
  if (!fs.existsSync(file)) return null;
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    return { parse_error: error instanceof Error ? error.message : String(error) };
  }
}

function readExitCode(relativePath) {
  const file = path.join(resultDir, relativePath);
  if (!fs.existsSync(file)) return null;
  const parsed = Number.parseInt(fs.readFileSync(file, 'utf8').trim(), 10);
  return Number.isInteger(parsed) ? parsed : null;
}

function normalizedOutcome(value) {
  return value === 'pass' ? 'pass' : (String(value).includes('runner_blocked') ? 'runner_blocked' : 'fail');
}

function collectFieldValues(value, fields, collected = []) {
  if (Array.isArray(value)) {
    for (const entry of value) collectFieldValues(entry, fields, collected);
    return collected;
  }
  if (!value || typeof value !== 'object') return collected;
  for (const [key, entry] of Object.entries(value)) {
    if (fields.has(key) && typeof entry === 'string' && entry !== '') collected.push(entry);
    collectFieldValues(entry, fields, collected);
  }
  return collected;
}

const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
const projectionIsolation = read('heartbeat-shared-wave-isolation.json');
const childProcesses = read('heartbeat-shared-wave-children.json');
const cleanupDiagnostics = read('shared-server-cleanup-diagnostics.json');
const cells = {
  php: {
    result_file: 'php/php-sdk-heartbeat-loop-evidence.json',
    result: read('php/php-sdk-heartbeat-loop-evidence.json'),
    exit_code: readExitCode('php/exit-code'),
  },
  python: {
    result_file: 'python/python-sdk-heartbeat-loop-evidence.json',
    result: read('python/python-sdk-heartbeat-loop-evidence.json'),
    exit_code: readExitCode('python/exit-code'),
  },
  rust: {
    result_file: 'rust/rust-sdk-heartbeat-loop-evidence.json',
    result: read('rust/rust-sdk-heartbeat-loop-evidence.json'),
    exit_code: readExitCode('rust/exit-code'),
  },
  waterline: {
    result_file: 'waterline/waterline-worker-status-result.json',
    result: read('waterline/waterline-worker-status-result.json'),
    exit_code: readExitCode('waterline/exit-code'),
  },
};

const cellResults = Object.fromEntries(Object.entries(cells).map(([cell, entry]) => {
  const result = entry.result;
  const reportedOutcome = result ? normalizedOutcome(result.outcome) : 'runner_blocked';
  const evidencePresent = result !== null && result.parse_error === undefined;
  const timedOut = [124, 137].includes(entry.exit_code);
  const processRunnerBlocked = entry.exit_code === null
    || timedOut
    || (entry.exit_code !== 0 && (!evidencePresent || reportedOutcome !== 'fail'));
  const outcome = processRunnerBlocked ? 'runner_blocked' : reportedOutcome;
  const topology = result?.topology ?? {};
  const expected = state.cell_isolation[cell];
  const workerIds = [...new Set(collectFieldValues(
    result,
    new Set(['worker_id', 'stale_worker_id', 'fresh_worker_id', 'peer_worker_id']),
  ))];
  const taskQueues = [...new Set(collectFieldValues(
    result,
    new Set(['task_queue']),
  ))];
  const workflowIds = [...new Set(collectFieldValues(
    result,
    new Set(['workflow_id', 'initial_workflow_id', 'after_stale_workflow_id', 'completed_workflow_id']),
  ))];
  const isolationChecks = {
    namespace_matches_receipt: topology.namespace === expected.namespace,
    task_queue_matches_prefix: String(topology.task_queue ?? '').startsWith(expected.task_queue_prefix),
    observer_worker_identities_present: workerIds.length >= 2,
    observer_worker_identities_isolated:
      workerIds.every((workerId) => workerId.startsWith(expected.worker_id_prefix)),
    observer_task_queues_isolated:
      taskQueues.length >= 1
      && taskQueues.every((taskQueue) => taskQueue.startsWith(expected.task_queue_prefix)),
    workflow_identities_present: workflowIds.length >= 2,
    workflow_identities_isolated:
      workflowIds.every((workflowId) => workflowId.startsWith(expected.workflow_id_prefix)),
  };
  if (cell !== 'waterline') {
    isolationChecks.stale_worker_matches_prefix =
      String(topology.stale_worker_id ?? '').startsWith(expected.worker_id_prefix);
    isolationChecks.fresh_worker_matches_prefix =
      String(topology.fresh_worker_id ?? '').startsWith(expected.worker_id_prefix);
  }
  return [cell, {
    outcome,
    runner_blocked: processRunnerBlocked || (result?.runner_blocked ?? true),
    classification: result?.classification ?? 'missing-cell-evidence',
    exit_code: entry.exit_code,
    timed_out: timedOut,
    result_file: entry.result_file,
    evidence_present: evidencePresent,
    artifact_versions: result?.artifact_versions ?? {},
    artifact_sources: result?.artifact_sources ?? {},
    executed_distribution_identities: result?.executed_distribution_identities ?? {},
    install_evidence: result?.installs ?? null,
    topology,
    observed_identity_projection: {
      worker_ids: workerIds,
      task_queues: taskQueues,
      workflow_ids: workflowIds,
    },
    isolation_checks: isolationChecks,
    isolation_passed: Object.values(isolationChecks).every((value) => value === true),
    stale_worker_shutdown: result?.stale_worker_shutdown ?? null,
    stale_transition: result?.stale_transition ?? null,
    cleanup: result?.cleanup ?? null,
    findings: result?.findings ?? result?.product_evidence?.findings ?? [],
  }];
}));

const namespaces = Object.values(state.cell_isolation).map((entry) => entry.namespace);
const taskQueuePrefixes = Object.values(state.cell_isolation).map((entry) => entry.task_queue_prefix);
const workflowIdPrefixes = Object.values(state.cell_isolation).map((entry) => entry.workflow_id_prefix);
const workerIdPrefixes = Object.values(state.cell_isolation).map((entry) => entry.worker_id_prefix);
const projectionFailures = Array.isArray(projectionIsolation?.failures)
  ? projectionIsolation.failures
  : [];
const observerProjectionAvailable = projectionIsolation !== null
  && projectionIsolation.parse_error === undefined;
const observerProductLeak = observerProjectionAvailable
  && projectionFailures.some((failure) =>
    ['leaked_worker_ids', 'leaked_task_queues', 'leaked_workflow_ids']
      .some((field) => Array.isArray(failure?.[field]) && failure[field].length > 0));
const observerTransportBlocked = !observerProjectionAvailable
  || (projectionIsolation?.outcome !== 'pass'
    && (
      !observerProductLeak
      || projectionFailures.some((failure) => typeof failure?.error === 'string')
    ));
const waveIsolation = {
  namespaces_unique: new Set(namespaces).size === namespaces.length,
  task_queue_prefixes_unique: new Set(taskQueuePrefixes).size === taskQueuePrefixes.length,
  workflow_id_prefixes_unique: new Set(workflowIdPrefixes).size === workflowIdPrefixes.length,
  worker_id_prefixes_unique: new Set(workerIdPrefixes).size === workerIdPrefixes.length,
  every_cell_matches_receipt: Object.values(cellResults).every((cell) => cell.isolation_passed),
  observer_projection_no_leaks: projectionIsolation?.outcome === 'pass',
};
const finishedAt = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');
const wallSeconds = Math.max(0, (Date.parse(finishedAt) - Date.parse(startedAt)) / 1_000);
const cellsPassed = Object.values(cellResults).every((cell) => cell.outcome === 'pass');
const cleanupRemaining = state.lifecycle.cleanup_resources_remaining ?? {};
const cleanupResourceKinds = [
  'containers', 'volumes', 'networks', 'attached_containers', 'sandbox_artifacts',
];
const resourcesEmpty = (resources) => cleanupResourceKinds.every(
  (kind) => Array.isArray(resources?.[kind]) && resources[kind].length === 0,
);
const cleanupInventoryEmpty = resourcesEmpty(cleanupRemaining)
  && resourcesEmpty(cleanupDiagnostics?.final_resources_remaining);
const cleanupTimingWithinDeadline = [
  state.lifecycle.cleanup_verification,
  cleanupDiagnostics,
].every((timing) =>
  Number.isInteger(timing?.elapsed_ms)
  && Number.isInteger(timing?.timeout_ms)
  && timing.elapsed_ms >= 0
  && timing.timeout_ms > 0
  && timing.elapsed_ms <= timing.timeout_ms
  && timing.deadline_exhausted === false);
const cleanupPassed = state.lifecycle.cleanup_status === 'pass'
  && Array.isArray(state.lifecycle.cleanup_failures)
  && state.lifecycle.cleanup_failures.length === 0
  && state.lifecycle.cleanup_verification?.stable_empty_observations >= 3
  && cleanupDiagnostics?.schema
    === 'durable-workflow.v2.heartbeat-runtime.shared-server-cleanup-diagnostics'
  && cleanupDiagnostics?.project === state.compose?.project
  && cleanupDiagnostics?.stable_empty_observations >= 3
  && Array.isArray(cleanupDiagnostics?.failures)
  && cleanupDiagnostics.failures.length === 0
  && cleanupTimingWithinDeadline
  && cleanupInventoryEmpty;
const requiredChildProcesses = ['php', 'python', 'rust', 'waterline'];
const childProcessesPassed =
  childProcesses?.schema === 'durable-workflow.v2.heartbeat-runtime.shared-wave-children'
  && childProcesses?.outcome === 'pass'
  && childProcesses?.required_cells_present === true
  && childProcesses?.all_process_groups_settled === true
  && requiredChildProcesses.every((cell) =>
    childProcesses?.cells?.[cell]?.settled === true
    && Number.isInteger(childProcesses.cells[cell].process_group_id));
const isolationPassed = Object.values(waveIsolation).every((value) => value === true);
const harnessIsolationPassed = waveIsolation.namespaces_unique
  && waveIsolation.task_queue_prefixes_unique
  && waveIsolation.workflow_id_prefixes_unique
  && waveIsolation.worker_id_prefixes_unique
  && waveIsolation.every_cell_matches_receipt;
const performancePassed = wallSeconds <= maximumSeconds;
const outcome = cellsPassed
  && childProcessesPassed
  && cleanupPassed
  && isolationPassed
  && performancePassed
  ? 'pass'
  : 'fail';
const runnerBlocked = outcome !== 'pass'
  && (
    !childProcessesPassed
    || !cleanupPassed
    || !harnessIsolationPassed
    || observerTransportBlocked
    || !performancePassed
    || Object.values(cellResults).some((cell) => cell.runner_blocked === true)
  );
const findings = [];
for (const [cell, result] of Object.entries(cellResults)) {
  if (result.outcome !== 'pass') {
    findings.push({
      finding_type: 'heartbeat_wave_cell_failed',
      owning_cell: cell,
      classification: result.classification,
      result_file: result.result_file,
      exit_code: result.exit_code,
      timed_out: result.timed_out,
      stale_worker_shutdown: result.stale_worker_shutdown,
      stale_transition: result.stale_transition,
    });
  }
}
if (!childProcessesPassed) {
  findings.push({
    finding_type: 'heartbeat_wave_child_process_cleanup_failed',
    owning_cell: 'conformance-harness',
    process_evidence: childProcesses,
  });
}
if (!cleanupPassed) {
  findings.push({
    finding_type: 'heartbeat_wave_cleanup_failed',
    owning_cell: 'shared-server',
    failures: state.lifecycle.cleanup_failures ?? [],
    resources_remaining: state.lifecycle.cleanup_resources_remaining ?? null,
    deadline: {
      lifecycle: state.lifecycle.cleanup_verification ?? null,
      diagnostics: cleanupDiagnostics
        ? {
          elapsed_ms: cleanupDiagnostics.elapsed_ms,
          timeout_ms: cleanupDiagnostics.timeout_ms,
          deadline_exhausted: cleanupDiagnostics.deadline_exhausted,
        }
        : null,
    },
    diagnostic_file: 'shared-server-cleanup-diagnostics.json',
  });
}
if (!isolationPassed) {
  findings.push({
    finding_type: 'heartbeat_wave_isolation_failed',
    owning_cell: 'conformance-harness',
    checks: waveIsolation,
  });
}
if (!performancePassed) {
  findings.push({
    finding_type: 'heartbeat_wave_wall_time_exceeded',
    owning_cell: 'conformance-harness',
    observed_seconds: wallSeconds,
    maximum_seconds: maximumSeconds,
  });
}

const result = {
  schema: 'durable-workflow.v2.heartbeat-runtime.shared-wave-result',
  version: 1,
  wave_run_id: state.wave_run_id,
  started_at: startedAt,
  finished_at: finishedAt,
  wall_time_seconds: wallSeconds,
  maximum_wall_time_seconds: maximumSeconds,
  budget_allocation_seconds: {
    wave: maximumSeconds,
    concurrent_cell: cellTimeoutSeconds,
    rust_preparation: rustPreparationTimeoutSeconds,
    rust_heartbeat_execution_reserve: rustExecutionReserveSeconds,
    wave_orchestration_and_cleanup_reserve: waveOrchestrationReserveSeconds,
  },
  outcome,
  runner_blocked: runnerBlocked,
  classification: outcome === 'pass'
    ? 'published-heartbeat-shared-wave-proven'
    : (runnerBlocked ? 'heartbeat-shared-wave-runner-blocked' : 'heartbeat-shared-wave-failed'),
  artifact_versions: {
    server: process.env.DW_SERVER_VERSION ?? '',
    cli: String(process.env.DW_CLI_VERSION ?? '').replace(/^v/, ''),
    'sdk-php': String(process.env.DW_PHP_SDK_VERSION ?? '').replace(/^v/, ''),
    'sdk-python': String(process.env.DW_PYTHON_SDK_VERSION ?? '').replace(/^v/, ''),
    'sdk-rust': String(process.env.DW_RUST_SDK_VERSION ?? '').replace(/^v/, ''),
    workflow: String(process.env.DW_WORKFLOW_PHP_VERSION ?? '').replace(/^v/, ''),
    waterline: String(process.env.DW_WATERLINE_VERSION ?? '').replace(/^v/, ''),
  },
  published_server_bootstrap: {
    ...state.clean_bootstrap,
    requested_reference: state.server.requested_reference,
    resolved_public_digest: state.server.resolved_public_digest,
    exact_published_image_verified: state.server.exact_published_image_verified,
    bootstrap_count: 1,
  },
  isolation: {
    ...waveIsolation,
    cells: state.cell_isolation,
    observer_projection_evidence: 'heartbeat-shared-wave-isolation.json',
    observer_projection: projectionIsolation,
  },
  cells: cellResults,
  child_processes: childProcesses,
  completed_peer_evidence: Object.values(cellResults)
    .filter((cell) => cell.evidence_present)
    .map((cell) => cell.result_file),
  cleanup: state.lifecycle,
  cleanup_diagnostics: cleanupDiagnostics,
  findings,
  local_product_source_checkouts_used: false,
};

fs.writeFileSync(
  path.join(resultDir, 'heartbeat-shared-wave-result.json'),
  `${JSON.stringify(result, null, 2)}\n`,
  'utf8',
);
if (outcome !== 'pass') process.exitCode = 1;
