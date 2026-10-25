// Fixture selection for parity records and comparisons, and the SQLite job's
// separate update-validation record (Server #723).
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

test('the SQLite job covers update-validation only through its separate record', () => {
  const sqlite = jobs()['execution-and-codec'];
  const commands = mainCorpusCommands(sqlite);
  assert.equal(commands.length, 4, 'php, embedded and rust records plus their comparison');
  for (const command of commands) assert.match(command, /--exclude-fixture tests\/Fixtures\/ServerParity\/update-validation\.json/);
  assert.match(sqlite, /update_validation_status=0\n\s+bash scripts\/conformance\/server-parity\/update-validation-sqlite\.sh http:\/\/parity-server:8080 http:\/\/parity-rust:8080 \|\| update_validation_status=\$\?/);
  assert.match(sqlite, /test "\$update_validation_status" -eq 0/);
});

test('the MySQL and PostgreSQL jobs keep update-validation in their main corpus', () => {
  const all = jobs();
  for (const name of ['mysql-execution', 'postgres-storage']) {
    assert.ok(mainCorpusCommands(all[name]).length > 0, name);
    assert.doesNotMatch(all[name], /--exclude-fixture/, name);
  }
});

test('the separate record stops maintenance for the PHP record only and compares all three targets', () => {
  const script = readFileSync(resolve(root, 'scripts/conformance/server-parity/update-validation-sqlite.sh'), 'utf8');
  assert.match(script, /^fixture=tests\/Fixtures\/ServerParity\/update-validation\.json$/m);
  assert.match(script, /^\. scripts\/conformance\/server-parity\/php-maintenance-window\.sh$/m);
  const windows = script.match(/^run_without_maintenance .*$/gm);
  assert.deepEqual(windows, ['run_without_maintenance parity-evidence/update-validation-maintenance-schedule.txt -- http_record "$php_url" php']);
  assert.match(script, /^http_record "\$native_url" rust$/m);
  assert.match(script, /--mode embedded --application-root \/app --target embedded --output \/evidence\/update-validation-embedded\.json --fixture "\$fixture"/);
  assert.match(script, /update-validation-php\.json \/evidence\/update-validation-rust\.json \/evidence\/update-validation-embedded\.json \\\n\s+--fixture "\$fixture"/);
  assert.match(script, /^set -euo pipefail$/m);
});
