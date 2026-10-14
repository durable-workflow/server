import http from 'node:http';
import {createHash} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {once} from 'node:events';
import {performance} from 'node:perf_hooks';

// Consume response bytes without retaining or parsing repeated dense payloads.
// The installed SDK verifies the original value before and after this wave.
export async function codecLoadRead(url, {timeoutSeconds,maximumBytes,minimumBytes=0,controlPlaneProtocol,token}) {
  const started=performance.now();
  return new Promise(resolve=>{
    let bytes=0,finished=false,status=null;
    const hash=createHash('sha256');
    const finish=error=>{
      if(finished)return;finished=true;clearTimeout(timer);
      resolve({status,bytes,sha256:error?null:hash.digest('hex'),error,
        elapsed_milliseconds:performance.now()-started});
    };
    const request=http.get(url,{headers:{'accept':'application/json','x-namespace':'default',
      ...(controlPlaneProtocol?{'x-durable-workflow-control-plane-version':controlPlaneProtocol}:{}),
      ...(token?{authorization:`Bearer ${token}`}:{})}},response=>{
      status=response.statusCode;
      response.on('data',chunk=>{
        if(finished)return;bytes+=chunk.length;
        if(bytes>maximumBytes){finish('response_byte_limit');request.destroy();return;}
        hash.update(chunk);
      });
      response.on('end',()=>finish(bytes<minimumBytes?'response_below_dense_minimum':null));
      response.on('error',()=>finish('response_interrupted'));
    });
    const timer=setTimeout(()=>{finish('request_timeout');request.destroy();},timeoutSeconds*1000);
    request.on('error',()=>finish('request_failed'));
  });
}

export async function codecLoadWave(url,spec,{health=false,token=null,onStarted=null}={}) {
  const startedAt=new Date().toISOString(),start=performance.now(),end=start+spec.wave_seconds*1000;
  onStarted?.({started:true,started_at:startedAt});
  const readers=Array.from({length:health?1:spec.readers},()=>({requests:0,successes:0,bytes:0,
    statuses:{},errors:{},minimum_milliseconds:null,maximum_milliseconds:0,hashes:[]}));
  await Promise.all(readers.map(async summary=>{
    while(performance.now()<end) {
      const paths=health?['/api/health','/api/ready']:[''];
      for(const path of paths) {
        const response=await codecLoadRead(health?new URL(path,url):new URL(url),{timeoutSeconds:spec.request_timeout_seconds,
          maximumBytes:health?65536:spec.maximum_response_bytes,
          minimumBytes:health?0:spec.minimum_dense_response_bytes,
          controlPlaneProtocol:spec.control_plane_protocol,token});
        summary.requests++;summary.bytes+=response.bytes;
        const status=String(response.status);summary.statuses[status]=(summary.statuses[status]??0)+1;
        if(response.error)summary.errors[response.error]=(summary.errors[response.error]??0)+1;
        else if(response.status===200)summary.successes++;
        summary.minimum_milliseconds=Math.min(summary.minimum_milliseconds??Infinity,response.elapsed_milliseconds);
        summary.maximum_milliseconds=Math.max(summary.maximum_milliseconds,response.elapsed_milliseconds);
        if(response.sha256&&!summary.hashes.includes(response.sha256)&&summary.hashes.length<16)summary.hashes.push(response.sha256);
      }
      if(health)await new Promise(resolve=>setTimeout(resolve,spec.health_interval_milliseconds));
    }
  }));
  return {role:health?'independent_health_reader':'dense_description_readers',started_at:startedAt,
    finished_at:new Date().toISOString(),duration_milliseconds:performance.now()-start,
    declared_wave_seconds:spec.wave_seconds,readers};
}

if(process.argv[1]===new URL(import.meta.url).pathname) {
  const [role,url,specPath,output]=process.argv.slice(2);
  if(!['load','health'].includes(role)||!url||!specPath||!output)throw new Error('Expected role, URL, specification and output');
  const specBytes=readFileSync(specPath),spec=JSON.parse(specBytes);
  process.stdout.write(JSON.stringify({ready:true,role})+'\n');
  // The parent has already admitted and claimed the original activity. Both
  // load processes start from its explicit handoff, without rebasing it.
  await once(process.stdin,'data');process.stdin.pause();
  const result=await codecLoadWave(url,spec,{health:role==='health',token:process.env.DW_PARITY_TOKEN,
    onStarted:receipt=>process.stdout.write(JSON.stringify(receipt)+'\n')});
  writeFileSync(output,JSON.stringify({specification_sha256:createHash('sha256').update(specBytes).digest('hex'),...result})+'\n');
}
