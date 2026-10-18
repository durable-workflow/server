import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {existsSync, mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {gzipSync} from 'node:zlib';
import {setTimeout as delay} from 'node:timers/promises';

async function startGateRequest(url, body, headers = {}) {
  return new Promise((resolve, reject) => {
    const request = http.request(url, {method: 'POST', headers: {'content-type': 'application/json', ...headers}}, response => {
      const chunks = [];
      response.on('data', bytes => chunks.push(bytes));
      response.on('end', () => resolve({status: response.statusCode, bytes: Buffer.concat(chunks)}));
      response.on('error', reject);
    });
    request.on('error', reject);
    request.end(body);
  });
}

test('start gate forwards original poll bytes only after the matching successful start receipt', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-start-gate-'));
  const journal = join(directory, 'wire.jsonl');
  const pollBody = '{"worker_id":"original-worker","timeout_seconds":1,"count":9007199254740993}';
  const pollReply = '{"task":{"task_id":"original-task","value":9007199254740993}}';
  let polls = 0, accepted = false, observedPoll;
  const upstream = http.createServer((request, response) => {
    const chunks = [];
    request.on('data', bytes => chunks.push(bytes));
    request.on('end', () => {
      const body = Buffer.concat(chunks).toString();
      if (request.url === '/api/worker/workflow-tasks/poll') {
        polls++;
        observedPoll = {body, accepted};
        response.end(pollReply);
      } else {
        const input = JSON.parse(body);
        setTimeout(() => {
          const success = input.workflow_id !== 'original-workflow' || input.accept === true;
          if (input.workflow_id === 'original-workflow' && success) accepted = true;
          response.writeHead(success ? 201 : 422);
          response.end(JSON.stringify({run_id: success ? input.workflow_id + ':run' : null}));
        }, 30);
      }
    });
  });
  upstream.listen(0, '127.0.0.1'); await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state', '--workflow-poll-after-start', 'original-workflow'],
    {stdio: ['ignore', 'pipe', 'pipe']});
  try {
    const [ready] = await once(proxy.stdout, 'data'); const url = ready.toString().trim();
    const pending = startGateRequest(url + '/api/worker/workflow-tasks/poll', pollBody);
    assert.equal((await startGateRequest(url + '/api/workflows', '{"workflow_id":"other-workflow"}')).status, 201);
    assert.equal(polls, 0, 'another workflow cannot release the original poll');
    assert.equal((await startGateRequest(url + '/api/workflows', '{"workflow_id":"original-workflow","accept":false}')).status, 422);
    assert.equal(polls, 0, 'a rejected start cannot release the original poll');
    const started = await startGateRequest(url + '/api/workflows', '{"workflow_id":"original-workflow","accept":true}');
    assert.equal(started.status, 201);
    const result = await pending;
    assert.deepEqual(observedPoll, {body: pollBody, accepted: true});
    assert.equal(result.status, 200); assert.equal(result.bytes.toString(), pollReply); assert.equal(polls, 1);
    const wire = readFileSync(journal, 'utf8').trim().split('\n').map(JSON.parse);
    assert.equal(wire.length, 4);
    assert.equal(wire[2].path, '/api/workflows'); assert.equal(wire[2].status, 201);
    assert.equal(wire[3].path, '/api/worker/workflow-tasks/poll');
    assert.equal(wire[3].request_body, pollBody); assert.equal(wire[3].response_body, pollReply);
    assert.equal(wire[3].upstream_request_started, true); assert.equal(wire[3].request_body_complete, true);
  } finally {
    const closed = once(proxy, 'close'); proxy.kill(); await closed;
    upstream.closeAllConnections(); await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});

