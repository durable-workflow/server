import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSignalsBeforeWorker} from '../../scripts/conformance/server-parity/signals-before-worker-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/signals-before-worker.json', import.meta.url)));
const expected = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityExpected/signals-before-worker.json', import.meta.url)));
const workflowId = 'TEST-signals-before-worker';
const workflow = 'test-signals-before-worker-before-worker';
const namespace = 'test-signals-before-worker-unregistered';
const queue = 'test-signals-before-worker-unserved';
const EVENTS = ['StartAccepted', 'WorkflowStarted', 'SignalReceived', 'SignalReceived', 'SignalReceived', 'SignalReceived',
  'TerminateRequested', 'WorkflowTerminated', 'SignalRejected', 'SignalRejected', 'SignalRejected', 'SignalRejected'];
function model() {
  // Requests are recorded as the fixture's templates; responses carry the run's names.
  const steps = expected.map(step => ({...step, response: JSON.parse(JSON.stringify(step.response)
    .replaceAll('@workflow', workflow).replaceAll('@namespace', namespace).replaceAll('@queue', queue))}));
  return {mode: 'http', signals_before_worker: {applicable: true, namespace, queue, workflow_id: workflow,
    run_id: '01M5EJXRUNIDENTIFIER00000A', steps: [
      {request: 'POST workflows', status: 201, response: {workflow_id: workflow, outcome: 'started_new'}},
      ...steps,
      {request: 'GET workflows/@workflow/runs/@run/history', status: 200,
        response: {events: EVENTS.map(event_type => ({event_type}))}},
    ]}};
}
const projection = {scope: 'signals_before_worker'};

test('published PHP responses pass', () => assert.deepStrictEqual(checkSignalsBeforeWorker(fixture, model(), workflowId), projection));
test('native requires exact fresh keyed metadata while preserving all original receipt fields', () => {
  const native = model();
  const receipt = native.signals_before_worker.steps[1].response;
  Object.assign(receipt, {request_id: 'before-worker-1', retained: false, intent_verified: true});
  assert.deepStrictEqual(checkSignalsBeforeWorker(fixture, native, workflowId, 'rust'), projection);
  assert.throws(() => checkSignalsBeforeWorker(fixture, native, workflowId, 'php'));
  assert.throws(() => checkSignalsBeforeWorker(fixture, model(), workflowId, 'rust'));
  for (const [field, wrong] of [['request_id', 'another-key'], ['retained', true], ['intent_verified', false],
    ['command_status', 'rejected'], ['command_id', 'missing']]) {
    const original = receipt[field];
    receipt[field] = wrong;
    assert.throws(() => checkSignalsBeforeWorker(fixture, native, workflowId, 'rust'), field);
    delete receipt[field];
    assert.throws(() => checkSignalsBeforeWorker(fixture, native, workflowId, 'rust'), field);
    receipt[field] = original;
  }
  native.signals_before_worker.steps[2].response.retained = false;
  assert.throws(() => checkSignalsBeforeWorker(fixture, native, workflowId, 'rust'), 'unkeyed responses stay exact');
});
test('embedded explicitly declares the case inapplicable', () => assert.deepStrictEqual(checkSignalsBeforeWorker(fixture,
  {mode: 'embedded', signals_before_worker: {applicable: false, reason: 'embedded_runs_have_a_loadable_contract'}}, workflowId),
  projection));
const step = (o, index) => o.signals_before_worker.steps[index];
for (const [label, mutate] of [
  ['another namespace', o => { o.signals_before_worker.namespace = 'default'; }],
  ['a signal rejected for an unrecorded contract', o => { step(o, 1).status = 404; step(o, 1).response.reason = 'unknown_signal'; }],
  ['a signal without input refused', o => { step(o, 2).status = 422; }],
  ['a reserved signal recorded as a command', o => { step(o, 5).response.command_id = '01M5EJXCOMMANDIDENTIFIER0A'; }],
  ['a reserved signal admitted', o => { step(o, 5).status = 202; }],
  ['a terminated run that admits a signal', o => { step(o, 7).status = 202; }],
  ['a lost signal in history', o => { step(o, 10).response.events.splice(2, 1); }],
  ['a terminate response without its envelope', o => { delete step(o, 6).response.control_plane; }],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation);
    assert.throws(() => checkSignalsBeforeWorker(fixture, observation, workflowId));
  });
}
