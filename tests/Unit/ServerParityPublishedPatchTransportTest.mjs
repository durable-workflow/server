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
      response.writeHead(409, {'content-type': 'application/json', 'content-encoding': 'gzip', 'content-length': compressed.length});
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
        response.on('end', () => resolve({status: response.statusCode, encoding: response.headers['content-encoding'], bytes: Buffer.concat(chunks)}));
        response.on('error', reject);
      });
      request.on('error', reject);
      request.end(requestBody);
    });
    assert.deepStrictEqual(upstreamBody, requestBody);
    assert.equal(upstreamAuthorization, 'Bearer disposable-fixture-only');
    assert.equal(received.status, 409);
    assert.equal(received.encoding, 'gzip');
    assert.deepStrictEqual(received.bytes, compressed);
    const raw = readFileSync(journal, 'utf8');
    assert.ok(!raw.includes('disposable-fixture-only'));
    const receipt = JSON.parse(raw);
    assert.equal(receipt.request_body, requestBody.toString());
    assert.equal(receipt.response_body, responseBody);
    assert.equal(receipt.response_encoding, 'gzip');
    assert.deepStrictEqual(Buffer.from(receipt.response_bytes_base64, 'base64'), compressed);
  } finally {
    const closed = once(proxy, 'close');
    proxy.kill();
    await closed;
    await new Promise(resolve => upstream.close(resolve));
    rmSync(directory, {recursive: true});
  }
});
