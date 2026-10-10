import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {checkWorkerDeregistration} from '../../scripts/conformance/server-parity/worker-deregistration-contract.mjs';

// Modeled evidence checks cannot qualify actual lifecycle execution.
const fixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/worker-registration-fencing.json', import.meta.url)));
const id = 'fence-reference';
const worker = id+'-fenced-worker';
const peer = id+fixture.worker_deregistration.peer_suffix;
const tokens = ['a','b','c'].map(value => value.repeat(32));
const capability = {schema: fixture.worker_deregistration.schema, supported: true,
  receipt_retention_seconds: 600, endpoint: '/worker/registrations/{workerId}/deregister'};
const protocol = {protocol_version: '1.20', server_capabilities: {worker_deregistration_fencing: capability}};
const event = (type, sequence) => ({event_type: type, sequence, payload: {workflow_instance_id: peer, workflow_run_id: 'peer-run'}});
const start = [event('StartAccepted',1), event('WorkflowStarted',2)];
const repairs = [3,4].map(sequence => ({...event('RepairRequested',sequence), payload: {...event('RepairRequested',sequence).payload,
  workflow_command_id: 'repair-'+sequence, command_type: 'repair', outcome: 'repair_dispatched', task_id: 'original-task', task_type: 'workflow'}}));
const run = events => ({workflow_id: peer, run_id: 'peer-run', workflow_type: fixture.worker_deregistration.peer_workflow_type,
  namespace: 'default', status: 'pending', typed_input: {type: 'list', value: [fixture.typed_value]}, events});
const initial = run(start), first = run([...start,repairs[0]]);
const latest = run([...start,...repairs]);
const output = {codec: 'avro', blob: 'modeled-frame'};
const final = {...latest, status: 'completed', typed_output: fixture.typed_value, execution: {output_envelope: output},
  events: [...latest.events, {event_type: 'WorkflowCompleted', sequence: 5, typed_decoded: {output: fixture.typed_value},
    payload: {output, task: {id: 'original-task', attempt_count: 3, repair_count: 2}}}]};
const registration = token => ({worker_id: worker, namespace: 'default', registered: true, registration_token: token});
const receipt = token => ({worker_id: worker, registration_token: token, outcome: 'deregistered', recovered_workflow_task_count: 1, ...protocol});
const task = attempt => ({task_id: 'original-task', workflow_id: peer, run_id: 'peer-run', lease_owner: worker, workflow_task_attempt: attempt});
const unknown = 'd'.repeat(32);
const failure = (status, reason, token) => ({status, response: {reason, worker_id: worker, registration_token: token, retryable: false}});
const state = {applicable: true, capability, worker_id: worker, peer_workflow_id: peer, peer_run_id: 'peer-run',
  original_registration: registration(tokens[0]), replacement_registration: registration(tokens[1]), latest_registration: registration(tokens[2]),
  heartbeat: {worker_id: worker, acknowledged: true}, replacement_heartbeat: {worker_id: worker, acknowledged: true}, latest_heartbeat: {worker_id: worker, acknowledged: true},
  original_task: task(1), latest_task: task(2), unknown_token: unknown, unknown: failure(404,'worker_registration_token_not_found',unknown),
  superseded: failure(409,'worker_registration_lost_authority',tokens[1]),
  before_unknown: initial, after_unknown: initial, after_original: first, after_stale_original: first,
  before_superseded: first, after_superseded: first, before_replay: first, after_replay: first, after_stale_replay: first,
  after_latest: latest, final, original_receipt: receipt(tokens[0]), replayed_receipt: receipt(tokens[0]), latest_receipt: receipt(tokens[2]),
  stale_original: {status:409,response:{reason:'task_not_leased',task_id:'original-task',workflow_task_attempt:1}},
  stale_after_replay: {status:409,response:{reason:'workflow_task_attempt_mismatch',task_id:'original-task',workflow_task_attempt:1}},
  idle_poll: null, idle_registration: {registration_token: 'e'.repeat(32)},
  idle_receipt: {worker_id:id+'-idle-worker',registration_token:'e'.repeat(32),outcome:'deregistered',recovered_workflow_task_count:0}};
