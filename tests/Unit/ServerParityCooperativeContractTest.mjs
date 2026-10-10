import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from '../../scripts/conformance/server-parity/contract.mjs';

const fixtures = ['cooperative-before-claim-cleanup', 'cooperative-pending-timer-cleanup', 'cooperative-cleanup-deadline'].map(name =>
  JSON.parse(readFileSync(new URL(`../Fixtures/ServerParityPending/${name}.json`, import.meta.url))));
const at = seconds => new Date(Date.UTC(2026, 0, 1) + seconds * 1000).toISOString().replace('Z', '123Z');
const typed = value => value === null ? {type: 'null', value: null} : Array.isArray(value)
  ? {type: 'list', value: value.map(typed)} : typeof value === 'object'
    ? {type: 'map', value: Object.fromEntries(Object.entries(value).map(([key, item]) => [key, typed(item)]))}
    : {type: typeof value === 'number' ? 'int64' : typeof value, value: typeof value === 'number' ? String(value) : value};

// A compact contract model for corruption tests, never execution evidence.
// Real SDK/queue executions are recorded separately by the differential suite.
function observation(fixture, mode) {
  const {phase, reason, cleanup_timeout_seconds: budget, cleanup_outcome: outcome} = fixture.cooperative_cancellation;
  const context = {schema: 'durable-workflow.cancellation-context/v1', request_id: 'root-request', root_request_id: 'root-request',
    root_workflow_instance_id: 'test-cooperative', root_workflow_run_id: 'run', parent_request_id: null, reason,
    requester: fixture.requester[mode], source: mode === 'http' ? 'control_plane' : 'php', requested_at: at(2),
    cleanup_deadline_at: at(2 + budget), lineage: [{request_id: 'root-request', workflow_instance_id: 'test-cooperative', workflow_run_id: 'run'}]};
  const raw = {mode, workflow_id: 'test-cooperative', run_id: 'run', workflow_type: fixture.workflow_type, namespace: 'default',
    task_queue: 'server-parity-v1', status: 'cancelled', payload_codec: 'avro', input: [fixture.input],
    typed_input: {type: 'list', value: [fixture.typed_value]}, output: null, typed_output: typed(null),
    execution: {started_at: at(0), closed_reason: 'cancelled', closed_at: at(outcome === 'completed' ? 5 : 7.1)}, events: []};
  const add = (type, seconds, payload, decoded = {}, typedDecoded = {}) => {
    raw.events.push({sequence: raw.events.length + 1, event_type: type, timestamp: at(seconds), payload, decoded, typed_decoded: typedDecoded});
  };
  const start = {workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, workflow_type: fixture.workflow_type, workflow_command_id: 'start-command'};
  add('StartAccepted', 0, {...start, outcome: 'started_new'});
  add('WorkflowStarted', 0.1, {...start, execution_timeout_seconds: 3600, run_timeout_seconds: 600,
    execution_deadline_at: at(3600), run_deadline_at: at(600)});
  const originalTimer = {timer_id: 'original-timer', sequence: 1, delay_seconds: 60, fire_at: at(61), task: {id: 'original-task'}};
  if (phase === 'pending_timer') add('TimerScheduled', 1, originalTimer);
  const requested = {workflow_command_id: context.request_id, workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id,
    command_type: 'request_cancellation', reason, cleanup_deadline_at: context.cleanup_deadline_at, cancellation: context,
    command: {id: context.request_id, sequence: 2, type: 'request_cancellation', target_scope: 'run', resolved_run_id: raw.run_id,
      status: 'accepted', outcome: 'cancellation_requested', accepted_at: context.requested_at, applied_at: context.requested_at}};
  add('CooperativeCancellationRequested', 2, requested, {cancellation_command: {reason, cleanup_deadline_at: context.cleanup_deadline_at, cancellation: context}});
  if (phase === 'pending_timer') add('TimerCancelled', 2.1, {...originalTimer, cancelled_at: at(2.1)});
  const deliveryTask = {id: 'delivery-task', lease_owner: 'worker', attempt_count: 1, lease_expires_at: at(mode === 'http' ? 6 : 62)};
  add('CooperativeCancellationDelivered', 2.2, {workflow_command_id: context.request_id, workflow_run_id: raw.run_id,
    sequence: 1, call_kind: 'timer', cancellation: context, task: deliveryTask});
  const cleanupTimer = {timer_id: 'cleanup-timer', sequence: 2, delay_seconds: fixture.input.cleanup_delay_seconds,
    fire_at: at(3 + fixture.input.cleanup_delay_seconds)};
  add('TimerScheduled', 3, cleanupTimer);
  if (outcome === 'completed') {
    add('TimerFired', 4, {...cleanupTimer, fired_at: cleanupTimer.fire_at});
    const payload = {activity_execution_id: 'cleanup-activity', activity_type: 'parity.v1.cooperative_cleanup_activity', sequence: 3};
    const decoded = {activity_arguments: [fixture.input, context]};
    const typedDecoded = {activity_arguments: {type: 'list', value: [fixture.typed_value, typed(context)]}};
    add('ActivityScheduled', 4.1, payload, decoded, typedDecoded);
    const started = {...payload, activity_attempt_id: 'cleanup-attempt', attempt_number: 1, task: {id: 'activity-task'}};
    add('ActivityStarted', 4.2, started, decoded, typedDecoded);
    add('ActivityHeartbeatRecorded', 4.3, {...started, progress: {details: {request_id: context.request_id}}}, decoded, typedDecoded);
    add('ActivityCompleted', 4.4, started, {...decoded, result: {value: fixture.input, cancellation: context}},
      {...typedDecoded, result: {type: 'map', value: {value: fixture.typed_value, cancellation: typed(context)}}});
  } else add('TimerCancelled', 7, {...cleanupTimer, cancelled_at: at(7)});
  const diagnostic = outcome === 'completed' ? 'Cooperative cancellation completed.' : 'Cooperative cancellation cleanup deadline expired.';
  add('WorkflowCancelled', outcome === 'completed' ? 5 : 7.1, {workflow_command_id: context.request_id,
    workflow_instance_id: raw.workflow_id, workflow_run_id: raw.run_id, failure_id: 'failure', failure_category: 'cancelled',
    exception_class: 'Workflow\\V2\\Exceptions\\WorkflowCancelledException', closed_reason: 'cancelled', reason: diagnostic,
    message: `Workflow cancelled: ${diagnostic}`, cancellation_cleanup: {request_id: context.request_id, outcome,
      cleanup_deadline_at: context.cleanup_deadline_at, delivery_sequence: 1, delivery_history_event_id: 'delivery-event', finished_at: raw.execution.closed_at}});
  const history = raw.events.map(({decoded, typed_decoded, ...event}) => event);
  const requestSequence = raw.events.find(event => event.event_type === 'CooperativeCancellationRequested').sequence;
  const pending = {request_id: context.request_id, requested_at: context.requested_at, cleanup_deadline_at: context.cleanup_deadline_at,
    delivery_sequence: null, delivered_at: null, history_refresh_page_token: 'opaque-original-cursor'};
  const receipt = duplicate => ({accepted: true, duplicate, workflow_id: raw.workflow_id, run_id: raw.run_id, cancellation_request: pending});
  const embeddedReceipt = {command: {command_id: context.request_id}, context};
  raw.cooperative = {before: history.slice(0, requestSequence - 1), after_request: {id: raw.run_id, status: phase === 'before_claim' ? 'pending' : 'waiting', closed_at: null},
    accepted: mode === 'http' ? receipt(false) : embeddedReceipt,
    pending_duplicate: mode === 'http' ? receipt(true) : embeddedReceipt,
    post_terminal_duplicate: mode === 'http' ? {...receipt(true), cancellation_request: {...pending, delivery_sequence: 1, delivered_at: at(2.2)}} : embeddedReceipt,
    history_before_pending_duplicate: history.slice(0, requestSequence), history_after_pending_duplicate: history.slice(0, requestSequence),
    history_before_terminal_duplicate: history, history_after_terminal_duplicate: history, fresh_history: history,
    fresh_execution: {id: raw.run_id, status: 'cancelled', output: null},
    outcome: {type: 'DurableWorkflow\\Exception\\WorkflowCancelled', message: diagnostic},
    traffic: [{path: '/api/worker/workflow-tasks/delivery-task/deliver-cancellation', status: 200,
      request: {request_id: context.request_id, sequence: 1, call_kind: 'timer', lease_owner: 'worker', workflow_task_attempt: 1},
      response: {request_id: context.request_id, sequence: 1, call_kind: 'timer', delivered: true}},
    {path: '/api/worker/workflow-tasks/delivery-task/heartbeat', status: 200, response: {cancellation_request: pending, lease_expires_at: at(6)}}],
    delivery_history_event_id: 'delivery-event',
    failures: [{id: 'failure', source_kind: 'workflow_run', source_id: raw.run_id, failure_category: 'cancelled',
      exception_class: raw.events.at(-1).payload.exception_class, message: raw.events.at(-1).payload.message}],
    tasks: [{status: 'cancelled', lease_expires_at: null}],
    attempts: outcome === 'completed' ? [{id: 'cleanup-attempt', status: 'completed', lease_expires_at: null}] : [],
    watchdog: outcome === 'completed' ? [] : [{cancellation_deadlines_enforced: 1}]};
  // JSON snapshots have independent copies. Avoid accidental object aliases
  // letting a corruption rewrite both sides of an immutability assertion.
  return JSON.parse(JSON.stringify(raw));
}

