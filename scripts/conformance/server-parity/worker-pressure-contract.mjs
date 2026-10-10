import assert from 'node:assert/strict';

export function checkSdkDatabasePressure(fixture, state, workflowId, token) {
  const sdk=state.sdk_database_pressure;
  assert.ok(sdk && typeof sdk==='object','actual SDK database-pressure evidence');
  assert.equal(sdk.kind,'real_independent_database_write_lock');
  assert.ok(['sqlite','mysql','pgsql'].includes(sdk.backend));
  assert.equal(sdk.bounded_transport,true);
  assert.ok(Number.isFinite(sdk.shutdown_elapsed_seconds) && sdk.shutdown_elapsed_seconds>=4
    && sdk.shutdown_elapsed_seconds<=10,'observed temporary pressure fits one SDK shutdown budget');
  for(const sample of [sdk.reference_php_session_before,sdk.reference_php_session_after]) {
    assert.equal(sample.origin,'php_application_cli_connection','sample origin is not the native pool or HTTP session');
    assert.equal(sample.driver,sdk.backend);
    assert.equal(sample.setting,{sqlite:'busy_timeout',mysql:'innodb_lock_wait_timeout',pgsql:'lock_timeout'}[sdk.backend]);
  }
  const after=sdk.reference_php_session_after.value;
  if(sdk.backend==='sqlite') assert.equal(after,5000);
  if(sdk.backend==='mysql') assert.equal(after,5);
  if(sdk.backend==='pgsql') assert.ok(['5s','5000ms'].includes(after),'actual configured PHP application session sample');
  assert.deepStrictEqual(state.after_pressure,state.before_pressure,'refused SDK shutdown preserves complete original peer read');
  assert.deepStrictEqual(state.before_pressure,state.before_unknown);
  const requests=sdk.requests;
  const path='/api/worker/registrations/'+state.worker_id+'/deregister';
  assert.deepStrictEqual(requests.map(r=>[r.method,r.path,r.status]),[
    ['POST','/api/worker/register',201],['POST',path,503],['POST',path,200],
  ],'real SDK retries original shutdown without polling or refreshing');
  assert.equal(requests[0].namespace,'default');
  assert.match(requests[0].credential_sha256,/^[a-f0-9]{64}$/);
  assert.notEqual(requests[0].credential_sha256,'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
  for(const r of requests.slice(1)) {
    assert.equal(r.registration_token,token);
    assert.equal(r.namespace,requests[0].namespace);
    assert.equal(r.credential_sha256,requests[0].credential_sha256);
    assert.ok(Number.isInteger(r.timeout_seconds) && r.timeout_seconds>=1 && r.timeout_seconds<=10);
  }
  assert.ok(requests[1].elapsed_seconds>=4 && requests[1].elapsed_seconds<=9,'real configured database wait');
  assert.equal(requests[1].retry_after,'1','actual positive Retry-After header');
  assert.ok(requests[2].timeout_seconds<requests[1].timeout_seconds,'retry consumes remaining budget after actual database wait');
  assert.equal(sdk.failures.length,1);
  const failure=sdk.failures[0];
  assert.equal(failure.status,503);
  for(const [key,value] of Object.entries({reason:'backend_lock_pressure',operation:'deregister_worker',worker_id:state.worker_id,
    registration_token:token,outcome:'unknown',retryable:true,retry_after_seconds:1})) assert.equal(failure.response[key],value);
  assert.deepStrictEqual(sdk.diagnostics.filter(d=>d.event==='worker.retrying').map(d=>[d.operation,d.attempt]),[['deregister_worker',1]]);
  assert.equal(sdk.diagnostics.filter(d=>d.event==='worker.deregistered').length,1);
  assert.equal(sdk.diagnostics.filter(d=>d.event==='worker.stopped').length,1);
  assert.equal(sdk.diagnostics.filter(d=>['worker.failed','worker.shutdown_failed','worker.shutdown_retry_unavailable'].includes(d.event)).length,0);
  const a=sdk.authority;
  assert.equal(a.workflow_id,workflowId+'-authority');
  assert.ok(a.run_id && a.run_id!==state.peer_run_id);
  assert.equal(a.task.workflow_id,a.workflow_id);
  assert.equal(a.task.run_id,a.run_id);
  assert.equal(a.task.lease_owner,state.worker_id);
  assert.equal(a.task.workflow_task_attempt,1);
  assert.ok(a.task.task_id && a.task.task_id!==state.original_task.task_id);
  assert.deepStrictEqual(a.after_pressure,a.before,'another original lease survives while the real writer is held');
  assert.equal(a.completion.status,200,'original authority actually completes without refreshing registration');
  assert.equal(a.final.status,'completed');
  assert.deepStrictEqual(a.final.typed_output,fixture.typed_value);
  for(const run of [a.before,a.after_pressure,a.final]) {
    assert.equal(run.workflow_id,a.workflow_id);
    assert.equal(run.run_id,a.run_id);
    assert.equal(run.namespace,'default');
    assert.deepStrictEqual(run.typed_input,{type:'list',value:[fixture.typed_value]});
    for(const event of run.events.filter(e=>e.event_type!=='WorkflowCompleted')) {
      assert.equal(event.payload.workflow_instance_id,a.workflow_id);
      assert.equal(event.payload.workflow_run_id,a.run_id);
    }
  }
  assert.deepStrictEqual(a.final.events.slice(0,2),a.before.events);
  assert.deepStrictEqual(a.final.events.map(e=>e.event_type),['StartAccepted','WorkflowStarted','WorkflowCompleted']);
  const completed=a.final.events.at(-1);
  assert.equal(completed.payload.task.id,a.task.task_id);
  assert.equal(completed.payload.task.attempt_count,1);
  assert.equal(completed.payload.task.repair_count,0);
  assert.deepStrictEqual(completed.typed_decoded.output,fixture.typed_value);
  assert.deepStrictEqual(completed.payload.output,a.final.execution.output_envelope);
}
