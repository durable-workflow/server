import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkConcurrentWaits} from '../../scripts/conformance/server-parity/concurrent-waits-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/concurrent-waits.json', import.meta.url), 'utf8'));
const expected = fixture.concurrent_waits.expected;
const observation = () => structuredClone({mode: 'http', concurrent_waits: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkConcurrentWaits(fixture, observation()), {scope: 'concurrent_waits', runs: 2});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkConcurrentWaits(fixture,
    {mode: 'embedded', concurrent_waits: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'concurrent_waits', runs: 2});
  assert.throws(() => checkConcurrentWaits(fixture, {mode: 'embedded', concurrent_waits: {applicable: true}}));
});

test('the fixture opens three waits at once and resolves each by its own signal', () => {
  const opened = expected.mixed.opened_history.filter(([type]) => /WaitOpened$/.test(type));
  assert.deepStrictEqual(opened.map(([, fields]) => fields.sequence), [1, 2, 3]);
  assert.deepStrictEqual(expected.mixed.second_resume.signal_wait_id, '{wait:2}');
  assert.deepStrictEqual(expected.mixed.first_resume.signal_wait_id, '{wait:1}');
  assert.deepStrictEqual(expected.same_name.received.map(([, fields]) => fields.signal_wait_id), ['{wait:6}', '{buffered:1}']);
});

for (const [name, mutate] of [
  ['a refused second wait', state => { state.mixed.opened.status = 409; state.mixed.opened.reason = 'pending_durable_operations'; }],
  ['a wait opened at another sequence', state => { state.mixed.opened_history[1][1].sequence = 1; }],
  ['a signal reserving another wait', state => { state.mixed.second_received[0][1].signal_wait_id = '{wait:1}'; }],
  ['a signal applied at another sequence', state => { state.mixed.second_history[2][1].sequence = 1; }],
  ['the condition wait left open', state => { state.mixed.second_history.splice(3, 2); }],
  ['the signal timeout left pending', state => { state.mixed.second_history.splice(1, 1); }],
  ['a summary naming the first wait', state => { state.mixed.opened_describe.wait_kind = 'signal'; }],
  ['an observation missing a wait', state => { state.mixed.opened_waits.pop(); }],
  ['the later signal resuming the first wait', state => { state.mixed.first_resume.signal_wait_id = '{wait:2}'; }],
  ['a same-name signal taking the earlier wait', state => { state.same_name.received[0][1].signal_wait_id = '{wait:5}'; }],
  ['a second same-name signal not buffered', state => { state.same_name.received[1][1].signal_wait_id = '{wait:5}'; }],
  ['an unreviewed step', state => { state.mixed.extra = true; }],
  ['a missing run', state => { delete state.same_name; }],
]) test(`the contract rejects ${name}`, () => {
  const value = observation();
  mutate(value.concurrent_waits);
  assert.throws(() => checkConcurrentWaits(fixture, value));
});