const event = (value, type) => value.events.find(item => item.event_type === type);
for (const fixture of fixtures) {
  for (const mode of ['http', 'embedded']) {
    const raw = observation(fixture, mode);
    test(`${fixture.id}/${mode} validates the reviewed root contract model`, () => {
      assert.deepStrictEqual(checkObservation(fixture, raw, raw.workflow_id),
        checkObservation(fixture, observation(fixture, mode === 'http' ? 'embedded' : 'http'), raw.workflow_id));
    });
    const mutations = [
      ['root identity', value => { event(value, 'CooperativeCancellationDelivered').payload.cancellation.root_request_id = 'changed'; }],
      ['original reason', value => { event(value, 'CooperativeCancellationRequested').payload.cancellation.reason = 'changed'; }],
      ['accepted open state', value => { value.cooperative.after_request.status = 'cancelled'; }],
      ['duplicate budget', value => { const receipt = value.cooperative.pending_duplicate; (receipt.context ?? receipt.cancellation_request).cleanup_deadline_at = at(1000); }],
      ['terminal diagnostic', value => { value.events.at(-1).payload.message = 'changed'; }],
      ['cleanup outcome', value => { value.events.at(-1).payload.cancellation_cleanup.outcome = 'unavailable'; }],
      ['delivery author position', value => { event(value, 'CooperativeCancellationDelivered').payload.sequence = 2; }],
      ['fresh history', value => { value.cooperative.fresh_history.at(-1).payload.reason = 'changed'; }],
    ];
    if (fixture.cooperative_cancellation.cleanup_outcome === 'completed') mutations.push(
      ['exact int64', value => { event(value, 'ActivityCompleted').typed_decoded.result.value.value.value.count.value = '9007199254740992'; }],
      ['heartbeat root', value => { event(value, 'ActivityHeartbeatRecorded').payload.progress.details.request_id = 'changed'; }],
      ['timer fired early', value => { event(value, 'TimerFired').payload.fired_at = at(0); }]);
    else mutations.push(['expired original deadline', value => { value.events.filter(item => item.event_type === 'TimerCancelled').at(-1).payload.cancelled_at = at(0); }]);
    if (mode === 'http') mutations.push(
      ['original delivery owner', value => { value.cooperative.traffic[0].request.lease_owner = 'changed'; }],
      ['renewal cannot extend budget', value => { event(value, 'CooperativeCancellationDelivered').payload.task.lease_expires_at = at(1000); }]);
    else mutations.push(['physical delivery identity', value => { value.cooperative.delivery_history_event_id = 'changed'; }]);
    for (const [label, mutate] of mutations) test(`${fixture.id}/${mode} rejects ${label}`, () => {
      const corrupted = structuredClone(raw); mutate(corrupted);
      assert.throws(() => checkObservation(fixture, corrupted, corrupted.workflow_id));
    });
  }
}
