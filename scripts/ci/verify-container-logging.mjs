import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {execFileSync, spawnSync} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const image = process.env.DW_SERVER_IMAGE;
assert.ok(image, 'DW_SERVER_IMAGE must identify the image under qualification');
const scope = process.env.DW_LOGGING_SMOKE_SCOPE || `dw-log-${randomBytes(8).toString('hex')}`;
assert.match(scope, /^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/);
const label = 'dev.durable-workflow.container-logging';
const fixture = fileURLToPath(new URL('../../tests/Fixtures/ContainerLogging/probe.php', import.meta.url));
const root = fs.mkdtempSync(path.join(process.env.RUNNER_TEMP || os.tmpdir(), `${scope}-`));
const database = `${scope}-database`;
const ini = path.join(root, 'logging-probe.ini');
fs.writeFileSync(ini, 'auto_prepend_file=/opt/container-logging-probe.php\n');
const names = new Set();
const failed = [];
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

function docker(args, timeout = 30000) {
  return execFileSync('docker', args, {encoding: 'utf8', timeout, maxBuffer: 524288}).trim();
}

function owned(name) {
  return docker(['inspect', '--format', `{{index .Config.Labels "${label}"}}`, name]) === scope;
}

function remove(name) {
  if (!names.has(name)) return;
  assert.ok(owned(name), `refuse cleanup of foreign container ${name}`);
  docker(['stop', '--time', '3', name]);
  docker(['rm', name]);
  names.delete(name);
}

const common = [
  '--user', '1000:1000', '--cpus', '1', '--memory', '512m', '--memory-swap', '512m',
  '--label', `${label}=${scope}`,
  '--mount', `type=volume,source=${database},target=/app/database`,
  '--env', 'DB_CONNECTION=sqlite', '--env', 'DB_DATABASE=/app/database/database.sqlite',
  '--env', 'DB_SQLITE_JOURNAL_MODE=DELETE', '--env', 'CACHE_STORE=file',
  '--env', 'QUEUE_CONNECTION=database', '--env', 'DW_AUTH_DRIVER=token',
  '--env', 'DW_AUTH_TOKEN=container-logging-synthetic-token',
];

function copy(name, source, target) {
  const result = spawnSync('docker', ['cp', `${name}:${source}`, target], {
    encoding: 'utf8', timeout: 10000, maxBuffer: 524288,
  });
  return result.status === 0 ? fs.readFileSync(target, 'utf8') : '';
}

async function check(role, mode) {
  const name = `${scope}-${mode}-${role}`;
  const nonce = randomBytes(16).toString('hex');
  const args = ['run', '--detach', '--name', name, ...common,
    '--mount', `type=bind,source=${fixture},target=/opt/container-logging-probe.php,readonly`,
    '--mount', `type=bind,source=${ini},target=/usr/local/etc/php/conf.d/zz-container-logging-probe.ini,readonly`,
    '--env', `CONTAINER_LOGGING_PROBE_ROLE=${role}`,
    '--env', `CONTAINER_LOGGING_PROBE_NONCE=${nonce}`];
  if (mode === 'json') args.push('--env', 'LOG_STDERR_FORMATTER=Monolog\\Formatter\\JsonFormatter');
  if (mode === 'file') args.push('--env', 'LOG_CHANNEL=single');
  if (mode === 'filtered') args.push('--env', 'LOG_LEVEL=critical');
  if (role === 'api') args.push('--publish', '127.0.0.1::8080');
  args.push(image);
  if (role === 'worker') args.push('php', 'artisan', 'queue:work', '--once', '--stop-when-empty', '--max-time=2');
  if (role === 'scheduler') args.push('php', 'artisan', 'schedule:evaluate', '--limit=100', '--json');

  assert.notEqual(spawnSync('docker', ['inspect', name], {stdio: 'ignore'}).status, 0,
    `container name already exists: ${name}`);
  docker(args);
  names.add(name);
  try {
    if (role === 'api') {
      const port = docker(['port', name, '8080/tcp']).split(':').at(-1);
      let ready = false;
      for (let attempt = 0; attempt < 100; attempt += 1) {
        try {
          const response = await fetch(`http://127.0.0.1:${port}/api/health`, {signal: AbortSignal.timeout(1000)});
          await response.text();
          if (response.status === 200) {ready = true; break;}
        } catch { /* The real API may still be starting. */ }
        await pause(100);
      }
      assert.ok(ready, 'API did not become live within its original probe budget');
    } else {
      assert.equal(docker(['wait', name]), '0', `${role} command must exit successfully`);
    }

    const proof = copy(name, '/tmp/dw-container-logging-probe', path.join(root, `${mode}-${role}.proof`));
    assert.equal(proof, nonce, `${role}: actual role must invoke the default Laravel logger`);
    const logs = spawnSync('docker', ['logs', name], {encoding: 'utf8', timeout: 10000, maxBuffer: 524288});
    assert.equal(logs.status, 0);
    const file = copy(name, '/app/storage/logs/laravel.log', path.join(root, `${mode}-${role}.log`));
    const stderr = logs.stderr.split('\n').filter(line => line.includes(nonce));
    assert.ok(!logs.stdout.includes(nonce), `${role}: application error must not go to stdout`);
    if (mode === 'file') {
      assert.ok(file.includes(nonce), 'operator-selected file channel must be honored');
      assert.equal(stderr.length, 0);
    } else if (mode === 'filtered') {
      assert.equal(stderr.length, 0, 'LOG_LEVEL must filter the emitted error');
      assert.ok(!file.includes(nonce));
    } else {
      assert.ok(stderr.length > 0, `${role}/${mode}: application error missing from container stderr`);
      assert.ok(!file.includes(nonce), `${role}: default stderr must not duplicate the error into a local file`);
      if (mode === 'json') {
        for (const line of stderr) {
          const record = JSON.parse(line);
          assert.equal(record.message, 'container-logging-probe');
          assert.equal(record.context.role, role);
          assert.equal(record.context.nonce, nonce);
        }
      }
    }
    console.log(JSON.stringify({role, mode, outcome: 'PASS'}));
  } finally {
    remove(name);
  }
}

async function main() {
try {
  docker(['volume', 'create', '--label', `${label}=${scope}`, database]);
  const bootstrap = `${scope}-bootstrap`;
  docker(['run', '--detach', '--name', bootstrap, ...common, image, 'server-bootstrap']);
  names.add(bootstrap);
  assert.equal(docker(['wait', bootstrap], 60000), '0', 'isolated database bootstrap must succeed');
  remove(bootstrap);
  for (const mode of ['default', 'json']) {
    for (const role of ['api', 'worker', 'scheduler']) {
      try {await check(role, mode);} catch (error) {failed.push(`${role}/${mode}: ${error.message}`);}
    }
  }
  for (const mode of ['file', 'filtered']) {
    try {await check('worker', mode);} catch (error) {failed.push(`worker/${mode}: ${error.message}`);}
  }
  assert.deepEqual(failed, [], 'container logging acceptance failed');
  console.log('Container logging acceptance: 8 PASS');
} finally {
  for (const name of names) remove(name);
  assert.equal(docker(['volume', 'inspect', '--format', `{{index .Labels "${label}"}}`, database]), scope,
    'refuse cleanup of a foreign volume');
  docker(['volume', 'rm', database]);
  fs.rmSync(root, {recursive: true});
}
}

main().catch(error => {console.error(error.message.slice(0, 4000)); process.exitCode = 1;});
