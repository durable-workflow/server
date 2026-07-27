import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..',
);
const lifecycleRunner = path.join(
  repositoryRoot,
  'scripts/conformance/heartbeats-shared-server.mjs',
);

const fakeDockerSource = `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const statePath = process.env.FAKE_DOCKER_STATE;
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
state.commands.push(args);
const save = () => fs.writeFileSync(statePath, JSON.stringify(state));

if (args[0] === 'compose' && args.includes('config')) {
  const overridePath = args[args.lastIndexOf('-f') + 1];
  const override = fs.readFileSync(overridePath, 'utf8');
  state.override = override;
  const forcedPersistent = process.env.FAKE_PERSISTENT_STORAGE;
  const mysqlTmpfs = forcedPersistent !== 'mysql'
    && override.includes('      - type: tmpfs\\n        target: /var/lib/mysql\\n');
  const redisTmpfs = forcedPersistent !== 'redis'
    && override.includes('      - type: tmpfs\\n        target: /data\\n');
  save();
  process.stdout.write(JSON.stringify({
    services: {
      mysql: {
        volumes: [mysqlTmpfs
          ? { type: 'tmpfs', target: '/var/lib/mysql' }
          : { type: 'volume', source: 'mysql_data', target: '/var/lib/mysql' }],
      },
      redis: {
        volumes: [redisTmpfs
          ? { type: 'tmpfs', target: '/data' }
          : { type: 'volume', source: 'redis_data', target: '/data' }],
      },
    },
  }));
  process.exit(0);
}
if (args[0] === 'pull') {
  state.pull_attempted = true;
  save();
  process.stderr.write('intentional pull stop\\n');
  process.exit(42);
}
if (args[0] === 'compose' && args.includes('down')) {
  save();
  process.exit(0);
}
save();
process.stderr.write('unexpected fake docker command: ' + args.join(' ') + '\\n');
process.exit(2);
`;

function executeStart(options = {}) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-storage-'));
  const bin = path.join(root, 'bin');
  const result = path.join(root, 'result');
  fs.mkdirSync(bin);
  fs.mkdirSync(result);
  const docker = path.join(bin, 'docker');
  fs.writeFileSync(docker, fakeDockerSource, { mode: 0o755 });
  const dockerStatePath = path.join(root, 'docker-state.json');
  fs.writeFileSync(dockerStatePath, JSON.stringify({
    commands: [],
    pull_attempted: false,
    override: null,
  }));
  const statePath = path.join(result, 'shared-server-state.json');
  const execution = spawnSync(process.execPath, [lifecycleRunner, 'start', statePath], {
    cwd: repositoryRoot,
    env: {
      ...process.env,
      PATH: `${bin}:${process.env.PATH}`,
      REPO_ROOT: repositoryRoot,
      FAKE_DOCKER_STATE: dockerStatePath,
      FAKE_PERSISTENT_STORAGE: options.persistentService ?? '',
      DW_SERVER_VERSION: '2.0.0-beta.18',
      DW_SERVER_IMAGE: 'durableworkflow/server:2.0.0-beta.18',
    },
    encoding: 'utf8',
    timeout: 10_000,
  });
  const dockerState = JSON.parse(fs.readFileSync(dockerStatePath, 'utf8'));
  fs.rmSync(root, { recursive: true, force: true });
  return { execution, dockerState };
}

test('shared heartbeat override replaces database and Redis volumes with tmpfs', () => {
  const { execution, dockerState } = executeStart();

  assert.notEqual(execution.status, 0);
  assert.match(execution.stderr, /intentional pull stop/);
  assert.equal(dockerState.pull_attempted, true);
  assert.match(
    dockerState.override,
    /mysql:\n {4}volumes:\n {6}- type: tmpfs\n {8}target: \/var\/lib\/mysql\n/,
  );
  assert.match(
    dockerState.override,
    /redis:\n {4}volumes:\n {6}- type: tmpfs\n {8}target: \/data\n/,
  );
  assert.doesNotMatch(dockerState.override, /mysql_data|redis_data/);
});

for (const [service, target] of [
  ['mysql', '/var/lib/mysql'],
  ['redis', '/data'],
]) {
  test(`shared heartbeat bootstrap refuses effective persistent ${service} storage`, () => {
    const { execution, dockerState } = executeStart({ persistentService: service });

    assert.notEqual(execution.status, 0);
    assert.match(
      execution.stderr,
      new RegExp(
        `effective Compose storage for ${service}:${target} must be one source-free tmpfs mount`,
      ),
    );
    assert.equal(dockerState.pull_attempted, false);
  });
}
