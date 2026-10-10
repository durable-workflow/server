import assert from 'node:assert/strict';

export function checkWorkerDeregistration(fixture, observation, workflowId) {
  const definition = fixture.worker_deregistration;
  const state = observation.worker_deregistration;
  const projection = {scope: definition.scope, receipt_retention_seconds: definition.receipt_retention_seconds};
  if (definition.replace_live_registration) projection.replace_live_registration = true;
  if (definition.sdk_reply_loss) projection.sdk_reply_loss = true;
  assert.ok(state && typeof state === 'object', 'actual worker deregistration observation');
  if (observation.mode === 'embedded') {
    assert.deepStrictEqual(state, {applicable: false, reason: 'embedded_has_no_http_worker_registration_lifecycle'}, 'embedded lifecycle explicitly inapplicable');
    return projection;
  }
  assert.equal(state.applicable, true, 'HTTP lifecycle actually executed');
  assert.deepStrictEqual(state.capability, {schema: definition.schema, supported: true,
    receipt_retention_seconds: definition.receipt_retention_seconds, endpoint: '/worker/registrations/{workerId}/deregister'}, 'negotiated exact fence contract');
  const workerId = workflowId+'-fenced-worker';
  const peerId = workflowId+definition.peer_suffix;
  assert.equal(state.worker_id, workerId);
  assert.equal(state.peer_workflow_id, peerId);
  assert.ok(state.peer_run_id && state.peer_run_id !== observation.run_id, 'distinct original peer run');
  const tokens = ['original', 'replacement', 'latest'].map(kind => {
    const registration = state[kind+'_registration'];
    assert.equal(registration.worker_id, workerId, 'same worker lifecycle identity');
    assert.equal(registration.namespace, 'default');
    assert.equal(registration.registered, true);
    assert.match(registration.registration_token, /^[a-f0-9]{32}$/);
    return registration.registration_token;
  });
  assert.equal(new Set(tokens).size, 3, 'every successful registration rotates the incarnation');
  for (const key of ['heartbeat', 'replacement_heartbeat', 'latest_heartbeat']) {
    assert.equal(state[key].worker_id, workerId);
    assert.equal(state[key].acknowledged, true, 'heartbeat succeeds without retiring original authority');
  }
  const task = state.original_task;
  const latest = state.latest_task;
  for (const leased of [task, latest]) {
    assert.equal(leased.workflow_id, peerId);
    assert.equal(leased.run_id, state.peer_run_id);
    assert.equal(leased.lease_owner, workerId);
    assert.ok(leased.task_id && Number.isInteger(leased.workflow_task_attempt));
  }
  assert.equal(latest.task_id, task.task_id, 'recover the original durable task');
  assert.equal(latest.workflow_task_attempt, task.workflow_task_attempt+1, 'new attempt after original recovery');
  const runKeys = ['before_unknown', 'after_unknown', 'after_original', 'after_stale_original', 'before_superseded',
    'after_superseded', 'before_replay', 'after_replay', 'after_stale_replay', 'after_latest', 'final'];
  for (const key of runKeys) {
    const run = state[key];
    assert.equal(run.workflow_id, peerId, 'retain original workflow identity');
    assert.equal(run.run_id, state.peer_run_id, 'retain original run identity');
    assert.equal(run.workflow_type, definition.peer_workflow_type);
    assert.equal(run.namespace, 'default');
    assert.deepStrictEqual(run.typed_input, {type: 'list', value: [fixture.typed_value]}, 'retain exact original input');
    assert.equal(run.status, key === 'final' ? 'completed' : state.before_unknown.status, 'refusals cannot complete original work');
    for (const event of run.events.filter(event => event.event_type !== 'WorkflowCompleted')) {
      assert.equal(event.payload.workflow_instance_id, peerId);
      assert.equal(event.payload.workflow_run_id, state.peer_run_id);
    }
  }
  for (const [before, after] of [['before_unknown','after_unknown'], ['after_original','after_stale_original'],
    ['before_superseded','after_superseded'], ['before_replay','after_replay'], ['after_replay','after_stale_replay']]) {
    assert.deepStrictEqual(state[after], state[before], 'refusal or receipt replay preserves complete original run and history');
  }
  const failure = (key, status, reason, token) => {
    assert.equal(state[key].status, status);
    assert.equal(state[key].response.reason, reason);
    assert.equal(state[key].response.worker_id, workerId);
    assert.equal(state[key].response.registration_token, token);
    assert.equal(state[key].response.retryable, false, 'terminal fence refusal cannot become a retry');
  };
  assert.ok(state.unknown_token !== tokens[0]);
  failure('unknown', 404, 'worker_registration_token_not_found', state.unknown_token);
  failure('superseded', 409, 'worker_registration_lost_authority', tokens[1]);
  for (const [key, reason] of [['stale_original',definition.first_stale_reason], ['stale_after_replay',definition.replacement_stale_reason]]) {
    assert.equal(state[key].status, 409);
    assert.equal(state[key].response.reason, reason);
    assert.equal(state[key].response.task_id, task.task_id);
    assert.equal(state[key].response.workflow_task_attempt, task.workflow_task_attempt);
  }
  const receiptFields = value => Object.fromEntries(['worker_id','registration_token','outcome','recovered_workflow_task_count'].map(key => [key,value[key]]));
  const receipt = (key, token) => {
    assert.equal(state[key].protocol_version, '1.20');
    assert.deepStrictEqual(state[key].server_capabilities.worker_deregistration_fencing, state.capability);
    assert.deepStrictEqual(receiptFields(state[key]), {worker_id: workerId,
      registration_token: token, outcome: 'deregistered', recovered_workflow_task_count: definition.recovered_workflow_task_count});
  };
  receipt('original_receipt', tokens[0]);
  receipt('latest_receipt', tokens[2]);
  assert.deepStrictEqual(state.replayed_receipt, state.original_receipt, 'immutable original receipt before inspecting replacement');
  const history = state.final.events;
  assert.deepStrictEqual(history.map(event => event.event_type), definition.expected_peer_events, 'actual recovery and completion history');
  assert.deepStrictEqual(history.map(event => event.sequence), history.map((_,index) => index+1), 'one original sequence');
  assert.deepStrictEqual(history.slice(0,2), state.before_unknown.events, 'original start is immutable');
  assert.deepStrictEqual(state.after_original.events.map(event => event.event_type), definition.expected_peer_events.slice(0,3));
  assert.deepStrictEqual(state.after_latest.events, history.slice(0,-1), 'completion appends after original repairs');
  const repairs = history.filter(event => event.event_type === 'RepairRequested');
  assert.equal(new Set(repairs.map(event => event.payload.workflow_command_id)).size, 2, 'distinct original repair commands');
  for (const event of repairs) {
    assert.equal(event.payload.command_type, 'repair');
    assert.equal(event.payload.outcome, 'repair_dispatched');
    assert.equal(event.payload.task_id, task.task_id);
    assert.equal(event.payload.task_type, 'workflow');
  }
  assert.deepStrictEqual(state.final.typed_output, fixture.typed_value, 'real SDK worker commits original typed result');
  const completed = history.at(-1);
  assert.deepStrictEqual(completed.typed_decoded.output, fixture.typed_value, 'original history commits the same typed result');
  assert.deepStrictEqual(completed.payload.output, state.final.execution.output_envelope, 'history and read expose the same committed Avro frame');
  assert.equal(completed.payload.task.id, task.task_id, 'real SDK completes the original recovered durable task');
  assert.equal(completed.payload.task.attempt_count, latest.workflow_task_attempt+1, 'real SDK obtains its own recovery attempt');
  assert.equal(completed.payload.task.repair_count, 2, 'both original repairs remain recorded');
  if (definition.replace_live_registration) {
    const live = state.live_replacement;
    const initial = live.initial_task;
    const initialRegistration = live.initial_registration;
    assert.equal(initialRegistration.worker_id, workerId);
    assert.equal(initialRegistration.namespace, 'default');
    assert.equal(initialRegistration.registered, true);
    assert.match(initialRegistration.registration_token, /^[a-f0-9]{32}$/);
    assert.equal(new Set([...tokens,initialRegistration.registration_token]).size, 4);
    assert.equal(initial.task_id, task.task_id, 'registration recovers the same original task');
    assert.equal(initial.workflow_id, peerId);
    assert.equal(initial.run_id, state.peer_run_id);
    assert.equal(initial.lease_owner, workerId);
    assert.equal(initial.workflow_task_attempt, 1);
    assert.equal(task.workflow_task_attempt, initial.workflow_task_attempt+1);
    assert.deepStrictEqual(live.before_replacement.events, history.slice(0,2));
    assert.deepStrictEqual(live.after_replacement, live.before_replacement, 'registration release preserves full original run/history');
    for (const key of ['before_replacement','after_replacement','after_superseded','after_stale_before_poll','before_stale_after_poll','after_stale_after_poll']) {
      const run = live[key];
      assert.equal(run.workflow_id, peerId);
      assert.equal(run.run_id, state.peer_run_id);
      assert.equal(run.workflow_type, definition.peer_workflow_type);
      assert.equal(run.namespace, 'default');
      assert.equal(run.status, state.before_unknown.status);
      assert.deepStrictEqual(run.typed_input, {type:'list',value:[fixture.typed_value]});
    }
    assert.deepStrictEqual(live.after_superseded, live.after_replacement);
    assert.deepStrictEqual(live.after_stale_before_poll, live.after_replacement);
    assert.deepStrictEqual(live.after_stale_after_poll, live.before_stale_after_poll);
    assert.deepStrictEqual(live.after_stale_after_poll, state.before_unknown);
    assert.equal(live.superseded.status, 409);
    assert.equal(live.superseded.response.reason, 'worker_registration_lost_authority');
    assert.equal(live.superseded.response.worker_id, workerId);
    assert.equal(live.superseded.response.registration_token, initialRegistration.registration_token);
    assert.equal(live.superseded.response.retryable, false);
    for (const [key,reason] of [['stale_before_poll','task_not_leased'],['stale_after_poll','workflow_task_attempt_mismatch']]) {
      assert.equal(live[key].status, 409);
      assert.equal(live[key].response.reason, reason);
      assert.equal(live[key].response.task_id, initial.task_id);
      assert.equal(live[key].response.workflow_task_attempt, initial.workflow_task_attempt);
    }
  }
  assert.equal(state.idle_poll, null, 'no duplicate remaining workflow task');
  assert.deepStrictEqual(receiptFields(state.idle_receipt), {worker_id: workflowId+'-idle-worker', registration_token: state.idle_registration.registration_token,
    outcome: 'deregistered', recovered_workflow_task_count: 0});
  if (definition.sdk_reply_loss) {
    const sdk = state.sdk_reply_loss;
    assert.ok(sdk && typeof sdk === 'object', 'actual SDK reconciliation evidence');
    assert.equal(sdk.kind, 'client_injected_after_real_http_commit', 'honest fault origin');
    assert.equal(sdk.bounded_transport, true);
    assert.equal(sdk.injected_loss_count, 1);
    assert.ok(Number.isFinite(sdk.shutdown_elapsed_seconds) && sdk.shutdown_elapsed_seconds >= 0
      && sdk.shutdown_elapsed_seconds <= 10, 'observed reconciliation fits one shutdown budget');
    assert.deepStrictEqual(sdk.requests.map(r=>[r.method,r.path]), [
      ['POST','/api/worker/register'],
      ['POST','/api/worker/registrations/'+workerId+'/deregister'],
      ['POST','/api/worker/registrations/'+workerId+'/deregister'],
    ], 'SDK retries shutdown without polling or refreshing registration');
    assert.equal(sdk.requests[0].status, 201, 'actual SDK registration response');
    assert.equal(sdk.requests[0].namespace, 'default');
    assert.match(sdk.requests[0].credential_sha256, /^[a-f0-9]{64}$/);
    assert.notEqual(sdk.requests[0].credential_sha256, 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 'credential actually supplied');
    for (const r of sdk.requests.slice(1)) {
      assert.equal(r.status, 200, 'real commit and successful receipt reconciliation');
      assert.equal(r.registration_token, tokens[0], 'retry original accepted incarnation');
      assert.equal(r.namespace, sdk.requests[0].namespace);
      assert.equal(r.credential_sha256, sdk.requests[0].credential_sha256);
      assert.ok(Number.isInteger(r.timeout_seconds) && r.timeout_seconds >= 1 && r.timeout_seconds <= 10,
        'each SDK shutdown request uses bounded I/O');
    }
    assert.ok(sdk.requests[2].timeout_seconds <= sdk.requests[1].timeout_seconds, 'retry timeout does not increase');
    assert.deepStrictEqual(sdk.diagnostics.filter(d=>d.event==='worker.retrying').map(d=>[d.operation,d.attempt]),
      [['deregister_worker',1]], 'actual SDK retries the original shutdown once');
    assert.equal(sdk.diagnostics.filter(d=>d.event==='worker.deregistered').length, 1);
    assert.equal(sdk.diagnostics.filter(d=>d.event==='worker.stopped').length, 1);
    assert.equal(sdk.diagnostics.filter(d=>['worker.failed','worker.shutdown_failed','worker.shutdown_retry_unavailable'].includes(d.event)).length, 0);
  }
  return projection;
}