test('cancelled fully received gated poll never reaches upstream or receives an invented claim', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-start-cancel-'));
  const journal = join(directory, 'wire.jsonl');
  let polls = 0;
  const upstream = http.createServer((request, response) => {
    if (request.url === '/api/worker/workflow-tasks/poll') polls++;
    request.resume(); request.on('end', () => {response.writeHead(201); response.end('{"run_id":"original-run"}');});
  });
  upstream.listen(0, '127.0.0.1'); await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state', '--workflow-poll-after-start', 'original-workflow'],
    {stdio: ['ignore', 'pipe', 'pipe']});
  let client;
  try {
    const [ready] = await once(proxy.stdout, 'data'); const url = ready.toString().trim();
    client = http.request(url + '/api/worker/workflow-tasks/poll', {method: 'POST'});
    client.on('error', () => {}); client.end('{"worker_id":"cancelled-original"}');
    await startGateRequest(url + '/api/workflows', '{"workflow_id":"other-workflow"}');
    assert.equal(polls, 0); client.destroy();
    const deadline = Date.now() + 2000;
    let receipt;
    while (!receipt) {
      const rows = readFileSync(journal, 'utf8').trim().split('\n').map(JSON.parse);
      receipt = rows.find(item => item.path === '/api/worker/workflow-tasks/poll');
      if (!receipt) {assert(Date.now() < deadline); await delay(10);}
    }
    assert.equal(receipt.status, 0); assert.equal(receipt.client_cancelled, true);
    assert.equal(receipt.request_body_complete, true); assert.equal(receipt.upstream_request_started, false);
    assert.equal(receipt.request_body, '{"worker_id":"cancelled-original"}');
    assert.equal(receipt.response_body, ''); assert.equal(receipt.response_bytes_base64, '');
    await startGateRequest(url + '/api/workflows', '{"workflow_id":"original-workflow"}');
    assert.equal(polls, 0, 'accepting the start cannot resurrect the cancelled poll');
  } finally {
    client?.destroy();
    const closed = once(proxy, 'close'); proxy.kill(); await closed;
    upstream.closeAllConnections(); await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});


test('HTTP observer preserves compressed bytes and refuses to retain authentication headers', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-wire-'));
  const journal = join(directory, 'wire.jsonl');
  const requestBody = Buffer.from('{"count":9007199254740993,"message":"λ"}');
  const responseBody = '{"outcome":"stale","count":9007199254740993}';
  const compressed = gzipSync(responseBody);
  let upstreamBody;
  let upstreamAuthorization;
  const upstream = http.createServer((request, response) => {
    const chunks = [];
    request.on('data', bytes => chunks.push(bytes));
    request.on('end', () => {
      upstreamBody = Buffer.concat(chunks);
      upstreamAuthorization = request.headers.authorization;
      response.writeHead(409, {'content-type': 'application/json', 'content-encoding': 'gzip', 'content-length': compressed.length,
        'retry-after': '1', 'authorization': 'Bearer disposable-response-only'});
      response.end(compressed);
    });
  });
  upstream.listen(0, '127.0.0.1');
  await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal], {stdio: ['ignore', 'pipe', 'pipe']});
  try {
    const [ready] = await once(proxy.stdout, 'data');
    const url = new URL(ready.toString().trim()+'/api/worker/workflow-tasks/test/complete');
    const received = await new Promise((resolve, reject) => {
      const request = http.request(url, {method: 'POST', headers: {'authorization': 'Bearer disposable-fixture-only', 'content-type': 'application/json'}}, response => {
        const chunks = [];
        response.on('data', bytes => chunks.push(bytes));
        response.on('end', () => resolve({status: response.statusCode, encoding: response.headers['content-encoding'],
          retryAfter: response.headers['retry-after'], bytes: Buffer.concat(chunks)}));
        response.on('error', reject);
      });
      request.on('error', reject);
      request.end(requestBody);
    });
    assert.deepStrictEqual(upstreamBody, requestBody);
    assert.equal(upstreamAuthorization, 'Bearer disposable-fixture-only');
    assert.equal(received.status, 409);
    assert.equal(received.encoding, 'gzip');
    assert.equal(received.retryAfter, '1');
    assert.deepStrictEqual(received.bytes, compressed);
    const raw = readFileSync(journal, 'utf8');
    assert.ok(!raw.includes('disposable-fixture-only'));
    assert.ok(!raw.includes('disposable-response-only'));
    const receipt = JSON.parse(raw);
    assert.deepEqual(Object.keys(receipt).sort(), ['method', 'path', 'request_body', 'status', 'response_encoding',
      'response_retry_after', 'response_bytes_base64', 'response_body', 'client_cancelled', 'transport_error'].sort());
    assert.equal(receipt.request_body, requestBody.toString());
    assert.equal(receipt.response_body, responseBody);
    assert.equal(receipt.response_encoding, 'gzip');
    assert.equal(receipt.response_retry_after, '1');
    assert.equal(receipt.client_cancelled, false);
    assert.equal(receipt.transport_error, null);
    assert.deepStrictEqual(Buffer.from(receipt.response_bytes_base64, 'base64'), compressed);
  } finally {
    const closed = once(proxy, 'close');
    proxy.kill();
    await closed;
    await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});

