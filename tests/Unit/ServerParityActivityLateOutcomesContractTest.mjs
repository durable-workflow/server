import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkActivityLateOutcomes} from '../../scripts/conformance/server-parity/activity-late-outcomes-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-late-outcomes.json', import.meta.url), 'utf8'));
const definition = fixture.activity_late_outcomes;
const cases = Object.keys(definition.cases).length;
const observation = refusal => {
  const state = structuredClone(definition.expected);
  for (const value of Object.values(state)) value.answer.refusal = refusal;
  return {mode: 'http', activity_late_outcomes: {applicable: true, ...state}};
};

test('either refusal class passes, before or after the timeout sweep', () => {
  for (const refusal of ['deadline_reason', 'after_timeout']) {
    assert.deepStrictEqual(checkActivityLateOutcomes(fixture, observation(refusal)), {scope: 'activity_late_outcomes', cases});
  }
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkActivityLateOutcomes(fixture,
    {mode: 'embedded', activity_late_outcomes: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'activity_late_outcomes', cases});
});

test('every late outcome is refused unrecorded and the deadline times the activity out once', () => {
  for (const [name, expected] of Object.entries(definition.expected)) {
    assert.deepStrictEqual([expected.answer.status, expected.answer.recorded], [409, false], name);
    assert.deepStrictEqual(expected.activity_events, ['ActivityScheduled', 'ActivityStarted', 'ActivityTimedOut'], name);
    assert.equal(expected.timeout_kind, definition.cases[name].timeout_reason.replace(/_timeout$/, ''), name);
  }
});

for (const [name, mutate] of [
  ['a recorded late completion', state => {
    state.start_to_close_complete.answer = {status: 200, recorded: true, refusal: null};
    state.start_to_close_complete.activity_events = ['ActivityScheduled', 'ActivityStarted', 'ActivityCompleted'];
  }],
  ['another refusal reason', state => { state.heartbeat_complete.answer.refusal = 'lease_expired'; }],
  ['a second timeout', state => { state.schedule_to_close_complete.activity_events.push('ActivityTimedOut'); }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation('deadline_reason'); mutate(raw.activity_late_outcomes);
  assert.throws(() => checkActivityLateOutcomes(fixture, raw));
});
