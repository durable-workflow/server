import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkParentCloseCancellation} from '../../scripts/conformance/server-parity/parent-close-cancellation-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParity/parent-close-cancellation.json', import.meta.url), 'utf8'));
const expected = fixture.parent_close_cancellation.expected;
const observation = () => structuredClone({mode: 'http', parent_close_cancellation: {applicable: true, ...expected}});
const cases = ['completed', 'failed', 'terminated'];

test('the published answers pass', () => {
  assert.deepStrictEqual(checkParentCloseCancellation(fixture, observation()), {scope: 'parent_close_cancellation', cases: 4});
});

test('embedded applications have no worker protocol', () => {
  assert.deepStrictEqual(checkParentCloseCancellation(fixture,
    {mode: 'embedded', parent_close_cancellation: {applicable: false, reason: 'embedded_has_no_worker_protocol'}}),
  {scope: 'parent_close_cancellation', cases: 4});
  assert.throws(() => checkParentCloseCancellation(fixture, {mode: 'embedded', parent_close_cancellation: {applicable: true}}));
});

test('every parent closes and records one origin after its terminal event', () => {
  for (const [name, terminal, status] of [['completed', 'WorkflowCompleted', 'completed'], ['failed', 'WorkflowFailed', 'failed'],
    ['terminated', 'WorkflowTerminated', 'terminated']]) {
    const state = expected[name];
    assert.equal(state.parent_status, status, name);
    const events = state.parent_events;
    assert.equal(events.filter(event => event === 'ParentCloseCancellationRequested').length, 1, name);
    assert.equal(events.indexOf('ParentCloseCancellationRequested'), events.indexOf(terminal) + 1, name);
    assert.deepStrictEqual(events.slice(events.indexOf(terminal) + 2), state.children.map(() => 'ParentClosePolicyApplied'), name);
    assert.deepStrictEqual([state.origin.parent_terminal_event_type, state.origin.requested_at_is_closure,
      state.origin.deadline_is_requested_plus_600_seconds], [terminal, true, true], name);
    const origin = state.origin.cancellation;
    assert.deepStrictEqual([origin.request_id, origin.parent_request_id, origin.source, origin.lineage.length],
      [origin.root_request_id, null, 'parent_close_policy', 1], name);
  }
});

test('each child takes a descendant request under the origin and receives it', () => {
  for (const name of cases) {
    const state = expected[name];
    const root = state.origin.cancellation.request_id;
    state.children.forEach((child, index) => {
      const applied = state.policy_events[index];
      assert.deepStrictEqual([applied.event_type, applied.payload.child_run_id, applied.payload.request_id],
        ['ParentClosePolicyApplied', child.run, child.request.workflow_command_id], name);
      assert.deepStrictEqual([child.request.cancellation.root_request_id, child.request.cancellation.parent_request_id,
        child.request.cleanup_deadline_at], [root, root, '{origin_cleanup_deadline_at}'], name);
      assert.deepStrictEqual(child.events, ['WorkflowStarted', 'CooperativeCancellationRequested'], name);
      assert.deepStrictEqual([child.request.command.source, child.request.command.target_scope,
        child.request.command.context.workflow.parent_run_id, child.request.command.context.workflow.sequence],
      ['workflow', 'instance', state.origin.cancellation.root_workflow_run_id, index + 1], name);
      assert.deepStrictEqual([state.child_tasks[index].run_id, state.child_tasks[index].cancellation_request.request_id],
        [child.run, child.request.workflow_command_id], name);
    });
    assert.equal(state.child_tasks.at(-1), null, name);
  }
});

test('a child under another root refuses and records the rejected request', () => {
  const state = expected.conflict;
  assert.deepStrictEqual([state.child_requested.status, state.parent_status], [202, 'terminated']);
  const [failed] = state.policy_events;
  assert.deepStrictEqual([failed.event_type, failed.payload.error, failed.payload.cancellation],
    ['ParentClosePolicyFailed', 'cancellation_root_conflict', null]);
  assert.equal(failed.payload.request_diagnostics.incoming_root_request_id, state.origin.cancellation.request_id);
  const [own, rejected] = state.children[0].commands;
  assert.equal(failed.payload.request_diagnostics.existing_request_id, own.id);
  assert.deepStrictEqual([rejected.id, rejected.sequence, rejected.status, rejected.source, rejected.target_scope,
    rejected.rejection_reason, rejected.outcome],
  [failed.payload.request_id, 2, 'rejected', 'workflow', 'instance', 'cancellation_root_conflict', undefined]);
});

for (const [name, mutate] of [
  ['a refused completion', state => {
    state.completed.completed = {status: 409, outcome: null, reason: 'pending_durable_operations', run_status: null, created_tasks: 0};
  }],
  ['a refused termination', state => {
    state.terminated.terminated = {status: 422, outcome: null, reason: 'termination_propagation_not_available'};
    state.terminated.parent_status = 'waiting';
  }],
  ['a failed parent that leaves its child open', state => {
    state.failed.parent_events = state.failed.parent_events.slice(0, -2);
    state.failed.origin = null;
    state.failed.policy_events = [];
  }],
  ['an origin per child', state => {
    state.terminated.parent_events.splice(-1, 0, 'ParentCloseCancellationRequested');
  }],
  ['an origin at another time', state => { state.completed.origin.requested_at_is_closure = false; }],
  ['another budget', state => { state.completed.origin.deadline_is_requested_plus_600_seconds = false; }],
  ['a child under its own root', state => {
    state.completed.children[0].request.cancellation.root_request_id = state.completed.children[0].request.workflow_command_id;
  }],
  ['a child request with its own deadline', state => {
    state.completed.children[0].request.cleanup_deadline_at = '{instant}';
  }],
  ['a child task without its request', state => { state.failed.child_tasks[0].cancellation_request = null; }],
  ['a request recorded as a PHP API command on the run', state => {
    const command = state.completed.children[0].request.command;
    Object.assign(command, {source: 'php', caller_label: 'PHP API', target_scope: 'run', context: {}});
  }],
  ['a policy failure', state => {
    state.terminated.policy_events[1].event_type = 'ParentClosePolicyFailed';
  }],
  ['a conflict without its rejected command', state => {
    state.conflict.policy_events[0].payload.request_id = null;
    state.conflict.children[0].commands.pop();
  }],
  ['a conflict without diagnostics', state => { state.conflict.policy_events[0].payload.request_diagnostics = []; }],
  ['an unexpected step', state => { state.completed.extra = true; }],
  ['an unexpected case', state => { state.extra = {}; }],
]) test(`the contract rejects ${name}`, () => {
  const raw = observation(); mutate(raw.parent_close_cancellation);
  assert.throws(() => checkParentCloseCancellation(fixture, raw));
});
