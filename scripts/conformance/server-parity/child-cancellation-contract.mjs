import assert from 'node:assert/strict';

const equal = (actual, expected, message) => assert.deepStrictEqual(actual, expected, message);
const nonempty = (value, message) => assert.ok(typeof value === 'string' && value.length > 0, message);

export function checkChildCancellation(fixture, observation, identities, projectedEvents) {
  const embedded = observation.mode === 'embedded';
  const {phase, reason} = fixture.child_cancellation;
  equal(observation.children.length, 1, 'one original cancelled child');
  const child = observation.children[0];
  const state = observation.child_cancellation;
  const scheduled = observation.events.find(event => event.event_type === 'ChildWorkflowScheduled');
  const started = observation.events.find(event => event.event_type === 'ChildRunStarted');
  const resolution = observation.events.find(event => event.event_type === 'ChildRunCancelled');
  const call = scheduled.payload.child_call_id;
  const link = scheduled.payload.workflow_link_id;
  nonempty(call, 'original child call');
  equal(link, call, 'canonical child link aliases its original call');
  identities.push(call, child.workflow_id, child.run_id);
  equal(new Set(identities).size, identities.length, 'distinct parent/call/child identities');
  for (const event of [scheduled, started, resolution]) {
    equal(event.payload.sequence, 1, 'original child command sequence');
    equal(event.payload.child_call_id, call, 'same child call throughout lifecycle');
    equal(event.payload.workflow_link_id, link, 'same child link throughout lifecycle');
    equal(event.payload.child_workflow_instance_id, child.workflow_id, 'same child instance throughout lifecycle');
    equal(event.payload.child_workflow_run_id, child.run_id, 'same original child run throughout lifecycle');
    equal(event.payload.child_workflow_type, fixture.child_workflow_type, 'original registered child type');
  }
  equal(started.payload.child_run_number, 1, 'no replacement child run');
  equal(resolution.payload.child_run_number, 1, 'original child run resolved');
  equal(resolution.payload.child_status, 'cancelled', 'parent observes child cancellation');
  equal(child.workflow_type, fixture.child_workflow_type, 'described original child type');
  equal(child.namespace, observation.namespace, 'child namespace');
  equal(child.task_queue, observation.task_queue, 'child queue');
  equal(child.payload_codec, 'avro', 'child payload codec');
  equal(child.status, 'cancelled', 'child durable terminal state');
  equal(child.input, [fixture.input], 'original decoded child input');
  equal(child.typed_input, {type: 'list', value: [fixture.typed_value]}, 'original child exact int64 input');
  equal(child.output, null, 'cancelled child has no successful output');
  equal(child.typed_output, {type: 'null', value: null}, 'cancelled child output type');
  equal(child.events.map(event => event.event_type), embedded ? fixture.embedded_child_expected_events : fixture.child_expected_events, 'complete cancelled child history');
  equal(child.events.map(event => event.sequence), child.events.map((_, i) => i + 1), 'original child history sequence');
  let previous = -Infinity;
  for (const event of child.events) {
    const time = Date.parse(event.timestamp);
    assert.ok(Number.isFinite(time) && time >= previous, 'ordered child timestamps');
    previous = time;
  }
  const childStarted = child.events.find(event => event.event_type === 'WorkflowStarted').payload;
  for (const [field, value] of Object.entries({workflow_instance_id: child.workflow_id, workflow_run_id: child.run_id,
    workflow_type: fixture.child_workflow_type, parent_workflow_instance_id: observation.workflow_id,
    parent_workflow_run_id: observation.run_id, parent_sequence: 1, workflow_link_id: link, child_call_id: call})) {
    equal(childStarted[field], value, `child startup retains ${field}`);
  }
  const requested = child.events.find(event => event.event_type === 'CancelRequested').payload;
  const terminal = child.events.at(-1).payload;
  const command = requested.workflow_command_id;
  const failure = terminal.failure_id;
  nonempty(command, 'original child cancellation command');
  nonempty(failure, 'original child cancellation failure');
  identities.push(command, failure);
  equal(new Set(identities).size, identities.length, 'distinct cancellation command and failure');
  const diagnostic = `Workflow cancelled: ${reason}`;
  for (const payload of [requested, terminal]) {
    equal(payload.workflow_instance_id, child.workflow_id, 'cancellation original child instance');
    equal(payload.workflow_run_id, child.run_id, 'cancellation original child run');
    equal(payload.workflow_command_id, command, 'cancellation original command');
    equal(payload.reason, reason, 'caller child cancellation reason');
  }
  equal(requested.command_type, 'cancel', 'direct cancellation command');
  for (const payload of [terminal, resolution.payload]) {
    equal(payload.failure_id, failure, 'parent resolution retains original child failure');
    equal(payload.failure_category, 'cancelled', 'child cancellation category');
    equal(payload.closed_reason, 'cancelled', 'child cancellation closed reason');
    equal(payload.exception_class, 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', 'child cancellation exception identity');
    equal(payload.message, diagnostic, 'complete original child diagnostic');
  }
  equal(state.workflow_id, child.workflow_id, 'cancel targets original child instance');
  equal(state.run_id, child.run_id, 'cancel targets original child run');
  equal(state.child_before.status, phase === 'before_claim' ? 'pending' : 'waiting', 'child is at declared cancellation phase');
  equal(state.child_history_before.map(event => event.event_type), child.events.slice(0, phase === 'pending_timer' ? (embedded ? 3 : 2) : (embedded ? 2 : 1)).map(event => event.event_type), 'cancel occurs at declared child phase');
  equal(state.parent_history_before.map(event => event.event_type), ['StartAccepted', 'WorkflowStarted', 'ChildWorkflowScheduled', 'ChildRunStarted'], 'parent waiting on its original child');
  equal(state.accepted.status, 200, 'direct child cancellation accepted');
  for (const [kind, receipt] of [['accepted', state.accepted], ['duplicate', state.duplicate], ['terminal_duplicate', state.terminal_duplicate]]) {
    const response = receipt.response;
    equal(receipt.status, kind === 'accepted' ? 200 : 409, 'closed child rejects replacement cancellation');
    equal(response.workflow_id, child.workflow_id, 'receipt original child instance');
    equal(response.run_id, child.run_id, 'receipt original child run');
    equal(response.resolved_run_id, child.run_id, 'receipt resolved child run');
    equal(response.requested_run_id, embedded ? child.run_id : null, 'declared adapter requested run scope');
    equal(response.target_scope, fixture.child_cancellation.receipt_target_scope[embedded ? 'embedded' : 'http'], 'declared adapter cancellation scope');
    equal(response.command_status, kind === 'accepted' ? 'accepted' : 'rejected', 'immutable cancellation receipt');
    equal(response.outcome, kind === 'accepted' ? 'cancelled' : 'rejected_not_active', 'canonical cancellation outcome');
    if (kind === 'accepted') equal(response.command_id, command, 'accepted original command identity');
    else equal(response.rejection_reason, 'run_not_active', 'closed child refusal reason');
  }
  for (const target of ['child', 'parent']) {
    equal(state[`${target}_history_after_duplicate`], state[`${target}_history_before_duplicate`], 'duplicate preserves original parent and child histories');
    equal(state[`${target}_history_after_terminal_duplicate`], state[`${target}_history_before_terminal_duplicate`], 'post-parent duplicate preserves committed histories');
    equal(state[`fresh_${target}_history`], state[`${target}_history_after_terminal_duplicate`], 'fresh read retains original history');
  }
  for (const [name, id, run, status] of [['child', child.workflow_id, child.run_id, 'cancelled'], ['parent', observation.workflow_id, observation.run_id, 'completed']]) {
    const fresh = state[`fresh_${name}`];
    equal(fresh.workflow_id ?? fresh.workflow_instance_id, id, 'fresh original instance');
    equal(fresh.run_id ?? fresh.id, run, 'fresh original run');
    equal(fresh.status, status, 'fresh durable outcome');
  }
  if (phase === 'pending_timer') {
    const timer = child.events.find(event => event.event_type === 'TimerScheduled');
    const cancelled = child.events.find(event => event.event_type === 'TimerCancelled');
    nonempty(timer.payload.timer_id, 'original child timer');
    for (const field of ['timer_id', 'sequence', 'delay_seconds', 'fire_at']) equal(cancelled.payload[field], timer.payload[field], 'cancelled original child timer');
    equal(timer.payload.sequence, 1, 'original child sleep command');
    equal(timer.payload.delay_seconds, fixture.child_cancellation.original_delay_seconds, 'original child timer budget');
    assert.ok(Date.parse(cancelled.payload.cancelled_at) < Date.parse(timer.payload.fire_at), 'direct cancellation closes the pending timer before fire');
  }
  const caught = state.caught;
  if (embedded) {
    equal(caught.class, 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', 'embedded authored parent catches actual cancellation error');
    equal(caught.message, `Child workflow ${child.run_id} closed as cancelled: ${reason}`, 'embedded parent diagnostic names original child run');
    const handled = observation.events.find(event => event.event_type === 'FailureHandled').payload;
    for (const [key, value] of Object.entries({failure_id: failure, source_id: child.run_id, source_kind: 'child_workflow_run',
      propagation_kind: 'child', failure_category: 'cancelled', handled: true, message: diagnostic})) equal(handled[key], value, 'embedded catches the original child failure');
    equal(state.failures_after_duplicate, state.failures_before_duplicate, 'duplicate preserves physical child failure');
    equal(state.fresh_failures, state.failures_before_duplicate, 'fresh physical child failure is unchanged');
    equal(state.fresh_failures.length, 1, 'one physical child cancellation failure');
    const physical = state.fresh_failures[0];
    for (const [key, value] of Object.entries({id: failure, workflow_run_id: child.run_id, source_kind: 'workflow_run',
      source_id: child.run_id, propagation_kind: 'cancelled', failure_category: 'cancelled', message: diagnostic})) equal(physical[key], value, 'physical original cancellation failure');
  } else {
    const parentPolls = observation.workflow_polls.filter(poll => poll.run_id === observation.run_id);
    const childPolls = observation.workflow_polls.filter(poll => poll.run_id === child.run_id);
    equal(parentPolls.length, 2, 'one parent claim and one original-child resumption');
    equal(childPolls.length, phase === 'before_claim' ? 0 : 1, 'declared child cancellation claim phase');
    equal(observation.workflow_polls.length, parentPolls.length + childPolls.length, 'no unrelated claims substitute for original work');
    const resumed = parentPolls[1];
    for (const [key, value] of Object.entries({child_call_id: call, child_workflow_run_id: child.run_id,
      workflow_event_type: 'ChildRunCancelled', resume_source_kind: 'child_workflow_run', resume_source_id: child.run_id})) equal(resumed[key], value, 'SDK polls original cancelled-child resumption');
    equal(caught.class, fixture.child_cancellation.http_parent_exception.class, 'published SDK child cancellation exception');
    equal(caught.failure_type, fixture.child_cancellation.http_parent_exception.failure_type, 'SDK cancellation failure type');
    equal(caught.workflow_type, fixture.child_workflow_type, 'SDK original child type');
    equal(caught.message, diagnostic, 'SDK original child failure diagnostic');
    equal(caught.payload, resolution.payload, 'SDK carries complete original child resolution');
  }
  // Validate the embedded-only handled record before projecting common events.
  const events = projectedEvents.filter(event => event.event_type !== 'FailureHandled').map((event, i) => ({...event, sequence: i + 1}));
  for (const event of events.filter(event => event.event_type.startsWith('Child'))) {
    Object.assign(event, {child_call_id: '@child-call:1', child_workflow_id: '@child:1', child_run_id: '@child-run:1'});
  }
  Object.assign(events.find(event => event.event_type === 'ChildRunCancelled'), {failure_id: '@child-failure:1', message: diagnostic});
  return {events, child: {workflow_id: '@child:1', run_id: '@child-run:1', status: 'cancelled',
    workflow_type: fixture.child_workflow_type, input: child.typed_input, output: child.typed_output,
    events: child.events.filter(event => event.event_type !== 'StartAccepted').map((event, i) => ({sequence: i + 1, event_type: event.event_type})),
    failure_id: '@child-failure:1', message: diagnostic, parent_caught_cancellation: true}};
}
