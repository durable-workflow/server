// The Shared Server fixtures producer fetches CLI 2.2.0's dw.phar once (#1103):
// the release download URL first, then the release-asset API URL only when
// that transfer fails; any completed transfer must match the committed size
// and SHA-256, with no second source after a mismatch. curl and timeout are
// replaced by recording fakes, so no network is used.
import test from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {spawnSync} from 'node:child_process';

const script = resolve('scripts/ci/fetch-parity-cli.sh');
const workflow = readFileSync(resolve('.github/workflows/server-parity.yml'), 'utf8');
const PRIMARY = 'https://github.com/durable-workflow/cli/releases/download/2.2.0/dw.phar';
const API = 'https://api.github.com/repos/durable-workflow/cli/releases/assets/614926795';
const GOOD = 'genuine parity CLI bytes\n';
const SAME_SIZE_WRONG = 'Genuine parity CLI bytes\n';
const SHA = createHash('sha256').update(GOOD).digest('hex');

// FAKE_PRIMARY / FAKE_API: ok | wrong | short | fail. Each call is one log line.
const fakeCurl = `#!/usr/bin/env bash
printf '%s\\x1f' "$@" >> "$CALL_LOG"; printf '\\n' >> "$CALL_LOG"
out=""; url=""
while (($#)); do case "$1" in --output) out="$2"; shift 2 ;; https://*) url="$1"; shift ;; *) shift ;; esac; done
case "$url" in ${PRIMARY}) mode="$FAKE_PRIMARY" ;; ${API}) mode="$FAKE_API" ;; *) exit 3 ;; esac
case "$mode" in
  ok) printf '%s' "$GOOD" > "$out" ;;
  wrong) printf '%s' "$SAME_SIZE_WRONG" > "$out" ;;
  short) printf 'x' > "$out" ;;
  *) echo "curl: (22) The requested URL returned error: 504" >&2; exit 22 ;;
esac
`;
const fakeTimeout = `#!/usr/bin/env bash
echo "$1" >> "$TIMEOUT_LOG"; shift; exec "$@"
`;

function run(t, primary, api, args = [SHA, String(GOOD.length)]) {
  const dir = mkdtempSync(join(tmpdir(), 'fetch-parity-cli-'));
  t.after(() => rmSync(dir, {recursive: true, force: true}));
  for (const [name, body] of [['curl', fakeCurl], ['timeout', fakeTimeout]]) {
    writeFileSync(join(dir, name), body);
    chmodSync(join(dir, name), 0o755);
  }
  writeFileSync(join(dir, 'calls'), '');
  writeFileSync(join(dir, 'timeouts'), '');
  const out = join(dir, 'parity-cli', 'dw.phar');
  const result = spawnSync('bash', [script, ...args, out], {
    encoding: 'utf8', timeout: 10000,
    env: {...process.env, PATH: `${dir}:${process.env.PATH}`, CALL_LOG: join(dir, 'calls'), TIMEOUT_LOG: join(dir, 'timeouts'),
      FAKE_PRIMARY: primary, FAKE_API: api, GOOD, SAME_SIZE_WRONG},
  });
  const calls = readFileSync(join(dir, 'calls'), 'utf8').split('\n').filter(Boolean)
    .map(line => line.split('\x1f').filter(Boolean));
  const timeouts = readFileSync(join(dir, 'timeouts'), 'utf8').trim().split('\n').filter(Boolean);
  return {...result, calls, timeouts, out};
}

function assertBounded(call, window) {
  for (const flag of ['--fail', '--location', '--retry-all-errors']) assert.ok(call.includes(flag), flag);
  for (const [flag, value] of [['--proto', '=https'], ['--proto-redir', '=https'], ['--connect-timeout', '10'],
    ['--max-time', '60'], ['--retry', '8'], ['--retry-max-time', window]]) {
    assert.equal(call[call.indexOf(flag) + 1], value, flag);
  }
}

test('the release download URL alone is used when it yields the committed bytes', t => {
  const result = run(t, 'ok', 'ok');
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.calls.length, 1);
  assert.equal(result.calls[0].at(-1), PRIMARY);
  assert.ok(!result.calls[0].includes('-H'), 'no API header on the release URL');
  assertBounded(result.calls[0], '240');
  assert.deepEqual(result.timeouts, ['300']);
  assert.match(result.stdout, /parity CLI transport: release download URL\n/);
  assert.match(result.stdout, /dw\.phar: OK\n/);
  assert.equal(readFileSync(result.out, 'utf8'), GOOD);
});

test('a completed transfer with other bytes fails at once and never falls back', t => {
  for (const mode of ['wrong', 'short']) {
    const result = run(t, mode, 'ok');
    assert.equal(result.status, 1, mode);
    assert.equal(result.calls.length, 1, `${mode}: no second source`);
    assert.equal(result.calls[0].at(-1), PRIMARY);
  }
});

test('only a failed release download falls back to the release-asset API URL', t => {
  const result = run(t, 'fail', 'ok');
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.calls.length, 2);
  assert.equal(result.calls[1].at(-1), API);
  assert.equal(result.calls[1][result.calls[1].indexOf('-H') + 1], 'Accept: application/octet-stream');
  assertBounded(result.calls[0], '240');
  assertBounded(result.calls[1], '120');
  assert.deepEqual(result.timeouts, ['300', '180']);
  assert.match(result.stderr, /release download URL failed \(exit 22\); trying the release-asset API URL/);
  assert.match(result.stdout, /parity CLI transport: release-asset API URL\n/);
  assert.match(result.stdout, /dw\.phar: OK\n/);
});

test('the API URL is held to the same size and digest, and both failing fails the step', t => {
  for (const [api, expected] of [['wrong', 1], ['short', 1], ['fail', 1]]) {
    const result = run(t, 'fail', api);
    assert.equal(result.status, expected, api);
    assert.equal(result.calls.length, 2, `${api}: exactly two transfers`);
  }
  assert.match(run(t, 'fail', 'fail').stderr, /both transports failed/);
});

test('a malformed digest or size is refused before any transfer', t => {
  for (const args of [['', '25'], ['abc', '25'], [SHA, '0'], [SHA, 'x']]) {
    const result = run(t, 'ok', 'ok', args);
    assert.equal(result.status, 2, JSON.stringify(args));
    assert.deepEqual(result.calls, []);
  }
});

test('the producer step runs the fetch with the committed digest and the bound asset size', () => {
  const producer = workflow.slice(workflow.indexOf('      - name: Fetch the parity CLI once, bound to its committed digest\n'));
  const step = producer.slice(0, producer.indexOf('\n      - ', 1));
  assert.match(step, /\n {8}timeout-minutes: 9\n/);
  assert.ok(step.includes(`bash scripts/ci/fetch-parity-cli.sh \\
            "$(jq -r '.visibility.cli.phar_sha256' tests/Fixtures/ServerParity/visibility-current-runs.json)" 626966 parity-cli/dw.phar`));
  assert.doesNotMatch(step, /curl /, 'every transfer goes through the script');
  // Each corpus job still checks the shared file against the committed digest before use.
  const consumer = "printf '%s  %s\\n' \"$(jq -r '.visibility.cli.phar_sha256' tests/Fixtures/ServerParity/visibility-current-runs.json)\" parity-consumers/dw.phar | sha256sum -c > parity-evidence/cli-artifact.txt";
  assert.equal(workflow.split(consumer).length - 1, 3);
  assert.match(workflow, /\n {6}- scripts\/ci\/\*\*\n/);
});
