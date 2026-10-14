import test from 'node:test';
import assert from 'node:assert/strict';
import {checkActivityDeadlinePollRetries} from '../../scripts/conformance/server-parity/activity-deadline-contract.mjs';

function observed() {
  const body=JSON.stringify({worker_id:'original-schedule_to_start_timeout-workflow-worker',
    task_queue:'original-schedule_to_start_timeout-workflow',poll_request_id:'original-poll',timeout_seconds:0});
  const request=JSON.parse(body);
  const wire={method:'POST',path:'/api/worker/workflow-tasks/poll',status:503,request_body:body,request,
    response_retry_after:'1',request_started_at:100.1,response_observed_at:100.2,
    response:{task:null,reason:'backend_lock_pressure',poll_status:'backend_lock_pressure',namespace:'default',
      task_queue:request.task_queue,task_kind:'workflow_task',retry_after_seconds:1,backend:{lock_pressure:true}}};
  return {state:{overall_budget_seconds:35,observer_started_at:100,observer_deadline:135,poll_retries:[{
    poll_request_id:'original-poll',worker_id:request.worker_id,task_queue:request.task_queue,task_kind:'workflow_task',
    namespace:'default',reason:'backend_lock_pressure',retry_after_seconds:1,failed_at:100.3,retry_at:101.4,observer_deadline:135}]},
    requests:[wire,{...wire,status:200,response:{task:null},request_started_at:101.5,response_observed_at:101.6}]};
}
test('pressure evidence binds unchanged request, actual backoff and original observer deadline',()=>{
  const {state,requests}=observed();checkActivityDeadlinePollRetries(state,requests,'original');
  checkActivityDeadlinePollRetries({poll_retries:[]},[],'old-record-without-pressure');
});
const mutations={
  'new poll identity':v=>v.requests[1].request_body+=' ',
  'wrong namespace':v=>v.requests[0].response.namespace='other',
  'wrong worker':v=>v.requests[0].request.worker_id='other',
  'wrong queue':v=>v.requests[0].response.task_queue='other',
  'wrong task kind':v=>v.requests[0].response.task_kind='activity_task',
  'unrelated error':v=>v.requests[0].response.reason='backend_unavailable',
  'missing retry':v=>v.state.poll_retries=[],
  'invented retry':v=>v.state.poll_retries.push(v.state.poll_retries[0]),
  'missing real retry request':v=>v.requests.pop(),
  'backoff skipped':v=>v.state.poll_retries[0].retry_at=100.5,
  'wire retry too early':v=>v.requests[1].request_started_at=100.5,
  'header disagrees':v=>v.requests[0].response_retry_after='0',
  'observer rebudgeted':v=>v.state.observer_deadline=136,
  'retry rebudgeted':v=>v.state.poll_retries[0].observer_deadline=136,
  'late response':v=>v.requests[1].response_observed_at=136,
  'unknown claim':v=>v.requests[0].response.task={task_id:'other'},
};
for(const[name,mutate]of Object.entries(mutations))test(`pressure reader refuses ${name}`,()=>{
  const value=observed();mutate(value);
  assert.throws(()=>checkActivityDeadlinePollRetries(value.state,value.requests,'original'));
});