const check = state => checkWorkerDeregistration(fixture, {mode:'http',run_id:'root-run',worker_deregistration:state}, id);
test('modeled lifecycle and explicitly inapplicable embedded observations project equally', () => {
  assert.deepStrictEqual(check(structuredClone(state)), checkWorkerDeregistration(fixture,
    {mode:'embedded',worker_deregistration:{applicable:false,reason:'embedded_has_no_http_worker_registration_lifecycle'}}, id));
});
const mutations = {
  'unexecuted HTTP lifecycle': s => { s.applicable=false; },
  'unsupported negotiated fence': s => { s.capability.supported=false; },
  'different receipt retention': s => { s.capability.receipt_retention_seconds=60; },
  'token does not rotate': s => { s.latest_registration.registration_token=tokens[1]; },
  'foreign registration namespace': s => { s.latest_registration.namespace='foreign'; },
  'unacknowledged heartbeat': s => { s.heartbeat.acknowledged=false; },
  'replacement task identity': s => { s.latest_task.task_id='other-task'; },
  'replacement run identity': s => { s.latest_task.run_id='other-run'; },
  'original attempt reused': s => { s.latest_task.workflow_task_attempt=1; },
  'unknown token acknowledged': s => { s.unknown.status=200; },
  'terminal refusal retryable': s => { s.superseded.response.retryable=true; },
  'superseded receipt affects current token': s => { s.superseded.response.registration_token=tokens[2]; },
  'original peer changed by unknown refusal': s => { s.after_unknown.events.push(event('WorkflowCompleted',3)); },
  'original receipt recovers twice': s => { s.replayed_receipt.recovered_workflow_task_count=2; },
  'replay acknowledges replacement token': s => { s.replayed_receipt.registration_token=tokens[2]; },
  'replay changes original durable history': s => { s.after_replay.events.push(event('RepairRequested',4)); },
  'stale original publication accepted': s => { s.stale_original.status=200; },
  'stale replacement publication accepted': s => { s.stale_after_replay.status=200; },
  'stale publication names another task': s => { s.stale_after_replay.response.task_id='other-task'; },
  'latest receipt has original token': s => { s.latest_receipt.registration_token=tokens[0]; },
  'different original input': s => { s.final.typed_input={type:'null',value:null}; },
  'lost original start history': s => { s.final.events[0].payload.workflow_run_id='other-run'; },
  'missing repair history': s => { s.final.events.splice(2,1); },
  'repair command reused': s => { s.final.events[3].payload.workflow_command_id='repair-3'; },
  'repair recovers another task': s => { s.final.events[3].payload.task_id='other-task'; },
  'wrong typed committed output': s => { s.final.events[4].typed_decoded.output={type:'null',value:null}; },
  'read exposes a different Avro frame': s => { s.final.execution.output_envelope.blob='other-frame'; },
  'SDK completes another task': s => { s.final.events[4].payload.task.id='other-task'; },
  'SDK reuses expired attempt': s => { s.final.events[4].payload.task.attempt_count=1; },
  'original repair count lost': s => { s.final.events[4].payload.task.repair_count=0; },
  'duplicate workflow task remains': s => { s.idle_poll=task(4); },
};
for (const [name, mutate] of Object.entries(mutations)) test(name, () => {
  // Actual JSON recordings contain independent snapshots.
  const s=JSON.parse(JSON.stringify(state));
  mutate(s);
  assert.throws(() => check(s));
});

