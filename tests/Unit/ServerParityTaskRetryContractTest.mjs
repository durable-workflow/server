import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkTaskRetry} from '../../scripts/conformance/server-parity/task-retry-contract.mjs';

const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/workflow-task-transient-retry.json', import.meta.url)));
function model() {
  const events = fixture.workflow_task_retry.expected_peer_events.map((event_type, i)=>({event_type, sequence:i+1,
    timestamp:'2026-10-10T01:02:03.123456Z', payload:{original:'unchanged'},
    ...(i===2 ? {typed_decoded:{output:fixture.typed_value}} : {})}));
  const before = {workflow_id:'test-retry',run_id:'peer-run',workflow_type:'parity.v1.task_retry',namespace:'default',
    task_queue:'server-parity-v1',payload_codec:'avro',typed_input:{type:'list',value:[fixture.typed_value]},
    typed_output:{type:'null',value:null},status:'pending',events:events.slice(0,2)};
  const task = i=>({task_id:'task-'+i,workflow_id:'test-retry',run_id:'peer-run',lease_owner:'test-retry-worker',workflow_task_attempt:i});
  const refusal = (i, stale=false)=>({status:409,response:{task_id:'task-'+i,workflow_task_attempt:i+(stale?1:0),reason:stale?'workflow_task_attempt_mismatch':'task_not_leased'}});
  const requests = [];
  const steps = fixture.workflow_task_retry.failures.map((failure, index)=>{
    const i=index+1, accepted={task_id:'task-'+i,workflow_task_attempt:i,outcome:'failed',recorded:true,reason:null,next_task_id:'task-'+(i+1)};
    for (const [offset,status] of [409,200,409].entries()) requests.push({method:'POST',namespace:'default',
      path:'/api/worker/workflow-tasks/task-'+i+'/fail',status,
      request:{lease_owner:'test-retry-worker',workflow_task_attempt:i+(offset===0?1:0),failure},
      response:offset===1?structuredClone(accepted):refusal(i,offset===0).response});
    requests.push({path:'/api/worker/workflow-tasks/task-'+i+'/complete',status:409});
    return {task:task(i),next_task:task(i+1),before:structuredClone(before),after_stale:structuredClone(before),
      after_accepted:structuredClone(before),after_refusals:structuredClone(before),
      stale:refusal(i,true),accepted,duplicate:refusal(i),late_completion:refusal(i)};
  });
  const completion={recorded:true,task_id:'task-3',workflow_task_attempt:3};
  requests.push({path:'/api/worker/workflow-tasks/task-3/complete',status:200,
    request:{lease_owner:'test-retry-worker',workflow_task_attempt:3,commands:[{type:'complete_workflow'}]},response:completion});
  requests.push({path:'/api/worker/workflow-tasks/task-3/fail',status:409,
    request:{lease_owner:'test-retry-worker',workflow_task_attempt:3,failure:fixture.workflow_task_retry.failures[0]}});
  const final={...structuredClone(before),status:'completed',typed_output:structuredClone(fixture.typed_value),events:structuredClone(events)};
  return structuredClone({mode:'http',run_id:'main-run',workflow_task_retry:{applicable:true,worker_id:'test-retry-worker',
    peer_workflow_id:'test-retry',peer_run_id:'peer-run',before,steps,last_task:task(3),completion,final,idle_poll:null,
    late_failure:{...refusal(3),response:{...refusal(3).response,reason:'run_closed'}},after_terminal_refusal:structuredClone(final),requests}});
}
test('ordinary task retry model preserves two failures then one original-run completion',()=>
  assert.deepStrictEqual(checkTaskRetry(fixture,model(),'test').attempts,[1,2,3]));
test('embedded explicitly declares the HTTP retry endpoint inapplicable',()=>checkTaskRetry(fixture,
  {mode:'embedded',workflow_task_retry:{applicable:false,reason:'embedded_has_no_http_workflow_task_retry_endpoint'}},'test'));
for (const [label, mutate] of [
  ['missing failure',s=>s.steps.pop()],
  ['new run',s=>s.steps[0].next_task.run_id='other-run'],
  ['reused task',s=>s.steps[0].next_task.task_id=s.steps[0].task.task_id],
  ['reset attempt',s=>s.steps[1].task.workflow_task_attempt=1],
  ['retry pointer differs from actual lease',s=>s.steps[0].accepted.next_task_id='unleased-task'],
  ['failure not recorded',s=>s.steps[0].accepted.recorded=false],
  ['stale report accepted',s=>s.steps[0].stale.status=200],
  ['duplicate accepted',s=>s.steps[0].duplicate.status=200],
  ['late completion accepted',s=>s.steps[0].late_completion.status=200],
  ['history appended by error',s=>s.steps[0].after_accepted.events.push({event_type:'WorkflowFailed'})],
  ['prefix changed',s=>s.steps[1].after_refusals.events[0].timestamp='changed'],
  ['input int64 rounded',s=>s.steps[0].after_accepted.typed_input.value[0].value.count.value='9007199254740992'],
  ['workflow fails instead of retrying',s=>s.steps[0].after_accepted.status='failed'],
  ['leasing changes the published pending projection',s=>s.before.status='running'],
  ['stale failure loses its fencing reason',s=>s.steps[0].stale.response.reason='other-refusal'],
  ['output int64 rounded',s=>s.final.typed_output.value.count.value='9007199254740992'],
  ['different final task',s=>s.last_task.task_id='other-task'],
  ['extra retry after terminal completion',s=>s.idle_poll=s.last_task],
  ['late terminal failure accepted',s=>s.late_failure.status=200],
  ['missing real fail exchange',s=>s.requests.splice(1,1)],
  ['wire receipt differs',s=>s.requests[1].response.next_task_id='other-task'],
  ['wrong wire error',s=>s.requests[1].request.failure.type='OtherError'],
  ['duplicate committed completion',s=>s.requests.push(structuredClone(s.requests.find(r=>r.status===200&&r.path.endsWith('/complete'))))],
]) test('task retry model rejects '+label,()=>{
  const observation=model();mutate(observation.workflow_task_retry);
  assert.throws(()=>checkTaskRetry(fixture,observation,'test'));
});
