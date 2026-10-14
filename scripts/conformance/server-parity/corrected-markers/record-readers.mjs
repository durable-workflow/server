// Run the existing mutation readers on complete, actual recorder output.
import assert from 'node:assert/strict';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';

const [recordPath, outputDirectory, family] = process.argv.slice(2);
const record = JSON.parse(readFileSync(recordPath));
assert.equal(record.outcome, 'pass');
const expected = family === 'portable'
  ? ['fresh-numeric', 'fresh-zero', 'deprecation-first', 'genuine-version-one', 'php-full-retry',
    'php-full-retry-refusal', 'rust-range-completed', 'rust-range-timer-pending']
  : family === 'clock' ? ['python-clock-zero', 'python-clock-genuine-one'] : null;
assert(expected, 'Choose the declared portable or clock reader family');
assert.deepEqual(record.cases.map(item => item.fixture.id).sort(), expected.sort());
mkdirSync(outputDirectory, {recursive: true});
const directory = dirname(fileURLToPath(import.meta.url));
const invoke = (script, args) => {
  const result = spawnSync(process.execPath, [resolve(directory, script), ...args], {encoding: 'utf8'});
  assert.equal(result.status, 0, `${script}: ${result.stderr}`);
  return JSON.parse(result.stdout);
};
const results = [];
if (family === 'clock') {
  results.push(invoke('check-clock-readers.mjs', [recordPath]));
} else {
  const artifactsPath = resolve(outputDirectory, 'artifacts.json');
  writeFileSync(artifactsPath, JSON.stringify(record.artifacts));
  for (const item of record.cases.filter(item => item.fixture.corrected_markers)) {
    assert.equal(item.outcome, 'pass');
    const fixturePath = resolve(outputDirectory, `${item.fixture.id}-fixture.json`);
    const observationPath = resolve(outputDirectory, `${item.fixture.id}-observation.json`);
    writeFileSync(fixturePath, JSON.stringify(item.fixture));
    writeFileSync(observationPath, JSON.stringify(item.observation));
    results.push(invoke('check-readers.mjs', [fixturePath, observationPath, artifactsPath]));
  }
  results.push(invoke('check-range-readers.mjs', [recordPath]));
}
console.log(JSON.stringify({outcome: 'pass', original_record: recordPath, family, results}));
