import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkLargeCommandBatches} from '../../scripts/conformance/server-parity/large-command-batches-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/large-command-batches.json', import.meta.url), 'utf8'));
const expected = fixture.large_command_batches.expected;
const observation = () => structuredClone({mode: 'http', large_command_batches: {applicable: true, ...expected}});

test('the published answers pass', () => {
  assert.deepStrictEqual(checkLargeCommandBatches(fixture, observation()), {scope: 'large_command_batches', runs: 7});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkLargeCommandBatches(fixture,
    {mode: 'embedded', large_command_batches: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'large_command_batches', runs: 7});
});

test('150 commands are accepted in one completion', () => {
  for (const [name, event] of [['timers', 'TimerScheduled'], ['activities', 'ActivityScheduled']]) {
    assert.deepStrictEqual([expected[name].completed.status, expected[name].completed.created_tasks, expected[name].events[event]],
      [200, 150, 150], name);
  }
});

test('a 150-member group is accepted, and a 1,001-member group is refused on every command', () => {
  const group = expected.activity_group;
  assert.deepStrictEqual([group.completed.status, group.completed.created_tasks, group.parallel_groups],
    [200, 150, {'parallel-activities:1:150': 150}]);
  const over = expected.group_over_limit.completed;
  assert.deepStrictEqual([over.status, over.reason, Object.keys(over.errors)],
    [422, 'validation_failed', ['commands.0.parallel_group_path', 'commands.1.parallel_group_path']]);
  assert.deepStrictEqual(expected.group_over_limit.events, {StartAccepted: 1, WorkflowStarted: 1});
});

for (const [name, mutate] of [
  ['the former 100-command refusal', state => {
    state.timers.completed = {status: 422, outcome: null, reason: 'invalid_commands', run_status: null, message: 'invalid_commands',
      errors: null, created_tasks: 0};
    state.timers.events = {StartAccepted: 1, WorkflowStarted: 1};
  }],
  ['a truncated batch', state => { state.activities.events.ActivityScheduled = 100; }],
  ['a run described without its wait', state => { state.timers.described.wait_kind = null; }],
  ['the former 100-member group refusal', state => {
    state.activity_group.completed = {status: 422, outcome: null, reason: 'development_parallel_group_unqualified',
      run_status: null, message: 'development_parallel_group_unqualified', errors: null, created_tasks: 0};
  }],
  ['an overflowing selection key accepted', state => {
    state.select_key_overflow.completed = {status: 200, outcome: 'completed', reason: null, run_status: 'waiting',
      message: null, errors: null, created_tasks: 1};
  }],
  ['an overflowing member refused for another reason', state => {
    state.select_member_overflow.completed.reason = 'development_parallel_group_unqualified';
  }],
  ['an over-limit group refused on its first command only', state => {
    delete state.group_over_limit.completed.errors['commands.1.parallel_group_path'];
  }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.large_command_batches);
  assert.throws(() => checkLargeCommandBatches(fixture, raw));
});
