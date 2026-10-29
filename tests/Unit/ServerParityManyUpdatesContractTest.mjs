import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkManyUpdates} from '../../scripts/conformance/server-parity/many-updates-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/many-updates.json', import.meta.url), 'utf8'));
const expected = fixture.many_updates.expected;
const observation = () => structuredClone({mode: 'http', many_updates: {applicable: true, ...expected}});
const steps = Object.keys(expected).length;

test('the published answers pass', () => {
  assert.deepStrictEqual(checkManyUpdates(fixture, observation()), {scope: 'many_updates', steps});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkManyUpdates(fixture,
    {mode: 'embedded', many_updates: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'many_updates', steps});
  assert.throws(() => checkManyUpdates(fixture, {mode: 'embedded', many_updates: {applicable: true}}));
});

test('65 completed updates leave the signal wait, and a 66th is the open update', () => {
  assert.deepStrictEqual(expected.updates, {'202 accepted own_task 200 waiting': 65});
  assert.deepStrictEqual([expected.after.wait_kind, expected.pending.described.wait_kind], ['signal', 'update']);
  assert.deepStrictEqual([expected.events.UpdateAccepted, expected.events.UpdateCompleted], [66, 65]);
});

for (const [name, mutate] of [
  ['a run described without its wait after 64 updates', state => {
    state.after.wait_kind = null; state.after.wait_reason = null;
  }],
  ['a pending update that is not the open wait', state => { state.pending.described.wait_kind = 'signal'; }],
  ['a refused 65th update', state => {
    state.updates = {'202 accepted own_task 200 waiting': 64, '503 none other_task 404 none': 1};
  }],
  ['an unexpected step', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.many_updates);
  assert.throws(() => checkManyUpdates(fixture, raw));
});
