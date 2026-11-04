import test from 'node:test';
import assert from 'node:assert/strict';
import {chmodSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {spawnSync} from 'node:child_process';
import {selectFixturePaths} from '../../scripts/conformance/server-parity/fixture-selection.mjs';

const root = resolve('.');
const bash = spawnSync('bash', ['-c', 'command -v bash'], {encoding: 'utf8'}).stdout.trim();
const revision = 'a'.repeat(40);

// Run the real orchestration script. Stand-ins replace only external work:
// Docker, dependency setup, the three-target helper and the CLI download.
function run(failure = '') {
  const dir = mkdtempSync(join(tmpdir(), 'native-image-corpus-'));
  try {
    for (const name of ['scripts', 'tests']) symlinkSync(join(root, name), join(dir, name));
    mkdirSync(join(dir, 'bin'));
    for (const name of ['calls', 'helpers']) writeFileSync(join(dir, name), '');
    const mocks = {
      git: `echo ${revision}`,
      curl: 'echo fixture > parity-consumers/dw.phar',
      sha256sum: 'cat > /dev/null; echo checksum-ok',
      bash: `
case "$1" in
  scripts/ci/run-composer.sh) exit 0 ;;
  scripts/conformance/server-parity/update-validation-sqlite.sh)
    echo "$4" >> "$MOCK_DIR/helpers"
    test "$FAILURE" != "$4" || exit 17 ;;
  *) exit 99 ;;
esac`,
      docker: `
echo "$*" >> "$MOCK_DIR/calls"
case "$1" in
  network) exit 0 ;;
  image) echo sha256:${'b'.repeat(64)} ;;
  run)
    stage=
    while test "$#" -gt 0; do
      case "$1" in
        --target) stage=$2; shift ;;
        compare) stage=compare ;;
      esac
      shift
    done
    test -z "$stage" || test "$FAILURE" != "$stage" || exit 17 ;;
  *) exit 99 ;;
esac`,
    };
    for (const [name, source] of Object.entries(mocks)) {
      writeFileSync(join(dir, 'bin', name), `#!/bin/sh\n${source}\n`);
      chmodSync(join(dir, 'bin', name), 0o755);
    }
    const result = spawnSync(bash, ['scripts/conformance/server-parity/native-image-corpus.sh', 'dw-native-test:corpus'], {
      cwd: dir, encoding: 'utf8',
      env: {...process.env, PATH: `${join(dir, 'bin')}:${process.env.PATH}`, MOCK_DIR: dir, FAILURE: failure,
        GITHUB_RUN_ID: '7', GITHUB_RUN_ATTEMPT: '2', GITHUB_JOB: 'corpus', RESOURCE_SCOPE: 'dw-parity-7-2-corpus'},
    });
    return {...result,
      receipt: JSON.parse(readFileSync(join(dir, 'parity-evidence/native-image-corpus.json'), 'utf8')),
      calls: readFileSync(join(dir, 'calls'), 'utf8').trim().split('\n').map(line => line.split(' ')),
      helpers: readFileSync(join(dir, 'helpers'), 'utf8').trim().split('\n'),
    };
  } finally {
    rmSync(dir, {recursive: true, force: true});
  }
}

test('native image qualification records every selected fixture once across the two partitions', () => {
  const result = run();
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(result.helpers, ['service-calls', 'schedule-catalog']);
  const commands = result.calls.filter(args => args.includes('scripts/conformance/server-parity.mjs'));
  assert.equal(commands.length, 4, 'three main records and their comparison');
  let first;
  for (const args of commands) {
    const paths = flag => args.flatMap((arg, i) => arg === flag ? [args[i + 1]] : []);
    const exclude = paths('--exclude-fixture');
    assert.deepEqual(exclude, result.helpers.map(name => `tests/Fixtures/ServerParity/${name}.json`));
    const include = paths('--include-fixture');
    const directory = join(root, 'tests/Fixtures/ServerParity');
    const main = selectFixturePaths({root, directory, include, exclude});
    const all = [...main, ...exclude.map(path => resolve(root, path))].sort();
    assert.deepEqual(all, selectFixturePaths({root, directory, include}).sort(), 'no fixture lost or repeated');
    if (first) assert.deepEqual(all, first, 'all record and comparison selections agree');
    first = all;
  }
  assert.deepEqual(result.receipt.status, {reference_records: 0, native_record: 0, compare: 0, service_calls: 0, schedule_catalog: 0});
  assert.equal(result.receipt.runner_revision, revision);
});

for (const [stage, field] of [
  ['php', 'reference_records'], ['embedded', 'reference_records'], ['rust', 'native_record'],
  ['compare', 'compare'], ['service-calls', 'service_calls'], ['schedule-catalog', 'schedule_catalog'],
]) test(`failure in ${stage} is retained in the receipt and fails image qualification`, () => {
  const result = run(stage);
  assert.notEqual(result.status, 0, result.stderr);
  assert.equal(result.receipt.status[field], 17);
  assert.deepEqual(result.helpers, ['service-calls', 'schedule-catalog'], 'one failure does not hide the separate checks');
});
