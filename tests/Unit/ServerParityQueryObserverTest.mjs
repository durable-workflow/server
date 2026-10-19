import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

test('observer preserves bytes and diagnoses refused dates and truncated upstream responses', {timeout: 5000}, async t => {
  const directory = mkdtempSync(join(tmpdir(), 'query-observer-'));
  t.after(() => rmSync(directory, {recursive: true, force: true}));
  const journal = join(directory, 'journal.jsonl');
  const date = '2026-02-28';
  let overrides = {};
  let truncated = false;
  let upstreamRequests = 0;
  let original;
  const upstream = http.createServer(async (request, response) => {
    upstreamRequests++;
    for await (const _chunk of request) { /* Drain this constructed poll body. */ }
    original = JSON.stringify({task: {
      query_task_id: 'original-query', query_task_attempt: 1,
      namespace: 'isolated', task_queue: 'queue', lease_owner: 'worker',
      workflow_id: 'workflow', run_id: 'run',
      query_admitted_at: date + 'T00:00:00.123456Z',
      query_deadline_at: date + 'T00:00:04.123456Z',
      lease_expires_at: date + 'T00:00:04.123456Z',
      server_time: date + 'T00:00:00.123456Z',
      ...overrides,
    }});
    response.writeHead(200, {'content-type': 'application/json',
      ...(truncated ? {'content-length': Buffer.byteLength(original) + 32,
        connection: 'close', 'x-private-fixture': 'never-retain-this-header'} : {})});
    response.end(original);
  });
  upstream.listen(0, '127.0.0.1');
  await once(upstream, 'listening');
  t.after(() => new Promise(resolve => upstream.close(resolve)));
  const observer = spawn(process.execPath, [
    fileURLToPath(new URL('../../scripts/conformance/server-parity/query-authority/observer.mjs', import.meta.url)),
    `http://127.0.0.1:${upstream.address().port}`, 'timely', journal,
  ], {env: {...process.env, DW_PARITY_QUERY_OBSERVER_PORT: '0'}, stdio: ['ignore', 'pipe', 'pipe']});
  const exited = once(observer, 'exit');
  t.after(async () => { observer.kill('SIGTERM'); assert.deepEqual(await exited, [0, null]); });
  let diagnostic = '';
  observer.stderr.on('data', chunk => { diagnostic += chunk; });
  const ready = await new Promise((resolve, reject) => {
    let output = '';
    observer.on('error', reject);
    observer.stdout.on('data', chunk => {
      output += chunk;
      if (output.includes('\n')) resolve(JSON.parse(output.split('\n')[0]));
    });
    observer.on('exit', () => reject(Error('Observer stopped before readiness: ' + diagnostic)));
  });
  assert.equal(ready.ready, true);
  const poll = () => new Promise((resolve, reject) => {
    const request = http.request(`http://127.0.0.1:${ready.port ?? 8080}/api/worker/query-tasks/poll`, {
      method: 'POST', headers: {'X-Namespace': 'isolated', 'X-Durable-Workflow-Protocol-Version': '1.20',
        authorization: 'Bearer never-retain-this-token'},
    }, response => {
      let bytes = '';
      response.on('data', chunk => { bytes += chunk; });
      response.on('error', reject);
      response.on('end', () => resolve({status: response.statusCode, bytes}));
    });
    request.on('error', reject);
    request.end(JSON.stringify({worker_id: 'worker', task_queue: 'queue'}));
  });
  assert.deepEqual(await poll(), {status: 200, bytes: original});
  const invalid = [
    Object.fromEntries(['query_admitted_at', 'query_deadline_at', 'lease_expires_at', 'server_time'].map(key =>
      [key, `2026-02-30T00:00:${key === 'query_deadline_at' || key === 'lease_expires_at' ? '04' : '00'}.123456Z`])),
    {query_deadline_at: date + 'T00:00:04.123457Z'},
    {lease_expires_at: date + 'T00:00:04.123457Z'},
    {server_time: date + 'T00:00:00.123455Z'},
  ];
  for (const fields of invalid) {
    overrides = fields;
    await assert.rejects(poll(), /socket hang up|reset|aborted/i, JSON.stringify(fields));
  }
  const events = readFileSync(journal, 'utf8').trim().split('\n').map(line => JSON.parse(line));
  assert.equal(events.filter(event => event.event === 'claim').length, 1);
  assert.equal(events.filter(event => event.event === 'delivered').length, 1);
  assert.equal(events.filter(event => event.event === 'observer-error').length, invalid.length);
  assert.match(events.find(event => event.event === 'observer-error').message, /query_admitted_at/);
  overrides = {};
  truncated = true;
  await assert.rejects(poll(), /socket hang up|reset|aborted/i);
  const failure = readFileSync(journal, 'utf8').trim().split('\n').map(JSON.parse).at(-1);
  assert.equal(failure.event, 'observer-error');
  assert.deepEqual(failure.upstream, {
    phase: 'response', started_at: failure.upstream.started_at, failed_at: failure.upstream.failed_at,
    headers_received: true, status: 200, received_bytes: Buffer.byteLength(original),
    response_complete: false, response_aborted: true, timed_out: false,
    error_name: 'Error', error_code: 'ECONNRESET',
  });
  assert.ok(failure.upstream.failed_at >= failure.upstream.started_at);
  assert.equal(failure.downstream.request_complete, true);
  assert.equal(failure.downstream.request_aborted, false);
  assert.equal(failure.downstream.response_destroyed, false);
  assert.equal(failure.downstream.response_ended, false);
  assert.ok(JSON.stringify(failure).length < 2048);
  assert.doesNotMatch(readFileSync(journal, 'utf8') + diagnostic, /never-retain-this/);
  const disconnect = async path => {
    const countBefore = readFileSync(journal, 'utf8').trim().split('\n').length;
    const socket = net.createConnection(ready.port, '127.0.0.1');
    socket.on('error', () => {});
    socket.resume();
    await once(socket, 'connect');
    const closed = new Promise(resolve => socket.once('close', resolve));
    socket.end(`POST ${path} HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4096\r\nConnection: close\r\n\r\nnever-retain-this-request-body`);
    await closed;
    const deadline = Date.now() + 500;
    while (true) {
      const entries = readFileSync(journal, 'utf8').trim().split('\n').map(JSON.parse);
      if (entries.length > countBefore) return entries.at(-1);
      assert.ok(Date.now() < deadline, 'Observer must record the actual disconnected request');
      await new Promise(resolve => setTimeout(resolve, 1));
    }
  };
  const forwarded = upstreamRequests;
  const abandoned = await disconnect('/api/worker/query-tasks/poll');
  assert.equal(abandoned.event, 'poll-disconnected', JSON.stringify(abandoned));
  assert.equal(failure.upstream_started, true);
  assert.equal(abandoned.upstream_started, false);
  assert.equal(abandoned.error_code, 'ECONNRESET');
  assert.equal(abandoned.downstream.request_complete, false);
  assert.equal(abandoned.downstream.request_aborted, true);
  assert.equal(upstreamRequests, forwarded, 'Incomplete poll never reached the upstream');
  const terminal = await disconnect('/api/worker/query-tasks/original-query/complete');
  assert.equal(terminal.event, 'observer-error', 'Interrupted result submissions remain fatal');
  assert.equal(terminal.upstream_started, false);
  assert.equal(upstreamRequests, forwarded);
  assert.doesNotMatch(readFileSync(journal, 'utf8') + diagnostic, /never-retain-this/);
});
