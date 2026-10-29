import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {delimiter, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const runner = fileURLToPath(new URL('../../scripts/conformance/server-parity.mjs', import.meta.url));
const fixture = fileURLToPath(new URL('../Fixtures/ServerParity/large-command-batches.json', import.meta.url));

// A probe that fails before it observes anything blocks the record. The
// artifact summary keeps the failure excerpt, not the record, so the
// excerpt must still name the blocked fixture and its error.
test('a blocked probe leaves a failure excerpt naming its fixture', () => {
  const directory = mkdtempSync(join(tmpdir(), 'dw-runner-blocked-'));
  try {
    writeFileSync(join(directory, 'php'), '#!/bin/sh\necho "fake php refused" >&2\nexit 3\n');
    chmodSync(join(directory, 'php'), 0o755);
    const output = join(directory, 'php-record.json');
    const run = spawnSync(process.execPath, [runner, 'record', '--mode', 'http', '--url', 'http://127.0.0.1:9',
      '--target', 'php', '--output', output, '--runner-revision', '0'.repeat(40), '--fixture', fixture],
    {encoding: 'utf8', env: {...process.env, PATH: `${directory}${delimiter}${process.env.PATH}`}});
    assert.notEqual(run.status, 0, run.stdout);
    assert.equal(JSON.parse(readFileSync(output, 'utf8')).outcome, 'runner-blocked');
    assert.match(readFileSync(join(directory, 'php-record.failure-excerpt.txt'), 'utf8'),
      /^== large-command-batches \(runner-blocked\): fake php refused/);
  } finally {
    rmSync(directory, {recursive: true, force: true});
  }
});
