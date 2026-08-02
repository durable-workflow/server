import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawn } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  '../..',
);
const waveRunner = path.join(
  repositoryRoot,
  'scripts/conformance/heartbeats-wave-published-artifacts.sh',
);

const sharedServerStub = `#!/usr/bin/env bash
set -euo pipefail
action="\${1:?}"
state_file="\${2:?}"
if [[ "$action" == start ]]; then
  printf '%s\\n' '{"cell_isolation":{"php":{"namespace":"php-test"},"python":{"namespace":"python-test"},"rust":{"namespace":"rust-test"},"waterline":{"namespace":"waterline-test"}}}' >"$state_file"
  exit 0
fi
if [[ "$action" == stop ]]; then
  : >"\${CANCELLATION_STOP_MARKER:?}"
  exit 0
fi
exit 2
`;

const termIgnoringCell = `#!/usr/bin/env bash
set -euo pipefail
trap '' TERM
printf '%s\\n' "$$" >>"\${CANCELLATION_CHILD_PIDS:?}"
while :; do
  sleep 1
done
`;

function writeExecutable(file, contents) {
  fs.writeFileSync(file, contents, { mode: 0o755 });
}

function processState(pid) {
  try {
    const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    return stat.slice(stat.lastIndexOf(') ') + 2).split(' ')[0] ?? null;
  } catch (error) {
    if (error?.code === 'ENOENT') return null;
    throw error;
  }
}

function processGroup(pid) {
  try {
    const stat = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    return Number.parseInt(stat.slice(stat.lastIndexOf(') ') + 2).split(' ')[2], 10);
  } catch (error) {
    if (error?.code === 'ENOENT') return null;
    throw error;
  }
}

async function waitUntil(predicate, timeoutMilliseconds) {
  const deadline = Date.now() + timeoutMilliseconds;
  while (Date.now() < deadline) {
    if (predicate()) return;
    await new Promise((resolve) => setTimeout(resolve, 25));
  }
  throw new Error(`condition was not met within ${timeoutMilliseconds}ms`);
}

function waitForExit(child, timeoutMilliseconds) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      reject(new Error(`heartbeat wave did not exit within ${timeoutMilliseconds}ms`));
    }, timeoutMilliseconds);
    child.once('error', (error) => {
      clearTimeout(timer);
      reject(error);
    });
    child.once('close', (code, signal) => {
      clearTimeout(timer);
      resolve({ code, signal });
    });
  });
}

test('repeated signal cleanup bounds TERM-ignoring cell groups before shared teardown', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'heartbeat-wave-cancellation-'));
  const scripts = path.join(root, 'scripts');
  const result = path.join(root, 'result');
  const childPidsFile = path.join(root, 'cell-pids');
  const stopMarker = path.join(root, 'shared-stop-complete');
  fs.mkdirSync(scripts);
  fs.mkdirSync(result);
  const copiedWaveRunner = path.join(scripts, path.basename(waveRunner));
  fs.copyFileSync(waveRunner, copiedWaveRunner);
  fs.chmodSync(copiedWaveRunner, 0o755);
  writeExecutable(path.join(scripts, 'heartbeats-shared-server.sh'), sharedServerStub);
  for (const runner of [
    'heartbeats-published-artifacts.sh',
    'heartbeats-python-published-artifacts.sh',
    'heartbeats-rust-published-artifacts.sh',
  ]) {
    writeExecutable(path.join(scripts, runner), termIgnoringCell);
  }
  const waterlineRunner = path.join(root, 'waterline-runner.sh');
  writeExecutable(waterlineRunner, termIgnoringCell);

  const child = spawn('bash', [copiedWaveRunner, '--result-dir', result], {
    cwd: repositoryRoot,
    env: {
      ...process.env,
      CANCELLATION_CHILD_PIDS: childPidsFile,
      CANCELLATION_STOP_MARKER: stopMarker,
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
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  let stderr = '';
  child.stderr.setEncoding('utf8');
  child.stderr.on('data', (chunk) => {
    stderr += chunk;
  });

  let childPids = [];
  try {
    await waitUntil(() => {
      if (!fs.existsSync(childPidsFile)) return false;
      childPids = fs.readFileSync(childPidsFile, 'utf8')
        .split(/\r?\n/)
        .filter(Boolean)
        .map((value) => Number.parseInt(value, 10));
      return childPids.length === 4;
    }, 5_000);
    const cancellationStartedAt = Date.now();
    assert.equal(child.kill('SIGTERM'), true);
    const repeatedSignal = setTimeout(() => child.kill('SIGTERM'), 250);
    const exit = await waitForExit(child, 7_000).finally(() => {
      clearTimeout(repeatedSignal);
    });
    const elapsedMilliseconds = Date.now() - cancellationStartedAt;

    assert.deepEqual(exit, { code: 143, signal: null }, stderr);
    assert.ok(
      elapsedMilliseconds < 6_000,
      `cancellation took ${elapsedMilliseconds}ms`,
    );
    assert.equal(fs.existsSync(stopMarker), true, 'shared teardown did not run');
    assert.equal(
      fs.readFileSync(
        path.join(result, 'heartbeat-shared-wave-process-cleanup.log'),
        'utf8',
      ),
      '',
      'zombie-only process groups were reported as active',
    );
    for (const pid of childPids) {
      const state = processState(pid);
      assert.ok(
        [null, 'Z', 'X'].includes(state),
        `cell process ${pid} remained active after cleanup `
          + `(state=${state}, pgid=${processGroup(pid)}, stderr=${stderr})`,
      );
    }
  } finally {
    if (child.exitCode === null && child.signalCode === null) {
      child.kill('SIGKILL');
    }
    for (const pid of childPids) {
      const pgid = processGroup(pid);
      if (Number.isInteger(pgid) && pgid > 1) {
        try {
          process.kill(-pgid, 'SIGKILL');
        } catch (error) {
          if (error?.code !== 'ESRCH') throw error;
        }
      }
    }
    fs.rmSync(root, { recursive: true, force: true });
  }
});
