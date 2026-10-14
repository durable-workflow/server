import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {once} from 'node:events';
import {spawn} from 'node:child_process';

const pressure=kind=>({task:null,poll_status:'backend_lock_pressure',reason:'backend_lock_pressure',
  namespace:'default',task_queue:'original-queue',task_kind:`${kind}_task`,retry_after_seconds:1,
  backend:{driver:'sqlite',lock_pressure:true}});

async function exercise(reply, {kind='workflow',budget=5,mode='new',count=1}={}) {
  const requests=[];
  const server=http.createServer((request,response)=>{
    const chunks=[];
    request.on('data',bytes=>chunks.push(bytes));
    request.on('end',()=>{
      requests.push({path:request.url,body:Buffer.concat(chunks).toString(),at:Date.now()/1000});
      const [status,body]=reply(requests.length);
      response.writeHead(status,{'content-type':'application/json','retry-after':'1'});
      response.end(JSON.stringify(body));
    });
  });
  server.listen(0,'127.0.0.1');await once(server,'listening');
  try {
    const child=spawn('php',[new URL('../Support/ServerParityDeadlinePoll.php',import.meta.url).pathname,
      `http://127.0.0.1:${server.address().port}`,kind,String(budget),mode,String(count)]);
    let output='',errors='';
    child.stdout.on('data',bytes=>output+=bytes);child.stderr.on('data',bytes=>errors+=bytes);
    const [code]=await once(child,'close');
    assert.equal(code,0,errors);assert.equal(errors,'');
    return {result:JSON.parse(output),requests};
  } finally {server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
}

for(const kind of ['workflow','activity'])test(`${kind} pressure retains identity and advertised backoff through actual installed Client`,async()=>{
  const reply=n=>n===1?[503,pressure(kind)]:[200,{task:{task_id:'original-parent',attempt:1}}];
  const old=await exercise(reply,{kind,mode:'old'});
  assert.equal(old.result.outcome,'refused');assert.equal(old.requests.length,1);
  const candidate=await exercise(reply,{kind});
  assert.equal(candidate.result.outcome,'pass');
  assert.deepEqual(candidate.result.tasks,[{task_id:'original-parent',attempt:1}]);
  assert.equal(candidate.requests.length,2);
  assert.equal(candidate.requests[0].body,candidate.requests[1].body);
  assert(candidate.requests[1].at-candidate.requests[0].at>=1);
  assert.equal(candidate.result.retries.length,1);
  assert.equal(candidate.result.retries[0].observer_deadline,candidate.result.deadline);
  assert(candidate.requests[1].at<candidate.result.deadline);
});

test('successful empty poll ends a logical request and the next observation gets its own identity',async()=>{
  const {result,requests}=await exercise(n=>n===1?[503,pressure('workflow')]:[200,{task:null}],{count:2});
  assert.deepEqual(result.tasks,[null,null]);assert.equal(requests.length,3);
  assert.equal(requests[0].body,requests[1].body);
  assert.notEqual(JSON.parse(requests[1].body).poll_request_id,JSON.parse(requests[2].body).poll_request_id);
});

test('pressure whose delay cannot fit the original budget is refused without another request',async()=>{
  const {result,requests}=await exercise(()=>[503,pressure('workflow')],{budget:0.5});
  assert.equal(result.outcome,'refused');assert.equal(result.status,503);
  assert.equal(result.reason,'backend_lock_pressure');assert.deepEqual(result.retries,[]);
  assert.equal(requests.length,1);
});

const refusals={
  'wrong namespace':value=>value.namespace='other',
  'wrong queue':value=>value.task_queue='other',
  'wrong kind':value=>value.task_kind='activity_task',
  'missing null task':value=>delete value.task,
  'claimed task':value=>value.task={task_id:'uncertain'},
  'wrong poll status':value=>value.poll_status='other',
  'unknown admission':value=>value.reason='backend_unavailable',
  'backend not pressured':value=>value.backend.lock_pressure=false,
  'fractional delay':value=>value.retry_after_seconds=0.5,
  'zero delay':value=>value.retry_after_seconds=0,
  'string delay':value=>value.retry_after_seconds='1',
};
for(const [name,mutate]of Object.entries(refusals))test(`${name} never receives a fixture retry`,async()=>{
  const body=pressure('workflow');mutate(body);
  const {result,requests}=await exercise(()=>[503,body]);
  assert.equal(result.outcome,'refused');assert.equal(requests.length,1);assert.deepEqual(result.retries,[]);
});
