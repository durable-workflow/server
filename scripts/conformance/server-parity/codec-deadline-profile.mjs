import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createInterface} from 'node:readline';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {createHash} from 'node:crypto';

const [url,prefix,specPath,artifactsPath,directory]=process.argv.slice(2);
if(!url||!prefix||!specPath||!artifactsPath||!directory)throw new Error('Expected URL, fresh prefix, specification, artifacts and output directory');
const spec=JSON.parse(readFileSync(specPath));
mkdirSync(directory,{recursive:true});
const children=[];
function child(executable,args) {
  const process=spawn(executable,args,{stdio:['pipe','pipe','inherit']});children.push(process);
  const queued=[],waiters=[];
  createInterface({input:process.stdout}).on('line',line=>{
    const value=JSON.parse(line);if(waiters.length)waiters.shift().resolve(value);else queued.push(value);
  });
  const closed=once(process,'close');
  closed.then(([code])=>{for(const waiter of waiters.splice(0))waiter.reject(new Error(`Observer closed with ${code}`));});
  return {process,closed,next:()=>queued.length?Promise.resolve(queued.shift()):new Promise((resolve,reject)=>waiters.push({resolve,reject}))};
}
const health=child(process.execPath,['scripts/conformance/server-parity/codec-deadline-load.mjs','health',url,specPath,`${directory}/health.json`]);
let timer=setTimeout(()=>{for(const process of children)process.kill('SIGKILL');},spec.preparation_budget_seconds*1000);
let complete=false;
try {
  if(!(await health.next()).ready)throw new Error('Independent health observer failed preparation');
  const author=child('php',['scripts/conformance/server-parity/codec-deadline-author.php',url,prefix,specPath,artifactsPath,`${directory}/original.json`]);
  const ready=await author.next();if(!ready.ready)throw new Error('Original activity preparation failed');
  const denseUrl=new URL(`/api/workflows/${encodeURIComponent(ready.dense_workflow_id)}/runs/${encodeURIComponent(ready.dense_run_id)}`,url);
  const load=child(process.execPath,['scripts/conformance/server-parity/codec-deadline-load.mjs','load',denseUrl.href,specPath,`${directory}/load.json`]);
  if(!(await load.next()).ready)throw new Error('Description readers failed preparation');
  clearTimeout(timer);
  timer=setTimeout(()=>{for(const process of children)process.kill('SIGKILL');},spec.observer_budget_seconds*1000);
  process.stdout.write(JSON.stringify({phase:'load_started',observed_at:new Date().toISOString()})+'\n');
  load.process.stdin.end('start\n');health.process.stdin.end('start\n');author.process.stdin.write('start\n');
  if(!(await author.next()).deadline_observed)throw new Error('Original deadline observation failed');
  const [[loadCode],[healthCode]]=await Promise.all([load.closed,health.closed]);
  if(loadCode!==0||healthCode!==0)throw new Error('Independent load reader failed');
  author.process.stdin.end('finish\n');
  const [authorCode]=await author.closed;if(authorCode!==0)throw new Error('Original deadline author failed');
  const inputs={original:JSON.parse(readFileSync(`${directory}/original.json`)),
    load:JSON.parse(readFileSync(`${directory}/load.json`)),health:JSON.parse(readFileSync(`${directory}/health.json`))};
  writeFileSync(`${directory}/record.json`,JSON.stringify({schema:spec.schema,
    runner_revision:process.env.DW_PARITY_RUNNER_REVISION,
    specification_sha256:createHash('sha256').update(readFileSync(specPath)).digest('hex'),
    artifacts:JSON.parse(readFileSync(artifactsPath)),...inputs})+'\n');
  complete=true;
} finally {
  clearTimeout(timer);
  for(const process of children)if(process.exitCode===null)process.kill('SIGKILL');
  if(!complete)writeFileSync(`${directory}/failed.json`,JSON.stringify({outcome:'failed',observed_at:new Date().toISOString()})+'\n');
}