const liveFixture = JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/worker-registration-live-lease.json', import.meta.url)));
function liveModel() {
  const s = JSON.parse(JSON.stringify(state));
  const initial = {...run(start),typed_input:{type:'list',value:[liveFixture.typed_value]}};
  for (const key of ['before_unknown','after_unknown','after_original','after_stale_original','before_superseded',
    'after_superseded','before_replay','after_replay','after_stale_replay','after_latest','final']) {
    s[key].typed_input = {type:'list',value:[liveFixture.typed_value]};
  }
  s.original_task.workflow_task_attempt=2;
  s.latest_task.workflow_task_attempt=3;
  s.stale_original.response.workflow_task_attempt=2;
  s.stale_after_replay.response.workflow_task_attempt=2;
  s.final.typed_output=liveFixture.typed_value;
  s.final.events.at(-1).typed_decoded.output=liveFixture.typed_value;
  s.final.events.at(-1).payload.task={id:'original-task',attempt_count:4,repair_count:2};
  const initialToken='f'.repeat(32);
  s.live_replacement={initial_registration:registration(initialToken),initial_task:task(1),before_replacement:initial,
    after_replacement:initial,after_superseded:initial,after_stale_before_poll:initial,
    before_stale_after_poll:s.before_unknown,after_stale_after_poll:s.before_unknown,
    superseded:failure(409,'worker_registration_lost_authority',initialToken),
    stale_before_poll:{status:409,response:{reason:'task_not_leased',task_id:'original-task',workflow_task_attempt:1}},
    stale_after_poll:{status:409,response:{reason:'workflow_task_attempt_mismatch',task_id:'original-task',workflow_task_attempt:1}}};
  return JSON.parse(JSON.stringify(s));
}
const checkLive = s => checkWorkerDeregistration(liveFixture,{mode:'http',run_id:'root-run',worker_deregistration:s},id);
test('modeled live lease replacement requires unchanged original history and fencing evidence', () => {
  assert.deepStrictEqual(checkLive(liveModel()),checkWorkerDeregistration(liveFixture,
    {mode:'embedded',worker_deregistration:{applicable:false,reason:'embedded_has_no_http_worker_registration_lifecycle'}},id));
});
const liveMutations={
  'missing live replacement evidence':s=>{delete s.live_replacement;},
  'live token reused':s=>{s.live_replacement.initial_registration.registration_token=tokens[0];},
  'live initial task replaced':s=>{s.live_replacement.initial_task.task_id='foreign-task';},
  'live initial run replaced':s=>{s.live_replacement.initial_task.run_id='foreign-run';},
  'live initial lease owner replaced':s=>{s.live_replacement.initial_task.lease_owner='foreign-worker';},
  'live registration appends repair history':s=>{s.live_replacement.after_replacement.events.push(event('RepairRequested',3));},
  'live superseded token acknowledged':s=>{s.live_replacement.superseded.status=200;},
  'live superseded token retryable':s=>{s.live_replacement.superseded.response.retryable=true;},
  'live old publication accepted before poll':s=>{s.live_replacement.stale_before_poll.status=200;},
  'live old publication accepted after poll':s=>{s.live_replacement.stale_after_poll.status=200;},
  'live old attempt silently reused':s=>{s.original_task.workflow_task_attempt=1;},
  'live refusal mutates full run':s=>{s.live_replacement.after_stale_after_poll.status='completed';},
  'live repair command reused':s=>{s.final.events[3].payload.workflow_command_id='repair-3';},
  'live final repair count lost':s=>{s.final.events.at(-1).payload.task.repair_count=1;},
};
for (const [name,mutate] of Object.entries(liveMutations)) test(name,()=>{
  const s=liveModel();
  mutate(s);
  assert.throws(()=>checkLive(s));
});

