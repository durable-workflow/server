import test from 'node:test';
import assert from 'node:assert/strict';
import {existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, symlinkSync, writeFileSync} from 'node:fs';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
import {summarizeEvidence} from '../../scripts/conformance/server-parity/artifact-summary.mjs';

function fixture(t) {
  const root = mkdtempSync(join(tmpdir(), 'parity-summary-'));
  t.after(() => rmSync(root, {recursive: true, force: true}));
  const source = join(root, 'raw');
  const output = join(root, 'summary');
  mkdirSync(source);
  return {root, source, output};
}

test('successful summaries preserve exact reports and omit journals, DBs, payloads and symlinks', t => {
  const {root, source, output} = fixture(t);
  const comparison = '{"outcome":"pass","value":9007199254740993,"text":"rangeλ"}\n';
  writeFileSync(join(source, 'comparison.json'), comparison);
  writeFileSync(join(source, 'rust-tests.txt'), 'test result: ok. 105 passed\n');
  writeFileSync(join(source, 'artifact-summary-tests.txt'), 'tests 3\npass 3\n');
  writeFileSync(join(source, 'php-record.json'), 'x'.repeat(4 * 1024 * 1024));
  writeFileSync(join(source, 'workflow.sqlite'), 'database');
  writeFileSync(join(source, 'php-server.log'), 'unneeded successful HTTP log');
  mkdirSync(join(source, 'objects'));
  writeFileSync(join(source, 'objects', 'identity.json'), 'provider bytes');
  writeFileSync(join(root, 'outside.json'), 'outside input');
  symlinkSync(join(root, 'outside.json'), join(source, 'identity.json'));
  const summary = summarizeEvidence(source, output, {outcome: 'success', revision: 'qualified-source'});
  assert.equal(readFileSync(join(output, 'comparison.json'), 'utf8'), comparison);
  assert.equal(readFileSync(join(source, 'comparison.json'), 'utf8'), comparison);
  assert.deepEqual(summary.retained.map(file => file.path), ['artifact-summary-tests.txt', 'rust-tests.txt', 'comparison.json']);
  for (const name of ['php-record.json', 'workflow.sqlite', 'php-server.log', 'objects', 'identity.json']) {
    assert.equal(existsSync(join(output, name)), false, name);
  }
  assert.equal(summary.outcome, 'success');
  assert.equal(summary.revision, 'qualified-source');
  assert.equal(summary.omitted_files, 3);
  assert.throws(() => summarizeEvidence(source, output), /fresh empty directory/);
});

test('failed summaries keep bounded diagnostic tails without truncating JSON into a false report', t => {
  const {source, output} = fixture(t);
  const diagnostic = `${'x'.repeat(128 * 1024)}\nHTTP 500 while installing pinned dependency\n`;
  writeFileSync(join(source, 'embedded-original-install.txt'), diagnostic);
  writeFileSync(join(source, 'php-server.log'), 'SQL lock refusal at original deadline\n');
  writeFileSync(join(source, 'final-actor.stderr'), 'HTTP 503 backend_lock_pressure during destroy\n');
  writeFileSync(join(source, 'sdk-profile.status'), '1\n');
  writeFileSync(join(source, 'comparison.json'), JSON.stringify({large: 'x'.repeat(128 * 1024)}));
  const summary = summarizeEvidence(source, output, {outcome: 'failure'});
  const tail = readFileSync(join(output, 'embedded-original-install.txt'));
  assert.equal(tail.length, 64 * 1024);
  assert.equal(tail.toString().endsWith('HTTP 500 while installing pinned dependency\n'), true);
  assert.equal(summary.retained.find(file => file.path.endsWith('install.txt')).tail_only, true);
  assert.equal(existsSync(join(output, 'php-server.log')), true);
  assert.equal(readFileSync(join(output, 'final-actor.stderr'), 'utf8'), 'HTTP 503 backend_lock_pressure during destroy\n');
  assert.equal(readFileSync(join(output, 'sdk-profile.status'), 'utf8'), '1\n');
  assert.equal(existsSync(join(output, 'comparison.json')), false);
  assert.equal(readFileSync(join(source, 'embedded-original-install.txt'), 'utf8'), diagnostic);
});

test('artifact contents and entry count remain bounded and omitted data stays explicit', t => {
  const {source, output, root} = fixture(t);
  for (let index = 0; index < 40; index++) {
    mkdirSync(join(source, `cell-${index}`));
    writeFileSync(join(source, `cell-${index}`, 'rust-tests.txt'), 'x'.repeat(64 * 1024));
  }
  const summary = summarizeEvidence(source, output, {outcome: 'failure'});
  assert.equal(summary.retained_bytes, 2 * 1024 * 1024);
  assert.equal(summary.retained.length, 32);
  assert.equal(summary.omitted_files, 8);
  const statuses = join(root, 'statuses');
  mkdirSync(statuses);
  for (let index = 0; index < 140; index++) writeFileSync(join(statuses, `case-${index}-status.txt`), '0');
  const second = summarizeEvidence(statuses, join(root, 'status-summary'), {outcome: 'success'});
  assert.equal(second.retained.length, 128);
  assert.equal(second.omitted_files, 12);
  assert.throws(() => summarizeEvidence(source, join(source, 'nested')), /outside raw evidence/);
});

test('empty actor diagnostics cannot hide failed profile status or the actual error', t => {
  const {source, output} = fixture(t);
  for (let index = 0; index < 200; index++) writeFileSync(join(source, `actor-${index}.stderr`), '');
  writeFileSync(join(source, 'sdk-profile.status'), '1\n');
  writeFileSync(join(source, 'z-final-actor.stderr'), 'HTTP 503 backend_lock_pressure during destroy\n');
  const summary = summarizeEvidence(source, output, {outcome: 'failure'});
  assert.equal(readFileSync(join(output, 'sdk-profile.status'), 'utf8'), '1\n');
  assert.equal(readFileSync(join(output, 'z-final-actor.stderr'), 'utf8'), 'HTTP 503 backend_lock_pressure during destroy\n');
  assert.equal(summary.retained.length, 2);
  assert.equal(summary.omitted_files, 200);
  assert.equal(summary.retained[0].path, 'sdk-profile.status');
});
