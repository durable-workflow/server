import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const statePath = path.resolve(process.env.STATE_FILE ?? '');
const resultPath = path.resolve(process.env.RESULT_FILE ?? '');
const token = String(process.env.DW_HEARTBEATS_AUTH_TOKEN ?? 'dev-token');
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));

function workers(payload) {
  if (Array.isArray(payload)) return payload;
  for (const field of ['workers', 'data', 'items']) {
    if (Array.isArray(payload?.[field])) return payload[field];
  }
  return [];
}

function workflows(payload) {
  if (Array.isArray(payload)) return payload;
  for (const field of ['workflows', 'data', 'items']) {
    if (Array.isArray(payload?.[field])) return payload[field];
  }
  return [];
}

async function query(resource, namespace, status = '') {
  const url = new URL(`/api/${resource}`, state.endpoint.host_control_url);
  if (status) url.searchParams.set('status', status);
  const response = await fetch(url, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      'X-Namespace': namespace,
      'X-Durable-Workflow-Protocol-Version': '1.0',
      'X-Durable-Workflow-Control-Plane-Version': '2',
    },
    signal: AbortSignal.timeout(5_000),
  });
  const raw = await response.text();
  if (!response.ok) throw new Error(`${url} returned ${response.status}: ${raw}`);
  return { status: response.status, body: JSON.parse(raw) };
}

const observations = {};
const failures = [];
for (const [cell, isolation] of Object.entries(state.cell_isolation)) {
  try {
    const active = await query('workers', isolation.namespace);
    const stale = await query('workers', isolation.namespace, 'stale');
    const workflowList = await query('workflows', isolation.namespace);
    const projectedWorkers = [...workers(active.body), ...workers(stale.body)];
    const projectedWorkflows = workflows(workflowList.body);
    const workerIds = [...new Set(projectedWorkers
      .map((worker) => String(worker?.worker_id ?? ''))
      .filter(Boolean))];
    const taskQueues = [...new Set(projectedWorkers
      .concat(projectedWorkflows)
      .map((entry) => String(entry?.task_queue ?? ''))
      .filter(Boolean))];
    const workflowIds = [...new Set(projectedWorkflows
      .map((workflow) => String(workflow?.workflow_id ?? ''))
      .filter(Boolean))];
    const leakedWorkerIds = workerIds.filter((workerId) =>
      !workerId.startsWith(isolation.worker_id_prefix));
    const leakedTaskQueues = taskQueues.filter((taskQueue) =>
      !taskQueue.startsWith(isolation.task_queue_prefix));
    const leakedWorkflowIds = workflowIds.filter((workflowId) =>
      !workflowId.startsWith(isolation.workflow_id_prefix));
    observations[cell] = {
      namespace: isolation.namespace,
      active,
      stale,
      workflows: workflowList,
      worker_ids: workerIds,
      task_queues: taskQueues,
      workflow_ids: workflowIds,
      leaked_worker_ids: leakedWorkerIds,
      leaked_task_queues: leakedTaskQueues,
      leaked_workflow_ids: leakedWorkflowIds,
      passed: leakedWorkerIds.length === 0
        && leakedTaskQueues.length === 0
        && leakedWorkflowIds.length === 0,
    };
    if (!observations[cell].passed) {
      failures.push({
        cell,
        namespace: isolation.namespace,
        leaked_worker_ids: leakedWorkerIds,
        leaked_task_queues: leakedTaskQueues,
        leaked_workflow_ids: leakedWorkflowIds,
      });
    }
  } catch (error) {
    observations[cell] = {
      namespace: isolation.namespace,
      passed: false,
      error: error instanceof Error ? error.message : String(error),
    };
    failures.push({
      cell,
      namespace: isolation.namespace,
      error: observations[cell].error,
    });
  }
}

const evidence = {
  schema: 'durable-workflow.v2.heartbeat-runtime.shared-wave-isolation',
  version: 1,
  wave_run_id: state.wave_run_id,
  observed_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
  outcome: failures.length === 0 ? 'pass' : 'fail',
  observations,
  failures,
};
fs.writeFileSync(resultPath, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
if (failures.length > 0) process.exitCode = 1;
