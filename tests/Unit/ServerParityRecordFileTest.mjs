import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import test from 'node:test';
import {writeRecord} from '../../scripts/conformance/server-parity/record-file.mjs';

test('full wire recordings serialize within a bounded heap without dropping case bytes', () => {
  const directory = mkdtempSync(join(tmpdir(), 'dw-parity-record-'));
  const path = join(directory, 'record.json');
  try {
    const source = `import {writeRecord} from ${JSON.stringify(new URL('../../scripts/conformance/server-parity/record-file.mjs', import.meta.url).href)};
      const wire = 'x'.repeat(2 * 1024 * 1024) + '\\u0000λ';
      writeRecord(${JSON.stringify(path)}, {outcome: 'pass', cases: Array.from({length: 16}, (_, index) => ({
        fixture_id: String(index), request_body: wire, typed: {int64: '9007199254740993', optional: null}}))});`;
    const child = spawnSync(process.execPath, ['--max-old-space-size=48', '--input-type=module', '-e', source],
      {encoding: 'utf8', timeout: 20000});
    assert.equal(child.status, 0, child.stderr);
    const record = JSON.parse(readFileSync(path, 'utf8'));
    assert.equal(record.outcome, 'pass');
    assert.equal(record.cases.length, 16);
    for (const [index, item] of record.cases.entries()) {
      assert.equal(item.fixture_id, String(index));
      assert.equal(item.request_body, 'x'.repeat(2 * 1024 * 1024) + '\u0000λ');
      assert.deepEqual(item.typed, {int64: '9007199254740993', optional: null});
    }
  } finally { rmSync(directory, {recursive: true}); }
});

test('empty and failed recordings keep their explicit disposition', () => {
  const directory = mkdtempSync(join(tmpdir(), 'dw-parity-record-'));
  try {
    for (const record of [{outcome: 'runner-blocked', cases: []},
      {outcome: 'product-fail', cases: [{outcome: 'product-fail', error: 'original expired'}], finished_at: 'unchanged'}]) {
      const path = join(directory, 'record.json');
      writeRecord(path, record);
      assert.deepEqual(JSON.parse(readFileSync(path)), record);
    }
  } finally { rmSync(directory, {recursive: true}); }
});
