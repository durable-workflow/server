import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {gunzipSync,inflateSync,brotliDecompressSync} from 'node:zlib';

export function checkActivityDeadlinePackages(fixture, observation, artifacts) {
  if (!fixture.activity_deadline_authority) return;
  assert.equal(observation.sdk_php,artifacts.sdk_php);
  assert.equal(observation.sdk_php_source,artifacts.sdk_php_source_commit);
  if (observation.mode === 'embedded') {
    assert.equal(observation.workflow_package,artifacts.workflow);
    assert.equal(observation.workflow_source,artifacts.workflow_source_commit);
    assert.equal(observation.embedded_composer_lock_sha256,artifacts.embedded_composer_lock_sha256);
    assert.equal(observation.activity_deadline_authority.definition_sha256,
      createHash('sha256').update(readFileSync(new URL('./activity-deadline-embedded.php',import.meta.url))).digest('hex'));
  }
}

export function checkActivityDeadlines(fixture, observation, workflowId) {
  const spec = fixture.activity_deadline_authority;
  assert(['http','embedded'].includes(observation.mode), 'Actual deadline adapter');
  const http = observation.mode === 'http';
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.status, 'deadline_observed');
  const state = observation.activity_deadline_authority;
  assert.equal(state.driver, http ? 'installed_sdk_worker_protocol' : 'real_queue_then_public_embedded_bridges');
  if (!http) {
    assert.equal(state.activity_invocations,0,'No callback masquerades as a late worker receipt');
    assert.match(state.definition_sha256,/^[a-f0-9]{64}$/,'Actual embedded author definition');
    assert.deepEqual(state.requests,[],'Embedded uses public library bridges, without fabricated HTTP');
  }
  assert.equal(state.overall_budget_seconds, spec.overall_budget_seconds);
  const requests = state.requests.map(wire=> {
    assert.equal(wire.transport_error,null);
    assert.equal(wire.client_cancelled,false,'No cancelled request counts as a receipt');
    const decoders = {identity:value=>value,gzip:gunzipSync,deflate:inflateSync,br:brotliDecompressSync};
    assert(decoders[wire.response_encoding],'Known actual response encoding');
    assert.equal(decoders[wire.response_encoding](Buffer.from(wire.response_bytes_base64,'base64')).toString('utf8'),wire.response_body,'Complete original response bytes');
    return {...wire,request:wire.request_body?JSON.parse(wire.request_body):null,response:wire.response_body?JSON.parse(wire.response_body):null};
  });
  assert.deepEqual(Object.keys(state.steps), spec.fields);
  const identities = [];
  for (const field of spec.fields) {
    const step = state.steps[field];
    const id = `${workflowId}-${field}`;
    assert.equal(step.field, field);
    assert.equal(step.workflow_id, id);
    assert.equal(step.workflow_task.workflow_id, id);
    assert.equal(step.workflow_task.run_id, step.run_id);
    identities.push(step.run_id);
    if (http) assert.equal(step.admission.recorded, true);
    const policy = {snapshot_version:1,max_attempts:spec.terminal_max_attempts,
      backoff_seconds:spec.retry_backoff_seconds,non_retryable_error_types:[],
      start_to_close_timeout:null,schedule_to_start_timeout:null,schedule_to_close_timeout:null,heartbeat_timeout:null};
    policy[field] = spec.timeout_seconds;
    if (http) {
      assert.equal(step.command.type,'schedule_activity');
      assert.equal(step.command.activity_type,'parity.v1.deadline_activity');
      assert.equal(step.command.queue,`${id}-activity`);
      assert.equal(step.command[field],spec.timeout_seconds);
      assert.deepEqual(step.command.retry_policy,{max_attempts:spec.terminal_max_attempts,
        backoff_seconds:spec.retry_backoff_seconds,non_retryable_error_types:[]});
    } else {
      assert.equal(step.authored_options.queue,`${id}-activity`);
      assert.equal(step.authored_options.max_attempts,spec.terminal_max_attempts);
      assert.deepEqual(step.authored_options.backoff,spec.retry_backoff_seconds);
      assert.deepEqual(step.authored_options.non_retryable_error_types,[]);
      for(const name of spec.fields)assert.equal(step.authored_options[name],name===field?spec.timeout_seconds:null);
      assert.match(step.worker_output,/RunWorkflowTask.*RUNNING/);
      assert.match(step.worker_output,/RunWorkflowTask.*DONE/);
      assert.equal(step.enforcement,true);
      assert.equal(step.workflow_task.workflow_task_attempt,1);
    }
    for (const snapshot of [step.scheduled,step.before_expiry,step.after_expiry]) {
      assert.equal(snapshot.workflow_id, id);
      assert.equal(snapshot.run_id, step.run_id);
      assert.equal(snapshot.workflow_type, fixture.workflow_type);
      assert.deepEqual(snapshot.typed_input, {type:'list',value:[fixture.typed_value]});
      for (const name of ['execution_deadline_at','run_deadline_at']) {
        assert.equal(snapshot.execution[name],step.scheduled.execution[name], 'Original run bounds');
      }
      assert.deepEqual(step.after_expiry.events.slice(0,snapshot.events.length),snapshot.events,'Exact original history prefix');
    }
    const claimed = field !== 'schedule_to_start_timeout';
    const types = ['StartAccepted','WorkflowStarted','ActivityScheduled',...(claimed?['ActivityStarted']:[]),'ActivityTimedOut'];
    const events = step.after_expiry.events;
    assert.deepEqual(events.map(event=>event.event_type),types,'One terminal timeout, no late outcome/progress/retry');
    for(const[index,event]of events.entries()) {
      assert.equal(event.sequence,index+1);
      assert.match(event.timestamp,/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/,'Original microsecond history');
      if(index)assert(event.timestamp>=events[index-1].timestamp);
    }
    const scheduled = events[2];
    if (http) assert.deepEqual(step.command.arguments,scheduled.payload.activity.arguments,'Original authored argument bytes');
    assert.deepEqual(scheduled.payload.activity.retry_policy,policy,'Entire original authored snapshot');
    assert.deepEqual(scheduled.typed_decoded.activity_arguments,{type:'list',value:[fixture.typed_value]});
    const terminal = events.at(-1).payload;
    assert.equal(terminal.activity_execution_id,scheduled.payload.activity_execution_id);
    assert.equal(terminal.timeout_kind,field.replace(/_timeout$/,''));
    assert.equal(terminal.failure_category,spec.failure_category);
    assert.equal(terminal.exception_class,spec.exception_class);
    assert.equal(terminal.attempt_number,1);
    assert.equal(terminal.activity.status,'failed');
    assert.deepEqual(terminal.activity.retry_policy,policy);
    assert.equal(typeof terminal.failure_id,'string');
    assert(terminal.failure_id.length>0);
    const parent = step.parent_delivery;
    assert.equal(parent.workflow_id,id);
    assert.equal(parent.run_id,step.run_id);
    assert.notEqual(parent.task_id,step.workflow_task.task_id,'One actual parent wakeup');
    assert.equal(parent.workflow_task_attempt,1);
    assert.deepEqual(step.extra_parent_poll,http?null:[]);
    assert.deepEqual(step.post_expiry_activity_poll,http?null:[]);
    assert.deepEqual(parent.history_events.map(event=>event.event_type),types);
    for(const[index,event]of parent.history_events.entries()) {
      assert.equal(event.sequence,events[index].sequence);
      assert.equal(event.recorded_at,events[index].timestamp);
      assert.deepEqual(event.payload,events[index].payload,'Whole actual parent timeout delivery');
    }
    if(claimed) {
      const activity = step.activity_task;
      assert.equal(activity.workflow_id,id);
      assert.equal(activity.run_id,step.run_id);
      assert.equal(activity.activity_execution_id,scheduled.payload.activity_execution_id);
      assert.equal(activity.attempt_number,1);
      assert.deepEqual(activity.retry_policy,policy);
      assert.equal(step.status_before_expiry.can_continue,true);
      assert.equal(step.status_before_expiry.heartbeat_recorded,false);
      assert.equal(step.status_after_expiry.can_continue,false);
      assert.equal(step.status_after_expiry.heartbeat_recorded,false);
      assert.deepEqual(step.late_outcomes.map(receipt=>receipt.id),['complete','fail','heartbeat']);
      for(const receipt of step.late_outcomes.slice(0,2)) {
        if (http) assert.equal(receipt.status,409,'Elapsed authority never commits a late result/failure');
        else {
          assert.equal(receipt.response.recorded,false,'Elapsed embedded authority refuses outcome');
          assert.equal(receipt.response.next_task_id,null);
          assert.equal(receipt.response.reason,field);
          assert.equal(receipt.response.task_id,activity.activity_attempt_id);
        }
      }
      const heartbeat = step.late_outcomes[2];
      if (http) assert([200,409].includes(heartbeat.status),'Structured heartbeat refusal or fenced conflict');
      if(!http || heartbeat.status===200) {
        assert.equal(heartbeat.response.can_continue,false);
        assert.equal(heartbeat.response.heartbeat_recorded,false);
        // A concurrent canonical timeout may clear the original lease.
        // It must never replace it with fresh authority.
        if(heartbeat.response.lease_expires_at!==null)assert.equal(heartbeat.response.lease_expires_at,step.status_after_expiry.lease_expires_at,'Refused heartbeat never renews lease');
        assert.equal(heartbeat.response.last_heartbeat_at,step.status_after_expiry.last_heartbeat_at,'Refused heartbeat never records progress');
      }
      const start = events.find(event=>event.event_type==='ActivityStarted');
      assert.equal(start.payload.activity_attempt_id,activity.activity_attempt_id);
      assert.equal(terminal.activity_attempt_id,activity.activity_attempt_id);
      const lease = Date.parse(activity.lease_expires_at)-Date.parse(start.timestamp);
      assert(lease>=(spec.ordinary_lease_seconds-1)*1000 && lease<=spec.ordinary_lease_seconds*1000,'Original ordinary activity lease');
    } else assert.equal(step.activity_task,null,'Schedule-to-start expires before any claim');
    if (!http) {
      const deadlineField = {start_to_close_timeout:'close_deadline_at',schedule_to_start_timeout:'schedule_deadline_at',
        schedule_to_close_timeout:'schedule_to_close_deadline_at',heartbeat_timeout:'heartbeat_deadline_at'}[field];
      for (const snapshot of [step.scheduled,step.before_expiry,step.after_expiry]) {
        assert.equal(snapshot.activities.length,1);
        assert.equal(snapshot.activities[0].id,scheduled.payload.activity_execution_id);
        assert.deepEqual(snapshot.activities[0].retry_policy,policy);
        if(snapshot!==step.scheduled)assert.equal(snapshot.activities[0][deadlineField],step.before_expiry.activities[0][deadlineField]);
      }
      assert.equal(step.after_expiry.activities[0].status,'failed');
      assert.equal(step.after_expiry.execution.status,'waiting','Parent timeout result is retained, not authored');
      const originalDeadline = step.before_expiry.activities[0][deadlineField];
      assert.match(originalDeadline,/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
      assert(events.at(-1).timestamp>=originalDeadline,'Actual wall-clock expiry at original microsecond precision');
      const original = step.scheduled.tasks.filter(task=>task.task_type==='workflow');
      assert.equal(original.length,1);
      assert.equal(original[0].id,step.workflow_task.task_id);
      const parentTask = step.after_expiry.tasks.find(task=>task.id===parent.task_id);
      assert.equal(parentTask.workflow_run_id,step.run_id);
      assert.equal(parentTask.status,'leased');
      assert.equal(parentTask.attempt_count,1);
      assert.equal(parentTask.repair_count,0);
      continue;
    }
    const wires = requests.filter(wire=>wire.path.includes(step.workflow_task.task_id)||claimed&&wire.path.includes(step.activity_task.task_id));
    const admission = wires.filter(wire=>wire.method==='POST'&&wire.path===`/api/worker/workflow-tasks/${step.workflow_task.task_id}/complete`);
    assert.equal(admission.length,1);
    assert.equal(admission[0].status,200);
    assert.deepEqual(admission[0].request.commands,[step.command]);
    assert.deepEqual(admission[0].response,step.admission);
    if(claimed)for(const kind of ['complete','fail','heartbeat']) {
      const outcomes = wires.filter(wire=>wire.method==='POST'&&wire.path===`/api/worker/activity-tasks/${step.activity_task.task_id}/${kind}`);
      assert.equal(outcomes.length,1,'One actual late request per outcome');
      assert.equal(outcomes[0].status,step.late_outcomes.find(receipt=>receipt.id===kind).status);
      assert.equal(outcomes[0].request.activity_attempt_id,step.activity_task.activity_attempt_id);
      assert.equal(outcomes[0].request.lease_owner,step.activity_task.lease_owner);
    }
  }
  assert.equal(new Set(identities).size,spec.fields.length,'Four genuinely independent originals');
  for(const wire of state.requests) {
    assert.equal(wire.client_cancelled,false,'No cancelled request is counted as a receipt');
    assert.equal(typeof wire.response_bytes_base64,'string');
    assert(wire.response_bytes_base64.length>0,'Full actual response bytes retained');
  }
  // Adapter provenance remains strict above and in the complete observation;
  // HTTP receipts and public embedded bridge calls have the same semantics.
  return {fixture_id:fixture.id,workflow_id:workflowId,fields:spec.fields,
    timeout_kind:spec.fields.map(field=>field.replace(/_timeout$/,'')),parent_delivery:'original_timeout_history',
    workflow_result:'not_authored',late_outcomes:'refused',policy_backoff:spec.retry_backoff_seconds};
}
