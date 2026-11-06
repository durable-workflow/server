// Fixture selection for parity records and comparisons, and the SQLite job's
// separate service-calls record (Server #796). update-validation records in
// every job's main corpus: published 2.11.5 carries the #726 fixes.
import test from 'node:test';
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {basename, join, resolve} from 'node:path';
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

test('two shards preserve the exact selected corpus, bytes and ordering without overlap', () => {
  const command = mainCorpusCommands(jobs()['execution-and-codec'])[0];
  const options = {root, directory,
    include: [...command.matchAll(/--include-fixture (\S+)/g)].map(match => match[1]),
    exclude: [...command.matchAll(/--exclude-fixture (\S+)/g)].map(match => match[1])};
  const whole = selectFixturePaths(options);
  const halves = ['1/2', '2/2'].map(shard => selectFixturePaths({...options, shard}));
  assert.deepEqual(names(halves[0]), names(whole).filter((_, index) => index % 2 === 0));
  assert.deepEqual(names(halves[1]), names(whole).filter((_, index) => index % 2 === 1));
  assert.equal(new Set(halves.flat()).size, whole.length);
  assert.deepEqual(names(halves.flat()), names(whole));
  assert.equal(halves[0].length, Math.ceil(whole.length / 2));
  assert.equal(halves[1].length, Math.floor(whole.length / 2));
  const hashes = paths => Object.fromEntries(paths.map(path => [basename(path),
    createHash('sha256').update(readFileSync(path)).digest('hex')]));
  assert.deepEqual(hashes(halves.flat()), hashes(whole));
});

for (const shard of ['', '0/2', '3/2', '1/1', '1/3', '01/2', '1/02', 1, false, null]) {
  test(`refuses an unsupported shard ${JSON.stringify(shard)}`, () => {
    assert.throws(() => selectFixturePaths({root, directory, shard}), /exactly 1\/2 or 2\/2/);
  });
}

test('shards reject colliding fixture names and empty selections', () => {
  for (const shard of ['1/2', '2/2']) {
    assert.throws(() => selectFixturePaths({root, directory, shard,
      fixture: ['first/same.json', 'second/same.json']}), /distinct JSON filenames/);
    assert.throws(() => selectFixturePaths({root, directory, shard,
      fixture: [pending], exclude: [pending]}), /Choose either/);
  }
  assert.throws(() => selectFixturePaths({root, directory, fixture: [pending], shard: '2/2'}), /must not be empty/);
});