const replyFixture=JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/worker-sdk-reply-reconciliation.json',import.meta.url)));
function replyModel() {
  const s=JSON.parse(JSON.stringify(state));
  for(const key of ['before_unknown','after_unknown','after_original','after_stale_original','before_superseded',
    'after_superseded','before_replay','after_replay','after_stale_replay','after_latest','final']) {
    s[key].typed_input={type:'list',value:[replyFixture.typed_value]};
  }
  s.final.typed_output=replyFixture.typed_value;
  s.final.events.at(-1).typed_decoded.output=replyFixture.typed_value;
  const request=(path,status,token=null,timeout=null)=>({method:'POST',path,status,namespace:'default',
    credential_sha256:'a'.repeat(64),registration_token:token,timeout_seconds:timeout});
  const shutdown='/api/worker/registrations/'+worker+'/deregister';
  s.sdk_reply_loss={kind:'client_injected_after_real_http_commit',bounded_transport:true,injected_loss_count:1,
    shutdown_elapsed_seconds:0.5,requests:[request('/api/worker/register',201),request(shutdown,200,tokens[0],9),request(shutdown,200,tokens[0],9)],
    diagnostics:[{event:'worker.retrying',operation:'deregister_worker',attempt:1},{event:'worker.deregistered'},{event:'worker.stopped'}]};
  return JSON.parse(JSON.stringify(s));
}
const checkReply=s=>checkWorkerDeregistration(replyFixture,{mode:'http',run_id:'root-run',worker_deregistration:s},id);
test('modeled SDK reply reconciliation retains its real lifecycle and explicit client fault origin',()=>{
  assert.deepStrictEqual(checkReply(replyModel()),checkWorkerDeregistration(replyFixture,
    {mode:'embedded',worker_deregistration:{applicable:false,reason:'embedded_has_no_http_worker_registration_lifecycle'}},id));
});
const replyMutations={
  'missing SDK reconciliation evidence':s=>{delete s.sdk_reply_loss;},
  'client fault mislabeled as TCP disconnect':s=>{s.sdk_reply_loss.kind='tcp_disconnect';},
  'SDK has unbounded transport':s=>{s.sdk_reply_loss.bounded_transport=false;},
  'SDK fault never injected':s=>{s.sdk_reply_loss.injected_loss_count=0;},
  'SDK fault injected repeatedly':s=>{s.sdk_reply_loss.injected_loss_count=2;},
  'SDK shutdown exceeds its budget':s=>{s.sdk_reply_loss.shutdown_elapsed_seconds=11;},
  'SDK resumes task polling':s=>{s.sdk_reply_loss.requests.push({method:'POST',path:'/api/worker/workflow-tasks/poll'});},
  'SDK refreshes its incarnation':s=>{s.sdk_reply_loss.requests[2].path='/api/worker/register';},
  'SDK first shutdown never commits':s=>{s.sdk_reply_loss.requests[1].status=503;},
  'SDK retry receives terminal refusal':s=>{s.sdk_reply_loss.requests[2].status=409;},
  'SDK retry changes token':s=>{s.sdk_reply_loss.requests[2].registration_token=tokens[2];},
  'SDK retry changes namespace':s=>{s.sdk_reply_loss.requests[2].namespace='foreign';},
  'SDK retry changes credential':s=>{s.sdk_reply_loss.requests[2].credential_sha256='b'.repeat(64);},
  'SDK retry is unbounded':s=>{s.sdk_reply_loss.requests[2].timeout_seconds=null;},
  'SDK retry increases request timeout':s=>{s.sdk_reply_loss.requests[2].timeout_seconds=10;},
  'SDK retry diagnostic missing':s=>{s.sdk_reply_loss.diagnostics.shift();},
  'SDK silently hides shutdown failure':s=>{s.sdk_reply_loss.diagnostics.push({event:'worker.shutdown_failed'});},
};
for(const [name,mutate] of Object.entries(replyMutations)) test(name,()=>{
  const s=replyModel();mutate(s);assert.throws(()=>checkReply(s));
});

