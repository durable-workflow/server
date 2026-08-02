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
if [[ "$action" == start ]]; then
  printf '%s\\n' '{"cell_isolation":{"php":{"namespace":"php-test"},"python":{"namespace":"python-test"},"rust":{"namespace":"rust-test"},"waterline":{"namespace":"waterline-test"}}}' >"$state_file"
  exit 0
fi
if [[ "$action" == stop ]]; then
  exit 0
fi
exit 2
`;

const completedCell = `#!/usr/bin/env bash
set -euo pipefail
result_dir=""
while [[ $# -gt 0 ]]; do
  if [[ "$1" == --result-dir ]]; then
    result_dir="$2"
    break
  fi
  shift
done
printf '%s\\n' '{"outcome":"pass"}' >"\${result_dir:?}/completed-peer-evidence.json"
sleep 0.25
`;

const timedOutCell = `#!/usr/bin/env bash
set -euo pipefail
sleep 0.25
exit 124
`;

function writeExecutable(file, contents) {
  fs.writeFileSync(file, contents, { mode: 0o755 });
}

test('a timed-out cell retains status 124 and completed peer files', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-wait-status-'));
  const scripts = path.join(root, 'scripts');
  const result = path.join(root, 'result');
  fs.mkdirSync(scripts);
  fs.mkdirSync(result);

  try {
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
    writeExecutable(path.join(scripts, 'heartbeats-published-artifacts.sh'), completedCell);
    writeExecutable(path.join(scripts, 'heartbeats-python-published-artifacts.sh'), completedCell);
    writeExecutable(path.join(scripts, 'heartbeats-rust-published-artifacts.sh'), timedOutCell);
    fs.writeFileSync(
      path.join(scripts, 'heartbeats-wave-observer.mjs'),
      'process.exit(0);\n',
    );
    fs.writeFileSync(
      path.join(scripts, 'heartbeats-wave-result.mjs'),
      'process.exit(0);\n',
    );
    const waterlineRunner = path.join(root, 'waterline-runner.sh');
    writeExecutable(waterlineRunner, completedCell);

    const execution = spawnSync(
      'bash',
      [path.join(scripts, 'heartbeats-wave-published-artifacts.sh'), '--result-dir', result],
      {
        cwd: repositoryRoot,
        env: {
          ...process.env,
          DW_SERVER_VERSION: '2.0.0-beta.18',
          DW_CLI_VERSION: '2.0.0-beta.18',
          DW_PHP_SDK_VERSION: '2.0.0-beta.18',
          DW_PYTHON_SDK_VERSION: '2.0.0-beta.18',
          DW_RUST_SDK_VERSION: '2.0.0-beta.18',
          DW_WORKFLOW_PHP_VERSION: '2.0.0-beta.18',
          DW_WATERLINE_VERSION: '2.0.0-beta.18',
          DW_HEARTBEATS_WATERLINE_RUNNER: waterlineRunner,
          DW_HEARTBEATS_WAVE_MAX_SECONDS: '181',
          DW_HEARTBEATS_CELL_TIMEOUT_SECONDS: '91',
          DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS: '1',
          DW_HEARTBEATS_CHILD_SETTLE_SECONDS: '2',
        },
        encoding: 'utf8',
        timeout: 10_000,
      },
    );

    assert.equal(execution.status, 0, execution.stderr);
    assert.equal(fs.readFileSync(path.join(result, 'rust/exit-code'), 'utf8'), '124\n');
    const children = JSON.parse(fs.readFileSync(
      path.join(result, 'heartbeat-shared-wave-children.json'),
      'utf8',
    ));
    assert.equal(children.cells.rust.exit_code, 124);
    assert.equal(children.cells.rust.settled, true);
    for (const cell of ['php', 'python', 'waterline']) {
      assert.equal(children.cells[cell].exit_code, 0);
      assert.equal(
        fs.existsSync(path.join(result, cell, 'completed-peer-evidence.json')),
        true,
      );
    }
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
