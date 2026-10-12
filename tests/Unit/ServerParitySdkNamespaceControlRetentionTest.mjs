import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {existsSync, mkdtempSync, readFileSync, rmSync} from 'node:fs';
import http from 'node:http';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';

// The namespace profile installs the locked SDK and requires this runtime
// regression. Pure Node reader runs cannot execute PHP/SDK subprocesses.
test('forced namespace setup loss preserves its unanswered request and stage', {
  skip: !process.env.DW_PARITY_SDK_AUTOLOAD,
  timeout: 15000,
}, async () => {
  const evidence = mkdtempSync(join(tmpdir(), 'namespace-retention-'));
  const id = 'namespace-retention-original';
  const base = join(evidence, 'sdk-namespace-signal-' + id + '-control');
  const requests = [], sockets = new Set();
  const server = http.createServer(request => requests.push({method: request.method, path: request.url}));
  server.on('connection', socket => { sockets.add(socket); socket.on('close', () => sockets.delete(socket)); });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const probe = process.env.DW_PARITY_RETENTION_TEST_PROBE
    ?? new URL('../../scripts/conformance/server-parity/sdk-namespace-signal-probe.php', import.meta.url).pathname;
  const fixture = new URL('../Fixtures/ServerParityPending/published-sdk-namespace-signals/signal-namespace-sdk-php-php.json', import.meta.url).pathname;
  const program = 'require getenv("DW_PARITY_SDK_AUTOLOAD"); require getenv("RETENTION_TEST_PROBE"); '
    + '$fixture=json_decode(file_get_contents(getenv("RETENTION_TEST_FIXTURE")),true,flags:JSON_THROW_ON_ERROR); '
    + 'namespaceSignalHttpObservation($fixture,["workflow-id"=>"namespace-retention-original","url"=>getenv("RETENTION_TEST_URL")]);';
  const child = spawn('php', ['-r', program], {detached: true, stdio: ['ignore', 'pipe', 'pipe'], env: {
    ...process.env, DW_PARITY_FAILURE_EVIDENCE_DIR: evidence, DW_PARITY_TOKEN: 'parity-test-token',
    RETENTION_TEST_PROBE: probe, RETENTION_TEST_FIXTURE: fixture,
    RETENTION_TEST_URL: 'http://127.0.0.1:' + server.address().port,
  }});
  let output = '', exited = false;
  child.stdout.on('data', bytes => { output += bytes.toString(); });
  child.stderr.on('data', bytes => { output += bytes.toString(); });
  const exit = new Promise(resolve => child.once('exit', () => { exited = true; resolve(); }));
  const stop = () => { try { process.kill(-child.pid, 'SIGKILL'); } catch (error) { if (error.code !== 'ESRCH') throw error; } };
  try {
    const deadline = Date.now() + 10000;
    while ((!existsSync(base + '-arrivals.jsonl') || readFileSync(base + '-arrivals.jsonl').length === 0)
      && !exited && Date.now() < deadline) await new Promise(resolve => setTimeout(resolve, 20));
    assert.equal(exited, false, output);
    assert(requests.some(request => request.method === 'GET' && request.path === '/api/namespaces/parity-signals-left'), output);
    stop(); await exit;
    const progress = JSON.parse(readFileSync(base + '-progress.json'));
    assert.equal(progress.stage, 'namespace_describe');
    assert.equal(progress.namespace, 'parity-signals-left'); assert.equal(progress.workflow_id, id);
    assert.equal(progress.pair_deadline_at_unix - progress.pair_started_at_unix, 70);
    assert.deepEqual(progress.run_budgets, [], 'setup must not invent or refresh an original run deadline');
    const arrivals = readFileSync(base + '-arrivals.jsonl', 'utf8').trim().split('\n').map(JSON.parse);
    assert.equal(arrivals.length, 1); assert.equal(arrivals[0].method, 'GET');
    assert.equal(arrivals[0].path, '/api/namespaces/parity-signals-left');
    assert.equal(arrivals[0].request_body, ''); assert.equal(arrivals[0].request_body_complete, true);
    assert.equal(arrivals[0].upstream_request_started, true); assert.equal(arrivals[0].response_observed, false);
    for (const field of ['headers', 'authorization', 'token']) assert.equal(arrivals[0][field], undefined);
    assert.equal(readFileSync(base + '-http.jsonl', 'utf8'), '', 'no completed response may be fabricated');
    assert.equal(existsSync(base + '.json'), false, 'forced loss must retain partial evidence instead of a final receipt');
  } finally {
    stop(); await exit;
    for (const socket of sockets) socket.destroy();
    await new Promise(resolve => server.close(resolve));
    rmSync(evidence, {recursive: true, force: true});
  }
});
