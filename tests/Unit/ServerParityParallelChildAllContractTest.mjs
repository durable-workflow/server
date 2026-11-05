import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import {checkParallelChildAll} from '../../scripts/conformance/server-parity/parallel-child-all-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/parallel-child-all.json', import.meta.url), 'utf8'));
const reference = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityObservations/parallel-child-all-php.json', import.meta.url), 'utf8'));
function observation() {
  const state = structuredClone(reference.parallel_child_all);
  for (const value of Object.values(state.cases)) {
    value.prefix = structuredClone(value.events.slice(0, 2 + value.size * 2));
    value.at_parent_close = structuredClone(value.events);
  }
  return {mode: 'http', parallel_child_all: state};
}

test('the redacted published PHP observation satisfies the five-case contract', () => {
  assert.deepStrictEqual(checkParallelChildAll(fixture, observation()), {scope: 'parallel_child_all', cases: 5});
});

test('the explicitly reviewed native duplicate representation has the same common projection', () => {
  const value = observation();
  for (const item of Object.values(value.parallel_child_all.cases)) {
    const duplicate = {status: 200, recorded: false, reason: 'already_completed', message: null};
    item.duplicate_start_outcome = structuredClone(duplicate);
    item.duplicate_finish_outcome = structuredClone(duplicate);
    item.duplicate_child_outcomes = item.duplicate_child_outcomes.map(() => structuredClone(duplicate));
  }
  assert.deepStrictEqual(checkParallelChildAll(fixture, value), {scope: 'parallel_child_all', cases: 5});
});

test('embedded execution has an explicit worker-protocol boundary', () => {
  assert.deepStrictEqual(checkParallelChildAll(fixture, {mode: 'embedded',
    parallel_child_all: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'parallel_child_all', cases: 5});
  assert.throws(() => checkParallelChildAll(fixture, {mode: 'embedded', parallel_child_all: {applicable: true}}));
});

for (const [name, mutate] of [
  ['a missing case', state => { delete state.cases.single; }],
  ['an extra case', state => { state.cases.extra = {}; }],
  ['an initial start turn', state => { state.cases.single.initial_no_parent_task = false; }],
  ['an early successful wakeup', state => { state.cases.out_of_order.empty_before_last[0] = false; }],
  ['a missing atomic group member', state => { state.cases.out_of_order.initial_commands.pop(); }],
  ['a mixed child group', state => { state.cases.single.initial_commands[0].parallel_group_kind = 'mixed'; }],
  ['a changed durable path', state => { state.cases.single.prefix[2].payload.parallel_group_path = []; }],
  ['a child started handle', state => { state.cases.single.initial_commands[0].await = 'started'; }],
  ['a wrong child queue', state => { state.cases.single.initial_commands[0].queue = 'other'; }],
  ['a changed int64 argument', state => { state.cases.single.children[0].typed_arguments.value[0].value.value.value.count.value = '9007199254740992'; }],
  ['a replacement original child run', state => { state.cases.single.children[0].run_id = 'other-run'; }],
  ['a wrong original parent', state => { state.cases.single.children[0].events[0].payload.parent_workflow_run_id = 'other-parent'; }],
  ['a wrong child call resolution', state => { state.cases.single.events[4].payload.child_call_id = 'other-call'; }],
  ['a swapped ordered result', state => { state.cases.out_of_order.typed_result.value.results.value.reverse(); }],
  ['a wrong canonical committed output', state => { state.cases.single.events.at(-1).typed_decoded.output = {type: 'null', value: null}; }],
  ['a missing failure identity', state => { state.cases.failed.events.at(-2).payload.failure_id = ''; }],
  ['an untyped failed-child result', state => { state.cases.failed.typed_result.value.failure_type.value = 'Error'; }],
  ['a changed failure diagnostic', state => { state.cases.cancelled.typed_result.value.message.value = 'different'; }],
  ['a missing child closure', state => { state.cases.terminated.children[0].events.pop(); }],
  ['a later abandoned child changing parent history', state => { state.cases.failed.at_parent_close.pop(); }],
  ['a duplicate start recording effects', state => { state.cases.single.duplicate_start_outcome = {status: 200, recorded: true, reason: null, message: null}; }],
  ['an unexplained duplicate refusal', state => { state.cases.single.duplicate_child_outcomes[0].reason = 'other'; }],
  ['an extra parent resume', state => { state.cases.single.no_extra_parent_task = false; }],
  ['a recorded task claim', state => { state.cases.single.prefix[2].payload.task = {lease_owner: 'fixture-authority'}; }],
]) test(`the comparison rejects ${name}`, () => {
  const value = observation();
  mutate(value.parallel_child_all);
  assert.throws(() => checkParallelChildAll(fixture, value));
});
