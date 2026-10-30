import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkActivityResultRepeats} from '../../scripts/conformance/server-parity/activity-result-repeats-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/activity-result-repeats.json', import.meta.url), 'utf8'));
const expected = fixture.activity_result_repeats.expected;
const observation = () => structuredClone({mode: 'http', activity_result_repeats: {applicable: true, ...expected}});
const cases = Object.keys(fixture.activity_result_repeats.cases).length;
const repeats = ['repeat_identical', 'repeat_different', 'repeat_other_route', 'repeat_after_close'];

test('the published answers pass', () => {
  assert.deepStrictEqual(checkActivityResultRepeats(fixture, observation()), {scope: 'activity_result_repeats', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkActivityResultRepeats(fixture,
    {mode: 'embedded', activity_result_repeats: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'activity_result_repeats', cases});
});

test('every repeat of a finished attempt is stale and records nothing', () => {
  for (const [name, observed] of Object.entries(expected)) {
    for (const step of repeats) {
      assert.deepStrictEqual([observed[step].status, observed[step].body.reason, observed[step].body.recorded],
        [409, 'stale_attempt', false], `${name} ${step}`);
    }
    assert.equal(observed.events.filter(event => /^Activity(Completed|Failed)$/.test(event)).length, 1, name);
  }
  // A repeated completion names its owner and closed statuses, which the
  // published SDKs read as their own committed completion.
  assert.deepStrictEqual(['lease_owner', 'activity_status', 'attempt_status', 'task_status']
    .map(field => expected.completed.repeat_identical.body[field]), ['{owner}', 'completed', 'completed', 'completed']);
  assert.equal(expected.failed.repeat_identical.body.lease_owner, undefined);
});

for (const [name, mutate] of [
  ['an idempotent receipt for an identical repeat', state => {
    state.completed.repeat_identical = {status: 200, body: {...state.completed.repeat_identical.body, reason: 'already_completed'}}; }],
  ['a repeated failure receipt with owner fields', state => {
    state.failed.repeat_identical.body.lease_owner = '{owner}'; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.activity_result_repeats);
  assert.throws(() => checkActivityResultRepeats(fixture, raw));
});
