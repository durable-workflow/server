import assert from 'node:assert/strict';

const equal = assert.deepStrictEqual;
const groupFields = ['parallel_group_id', 'parallel_group_kind', 'parallel_group_base_sequence',
  'parallel_group_size', 'parallel_group_index'];
const map = value => ({type: 'map', value});
const list = value => ({type: 'list', value});
const text = value => ({type: 'string', value});
const nonempty = value => assert.ok(typeof value === 'string' && value.length > 0, 'canonical identity');

// The immutable PHP reference refuses duplicate completed tasks. Native's
// existing immutable receipt acknowledges them. Both must preserve all history.
function duplicate(outcome, phase) {
  equal(outcome, outcome.status === 409
    ? {status: 409, recorded: null, reason: phase === 'start' ? 'task_not_leased' : 'run_closed', message: null}
    : {status: 200, recorded: false, reason: 'already_completed', message: null}, 'reviewed duplicate outcome');
}

function history(events, expected) {
  equal(events.map(event => event.event_type), expected, 'complete canonical event inventory');
  equal(events.map(event => event.sequence), events.map((_, index) => index + 1), 'canonical event positions');
  for (const event of events) {
    assert.ok(!Object.hasOwn(event.payload, 'task'), 'task claims are excluded from observations');
  }
}

function metadata(payload, size, index) {
  const group = {parallel_group_id: `parallel-children:1:${size}`, parallel_group_kind: 'child',
    parallel_group_base_sequence: 1, parallel_group_size: size, parallel_group_index: index};
  for (const field of groupFields) equal(payload[field], group[field], 'original child barrier descriptor');
  equal(payload.parallel_group_path, [group], 'original flat child barrier path');
  equal(payload.parallel_group_mode ?? 'all', 'all', 'all barrier mode');
}

