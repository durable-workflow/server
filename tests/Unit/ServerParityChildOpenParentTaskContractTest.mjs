import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkChildOpenParentTask} from '../../scripts/conformance/server-parity/child-open-parent-task-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/child-open-parent-task.json', import.meta.url), 'utf8'));
const expected = fixture.child_open_parent_task.expected;
const observation = () => structuredClone({mode: 'http', child_open_parent_task: {applicable: true, ...expected}});
const projection = {scope: 'child_open_parent_task', cases: Object.keys(expected).length};

test('the published answers pass', () => {
  assert.deepStrictEqual(checkChildOpenParentTask(fixture, observation()), projection);
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkChildOpenParentTask(fixture,
    {mode: 'embedded', child_open_parent_task: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}), projection);
});

test('the ready task is the parent\'s only resume and replays the child result', () => {
  assert.deepStrictEqual(expected.ready.claimable_parent_tasks, [{workflow_update_id: null, replays_child_result: true}]);
  assert.equal(expected.ready.closed.run_status, 'completed');
  assert.equal(expected.ready.described_after_child.wait_kind, 'workflow-task');
});

for (const [name, mutate] of [
  ['a second resume task for the child', state => {
    state.ready.claimable_parent_tasks.push({workflow_update_id: null, replays_child_result: true});
  }],
  ['a resume that does not replay the child result', state => {
    state.ready.claimable_parent_tasks[0].replays_child_result = false;
  }],
  ['a resume carrying an update id', state => { state.ready.claimable_parent_tasks[0].workflow_update_id = 'u'; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.child_open_parent_task);
  assert.throws(() => checkChildOpenParentTask(fixture, raw));
});
