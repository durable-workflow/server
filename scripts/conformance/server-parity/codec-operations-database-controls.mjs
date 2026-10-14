import assert from 'node:assert/strict';
import {mkdtempSync,readFileSync,writeFileSync,rmSync} from 'node:fs';
import {spawnSync} from 'node:child_process';

const [evidence]=process.argv.slice(2);
const directory=mkdtempSync('/tmp/server-codec-database-controls-');
try {
  // Preserve the genuine JSON bytes; mutate only the independent SQL rows.
  for(const file of ['record.json','cpu.json'])writeFileSync(`${directory}/${file}`,readFileSync(`${evidence}/${file}`));
  const record=JSON.parse(readFileSync(`${evidence}/record.json`));
  const original=readFileSync(`${evidence}/database-audit.tsv`,'utf8');
  const mutations=[
    ['duplicate canonical completion',rows=>{const row=rows.find(r=>r[0]==='history'&&r[1]===record.operations.timer.run_id&&r[2]==='WorkflowCompleted');assert(row);row[3]='2';}],
    ['manual task repair',rows=>{const row=rows.find(r=>r[0]==='task');assert(row);row[4]='1';}],
    ['conflicting canonical status',rows=>{const row=rows.find(r=>r[0]==='run'&&r[1]===record.operations.timer.run_id);assert(row);row[2]='cancelled';}]
  ];
  for(const [name,mutate]of mutations) {
    const rows=original.trim().split('\n').map(line=>line.split('\t'));mutate(rows);
    writeFileSync(`${directory}/database-audit.tsv`,rows.map(row=>row.join('\t')).join('\n')+'\n');
    const result=spawnSync(process.execPath,['scripts/conformance/server-parity/codec-operations-database-audit.mjs',directory],{encoding:'utf8'});
    assert.equal(result.status,1,name);assert.match(result.stderr,/AssertionError/,name);
  }
  console.log(JSON.stringify({outcome:'pass',refused_actual_database_corruptions:mutations.length}));
}finally{rmSync(directory,{recursive:true});}
