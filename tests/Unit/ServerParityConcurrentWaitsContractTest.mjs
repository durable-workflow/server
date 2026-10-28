import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkConcurrentWaits} from '../../scripts/conformance/server-parity/concurrent-waits-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/concurrent-waits.json', import.meta.url), 'utf8'));
const expected = fixture.concurrent_waits.expected;
const observation = () => structuredClone({mode: 'http', concurrent_waits: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkConcurrentWaits(fixture, observation()), {scope: 'concurrent_waits', runs: 4});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkConcurrentWaits(fixture,
    {mode: 'embedded', concurrent_waits: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), {scope: 'concurrent_waits', runs: 4});
  assert.throws(() => checkConcurrentWaits(fixture, {mode: 'embedded', concurrent_waits: {applicable: true}}));
});

test('the fixture opens three waits at once and resolves each by its own signal', () => {
  const opened = expected.mixed.opened_history.filter(([type]) => /WaitOpened$/.test(type));
  assert.deepStrictEqual(opened.map(([, fields]) => fields.sequence), [1, 2, 3]);
  assert.deepStrictEqual(expected.mixed.second_resume.signal_wait_id, '{wait:2}');
  assert.deepStrictEqual(expected.mixed.first_resume.signal_wait_id, '{wait:1}');
  assert.deepStrictEqual(expected.same_name.received.map(([, fields]) => fields.signal_wait_id), ['{wait:6}', '{buffered:1}']);
});

test('the fixture delivers a buffered signal only when declared or when a wait takes its identity', () => {
  assert.equal(expected.same_name.buffered_resume, null);
  assert.deepStrictEqual(expected.same_name.buffered_history, []);
  const declared = expected.same_name_declared;
  const [earlier] = declared.received.map(([, fields]) => fields.signal_wait_id);
  assert.equal(declared.buffered_resume.signal_wait_id, declared.received[3][1].signal_wait_id);
  const rebound = declared.buffered_history.find(([type]) => type === 'SignalApplied')[1];
  assert.deepStrictEqual([rebound.sequence, rebound.signal_wait_id], [1, earlier]);
  const reopen = expected.same_name_reopen;
  const buffered = reopen.received[3][1].signal_wait_id;
  assert.deepStrictEqual(reopen.applied_history.find(([type]) => type === 'SignalWaitOpened')[1],
    {sequence: 3, signal_name: 'dup', signal_wait_id: buffered});
  const applied = reopen.buffered_history.find(([type]) => type === 'SignalApplied')[1];
  assert.deepStrictEqual([applied.sequence, applied.signal_wait_id], [3, buffered]);
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
  ['the undeclared buffered signal delivered', state => { state.same_name.buffered_resume = structuredClone(state.same_name_reopen.buffered_resume); }],
  ['the declared buffered signal left undelivered', state => { state.same_name_declared.buffered_resume = null; }],
  ['the declared buffered signal applied without a wait', state => {
    const applied = state.same_name_declared.buffered_history.find(([type]) => type === 'SignalApplied')[1];
    delete applied.sequence;
    applied.signal_wait_id = state.same_name_declared.received[3][1].signal_wait_id;
  }],
  ['the reopened wait taking a fresh identity', state => {
    state.same_name_reopen.applied_history.find(([type]) => type === 'SignalWaitOpened')[1].signal_wait_id = '{wait:99}';
  }],
  ['the buffered signal resuming an earlier wait after a reopen', state => {
    state.same_name_reopen.buffered_history.find(([type]) => type === 'SignalApplied')[1].sequence = 1;
  }],
  ['an unreviewed step', state => { state.mixed.extra = true; }],
  ['a missing run', state => { delete state.same_name; }],
]) test(`the contract rejects ${name}`, () => {
  const value = observation();
  mutate(value.concurrent_waits);
  assert.throws(() => checkConcurrentWaits(fixture, value));
});
