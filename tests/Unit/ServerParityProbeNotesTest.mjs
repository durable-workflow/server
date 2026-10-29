import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {chmodSync, mkdtempSync, readFileSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {delimiter, join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {probeNotes} from '../../scripts/conformance/server-parity/probe-capture.mjs';

const runner = fileURLToPath(new URL('../../scripts/conformance/server-parity.mjs', import.meta.url));
const fixture = fileURLToPath(new URL('../Fixtures/ServerParity/many-updates.json', import.meta.url));
const note = '{"many_updates_submit_retry":{"index":15,"attempt":1,"status":500}}';

test('only the recognized value-free notes are kept', () => {
  assert.deepStrictEqual(probeNotes(`noise\n${note}\n{"many_updates_submit_retry":{"index":1,"attempt":1,"status":500,"body":"x"}}\n` +
    `{"other":1}\n  ${note}  \n`), [note, note]);
  assert.deepStrictEqual(probeNotes(Array(30).fill(note).join('\n')).length, 20);
  assert.deepStrictEqual(probeNotes(undefined), []);
});

// A probe that exits 0 after a recovered retry: the recorder keeps its note
// on the case and in its own stderr, and nothing else from the probe's.
test('a successful probe keeps its retry note in the record and the recorder log', () => {
  const directory = mkdtempSync(join(tmpdir(), 'dw-probe-notes-'));
  try {
    writeFileSync(join(directory, 'php'),
      `#!/bin/sh\necho '${note}' >&2\necho 'private stderr text' >&2\necho '{"mode":"http"}'\nexit 0\n`);
    chmodSync(join(directory, 'php'), 0o755);
    const output = join(directory, 'php-record.json');
    const run = spawnSync(process.execPath, [runner, 'record', '--mode', 'http', '--url', 'http://127.0.0.1:9',
      '--target', 'php', '--output', output, '--runner-revision', '0'.repeat(40), '--fixture', fixture],
    {encoding: 'utf8', env: {...process.env, PATH: `${directory}${delimiter}${process.env.PATH}`}});
    assert.match(run.stderr, new RegExp(`many-updates: ${note.replace(/[{}]/g, '\\$&')}`));
    assert.doesNotMatch(run.stderr, /private stderr text/);
    const record = JSON.parse(readFileSync(output, 'utf8'));
    assert.deepStrictEqual(record.cases[0].probe_notes, [note]);
    assert.doesNotMatch(readFileSync(output, 'utf8'), /private stderr text/);
  } finally {
    rmSync(directory, {recursive: true, force: true});
  }
});
