import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkCodecDeadlineRecord} from './codec-deadline-contract.mjs';

// Start from an actual passing original. These controls do not construct a
// producer, rerun work, issue new budgets or replace the positive observation.
const [recordPath,specPath,cpuPath]=process.argv.slice(2);
const recordBytes=readFileSync(recordPath,'utf8');
const record=JSON.parse(recordBytes),specBytes=readFileSync(specPath),cpu=JSON.parse(readFileSync(cpuPath));
checkCodecDeadlineRecord(record,specBytes,cpu);
assert(recordBytes.includes('9007199254740993'),'Original int64 bytes survive the profile writer');
assert(!recordBytes.includes('9007199254740992'),'No JavaScript-rounded int64 record');
const controls=[
  ['wrong installed actor',r=>{r.original.sdk_source='0'.repeat(40);}],
  ['decoded value changed',r=>{r.original.dense.after_decoded_sha256='0'.repeat(64);}],
  ['rebased observer budget',r=>{r.original.observer_deadline++;}],
  ['handoff beyond original limit',r=>{r.original.observer_started_at+=2;}],
  ['early outcome',r=>{r.original.late_outcomes[0].submitted_at=r.original.admission_observed_at;}],
  ['accepted expired completion',r=>{r.original.late_outcomes[0].status=200;}],
  ['accepted expired failure',r=>{r.original.late_outcomes[1].status=200;}],
  ['renewed expired heartbeat',r=>{r.original.late_outcomes[2].status=200;r.original.late_outcomes[2].response={can_continue:true,heartbeat_recorded:false};}],
  ['recorded expired heartbeat',r=>{r.original.late_outcomes[2].status=200;r.original.late_outcomes[2].response={can_continue:false,heartbeat_recorded:true};}],
  ['changed attempt identity',r=>{r.original.activity_task.activity_attempt_id='different';}],
  ['changed original history prefix',r=>{r.original.after.events[0].timestamp='2000-01-01T00:00:00.000000Z';}],
  ['changed full retry list',r=>{r.original.activity_task.retry_policy.backoff_seconds.pop();}],
  ['changed typed int64',r=>{r.original.before.typed_input.value[0].value.integer.value='9007199254740992';}],
  ['rebased original run deadline',r=>{r.original.after.execution.run_deadline_at='2000-01-01T00:00:00.000000Z';}],
  ['missing canonical timeout',r=>{r.original.after.events.pop();}],
  ['redelivered activity',r=>{r.original.extra_activity_poll={task_id:'unexpected'};}],
  ['repaired parent attempt',r=>{r.original.parent_delivery.workflow_task_attempt=2;}],
  ['changed parent microclock',r=>{r.original.parent_delivery.history_events[4].recorded_at='2000-01-01T00:00:00.000000Z';}],
  ['too few actual readers',r=>{r.load.readers.pop();}],
  ['failed dense read',r=>{r.load.readers[0].successes--;}],
  ['failed health read',r=>{r.health.readers[0].successes--;}],
  ['empty successful dense read',r=>{r.load.readers[0].bytes=0;}],
  ['read over absolute limit',r=>{r.load.readers[0].maximum_milliseconds=2001;}],
  ['load ended before outcome',r=>{r.load.finished_at=r.load.started_at;}],
  ['wrong actual CPU allocation',(_r,c)=>{c.resource_limits.nano_cpus=2000000000;}],
  ['swap-enabled target',(_r,c)=>{c.resource_limits.memory_swap_bytes++;}],
  ['missing actual CPU evidence',(_r,c)=>{c.samples=[];}],
  ['idle workload',(_r,c)=>{for(const sample of c.samples)sample.cpu_percent=0;}],
  ['memory above declared ceiling',(_r,c)=>{for(const sample of c.samples)sample.memory_bytes=536870913;}],
];
for(const[name,change]of controls){
  const changed=structuredClone(record),changedCpu=structuredClone(cpu);change(changed,changedCpu);
  assert.throws(()=>checkCodecDeadlineRecord(changed,specBytes,changedCpu),undefined,name);
}
console.log(JSON.stringify({outcome:'pass',actual_original_run_id:record.original.run_id,
  refused_corruptions:controls.length,original_int64_bytes_preserved:true}));
