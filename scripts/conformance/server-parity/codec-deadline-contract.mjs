import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';

export function checkCodecDeadlineRecord(record,specBytes,cpuEvidence) {
  const spec=JSON.parse(specBytes),hash=createHash('sha256').update(specBytes).digest('hex');
  assert.equal(record.schema,spec.schema);
  assert.match(record.runner_revision,/^[a-f0-9]{40}$/);
  assert.equal(record.specification_sha256,hash);
  const original=record.original;
  assert.equal(original.outcome,'observed','Actual original deadline observation');
  assert.equal(original.specification_sha256,hash);
  assert.equal(original.sdk_version,record.artifacts.sdk_php);
  assert.equal(original.sdk_source,record.artifacts.sdk_php_source_commit);
  assert.equal(original.dense.row_count,spec.rows);
  assert.match(original.dense.decoded_sha256,/^[a-f0-9]{64}$/);
  assert.equal(original.dense.after_decoded_sha256,original.dense.decoded_sha256);
  assert.equal(original.admission.recorded,true);
  assert(Math.abs(original.observer_deadline-original.observer_started_at-spec.observer_budget_seconds)<0.000001);
  assert(original.observer_finished_at<original.observer_deadline);
  assert(original.observer_started_at-original.admission_observed_at<=spec.maximum_handoff_delay_seconds);
  assert(original.late_observed_at>=original.admission_observed_at+spec.late_outcome_delay_seconds);
  const policy={snapshot_version:1,max_attempts:1,backoff_seconds:[1,2,5,10,15,30,60,120],
    non_retryable_error_types:[],start_to_close_timeout:null,schedule_to_start_timeout:null,
    schedule_to_close_timeout:spec.activity_schedule_to_close_seconds,heartbeat_timeout:null};
  const types=['StartAccepted','WorkflowStarted','ActivityScheduled','ActivityStarted','ActivityTimedOut'];
  assert.deepEqual(original.before.events.map(event=>event.event_type),types.slice(0,4));
  assert.deepEqual(original.after.events.map(event=>event.event_type),types);
  assert.deepEqual(original.after.events.slice(0,4),original.before.events,'Whole original history prefix');
  const activity=original.activity_task;
  assert.equal(activity.workflow_id,original.workflow_id);assert.equal(activity.run_id,original.run_id);
  assert.equal(activity.attempt_number,1);assert.deepEqual(activity.retry_policy,policy);
  for(const snapshot of [original.before,original.after]) {
    assert.equal(snapshot.run_id,original.run_id);assert.equal(snapshot.workflow_id,original.workflow_id);
    assert.deepEqual(snapshot.typed_input,{type:'list',value:[spec.typed_row]});
    for(const field of ['run_deadline_at','execution_deadline_at'])assert.equal(snapshot.execution[field],original.before.execution[field]);
  }
  const scheduled=original.after.events[2],started=original.after.events[3],terminal=original.after.events[4];
  assert.deepEqual(scheduled.payload.activity.retry_policy,policy);
  assert.deepEqual(scheduled.payload.activity.arguments,original.command.arguments);
  assert.deepEqual(scheduled.typed_decoded.activity_arguments,{type:'list',value:[spec.typed_row]});
  assert.equal(started.payload.activity_attempt_id,activity.activity_attempt_id);
  assert.equal(terminal.payload.activity_attempt_id,activity.activity_attempt_id);
  assert.equal(terminal.payload.activity_execution_id,activity.activity_execution_id);
  assert.equal(terminal.payload.timeout_kind,'schedule_to_close');
  assert.deepEqual(terminal.payload.activity.retry_policy,policy);
  assert.equal(original.status_before.can_continue,true);assert.equal(original.status_after.can_continue,false);
  assert.equal(original.status_before.heartbeat_recorded,false);assert.equal(original.status_after.heartbeat_recorded,false);
  assert.deepEqual(original.late_outcomes.map(outcome=>outcome.id),['complete','fail','heartbeat']);
  for(const outcome of original.late_outcomes.slice(0,2))assert.equal(outcome.status,409,'Elapsed original authority refuses outcome');
  const heartbeat=original.late_outcomes[2];assert([200,409].includes(heartbeat.status));
  if(heartbeat.status===200){assert.equal(heartbeat.response.can_continue,false);assert.equal(heartbeat.response.heartbeat_recorded,false);}
  assert.equal(original.extra_activity_poll,null);
  const parent=original.parent_delivery;
  assert.equal(parent.workflow_id,original.workflow_id);assert.equal(parent.run_id,original.run_id);
  assert.notEqual(parent.task_id,original.workflow_task.task_id);assert.equal(parent.workflow_task_attempt,1);
  assert.deepEqual(parent.history_events.map(event=>event.event_type),types);
  for(const[index,event]of parent.history_events.entries()) {
    assert.equal(event.sequence,index+1);
    assert.equal(event.recorded_at,original.after.events[index].timestamp);
    assert.deepEqual(event.payload,original.after.events[index].payload);
  }
  for(const role of ['load','health']) {
    const wave=record[role];assert.equal(wave.specification_sha256,hash);
    assert.equal(wave.declared_wave_seconds,spec.wave_seconds);
    assert.equal(wave.readers.length,role==='load'?spec.readers:1);
    assert(Date.parse(wave.started_at)/1000<=original.late_observed_at);
    assert(Date.parse(wave.finished_at)/1000>=original.late_observed_at,'Original expiry was observed during the wave');
    for(const outcome of original.late_outcomes) {
      assert(outcome.submitted_at>=original.admission_observed_at+spec.late_outcome_delay_seconds);
      assert(Date.parse(wave.started_at)/1000<=outcome.submitted_at);
      assert(outcome.observed_at>=outcome.submitted_at);
      assert(Date.parse(wave.finished_at)/1000>=outcome.observed_at,'Every late outcome is observed under load');
    }
    for(const reader of wave.readers) {
      assert(reader.requests>0);assert.equal(reader.successes,reader.requests,'No healthy interpretation of failed reads');
      assert.deepEqual(reader.errors,{});assert.equal(reader.statuses['200'],reader.requests);
      assert(reader.maximum_milliseconds<=spec.request_timeout_seconds*1000);
      assert(reader.hashes.length>0&&reader.hashes.length<=16);
      if(role==='load')assert(reader.bytes>=reader.requests*spec.minimum_dense_response_bytes,'Genuine dense responses');
    }
  }
  assert.deepEqual(cpuEvidence.resource_limits,{nano_cpus:spec.server_cpu_limit*1000000000,
    memory_bytes:spec.server_memory_bytes,memory_swap_bytes:spec.server_memory_bytes},'Actual inspected service limits');
  const cpu=cpuEvidence.samples.filter(sample=>Date.parse(sample.observed_at)>=Date.parse(record.load.started_at)
    &&Date.parse(sample.observed_at)<=Date.parse(record.load.finished_at));
  const busy=cpu.filter(sample=>sample.cpu_percent>=spec.minimum_busy_cpu_percent);
  assert(busy.length>=spec.minimum_busy_cpu_samples,
    'Actual server CPU evidence is required before load qualification');
  assert(busy.some(sample=>Date.parse(sample.observed_at)/1000<=original.late_observed_at
    &&Date.parse(sample.observed_at)/1000>=original.late_observed_at-1.5),'Busy CPU before the original expiry observation');
  assert(busy.some(sample=>Date.parse(sample.observed_at)/1000>=original.late_observed_at
    &&Date.parse(sample.observed_at)/1000<=original.late_observed_at+1.5),'Busy CPU after the original expiry observation');
  assert(cpu.every(sample=>sample.memory_bytes<=spec.server_memory_bytes),'Declared server memory limit');
  return {outcome:'pass',scope:'http_codec_load_and_original_activity_global_deadline',
    busy_cpu_samples:busy.length,original_run_id:original.run_id,parent_task_id:parent.task_id,
    dense_input_sha256:original.dense.decoded_sha256};
}

if(process.argv[1]===new URL(import.meta.url).pathname) {
  const [recordPath,specPath,cpuPath]=process.argv.slice(2);
  console.log(JSON.stringify(checkCodecDeadlineRecord(JSON.parse(readFileSync(recordPath)),readFileSync(specPath),
    JSON.parse(readFileSync(cpuPath)))));
}
