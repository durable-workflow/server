// Deterministic comparator examples model the published adapter differences.
// Actual workflow observations are retained separately as bounded artifacts.
export function scheduleObservation(fixture, mode) {
  const clone = value => structuredClone(value);
  const embedded = mode === 'embedded';
  const manual = fixture.schedule.phase === 'manual_lifecycle';
  const scheduleId = `schedule-reference-${manual ? 'manual' : 'rate'}`;
  const workflowId = `${mode}-scheduled-workflow`;
  const runId = `${mode}-scheduled-run`;
  const scheduleUlid = `${mode}-original-schedule`;
  const time = seconds => new Date(Date.UTC(2026, 0, 1) + seconds * 1000).toISOString();
  const next = time(manual ? 20 : 2);
  const arguments_ = {type: 'list', value: [clone(fixture.typed_value)]};
  const action = {workflow_type: fixture.workflow_type, ...clone(fixture.schedule.action_timeouts),
    ...(embedded ? {workflow_class: 'ServerParity\\EchoWorkflow', input: [clone(fixture.input)]}
      : {task_queue: 'server-parity-v1', input: {codec: 'avro', blob: 'modeled-envelope-bytes'}})};
  const operations = ['create', 'pause', 'trigger', 'resume', 'trigger', 'delete'];
  const context = (index, operation) => ({source: 'control_plane', context: {
    caller: {type: 'server', label: 'Standalone Server'},
    auth: {status: 'authorized', method: 'token', role: 'admin', roles: ['admin'],
      principal: {subject: 'legacy-token', roles: ['admin'], claims: {legacy_credential: true}, legacy_full_access: true}},
    principal: {type: 'auth:token', id: 'legacy-token', label: 'Admin'},
    request: {method: operation === 'delete' ? 'DELETE' : 'POST',
      path: operation === 'create' ? '/api/schedules' : `/api/schedules/${scheduleId}${operation === 'delete' ? '' : `/${operation}`}`,
      route_name: `route-${operation}`, ip: '127.0.0.1', user_agent: 'modeled-sdk', fingerprint: `sha256:${'0'.repeat(64)}`},
    server: {namespace: 'default', workflow_id: scheduleId, command: `schedule.${operation}`, operation_id: `${mode}-operation-${index}`},
  }});
  const audit = fixture.schedule.expected_events.map((event_type, index) => {
    const triggered = event_type === 'ScheduleTriggered';
    const deleted = event_type === 'ScheduleDeleted';
    const skipped = manual && index >= 2 ? 1 : 0;
    const status = deleted ? 'deleted' : manual && [1, 2].includes(index) ? 'paused' : 'active';
    const snapshot = {id: scheduleUlid, schedule_id: scheduleId, namespace: 'default', status, overlap_policy: 'skip',
      fires_count: deleted ? 1 : 0, skipped_trigger_count: embedded && manual && index === 3 ? 0 : skipped,
      ...(!deleted ? {next_fire_at: next} : {latest_workflow_instance_id: workflowId})};
    const payload = {schedule: snapshot,
      ...(index === 0 ? {spec: clone(fixture.schedule.spec), action: clone(action), overlap_policy: 'skip', next_fire_at: next} : {}),
      ...(event_type === 'ScheduleTriggerSkipped' ? {reason: 'status_not_triggerable', skipped_trigger_count: 1} : {}),
      ...(triggered ? {workflow_instance_id: workflowId, workflow_run_id: runId, outcome: 'scheduled',
        effective_overlap_policy: 'skip', trigger_number: 1, ...(!manual ? {occurrence_time: next} : {})} : {}),
      ...(deleted ? {reason: manual ? 'deleted' : 'max_runs_exhausted'} : {})};
    if (!embedded && (manual || index === 0)) payload.command_context = context(index, manual ? operations[index] : 'create');
    return {id: `${mode}-schedule-event-${index}`, sequence: index + 1, event_type, payload,
      workflow_instance_id: triggered ? workflowId : null, workflow_run_id: triggered ? runId : null,
      recorded_at: time(index === 0 ? 0 : deleted ? manual ? 4 : 2.1 : triggered ? 2 : 1)};
  });
  const start = {workflow_instance_id: workflowId, workflow_run_id: runId, workflow_type: fixture.workflow_type,
    workflow_command_id: `${mode}-start-command`};
  const marker = {schedule_id: scheduleId, schedule_ulid: scheduleUlid, timezone: 'UTC', overlap_policy: 'skip', trigger_number: 1,
    ...(!manual ? {occurrence_time: next} : {})};
  const events = [
    {sequence: 1, event_type: 'StartAccepted', timestamp: time(2), payload: {...start, outcome: 'started_new'}},
    {sequence: 2, event_type: 'WorkflowStarted', timestamp: time(2.01), payload: {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600,
      execution_deadline_at: time(3602), run_deadline_at: time(602)}},
    {sequence: 3, event_type: 'ScheduleTriggered', timestamp: time(2.02), payload: marker},
    {sequence: 4, event_type: 'WorkflowCompleted', timestamp: time(3), payload: {}, decoded: {output: clone(fixture.input)}, typed_decoded: {output: clone(fixture.typed_value)}},
  ];
  const recent = [{workflow_id: workflowId, run_id: runId, outcome: 'scheduled'}];
  const describe = (status, fires, skips) => ({schedule_id: scheduleId, namespace: 'default', spec: clone(fixture.schedule.spec), action: clone(action),
    status, overlap_policy: 'skip', jitter_seconds: 0, max_runs: fixture.schedule.max_runs ?? null,
    remaining_actions: fixture.schedule.max_runs ? 1 - fires : null, fires_count: fires, failures_count: 0,
    next_fire_at: status === 'deleted' ? null : next, latest_workflow_instance_id: fires ? workflowId : null,
    ...(embedded ? {id: scheduleUlid, connection: 'database', queue: 'server-parity-v1', skipped_trigger_count: skips, recent_actions: fires ? clone(recent) : []}
      : {paused: status === 'paused', state: {status}, info: {fires_count: fires, failures_count: 0, skipped_trigger_count: skips, recent_actions: fires ? clone(recent) : []}})});
  const state = {schedule_id: scheduleId, typed_action_input: clone(arguments_), created_history: clone(audit.slice(0, 1)),
    history_before_worker: clone(audit.slice(0, manual ? 5 : 3)), history_after_workflow: clone(audit.slice(0, manual ? 5 : 3)), fresh_history: clone(audit),
    trigger: {schedule_id: scheduleId, workflow_id: workflowId, run_id: runId, ...(manual ? {outcome: 'triggered'} : {})},
    fresh_workflow: {workflow_id: workflowId, run_id: runId, status: 'completed',
      ...(embedded ? {typed_input: clone(arguments_), typed_output: clone(fixture.typed_value)} : {input: [clone(fixture.input)], output: clone(fixture.input)})}};
  if (manual) Object.assign(state, {created: describe('active', 0, 0), paused: describe('paused', 0, 0),
    resumed: describe('active', 0, 1), after_workflow: describe('active', 1, 1), paused_history: clone(audit.slice(0, 3)),
    paused_trigger: {outcome: 'skipped', reason: 'status_not_triggerable', ...(!embedded ? {schedule_id: scheduleId} : {})}});
  else Object.assign(state, {later_started_at: time(3.1), later_finished_at: time(7.2), ...(embedded ? {
    tick_results: [[], [{schedule_id: scheduleId, instance_id: workflowId, run_id: runId, outcome: 'triggered', occurrence_time: next, last_fired_at: time(2.1)}]],
    later_tick_results: [[], [], []]} : {})});
  if (embedded) Object.assign(state, {fresh_schedule: describe('deleted', 1, manual ? 1 : 0), physical_schedule_rows: 1, related_run_ids: [runId]});
  else {
    const refusal = {status: 404, reason: 'schedule_not_found', response: {reason: 'schedule_not_found', message: 'modeled deleted schedule'}};
    Object.assign(state, {after_delete_describe: clone(refusal), after_delete_trigger: clone(refusal), post_close_poll: null, fresh_workflow_history: clone(events)});
    const path = `/api/schedules/${scheduleId}`;
    state.control_receipts = [{method: 'POST', path: '/api/schedules', status: 201,
      request: {schedule_id: scheduleId, spec: clone(fixture.schedule.spec), action: clone(action), overlap_policy: 'skip', jitter_seconds: 0,
        ...(fixture.schedule.max_runs ? {max_runs: fixture.schedule.max_runs} : {})}, response: {schedule_id: scheduleId, outcome: 'created'}}];
    if (manual) state.control_receipts.push(
      {method: 'POST', path: `${path}/pause`, status: 200, response: {schedule_id: scheduleId, outcome: 'paused'}},
      {method: 'POST', path: `${path}/trigger`, status: 200, response: clone(state.paused_trigger)},
      {method: 'POST', path: `${path}/resume`, status: 200, response: {schedule_id: scheduleId, outcome: 'resumed'}},
      {method: 'POST', path: `${path}/trigger`, status: 200, response: clone(state.trigger)},
      {method: 'DELETE', path, status: 200, response: {schedule_id: scheduleId, outcome: 'deleted'}});
    state.control_receipts.push({method: 'POST', path: `${path}/trigger`, status: 404, response: clone(refusal.response)});
  }
  const taskId = `${mode}-original-task`;
  return {mode, sdk_php: '2.2.6', ...(embedded ? {workflow_package: '2.5.5'} : {}), workflow_id: workflowId, run_id: runId, workflow_type: fixture.workflow_type,
    namespace: 'default', task_queue: 'server-parity-v1', status: 'completed', payload_codec: 'avro', execution: {started_at: time(2)},
    input: [clone(fixture.input)], typed_input: arguments_, output: clone(fixture.input), typed_output: clone(fixture.typed_value), events, schedule: state,
    ...(!embedded ? {workflow_polls: [{task_id: taskId, lease_owner: `${mode}-original-lease`, workflow_id: workflowId, run_id: runId,
      workflow_type: fixture.workflow_type, workflow_task_attempt: 1, history_events: clone(events.slice(0, 3))}],
    workflow_completions: [{path: `/api/worker/workflow-tasks/${taskId}/complete`, request: {lease_owner: `${mode}-original-lease`, workflow_task_attempt: 1},
      response: {task_id: taskId, run_id: runId, outcome: 'completed', run_status: 'completed', recorded: true},
      decoded_commands: [{typed_decoded: {result: clone(fixture.typed_value)}}]}]} : {})};
}