test('opt-in HTTP observer proves a partial cancelled poll never starts an upstream request', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-unsent-'));
  const journal = join(directory, 'wire.jsonl');
  let upstreamRequests = 0;
  const upstream = http.createServer((request, response) => {upstreamRequests++; response.end('{}');});
  upstream.listen(0, '127.0.0.1');
  await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state'], {stdio: ['ignore', 'pipe', 'pipe']});
  let client;
  try {
    const [ready] = await once(proxy.stdout, 'data');
    const address = new URL(ready.toString().trim());
    client = net.createConnection({host: '127.0.0.1', port: Number(address.port)});
    await once(client, 'connect');
    const continueResponse = once(client, 'data');
    client.write('POST /api/worker/activity-tasks/poll HTTP/1.1\r\nHost: localhost\r\nExpect: 100-continue\r\nContent-Length: 100\r\n\r\n');
    const [interim] = await continueResponse;
    assert.match(interim.toString(), /^HTTP\/1\.1 100 Continue/);
    client.end('{"worker_id":');
    // Creating the journal can notify another process before appendFileSync
    // finishes writing its first line. Require the complete receipt itself.
    const deadline = Date.now() + 2000;
    let raw = '';
    while (!raw.endsWith('\n')) {
      try { raw = readFileSync(journal, 'utf8'); }
      catch (error) { if (error.code !== 'ENOENT') throw error; }
      if (raw.endsWith('\n')) break;
      assert.ok(Date.now() < deadline, 'observer did not finish the cancelled-poll receipt');
      await delay(10);
    }
    const receipt = JSON.parse(raw);
    assert.equal(receipt.request_body, '{"worker_id":');
    assert.equal(receipt.request_body_complete, false);
    assert.equal(receipt.upstream_request_started, false);
    assert.equal(receipt.client_cancelled, true);
    assert.equal(receipt.status, 0);
    assert.equal(receipt.transport_error, null);
    assert.equal(receipt.response_body, '');
    assert.equal(receipt.response_bytes_base64, '');
    assert.equal(receipt.response_retry_after, null);
    assert.equal(receipt.response_encoding, 'identity');
    assert.equal(upstreamRequests, 0);
    assert.equal(proxy.exitCode, null);
  } finally {
    client?.destroy();
    const closed = once(proxy, 'close');
    proxy.kill();
    await closed;
    upstream.closeAllConnections();
    await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});

test('HTTP observer forwards client cancellation to the upstream long poll', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-cancel-'));
  const journal = join(directory, 'wire.jsonl');
  let accept;
  let cancel;
  const accepted = new Promise(resolve => accept = resolve);
  const cancelled = new Promise(resolve => cancel = resolve);
  const upstream = http.createServer((request, response) => {
    request.resume();
    request.on('end', accept);
    response.on('close', () => { if (!response.writableFinished) cancel(); });
  });
  upstream.listen(0, '127.0.0.1');
  await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state'], {stdio: ['ignore', 'pipe', 'pipe']});
  let timer;
  try {
    const [ready] = await once(proxy.stdout, 'data');
    const client = http.request(ready.toString().trim()+'/api/worker/query-tasks/poll', {method: 'POST'});
    client.on('error', () => {});
    client.end('{"worker_id":"cancelled-worker","timeout_seconds":1}');
    await accepted;
    client.destroy();
    await Promise.race([cancelled, new Promise((_, reject) => timer = setTimeout(() => reject(new Error('upstream poll stayed alive after client cancellation')), 2000))]);
    const receipt = JSON.parse(readFileSync(journal, 'utf8'));
    assert.equal(receipt.client_cancelled, true);
    assert.equal(receipt.status, 0);
    assert.equal(receipt.transport_error, null);
    assert.equal(receipt.response_body, '');
    assert.equal(receipt.response_bytes_base64, '');
    assert.equal(receipt.request_body, '{"worker_id":"cancelled-worker","timeout_seconds":1}');
    assert.equal(receipt.request_body_complete, true);
    assert.equal(receipt.upstream_request_started, true);
  } finally {
    clearTimeout(timer);
    const closed = once(proxy, 'close');
    proxy.kill();
    await closed;
    upstream.closeAllConnections();
    await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});