test('the explicit shard environment is validated and a CLI value takes precedence', () => {
  const run = args => spawnSync(process.execPath, ['scripts/conformance/server-parity.mjs', 'compare', ...args], {
    cwd: root, encoding: 'utf8', timeout: 10000, env: {...process.env, DW_PARITY_CORPUS_SHARD: '3/2'},
  });
  const refused = run([]);
  assert.notEqual(refused.status, 0);
  assert.match(refused.stderr, /Fixture shard must be exactly 1\/2 or 2\/2/);
  const overridden = run(['--fixture-shard', '1/2']);
  assert.notEqual(overridden.status, 0);
  assert.match(overridden.stderr, /at least two recordings are required/);
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

test('the SQLite job keeps update-validation in its main corpus and isolates the contention-sensitive fixtures', () => {
  const sqlite = jobs()['execution-and-codec'];
  const commands = mainCorpusCommands(sqlite);
  assert.equal(commands.length, 4, 'php, embedded and rust records plus their comparison');
  for (const command of commands) {
    assert.equal((command.match(/-e DW_PARITY_CORPUS_SHARD/g) ?? []).length, 1);
    assert.deepEqual(command.match(/--exclude-fixture \S+/g), [
      '--exclude-fixture tests/Fixtures/ServerParity/service-calls.json',
      '--exclude-fixture tests/Fixtures/ServerParity/schedule-catalog.json',
      '--exclude-fixture tests/Fixtures/ServerParity/namespace-delete-payloads.json',
      '--exclude-fixture tests/Fixtures/ServerParity/retention-external-payloads.json',
    ]);
  }
  assert.ok(corpus.includes('update-validation.json'));
  assert.ok(corpus.includes('service-calls.json'));
  assert.match(sqlite, /service_calls_status=0\n\s+bash scripts\/conformance\/server-parity\/update-validation-sqlite\.sh http:\/\/parity-server:8080 http:\/\/parity-rust:8080 service-calls \|\| service_calls_status=\$\?/);
  assert.match(sqlite, /test "\$service_calls_status" -eq 0/);
  assert.match(sqlite, /schedule_catalog_status=0\n\s+bash scripts\/conformance\/server-parity\/update-validation-sqlite\.sh http:\/\/parity-server:8080 http:\/\/parity-rust:8080 schedule-catalog \|\| schedule_catalog_status=\$\?/);
  assert.match(sqlite, /test "\$schedule_catalog_status" -eq 0/);
  assert.match(sqlite, /namespace_delete_payloads_status=0\n\s+bash scripts\/conformance\/server-parity\/update-validation-sqlite\.sh http:\/\/parity-server:8080 http:\/\/parity-rust:8080 namespace-delete-payloads \|\| namespace_delete_payloads_status=\$\?/);
  assert.match(sqlite, /test "\$namespace_delete_payloads_status" -eq 0/);
  assert.match(sqlite, /retention_payloads_status=0\n\s+bash scripts\/conformance\/server-parity\/update-validation-sqlite\.sh http:\/\/parity-server:8080 http:\/\/parity-rust:8080 retention-external-payloads \|\| retention_payloads_status=\$\?/);
  assert.match(sqlite, /test "\$retention_payloads_status" -eq 0/);
  assert.doesNotMatch(sqlite, /update-validation-sqlite\.sh http:\/\/parity-server:8080 http:\/\/parity-rust:8080 \|\|/);
  assert.match(sqlite, /DW_PARITY_CORPUS_SHARD: \$\{\{ matrix\.part == 'corpus-b' && '2\/2' \|\| '1\/2' \}\}/);
  const profileBlock = /if \[ "\$DW_PARITY_CORPUS_SHARD" = "1\/2" \]; then\n([\s\S]*?)\n {10}fi/.exec(sqlite)?.[1];
  assert.ok(profileBlock);
  assert.equal((profileBlock.match(/bash scripts\/conformance\/server-parity\/update-validation-sqlite\.sh /g) ?? []).length, 4);
  assert.equal((sqlite.match(/if \[ "\$DW_PARITY_CORPUS_SHARD" = "1\/2" \]; then/g) ?? []).length, 2);
});

test('the MySQL and PostgreSQL jobs keep update-validation and service-calls in their main corpus', () => {
  const all = jobs();
  for (const name of ['mysql-execution', 'postgres-storage']) {
    assert.ok(mainCorpusCommands(all[name]).length > 0, name);
    assert.doesNotMatch(all[name], /--exclude-fixture/, name);
    assert.doesNotMatch(all[name], /--fixture-shard/, name);
  }
});

test('both SQLite parts compare every target and preserve record/profile failure statuses', t => {
  const directory = mkdtempSync(join(tmpdir(), 'sqlite-corpus-commands-'));
  t.after(() => rmSync(directory, {recursive: true, force: true}));
  const bin = join(directory, 'bin');
  mkdirSync(bin);
  mkdirSync(join(directory, 'parity-evidence'));
  writeFileSync(join(bin, 'docker'), `#!/usr/bin/env node
const fs = require('node:fs');
const args = process.argv.slice(2);
const phase = args.includes('compare') ? 'compare' : args[args.indexOf('--target') + 1];
fs.appendFileSync(process.env.CORPUS_COMMAND_LOG, JSON.stringify({phase, args}) + '\\n');
if (process.env.CORPUS_FAIL === phase) process.exit(7);
`, {mode: 0o755});
  const helpers = join(directory, 'scripts/conformance/server-parity');
  mkdirSync(helpers, {recursive: true});
  writeFileSync(join(helpers, 'update-validation-sqlite.sh'), `#!/bin/bash
printf '%s\\n' "$3" >> "$CORPUS_SPECIAL_LOG"
if [ "$CORPUS_FAIL" = "$3" ]; then exit 7; fi
`);
  const step = jobs()['execution-and-codec'].split('      - name: Execute and compare Rust, published PHP and embedded fixtures')[1]
    .split('\n      - name:')[0];
  const shell = step.split('        run: |\n')[1].split('\n').map(line => line.slice(10)).join('\n');
  // The unchanged preparation is outside this inert command/control test.
  const body = 'set -euo pipefail\n' + shell.slice(shell.indexOf('docker run --rm'));
  const profiles = ['service-calls', 'schedule-catalog', 'namespace-delete-payloads', 'retention-external-payloads'];
  const run = (shard, fail = '') => {
    const evidence = mkdtempSync(join(directory, 'case-'));
    const log = join(evidence, 'commands.jsonl');
    const special = join(evidence, 'profiles.txt');
    const result = spawnSync('bash', ['-c', body], {cwd: directory, encoding: 'utf8', timeout: 10000,
      env: {...process.env, PATH: `${bin}:${process.env.PATH}`, image: 'fixture-image',
        DW_PARITY_CORPUS_SHARD: shard, RESOURCE_SCOPE: 'fixture', DW_PARITY_RUNNER_REVISION: 'a'.repeat(40),
        DW_PARITY_CLI_PHAR: '/source/fixture.phar', CORPUS_COMMAND_LOG: log, CORPUS_SPECIAL_LOG: special,
        CORPUS_FAIL: fail}});
    return {...result, calls: existsSync(log) ? readFileSync(log, 'utf8').trim().split('\n').map(JSON.parse) : [],
      profiles: existsSync(special) ? readFileSync(special, 'utf8').trim().split('\n') : []};
  };
  for (const shard of ['1/2', '2/2']) {
    const result = run(shard);
    assert.equal(result.status, 0, result.stderr);
    assert.deepEqual(result.calls.map(call => call.phase), ['php', 'embedded', 'rust', 'compare']);
    assert.ok(result.calls.every(call => call.args.includes('DW_PARITY_CORPUS_SHARD')));
    assert.deepEqual(result.profiles, shard === '1/2' ? profiles : []);
    for (const failure of ['rust', 'compare']) {
      const refused = run(shard, failure);
      assert.notEqual(refused.status, 0);
      assert.deepEqual(refused.calls.map(call => call.phase), ['php', 'embedded', 'rust', 'compare']);
    }
  }
  for (const failure of ['php', 'embedded']) {
    const refused = run('1/2', failure);
    assert.equal(refused.status, 7);
    assert.equal(refused.calls.some(call => call.phase === 'rust' || call.phase === 'compare'), false);
    assert.deepEqual(refused.profiles, []);
  }
  for (const failure of profiles) {
    const refused = run('1/2', failure);
    assert.notEqual(refused.status, 0);
    assert.deepEqual(refused.profiles, profiles, 'one failed separate profile does not omit the others');
  }
});

test('the separate record stops maintenance for the PHP record only and compares all three targets', () => {
  const script = readFileSync(resolve(root, 'scripts/conformance/server-parity/update-validation-sqlite.sh'), 'utf8');
  // update-validation by default, or the named reviewed fixture.
  assert.match(script, /^name="\$\{3:-update-validation\}"\n\[\[ "\$name" =~ \^\[a-z0-9-\]\+\$ \]\]$/m);
  assert.match(script, /^fixture="tests\/Fixtures\/ServerParity\/\$name\.json"\ntest -f "\$fixture"$/m);
  assert.match(script, /^\. scripts\/conformance\/server-parity\/php-maintenance-window\.sh$/m);
  // The PHP record, and its one retry after a lost SQLite write (#754).
  const windows = script.match(/^ *run_without_maintenance .*$/gm).map(line => line.trim());
  assert.deepEqual(windows, [
    'run_without_maintenance "parity-evidence/$name-maintenance-schedule.txt" -- php_record',
    'run_without_maintenance "parity-evidence/$name-maintenance-schedule-retry.txt" -- php_record',
  ]);
  assert.match(script, /^php_record\(\) \{\n  php_status=0\n  record_started=\$\(date -u \+%Y-%m-%dT%H:%M:%S\.%NZ\)\n  http_record "\$php_url" php \|\| php_status=\$\?\n  record_finished=\$\(date -u \+%Y-%m-%dT%H:%M:%S\.%NZ\)\n\}$/m);
  assert.match(script, /docker logs --since "\$record_started" --until "\$record_finished" "\$RESOURCE_SCOPE-server"/);
  assert.match(script, /^http_record "\$native_url" rust$/m);
  assert.match(script, /--mode embedded --application-root \/app --target embedded --output "\/evidence\/\$name-embedded\.json" --fixture "\$fixture"/);
  assert.match(script, /"\/evidence\/\$name-php\.json" "\/evidence\/\$name-rust\.json" "\/evidence\/\$name-embedded\.json" \\\n\s+--fixture "\$fixture" > "parity-evidence\/\$name-comparison\.json"/);
  assert.match(script, /^set -euo pipefail$/m);
});
