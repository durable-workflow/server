// Deterministic comparator examples model the published representations observed
// by the shared runner. Real per-run observations belong in bounded artifacts.
export function childCancellationObservation(fixture, mode) {
  const clone = value => structuredClone(value);
  const embedded = mode === 'embedded';
  const timer = fixture.child_cancellation.phase === 'pending_timer';
  const workflow = `child-reference-${timer ? 'pending-timer' : 'before-claim'}`;
  const parent = 'original-parent-run';
  const child = 'original-child-instance';
  const run = 'original-child-run';
  const call = 'original-child-call';
  const cancel = 'original-cancel-command';
  const failure = 'original-child-failure';
  const reason = fixture.child_cancellation.reason;
  const message = `Workflow cancelled: ${reason}`;
  const time = seconds => new Date(Date.UTC(2026, 0, 1, 0, 0, seconds)).toISOString();
  const event = (event_type, sequence, seconds, payload, decoded = {}, typed_decoded = {}) =>
    ({event_type, sequence, timestamp: time(seconds), payload: clone(payload), decoded: clone(decoded), typed_decoded: clone(typed_decoded)});
  const start = {workflow_instance_id: workflow, workflow_run_id: parent,
    workflow_command_id: 'original-parent-start-command', workflow_type: fixture.workflow_type};
  const relationship = {sequence: 1, workflow_link_id: call, child_call_id: call,
    child_workflow_instance_id: child, child_workflow_run_id: run,
    child_workflow_type: fixture.child_workflow_type};
  const cancellation = {workflow_instance_id: child, workflow_run_id: run,
    workflow_command_id: cancel, reason};
  const terminal = {...cancellation, failure_id: failure, failure_category: 'cancelled', closed_reason: 'cancelled',
    exception_class: 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', message};
  const resolution = {...relationship, child_run_number: 1, child_status: 'cancelled', closed_at: time(5),
    failure_id: failure, failure_category: 'cancelled', closed_reason: 'cancelled',
    exception_class: terminal.exception_class, message};
  const events = [
    event('StartAccepted', 1, 0, {...start, outcome: 'started_new'}),
    event('WorkflowStarted', 2, 0, {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600,
      execution_deadline_at: time(3600), run_deadline_at: time(600)}),
    event('ChildWorkflowScheduled', 3, 1, relationship),
    event('ChildRunStarted', 4, 1, {...relationship, child_run_number: 1}),
    event('ChildRunCancelled', 5, 5, resolution),
  ];
  if (embedded) events.push(event('FailureHandled', 6, 6, {failure_id: failure, sequence: 1,
    failure_category: 'cancelled', source_kind: 'child_workflow_run', source_id: run,
    propagation_kind: 'child', exception_class: terminal.exception_class, message, handled: true}));
  events.push(event('WorkflowCompleted', events.length + 1, 7, {},
    {output: fixture.output}, {output: fixture.typed_output}));
  const childEvents = [];
  const add = (type, seconds, payload) => childEvents.push(event(type, childEvents.length + 1, seconds, payload));
  if (embedded) add('StartAccepted', 0, {workflow_instance_id: child, workflow_run_id: run, workflow_command_id: 'original-child-start-command'});
  add('WorkflowStarted', 1, {workflow_instance_id: child, workflow_run_id: run, workflow_type: fixture.child_workflow_type,
    parent_workflow_instance_id: workflow, parent_workflow_run_id: parent, parent_sequence: 1, workflow_link_id: call, child_call_id: call});
  const scheduled = {timer_id: 'original-child-timer', sequence: 1, delay_seconds: 60, fire_at: time(62)};
  if (timer) add('TimerScheduled', 2, scheduled);
  const beforeCount = childEvents.length;
  add('CancelRequested', 3, {...cancellation, command_type: 'cancel'});
  if (timer) add('TimerCancelled', 4, {...scheduled, cancelled_at: time(4)});
  add('WorkflowCancelled', 5, terminal);
  const rawHistory = rows => rows.map(({sequence, timestamp, event_type, payload}) => ({sequence, timestamp, event_type, payload: clone(payload)}));
  const childHistory = rawHistory(childEvents);
  const parentHistory = rawHistory(events);
  const receipt = (accepted, command) => ({status: accepted ? 200 : 409, response: {
    workflow_id: child, run_id: run, requested_run_id: embedded ? run : null, resolved_run_id: run,
    command_id: command, target_scope: embedded ? 'run' : 'instance', workflow_type: fixture.child_workflow_type,
    command_status: accepted ? 'accepted' : 'rejected', outcome: accepted ? 'cancelled' : 'rejected_not_active',
    reason: accepted ? reason : 'run_not_active', rejection_reason: accepted ? null : 'run_not_active',
  }});
  const physical = {id: failure, workflow_run_id: run, source_kind: 'workflow_run', source_id: run,
    propagation_kind: 'cancelled', failure_category: 'cancelled', exception_class: terminal.exception_class,
    message, handled: false, non_retryable: false};
  const state = {
    workflow_id: child, run_id: run, child_before: {status: timer ? 'waiting' : 'pending'},
    child_history_before: clone(childHistory.slice(0, beforeCount)), parent_history_before: clone(parentHistory.slice(0, 4)),
    accepted: receipt(true, cancel), duplicate: receipt(false, 'duplicate-cancel-command'),
    terminal_duplicate: receipt(false, 'post-parent-cancel-command'),
    child_history_before_duplicate: clone(childHistory), child_history_after_duplicate: clone(childHistory),
    parent_history_before_duplicate: clone(parentHistory.slice(0, 5)), parent_history_after_duplicate: clone(parentHistory.slice(0, 5)),
    child_history_before_terminal_duplicate: clone(childHistory), child_history_after_terminal_duplicate: clone(childHistory),
    parent_history_before_terminal_duplicate: clone(parentHistory), parent_history_after_terminal_duplicate: clone(parentHistory),
    fresh_child: {workflow_id: child, run_id: run, status: 'cancelled'}, fresh_parent: {workflow_id: workflow, run_id: parent, status: 'completed'},
    fresh_child_history: clone(childHistory), fresh_parent_history: clone(parentHistory),
    caught: embedded ? {class: terminal.exception_class, message: `Child workflow ${run} closed as cancelled: ${reason}`}
      : {class: 'DurableWorkflow\\Exception\\ChildWorkflowFailed', message, failure_type: 'ChildRunCancelled', workflow_type: fixture.child_workflow_type, payload: clone(resolution)},
  };
  if (embedded) Object.assign(state, {failures_before_duplicate: [clone(physical)], failures_after_duplicate: [clone(physical)], fresh_failures: [clone(physical)]});
  const polls = [{workflow_id: workflow, run_id: parent, workflow_type: fixture.workflow_type}];
  if (timer) polls.push({workflow_id: child, run_id: run, workflow_type: fixture.child_workflow_type});
  polls.push({workflow_id: workflow, run_id: parent, workflow_type: fixture.workflow_type, child_call_id: call,
    child_workflow_run_id: run, workflow_event_type: 'ChildRunCancelled', resume_source_kind: 'child_workflow_run', resume_source_id: run});
  return {mode, workflow_id: workflow, run_id: parent, workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: 'server-parity-v1', status: 'completed', payload_codec: 'avro',
    execution: {started_at: time(0)}, input: [clone(fixture.input)], typed_input: {type: 'list', value: [clone(fixture.typed_value)]},
    output: clone(fixture.output), typed_output: clone(fixture.typed_output), events, child_cancellation: state,
    workflow_polls: polls, children: [{workflow_id: child, run_id: run, workflow_type: fixture.child_workflow_type,
      namespace: 'default', task_queue: 'server-parity-v1', status: 'cancelled', payload_codec: 'avro',
      input: [clone(fixture.input)], typed_input: {type: 'list', value: [clone(fixture.typed_value)]}, output: null,
      typed_output: {type: 'null', value: null}, events: childEvents}]};
}