async function requestArrivalRows(path, count) {
  const deadline = Date.now() + 2000;
  while (true) {
    let raw = '';
    try { raw = readFileSync(path, 'utf8'); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    const complete = raw.slice(0, raw.lastIndexOf('\n') + 1).trim();
    const rows = complete ? complete.split('\n').map(JSON.parse) : [];
    if (rows.length >= count) return rows;
    assert(Date.now() < deadline, 'complete request-arrival evidence did not appear');
    await delay(10);
  }
}

test('request arrivals precede pending responses and bind reverse-order receipts without credentials', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-update-arrival-'));
  const journal = join(directory, 'wire.jsonl'), arrivals = join(directory, 'arrivals.jsonl');
  const firstBody = '{"request_id":"original-update","message":"λ","count":9007199254740993}';
  const duplicateBody = '{"request_id":"original-update","message":"duplicate must not apply"}';
  const pending = [];
  const upstream = http.createServer((request, response) => {
    const chunks = [];
    request.on('data', chunk => chunks.push(chunk));
    request.on('end', () => pending.push({body: Buffer.concat(chunks).toString(), response,
      authorization: request.headers.authorization}));
  });
  upstream.listen(0, '127.0.0.1'); await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state'],
    {env: {...process.env, DW_PARITY_HTTP_REQUEST_ARRIVALS: arrivals}, stdio: ['ignore', 'pipe', 'pipe']});
  try {
    const [ready] = await once(proxy.stdout, 'data'), url = ready.toString().trim() + '/api/workflows/original/update/set_payload';
    const first = startGateRequest(url, firstBody, {authorization: 'Bearer disposable-arrival-only'});
    await requestArrivalRows(arrivals, 1);
    const duplicate = startGateRequest(url, duplicateBody);
    const rows = await requestArrivalRows(arrivals, 2);
    assert.deepEqual(rows.map(row => row.request_observation_id), [1, 2]);
    assert.deepEqual(rows.map(row => row.request_body), [firstBody, duplicateBody]);
    for (const row of rows) {
      assert.equal(row.schema, 'durable-workflow.server-parity-http-request-arrival/v1');
      assert.equal(row.method, 'POST');
      assert.equal(row.path, '/api/workflows/original/update/set_payload');
      assert.equal(row.request_body_complete, true);
      assert.equal(row.upstream_request_started, true);
      assert.equal(row.response_observed, false);
      assert(!Object.hasOwn(row, 'status'), 'arrival must not fabricate a server receipt');
      assert(!Object.hasOwn(row, 'response_body'));
      assert(!Object.hasOwn(row, 'headers'));
    }
    assert.equal(existsSync(journal), false, 'response journal stays empty while both calls are pending');
    const deadline = Date.now() + 2000;
    while (pending.length !== 2) {assert(Date.now() < deadline); await delay(10);}
    assert.deepEqual(pending.map(row => row.body), [firstBody, duplicateBody]);
    assert.equal(pending[0].authorization, 'Bearer disposable-arrival-only');
    assert(!readFileSync(arrivals, 'utf8').includes('disposable-arrival-only'));
    pending[1].response.end('{"result":"second"}');
    assert.equal((await duplicate).bytes.toString(), '{"result":"second"}');
    pending[0].response.end('{"result":"first"}');
    assert.equal((await first).bytes.toString(), '{"result":"first"}');
    const wire = readFileSync(journal, 'utf8').trim().split('\n').map(JSON.parse);
    assert(!readFileSync(journal, 'utf8').includes('disposable-arrival-only'));
    assert.deepEqual(wire.map(row => row.request_observation_id), [2, 1]);
    for (const row of wire) {
      assert.equal(row.request_body, rows.find(arrival => arrival.request_observation_id === row.request_observation_id).request_body);
      assert.equal(row.status, 200);
      assert.equal(row.client_cancelled, false);
    }
  } finally {
    const closed = once(proxy, 'close'); proxy.kill(); await closed;
    upstream.closeAllConnections(); await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});

