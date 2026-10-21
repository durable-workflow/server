// Inject one pre-forwarding pressure refusal into a real published HTTP case.
// Other requests reach the unmodified Server and retain their original bytes.
import http from 'node:http';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {writeFileSync} from 'node:fs';
import assert from 'node:assert/strict';
import {proxyHttpRequest} from '../../scripts/conformance/proxy-target.mjs';

const [target,output,prefix,artifacts]=process.argv.slice(2);
const upstream=new URL(target);
let parentPolls=0, refused=null;
const server=http.createServer((request,response)=>{
  const chunks=[];
  request.on('data',bytes=>chunks.push(bytes));
  request.on('end',()=>{
    const bytes=Buffer.concat(chunks);
    if(request.url==='/api/worker/workflow-tasks/poll') {
      const body=JSON.parse(bytes);
      if(body.task_queue===`${prefix}-activity-deadline-authority-schedule_to_start_timeout-workflow`
        && ++parentPolls===2) {
        refused={poll_request_id:body.poll_request_id,task_queue:body.task_queue,upstream_request_started:false};
        response.writeHead(503,{'content-type':'application/json','retry-after':'1'});
        response.end(JSON.stringify({task:null,reason:'backend_lock_pressure',poll_status:'backend_lock_pressure',
          namespace:'default',task_queue:body.task_queue,task_kind:'workflow_task',retry_after_seconds:1,
          backend:{driver:'sqlite',lock_pressure:true}}));
        return;
      }
    }
    const forward=proxyHttpRequest(request.url,upstream,{method:request.method,
      headers:{...request.headers,host:upstream.host}},incoming=>{
      response.writeHead(incoming.statusCode,incoming.headers);incoming.pipe(response);
    });
    forward.on('error',error=>response.destroy(error));forward.end(bytes);
  });
});
server.listen(0,'127.0.0.1');await once(server,'listening');
try {
  const child=spawn(process.execPath,['scripts/conformance/server-parity.mjs','record',
    '--mode','http','--url',`http://127.0.0.1:${server.address().port}`,'--target','php',
    '--prefix',prefix,'--artifacts',artifacts,'--fixture','tests/Fixtures/ServerParityPending/activity-deadline-authority.json',
    '--output',output],{stdio:'inherit'});
  const [code]=await once(child,'close');
  writeFileSync(`${output}.fault.json`,JSON.stringify({fault:'one_preforwarding_pressure_response',refused,parent_polls:parentPolls})+'\n');
  assert.equal(code,0);assert(refused,'Actual parent-poll fault was exercised');
} finally {server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
