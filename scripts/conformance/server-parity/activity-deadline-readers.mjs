import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkActivityDeadlines} from './activity-deadline-contract.mjs';

const record=JSON.parse(readFileSync(process.argv[2]));
assert.equal(record.outcome,'pass','Actual complete elapsed-deadline record');
assert.equal(record.cases.length,1);
const item=record.cases[0], original=item.observation, fixture=item.fixture;
const read=value=>checkActivityDeadlines(fixture,value,original.workflow_id);
read(original);
const mutations={
  'reused original run':value=>{const steps=Object.values(value.activity_deadline_authority.steps);steps[1].run_id=steps[0].run_id;},
  'rebudgeted observer':value=>{value.activity_deadline_authority.overall_budget_seconds+=1;},
  'missing original deadline family':value=>{delete value.activity_deadline_authority.steps.heartbeat_timeout;},
  'missing response bytes':value=>{value.activity_deadline_authority.requests[0].response_bytes_base64='';},
  'cancelled request counted':value=>{value.activity_deadline_authority.requests[0].client_cancelled=true;},
};
const results=[];
for(const field of fixture.activity_deadline_authority.fields){
  const step=value=>value.activity_deadline_authority.steps[field];
  const controls={
    'changed authored timeout':value=>{step(value).command[field]=2;},
    'trimmed complete retry list':value=>{step(value).after_expiry.events[2].payload.activity.retry_policy.backoff_seconds.pop();},
    'duplicate timeout event':value=>{step(value).after_expiry.events.push(structuredClone(step(value).after_expiry.events.at(-1)));},
    'wrong timeout kind':value=>{step(value).after_expiry.events.at(-1).payload.timeout_kind='other';},
    'lost original prefix':value=>{step(value).before_expiry.events[0].payload.workflow_run_id='other';},
    'changed event microclock':value=>{step(value).after_expiry.events[2].timestamp='2000-01-01T00:00:00.000000Z';},
    'wrong parent task':value=>{step(value).parent_delivery.task_id=step(value).workflow_task.task_id;},
    'missing actual parent timeout delivery':value=>{step(value).parent_delivery.history_events.pop();},
    'second parent admission':value=>{step(value).extra_parent_poll=step(value).parent_delivery;},
    'late activity admission':value=>{step(value).post_expiry_activity_poll={task_id:'other'};},
    'original run deadline replaced':value=>{step(value).after_expiry.execution.run_deadline_at='2000-01-01T00:00:00.000000Z';},
    'typed input rounded':value=>{step(value).scheduled.typed_input.value[0].value.integer.value='9007199254740992';},
  };
  if(field!=='schedule_to_start_timeout')Object.assign(controls,{
    'late completion accepted':value=>{step(value).late_outcomes[0].status=200;},
    'late failure accepted':value=>{step(value).late_outcomes[1].status=200;},
    'expired heartbeat recorded':value=>{step(value).late_outcomes[2].status=200;step(value).late_outcomes[2].response.heartbeat_recorded=true;},
    'expired status grants authority':value=>{step(value).status_after_expiry.can_continue=true;},
    'wrong original attempt':value=>{step(value).activity_task.activity_attempt_id='other';},
    'missing actual late completion':value=>{value.activity_deadline_authority.requests=value.activity_deadline_authority.requests.filter(wire=>wire.path!==`/api/worker/activity-tasks/${step(value).activity_task.task_id}/complete`);},
  });
  for(const[name,mutate]of Object.entries(controls)){const value=structuredClone(original);mutate(value);assert.throws(()=>read(value),undefined,`${field}: ${name}`);}
  results.push({field,actual_record:true,mutation_controls:Object.keys(controls)});
}
for(const[name,mutate]of Object.entries(mutations)){const value=structuredClone(original);mutate(value);assert.throws(()=>read(value),undefined,name);}
console.log(JSON.stringify({outcome:'pass',actual_record:true,results,global_controls:Object.keys(mutations)}));
