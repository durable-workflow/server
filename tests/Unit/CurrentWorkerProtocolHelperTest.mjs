import assert from 'node:assert/strict';
import test from 'node:test';

import {
  CURRENT_WORKER_PROTOCOL_VERSION,
  assertCurrentWorkerRegistration,
  avroResultFromTaskArguments,
  currentWorkerProtocolHeaders,
  currentWorkerRegistration,
  workflowTaskCompletionPayload,
} from '../../scripts/conformance/current-worker-protocol.mjs';

const task = {
  task_id: 'task-1',
  lease_owner: 'probe-worker',
  workflow_task_attempt: 1,
  arguments: { codec: 'avro', blob: 'wwHioz3/VYAiNwQA' },
};

test('direct worker requests select current protocol 1.19', () => {
  assert.equal(CURRENT_WORKER_PROTOCOL_VERSION, '1.19');
  assert.equal(
    currentWorkerProtocolHeaders({ Accept: 'application/json' })['X-Durable-Workflow-Protocol-Version'],
    '1.19',
  );
});

test('direct registration always carries exact declarations and capability manifest', () => {
  const registration = currentWorkerRegistration({
    worker_id: 'probe-worker',
    task_queue: 'probe-queue',
    runtime: 'php',
    sdk_version: 'published',
    supported_workflow_types: ['probe.workflow'],
    supported_activity_types: [],
  });

  assert.deepEqual(Object.keys(registration.capability_manifest), [
    'local_activities',
    'worker_sessions',
    'sticky_execution',
  ]);
  const mutated = { ...registration };
  delete mutated.capability_manifest;
  assert.throws(() => assertCurrentWorkerRegistration(mutated), /requires capability_manifest/);
});

test('direct completion reuses official Avro task arguments and rejects JSON mutation', () => {
  const result = avroResultFromTaskArguments(task);
  const completion = workflowTaskCompletionPayload(task, [{ type: 'complete_workflow', result }]);
  assert.deepEqual(completion.commands[0].result, task.arguments);

  assert.throws(
    () => workflowTaskCompletionPayload(task, [{ type: 'complete_workflow', result: '["raw-json"]' }]),
    /JSON-shaped payload instead of Avro/,
  );
});
