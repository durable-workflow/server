import assert from 'node:assert/strict';
import test from 'node:test';
import {checkDispatchSummary, checkedPolicyDispatchSummaries} from '../../scripts/conformance/server-parity/schedule-dispatch-summary-contract.mjs';

const started = {id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', outcome: 'started', recorded_at: '2026-11-06T08:00:00.123000Z',
  reason: null, workflow_instance_id: '01ARZ3NDEKTSV4RRFFQ69G5FAW', workflow_run_id: '01ARZ3NDEKTSV4RRFFQ69G5FAX'};
const known = () => ({version: 1, state: 'known', last_outcome: structuredClone(started), buffered_actions_count: 0});

test('complete unknown and never states have distinct null/count authority', () => {
  checkDispatchSummary({version: 1, state: 'unavailable', last_outcome: null, buffered_actions_count: null});
  checkDispatchSummary({version: 1, state: 'never', last_outcome: null, buffered_actions_count: 0});
  assert.throws(() => checkDispatchSummary({version: 1, state: 'unavailable', last_outcome: null, buffered_actions_count: 0}));
  assert.throws(() => checkDispatchSummary({version: 1, state: 'never', buffered_actions_count: 0}));
});

test('all recorded outcomes preserve literal reasons and current counts', () => {
  checkDispatchSummary(known());
  for (const outcome of ['skipped', 'failed', 'buffered']) {
    const summary = known();
    Object.assign(summary.last_outcome, {outcome, workflow_instance_id: null, workflow_run_id: null});
    for (const reason of outcome === 'buffered' ? [null] : ['', ' ', '\0', ' denied ', '😀'.repeat(64)]) {
      summary.last_outcome.reason = reason;
      summary.buffered_actions_count = 2;
      checkDispatchSummary(summary);
      summary.buffered_actions_count = 0;
      checkDispatchSummary(summary);
    }
  }
});

for (const [name, mutate] of [
  ['lowercase identity', s => { s.last_outcome.id = s.last_outcome.id.toLowerCase(); }],
  ['overflow identity', s => { s.last_outcome.id = '81ARZ3NDEKTSV4RRFFQ69G5FAV'; }],
  ['missing outcome member', s => { delete s.last_outcome.reason; }],
  ['noninteger count', s => { s.buffered_actions_count = '0'; }],
  ['unsafe count', s => { s.buffered_actions_count = Number.MAX_SAFE_INTEGER + 1; }],
  ['invalid calendar', s => { s.last_outcome.recorded_at = '2026-02-30T08:00:00.123000Z'; }],
  ['lost precision', s => { s.last_outcome.recorded_at = '2026-11-06T08:00:00.123Z'; }],
  ['oversized reason', s => { Object.assign(s.last_outcome, {outcome: 'failed', reason: 'x'.repeat(65), workflow_instance_id: null, workflow_run_id: null}); }],
]) test(`dispatch validation refuses ${name}`, () => {
  const summary = known(); mutate(summary); assert.throws(() => checkDispatchSummary(summary));
});

test('native policy comparison binds raw detail to the preceding actual trigger receipt', () => {
  const steps = [
    {status: 200, request: 'POST schedules/hourly/trigger', response: {schedule_id: 'hourly', outcome: 'triggered', workflow_id: started.workflow_instance_id, run_id: started.workflow_run_id}},
    {status: 200, request: 'GET schedules/hourly', response: {schedule_id: 'hourly', spec: {}, dispatch_summary: known()}},
  ];
  const original = structuredClone(steps);
  assert.deepStrictEqual(checkedPolicyDispatchSummaries(steps, 'rust')[1].response, {schedule_id: 'hourly', spec: {}});
  assert.deepStrictEqual(steps, original, 'retained raw observations keep the validated summary');
  steps[1].response.dispatch_summary.last_outcome.workflow_run_id = started.workflow_instance_id;
  assert.throws(() => checkedPolicyDispatchSummaries(steps, 'rust'), /complete last manual outcome/);
  delete steps[1].response.dispatch_summary;
  assert.throws(() => checkedPolicyDispatchSummaries(steps, 'rust'), /complete dispatch summary/);
  assert.doesNotThrow(() => checkedPolicyDispatchSummaries(steps, 'php'), 'older PHP keeps its frozen response');
});
