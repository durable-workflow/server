import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync} from 'node:fs';
import {instantNanoseconds} from './contract.mjs';

const map=value=>({type:'map',value});
const list=value=>({type:'list',value});
const integer=value=>({type:'int64',value:String(value)});

export function checkContinueRecord(record,fixture,artifacts,mode='http') {
  const spec=fixture.continuation;
  assert.equal(record.outcome,'observed');
  assert.equal(spec.phase_budget_seconds,30);
  assert.equal(spec.overall_budget_seconds,120);
  assert.deepEqual(Object.keys(record.phases),spec.phase_order);
  const {original,activity,continuation,successor}=record.phases;
  const old=record.predecessor_run_id;
  const next=record.successor_run_id;
  assert.equal(typeof old,'string');
  assert.equal(typeof next,'string');
  assert(old.length>0&&next.length>0&&old!==next,'distinct original successor');
  const memo=map({empty:list([]),keep:fixture.typed_value});
  const pids=[];
  assert(['http','embedded'].includes(mode));
  const definition=createHash('sha256').update(readFileSync(new URL(
    mode==='http'?'./continue-worker.php':'./continue-embedded.php',import.meta.url))).digest('hex');
  for(const [phase,observation] of Object.entries(record.phases)) {
    assert.equal(observation.workflow_id,original.workflow_id);
    assert.equal(observation.workflow_type,fixture.workflow_type);
    assert.equal(observation.namespace,'default');
    assert.equal(observation.run_id,phase==='successor'?next:old);
    assert.equal(observation.task_queue,original.workflow_id+'-workflow');
    assert.equal(observation.status,['original','activity'].includes(phase)?'waiting':'completed');
    assert.deepEqual(observation.typed_memo,memo,'typed memo survives each original phase');
    assert.deepEqual(observation.typed_input,list([map({generation:integer(phase==='successor'?1:0),value:fixture.typed_value})]));
    const actor=observation.actor;
    assert(Number.isSafeInteger(actor.pid)&&actor.pid>0);
    pids.push(actor.pid);
    assert.equal(actor.worker_finished,true);
    assert.equal(actor.run_id,observation.run_id);
    assert.equal(actor.workflow_id,original.workflow_id);
    assert.equal(actor.activity_invocations,phase==='activity'?1:0);
    if(mode==='http') {
      assert.equal(actor.sdk_version,artifacts.sdk_php);
      assert.equal(actor.sdk_source,artifacts.sdk_php_source_commit);
      assert.equal(actor.sdk_composer_lock_sha256,artifacts.sdk_composer_lock_sha256);
      assert.deepEqual(actor.sdk_loaded_sources,artifacts.published_sdk_artifacts.php.loaded_sources);
    } else {
      assert.equal(actor.workflow_package,artifacts.workflow);
      assert.equal(actor.workflow_source,artifacts.workflow_source_commit);
      assert.equal(actor.embedded_composer_lock_sha256,artifacts.embedded_composer_lock_sha256);
      assert.deepEqual(actor.workflow_loaded_sources,artifacts.workflow_loaded_sources);
    }
    assert.equal(actor.definition_sha256,definition);
    if(mode==='http') {
    const kind=phase==='activity'?'activity':'workflow';
    const claim=actor.receipts.find(receipt=>receipt.path===`/api/worker/${kind}-tasks/poll`&&receipt.response.task)?.response.task;
    assert(claim,'actual normal SDK poll delivery');
    assert.equal(claim.run_id,observation.run_id);
    const completion=actor.receipts.find(receipt=>receipt.path===`/api/worker/${kind}-tasks/${claim.task_id}/complete`);
    assert(completion,'completion of the original delivered task');
    assert.equal(completion.status,200);
    assert.equal(completion.response.recorded,true,'original completion commits durable work');
    assert.equal(completion.request.lease_owner,claim.lease_owner);
    if(kind==='workflow') {
      assert.equal(completion.request.workflow_task_attempt,claim.workflow_task_attempt);
      assert.deepEqual(completion.request.commands.map(command=>command.type),[
        {original:'schedule_activity',continuation:'continue_as_new',successor:'complete_workflow'}[phase]]);
    } else assert.equal(completion.request.activity_attempt_id,claim.activity_attempt_id);
    }
    assert.equal(observation.execution.execution_deadline_at,record.initial.execution.execution_deadline_at);
    if(phase!=='successor')assert.equal(observation.execution.run_deadline_at,record.initial.execution.run_deadline_at);
  }
  assert.equal(new Set(pids).size,4,'four independent cold processes');
  assert.deepEqual(activity.events.slice(0,original.events.length),original.events);
  assert.deepEqual(continuation.events.slice(0,activity.events.length),activity.events);
  assert.deepEqual(record.predecessor_final.events,continuation.events);
  assert.deepEqual(continuation.events.map(event=>event.event_type),[
    'StartAccepted','WorkflowStarted','ActivityScheduled','ActivityStarted','ActivityCompleted','WorkflowContinuedAsNew']);
  assert.deepEqual(continuation.events.map(event=>event.sequence),[1,2,3,4,5,6]);
  const successorOrder=spec.successor_event_order[mode];
  assert.deepEqual(successor.events.map(event=>event.event_type),successorOrder);
  assert.deepEqual(successor.events.map(event=>event.sequence),successorOrder.map((_,index)=>index+1));
  assert.deepEqual(successor.typed_output,fixture.typed_value);
  assert.equal(continuation.execution.closed_reason,'continued');
  assert.equal(continuation.execution.run_number,1);
  assert.equal(successor.execution.run_number,2);
  assert.equal(record.inherited.run_id,next);
  assert.equal(record.inherited.status,'pending');
  assert.deepEqual(record.inherited.typed_memo,memo,'inherited before successor claim');
  assert.equal(record.inherited.events.length,successorOrder.length-1);
  assert.deepEqual(successor.events.slice(0,record.inherited.events.length),record.inherited.events);
  const continued=continuation.events.at(-1).payload;
  const started=successor.events.find(event=>event.event_type==='WorkflowStarted').payload;
  assert.equal(continued.sequence,2);
  assert.equal(continued.closed_reason,'continued');
  assert.equal(continued.continued_to_run_id,next);
  assert.equal(continued.continued_to_run_number,2);
  assert.equal(typeof continued.workflow_link_id,'string');
  assert(continued.workflow_link_id.length>0);
  assert.equal(started.workflow_link_id,continued.workflow_link_id);
  assert.equal(started.continued_from_run_id,old);
  assert.equal(started.workflow_run_id,next);
  assert.equal(started.workflow_instance_id,original.workflow_id);
  if(mode==='http')assert.equal(successor.execution.continued_from_run_id,old);
  const scheduled=continuation.events[2].payload;
  for(const event of continuation.events.slice(2,5)) {
    assert.equal(event.payload.sequence,1);
    assert.equal(event.payload.activity_execution_id,scheduled.activity_execution_id);
    assert.equal(event.payload.activity_type,'parity.v1.continue_echo');
    assert.equal(event.payload.task.repair_count,0);
    assert.deepEqual(event.payload.activity.retry_policy,{snapshot_version:1,max_attempts:1,backoff_seconds:[],
      start_to_close_timeout:120,schedule_to_start_timeout:null,schedule_to_close_timeout:600,
      heartbeat_timeout:null,non_retryable_error_types:[]});
  }
  assert.equal(continuation.events[3].payload.attempt_number,1);
  assert.equal(continuation.events[4].payload.attempt_number,1);
  assert.equal(continuation.events[4].payload.activity_attempt_id,continuation.events[3].payload.activity_attempt_id);
  assert.deepEqual(continuation.events[4].typed_decoded.result,fixture.typed_value);
  assert.deepEqual(record.duplicate.before.map(run=>run.events),record.duplicate.after.map(run=>run.events));
  assert.deepEqual(record.duplicate.before.map(run=>run.typed_memo),record.duplicate.after.map(run=>run.typed_memo));
  assert.equal(record.duplicate.current_after.run_id,next);
  const duplicate=record.duplicate.receipt;
  if(mode==='embedded') {
    assert.equal(record.duplicate.delivery,'real_database_queue');
    assert.equal(record.duplicate.task_id,continued.task.id);
    assert(record.duplicate.worker_output.includes('RunWorkflowTask'));
  } else {
  assert.deepEqual(record.duplicate.submitted_request,record.duplicate.request.request,
    'continuation retry includes all original SDK completion metadata');
  if(duplicate.status===409) {
    assert(['run_closed','task_not_leased'].includes(duplicate.response.reason));
    assert.equal(duplicate.response.task_status,'completed');
  } else {
    assert.equal(duplicate.status,200);
    assert.equal(duplicate.response.recorded,false);
  }
  }
  for(const runs of [record.runs_before_duplicate,record.runs_after_duplicate,record.runs_final]) {
    assert.equal(runs.length,2,'exactly two original runs');
    assert.deepEqual(runs.map(run=>run.run_id).sort(),[old,next].sort());
  }
  assert.equal(record.current_final.run_id,next);
  assert.equal(record.current_final.status,'completed');
  const deadline=instantNanoseconds(record.inherited.execution.run_deadline_at);
  const startedAt=instantNanoseconds(record.inherited.execution.started_at);
  // Published describe uses whole seconds for this deadline. Exact stored
  // microseconds are a separate stopped-database control, never rebased here.
  const precision=record.inherited.execution.run_deadline_at.match(/\.(\d+)(?:Z|[+-])/);
  const quantum=10n**BigInt(9-(precision?.[1].length??0));
  const expected=startedAt+BigInt(spec.run_timeout_seconds)*1_000_000_000n;
  assert(deadline<=expected&&expected-deadline<quantum,'published new-run deadline at its displayed precision');
  return {outcome:'pass',workflow_id:original.workflow_id,predecessor_run_id:old,successor_run_id:next,
    phases:4,activity_invocations:1,predecessor_events:6,successor_events:successorOrder.length,duplicate_status:duplicate?.status??null,mode};
}

if(process.argv[1]===new URL(import.meta.url).pathname) {
  const [record,fixture,artifacts]=process.argv.slice(2,5).map(file=>JSON.parse(readFileSync(file)));
  console.log(JSON.stringify(checkContinueRecord(record,fixture,artifacts,process.argv[5]??'http')));
}
