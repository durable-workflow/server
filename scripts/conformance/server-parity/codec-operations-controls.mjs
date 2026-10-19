import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkCodecOperationsRecord} from './codec-operations-contract.mjs';

const[recordPath,specPath,cpuPath]=process.argv.slice(2);
const bytes=readFileSync(recordPath,'utf8'),record=JSON.parse(bytes),spec=readFileSync(specPath),cpu=JSON.parse(readFileSync(cpuPath));
checkCodecOperationsRecord(record,spec,cpu);
assert(bytes.includes('9007199254740993'));assert(!bytes.includes('9007199254740992'));
const changes=[
  ['missing timer observer',r=>{delete r.operations.timer;}],
  ['failed operation',r=>{r.operations.renewal.outcome='failed';}],
  ['wrong installed operation actor',r=>{r.operations.timer.sdk_source='0'.repeat(40);}],
  ['shorter actual load',r=>{r.load.finished_at=new Date(Date.parse(r.load.started_at)+5000).toISOString();}],
  ['operation outside wave',r=>{r.operations.timer.calls[0].submitted_at=0;}],
  ['slow ordinary control',r=>{r.operations.timer.calls[0].observed_at=r.operations.timer.calls[0].submitted_at+2.1;}],
  ['rebased operation budget',r=>{r.operations.renewal.observer_deadline++;}],
  ['changed timer identity',r=>{r.operations.timer.after.events[3].payload.timer_id='different';}],
  ['early timer',r=>{r.operations.timer.after.events[3].payload.fired_at='2000-01-01T00:00:00.000000Z';}],
  ['late timer',r=>{r.operations.timer.after.events[3].payload.fired_at='2100-01-01T00:00:00.000000Z';}],
  ['missing timer completion',r=>{r.operations.timer.after.events.pop();}],
  ['wrong timer output',r=>{r.operations.timer.after.typed_output.value.integer.value='7';}],
  ['changed heartbeat attempt',r=>{r.operations.renewal.activity_task.activity_attempt_id='different';}],
  ['changed full retry snapshot',r=>{r.operations.renewal.activity_task.retry_policy.backoff_seconds=[1];}],
  ['status records progress',r=>{r.operations.renewal.status_second.heartbeat_recorded=true;}],
  ['status renews lease',r=>{r.operations.renewal.status_after.lease_expires_at='2100-01-01T00:00:00.000000Z';}],
  ['first heartbeat refused',r=>{r.operations.renewal.heartbeat_first.can_continue=false;}],
  ['second heartbeat does not commit',r=>{r.operations.renewal.heartbeat_second.heartbeat_recorded=false;}],
  ['changed heartbeat progress',r=>{r.operations.renewal.after.events[5].payload.progress.details.message='different';}],
  ['redelivered renewal attempt',r=>{r.operations.renewal.parent.workflow_task_attempt=2;}],
  ['changed root reason',r=>{r.operations.cancellation.after.events[3].payload.cancellation.reason='different';}],
  ['changed root requester',r=>{r.operations.cancellation.after.events[3].payload.cancellation.requester.id='different';}],
  ['changed duplicate request',r=>{r.operations.cancellation.duplicate.cancellation_request.request_id='different';}],
  ['rebased duplicate cleanup deadline',r=>{r.operations.cancellation.terminal_duplicate.cancellation_request.cleanup_deadline_at='2100-01-01T00:00:00.000000Z';}],
  ['cleanup renewal crosses root deadline',r=>{r.operations.cancellation.renewal.lease_expires_at='2100-01-01T00:00:00.000000Z';}],
  ['changed cleanup outcome',r=>{r.operations.cancellation.after.events[8].payload.cancellation_cleanup.outcome='completed';}],
  ['changed cleanup timer cancellation',r=>{r.operations.cancellation.after.events[7].payload.timer_id='different';}],
  ['late observed cleanup',r=>{r.operations.cancellation.closure_observed_at+=4;}],
  ['no busy CPU during timed operations',(_r,c)=>{for(const sample of c.samples)sample.cpu_percent=0;}],
];
for(const[name,change]of changes){const r=structuredClone(record),c=structuredClone(cpu);change(r,c);
  assert.throws(()=>checkCodecOperationsRecord(r,spec,c),undefined,name);}
console.log(JSON.stringify({outcome:'pass',actual_original_run_id:record.original.run_id,
  refused_operation_corruptions:changes.length,int64_bytes_preserved:true}));
