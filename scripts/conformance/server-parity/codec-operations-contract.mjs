import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkCodecDeadlineRecord} from './codec-deadline-contract.mjs';
import {instantNanoseconds} from './contract.mjs';

const instant=value=>Date.parse(value.includes('T')?value:value.replace(' ','T')+'Z')/1000;
const rawEvents=events=>events.map(({decoded,typed_decoded,...event})=>event);
export function checkCanonicalLateness(at,deadline,maximumSeconds) {
  const delta=instantNanoseconds(at)-instantNanoseconds(deadline);
  assert(delta>=0n&&delta<=BigInt(maximumSeconds)*1_000_000_000n,`Canonical event ${at} is within ${maximumSeconds}s after its deadline ${deadline}`);
}
function prefix(before,after){assert.deepEqual(rawEvents(after.slice(0,before.length)),rawEvents(before),'Entire original prefix and clocks');}
function events(observation,types){assert.deepEqual(observation.events.map(event=>event.event_type),types);}

export function checkCodecOperationsRecord(record,specBytes,cpu) {
  const base=checkCodecDeadlineRecord(record,specBytes,cpu),spec=JSON.parse(specBytes);
  const start=instant(record.load.started_at),end=instant(record.load.finished_at);
  assert(end-start>=spec.wave_seconds,'Actual full declared load duration');
  assert(instant(record.health.finished_at)-instant(record.health.started_at)>=spec.wave_seconds);
  assert.deepEqual(Object.keys(record.operations).sort(),['cancellation','renewal','timer']);
  const underLoad=at=>{assert(at>=start&&at<=end,'Operation observed during actual read wave');};
  const busy=at=>{
    assert(cpu.samples.some(sample=>Math.abs(instant(sample.observed_at)-at)<=1.5
      &&sample.cpu_percent>=spec.minimum_busy_cpu_percent),'Actual CPU busy at operation boundary');
  };
  for(const[role,o]of Object.entries(record.operations)) {
    assert.equal(o.role,role);assert.equal(o.outcome,'observed');assert.equal(o.phase,'complete');
    assert.equal(o.sdk_version,record.artifacts.sdk_php);assert.equal(o.sdk_source,record.artifacts.sdk_php_source_commit);
    assert.equal(o.specification_sha256,record.specification_sha256);
    assert.equal(o.observer_deadline-o.observer_started_at,spec.observer_budget_seconds);
    assert(o.observer_finished_at<o.observer_deadline);
    assert.equal(o.task.run_id,o.run_id);assert.equal(o.task.workflow_id,o.workflow_id);assert.equal(o.task.workflow_task_attempt,1);
    assert.notEqual(o.run_id,record.original.run_id);
    for(const snapshot of [o.prepared,o.before,o.after]) {
      assert.equal(snapshot.run_id,o.run_id);assert.equal(snapshot.workflow_id,o.workflow_id);
      assert.deepEqual(snapshot.typed_input,{type:'list',value:[spec.typed_row]});
      for(const field of ['run_deadline_at','execution_deadline_at'])assert.equal(snapshot.execution[field],o.prepared.execution[field]);
    }
    events(o.prepared,['StartAccepted','WorkflowStarted']);prefix(o.prepared.events,o.after.events);
    for(const call of o.calls) {
      assert(call.observed_at>=call.submitted_at);underLoad(call.submitted_at);underLoad(call.observed_at);
      assert(call.observed_at-call.submitted_at<=spec.request_timeout_seconds,'Ordinary control operation deadline health');
    }
    if(role==='timer') {
      events(o.before,['StartAccepted','WorkflowStarted','TimerScheduled']);
      events(o.after,['StartAccepted','WorkflowStarted','TimerScheduled','TimerFired','WorkflowCompleted']);
      prefix(o.before.events,o.after.events);
      const scheduled=o.after.events[2].payload,fired=o.after.events[3].payload;
      for(const field of ['timer_id','sequence','delay_seconds','fire_at'])assert.deepEqual(fired[field],scheduled[field]);
      assert.equal(scheduled.delay_seconds,spec.operations.timer.delay_seconds);assert.equal(scheduled.sequence,1);
      const due=instant(scheduled.fire_at),at=instant(fired.fired_at);
      checkCanonicalLateness(fired.fired_at,scheduled.fire_at,spec.operations.timer.maximum_lateness_seconds);
      underLoad(due);underLoad(at);underLoad(o.timer_delivered_at);busy(due);
      assert.equal(o.parent.run_id,o.run_id);assert.equal(o.parent.workflow_task_attempt,1);
      assert.equal(o.after.status,'completed');assert.deepEqual(o.after.typed_output,spec.typed_row);
      assert.equal(o.workflow_completion.recorded,true);
      assert.equal(o.timer_admission.recorded,true);
    } else if(role==='renewal') {
      events(o.before,['StartAccepted','WorkflowStarted','ActivityScheduled','ActivityStarted']);
      events(o.after,['StartAccepted','WorkflowStarted','ActivityScheduled','ActivityStarted',
        'ActivityHeartbeatRecorded','ActivityHeartbeatRecorded','ActivityCompleted','WorkflowCompleted']);
      prefix(o.before.events,o.after.events);
      const task=o.activity_task,policy=spec.operations.renewal;
      assert.equal(task.run_id,o.run_id);assert.equal(task.attempt_number,1);
      assert.deepEqual(task.retry_policy,{snapshot_version:1,max_attempts:1,backoff_seconds:[],non_retryable_error_types:[],
        start_to_close_timeout:policy.start_to_close_seconds,schedule_to_start_timeout:null,
        schedule_to_close_timeout:policy.schedule_to_close_seconds,heartbeat_timeout:policy.heartbeat_timeout_seconds});
      assert.equal(o.before.events[3].payload.activity_attempt_id,task.activity_attempt_id);
      for(const status of [o.status_before,o.status_first,o.status_second,o.status_after]) {
        assert.equal(status.can_continue,true);assert.equal(status.heartbeat_recorded,false);
      }
      assert.equal(o.status_first.lease_expires_at,o.status_before.lease_expires_at);
      assert.equal(o.status_second.lease_expires_at,o.heartbeat_first.lease_expires_at);
      assert.equal(o.status_after.lease_expires_at,o.heartbeat_second.lease_expires_at);
      assert.equal(o.status_second.last_heartbeat_at,o.heartbeat_first.last_heartbeat_at);
      assert.equal(o.status_after.last_heartbeat_at,o.heartbeat_second.last_heartbeat_at);
      // The exact final history above proves that non-renewing status reads added no events.
      for(const[name,index]of [['first',4],['second',5]]) {
        const heartbeat=o['heartbeat_'+name],call=o.calls.find(call=>call.name==='heartbeat_'+name),event=o.after.events[index];
        assert.equal(heartbeat.can_continue,true);assert.equal(heartbeat.heartbeat_recorded,true);
        assert(call.submitted_at>=o.activity_claimed_at+policy[name+'_heartbeat_delay_seconds']);
        assert.equal(event.payload.activity_attempt_id,task.activity_attempt_id);
        assert.equal(event.payload.activity_execution_id,task.activity_execution_id);
        assert.deepEqual(event.payload.progress,{details:{message:name+' CPU renewal λ'}});
        assert.deepEqual(event.payload.activity.retry_policy,task.retry_policy);
        busy(call.submitted_at);
      }
      assert(instant(o.heartbeat_first.lease_expires_at)>instant(o.status_before.lease_expires_at));
      assert(instant(o.heartbeat_second.lease_expires_at)>instant(o.heartbeat_first.lease_expires_at));
      assert(instantNanoseconds(o.heartbeat_first.last_heartbeat_at)<instantNanoseconds(o.before.events[3].timestamp)+BigInt(policy.heartbeat_timeout_seconds)*1_000_000_000n);
      assert(instantNanoseconds(o.heartbeat_second.last_heartbeat_at)<instantNanoseconds(o.heartbeat_first.last_heartbeat_at)+BigInt(policy.heartbeat_timeout_seconds)*1_000_000_000n);
      const second=o.calls.find(call=>call.name==='heartbeat_second');
      assert(second.submitted_at>=instant(o.before.events[3].timestamp)+policy.heartbeat_timeout_seconds,
        'The same attempt survives its original heartbeat boundary');
      assert.equal(o.after.events[6].payload.activity_attempt_id,task.activity_attempt_id);
      assert.deepEqual(o.after.events[6].typed_decoded.result,spec.typed_row);
      assert.deepEqual(o.after.events[7].typed_decoded.output,spec.typed_row);
      assert(instantNanoseconds(o.after.events[6].timestamp)<instantNanoseconds(o.before.events[3].timestamp)+BigInt(policy.start_to_close_seconds)*1_000_000_000n);
      assert(instantNanoseconds(o.after.events[6].timestamp)<instantNanoseconds(o.before.events[2].timestamp)+BigInt(policy.schedule_to_close_seconds)*1_000_000_000n);
      assert.equal(o.parent.run_id,o.run_id);assert.equal(o.parent.workflow_task_attempt,1);
      assert.equal(o.activity_completion.recorded,true);assert.equal(o.workflow_completion.recorded,true);
      assert.equal(o.after.status,'completed');assert.deepEqual(o.after.typed_output,spec.typed_row);
    } else {
      const policy=spec.operations.cancellation;
      events(o.before,['StartAccepted','WorkflowStarted','TimerScheduled']);
      events(o.before_expiry,['StartAccepted','WorkflowStarted','TimerScheduled','CooperativeCancellationRequested',
        'TimerCancelled','CooperativeCancellationDelivered','TimerScheduled']);
      events(o.after,['StartAccepted','WorkflowStarted','TimerScheduled','CooperativeCancellationRequested',
        'TimerCancelled','CooperativeCancellationDelivered','TimerScheduled','TimerCancelled','WorkflowCancelled']);
      prefix(o.before_expiry.events,o.after.events);
      const requested=o.after.events[3],delivered=o.after.events[5],terminal=o.after.events[8];
      const context=requested.payload.cancellation,deadline=instant(context.cleanup_deadline_at),requestedAt=instant(context.requested_at);
      assert.equal(context.schema,'durable-workflow.cancellation-context/v1');
      assert.equal(context.request_id,requested.payload.workflow_command_id);
      assert.deepEqual(context.requester,{type:'auth:token',id:'legacy-token',label:'Admin'});
      assert.equal(instantNanoseconds(context.cleanup_deadline_at)-instantNanoseconds(context.requested_at),BigInt(policy.cleanup_timeout_seconds)*1_000_000_000n);
      assert.equal(context.reason,policy.reason);assert.equal(context.root_request_id,context.request_id);
      assert.equal(context.root_workflow_instance_id,o.workflow_id);assert.equal(context.root_workflow_run_id,o.run_id);
      assert.equal(context.parent_request_id,null);assert.equal(context.source,'control_plane');
      assert.deepEqual(context.lineage,[{request_id:context.request_id,workflow_instance_id:o.workflow_id,workflow_run_id:o.run_id}]);
      assert.deepEqual(delivered.payload.cancellation,context);assert.equal(delivered.payload.sequence,1);assert.equal(delivered.payload.call_kind,'timer');
      assert.equal(o.delivery.delivered,true);assert.equal(o.delivery_claim.run_id,o.run_id);
      assert.equal(o.accepted.accepted,true);assert.equal(o.timer_admission.recorded,true);
      assert.equal(o.cleanup_admission.recorded,true);
      assert.equal(o.delivery_claim.workflow_task_attempt,1);assert.equal(o.accepted.duplicate,false);
      for(const receipt of [o.accepted,o.duplicate,o.terminal_duplicate,o.renewal]) {
        assert.equal(receipt.cancellation_request.request_id,context.request_id);
        assert.equal(receipt.cancellation_request.cleanup_deadline_at,context.cleanup_deadline_at);
      }
      assert.equal(o.duplicate.duplicate,true);assert.equal(o.terminal_duplicate.duplicate,true);
      assert(instantNanoseconds(o.renewal.lease_expires_at)<=instantNanoseconds(context.cleanup_deadline_at),'Cleanup lease capped by original deadline');
      assert.equal(o.after.events[2].payload.delay_seconds,policy.original_delay_seconds);
      assert.equal(o.after.events[6].payload.delay_seconds,policy.cleanup_delay_seconds);
      assert.notEqual(o.after.events[2].payload.timer_id,o.after.events[6].payload.timer_id);
      for(const[scheduled,cancelled]of [[2,4],[6,7]])assert.equal(o.after.events[cancelled].payload.timer_id,o.after.events[scheduled].payload.timer_id);
      const cleanup=terminal.payload.cancellation_cleanup,closed=instant(cleanup.finished_at);
      assert.equal(o.closure_event.event_type,'WorkflowCancelled');assert.equal(o.closure_event.sequence,terminal.sequence);
      assert.deepEqual(o.closure_event.payload,terminal.payload,'Observed canonical closure is the final durable outcome');
      assert.equal(cleanup.request_id,context.request_id);assert.equal(cleanup.cleanup_deadline_at,context.cleanup_deadline_at);
      assert.equal(cleanup.outcome,'deadline_expired');assert.equal(cleanup.delivery_sequence,1);
      assert.equal(cleanup.finished_at,o.after.execution.closed_at);assert.equal(o.after.status,'cancelled');assert.equal(o.after.output,null);
      checkCanonicalLateness(cleanup.finished_at,context.cleanup_deadline_at,policy.maximum_lateness_seconds);
      // Canonical closure lateness is checked above. A client can only see it through
      // a request that may take the declared request timeout under this load.
      assert(o.closure_observed_at>=closed&&o.closure_observed_at-deadline<=policy.maximum_lateness_seconds+spec.request_timeout_seconds);
      underLoad(deadline);underLoad(closed);underLoad(o.closure_observed_at);busy(deadline);
    }
  }
  return {...base,scope:'http_codec_load_timer_renewal_and_cancellation_deadlines',operations:Object.keys(record.operations)};
}

if(process.argv[1]===new URL(import.meta.url).pathname){
  const[record,spec,cpu]=process.argv.slice(2);
  console.log(JSON.stringify(checkCodecOperationsRecord(JSON.parse(readFileSync(record)),readFileSync(spec),JSON.parse(readFileSync(cpu)))));
}