const pressureFixture=JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/worker-sdk-database-pressure.json',import.meta.url)));
function pressureModel() {
  const s=replyModel();
  delete s.sdk_reply_loss;
  for(const key of ['before_unknown','after_unknown','after_original','after_stale_original','before_superseded',
    'after_superseded','before_replay','after_replay','after_stale_replay','after_latest','final']) {
    s[key].typed_input={type:'list',value:[pressureFixture.typed_value]};
  }
  s.final.typed_output=pressureFixture.typed_value;
  s.final.events.at(-1).typed_decoded.output=pressureFixture.typed_value;
  s.before_pressure=s.before_unknown;
  s.after_pressure=s.before_unknown;
  const request=(path,status,timeout=null)=>({method:'POST',path,status,namespace:'default',credential_sha256:'a'.repeat(64),
    registration_token:timeout===null?null:tokens[0],timeout_seconds:timeout,elapsed_seconds:status===503?5:0.1,retry_after:status===503?'1':''});
  const authorityId=id+'-authority';
  const before={workflow_id:authorityId,run_id:'authority-run',namespace:'default',status:'pending',
    typed_input:{type:'list',value:[pressureFixture.typed_value]},events:start.map(e=>({...e,payload:{workflow_instance_id:authorityId,workflow_run_id:'authority-run'}}))};
  const completed={...before,status:'completed',typed_output:pressureFixture.typed_value,execution:{output_envelope:output},
    events:[...before.events,{event_type:'WorkflowCompleted',sequence:3,typed_decoded:{output:pressureFixture.typed_value},
      payload:{output,task:{id:'authority-task',attempt_count:1,repair_count:0}}}]};
  s.sdk_database_pressure={kind:'real_independent_database_write_lock',backend:'sqlite',bounded_transport:true,shutdown_elapsed_seconds:6.5,
    reference_php_session_before:{origin:'php_application_cli_connection',driver:'sqlite',setting:'busy_timeout',value:5000},
    reference_php_session_after:{origin:'php_application_cli_connection',driver:'sqlite',setting:'busy_timeout',value:5000},
    requests:[request('/api/worker/register',201),request('/api/worker/registrations/'+worker+'/deregister',503,9),
      request('/api/worker/registrations/'+worker+'/deregister',200,3)],
    failures:[{status:503,response:{reason:'backend_lock_pressure',operation:'deregister_worker',worker_id:worker,
      registration_token:tokens[0],outcome:'unknown',retryable:true,retry_after_seconds:1}}],
    diagnostics:[{event:'worker.retrying',operation:'deregister_worker',attempt:1},{event:'worker.deregistered'},{event:'worker.stopped'}],
    authority:{workflow_id:authorityId,run_id:'authority-run',task:{...task(1),workflow_id:authorityId,run_id:'authority-run',task_id:'authority-task'},
      before,after_pressure:before,completion:{status:200,response:{}},final:completed}};
  return JSON.parse(JSON.stringify(s));
}
const checkPressure=s=>checkWorkerDeregistration(pressureFixture,{mode:'http',run_id:'root-run',worker_deregistration:s},id);
test('modeled SQLite immediate real contention refusal still consumes SDK retry budget',()=>{
  const s=pressureModel();const sdk=s.sdk_database_pressure;
  sdk.shutdown_elapsed_seconds=1.38;sdk.requests[1].elapsed_seconds=0.05;
  sdk.requests[2].timeout_seconds=8;checkPressure(s);
});
test('modeled real SDK pressure evidence requires original authority completion and bounded same-token retry',()=>{
  assert.deepStrictEqual(checkPressure(pressureModel()),checkWorkerDeregistration(pressureFixture,
    {mode:'embedded',worker_deregistration:{applicable:false,reason:'embedded_has_no_http_worker_registration_lifecycle'}},id));
});
for(const backend of ['mysql','pgsql']) test('modeled declared fault limit and separately labeled '+backend+' session samples',()=>{
  const s=pressureModel();const sdk=s.sdk_database_pressure;sdk.backend=backend;
  sdk.reference_php_session_before={origin:'php_application_cli_connection',driver:backend,
    setting:backend==='mysql'?'innodb_lock_wait_timeout':'lock_timeout',value:backend==='mysql'?50:'0'};
  sdk.reference_php_session_after={...sdk.reference_php_session_before,value:backend==='mysql'?5:'5s'};
  checkPressure(s);
  sdk.requests[1].elapsed_seconds=0.05;
  assert.throws(()=>checkPressure(s),'row-lock wait floor remains required for '+backend);
});
const pressureMutations={
  'pressure never executed':s=>{delete s.sdk_database_pressure;},
  'pressure is a mocked failure':s=>{s.sdk_database_pressure.kind='injected_transport_error';},
  'pressure exceeds shutdown budget':s=>{s.sdk_database_pressure.shutdown_elapsed_seconds=11;},
  'pressure response duration is unmeasured':s=>{s.sdk_database_pressure.requests[1].elapsed_seconds=0;},
  'pressure response was success':s=>{s.sdk_database_pressure.requests[1].status=200;},
  'pressure header has no retry delay':s=>{s.sdk_database_pressure.requests[1].retry_after='0';},
  'pressure retry resets its budget':s=>{s.sdk_database_pressure.requests[2].timeout_seconds=9;},
  'pressure retry refreshes registration':s=>{s.sdk_database_pressure.requests[2].path='/api/worker/register';},
  'pressure retry changes token':s=>{s.sdk_database_pressure.requests[2].registration_token=tokens[2];},
  'pressure retry changes namespace':s=>{s.sdk_database_pressure.requests[2].namespace='other';},
  'pressure retry changes credential':s=>{s.sdk_database_pressure.requests[2].credential_sha256='b'.repeat(64);},
  'pressure terminal refusal retried':s=>{s.sdk_database_pressure.failures[0].status=409;},
  'pressure unknown outcome hidden':s=>{s.sdk_database_pressure.failures[0].response.outcome='deregistered';},
  'pressure old authority silently replaced':s=>{s.sdk_database_pressure.authority.task.workflow_task_attempt=2;},
  'pressure changes original peer':s=>{s.after_pressure.status='completed';},
  'pressure changes another original history':s=>{s.sdk_database_pressure.authority.after_pressure.events.push(event('RepairRequested',3));},
  'pressure original attempt never completes':s=>{s.sdk_database_pressure.authority.completion.status=409;},
  'pressure original authority completes another task':s=>{s.sdk_database_pressure.authority.final.events.at(-1).payload.task.id='foreign-task';},
  'pressure original authority repaired':s=>{s.sdk_database_pressure.authority.final.events.at(-1).payload.task.repair_count=1;},
  'pressure original typed output changed':s=>{s.sdk_database_pressure.authority.final.typed_output={type:'null',value:null};},
  'pressure session sample mislabeled':s=>{s.sdk_database_pressure.reference_php_session_after.origin='native_pool';},
  'pressure session not configured':s=>{s.sdk_database_pressure.reference_php_session_after.value=0;},
  'pressure SDK failure hidden':s=>{s.sdk_database_pressure.diagnostics.push({event:'worker.shutdown_failed'});},
};
for(const [name,mutate] of Object.entries(pressureMutations)) test(name,()=>{
  const s=pressureModel();mutate(s);assert.throws(()=>checkPressure(s));
});

