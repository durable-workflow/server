import assert from 'node:assert/strict';

const equal = (actual, expected, label) => assert.deepStrictEqual(actual, expected, label);
const identity = (value, label) => assert.ok(typeof value === 'string' && value.length > 0, label);
const rawHistory = events => events.map(({sequence, event_type, timestamp, recorded_at, payload}) => ({sequence, event_type, timestamp: timestamp ?? recorded_at, payload}));

// Generated workflow names differ between the published remote and PHP-class
// starters. Resolve them only through the original schedule's durable receipt.
export function scheduledWorkflowIdentity(observation, scheduleId) {
  const state = observation.schedule;
  equal(state.schedule_id, scheduleId, 'original caller schedule identity');
  equal(state.trigger.schedule_id, scheduleId, 'original trigger receipt schedule');
  identity(state.trigger.workflow_id, 'original generated workflow identity');
  identity(state.trigger.run_id, 'original generated run identity');
  const triggers = state.history_before_worker.filter(event => event.event_type === 'ScheduleTriggered');
  equal(triggers.length, 1, 'one original schedule trigger before authored execution');
  const trigger = triggers[0];
  for (const payload of [trigger, trigger.payload]) {
    equal(payload.workflow_instance_id, state.trigger.workflow_id, 'durable trigger workflow relationship');
    equal(payload.workflow_run_id, state.trigger.run_id, 'durable trigger run relationship');
  }
  equal(observation.run_id, state.trigger.run_id, 'authored execution retains original triggered run');
  assert.notEqual(state.trigger.workflow_id, scheduleId, 'schedule is not substituted for generated workflow');
  return state.trigger.workflow_id;
}

function action(fixture, state, value, embedded) {
  equal(value.workflow_type, fixture.workflow_type, 'schedule registered action type');
  for (const [name, seconds] of Object.entries(fixture.schedule.action_timeouts)) equal(value[name], seconds, `schedule action ${name}`);
  if (embedded) {
    equal(value.workflow_class, 'ServerParity\\EchoWorkflow', 'normal installed PHP-class schedule starter');
    equal(value.input, [fixture.input], 'embedded decoded schedule argument list');
  } else {
    equal(value.task_queue, 'server-parity-v1', 'HTTP schedule action task queue');
    equal(value.input.codec, 'avro', 'HTTP schedule action envelope codec');
    identity(value.input.blob, 'HTTP schedule action envelope bytes');
  }
  equal(state.typed_action_input, {type: 'list', value: [fixture.typed_value]}, 'exact decoded schedule action int64 and argument types');
}

function commandContext(event, scheduleId, operation, method, path, identities) {
  const command = event.payload.command_context;
  equal(command.source, 'control_plane', 'schedule audit source');
  const context = command.context;
  equal(context.caller, {type: 'server', label: 'Standalone Server'}, 'schedule audit caller');
  equal(context.auth.status, 'authorized', 'schedule audit authorization');
  equal(context.auth.method, 'token', 'schedule audit authentication');
  equal(context.auth.role, 'admin', 'schedule audit role');
  equal(context.auth.roles, ['admin'], 'schedule audit roles');
  equal(context.auth.principal, {subject: 'legacy-token', roles: ['admin'], claims: {legacy_credential: true}, legacy_full_access: true}, 'schedule audit legacy credential principal');
  equal(context.principal, {type: 'auth:token', id: 'legacy-token', label: 'Admin'}, 'schedule audit effective principal');
  equal(context.request.method, method, 'schedule audit request method');
  equal(context.request.path, path, 'schedule audit request path');
  identity(context.request.route_name, 'schedule audit route');
  identity(context.request.ip, 'schedule audit request address');
  identity(context.request.user_agent, 'schedule audit request agent');
  assert.match(context.request.fingerprint, /^sha256:[a-f0-9]{64}$/, 'schedule audit request fingerprint');
  equal(context.server.namespace, 'default', 'schedule audit namespace');
  equal(context.server.workflow_id, scheduleId, 'schedule audit target');
  equal(context.server.command, `schedule.${operation}`, 'schedule audit operation');
  identity(context.server.operation_id, 'schedule audit operation identity');
  identities.push(context.server.operation_id);
}

