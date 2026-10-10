import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';

export function checkSdkPersistentPressure(fixture,state,workflowId,token) {
  const p=state.sdk_persistent_pressure, original=fixture.worker_deregistration.original_worker_failure;
  assert.ok(p && typeof p==='object','persistent SDK pressure actually executed');
  assert.equal(p.kind,'real_independent_database_write_lock_held_until_sdk_returns');
  assert.equal(p.lock_release,'after_sdk_return_before_manual_reconciliation');
  assert.equal(p.original_worker_failure,original);
  assert.equal(p.bounded_transport,true);
  assert.ok(['sqlite','mysql','pgsql'].includes(p.backend));
  assert.ok(Number.isFinite(p.shutdown_elapsed_seconds) && p.shutdown_elapsed_seconds>=8
    && p.shutdown_elapsed_seconds<=10,'one real SDK budget expires while pressure stays held');
  for(const sample of [p.reference_php_session_before,p.reference_php_session_after]) {
    assert.equal(sample.origin,'php_application_cli_connection');
    assert.equal(sample.driver,p.backend);
    assert.equal(sample.setting,{sqlite:'busy_timeout',mysql:'innodb_lock_wait_timeout',pgsql:'lock_timeout'}[p.backend]);
  }
  const after=p.reference_php_session_after.value;
  if(p.backend==='sqlite') assert.equal(after,5000);
  if(p.backend==='mysql') assert.equal(after,5);
  if(p.backend==='pgsql') assert.ok(['5s','5000ms'].includes(after));
  assert.deepStrictEqual(state.before_pressure,state.before_unknown);
  assert.deepStrictEqual(state.after_pressure,state.before_pressure,'first actual refusal preserves full original peer while writer is held');
  const [registration,...rest]=p.requests;
  assert.deepStrictEqual([registration.method,registration.path,registration.status],['POST','/api/worker/register',201]);
  assert.equal(registration.namespace,'default');
  assert.match(registration.credential_sha256,/^[a-f0-9]{64}$/);
  if(original) {
    assert.equal(p.request_fault,'one_actual_sdk_workflow_poll_with_invalid_fixture_credential');
    const poll=rest.shift();
    assert.deepStrictEqual([poll.method,poll.path,poll.status],['POST','/api/worker/workflow-tasks/poll',401]);
    assert.equal(poll.namespace,registration.namespace);
    assert.equal(poll.credential_sha256,createHash('sha256').update('Bearer parity-intentionally-invalid-fixture-credential').digest('hex'));
    assert.notEqual(poll.credential_sha256,registration.credential_sha256);
    // This ordinary authentication-fault poll precedes shutdown. Its adapter
    // timeout may be absent; only the fenced shutdown spends the new budget.
    assert.ok(poll.timeout_seconds===null || (Number.isInteger(poll.timeout_seconds)
      && poll.timeout_seconds>=1 && poll.timeout_seconds<=65));
  } else assert.equal(p.request_fault,null);
  assert.ok(rest.length>=2 && rest.length<=10,'SDK actually retries bounded shutdown before failing');
  assert.equal(p.failures.length,rest.length);
  assert.equal(rest[0].status,503,'first refusal is actual HTTP contention');
  for(const [i,r] of rest.entries()) {
    assert.equal(r.method,'POST');
    assert.equal(r.path,'/api/worker/registrations/'+state.worker_id+'/deregister');
    assert.equal(r.registration_token,token);
    assert.equal(r.namespace,registration.namespace);
    assert.equal(r.credential_sha256,registration.credential_sha256);
    assert.ok(Number.isInteger(r.timeout_seconds) && r.timeout_seconds>=1 && r.timeout_seconds<=10);
    assert.ok(Number.isFinite(r.elapsed_seconds) && r.elapsed_seconds>0 && r.elapsed_seconds<=r.timeout_seconds+0.5);
    if(i) assert.ok(r.timeout_seconds<rest[i-1].timeout_seconds,'each retry consumes the original remaining budget');
    assert.ok([503,0].includes(r.status),'no SDK success, terminal refusal or new work hidden in shutdown');
    const failure=p.failures[i];
    assert.equal(failure.status??0,r.status);
    if(r.status===503) {
      assert.equal(r.retry_after,'1');
      for(const [key,value] of Object.entries({reason:'backend_lock_pressure',operation:'deregister_worker',worker_id:state.worker_id,
        registration_token:token,outcome:'unknown',retryable:true,retry_after_seconds:1})) assert.equal(failure.response[key],value);
    } else assert.equal(failure.response,null,'bounded I/O timeout is not a known server outcome');
  }
  if(p.backend!=='sqlite') assert.ok(rest[0].elapsed_seconds>=4,'real configured row-lock wait');
  assert.equal(p.sdk_receipt,null,'persistent failure is not acknowledged as deregistration');
  const retries=p.diagnostics.filter(d=>d.event==='worker.retrying');
  assert.ok(retries.length>=1 && retries.length<=rest.length);
  assert.deepStrictEqual(retries.map(d=>[d.operation,d.attempt]),retries.map((_,i)=>['deregister_worker',i+1]));
  const shutdown=p.diagnostics.filter(d=>d.event==='worker.shutdown_failed');
  assert.equal(shutdown.length,1,'shutdown failure remains visible');
  assert.equal(p.diagnostics.filter(d=>d.event==='worker.deregistered').length,0);
  assert.equal(p.diagnostics.filter(d=>d.event==='worker.shutdown_retry_unavailable').length,0);
  const failed=p.diagnostics.filter(d=>d.event==='worker.failed');
  assert.equal(failed.length,original?1:0);
  assert.equal(p.diagnostics.filter(d=>d.event==='worker.stopped').length,original?1:0);
  for(const e of [p.returned_error,shutdown[0].error]) assert.match(e.class,/^DurableWorkflow\\Exception\\(?:ServerException|TransportException)$/);
  assert.equal(p.returned_error.same_as_returned_error,true);
  assert.equal(shutdown[0].error.same_as_returned_error,!original);
  if(original) {
    assert.equal(p.returned_error.status,401);
    assert.deepStrictEqual(failed[0].error,p.returned_error,'the exact original worker exception survives shutdown failure');
  } else assert.deepStrictEqual(shutdown[0].error,p.returned_error);
  assert.equal(p.manual_reconciliation.status,200);
  assert.deepStrictEqual(p.manual_reconciliation.response,state.original_receipt,'manual reconciliation uses the original token after SDK failure');
  assert.equal(p.manual_replay.status,200);
  assert.deepStrictEqual(p.manual_replay,p.manual_reconciliation,'uncertain in-flight outcome repairs once');
  assert.deepStrictEqual(p.before_manual_replay,state.after_original);
  assert.deepStrictEqual(p.after_manual_replay,p.before_manual_replay,'manual replay preserves the full original read/history');
}
