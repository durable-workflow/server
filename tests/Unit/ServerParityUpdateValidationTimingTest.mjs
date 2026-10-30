import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';

// Run the actual background request program, with its real retry policy,
// against HTTP responses. No SDK, worker or frozen timing bounds are replaced.
const source = readFileSync(new URL('../../scripts/conformance/server-parity/update-validation-probe.php', import.meta.url), 'utf8');
const childProgram = source.match(/\$child = <<<'PHP'\n([\s\S]*?)\nPHP;/)?.[1];
const retryRule = fileURLToPath(new URL('../../scripts/conformance/server-parity/lock-pressure-retry.php', import.meta.url));
const path = 'workflows/timing-workflow/update/approve';
const body = '{"value":{"original":true},"idempotency_key":"same-request"}';
const pressure = {reason: 'backend_lock_pressure', retryable: true, retry_after_seconds: 0.1};
const cached = {accepted: false, reason: 'deduped_existing_command', run_id: 'original-run', command_id: 'original-command'};

async function request(t, replies) {
  assert(childProgram, 'background request program must be present');
  const received = [];
  const timers = new Set();
  const server = http.createServer((incoming, response) => {
    const chunks = [];
    incoming.on('data', bytes => chunks.push(bytes));
    incoming.on('end', () => {
      received.push({method: incoming.method, path: incoming.url, body: Buffer.concat(chunks).toString()});
      const reply = replies[received.length - 1] ?? {status: 500, body: {unexpected_request: true}};
      const timer = setTimeout(() => {
        timers.delete(timer);
        response.writeHead(reply.status, {'content-type': 'application/json'});
        response.end(JSON.stringify(reply.body));
      }, reply.delay ?? 0);
      timers.add(timer);
    });
  });
  let child;
  let closed;
  t.after(async () => {
    if (child && child.exitCode === null && child.signalCode === null) child.kill();
    // The request reports spawn errors; cleanup must still release the server.
    if (closed) await closed.catch(() => undefined);
    for (const timer of timers) clearTimeout(timer);
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
  });
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  child = spawn('php', ['-r', childProgram, '--', `http://127.0.0.1:${server.address().port}`, '', path, body, retryRule],
    {stdio: ['ignore', 'pipe', 'pipe']});
  closed = once(child, 'close');
  let stdout = '', stderr = '';
  child.stdout.on('data', bytes => {stdout += bytes;});
  child.stderr.on('data', bytes => {stderr += bytes;});
  const [code, signal] = await closed;
  assert.equal(code, 0, stderr);
  assert.equal(signal, null);
  assert.deepEqual(received, replies.map(() => ({method: 'POST', path: '/api/' + path, body})));
  return {...JSON.parse(stdout), stderr};
}

test('elapsed time includes the refused attempt and backoff before an accepted update', {timeout: 5000}, async t => {
  const accepted = {accepted: true, run_id: 'original-run', command_id: 'original-command'};
  const result = await request(t, [{status: 503, body: pressure, delay: 60}, {status: 202, body: accepted}]);
  assert.equal(result.status, 202);
  assert.deepEqual(result.response, accepted);
  assert(result.seconds >= 0.15, `whole request took ${result.seconds}s`);
  assert.deepEqual(JSON.parse(result.stderr), {lock_pressure_update_retries: {path, delays: [0.1]}});
});

test('elapsed time includes every refused attempt and backoff before the original cached receipt', {timeout: 5000}, async t => {
  const result = await request(t, [{status: 503, body: pressure, delay: 40},
    {status: 503, body: pressure, delay: 40}, {status: 200, body: cached}]);
  assert.equal(result.status, 200);
  assert.deepEqual(result.response, cached);
  assert(result.seconds >= 0.27, `whole request took ${result.seconds}s`);
  assert.deepEqual(JSON.parse(result.stderr), {lock_pressure_update_retries: {path, delays: [0.1, 0.1]}});
});

test('an immediate cached receipt keeps its original response without retry', {timeout: 5000}, async t => {
  const result = await request(t, [{status: 200, body: cached}]);
  assert.equal(result.status, 200);
  assert.deepEqual(result.response, cached);
  assert(result.seconds >= 0);
  assert.equal(result.stderr, '');
});

test('an unrelated server failure keeps its original response without retry', {timeout: 5000}, async t => {
  const failure = {reason: 'unrelated_failure', retryable: true};
  const result = await request(t, [{status: 500, body: failure}]);
  assert.equal(result.status, 500);
  assert.deepEqual(result.response, failure);
  assert(result.seconds >= 0);
  assert.equal(result.stderr, '');
});