const persistentFixtures=[false,true].map(original=>JSON.parse(readFileSync(new URL('../Fixtures/ServerParityPending/'+
  (original?'worker-sdk-original-failure':'worker-sdk-persistent-pressure')+'.json',import.meta.url))));
function persistentModel(fixture=persistentFixtures[0]) {
  const s=JSON.parse(JSON.stringify(pressureModel()).replaceAll(JSON.stringify(pressureFixture.input.message),JSON.stringify(fixture.input.message)));
  const source=s.sdk_database_pressure;delete s.sdk_database_pressure;
  const original=fixture.worker_deregistration.original_worker_failure;
  const shutdownError={class:'DurableWorkflow\\Exception\\TransportException',status:null,reason:null,same_as_returned_error:!original};
  const returned=original?{class:'DurableWorkflow\\Exception\\ServerException',status:401,reason:'unauthorized',same_as_returned_error:true}:shutdownError;
  const requests=[source.requests[0],...[9,8,7,6,5,3].map(timeout=>({...source.requests[1],timeout_seconds:timeout,elapsed_seconds:0.05}))];
  if(original) requests.splice(1,0,{method:'POST',path:'/api/worker/workflow-tasks/poll',status:401,namespace:'default',timeout_seconds:null,
    credential_sha256:createHash('sha256').update('Bearer parity-intentionally-invalid-fixture-credential').digest('hex'),elapsed_seconds:0.05});
  s.sdk_persistent_pressure={kind:'real_independent_database_write_lock_held_until_sdk_returns',backend:'sqlite',bounded_transport:true,
    shutdown_elapsed_seconds:9.1,lock_release:'after_sdk_return_before_manual_reconciliation',original_worker_failure:original,
    request_fault:original?'one_actual_sdk_workflow_poll_with_invalid_fixture_credential':null,
    reference_php_session_before:source.reference_php_session_before,reference_php_session_after:source.reference_php_session_after,
    requests,failures:Array.from({length:6},()=>source.failures[0]),sdk_receipt:null,returned_error:returned,
    diagnostics:[...(original?[{event:'worker.failed',error:returned}]:[]),
      ...Array.from({length:6},(_,i)=>({event:'worker.retrying',operation:'deregister_worker',attempt:i+1})),
      {event:'worker.shutdown_failed',error:shutdownError},...(original?[{event:'worker.stopped'}]:[])],
    manual_reconciliation:{status:200,response:s.original_receipt},manual_replay:{status:200,response:s.original_receipt},
    before_manual_replay:s.after_original,after_manual_replay:s.after_original};
  return JSON.parse(JSON.stringify(s));
}
const checkPersistent=(s,fixture=persistentFixtures[0])=>checkWorkerDeregistration(fixture,
  {mode:'http',run_id:'root-run',worker_deregistration:s},id);
