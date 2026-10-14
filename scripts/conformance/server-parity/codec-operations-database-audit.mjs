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
console.log(JSON.stringify({outcome:'pass',original_run_id:record.original.run_id,
  original_runs:observations.map(observation=>observation.run_id),task_count:tasks.length,repairs:0,
  scope:'stopped_service_read_only_database_history_and_task_audit'}));
