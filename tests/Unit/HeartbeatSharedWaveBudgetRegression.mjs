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
const conformanceRoot = path.join(repositoryRoot, 'scripts/conformance');

const sharedServerStub = `#!/usr/bin/env bash
set -euo pipefail
action="\${1:?}"
state_file="\${2:?}"
printf '%s\\n' "$action" >>"\${BUDGET_SERVER_ACTIONS:?}"
if [[ "$action" == start ]]; then
  printf '%s\\n' '{"cell_isolation":{"php":{"namespace":"php-test"},"python":{"namespace":"python-test"},"rust":{"namespace":"rust-test"},"waterline":{"namespace":"waterline-test"}}}' >"$state_file"
fi
`;

const completedCell = `#!/usr/bin/env bash
set -euo pipefail
sleep 0.25
exit 0
`;

const resultWriter = `import fs from 'node:fs';
import path from 'node:path';

const allocation = {
  wave: Number.parseInt(process.env.MAXIMUM_SECONDS, 10),
  concurrent_cell: Number.parseInt(process.env.CELL_TIMEOUT_SECONDS, 10),
  rust_preparation: Number.parseInt(process.env.RUST_PREPARATION_TIMEOUT_SECONDS, 10),
  rust_heartbeat_execution_reserve: Number.parseInt(process.env.RUST_EXECUTION_RESERVE_SECONDS, 10),
  wave_orchestration_and_cleanup_reserve: Number.parseInt(process.env.WAVE_ORCHESTRATION_RESERVE_SECONDS, 10),
};
fs.writeFileSync(
  path.join(process.env.RESULT_DIR, 'heartbeat-shared-wave-result.json'),
  JSON.stringify({ budget_allocation_seconds: allocation }),
);
`;

function writeExecutable(file, contents) {
  fs.writeFileSync(file, contents, { mode: 0o755 });
}

function prepareFixture(root) {
  const scripts = path.join(root, 'scripts');
  const result = path.join(root, 'result');
  fs.mkdirSync(scripts);
  fs.mkdirSync(result);
  for (const script of [
    'heartbeats-wave-published-artifacts.sh',
    'heartbeats-wave-children.mjs',
  ]) {
    fs.copyFileSync(
      path.join(conformanceRoot, script),
      path.join(scripts, script),
    );
  }
  fs.chmodSync(path.join(scripts, 'heartbeats-wave-published-artifacts.sh'), 0o755);
  writeExecutable(path.join(scripts, 'heartbeats-shared-server.sh'), sharedServerStub);
  for (const runner of [
    'heartbeats-published-artifacts.sh',
    'heartbeats-python-published-artifacts.sh',
    'heartbeats-rust-published-artifacts.sh',
  ]) {
    writeExecutable(path.join(scripts, runner), completedCell);
  }
  fs.writeFileSync(path.join(scripts, 'heartbeats-wave-observer.mjs'), 'process.exit(0);\n');
  fs.writeFileSync(path.join(scripts, 'heartbeats-wave-result.mjs'), resultWriter);
  const waterlineRunner = path.join(root, 'waterline-runner.sh');
  writeExecutable(waterlineRunner, completedCell);
  return {
    result,
    runner: path.join(scripts, 'heartbeats-wave-published-artifacts.sh'),
    serverActions: path.join(root, 'server-actions'),
    waterlineRunner,
  };
}

function execute(fixture, overrides = {}) {
  const environment = {
    ...process.env,
    BUDGET_SERVER_ACTIONS: fixture.serverActions,
    DW_SERVER_VERSION: '2.0.0-rc.14',
    DW_CLI_VERSION: '2.0.0-rc.14',
    DW_PHP_SDK_VERSION: '2.0.0-rc.14',
    DW_PYTHON_SDK_VERSION: '2.0.0-rc.14',
    DW_RUST_SDK_VERSION: '2.0.0-rc.14',
    DW_WORKFLOW_PHP_VERSION: '2.0.0-rc.14',
    DW_WATERLINE_VERSION: '2.0.0-rc.14',
    DW_HEARTBEATS_WATERLINE_RUNNER: fixture.waterlineRunner,
    DW_HEARTBEATS_CHILD_SETTLE_SECONDS: '1',
  };
  for (const variable of [
    'DW_HEARTBEATS_WAVE_MAX_SECONDS',
    'DW_HEARTBEATS_CELL_TIMEOUT_SECONDS',
    'DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS',
  ]) {
    delete environment[variable];
  }
  Object.assign(environment, overrides);
  return spawnSync('bash', [fixture.runner, '--result-dir', fixture.result], {
    cwd: repositoryRoot,
    env: environment,
    encoding: 'utf8',
    timeout: 10_000,
  });
}

for (const scenario of [
  {
    name: 'default',
    overrides: {},
    expected: {
      wave: 540,
      concurrent_cell: 450,
      rust_preparation: 360,
      rust_heartbeat_execution_reserve: 90,
      wave_orchestration_and_cleanup_reserve: 90,
    },
  },
  {
    name: 'exact reserve boundary',
    overrides: {
      DW_HEARTBEATS_WAVE_MAX_SECONDS: '300',
      DW_HEARTBEATS_CELL_TIMEOUT_SECONDS: '210',
      DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS: '120',
    },
    expected: {
      wave: 300,
      concurrent_cell: 210,
      rust_preparation: 120,
      rust_heartbeat_execution_reserve: 90,
      wave_orchestration_and_cleanup_reserve: 90,
    },
  },
]) {
  test(`${scenario.name} allocation is accepted and reported as enforced`, () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-budget-valid-'));
    try {
      const fixture = prepareFixture(root);
      const execution = execute(fixture, scenario.overrides);
      assert.equal(execution.status, 0, execution.stderr);
      assert.deepEqual(
        fs.readFileSync(fixture.serverActions, 'utf8').trim().split(/\r?\n/),
        ['start', 'stop'],
      );
      const result = JSON.parse(fs.readFileSync(
        path.join(fixture.result, 'heartbeat-shared-wave-result.json'),
        'utf8',
      ));
      assert.deepEqual(result.budget_allocation_seconds, scenario.expected);
    } finally {
      fs.rmSync(root, { recursive: true, force: true });
    }
  });
}

for (const scenario of [
  {
    name: 'cell timeout consumes orchestration and cleanup reserve',
    overrides: {
      DW_HEARTBEATS_WAVE_MAX_SECONDS: '540',
      DW_HEARTBEATS_CELL_TIMEOUT_SECONDS: '451',
      DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS: '360',
    },
    error: 'must leave a 90-second orchestration and cleanup reserve',
  },
  {
    name: 'Rust preparation consumes heartbeat-execution reserve',
    overrides: {
      DW_HEARTBEATS_WAVE_MAX_SECONDS: '540',
      DW_HEARTBEATS_CELL_TIMEOUT_SECONDS: '450',
      DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS: '361',
    },
    error: 'must leave a 90-second heartbeat-execution reserve',
  },
]) {
  test(`${scenario.name} before the shared server starts`, () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-budget-invalid-'));
    try {
      const fixture = prepareFixture(root);
      const execution = execute(fixture, scenario.overrides);
      assert.equal(execution.status, 2, execution.stderr);
      assert.match(execution.stderr, new RegExp(scenario.error));
      assert.equal(fs.existsSync(fixture.serverActions), false);
    } finally {
      fs.rmSync(root, { recursive: true, force: true });
    }
  });
}
