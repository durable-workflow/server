import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

// Published PHP's reviewed, normalized responses to every step.
const EXPECTED = JSON.parse(readFileSync(new URL('../../../tests/Fixtures/ServerParityExpected/signals-before-worker.json', import.meta.url)));

// Generated identities, times, audit fingerprints, route names and peer
// addresses differ between servers and runs.
const VOLATILE = [/^\d{4}-\d{2}-\d{2}T/, /^[0-9A-Za-z]{26}$/, /^sha256:[0-9a-f]{64}$/, /^generated::/, /^\d+\.\d+\.\d+\.\d+$/];

export function normalized(value, names = {}) {
  if (Array.isArray(value)) return value.map(item => normalized(item, names));
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([field, item]) => [field, normalized(item, names)]));
  }
  if (typeof value !== 'string') return value;
  if (VOLATILE.some(pattern => pattern.test(value))) return '@v';
  return Object.entries(names).reduce((text, [name, placeholder]) => text.replaceAll(name, placeholder), value);
}

export const STATUSES = [201, 202, 202, 202, 202, 422, 200, 409, 422, 404, 200];
// From Server 2.10, signals that a run closed before applying are recorded as
// rejected after its terminal event.
const EVENTS = ['StartAccepted', 'WorkflowStarted', 'SignalReceived', 'SignalReceived', 'SignalReceived', 'SignalReceived',
  'TerminateRequested', 'WorkflowTerminated', 'SignalRejected', 'SignalRejected', 'SignalRejected', 'SignalRejected'];

export function checkSignalsBeforeWorker(fixture, observation, workflowId) {
  const state = observation.signals_before_worker;
  assert.ok(state && typeof state === 'object', 'actual signals-before-worker observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_runs_have_a_loadable_contract'},
      'embedded runs explicitly inapplicable');
    return {scope: 'signals_before_worker'};
  }
  const namespace = `${workflowId}-unregistered`.toLowerCase();
  assert.deepStrictEqual([state.applicable, state.namespace, state.workflow_id],
    [true, namespace, `${workflowId}-before-worker`.toLowerCase()], 'fresh namespace and run');
  const names = {[state.workflow_id]: '@workflow', [namespace]: '@namespace', [state.queue]: '@queue'};
  const steps = state.steps.map(step => ({request: step.request, status: step.status, response: normalized(step.response, names)}));
  assert.deepStrictEqual(steps.map(step => step.status), STATUSES, 'step statuses');
  const signals = steps.slice(1, 5).map(step => step.response);
  assert.ok(signals.every(signal => signal.outcome === 'signal_received' && signal.command_status === 'accepted'),
    'every signal is admitted without a contract');
  assert.deepStrictEqual([steps[5].response.reason, steps[5].response.accepted, 'command_id' in steps[5].response],
    ['runtime_reserved_signal', false, false], 'reserved runtime signal refused without a command');
  assert.deepStrictEqual([steps[7].response.reason, steps[8].response.reason, steps[9].response.reason],
    ['run_not_active', 'runtime_reserved_signal', 'instance_not_found'], 'after termination and for a missing workflow');
  assert.deepStrictEqual(steps[10].response.events.map(event => event.event_type), EVENTS, 'durable history');
  // This fixture covers signal admission: the start response and history
  // payloads are compared by their own fixtures.
  const compared = steps.slice(1, 10);
  assert.deepStrictEqual(compared, EXPECTED, 'every response matches the reviewed published PHP responses');
  return {scope: 'signals_before_worker'};
}
