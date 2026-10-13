import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync, readFileSync, readdirSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {captureProbe} from '../../scripts/conformance/server-parity/probe-capture.mjs';

function captured(run) {
  const directory = mkdtempSync(join(tmpdir(), 'parity-capture-test-'));
  try {
    run((command, args, options = {}) => captureProbe(command, args, {directory, ...options}));
    assert.deepEqual(readdirSync(directory), [], 'temporary stdout/stderr are removed');
  } finally {
    rmSync(directory, {recursive: true, force: true});
  }
}

test('complete valid JSON above the former 4 MiB pipe limit remains readable', () => captured(run => {
  const size = 5 * 1024 * 1024;
  const result = run(process.execPath, ['-e', `console.log(JSON.stringify({raw:'x'.repeat(${size}),tail:'終わり'}));`]);
  assert.equal(result.error, undefined);
  assert.equal(result.status, 0);
  const observation = JSON.parse(result.stdout);
  assert.equal(observation.raw.length, size);
  assert.equal(observation.raw, 'x'.repeat(size));
  assert.equal(observation.tail, '終わり');
  assert.equal(result.stderr, '');
}));

test('an explicitly preserved probe keeps original numeric bytes until its caller removes them', () => {
  const directory = mkdtempSync(join(tmpdir(), 'parity-original-test-'));
  try {
    const preserved = join(directory, 'original.stdout');
    const raw = '{"result":9223372036854775807,"optional":null,"unicode":"λ"}\n';
    const result = captureProbe(process.execPath, ['-e', `process.stdout.write(${JSON.stringify(raw)})`],
      {directory, preserveStdout: preserved});
    assert.equal(result.status, 0);
    assert.equal(result.stdoutPath, preserved);
    assert.equal(readFileSync(preserved, 'utf8'), raw);
    assert.deepEqual(readdirSync(directory), ['original.stdout']);
  } finally { rmSync(directory, {recursive: true}); }
});

test('a nonzero probe exit preserves both streams and refuses success', () => captured(run => {
  const result = run(process.execPath, ['-e', 'process.stdout.write("partial");process.stderr.write("original failure");process.exitCode=7;']);
  assert.equal(result.error, undefined);
  assert.equal(result.status, 7);
  assert.equal(result.stdout, 'partial');
  assert.equal(result.stderr, 'original failure');
}));

test('missing executable remains a spawn error and leaves no capture files', () => captured(run => {
  const result = run('/missing-parity-probe', []);
  assert.equal(result.error.code, 'ENOENT');
  assert.equal(result.status, null);
  assert.equal(result.stdout, '');
  assert.equal(result.stderr, '');
}));

test('file-backed capture retains the absolute child deadline and partial evidence', () => captured(run => {
  const result = run(process.execPath, ['-e', 'process.stdout.write("started");setInterval(()=>{},1000);'], {timeout: 1000});
  assert.equal(result.error.code, 'ETIMEDOUT');
  assert.equal(result.status, null);
  assert.equal(result.signal, 'SIGTERM');
  assert.equal(result.stdout, 'started');
}));

test('oversized stderr is explicitly refused rather than silently truncated', () => captured(run => {
  const result = run(process.execPath, ['-e', 'process.stdout.write("{}");process.stderr.write("x".repeat(4*1024*1024+1));']);
  assert.equal(result.status, 0);
  assert.equal(result.error.code, 'DW_PARITY_CAPTURE_LIMIT');
  assert.match(result.error.message, /stderr exceeds 4194304 bytes/);
  assert.equal(result.stdout, '');
  assert.equal(result.stderr, '');
}));
