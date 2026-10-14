import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {once} from 'node:events';
import {createHash} from 'node:crypto';
import {codecLoadRead,codecLoadWave} from '../../scripts/conformance/server-parity/codec-deadline-load.mjs';
import {checkCanonicalLateness} from '../../scripts/conformance/server-parity/codec-operations-contract.mjs';

async function withServer(handler,run) {
  const server=http.createServer(handler);server.listen(0,'127.0.0.1');await once(server,'listening');
  try{return await run(`http://127.0.0.1:${server.address().port}`);}
  finally{server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}
}

test('one microsecond early is refused even when Date.parse reports the same millisecond',()=>{
  const due='2026-10-14T02:19:46.114321Z',early='2026-10-14T02:19:46.114320Z';
  assert.equal(Date.parse(due),Date.parse(early));
  assert.throws(()=>checkCanonicalLateness(early,due,1));
  checkCanonicalLateness(due,due,1);
});

test('one microsecond beyond declared lateness is refused without rounding the original deadline',()=>{
  const due='2026-10-14T02:19:46.114321Z',atLimit='2026-10-14T02:19:47.114321Z',late='2026-10-14T02:19:47.114322Z';
  assert.equal(Date.parse(atLimit),Date.parse(late));
  checkCanonicalLateness(atLimit,due,1);
  assert.throws(()=>checkCanonicalLateness(late,due,1));
});

test('the actual wave start is acknowledged before dependent operations are released',async()=>{
  let started=null,requests=0;
  await withServer((_request,response)=>{assert(started?.started);requests++;response.end('{}');},async url=>{
    const record=await codecLoadWave(url,{wave_seconds:0.03,readers:2,request_timeout_seconds:1,maximum_response_bytes:1000},
      {onStarted:receipt=>{assert.equal(started,null);started=receipt;}});
    assert.equal(record.started_at,started.started_at);assert(requests>0);
  });
});

test('published control-plane version and namespace are carried on every description read',async()=>{
  const observed=[];
  await withServer((request,response)=>{
    observed.push({version:request.headers['x-durable-workflow-control-plane-version'],namespace:request.headers['x-namespace']});
    response.writeHead(observed.at(-1).version==='2'?200:400);response.end('{}');
  },async url=>{
    const counterfactual=await codecLoadRead(url,{timeoutSeconds:1,maximumBytes:1000});
    assert.equal(counterfactual.status,400);
    const actual=await codecLoadWave(url,{wave_seconds:0.03,readers:2,request_timeout_seconds:1,
      maximum_response_bytes:1000,control_plane_protocol:'2'});
    for(const reader of actual.readers){assert(reader.requests>0);assert.equal(reader.successes,reader.requests);}
    for(const read of observed.slice(1))assert.deepEqual(read,{version:'2',namespace:'default'});
  });
});

test('dense reader retains byte count/hash and status without parsing large int64 bodies',async()=>{
  const bytes=Buffer.from('{"integer":9007199254740993,"unicode":"λ"}');
  await withServer((_request,response)=>{response.write(bytes.subarray(0,10));response.end(bytes.subarray(10));},async url=>{
    const receipt=await codecLoadRead(url,{timeoutSeconds:1,maximumBytes:1000});
    assert.equal(receipt.status,200);assert.equal(receipt.bytes,bytes.length);
    assert.equal(receipt.sha256,createHash('sha256').update(bytes).digest('hex'));
    assert.equal(receipt.error,null);assert.equal(Object.hasOwn(receipt,'body'),false);
  });
});

test('oversized response is bounded and never called a complete hash',async()=>{
  await withServer((_request,response)=>response.end(Buffer.alloc(1024)),async url=>{
    const receipt=await codecLoadRead(url,{timeoutSeconds:1,maximumBytes:128});
    assert.equal(receipt.error,'response_byte_limit');assert.equal(receipt.sha256,null);
  });
});

test('an empty successful response cannot masquerade as a dense description read',async()=>{
  await withServer((_request,response)=>response.end('{}'),async url=>{
    const receipt=await codecLoadRead(url,{timeoutSeconds:1,maximumBytes:1000,minimumBytes:128});
    assert.equal(receipt.status,200);assert.equal(receipt.error,'response_below_dense_minimum');
  });
});

test('absolute request timeout also stops a connection that continuously sends bytes',async()=>{
  await withServer((_request,response)=>{
    const timer=setInterval(()=>response.write('data'),5);response.on('close',()=>clearInterval(timer));
  },async url=>{
    const receipt=await codecLoadRead(url,{timeoutSeconds:0.04,maximumBytes:65536});
    assert.equal(receipt.error,'request_timeout');assert.equal(receipt.sha256,null);
    assert(receipt.elapsed_milliseconds<500);
  });
});

test('health reader exercises both routes independently and counts failed health as failed',async()=>{
  const paths=[];
  await withServer((request,response)=>{paths.push(request.url);response.writeHead(request.url==='/api/ready'?503:200);response.end('{}');},async url=>{
    const record=await codecLoadWave(url,{wave_seconds:0.03,readers:8,request_timeout_seconds:1,
      maximum_response_bytes:1000,health_interval_milliseconds:50},{health:true});
    assert.equal(record.role,'independent_health_reader');assert.equal(record.readers.length,1);
    assert.deepEqual(paths,['/api/health','/api/ready']);
    assert.equal(record.readers[0].successes,1);assert.equal(record.readers[0].statuses['503'],1);
  });
});

test('declared description readers keep bounded summaries across repeated reads',async()=>{
  await withServer((_request,response)=>response.end('{}'),async url=>{
    const record=await codecLoadWave(url,{wave_seconds:0.03,readers:2,request_timeout_seconds:1,
      maximum_response_bytes:1000,health_interval_milliseconds:50});
    assert.equal(record.readers.length,2);
    for(const reader of record.readers){assert(reader.requests>0);assert.equal(reader.successes,reader.requests);assert(reader.hashes.length<=16);}
  });
});
