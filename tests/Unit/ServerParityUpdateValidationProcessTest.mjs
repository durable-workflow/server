import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync, mkdirSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';

test('parallel timing tests report missing PHP and release their HTTP listeners', {timeout: 15000}, () => {
  const directory = mkdtempSync(join(tmpdir(), 'server-parity-missing-php-'));
  try {
    const emptyPath = join(directory, 'empty-path');
    mkdirSync(emptyPath);
    const companion = join(directory, 'companion.mjs');
    writeFileSync(companion, "import test from 'node:test'; test('parallel companion', () => {});\n");
    const timingTests = fileURLToPath(new URL('./ServerParityUpdateValidationTimingTest.mjs', import.meta.url));
    const environment = {...process.env, PATH: emptyPath};
    // The child is an independent runner, not another worker of this test run.
    delete environment.NODE_TEST_CONTEXT;
    const result = spawnSync(process.execPath,
      ['--test', '--test-concurrency=2', timingTests, companion], {
        env: environment,
        encoding: 'utf8',
        timeout: 10000,
        killSignal: 'SIGKILL',
      });

    assert.ifError(result.error);
    assert.equal(result.signal, null);
    assert.equal(result.status, 1, result.stdout + result.stderr);
    assert.match(result.stdout + result.stderr, /spawn php ENOENT/);
    assert.match(result.stdout, /parallel companion/);
  } finally {
    rmSync(directory, {recursive: true, force: true});
  }
});