function description(fixture, state, value, status, fires, skipped, embedded, instant) {
  equal(value.schedule_id, state.schedule_id, 'description original schedule');
  equal(value.namespace, 'default', 'description schedule namespace');
  equal(value.spec, fixture.schedule.spec, 'original interval specification and timezone');
  action(fixture, state, value.action, embedded);
  equal(value.status, status, 'schedule description status');
  equal(value.overlap_policy, 'skip', 'schedule description overlap policy');
  equal(value.jitter_seconds, 0, 'schedule description jitter');
  equal(value.max_runs, fixture.schedule.max_runs ?? null, 'schedule original action quota');
  equal(value.remaining_actions, fixture.schedule.max_runs === 1 ? 1 - fires : null, 'schedule remaining action quota');
  equal(value.fires_count, fires, 'schedule description successful fire count');
  equal(value.failures_count, 0, 'schedule description has no concealed failed occurrence');
  equal(embedded ? value.skipped_trigger_count ?? 0 : value.info.skipped_trigger_count, skipped, 'schedule description skipped count');
  if (status === 'deleted') equal(value.next_fire_at, null, 'deleted schedule clears next occurrence');
  else equal(instant(value.next_fire_at), instant(state.created_history[0].payload.next_fire_at), 'original next occurrence is preserved');
  if (embedded) {
    equal(value.id, state.created_history[0].payload.schedule.id, 'physical schedule original identity');
    equal(value.connection, 'database', 'embedded schedule dispatch connection');
    equal(value.queue, 'server-parity-v1', 'embedded schedule dispatch queue');
  } else {
    equal(value.paused, status === 'paused', 'HTTP schedule paused flag');
    equal(value.state.status, status, 'HTTP schedule state status');
    equal(value.info.fires_count, fires, 'HTTP schedule info fire count');
    equal(value.info.failures_count, 0, 'HTTP schedule info failure count');
  }
  if (fires) {
    equal(value.latest_workflow_instance_id, state.trigger.workflow_id, 'latest action original generated workflow');
    const recent = embedded ? value.recent_actions : value.info.recent_actions;
    equal(recent.length, 1, 'one retained action');
    equal(recent[0].workflow_id, state.trigger.workflow_id, 'recent action original workflow');
    equal(recent[0].run_id, state.trigger.run_id, 'recent action original run');
    equal(recent[0].outcome, 'scheduled', 'recent action scheduled outcome');
  }
}

function httpReceipts(fixture, state) {
  const manual = fixture.schedule.phase === 'manual_lifecycle';
  const path = `/api/schedules/${state.schedule_id}`;
  const expected = manual ? [['POST', '/api/schedules', 201, 'created'], ['POST', `${path}/pause`, 200, 'paused'],
    ['POST', `${path}/trigger`, 200, 'skipped'], ['POST', `${path}/resume`, 200, 'resumed'],
    ['POST', `${path}/trigger`, 200, 'triggered'], ['DELETE', path, 200, 'deleted'], ['POST', `${path}/trigger`, 404, null]]
    : [['POST', '/api/schedules', 201, 'created'], ['POST', `${path}/trigger`, 404, null]];
  equal(state.control_receipts.length, expected.length, 'complete HTTP schedule control inventory');
  for (const [index, [method, route, status, outcome]] of expected.entries()) {
    const receipt = state.control_receipts[index];
    equal([receipt.method, receipt.path, receipt.status], [method, route, status], 'actual schedule HTTP control receipt');
    if (outcome !== null) equal(receipt.response, outcome === 'triggered' ? state.trigger
      : outcome === 'skipped' ? state.paused_trigger : {schedule_id: state.schedule_id, outcome}, 'schedule control response');
    else equal(receipt.response, state.after_delete_trigger.response, 'actual deleted trigger response retained');
  }
  const created = state.control_receipts[0].request;
  equal(created.schedule_id, state.schedule_id, 'submitted schedule identity');
  equal(created.spec, fixture.schedule.spec, 'submitted schedule specification');
  equal(created.action, state.created_history[0].payload.action, 'submitted action retained unchanged in audit');
  equal(created.overlap_policy, 'skip', 'submitted overlap policy');
  equal(created.jitter_seconds, 0, 'submitted jitter');
  equal(created.max_runs ?? null, fixture.schedule.max_runs ?? null, 'submitted action quota');
  for (const operation of ['describe', 'trigger']) {
    equal(state[`after_delete_${operation}`].status, 404, 'deleted schedule HTTP visibility');
    equal(state[`after_delete_${operation}`].reason, 'schedule_not_found', 'deleted schedule HTTP reason');
    equal(state[`after_delete_${operation}`].response.reason, 'schedule_not_found', 'deleted schedule raw error');
  }
  equal(state.post_close_poll, null, 'no scheduled task admitted after closure');
}

