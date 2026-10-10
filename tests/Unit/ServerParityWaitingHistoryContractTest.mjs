import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkWaitingHistory} from '../../scripts/conformance/server-parity/waiting-history-contract.mjs';

for (const family of ['type','message']) {
  const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/workflow-waiting-history-'+family+'.json',import.meta.url)));
  function model() {
    const events = fixture.waiting_for_history.expected_peer_events.map((event_type,index)=>({
      event_type, sequence:index+1, timestamp:'2026-10-10T01:02:03Z', payload:{sequence:2,activity_execution_id:'activity-original'},
      ...(index===6?{typed_decoded:{result:fixture.typed_value}}:index===7?{typed_decoded:{output:fixture.typed_value}}:{})
    }));
    const snapshot = {workflow_id:'test-pending',run_id:'peer-run',workflow_type:fixture.waiting_for_history.peer_workflow_type,
      namespace:'default',task_queue:'server-parity-v1',payload_codec:'avro',typed_input:{type:'list',value:[fixture.typed_value]},
      status:'waiting',typed_output:{type:'null',value:null},events:events.slice(0,5)};
    const task = id=>({task_id:id,workflow_id:'test-pending',run_id:'peer-run',lease_owner:'test-waiting-worker',workflow_task_attempt:1});
    const acknowledgement={task_id:'replay-task',workflow_task_attempt:1,outcome:'waiting_for_history',recorded:true,reason:null,next_task_id:null};
    const refusal=(attempt=1)=>({status:409,response:{task_id:'replay-task',workflow_task_attempt:attempt}});
    return structuredClone({mode:'http',run_id:'main-run',waiting_for_history:{
      applicable:true,worker_id:'test-waiting-worker',peer_workflow_id:'test-pending',peer_run_id:'peer-run',
      first_task:task('first-task'),replay_task:task('replay-task'),last_task:task('last-task'),
      before:structuredClone(snapshot),after_stale:structuredClone(snapshot),after_acknowledgement:structuredClone(snapshot),after_refusals:structuredClone(snapshot),
      final:{...structuredClone(snapshot),status:'completed',typed_output:fixture.typed_value,events:structuredClone(events)},
      stale_attempt:refusal(2),duplicate:refusal(),late_completion:refusal(),acknowledgement:structuredClone(acknowledgement),idle_poll:null,
      activity_task:{activity_execution_id:'activity-original',run_id:'peer-run',lease_owner:'test-waiting-worker'},
      requests:[...[409,200,409].map((status,index)=>({method:'POST',path:'/api/worker/workflow-tasks/replay-task/fail',namespace:'default',
        request:{lease_owner:'test-waiting-worker',workflow_task_attempt:index===0?2:1,failure:fixture.waiting_for_history.failure},
        status,response:index===1?structuredClone(acknowledgement):refusal(index===0?2:1).response})),
        {path:'/api/worker/activity-tasks/activity-task/complete',status:200}]
    }});
  }
  test(family+': HTTP model accepts original waiting acknowledgement',()=>assert.equal(checkWaitingHistory(fixture,model(),'test').original_activity_sequence,2));
  test(family+': embedded declares service endpoint inapplicable',()=>checkWaitingHistory(fixture,{mode:'embedded',waiting_for_history:{applicable:false,reason:'embedded_has_no_http_workflow_task_failure_endpoint'}},'test'));
  for (const [label,mutate] of [
    ['missing actual endpoint control',s=>s.applicable=false],
    ['changed replay run',s=>s.replay_task.run_id='replacement-run'],
    ['reused task identity',s=>s.last_task.task_id=s.replay_task.task_id],
    ['changed original int64',s=>s.after_acknowledgement.typed_input.value[0].value.count.value='9007199254740992'],
    ['history mutation at acknowledgement',s=>s.after_acknowledgement.events[0].timestamp='later'],
    ['acknowledgement records a failure',s=>s.after_acknowledgement.events.push({event_type:'WorkflowTaskFailed'})],
    ['acknowledgement closes workflow',s=>s.after_acknowledgement.status='failed'],
    ['stale attempt accepted',s=>s.stale_attempt.status=200],
    ['duplicate accepted',s=>s.duplicate.status=200],
    ['late completion accepted',s=>s.late_completion.status=200],
    ['unsolicited retry',s=>s.idle_poll=s.replay_task],
    ['new task returned by acknowledgement',s=>s.acknowledgement.next_task_id='retry-task'],
    ['wrong acknowledgement attempt',s=>s.acknowledgement.workflow_task_attempt=2],
    ['different original activity',s=>s.activity_task.activity_execution_id='new-activity'],
    ['original activity shifts',s=>s.before.events[3].payload.sequence=3],
    ['final prefix changes',s=>s.final.events[0].payload.sequence=99],
    ['activity outcome int64 rounds',s=>s.final.events[6].typed_decoded.result.value.count.value='9007199254740992'],
    ['workflow outcome int64 rounds',s=>s.final.typed_output.value.count.value='9007199254740992'],
    ['missing real fail exchange',s=>s.requests.splice(1,1)],
    ['different wire failure',s=>s.requests[1].request.failure.type='WorkerFailure'],
    ['wire receipt disagrees',s=>s.requests[1].response.recorded=false],
    ['duplicate activity outcome',s=>s.requests.push(structuredClone(s.requests.at(-1)))],
  ]) {
    test(family+': model rejects '+label,()=>{const observation=model();mutate(observation.waiting_for_history);assert.throws(()=>checkWaitingHistory(fixture,observation,'test'));});
  }
}
