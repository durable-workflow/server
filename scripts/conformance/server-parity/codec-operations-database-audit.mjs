import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const [directory]=process.argv.slice(2);
const record=JSON.parse(readFileSync(`${directory}/record.json`));
const rows=readFileSync(`${directory}/database-audit.tsv`,'utf8').trim().split('\n').map(line=>line.split('\t'));
const observations=[record.original.after,...Object.values(record.operations).map(operation=>operation.after)];
for(const observation of observations) {
  const run=rows.filter(row=>row[0]==='run'&&row[1]===observation.run_id);
  assert.equal(run.length,1,'Exactly one original database run');
  assert.equal(run[0][2],observation.status);
  const actual=Object.fromEntries(rows.filter(row=>row[0]==='history'&&row[1]===observation.run_id).map(row=>[row[2],Number(row[3])]));
  const expected={};
  for(const event of observation.events)expected[event.event_type]=(expected[event.event_type]??0)+1;
  assert.deepEqual(actual,expected,'Independent canonical history multiplicities match the original HTTP observation');
}
const tasks=rows.filter(row=>row[0]==='task');
assert(tasks.length>0);
assert(tasks.every(row=>row[4]==='0'),'No manual repair or recovered replacement task');
assert(tasks.every(row=>Number(row[3])<=1),'One original attempt per task');
const metrics=wave=>({requests:wave.readers.reduce((n,r)=>n+r.requests,0),
  successes:wave.readers.reduce((n,r)=>n+r.successes,0),maximum_milliseconds:Math.max(...wave.readers.map(r=>r.maximum_milliseconds))});
const cpu=JSON.parse(readFileSync(`${directory}/cpu.json`));
console.log(JSON.stringify({outcome:'pass',original_run_id:record.original.run_id,
  original_runs:observations.map(observation=>observation.run_id),task_count:tasks.length,repairs:0,
  runner_revision:record.runner_revision,specification_sha256:record.specification_sha256,
  dense:metrics(record.load),health:metrics(record.health),wave_started_at:record.load.started_at,wave_finished_at:record.load.finished_at,
  maximum_cpu_percent:Math.max(...cpu.samples.map(sample=>sample.cpu_percent)),
  maximum_memory_bytes:Math.max(...cpu.samples.map(sample=>sample.memory_bytes)),
  operations:Object.fromEntries(Object.entries(record.operations).map(([role,o])=>[role,{run_id:o.run_id,
    status:o.after.status,event_count:o.after.events.length,fire_at:o.after.events[2]?.payload.fire_at,
    fired_at:o.after.events[3]?.payload.fired_at,closed_at:o.after.execution.closed_at,
    cleanup_deadline_at:o.accepted?.cancellation_request.cleanup_deadline_at}])),
  scope:'stopped_service_read_only_database_history_and_task_audit'}));
