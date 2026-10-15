import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

test('observer forwards valid canonical metadata bytes and refuses normalized invalid calendar dates', {timeout: 5000}, async t => {
  const directory = mkdtempSync(join(tmpdir(), 'query-observer-'));
  t.after(() => rmSync(directory, {recursive: true, force: true}));
  const journal = join(directory, 'journal.jsonl');
  let date = '2026-02-28';
  let original;
  const upstream = http.createServer(async (request, response) => {
    for await (const _chunk of request) { /* Drain this constructed poll body. */ }
    original = JSON.stringify({task: {
      query_task_id: 'original-query', query_task_attempt: 1,
      namespace: 'isolated', task_queue: 'queue', lease_owner: 'worker',
      workflow_id: 'workflow', run_id: 'run',
      query_admitted_at: date + 'T00:00:00.123456Z',
      query_deadline_at: date + 'T00:00:04.123456Z',
      lease_expires_at: date + 'T00:00:04.123456Z',
      server_time: date + 'T00:00:00.123456Z',
    }});
    response.writeHead(200, {'content-type': 'application/json'});
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
      method: 'POST', headers: {'X-Namespace': 'isolated', 'X-Durable-Workflow-Protocol-Version': '1.20'},
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
  date = '2026-02-30';
  await assert.rejects(poll(), /socket hang up|reset|aborted/i);
  const events = readFileSync(journal, 'utf8').trim().split('\n').map(line => JSON.parse(line));
  assert.equal(events.filter(event => event.event === 'claim').length, 1);
  assert.equal(events.filter(event => event.event === 'delivered').length, 1);
  assert.match(events.find(event => event.event === 'observer-error').message, /query_admitted_at/);
});
