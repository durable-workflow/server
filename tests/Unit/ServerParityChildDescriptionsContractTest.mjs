import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkChildDescriptions} from '../../scripts/conformance/server-parity/child-descriptions-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/child-descriptions.json', import.meta.url), 'utf8'));
const expected = fixture.child_descriptions.expected;
const observation = () => structuredClone({mode: 'http', child_descriptions: {applicable: true, ...expected}});
const cases = Object.keys(fixture.child_descriptions.cases).length;

test('the published answers pass', () => {
  assert.deepStrictEqual(checkChildDescriptions(fixture, observation()), {scope: 'child_descriptions', cases});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkChildDescriptions(fixture,
    {mode: 'embedded', child_descriptions: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'child_descriptions', cases});
});

test('children and timers are described by the published precedence', () => {
  assert.deepStrictEqual(Object.fromEntries(Object.entries(expected).map(([name, run]) => [name, run.described.wait_kind])),
    {one_child: 'child', two_children: 'child', child_then_timer: 'timer', timer_then_child: 'timer', terminating_child: 'child',
      cancelling_child: 'workflow-task', child_then_activity: 'activity', activity_then_child: 'activity'});
  assert.deepStrictEqual([expected.cancelling_child.completed.status, expected.cancelling_child.completed.reason],
    [409, 'child_cancellation_policy_not_supported']);
});

for (const [name, mutate] of [
  ['two children described without a wait', state => { state.two_children.described.wait_kind = null; }],
  ['a child ahead of an open timer', state => { state.child_then_timer.described.wait_kind = 'child'; }],
  ['a child beside an activity described without a wait', state => { state.child_then_activity.described.wait_kind = null; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.child_descriptions);
  assert.throws(() => checkChildDescriptions(fixture, raw));
});
