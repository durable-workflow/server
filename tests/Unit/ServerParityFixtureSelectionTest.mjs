// Fixture selection for parity records and comparisons. Every database job
// records update-validation and service-calls in its main corpus, with PHP's
// maintenance loop running: published 2.11.5 carries the #726 and #796 fixes
// that the SQLite job's former separate records worked around.
import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, readdirSync} from 'node:fs';
import {basename, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {selectFixturePaths} from '../../scripts/conformance/server-parity/fixture-selection.mjs';

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)));
const directory = resolve(root, 'tests/Fixtures/ServerParity');
const corpus = readdirSync(directory).filter(name => name !== 'php-baseline.json' && name.endsWith('.json')).sort();
const updateValidation = 'tests/Fixtures/ServerParity/update-validation.json';
const pending = 'tests/Fixtures/ServerParityPending/workflow-task-transient-retry.json';
const names = paths => paths.map(path => basename(path)).sort();

test('the default selection is the reviewed corpus without the PHP baseline', () => {
  assert.deepEqual(names(selectFixturePaths({root, directory})), corpus);
  assert.ok(corpus.includes('update-validation.json'));
});

test('an exclusion removes exactly that corpus fixture and keeps included candidates', () => {
  const selected = names(selectFixturePaths({root, directory, include: [pending], exclude: [updateValidation]}));
  assert.deepEqual(selected, [...corpus.filter(name => name !== 'update-validation.json'), basename(pending)].sort());
});

test('an exclusion must name a selected fixture', () => {
  assert.throws(() => selectFixturePaths({root, directory, exclude: [pending]}), /Excluded fixture is not in the selected set/);
  assert.throws(() => selectFixturePaths({root, directory, exclude: ['tests/Fixtures/ServerParity/no-such-fixture.json']}),
    /Excluded fixture is not in the selected set/);
});

test('an explicit fixture set cannot be combined with inclusions or exclusions', () => {
  assert.throws(() => selectFixturePaths({root, directory, fixture: [updateValidation], exclude: [updateValidation]}),
    /Choose either --fixture or --exclude-fixture/);
  assert.throws(() => selectFixturePaths({root, directory, fixture: [updateValidation], include: [pending]}),
    /Choose either --fixture or --include-fixture/);
  assert.deepEqual(names(selectFixturePaths({root, directory, fixture: [updateValidation]})), ['update-validation.json']);
});

// Splits the workflow into its top-level jobs.
function jobs() {
  const text = readFileSync(resolve(root, '.github/workflows/server-parity.yml'), 'utf8');
  const result = {};
  let current;
  for (const line of text.split('\n')) {
    const header = /^ {2}([a-z][a-z0-9-]*):$/.exec(line);
    if (header) current = result[header[1]] = [];
    else if (current) current.push(line);
  }
  return Object.fromEntries(Object.entries(result).map(([name, body]) => [name, body.join('\n')]));
}

// Each record/compare command of the main corpus, as one string.
function mainCorpusCommands(job) {
  return job.split(/(?=docker run )/).filter(command => /server-parity\.mjs (record|compare)/.test(command));
}

test('the SQLite job keeps update-validation and service-calls in its main corpus', () => {
  const sqlite = jobs()['execution-and-codec'];
  const commands = mainCorpusCommands(sqlite);
  assert.equal(commands.length, 4, 'php, embedded and rust records plus their comparison');
  for (const command of commands) assert.doesNotMatch(command, /--exclude-fixture/);
  assert.ok(corpus.includes('update-validation.json'));
  assert.ok(corpus.includes('service-calls.json'));
  assert.doesNotMatch(sqlite, /update-validation-sqlite|run_without_maintenance/);
});

test('the MySQL and PostgreSQL jobs keep update-validation and service-calls in their main corpus', () => {
  const all = jobs();
  for (const name of ['mysql-execution', 'postgres-storage']) {
    assert.ok(mainCorpusCommands(all[name]).length > 0, name);
    assert.doesNotMatch(all[name], /--exclude-fixture/, name);
  }
});
