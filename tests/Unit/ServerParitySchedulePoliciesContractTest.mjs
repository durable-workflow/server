import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkSchedulePolicies} from '../../scripts/conformance/server-parity/schedule-policies-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/schedule-policies.json', import.meta.url)));
const expected = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityExpected/schedule-policies.json', import.meta.url)));
const workflowId = 'TEST-schedule-policies';
function model() {
  const steps = structuredClone(expected);
  // Generated values as a server reports them before normalization.
  steps[3].response.next_fire_at = '2027-01-01T02:00:00+00:00';
  steps[3].response.created_at = '2026-10-21T05:00:00+00:00';
  return {mode: 'http', schedule_policies: {applicable: true, namespace: 'test-schedule-policies-schedules',
    queue: 'test-schedule-policies-queue', retries: 0, attempts: 1, steps: steps.map(step => JSON.parse(JSON.stringify(step)
      .replaceAll('@namespace', 'test-schedule-policies-schedules').replaceAll('@queue', 'test-schedule-policies-queue')))}};
}
const projection = {scope: 'schedule_policies'};
const at = (observation, request) => observation.schedule_policies.steps.filter(step => step.request === request);

test('published PHP responses pass', () => assert.deepStrictEqual(checkSchedulePolicies(fixture, model(), workflowId), projection));
test('retried lock failures pass', () => {
  const observation = model();
  observation.schedule_policies.retries = 2;
  assert.deepStrictEqual(checkSchedulePolicies(fixture, observation, workflowId), projection);
});
test('embedded explicitly declares the HTTP surface inapplicable', () => assert.deepStrictEqual(checkSchedulePolicies(fixture,
  {mode: 'embedded', schedule_policies: {applicable: false, reason: 'embedded_has_no_http_schedules'}}, workflowId), projection));
for (const [label, mutate] of [
  ['another namespace', o => { o.schedule_policies.namespace = 'default'; }],
  ['unrecorded retries', o => { delete o.schedule_policies.retries; }],
  ['unrecorded attempts', o => { delete o.schedule_policies.attempts; }],
  ['a replay in the first namespace', o => { o.schedule_policies.attempts = 2; }],
  ['a missing step', o => { o.schedule_policies.steps.pop(); }],
  ['another status', o => { o.schedule_policies.steps[4].status = 422; }],
  ['a changed validation message', o => { o.schedule_policies.steps[1].response.message = 'The spec field is required.'; }],
  ['another yearly fire time', o => { o.schedule_policies.steps[3].response.next_fire_at = '2027-01-01T03:00:00+00:00'; }],
  ['an untrimmed note', o => { o.schedule_policies.steps[3].response.note = '  yearly report  '; }],
  ['a buffer that never fills', o => { at(o, 'POST schedules/policy-buffer-one/trigger')[2].response.outcome = 'buffered'; }],
  ['a skip policy that starts another run', o => { at(o, 'POST schedules/policy-skip/trigger')[1].response.outcome = 'triggered'; }],
  ['a paused trigger that runs', o => { at(o, 'POST schedules/policy-skip/trigger').at(-1).response.reason = 'overlap_policy_skip'; }],
  ['an action without its timeout aliases', o => { delete at(o, 'GET schedules/mixed')[1].response.action.run_timeout_seconds; }],
  ['a different audit reason', o => { o.schedule_policies.steps.at(-1).response.events[0].payload.reason = 'deleted'; }],
]) {
  test(`rejects ${label}`, () => {
    const observation = model();
    mutate(observation);
    assert.throws(() => checkSchedulePolicies(fixture, observation, workflowId));
  });
}
test('a status difference names the step and its response', () => {
  const observation = model();
  observation.schedule_policies.steps[47] = {request: 'POST schedules/quota/trigger', status: 404,
    response: {reason: 'schedule_not_found', schedule_id: 'quota'}};
  assert.throws(() => checkSchedulePolicies(fixture, observation, workflowId),
    /step 47 POST schedules\/quota\/trigger: 404, expected 200: \{"reason":"schedule_not_found","schedule_id":"quota"\}/);
});
test('an outcome difference shows the trigger responses', () => {
  const observation = model();
  Object.assign(at(observation, 'POST schedules/policy-skip/trigger')[0].response, {outcome: 'skipped', reason: 'overlap_policy_skip'});
  assert.throws(() => checkSchedulePolicies(fixture, observation, workflowId),
    /skip trigger outcomes \["skipped","skipped","skipped"\], expected \["triggered","skipped","skipped"\]: .*"reason":"overlap_policy_skip"/);
});
