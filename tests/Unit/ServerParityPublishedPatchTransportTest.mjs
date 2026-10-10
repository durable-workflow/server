import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {gzipSync} from 'node:zlib';

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
    `http://127.0.0.1:${upstream.address().port}`, journal], {stdio: ['ignore', 'pipe', 'pipe']});
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
