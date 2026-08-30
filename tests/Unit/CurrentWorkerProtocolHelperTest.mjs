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

test('direct completion reuses official Avro task argument envelopes', () => {
  const result = avroResultFromTaskArguments(task);
  const completion = workflowTaskCompletionPayload(task, [{ type: 'complete_workflow', result }]);
  assert.deepEqual(completion.commands[0].result, task.arguments);
});

test('direct completion reuses official Avro task argument strings', () => {
  const stringTask = {
    ...task,
    arguments: task.arguments.blob,
    payload_codec: 'avro',
  };
  const result = avroResultFromTaskArguments(stringTask);
  const completion = workflowTaskCompletionPayload(stringTask, [{ type: 'complete_workflow', result }]);
  assert.equal(completion.commands[0].result, task.arguments.blob);
});

const jsonDocumentStrings = {
  null: ' \tnull\r\n',
  true: ' true\t',
  false: '\nfalse ',
  integer: ' 7\r\n',
  exponent: '\t-7.25e+3 ',
  string: ' "raw-json"\n',
  array: '\r["raw-json"] ',
  object: ' {"status":"completed"}\t',
};

for (const [valueClass, payload] of Object.entries(jsonDocumentStrings)) {
  test(`direct completion rejects complete JSON ${valueClass} document strings`, () => {
    assert.throws(
      () => workflowTaskCompletionPayload(task, [{ type: 'complete_workflow', result: payload }]),
      /JSON-shaped payload instead of Avro/,
    );
  });
}

const rawJsonValues = {
  null: null,
  true: true,
  false: false,
  integer: 7,
  number: 7.25,
  array: ['raw-json'],
  object: { status: 'completed' },
};

for (const [valueClass, payload] of Object.entries(rawJsonValues)) {
  test(`direct completion rejects raw JSON ${valueClass} values`, () => {
    assert.throws(
      () => workflowTaskCompletionPayload(task, [{ type: 'complete_workflow', result: payload }]),
      /must contain an Avro Value payload/,
    );
  });
}
