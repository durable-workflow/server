import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkContinueRecord} from './continue-contract.mjs';

const [record,fixture,artifacts]=process.argv.slice(2,5).map(file=>JSON.parse(readFileSync(file)));
const mode=process.argv[5]??'http';
checkContinueRecord(record,fixture,artifacts,mode);
const started=r=>r.phases.successor.events.find(event=>event.event_type==='WorkflowStarted');
const corruptions=[
  r=>{r.outcome='pending';},
  r=>{r.successor_run_id=r.predecessor_run_id;},
  r=>{r.phases.successor.run_id=r.predecessor_run_id;},
  r=>{r.phases.successor.actor.run_id=r.predecessor_run_id;},
  r=>{r.phases.activity.actor.activity_invocations=2;},
  r=>{r.phases.continuation.actor.pid=r.phases.original.actor.pid;},
  r=>{r.phases.successor.actor[mode==='http'?'sdk_source':'workflow_source']='unqualified';},
  r=>{r.phases.original.actor.definition_sha256='forged';},
  r=>{r.phases.continuation.events[2].payload.activity_execution_id='changed';},
  r=>{r.phases.continuation.events[3].payload.task.repair_count=1;},
  r=>{r.phases.continuation.events[4].payload.attempt_number=2;},
  r=>{r.phases.continuation.events[4].typed_decoded.result.value.integer.value='9007199254740992';},
  r=>{r.phases.continuation.events[5].payload.continued_to_run_id='changed';},
  r=>{started(r).payload.workflow_link_id='changed';},
  r=>{started(r).payload.continued_from_run_id='changed';},
  r=>{r.phases.successor.events.push(structuredClone(r.phases.successor.events[1]));},
  r=>{r.phases.successor.typed_output.value.optional={type:'string',value:'null'};},
  r=>{delete r.inherited.typed_memo.value.keep;},
  r=>{r.inherited.status='completed';},
  r=>{r.phases.continuation.execution.closed_reason='completed';},
  r=>{r.phases.successor.execution.execution_deadline_at='2099-01-01T00:00:00Z';},
  r=>{r.inherited.execution.run_deadline_at='2099-01-01T00:00:00Z';},
  r=>{r.duplicate.current_after.run_id='changed';},
  r=>{if(mode==='http')r.duplicate.receipt.status=500;else r.duplicate.task_id='changed';},
  r=>{r.duplicate.after[0].events.push(structuredClone(r.duplicate.after[0].events[0]));},
  r=>{r.runs_after_duplicate.push(structuredClone(r.runs_after_duplicate[1]));},
  r=>{r.current_final.run_id=r.predecessor_run_id;},
  r=>{if(mode==='http')r.phases.successor.actor.receipts=[];else r.duplicate.delivery='constructed';},
  r=>{if(mode==='http')r.phases.successor.actor.receipts.find(receipt=>receipt.path.endsWith('/complete')).response.recorded=false;else r.duplicate.worker_output='';},
];
if(mode==='http')corruptions.push(r=>{delete r.duplicate.submitted_request.sticky_cache;});
for(const corrupt of corruptions) {
  const changed=structuredClone(record);
  corrupt(changed);
  assert.throws(()=>checkContinueRecord(changed,fixture,artifacts,mode));
}
console.log(JSON.stringify({outcome:'pass',actual_predecessor:record.predecessor_run_id,
  actual_successor:record.successor_run_id,refused_corruptions:corruptions.length,mode}));
