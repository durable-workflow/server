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
const { spawn } = require('node:child_process');
const statePath = process.env.FAKE_DOCKER_STATE;
const args = process.argv.slice(2);
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
const save = () => {
  const temporary = statePath + '.tmp-' + process.pid;
  fs.writeFileSync(temporary, JSON.stringify(state));
  fs.renameSync(temporary, statePath);
};
const output = (values) => {
  if (values.length > 0) process.stdout.write(values.join('\\n') + '\\n');
};
if (Array.isArray(state.commands)) {
  state.commands.push(args);
  save();
}
if (state.hang_command === 'volume-ls'
  && args[0] === 'volume'
  && args[1] === 'ls') {
  const descendant = spawn(process.execPath, [
    '-e',
    "process.on('SIGTERM', () => {}); setInterval(() => {}, 1000);",
  ], { stdio: 'ignore' });
  state.descendant_pid = descendant.pid;
  save();
  process.stdout.write(
    'hung inventory stdout remains available\\n' + 'o'.repeat(80 * 1024),
  );
  process.stderr.write(
    'hung inventory stderr remains available\\n' + 'e'.repeat(80 * 1024),
  );
  process.on('SIGTERM', () => {
    state.sigterm_ignored = true;
    save();
  });
  setInterval(() => {}, 1000);
} else if (args[0] === 'compose' && args.includes('down')) {
  state.containers = [];
  state.volumes = state.persistent_volume ? [state.persistent_volume] : [];
  state.networks = [];
  state.race_ready = state.inject_race === true;
  save();
  process.exit(0);
} else if (args[0] === 'container' && args[1] === 'inspect') {
  process.stderr.write('No such container\\n');
  process.exit(1);
} else if (args[0] === 'ps') {
  if (state.race_ready) {
    state.race_ready = false;
    state.inject_race = false;
    state.containers = ['mysql-created', 'redis-created', 'server-created', 'bootstrap-created'];
    state.volumes = ['mysql-data', 'redis-data'];
    state.networks = [state.project + '_default'];
    save();
  }
  output(state.containers);
  process.exit(0);
} else if (args[0] === 'volume' && args[1] === 'ls') {
  output(state.volumes);
  process.exit(0);
} else if (args[0] === 'network' && args[1] === 'ls') {
  output(state.networks);
  process.exit(0);
} else if (args[0] === 'network' && args[1] === 'inspect') {
  const network = args.at(-1);
  if (!state.networks.includes(network)) {
    process.stderr.write('network not found\\n');
    process.exit(1);
  }
  process.stdout.write('{}\\n');
  process.exit(0);
} else if (args[0] === 'rm' && args[1] === '-f') {
  state.containers = state.containers.filter((value) => value !== args[2]);
  save();
  process.exit(0);
} else if (args[0] === 'volume' && args[1] === 'rm') {
  const name = args.at(-1);
  if (state.persistent_volume === name) {
    process.stderr.write('volume is in use by an owned container\\n');
    process.exit(1);
  }
  state.volumes = state.volumes.filter((value) => value !== name);
  save();
  process.exit(0);
} else if (args[0] === 'network' && args[1] === 'rm') {
  state.networks = state.networks.filter((value) => value !== args[2]);
  save();
  process.exit(0);
} else {
  process.stderr.write('unsupported fake docker command: ' + args.join(' ') + '\\n');
  process.exit(2);
}
`;

function sharedState(project, overrideFile) {
  return {
    schema: 'durable-workflow.v2.heartbeat-runtime.shared-server-bootstrap',
    version: 1,
    server: {
      version: '2.0.0-beta.18',
      requested_reference: 'durableworkflow/server:2.0.0-beta.18',
    },
    compose: {
      project,
      base_file: 'docker-compose.published.yml',
      override_file: path.basename(overrideFile),
      network: `${project}_default`,
    },
    endpoint: {
      port: 18080,
      host_url: 'http://127.0.0.1:18080',
      host_control_url: 'http://127.0.0.1:18080',
      container_url: 'http://server:8080',
      transport: {
        mode: 'published_loopback',
        relay_pid: null,
        relay_pid_file: null,
        host_control_url: 'http://127.0.0.1:18080',
        compose_network: `${project}_default`,
        executor_network_attached: false,
        attachment_owned_by_wave: false,
      },
    },
    lifecycle: {
      owner: 'heartbeat-wave-runner',
      cleanup_required: true,
      cleanup_status: 'pending',
    },
  };
}

function fixture(options = {}) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-cleanup-'));
  const bin = path.join(root, 'bin');
  const result = path.join(root, 'result');
  fs.mkdirSync(bin);
  fs.mkdirSync(path.join(result, 'php'), { recursive: true });
  fs.mkdirSync(path.join(result, 'waterline'), { recursive: true });
  const docker = path.join(bin, 'docker');
  fs.writeFileSync(docker, fakeDockerSource, { mode: 0o755 });
  const project = 'dw-hb-wave-cleanup-test';
  const dockerStatePath = path.join(root, 'docker-state.json');
  const dockerState = {
    project,
    containers: [],
    volumes: [],
    networks: [],
    inject_race: options.injectRace === true,
    race_ready: false,
    persistent_volume: options.persistentVolume ?? null,
  };
  if (options.hangCommand) {
    dockerState.hang_command = options.hangCommand;
    dockerState.commands = [];
  }
  fs.writeFileSync(dockerStatePath, JSON.stringify(dockerState));
  const overrideFile = path.join(result, 'docker-compose.heartbeat-test.yml');
  fs.writeFileSync(overrideFile, 'services: {}\n');
  fs.mkdirSync(path.join(result, 'php', 'php-heartbeat-run.leftover'));
  fs.mkdirSync(path.join(result, 'waterline', 'waterline-worker-status-run.leftover'));
  const statePath = path.join(result, 'shared-server-state.json');
  fs.writeFileSync(statePath, JSON.stringify(sharedState(project, overrideFile)));
  return {
    root,
    result,
    statePath,
    dockerStatePath,
    execute: () => spawnSync(process.execPath, [lifecycleRunner, 'stop', statePath], {
      cwd: repositoryRoot,
      env: {
        ...process.env,
        PATH: `${bin}:${process.env.PATH}`,
        REPO_ROOT: repositoryRoot,
        FAKE_DOCKER_STATE: dockerStatePath,
        DW_HEARTBEATS_CLEANUP_TIMEOUT_SECONDS:
          String(options.cleanupTimeoutSeconds ?? 3),
      },
      encoding: 'utf8',
      timeout: 10_000,
    }),
  };
}

function processSettled(pid) {
  try {
    const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    const commandEnd = stat.lastIndexOf(')');
    return commandEnd >= 0 && stat.slice(commandEnd + 2, commandEnd + 3) === 'Z';
  } catch (error) {
    if (error?.code === 'ENOENT') return true;
    throw error;
  }
}

test('shared cleanup survives a post-down resource race and proves stable absence', () => {
  const run = fixture({
    injectRace: true,
    cleanupTimeoutSeconds: 6,
  });
  try {
    const execution = run.execute();
    assert.equal(execution.status, 0, execution.stderr);
    const state = JSON.parse(fs.readFileSync(run.statePath, 'utf8'));
    assert.equal(state.lifecycle.cleanup_status, 'pass');
    assert.equal(state.lifecycle.cleanup_verification.stable_empty_observations, 3);
    assert.deepEqual(state.lifecycle.cleanup_resources_remaining, {
      containers: [],
      volumes: [],
      networks: [],
      attached_containers: [],
      sandbox_artifacts: [],
    });
    assert.deepEqual(
      new Set(state.lifecycle.fallback_removed.containers),
      new Set(['mysql-created', 'redis-created', 'server-created', 'bootstrap-created']),
    );
    assert.deepEqual(
      new Set(state.lifecycle.fallback_removed.volumes),
      new Set(['mysql-data', 'redis-data']),
    );
    assert.equal(
      state.lifecycle.fallback_removed.networks.includes('dw-hb-wave-cleanup-test_default'),
      true,
    );
    assert.deepEqual(JSON.parse(fs.readFileSync(run.dockerStatePath, 'utf8')), {
      project: 'dw-hb-wave-cleanup-test',
      containers: [],
      volumes: [],
      networks: [],
      inject_race: false,
      race_ready: false,
      persistent_volume: null,
    });
    const diagnostics = JSON.parse(fs.readFileSync(
      path.join(run.result, 'shared-server-cleanup-diagnostics.json'),
      'utf8',
    ));
    assert.equal(diagnostics.stable_empty_observations, 3);
    assert.equal(
      diagnostics.observations.some((observation) =>
        observation.resources.containers.includes('server-created')),
      true,
    );
  } finally {
    fs.rmSync(run.root, { recursive: true, force: true });
  }
});

test('persistent resource residue is non-passing with bounded decisive diagnostics', () => {
  const run = fixture({ persistentVolume: 'mysql-data' });
  try {
    const execution = run.execute();
    assert.equal(execution.status, 1, execution.stderr);
    const state = JSON.parse(fs.readFileSync(run.statePath, 'utf8'));
    assert.equal(state.lifecycle.cleanup_status, 'fail');
    assert.deepEqual(state.lifecycle.cleanup_resources_remaining.volumes, ['mysql-data']);
    assert.equal(
      state.lifecycle.cleanup_failures.some((failure) =>
        failure.includes('volumes remain: mysql-data')),
      true,
    );
    assert.ok(state.lifecycle.cleanup_verification.elapsed_ms < 7_000);
    const diagnostics = JSON.parse(fs.readFileSync(
      path.join(run.result, 'shared-server-cleanup-diagnostics.json'),
      'utf8',
    ));
    assert.equal(
      diagnostics.removal_attempts.some((attempt) =>
        attempt.name === 'mysql-data'
        && attempt.stderr.includes('volume is in use')),
      true,
    );
  } finally {
    fs.rmSync(run.root, { recursive: true, force: true });
  }
});

test('a SIGTERM-ignoring inventory process group cannot extend the cleanup deadline', () => {
  const run = fixture({
    hangCommand: 'volume-ls',
    cleanupTimeoutSeconds: 1,
  });
  try {
    const started = performance.now();
    const execution = run.execute();
    const elapsed = performance.now() - started;
    assert.equal(execution.status, 1, execution.stderr);
    assert.ok(elapsed < 2_500, `cleanup process ran for ${elapsed}ms`);

    const state = JSON.parse(fs.readFileSync(run.statePath, 'utf8'));
    assert.equal(state.lifecycle.cleanup_status, 'fail');
    assert.equal(state.lifecycle.cleanup_verification.deadline_exhausted, true);
    assert.ok(
      state.lifecycle.cleanup_verification.elapsed_ms
        <= state.lifecycle.cleanup_verification.timeout_ms + 250,
      JSON.stringify(state.lifecycle.cleanup_verification),
    );
    assert.equal(
      state.lifecycle.cleanup_failures.some((failure) =>
        failure.includes('wall-clock deadline')),
      true,
    );

    const dockerState = JSON.parse(fs.readFileSync(run.dockerStatePath, 'utf8'));
    assert.equal(
      dockerState.commands.some((args) =>
        args[0] === 'volume' && args[1] === 'ls'),
      true,
    );
    assert.equal(
      dockerState.commands.some((args) =>
        args[0] === 'network' && args[1] === 'ls'),
      false,
    );
    assert.equal(dockerState.sigterm_ignored, true);
    assert.equal(processSettled(dockerState.descendant_pid), true);

    const diagnostics = JSON.parse(fs.readFileSync(
      path.join(run.result, 'shared-server-cleanup-diagnostics.json'),
      'utf8',
    ));
    assert.equal(diagnostics.deadline_exhausted, true);
    assert.equal(
      diagnostics.observations[0].commands.volumes.deadline_exhausted,
      true,
    );
    assert.equal(diagnostics.observations[0].commands.volumes.status, null);
    assert.equal(diagnostics.observations[0].commands.volumes.signal, 'SIGKILL');
    assert.equal(diagnostics.observations[0].commands.volumes.timed_out, true);
    assert.deepEqual(
      diagnostics.observations[0].commands.volumes.termination,
      { signal: 'SIGTERM', sent: true, error: null },
    );
    assert.deepEqual(
      diagnostics.observations[0].commands.volumes.escalation,
      { signal: 'SIGKILL', sent: true, error: null },
    );
    assert.equal(diagnostics.observations[0].commands.volumes.reaped, true);
    assert.equal(
      diagnostics.observations[0].commands.volumes.descendants_settled,
      true,
    );
    assert.deepEqual(
      diagnostics.observations[0].commands.volumes.unsettled_processes,
      [],
    );
    assert.equal(
      diagnostics.observations[0].commands.volumes.stdout.startsWith(
        'hung inventory stdout remains available\n',
      ),
      true,
    );
    assert.equal(
      diagnostics.observations[0].commands.volumes.stderr.startsWith(
        'hung inventory stderr remains available\n',
      ),
      true,
    );
    assert.equal(diagnostics.observations[0].commands.volumes.stdout_truncated, true);
    assert.equal(diagnostics.observations[0].commands.volumes.stderr_truncated, true);
    assert.ok(
      Buffer.byteLength(diagnostics.observations[0].commands.volumes.stdout)
        <= 64 * 1024,
    );
    assert.ok(
      Buffer.byteLength(diagnostics.observations[0].commands.volumes.stderr)
        <= 64 * 1024,
    );
    assert.equal(
      diagnostics.observations[0].commands.networks.error,
      'cleanup deadline exhausted before command could start',
    );
  } finally {
    fs.rmSync(run.root, { recursive: true, force: true });
  }
});