export function checkSchedule(fixture, observation, identities, projectedEvents, instant) {
  const state = observation.schedule;
  const manual = fixture.schedule.phase === 'manual_lifecycle';
  const embedded = observation.mode === 'embedded';
  const history = state.fresh_history;
  const created = history[0];
  const trigger = history.find(event => event.event_type === 'ScheduleTriggered');
  const deleted = history.at(-1);
  const scheduleUlid = created.payload.schedule.id;
  identity(scheduleUlid, 'original schedule durable identity');
  identities.push(state.schedule_id, scheduleUlid);
  equal(history.map(event => event.event_type), fixture.schedule.expected_events, 'complete schedule audit inventory');
  equal(history.map(event => event.sequence), history.map((_, index) => index + 1), 'schedule audit order');
  equal(state.created_history, history.slice(0, 1), 'original committed creation audit');
  equal(state.history_before_worker, history.slice(0, manual ? 5 : 3), 'original trigger before worker history');
  equal(state.history_after_workflow, state.history_before_worker, 'authored completion does not replace schedule audit');
  equal(created.payload.spec, fixture.schedule.spec, 'durable schedule specification');
  equal(created.payload.overlap_policy, 'skip', 'durable schedule overlap policy');
  action(fixture, state, created.payload.action, embedded);
  let previous = -1n;
  const statuses = manual ? ['active', 'paused', 'paused', 'active', 'active', 'deleted'] : ['active', 'active', 'deleted'];
  const skips = manual ? [0, 0, 1, 1, 1, 1] : [0, 0, 0];
  const operations = ['create', 'pause', 'trigger', 'resume', 'trigger', 'delete'];
  for (const [index, event] of history.entries()) {
    identity(event.id, 'schedule audit event identity');
    identities.push(event.id);
    const time = instant(event.recorded_at);
    assert.ok(time >= previous, 'schedule audit timestamps ordered');
    previous = time;
    const snapshot = event.payload.schedule;
    equal([snapshot.id, snapshot.schedule_id, snapshot.namespace], [scheduleUlid, state.schedule_id, 'default'], 'every audit preserves original schedule');
    equal(snapshot.status, statuses[index], 'schedule audit status transition');
    equal(snapshot.overlap_policy, 'skip', 'schedule audit overlap policy');
    equal(snapshot.fires_count, index === history.length - 1 ? 1 : 0, 'audit preserves trigger admission before successful fire accounting');
    // The installed embedded resume uses the caller's stale model for its
    // audit snapshot. Its fresh description and next trigger retain the skip.
    equal(snapshot.skipped_trigger_count, embedded && manual && index === 3
      ? fixture.schedule.embedded_resumed_audit_skipped_trigger_count : skips[index], 'schedule audit skipped accounting');
    if (index !== history.length - 1) equal(instant(snapshot.next_fire_at), instant(created.payload.next_fire_at), 'audit retains original scheduled occurrence');
    else {
      equal(snapshot.next_fire_at ?? null, null, 'deleted audit has no next occurrence');
      equal(snapshot.latest_workflow_instance_id, observation.workflow_id, 'deleted audit retains original generated workflow');
    }
    if (event.event_type !== 'ScheduleTriggered') equal([event.workflow_instance_id, event.workflow_run_id], [null, null], 'non-trigger audit has no generated execution');
    if (!embedded && (manual || index === 0)) {
      const operation = manual ? operations[index] : 'create';
      const method = operation === 'delete' ? 'DELETE' : 'POST';
      const path = operation === 'create' ? '/api/schedules' : `/api/schedules/${state.schedule_id}${operation === 'delete' ? '' : `/${operation}`}`;
      commandContext(event, state.schedule_id, operation, method, path, identities);
    } else equal(event.payload.command_context ?? null, null, 'ordinary embedded or scheduler audit has no HTTP command context');
  }
  equal(trigger.payload.outcome, 'scheduled', 'durable schedule fire outcome');
  equal(trigger.payload.effective_overlap_policy, 'skip', 'effective schedule overlap policy');
  equal(trigger.payload.trigger_number, 1, 'original first schedule trigger');
  equal([trigger.workflow_instance_id, trigger.workflow_run_id], [observation.workflow_id, observation.run_id], 'fresh schedule trigger original execution');
  equal([trigger.payload.workflow_instance_id, trigger.payload.workflow_run_id], [observation.workflow_id, observation.run_id], 'fresh trigger payload original execution');
  const rootTrigger = observation.events[2];
  equal(rootTrigger.payload, {schedule_id: state.schedule_id, schedule_ulid: scheduleUlid, timezone: 'UTC', overlap_policy: 'skip', trigger_number: 1,
    ...(!manual ? {occurrence_time: trigger.payload.occurrence_time} : {})}, 'workflow schedule marker retains original schedule and occurrence');
  Object.assign(projectedEvents[2], {schedule_id: state.schedule_id, schedule_ulid: '@schedule:1', trigger_number: 1, timezone: 'UTC', overlap_policy: 'skip',
    ...(!manual ? {occurrence: '@original-occurrence:1'} : {})});
  equal(deleted.payload.reason, manual ? 'deleted' : 'max_runs_exhausted', 'schedule deletion reason');
  if (manual) {
    equal(state.paused_history, history.slice(0, 3), 'paused trigger adds only a durable skip');
    equal(state.paused_trigger.outcome, 'skipped', 'paused manual trigger is skipped');
    equal(state.paused_trigger.reason, 'status_not_triggerable', 'paused manual trigger reason');
    equal(state.paused_trigger.workflow_id ?? null, null, 'paused trigger starts no workflow');
    equal(state.paused_trigger.run_id ?? null, null, 'paused trigger starts no run');
    equal(history[2].payload.reason, 'status_not_triggerable', 'durable paused skip reason');
    equal(history[2].payload.skipped_trigger_count, 1, 'one durable paused skip');
    equal(state.trigger.outcome, 'triggered', 'resumed manual trigger receipt');
    for (const [name, status, fires, skipped] of [['created', 'active', 0, 0], ['paused', 'paused', 0, 0], ['resumed', 'active', 0, 1], ['after_workflow', 'active', 1, 1]])
      description(fixture, state, state[name], status, fires, skipped, embedded, instant);
    assert.ok(instant(created.payload.next_fire_at) > instant(observation.events.at(-1).timestamp), 'manual schedule original automatic occurrence remains outside execution window');
  } else {
    const occurrence = instant(created.payload.next_fire_at);
    equal(instant(trigger.payload.occurrence_time), occurrence, 'normal scheduler fires original committed occurrence');
    assert.ok(instant(rootTrigger.timestamp) >= occurrence, 'original occurrence does not fire early at submillisecond precision');
    assert.ok(instant(observation.events[0].timestamp) >= occurrence, 'original occurrence does not start a workflow early');
    assert.ok(instant(state.later_finished_at) - instant(state.later_started_at) >= 4_000_000_000n, 'two actual later interval boundaries observed');
    assert.ok(instant(state.later_started_at) >= instant(observation.events.at(-1).timestamp), 'later observation follows authored completion');
    if (embedded) {
      const fires = state.tick_results.flat();
      equal(fires.length, 1, 'ordinary installed ticks report one original scheduled fire');
      const fire = fires[0];
      equal([fire.schedule_id, fire.instance_id, fire.run_id, fire.outcome], [state.schedule_id, observation.workflow_id, observation.run_id, 'triggered'], 'ordinary installed tick reports original execution');
      equal(instant(fire.occurrence_time), occurrence, 'installed tick retains original occurrence');
      assert.ok(instant(fire.last_fired_at) >= occurrence, 'installed tick does not report an early fire');
      assert.ok(state.later_tick_results.length > 1, 'normal scheduler keeps evaluating later intervals');
      equal(state.later_tick_results.flat(), [], 'later installed ticks fire no schedules');
    }
  }
  equal(state.fresh_workflow.workflow_id, observation.workflow_id, 'fresh original generated workflow');
  equal(state.fresh_workflow.run_id, observation.run_id, 'fresh original generated run');
  equal(state.fresh_workflow.status, 'completed', 'fresh authored schedule completion');
  if (embedded) {
    description(fixture, state, state.fresh_schedule, 'deleted', 1, manual ? 1 : 0, true, instant);
    equal(state.physical_schedule_rows, 1, 'one original schedule tombstone retained');
    equal(state.related_run_ids, [observation.run_id], 'exactly one physical schedule-generated execution');
    equal(state.fresh_workflow.typed_input, observation.typed_input, 'fresh schedule run exact arguments');
    equal(state.fresh_workflow.typed_output, observation.typed_output, 'fresh schedule run exact result');
  } else {
    httpReceipts(fixture, state);
    equal(state.fresh_workflow.input, observation.input, 'fresh HTTP scheduled arguments');
    equal(state.fresh_workflow.output, observation.output, 'fresh HTTP scheduled result');
    equal(rawHistory(state.fresh_workflow_history), rawHistory(observation.events), 'fresh original complete workflow history');
    equal(observation.workflow_polls.length, 1, 'one actual authored schedule task');
    const task = observation.workflow_polls[0];
    identity(task.task_id, 'actual scheduled task identity');
    identities.push(task.task_id);
    equal([task.workflow_id, task.run_id, task.workflow_type, task.workflow_task_attempt], [observation.workflow_id, observation.run_id, fixture.workflow_type, 1], 'actual scheduled task original execution');
    equal(rawHistory(task.history_events), rawHistory(observation.events.slice(0, 3)), 'worker sees original durable schedule marker');
    equal(observation.workflow_completions.length, 1, 'one actual authored schedule completion');
    const completion = observation.workflow_completions[0];
    equal(completion.path, `/api/worker/workflow-tasks/${task.task_id}/complete`, 'scheduled completion original task path');
    equal(completion.request.lease_owner, task.lease_owner, 'scheduled completion original lease');
    equal(completion.request.workflow_task_attempt, 1, 'scheduled completion original attempt');
    equal([completion.response.task_id, completion.response.run_id, completion.response.outcome, completion.response.run_status, completion.response.recorded], [task.task_id, observation.run_id, 'completed', 'completed', true], 'actual scheduled completion acknowledgment');
    equal(completion.decoded_commands[0].typed_decoded.result, fixture.typed_value, 'actual authored schedule result int64');
  }
  equal(new Set(identities).size, identities.length, 'distinct original schedule, audit, operation and execution identities');
  return {schedule_id: state.schedule_id, schedule_ulid: '@schedule:1', phase: fixture.schedule.phase, spec: fixture.schedule.spec,
    action: {workflow_type: fixture.workflow_type, input: state.typed_action_input, ...fixture.schedule.action_timeouts},
    overlap_policy: 'skip', jitter_seconds: 0, max_runs: fixture.schedule.max_runs ?? null,
    workflow_id: '@scheduled-workflow:1', run_id: '@run:1', trigger_number: 1, fires_count: 1, failures_count: 0,
    skipped_trigger_count: manual ? 1 : 0, deleted_reason: deleted.payload.reason,
    audit: history.map(event => ({sequence: event.sequence, event_type: event.event_type})),
    ...(!manual ? {original_occurrence_retained: true, later_intervals: 2, remaining_actions: 0} : {})};
}