test('cancelled forwarded request keeps arrival correlation without inventing acceptance', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-update-cancel-'));
  const journal = join(directory, 'wire.jsonl'), arrivals = join(directory, 'arrivals.jsonl');
  const upstream = http.createServer(request => request.resume());
  upstream.listen(0, '127.0.0.1'); await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state'],
    {env: {...process.env, DW_PARITY_HTTP_REQUEST_ARRIVALS: arrivals}, stdio: ['ignore', 'pipe', 'pipe']});
  let client;
  try {
    const [ready] = await once(proxy.stdout, 'data');
    client = http.request(ready.toString().trim() + '/api/workflows/original/update/set_payload', {method: 'POST'});
    client.on('error', () => {}); client.end('{"request_id":"cancelled-update"}');
    const [arrival] = await requestArrivalRows(arrivals, 1);
    client.destroy();
    const [wire] = await requestArrivalRows(journal, 1);
    assert.equal(wire.request_observation_id, arrival.request_observation_id);
    assert.equal(wire.request_body, arrival.request_body);
    assert.equal(wire.status, 0); assert.equal(wire.client_cancelled, true);
    assert.equal(wire.request_body_complete, true); assert.equal(wire.upstream_request_started, true);
    assert.equal(wire.response_body, ''); assert.equal(wire.response_bytes_base64, '');
    assert.equal(arrival.response_observed, false);
    assert(!Object.hasOwn(arrival, 'status'));
  } finally {
    client?.destroy();
    const closed = once(proxy, 'close'); proxy.kill(); await closed;
    upstream.closeAllConnections(); await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});

test('oversized request-arrival evidence fails within its byte budget', {timeout: 10000}, async () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-update-budget-'));
  const journal = join(directory, 'wire.jsonl'), arrivals = join(directory, 'arrivals.jsonl');
  let upstreamRequests = 0;
  const upstream = http.createServer(request => {upstreamRequests++; request.resume();});
  upstream.listen(0, '127.0.0.1'); await once(upstream, 'listening');
  const proxy = spawn(process.execPath, [new URL('../../scripts/conformance/server-parity/published-sdk-patch/observe-http.mjs', import.meta.url).pathname,
    `http://127.0.0.1:${upstream.address().port}`, journal, '--forwarding-state'],
    {env: {...process.env, DW_PARITY_HTTP_REQUEST_ARRIVALS: arrivals}, stdio: ['ignore', 'pipe', 'pipe']});
  let client, stderr = '';
  proxy.stderr.on('data', bytes => stderr += bytes.toString());
  try {
    const [ready] = await once(proxy.stdout, 'data');
    const closed = once(proxy, 'close');
    client = http.request(ready.toString().trim() + '/api/workflows/original/update/set_payload', {method: 'POST'});
    client.on('error', () => {}); client.end('x'.repeat(5 * 1024 * 1024));
    const [code] = await closed;
    assert.notEqual(code, 0);
    assert.match(stderr, /Request-arrival journal exceeds its bounded fixture budget/);
    assert.equal(upstreamRequests, 0, 'oversized evidence is refused before forwarding the operation');
    assert.equal(existsSync(arrivals), false, 'oversized body cannot produce a qualifying arrival record');
  } finally {
    client?.destroy();
    if (proxy.exitCode === null) {const closed = once(proxy, 'close'); proxy.kill(); await closed;}
    upstream.closeAllConnections(); await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});