for(const fixture of persistentFixtures) test('modeled persistent budget exhaustion and original-error precedence '+fixture.id,()=>{
  assert.deepStrictEqual(checkPersistent(persistentModel(fixture),fixture),checkWorkerDeregistration(fixture,
    {mode:'embedded',worker_deregistration:{applicable:false,reason:'embedded_has_no_http_worker_registration_lifecycle'}},id));
});
for(const backend of ['mysql','pgsql']) test('modeled persistent '+backend+' second request I/O timeout is an uncertain outcome',()=>{
  const s=persistentModel(),p=s.sdk_persistent_pressure;p.backend=backend;
  p.reference_php_session_before={origin:'php_application_cli_connection',driver:backend,
    setting:backend==='mysql'?'innodb_lock_wait_timeout':'lock_timeout',value:backend==='mysql'?50:'0'};
  p.reference_php_session_after={...p.reference_php_session_before,value:backend==='mysql'?5:'5s'};
  p.requests=[p.requests[0],{...p.requests[1],elapsed_seconds:5},
    {...p.requests[2],status:0,timeout_seconds:3,elapsed_seconds:3,retry_after:''}];
  p.failures=[p.failures[0],{status:null,response:null}];
  p.diagnostics=p.diagnostics.filter(d=>d.event!=='worker.retrying'||d.attempt===1);
  checkPersistent(s);
  p.failures[1].response={outcome:'rolled_back'};assert.throws(()=>checkPersistent(s));
});
const firstFence=s=>s.sdk_persistent_pressure.requests.find(r=>r.path.endsWith('/deregister'));
const persistentMutations={
  'persistent pressure absent':s=>{delete s.sdk_persistent_pressure;},
  'persistent lock is a mock':s=>{s.sdk_persistent_pressure.kind='mock_sql_error';},
  'persistent lock released before SDK failure':s=>{s.sdk_persistent_pressure.lock_release='before_retry';},
  'persistent I/O unbounded':s=>{s.sdk_persistent_pressure.bounded_transport=false;},
  'persistent budget restarted':s=>{s.sdk_persistent_pressure.shutdown_elapsed_seconds=11;},
  'persistent failure did not spend budget':s=>{s.sdk_persistent_pressure.shutdown_elapsed_seconds=1;},
  'persistent CLI session origin hidden':s=>{s.sdk_persistent_pressure.reference_php_session_after.origin='native_pool';},
  'persistent declared limit not configured':s=>{s.sdk_persistent_pressure.reference_php_session_after.value=0;},
  'persistent refusal changed original history':s=>{s.after_pressure.events.push(event('RepairRequested',3));},
  'persistent SDK secretly succeeded':s=>{firstFence(s).status=200;},
  'persistent terminal response retried':s=>{firstFence(s).status=409;},
  'persistent retry changes token':s=>{firstFence(s).registration_token=tokens[2];},
  'persistent retry changes namespace':s=>{firstFence(s).namespace='foreign';},
  'persistent retry changes credential':s=>{firstFence(s).credential_sha256='b'.repeat(64);},
  'persistent reply omits Retry-After':s=>{firstFence(s).retry_after='';},
  'persistent retry target lost':s=>{s.sdk_persistent_pressure.failures[0].response.worker_id='other';},
  'persistent unknown response called success':s=>{s.sdk_persistent_pressure.failures[0].response.outcome='deregistered';},
  'persistent timeouts reset':s=>{s.sdk_persistent_pressure.requests.at(-1).timeout_seconds=9;},
  'persistent I/O timeout absent':s=>{firstFence(s).timeout_seconds=null;},
  'persistent I/O exceeds remaining budget':s=>{firstFence(s).elapsed_seconds=10;},
  'persistent resumes registration':s=>{s.sdk_persistent_pressure.requests.push({method:'POST',path:'/api/worker/register'});},
  'persistent acknowledges failure':s=>{s.sdk_persistent_pressure.sdk_receipt=s.original_receipt;},
  'persistent shutdown diagnostic hidden':s=>{s.sdk_persistent_pressure.diagnostics=s.sdk_persistent_pressure.diagnostics.filter(d=>d.event!=='worker.shutdown_failed');},
  'persistent original exception precedence reversed':s=>{s.sdk_persistent_pressure.diagnostics.find(d=>d.event==='worker.shutdown_failed').error.same_as_returned_error=
    !s.sdk_persistent_pressure.diagnostics.find(d=>d.event==='worker.shutdown_failed').error.same_as_returned_error;},
  'persistent manual reconciliation failed':s=>{s.sdk_persistent_pressure.manual_reconciliation.status=503;},
  'persistent manual replay repairs twice':s=>{s.sdk_persistent_pressure.manual_replay.response.recovered_workflow_task_count=2;},
  'persistent manual replay changes original read':s=>{s.sdk_persistent_pressure.after_manual_replay.events.push(event('RepairRequested',4));},
};
for(const fixture of persistentFixtures) for(const [name,mutate] of Object.entries(persistentMutations)) test(name+' '+fixture.id,()=>{
  const s=persistentModel(fixture);mutate(s);assert.throws(()=>checkPersistent(s,fixture));
});
test('modeled original worker error requires actual authentication refusal and exact returned exception',()=>{
  const fixture=persistentFixtures[1];
  for(const mutate of [s=>{s.sdk_persistent_pressure.requests[1].status=200;},
    s=>{s.sdk_persistent_pressure.request_fault='injected_exception';},
    s=>{s.sdk_persistent_pressure.returned_error.status=503;},
    s=>{s.sdk_persistent_pressure.diagnostics.find(d=>d.event==='worker.failed').error.same_as_returned_error=false;}]) {
    const s=persistentModel(fixture);mutate(s);assert.throws(()=>checkPersistent(s,fixture));
  }
});