export function checkParallelChildAll(fixture, observation) {
  const definition = fixture.parallel_child_all;
  const state = observation.parallel_child_all;
  const projection = {scope: 'parallel_child_all', cases: Object.keys(definition.cases).length};
  if (observation.mode === 'embedded') {
    equal(state, {applicable: false, reason: 'embedded_has_no_worker_protocol'}, 'explicit embedded boundary');
    return projection;
  }
  equal(state?.applicable, true, 'real worker-protocol observation');
  equal(Object.keys(state.cases).sort(), Object.keys(definition.cases).sort(), 'complete case inventory');
  for (const [name, size] of Object.entries(definition.cases)) {
    const value = state.cases[name];
    const success = name === 'single' || name === 'out_of_order';
    const terminal = {failed: 'ChildRunFailed', cancelled: 'ChildRunCancelled', terminated: 'ChildRunTerminated'}[name];
    const prefix = ['StartAccepted', 'WorkflowStarted', ...Array.from({length: size}, () => ['ChildWorkflowScheduled', 'ChildRunStarted']).flat()];
    const order = success && size === 3 ? [2, 0, 1] : Array.from({length: size}, (_, index) => index);
    equal(value.size, size, 'authored group size');
    equal(value.initial_recorded, true, 'atomic initial commit');
    equal(value.initial_no_parent_task, true, 'no committed-start wakeup');
    equal(value.completion_order, order, 'actual controlled completion order');
    equal(value.empty_before_last, success ? Array(size - 1).fill(true) : [], 'no early successful wakeup');
    equal(value.no_extra_parent_task, true, 'no duplicate parent resume');
    duplicate(value.duplicate_start_outcome, 'start');
    duplicate(value.duplicate_finish_outcome, 'finish');
    equal(value.duplicate_child_outcomes.length, success ? size : size - 1, 'every successful child duplicate observed');
    value.duplicate_child_outcomes.forEach(outcome => duplicate(outcome, 'child'));
    history(value.prefix, prefix);
    history(value.events, [...prefix, ...(success ? Array(size).fill('ChildRunCompleted') : [terminal]), 'WorkflowCompleted']);
    equal(value.events.slice(0, prefix.length), value.prefix, 'immutable original child start history');
    equal(value.events, value.at_parent_close, 'later abandoned siblings add no parent history');
    nonempty(value.workflow_id);
    nonempty(value.run_id);
    equal(value.prefix[1].payload.workflow_instance_id, value.workflow_id, 'original parent instance');
    equal(value.prefix[1].payload.workflow_run_id, value.run_id, 'original parent run');
    equal(value.initial_commands.length, size, 'official Replayer authors every member together');
    equal(value.children.length, size, 'complete original child inventory');
    const identities = [value.workflow_id, value.run_id];
    const outputs = [];
    for (let index = 0; index < size; index++) {
      const command = value.initial_commands[index];
      const child = value.children[index];
      const scheduled = value.prefix[2 + index * 2].payload;
      const started = value.prefix[3 + index * 2].payload;
      const argument = map({index: {type: 'int64', value: String(index)}, value: fixture.typed_value});
      const output = map({echo: argument});
      outputs.push(output);
      equal(command.type, 'start_child_workflow', 'real SDK child command');
      equal(command.workflow_type, definition.child_type, 'registered child type');
      equal(command.queue, `${value.workflow_id}-children`, 'isolated child queue');
      equal(command.typed_arguments, list([argument]), 'exact SDK child argument types');
      assert.ok(!Object.hasOwn(command, 'await'), 'deferred result barrier uses the published default');
      metadata(command, size, index);
      for (const payload of [scheduled, started]) {
        metadata(payload, size, index);
        equal(payload.sequence, index + 1, 'authored child sequence');
        equal(payload.child_workflow_class, definition.child_type, 'original child class');
        equal(payload.child_workflow_type, definition.child_type, 'original child type');
        equal(payload.parent_close_policy, 'abandon', 'default parent-close policy');
        equal(payload.cancellation_policy, 'abandon', 'default cancellation policy');
      }
      for (const key of ['workflow_link_id', 'child_call_id', 'child_workflow_instance_id', 'child_workflow_run_id']) {
        nonempty(scheduled[key]);
        equal(started[key], scheduled[key], `child start keeps ${key}`);
      }
      equal(scheduled.workflow_link_id, scheduled.child_call_id, 'original call is original link');
      equal(child.workflow_id, scheduled.child_workflow_instance_id, 'actual child instance');
      equal(child.run_id, scheduled.child_workflow_run_id, 'actual child run');
      identities.push(scheduled.child_call_id, child.workflow_id, child.run_id);
      equal(child.typed_arguments, list([argument]), 'actual claimed child argument types');
      const childSuccess = success || index !== 0;
      const childEvents = childSuccess ? ['WorkflowStarted', 'WorkflowCompleted']
        : name === 'failed' ? ['WorkflowStarted', 'WorkflowFailed']
          : name === 'cancelled' ? ['WorkflowStarted', 'CancelRequested', 'WorkflowCancelled']
            : ['WorkflowStarted', 'TerminateRequested', 'WorkflowTerminated'];
      history(child.events, childEvents);
      for (const [key, expected] of Object.entries({workflow_instance_id: child.workflow_id,
        workflow_run_id: child.run_id, parent_workflow_instance_id: value.workflow_id,
        parent_workflow_run_id: value.run_id, parent_sequence: index + 1,
        workflow_link_id: scheduled.child_call_id, child_call_id: scheduled.child_call_id})) {
        equal(child.events[0].payload[key], expected, `canonical original child ${key}`);
      }
      if (childSuccess) equal(child.events.at(-1).typed_decoded.output, output, 'canonical child output types');
    }
    equal(new Set(identities).size, identities.length, 'all original child/call/run identities are distinct');
    const resolutions = value.events.slice(prefix.length, -1);
    for (const [offset, event] of resolutions.entries()) {
      const index = success ? order[offset] : 0;
      const scheduled = value.prefix[2 + index * 2].payload;
      metadata(event.payload, size, index);
      equal(event.payload.sequence, index + 1, 'resolution retains original authored sequence');
      for (const key of ['workflow_link_id', 'child_call_id', 'child_workflow_instance_id', 'child_workflow_run_id']) {
        equal(event.payload[key], scheduled[key], `resolution retains ${key}`);
      }
      equal(event.payload.child_status, success ? 'completed' : name, 'canonical child outcome');
      if (success) {
        equal(event.typed_decoded.result, outputs[index], 'canonical typed child resolution');
        equal(event.typed_decoded.output, outputs[index], 'canonical typed child output');
      } else nonempty(event.payload.failure_id);
    }
    const result = success ? map({results: list(outputs)}) : map({
      failure_class: text('DurableWorkflow\\Exception\\ChildWorkflowFailed'), failure_type: text(terminal),
      workflow_type: text(definition.child_type), message: text(definition.failure_messages[name]),
    });
    equal(value.typed_result, result, 'cold official Replayer preserves result order or typed failure');
    equal(value.events.at(-1).typed_decoded.output, result, 'canonical committed parent output');
  }
  return projection;
}
